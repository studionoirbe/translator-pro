<?php

namespace studionoir\translingua\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;

/**
 * Translingua settings.
 *
 * @property-read string $resolvedApiKey
 */
class Settings extends Model
{
    public const PROVIDER_DEEPL = 'deepl';
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_GOOGLE = 'google';

    /**
     * @var string Which AI provider to use for translations.
     */
    public string $provider = self::PROVIDER_DEEPL;

    /**
     * @var string API key for the selected provider. Supports environment variables ($MY_VAR).
     */
    public string $apiKey = '';

    /**
     * @var string Model name for providers that expose one (OpenAI / Anthropic / Google).
     * Left empty, a sensible per-provider default is used.
     */
    public string $model = '';

    /**
     * @var bool Whether DeepL should use the free API endpoint.
     * Ignored when the key ends in `:fx`, which is auto-detected.
     */
    public bool $deeplFreeApi = false;

    /**
     * @var string Extra instructions appended to the prompt for LLM providers.
     */
    public string $promptContext = '';

    /**
     * @var bool Whether to show per-field translate buttons throughout the control panel.
     */
    public bool $enableFieldButtons = true;

    /**
     * @var bool Whether to show the "Translate page" button next to Save.
     */
    public bool $enablePageButton = true;

    /**
     * @var bool Whether the page/field buttons overwrite fields that already have content.
     */
    public bool $overwriteExisting = false;

    /**
     * @var string[] Input `name` fragments that should never get a translate button.
     *
     * Kept deliberately short. Matching is a substring test, so a broad word
     * like `url` also knocks out a field handled `fieldLinkUrl`; actual URLs are
     * caught by inspecting the value instead.
     */
    public array $excludedFieldPatterns = [
        'handle',
        'slug',
        'template',
        'uri',
        'uriFormat',
        'csrf',
        'password',
        'redirect',
        'CRAFT_CSRF_TOKEN',
    ];

    /**
     * @var string[] Control panel paths where the "Translate page" button may
     * appear. A `*` matches one path segment, and a pattern matches anything
     * below it, so `globals/*` covers `globals/globalHeader`.
     *
     * Per-field buttons are not affected: those stay available wherever you can
     * type, which is what makes them work inside other plugins' screens.
     */
    public array $pageButtonPaths = [
        // Entry edit pages, including drafts and revisions below them.
        // Craft 5.9 moved these to content/{page}/{section}/{id}; the older
        // shape still routes, and older saved settings still use it.
        'content/*/*/*',
        'entries/*/*',
        // Category edit pages.
        'categories/*/*',
        // Global set edit pages.
        'globals/*',
        // SEOmatic — Global SEO.
        'seomatic/global',
        // SEOmatic — Content SEO.
        'seomatic/edit-content',
    ];

    /**
     * @var string[] UIDs of sections whose entries don't get the page button,
     * set from a toggle on the section's own settings screen.
     */
    public array $disabledSectionUids = [];

    /**
     * @var string[] UIDs of global sets that don't get the page button.
     */
    public array $disabledGlobalSetUids = [];

    /**
     * @var string[] UIDs of category groups Translingua is switched off for.
     */
    public array $disabledCategoryGroupUids = [];

    /**
     * @var string[] Control panel paths where no translate buttons appear at all.
     *
     * These are the screens where you type configuration rather than content —
     * naming a field, pasting a licence key, writing a section handle. Matching
     * works like {@see $pageButtonPaths}: a path also covers everything below it,
     * so `settings` covers `settings/fields/new`.
     */
    public array $fieldButtonExcludedPaths = [
        // Craft's own administrative areas.
        'settings',
        'plugin-store',
        'utilities',
        'updates',
        'myaccount',
        // User accounts aren't localised, so there's nothing to translate into.
        'users',
        // Plugin configuration, as opposed to the content those plugins hold.
        'formie/settings',
        // Our own screens: the translation editor is not itself translatable.
        'translingua',
    ];

    /**
     * @var string[] Additional template directories (aliases allowed) to scan for
     * translatable strings, on top of the project's template root.
     */
    public array $extraScanPaths = [];

    /**
     * @var string[] File extensions scanned for `t()` / `|t` calls.
     */
    public array $scanExtensions = ['twig', 'html', 'php', 'js'];

    /**
     * @var int How many strings are sent to the provider in a single request.
     */
    public int $batchSize = 25;

    /**
     * @var bool Whether AI batch jobs should create a new revision per saved element.
     */
    public bool $createRevisions = true;

