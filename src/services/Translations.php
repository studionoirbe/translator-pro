<?php

namespace studionoir\translatorpro\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use studionoir\translatorpro\models\TranslationSource;
use studionoir\translatorpro\TranslatorPro;
use yii\base\Exception;

/**
 * Reads and writes the static translation files.
 *
 * Everything is written to `{root}/translations/{locale}/{category}.php`. Craft
 * registers every plugin's message source with `allowOverrides`, so a file in
 * that folder transparently overrides the plugin's own language file without
 * touching anything inside `vendor/`.
 */
class Translations extends Component
{
    /**
     * @var array<string,array<string,string>>
     */
    private array $overrideCache = [];

    /**
     * @var array<string,array<string,string>>
     */
    private array $sourceCache = [];

    /**
     * @var array<string,array<string,string>>
     */
    private array $baseCache = [];

    /**
     * Every locale a translation can be written for, derived from the site languages.
     *
     * @return string[]
     */
    public function getTargetLocales(): array
    {
        $locales = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $locales[$site->language] = $site->language;
        }

        ksort($locales);

        return array_values($locales);
    }

    /**
     * Locales mapped to a readable label, e.g. `nl` => `Dutch (nl)`.
     *
     * @return array<string,string>
     */
    public function getTargetLocaleOptions(): array
    {
        $options = [];

        foreach ($this->getTargetLocales() as $locale) {
            $options[$locale] = sprintf(
                '%s (%s)',
                Craft::$app->getI18n()->getLocaleById($locale)->getDisplayName(Craft::$app->language),
                $locale,
            );
        }

        return $options;
    }

    /**
     * The untranslated source strings for a source, as `key => source text`.
     *
     * @return array<string,string>
     */
    public function getSourceStrings(TranslationSource $source): array
    {
        if (isset($this->sourceCache[$source->category])) {
            return $this->sourceCache[$source->category];
        }

        if ($source->isScanned()) {
            $strings = TranslatorPro::$plugin->scanner->getStrings();
        } else {
            $file = sprintf(
                '%s%s%s%s%s.php',
                $source->basePath,
                DIRECTORY_SEPARATOR,
                $source->sourceLanguage,
                DIRECTORY_SEPARATOR,
                $source->category,
            );

            $strings = $this->readFile($file);

            // Plugin language files are `source => translation` in the source language.
            // The key is what `Craft::t()` is actually called with, so that is what we edit.
            $strings = array_combine(
                array_keys($strings),
                array_map(
                    static fn($key, $value) => $value !== '' ? $value : $key,
                    array_keys($strings),
                    $strings,
                ),
            );
        }

        // Anything already overridden but no longer present in the source stays visible,
        // so hand-written or since-removed strings are never silently dropped.
        foreach ($this->getTargetLocales() as $locale) {
            foreach (array_keys($this->getOverrides($source, $locale)) as $key) {
                if (!array_key_exists($key, $strings)) {
                    $strings[$key] = $key;
                }
            }
        }

        ksort($strings, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->sourceCache[$source->category] = $strings;
    }

    /**
     * The translations a source already ships for a locale.
     *
     * Formie, Craft and most plugins bundle their own Dutch, French and so on.
     * Those are what the site is using today, so the editor sees them in the
     * field rather than a blank box that suggests nothing is translated.
     *
     * @return array<string,string>
     */
    public function getBaseTranslations(TranslationSource $source, string $locale): array
    {
        if ($source->basePath === null) {
            // `site` has no bundled layer — the project file is the only one.
            return [];
        }

        $cacheKey = $source->category . '/' . $locale;

        if (isset($this->baseCache[$cacheKey])) {
            return $this->baseCache[$cacheKey];
        }

        $messages = $this->readFile($this->baseFile($source, $locale));

        // Plugins ship `nl` where Craft asks for `nl-BE`, and occasionally the
        // other way round, so fall back the same way Yii does.
        $fallback = substr($locale, 0, 2);

        if ($messages === [] && $fallback !== $locale) {
            $messages = $this->readFile($this->baseFile($source, $fallback));
        }

        return $this->baseCache[$cacheKey] = $messages;
    }

    /**
     * The translation actually in effect for a key: the project override if
     * there is one, otherwise whatever the source ships.
     */
    public function getEffectiveTranslation(TranslationSource $source, string $locale, string $key): string
    {
        $override = $this->getOverrides($source, $locale)[$key] ?? '';

        if ($override !== '') {
            return $override;
        }

        return $this->getBaseTranslations($source, $locale)[$key] ?? '';
    }

    /**
     * The overrides currently stored for a source + locale.
     *
     * @return array<string,string>
     */
    public function getOverrides(TranslationSource $source, string $locale): array
    {
        $key = $source->category . '/' . $locale;

        if (isset($this->overrideCache[$key])) {
            return $this->overrideCache[$key];
        }

        return $this->overrideCache[$key] = $this->readFile($source->getOverrideFile($locale));
    }

    /**
     * Writes overrides for one source + locale.
     *
     * Empty values are removed rather than written as empty strings, so the
     * plugin's original string keeps showing through.
     *
     * @param array<string,string> $translations
     * @throws Exception if the file can't be written
     */
    public function saveOverrides(TranslationSource $source, string $locale, array $translations): bool
    {
        $existing = $this->getOverrides($source, $locale);
        $merged = array_merge($existing, $translations);

        $base = $this->getBaseTranslations($source, $locale);

        $merged = array_filter(
            $merged,
            // Blank removes the override; so does re-saving the shipped
            // translation unchanged, which would otherwise duplicate a plugin's
            // entire language file into the project for no benefit.
            static fn($value, $key) => is_string($value)
                && trim($value) !== ''
                && $value !== ($base[$key] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );

        ksort($merged, SORT_NATURAL | SORT_FLAG_CASE);

        $file = $source->getOverrideFile($locale);

        if ($merged === []) {
            if (is_file($file)) {
                FileHelper::unlink($file);
            }
        } else {
            FileHelper::writeToFile($file, $this->renderFile($source, $locale, $merged));
        }

        unset($this->overrideCache[$source->category . '/' . $locale]);
        $this->sourceCache = [];

        $this->invalidate($file);

        return true;
    }

    /**
     * Deletes a single key from a source across every locale.
     */
    public function deleteKey(TranslationSource $source, string $key): void
    {
        foreach ($this->getTargetLocales() as $locale) {
            $overrides = $this->getOverrides($source, $locale);

            if (!array_key_exists($key, $overrides)) {
                continue;
            }

            unset($overrides[$key]);

            $file = $source->getOverrideFile($locale);

            if ($overrides === []) {
                if (is_file($file)) {
                    FileHelper::unlink($file);
                }
            } else {
                FileHelper::writeToFile($file, $this->renderFile($source, $locale, $overrides));
            }

            unset($this->overrideCache[$source->category . '/' . $locale]);
            $this->invalidate($file);
        }

        $this->sourceCache = [];
    }

    /**
     * A single translation, falling back to an empty string.
     */
    public function getTranslation(TranslationSource $source, string $locale, string $key): string
    {
        return $this->getOverrides($source, $locale)[$key] ?? '';
    }

    /**
     * How many of a source's strings are still missing for a locale.
     */
    public function getMissingCount(TranslationSource $source, string $locale): int
    {
        $overrides = $this->getOverrides($source, $locale);
        $base = $this->getBaseTranslations($source, $locale);
        $missing = 0;

        foreach (array_keys($this->getSourceStrings($source)) as $key) {
            // A string the plugin already translates isn't missing.
            if (($overrides[$key] ?? '') === '' && ($base[$key] ?? '') === '') {
                $missing++;
            }
        }

        return $missing;
    }

    private function baseFile(TranslationSource $source, string $locale): string
    {
        return sprintf(
            '%s%s%s%s%s.php',
            $source->basePath,
            DIRECTORY_SEPARATOR,
            $locale,
            DIRECTORY_SEPARATOR,
            $source->category,
        );
    }

    /**
     * @return array<string,string>
     */
    private function readFile(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        try {
            $contents = require $file;
        } catch (\Throwable $e) {
            Craft::error("Couldn't read translation file $file: {$e->getMessage()}", __METHOD__);
            return [];
        }

        if (!is_array($contents)) {
            return [];
        }

        $clean = [];

        foreach ($contents as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $clean[$key] = (string)$value;
            }
        }

        return $clean;
    }

    /**
     * @param array<string,string> $translations
     */
    private function renderFile(TranslationSource $source, string $locale, array $translations): string
    {
        $lines = [
            '<?php',
            '',
            '/**',
            ' * ' . $source->name . ' — ' . $locale,
            ' *',
            ' * Managed by Translator Pro. Overrides the `' . $source->category . '` translation category.',
            ' * Hand edits are preserved, but keys are re-sorted whenever this file is saved.',
            ' */',
            '',
            'return [',
        ];

        foreach ($translations as $key => $value) {
            $lines[] = sprintf(
                '    %s => %s,',
                var_export((string)$key, true),
                var_export((string)$value, true),
            );
        }

        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Translation files are `require`d, so a stale opcache entry would hide the change.
     */
    private function invalidate(string $file): void
    {
        if (function_exists('opcache_invalidate') && ini_get('opcache.enable')) {
            @opcache_invalidate($file, true);
        }
    }
}
