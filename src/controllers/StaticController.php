<?php

namespace studionoir\translingua\controllers;

use Craft;
use studionoir\translingua\translators\TranslatorException;
use studionoir\translingua\Translingua;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The free-tier screens: template strings, Formie, and any other installed
 * plugin's language files.
 */
class StaticController extends BaseController
{
    private const PER_PAGE = 50;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Translingua::PERMISSION_STATIC);

        return true;
    }

    /**
     * Lists every translation source that can be edited.
     */
    public function actionIndex(): Response
    {
        $plugin = Translingua::$plugin;

        return $this->renderTemplate('translingua/static/index', [
            'title' => Craft::t('translingua', 'Static translations'),
            'sources' => $plugin->sources->getAll(),
            'locales' => $plugin->translations->getTargetLocaleOptions(),
            'translations' => $plugin->translations,
            'scanPaths' => $plugin->scanner->getScanPaths(),
        ]);
    }

    /**
     * The editing table for one source + locale.
     */
    public function actionEdit(string $category): Response
    {
        $plugin = Translingua::$plugin;
        $source = $plugin->sources->getByCategory($category);

        if ($source === null) {
            throw new NotFoundHttpException("No translation source found for “$category”.");
        }

        $request = Craft::$app->getRequest();
        $locales = $plugin->translations->getTargetLocaleOptions();
        $locale = (string)$request->getParam('locale', array_key_first($locales));

        if (!isset($locales[$locale])) {
            $locale = (string)array_key_first($locales);
        }

        $search = trim((string)$request->getParam('search', ''));
        $filter = (string)$request->getParam('filter', 'all');
        $page = max(1, (int)$request->getParam('page', 1));

        $strings = $plugin->translations->getSourceStrings($source);
        $overrides = $plugin->translations->getOverrides($source, $locale);
        $base = $plugin->translations->getBaseTranslations($source, $locale);

        $rows = [];

        foreach ($strings as $key => $sourceText) {
            $override = $overrides[$key] ?? '';
            $shipped = $base[$key] ?? '';

            // Show what the site actually renders today. An empty box would
            // suggest nothing is translated when the plugin already ships one.
            $translation = $override !== '' ? $override : $shipped;

            if ($filter === 'missing' && $translation !== '') {
                continue;
            }

            if ($filter === 'translated' && $translation === '') {
                continue;
            }

            if ($search !== '' && !$this->matches($search, $key, $sourceText, $translation)) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'source' => $sourceText,
                'translation' => $translation,
                'shipped' => $shipped,
                'isOverridden' => $override !== '',
            ];
        }

        $total = count($rows);
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);
        $rows = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return $this->renderTemplate('translingua/static/edit', [
            'title' => $source->name,
            'source' => $source,
            'locale' => $locale,
            'locales' => $locales,
            'rows' => $rows,
            'search' => $search,
            'filter' => $filter,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'missingCount' => $plugin->translations->getMissingCount($source, $locale),
            'canUseAi' => $plugin->aiIsReady(),
        ]);
    }

    /**
     * Saves the translations posted from the editing table.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $category = (string)$request->getRequiredBodyParam('category');
        $locale = (string)$request->getRequiredBodyParam('locale');
        $source = Translingua::$plugin->sources->getByCategory($category);

        if ($source === null) {
            throw new NotFoundHttpException("No translation source found for “$category”.");
        }

        /** @var array<string,string> $translations */
        $translations = (array)$request->getBodyParam('translations', []);
        $clean = [];

        foreach ($translations as $key => $value) {
            if (is_string($key) && is_string($value)) {
                // A blank value removes the override rather than storing "".
                $clean[$this->decodeKey($key)] = $value;
            }
        }

        try {
            Translingua::$plugin->translations->saveOverrides($source, $locale, $clean);
        } catch (\Throwable $e) {
            Craft::error("Couldn't save translations: {$e->getMessage()}", __METHOD__);
            $this->setFailFlash(Craft::t('translingua', 'Couldn’t save translations: {message}', [
                'message' => $e->getMessage(),
            ]));

            return null;
        }

        $this->setSuccessFlash(Craft::t('translingua', 'Translations saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Adds a brand new string to a source — useful for keys that only exist in
     * code you haven't written yet, or for a plugin string Craft never emitted.
     */
    public function actionAddString(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $category = (string)$request->getRequiredBodyParam('category');
        $locale = (string)$request->getRequiredBodyParam('locale');
        $key = trim((string)$request->getRequiredBodyParam('key'));
        $value = (string)$request->getBodyParam('value', '');

        $source = Translingua::$plugin->sources->getByCategory($category);

        if ($source === null) {
            throw new NotFoundHttpException("No translation source found for “$category”.");
        }

        if ($key === '') {
            $this->setFailFlash(Craft::t('translingua', 'A source string is required.'));
            return null;
        }

        // Storing the key as its own translation keeps it visible in the table
        // even when the editor hasn't supplied a translation yet.
        Translingua::$plugin->translations->saveOverrides($source, $locale, [
            $key => $value !== '' ? $value : $key,
        ]);

        $this->setSuccessFlash(Craft::t('translingua', 'String added.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Removes a string's override in every locale.
     */
    public function actionDeleteString(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $category = (string)$request->getRequiredBodyParam('category');
        $key = (string)$request->getRequiredBodyParam('key');

        $source = Translingua::$plugin->sources->getByCategory($category);

        if ($source === null) {
            throw new NotFoundHttpException("No translation source found for “$category”.");
        }

        Translingua::$plugin->translations->deleteKey($source, $key);

        return $this->asSuccess(Craft::t('translingua', 'String deleted.'));
    }

    /**
     * Forces a fresh template scan.
     */
    public function actionRescan(): Response
    {
        $this->requirePostRequest();

        Translingua::$plugin->scanner->invalidate();

        $count = count(Translingua::$plugin->scanner->getStrings());

        $this->setSuccessFlash(Craft::t('translingua', '{count} strings found.', ['count' => $count]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Fills in every missing translation for a source + locale using the
     * configured AI provider. Plus only.
     */
    public function actionTranslateMissing(): ?Response
    {
        $this->requirePostRequest();

        if (!Translingua::$plugin->canUseAi()) {
            $this->setFailFlash(Craft::t('translingua', 'AI translations require the Plus edition.'));
            return null;
        }

        if (!Translingua::$plugin->translator->verify()) {
            $this->setFailFlash(Craft::t('translingua', 'The connection to the AI provider isn’t working: {message}', [
                'message' => Translingua::$plugin->translator->getVerificationError() ?? '',
            ]));

            return null;
        }

        $request = Craft::$app->getRequest();
        $category = (string)$request->getRequiredBodyParam('category');
        $locale = (string)$request->getRequiredBodyParam('locale');

        $source = Translingua::$plugin->sources->getByCategory($category);

        if ($source === null) {
            throw new NotFoundHttpException("No translation source found for “$category”.");
        }

        $strings = Translingua::$plugin->translations->getSourceStrings($source);
        $overrides = Translingua::$plugin->translations->getOverrides($source, $locale);
        $base = Translingua::$plugin->translations->getBaseTranslations($source, $locale);

        $missing = [];

        foreach ($strings as $key => $text) {
            // Leave the plugin's own translations alone — only fill real gaps.
            if (($overrides[$key] ?? '') === '' && ($base[$key] ?? '') === '' && trim($text) !== '') {
                $missing[$key] = $text;
            }
        }

        if ($missing === []) {
            $this->setSuccessFlash(Craft::t('translingua', 'Nothing left to translate.'));
            return $this->redirectToPostedUrl();
        }

        try {
            $translated = Translingua::$plugin->translator->translate(
                $missing,
                $source->sourceLanguage,
                $locale,
            );
        } catch (TranslatorException $e) {
            $this->setFailFlash($e->getMessage());
            return null;
        }

        Translingua::$plugin->translations->saveOverrides($source, $locale, $translated);

        $this->setSuccessFlash(Craft::t('translingua', '{count} strings translated.', [
            'count' => count($translated),
        ]));

        return $this->redirectToPostedUrl();
    }

    private function matches(string $search, string ...$haystacks): bool
    {
        foreach ($haystacks as $haystack) {
            if (stripos($haystack, $search) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keys are base64url-encoded in the form so arbitrary characters — dots,
     * spaces, quotes — survive PHP's parsing of input names.
     */
    private function decodeKey(string $key): string
    {
        $decoded = base64_decode(strtr($key, '-_', '+/'), true);

        return $decoded !== false && $decoded !== '' ? $decoded : $key;
    }
}