    /**
     * Craft funnels both the settings form and project config through
     * `setSettings()`, which lands here.
     *
     * The three list settings are edited with `editableTableField`, which posts
     * rows shaped like `[['value' => 'handle'], …]` rather than a flat list.
     * Storing those verbatim breaks every consumer — and the settings screen
     * itself, with "Array to string conversion" — so they're flattened on the
     * way in, wherever they came from.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        parent::setAttributes($values, $safeOnly);

        $this->excludedFieldPatterns = self::toStringList($this->excludedFieldPatterns);
        $this->pageButtonPaths = self::toStringList($this->pageButtonPaths);
        $this->fieldButtonExcludedPaths = self::toStringList($this->fieldButtonExcludedPaths);
        $this->disabledSectionUids = self::toStringList($this->disabledSectionUids);
        $this->disabledGlobalSetUids = self::toStringList($this->disabledGlobalSetUids);
        $this->disabledCategoryGroupUids = self::toStringList($this->disabledCategoryGroupUids);
        $this->extraScanPaths = self::toStringList($this->extraScanPaths);
        $this->scanExtensions = self::toStringList($this->scanExtensions);
    }

    /**
     * Coerces whatever an editable table, project config or a config file hands
     * us into a flat list of non-empty strings.
     *
     * @return string[]
     */
    private static function toStringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            // A newline- or comma-separated string is a reasonable thing to
            // write by hand in config/translingua.php.
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                // An editable table row: take `value`, else the first column.
                $item = $item['value'] ?? reset($item);
            }

            if (!is_scalar($item)) {
                continue;
            }

            $item = trim((string)$item);

            if ($item !== '') {
                $list[] = $item;
            }
        }

        return array_values(array_unique($list));
    }

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiKey'],
            ],
        ];
    }

    public function defineRules(): array
    {
        return [
            [['provider'], 'required'],
            [['provider'], 'in', 'range' => array_keys(self::providerOptions())],
            [['batchSize'], 'integer', 'min' => 1, 'max' => 100],
            [['apiKey', 'model', 'promptContext'], 'string'],
            [['enableFieldButtons', 'enablePageButton', 'overwriteExisting', 'deeplFreeApi', 'createRevisions'], 'boolean'],
            [
                [
                    'excludedFieldPatterns', 'extraScanPaths', 'scanExtensions', 'pageButtonPaths',
                    'fieldButtonExcludedPaths', 'disabledSectionUids', 'disabledGlobalSetUids',
                    'disabledCategoryGroupUids',
                ],
                'safe',
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function providerOptions(): array
    {
        return [
            self::PROVIDER_DEEPL => 'DeepL',
            self::PROVIDER_OPENAI => 'OpenAI',
            self::PROVIDER_ANTHROPIC => 'Anthropic (Claude)',
            self::PROVIDER_GOOGLE => 'Google Gemini',
        ];
    }

    /**
     * Returns the API key with any environment variable resolved.
     */
    public function getResolvedApiKey(): string
    {
        return trim((string)App::parseEnv($this->apiKey));
    }

    /**
     * Whether enough is configured to actually talk to a provider.
     */
    public function isConfigured(): bool
    {
        return $this->getResolvedApiKey() !== '';
    }

    /**
     * Identifies the current credentials, so a verified connection stops
     * counting as verified the moment the key, provider or model changes.
     */
    public function credentialsFingerprint(): string
    {
        return md5(implode('|', [
            $this->provider,
            $this->getResolvedApiKey(),
            $this->model,
            $this->deeplFreeApi ? '1' : '0',
        ]));
    }

    /**
     * Whether a control panel path is one of the screens the page button is
     * allowed on.
     *
     * A pattern matches as a prefix: `seomatic/global` also covers
     * `seomatic/global/general/nl`, so tabbed screens don't each need listing.
     *
     * @param string[] $segments Path segments, without the CP trigger.
     */
    public function allowsPageButton(array $segments): bool
    {
        foreach (self::pathVariants($segments) as $candidate) {
            if (self::matchesPath($this->pageButtonPaths, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ways a control panel path can be written.
     *
     * Craft 5.9 moved entry editing from `entries/{section}/{id}` to
     * `content/{page}/{section}/{id}`. Both are matched, so a settings list
     * saved before the change keeps working and one written afterwards reads
     * the way the URL bar does.
     *
     * @param string[] $segments
     * @return string[][]
     */
    private static function pathVariants(array $segments): array
    {
        $variants = [$segments];

        if (count($segments) >= 4 && strcasecmp($segments[0], 'content') === 0) {
            $variants[] = array_merge(['entries'], array_slice($segments, 2));
        }

        return $variants;
    }

    /**
     * Whether the page button is switched on for a section.
     */
    public function allowsSection(?string $uid): bool
    {
        return $uid === null || !in_array($uid, self::toStringList($this->disabledSectionUids), true);
    }

    /**
     * Whether the page button is switched on for a global set.
     */
    public function allowsGlobalSet(?string $uid): bool
    {
        return $uid === null || !in_array($uid, self::toStringList($this->disabledGlobalSetUids), true);
    }

    /**
     * Whether Translingua is switched on for a category group.
     */
    public function allowsCategoryGroup(?string $uid): bool
    {
        return $uid === null || !in_array($uid, self::toStringList($this->disabledCategoryGroupUids), true);
    }

    /**
     * Adds or removes a UID from one of the per-source lists.
     *
     * @param string[] $list
     * @return string[]
     */
    public static function toggleUid(array $list, string $uid, bool $enabled): array
    {
        $list = self::toStringList($list);

        if ($enabled) {
            return array_values(array_diff($list, [$uid]));
        }

        return array_values(array_unique(array_merge($list, [$uid])));
    }

    /**
     * Whether this control panel screen is off-limits to every translate button.
     *
     * @param string[] $segments Path segments, without the CP trigger.
     */
    public function excludesFieldButtons(array $segments): bool
    {
        return self::matchesPath($this->fieldButtonExcludedPaths, $segments);
    }

    /**
     * Matches path segments against a list of patterns.
     *
     * A pattern matches as a prefix — `settings` covers `settings/fields/new` —
     * and `*` stands in for exactly one segment.
     *
     * @param string[]|mixed $patterns
     * @param string[] $segments
     */
    private static function matchesPath(mixed $patterns, array $segments): bool
    {
        foreach (self::toStringList($patterns) as $pattern) {
            $parts = explode('/', trim($pattern, '/'));

            if (count($parts) > count($segments)) {
                continue;
            }

            foreach ($parts as $i => $part) {
                if ($part !== '*' && strcasecmp($part, $segments[$i]) !== 0) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Normalises the excluded patterns to lowercase, non-empty values.
     *
     * @return string[]
     */
    public function normalizedExcludedPatterns(): array
    {
        $patterns = array_map(
            static fn(string $p) => strtolower($p),
            self::toStringList($this->excludedFieldPatterns),
        );

        return array_values(array_filter($patterns, static fn($p) => $p !== ''));
    }
}
