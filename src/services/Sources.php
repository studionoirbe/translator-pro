<?php

namespace studionoir\translatorpro\services;

use Craft;
use craft\base\Component;
use craft\base\PluginInterface;
use studionoir\translatorpro\models\TranslationSource;
use studionoir\translatorpro\TranslatorPro;

/**
 * Discovers every pool of static translations that can be edited.
 *
 * A plugin only shows up once it is actually installed and enabled, and only
 * when it ships language files of its own.
 */
class Sources extends Component
{
    /**
     * @var TranslationSource[]|null
     */
    private ?array $sources = null;

    /**
     * All editable sources, keyed by translation category.
     *
     * @return array<string,TranslationSource>
     */
    public function getAll(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $sources = [];

        $sources['site'] = new TranslationSource([
            'category' => 'site',
            'name' => Craft::t('translator-pro', 'Templates'),
            'type' => TranslationSource::TYPE_SITE,
            'basePath' => null,
            'sourceLanguage' => 'en',
        ]);

        $appPath = Craft::getAlias('@app/translations');

        if (is_dir($appPath)) {
            $sources['app'] = new TranslationSource([
                'category' => 'app',
                'name' => Craft::t('translator-pro', 'Craft control panel'),
                'type' => TranslationSource::TYPE_APP,
                'basePath' => $appPath,
                'sourceLanguage' => 'en',
            ]);
        }

        $plugins = [];

        foreach ($this->pluginSources() as $source) {
            // Never let a plugin quietly shadow `site` or `app`.
            if (isset($sources[$source->category]) || isset($plugins[$source->category])) {
                continue;
            }

            $plugins[$source->category] = $source;
        }

        uasort($plugins, static fn(TranslationSource $a, TranslationSource $b) => strcasecmp($a->name, $b->name));

        // Templates first, then Craft, then plugins alphabetically.
        $this->sources = $sources + $plugins;

        $this->attachCounts();

        // A plugin that ships an empty language file has nothing to edit.
        $this->sources = array_filter(
            $this->sources,
            static fn(TranslationSource $source) => $source->type !== TranslationSource::TYPE_PLUGIN
                || $source->stringCount > 0,
        );

        return $this->sources;
    }

    public function getByCategory(string $category): ?TranslationSource
    {
        return $this->getAll()[$category] ?? null;
    }

    /**
     * Sources contributed by installed plugins.
     *
     * @return TranslationSource[]
     */
    private function pluginSources(): array
    {
        $sources = [];

        foreach (Craft::$app->getPlugins()->getAllPlugins() as $plugin) {
            /** @var PluginInterface&\craft\base\Plugin $plugin */
            if ($plugin->id === TranslatorPro::$plugin?->id) {
                continue;
            }

            $basePath = $plugin->getBasePath() . DIRECTORY_SEPARATOR . 'translations';

            if (!is_dir($basePath)) {
                continue;
            }

            $category = $plugin->t9nCategory;
            $sourceLanguage = $this->resolveSourceLanguage($basePath, $plugin->sourceLanguage);

            if ($sourceLanguage === null) {
                // Ships a translations dir but nothing we can read source strings from.
                continue;
            }

            if (!is_file($basePath . DIRECTORY_SEPARATOR . $sourceLanguage . DIRECTORY_SEPARATOR . $category . '.php')) {
                continue;
            }

            $sources[] = new TranslationSource([
                'category' => $category,
                'name' => $plugin->name,
                'type' => TranslationSource::TYPE_PLUGIN,
                'basePath' => $basePath,
                'sourceLanguage' => $sourceLanguage,
                'pluginHandle' => $plugin->id,
            ]);
        }

        return $sources;
    }

    /**
     * Plugins declare a source language like `en-US` but usually ship an `en` folder
     * (or the other way round), so fall back through the sensible candidates.
     */
    private function resolveSourceLanguage(string $basePath, string $declared): ?string
    {
        $candidates = [$declared, substr($declared, 0, 2), 'en', 'en-US'];

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate !== '' && is_dir($basePath . DIRECTORY_SEPARATOR . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Fills in the string/translated counts shown on the index.
     */
    private function attachCounts(): void
    {
        $translations = TranslatorPro::$plugin->translations;
        $locales = $translations->getTargetLocales();

        foreach ($this->sources as $source) {
            $sourceStrings = $translations->getSourceStrings($source);
            $source->stringCount = count($sourceStrings);

            $translated = 0;

            foreach (array_keys($sourceStrings) as $key) {
                foreach ($locales as $locale) {
                    $existing = $translations->getOverrides($source, $locale);

                    if (isset($existing[$key]) && $existing[$key] !== '') {
                        $translated++;
                        break;
                    }
                }
            }

            $source->translatedCount = $translated;
        }
    }
}
