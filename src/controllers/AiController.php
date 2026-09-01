<?php

namespace studionoir\translingua\controllers;

use Craft;
use craft\web\Controller;
use studionoir\translingua\translators\TranslatorException;
use studionoir\translingua\Translingua;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Backs the translate buttons injected into the control panel.
 *
 * The buttons read and write the DOM, so this endpoint never touches elements —
 * it only translates the strings the browser sends, and the editor still has to
 * press Save. That's what makes the buttons work identically inside Formie,
 * SEOmatic or any other plugin's screens.
 */
class AiController extends Controller
{
    /**
     * Guard rails against a runaway page: 400 fields or ~200k characters per request.
     */
    private const MAX_TEXTS = 400;
    private const MAX_CHARS = 200000;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAcceptsJson();

        if (!Translingua::$plugin->isPlus()) {
            throw new ForbiddenHttpException(Craft::t('translingua', 'AI translations require the Plus edition.'));
        }

        $this->requirePermission(Translingua::PERMISSION_AI);

        // The buttons are only rendered for a verified connection, so reaching
        // here unverified means either a stale page or a direct call.
        $translator = Translingua::$plugin->translator;

        if (!$translator->isVerified() && !$translator->verify()) {
            throw new ForbiddenHttpException(
                $translator->getVerificationError()
                    ?? Craft::t('translingua', 'The AI provider connection hasn’t been verified.'),
            );
        }

        return true;
    }

    /**
     * Translates a batch of strings for the page currently being edited.
     *
     * Expects `items` as a list of `{id, text, format}` objects and returns
     * `translations` as an `id => text` map. IDs are opaque to the server: the
     * JS uses them to find its way back to the right input.
     */
    public function actionTranslate(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $items = (array)$request->getBodyParam('items', []);
        $sourceLanguage = $request->getBodyParam('sourceLanguage');
        $targetSiteId = (int)$request->getBodyParam('targetSiteId', 0);
        $targetLanguage = (string)$request->getBodyParam('targetLanguage', '');

        if ($targetLanguage === '' && $targetSiteId > 0) {
            $targetLanguage = (string)Craft::$app->getSites()->getSiteById($targetSiteId)?->language;
        }

        if ($targetLanguage === '') {
            $targetLanguage = Craft::$app->getSites()->getCurrentSite()->language;
        }

        if ($sourceLanguage === $targetLanguage) {
            return $this->asFailure(Craft::t('translingua', 'Pick a different language to translate from.'));
        }

        if (count($items) > self::MAX_TEXTS) {
            return $this->asFailure(Craft::t('translingua', 'Too many fields in one request. Translate the page in sections.'));
        }

        // Group by format so HTML fields keep their markup.
        $byFormat = [];
        $characters = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = (string)($item['id'] ?? '');
            $text = (string)($item['text'] ?? '');
            $format = ($item['format'] ?? 'text') === 'html' ? 'html' : 'text';

            if ($id === '' || trim($text) === '') {
                continue;
            }

            $characters += mb_strlen($text);

            if ($characters > self::MAX_CHARS) {
                return $this->asFailure(Craft::t('translingua', 'Too much content in one request. Translate the page in sections.'));
            }

            $byFormat[$format][$id] = $text;
        }

        if ($byFormat === []) {
            return $this->asSuccess(data: ['translations' => []]);
        }

        $translations = [];

        foreach ($byFormat as $format => $group) {
            try {
                $translations += Translingua::$plugin->translator->translate(
                    $group,
                    is_string($sourceLanguage) && $sourceLanguage !== '' ? $sourceLanguage : null,
                    $targetLanguage,
                    $format,
                );
            } catch (TranslatorException $e) {
                return $this->asFailure($e->getMessage());
            } catch (\Throwable $e) {
                Craft::error("Translation request failed: {$e->getMessage()}", __METHOD__);
                return $this->asFailure(Craft::t('translingua', 'Translation failed. Check the logs for details.'));
            }
        }

        return $this->asSuccess(data: [
            'translations' => $translations,
            'targetLanguage' => $targetLanguage,
        ]);
    }
}
