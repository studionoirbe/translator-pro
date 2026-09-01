<?php

namespace studionoir\translingua\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;

/**
 * Describes one editable pool of static translations.
 *
 * Three flavours exist:
 *  - `site`   the project's own template strings (`translations/{locale}/site.php`)
 *  - `app`    Craft's own control panel strings
 *  - `plugin` any installed plugin that ships language files
 */
class TranslationSource extends Model
{
    public const TYPE_SITE = 'site';
    public const TYPE_APP = 'app';
    public const TYPE_PLUGIN = 'plugin';

    /**
     * @var string The Craft translation category, e.g. `site`, `app`, `formie`.
     */
    public string $category = '';

    /**
     * @var string Human readable name.
     */
    public string $name = '';

    public string $type = self::TYPE_PLUGIN;

    /**
     * @var string|null Absolute path to the source translations directory
     * (the one holding `en/`, `nl/`, …). Null for the `site` category, whose
     * source strings come from a template scan rather than a file.
     */
    public ?string $basePath = null;

    /**
     * @var string The language the source strings are written in.
     */
    public string $sourceLanguage = 'en';

    /**
     * @var string|null Handle of the plugin this source belongs to.
     */
    public ?string $pluginHandle = null;

    /**
     * @var int Number of source strings available.
     */
    public int $stringCount = 0;

    /**
     * @var int Number of strings that already have a translation in at least one target language.
     */
    public int $translatedCount = 0;

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('translingua/static/' . $this->category);
    }

    /**
     * The file the overrides are written to for a given locale.
     */
    public function getOverrideFile(string $locale): string
    {
        return sprintf(
            '%s%s%s%s%s.php',
            Craft::getAlias('@translations'),
            DIRECTORY_SEPARATOR,
            $locale,
            DIRECTORY_SEPARATOR,
            $this->category,
        );
    }

    /**
     * `site` strings are discovered by scanning templates rather than read from a file.
     */
    public function isScanned(): bool
    {
        return $this->type === self::TYPE_SITE;
    }
}
