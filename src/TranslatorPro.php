<?php

namespace studionoir\translatorpro;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\Cp;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\UrlManager;
use craft\web\View;
use studionoir\translatorpro\assetbundles\cp\FieldTranslateAsset;
use studionoir\translatorpro\models\Settings;
use studionoir\translatorpro\services\Batch;
use studionoir\translatorpro\services\Content;
use studionoir\translatorpro\services\ElementTranslator;
use studionoir\translatorpro\services\FormieBridge;
use studionoir\translatorpro\services\Scanner;
use studionoir\translatorpro\services\Sources;
use studionoir\translatorpro\services\Translations;
use studionoir\translatorpro\services\Translator;
use yii\base\Event;

/**
 * Translator Pro
 *
 * Free (Lite) edition
 *  - Static template translations, Enupal-Translate style
 *  - Formie translation overrides
 *  - Override the language files of any installed plugin
 *
 * Pro edition
 *  - AI translations through DeepL / OpenAI / Anthropic / Google
 *  - A translate button on every input field in the control panel
 *  - A "Translate page" button next to Save
 *  - Batch translation of entries, categories and globals
 *
 * @author Studio Noir <info@studionoir.be>
 *
 * @property-read Sources $sources
 * @property-read Scanner $scanner
 * @property-read Translations $translations
 * @property-read Translator $translator
 * @property-read Content $content
 * @property-read Batch $batch
 * @property-read ElementTranslator $elementTranslator
 * @property-read FormieBridge $formie
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class TranslatorPro extends Plugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_STATIC = 'translatorPro:manageStaticTranslations';
    public const PERMISSION_AI = 'translatorPro:useAiTranslations';

    public static ?TranslatorPro $plugin = null;

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'sources' => ['class' => Sources::class],
                'scanner' => ['class' => Scanner::class],
                'translations' => ['class' => Translations::class],
                'translator' => ['class' => Translator::class],
                'content' => ['class' => Content::class],
                'batch' => ['class' => Batch::class],
                'elementTranslator' => ['class' => ElementTranslator::class],
                'formie' => ['class' => FormieBridge::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerFormieTab();
        $this->registerSourceToggles();

        // Everything below only makes sense once Craft is fully booted.
        Craft::$app->onInit(function() {
            $this->registerCpAssets();
        });
    }

    /**
     * Whether the Pro (AI) features are available.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    /**
     * Whether the current user may use the AI features.
     */
    public function canUseAi(): bool
    {
        if (!$this->isPro()) {
            return false;
        }

        $user = Craft::$app->getUser()->getIdentity();

        return $user !== null && $user->can(self::PERMISSION_AI);
    }

    /**
     * Whether the AI translate buttons should appear at all.
     *
     * Deliberately stricter than {@see canUseAi()}: the buttons only show up
     * once a key has been entered *and* a real request to the provider has
     * succeeded. A button that fails the moment it's pressed is worse than no
     * button, so an unverified connection means no buttons.
     */
    public function aiIsReady(): bool
    {
        return $this->canUseAi() && $this->translator->isVerified();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('translator-pro/settings'),
        );
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();
        $subNav = [];

        if ($user?->can(self::PERMISSION_STATIC)) {
            $subNav['static'] = [
                'label' => Craft::t('translator-pro', 'Static translations'),
                'url' => 'translator-pro/static',
            ];
        }

        if ($this->canUseAi()) {
            $subNav['ai'] = [
                'label' => Craft::t('translator-pro', 'AI translations'),
                'url' => 'translator-pro/ai',
            ];
        }

        if ($user?->admin) {
            $subNav['settings'] = [
                'label' => Craft::t('translator-pro', 'Settings'),
                'url' => 'translator-pro/settings',
            ];
        }

        if ($subNav === []) {
            return null;
        }

        $item['label'] = Craft::t('translator-pro', 'Translator Pro');
        $item['subnav'] = $subNav;

        return $item;
    }

    /**
     * Adds a Translator Pro tab to Formie's form builder.
     *
     * Formie builds its tabs in a controller with no event to hook, and its
     * builder template resolves each pane as `formie/forms/_panes/{value}`.
     * Craft lets several directories share one template root, so registering
     * ours under `formie` makes the pane resolvable without touching Formie,
     * and the tab itself is appended to the template variables on the way out.
     */
    private function registerFormieTab(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                // Every handler shares one $roots array, and Formie has already
                // claimed this key for its own template directory. Assigning
                // would replace it and break the whole plugin, so append —
                // Craft resolves a root's directories in order and falls
                // through to Formie's for anything we don't provide.
                $existing = $event->roots['formie'] ?? [];

                $event->roots['formie'] = array_merge(
                    (array)$existing,
                    [$this->getBasePath() . DIRECTORY_SEPARATOR . 'formie-templates'],
                );
            },
        );

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                if ($event->template !== 'formie/forms/_edit') {
                    return;
                }

                if (!$this->formie->isAvailable() || empty($event->variables['form']?->id)) {
                    return;
                }

                // The tab is Pro-only: the language it sets exists purely to
                // tell the AI what to translate into, and the translate button
                // below it is Pro too. On Lite the whole tab would be inert.
                // Gated on the edition rather than on aiIsReady(), so a key that
                // has momentarily stopped verifying doesn't hide the setting.
                if (!$this->isPro()) {
                    return;
                }

                $user = Craft::$app->getUser();
                $form = $event->variables['form'];

                if (
                    !$user->checkPermission('formie-manageForms') &&
                    !$user->checkPermission('formie-manageForms:' . $form->uid)
                ) {
                    return;
                }

                $tab = [
                    'label' => Craft::t('translator-pro', 'Translator Pro'),
                    'value' => 'translator-pro',
                    'url' => '#tab-translator-pro',
                ];

                // Formie reads both, and Craft nulls `tabs` when there's only one.
                foreach (['tabs', 'formTabs'] as $key) {
                    if (isset($event->variables[$key]) && is_array($event->variables[$key])) {
                        $event->variables[$key][] = $tab;
                    }
                }
            },
        );
    }

    /**
     * Adds a "Translate page button" toggle to each section's and global set's
     * own settings screen, and stores what the editor picks.
     *
     * Craft offers no template hook on either screen, so the field is rendered
     * server-side and dropped into the form from JS. The value comes back on
     * the normal save request, which is why it's read from the save events
     * rather than from a controller of our own.
     */
    private function registerSourceToggles(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            function(TemplateEvent $event) {
                if ($event->template === 'settings/sections/_edit.twig') {
                    $section = $event->variables['section'] ?? null;

                    $this->renderSourceToggle(
                        $section?->uid,
                        $section?->uid !== null && $this->getSettings()->allowsSection($section->uid),
                        // A Single holds one entry, so "entries in this section"
                        // would be describing something that doesn't exist.
                        $section?->type === \craft\models\Section::TYPE_SINGLE
                            ? Craft::t('translator-pro', 'Make Translator Pro available for this section')
                            : Craft::t('translator-pro', 'Make Translator Pro available for entries in this section'),
                        '#enableVersioning-field',
                    );
                } elseif ($event->template === 'settings/globals/_edit.twig') {
                    $set = $event->variables['globalSet'] ?? null;

                    $this->renderSourceToggle(
                        $set?->uid,
                        $set?->uid !== null && $this->getSettings()->allowsGlobalSet($set->uid),
                        Craft::t('translator-pro', 'Make Translator Pro available for this global'),
                        '#handle-field',
                    );
                } elseif ($event->template === 'settings/categories/_edit.twig') {
                    $group = $event->variables['categoryGroup'] ?? null;

                    $this->renderSourceToggle(
                        $group?->uid,
                        $group?->uid !== null && $this->getSettings()->allowsCategoryGroup($group->uid),
                        Craft::t('translator-pro', 'Make Translator Pro available in this category group'),
                        '#default-placement-field',
                    );
                }
            },
        );

        // Hooked on the controller action, not on Entries::EVENT_AFTER_SAVE_SECTION.
        // That event fires from the project config listener, so it only runs when
        // the section's own config actually changed — and flipping this checkbox
        // changes nothing about the section, so it never fired at all.
        Event::on(
            \craft\controllers\SectionsController::class,
            \craft\web\Controller::EVENT_AFTER_ACTION,
            function(\yii\base\ActionEvent $event) {
                if ($event->action->id !== 'save-section') {
                    return;
                }

                $id = (int)Craft::$app->getRequest()->getBodyParam('sectionId');
                $uid = $id ? Craft::$app->getEntries()->getSectionById($id)?->uid : null;

                if ($uid !== null) {
                    $this->storeSourceToggle('disabledSectionUids', $uid);
                }
            },
        );

        Event::on(
            \craft\controllers\CategoriesController::class,
            \craft\web\Controller::EVENT_AFTER_ACTION,
            function(\yii\base\ActionEvent $event) {
                if ($event->action->id !== 'save-group') {
                    return;
                }

                $id = (int)Craft::$app->getRequest()->getBodyParam('groupId');
                $uid = $id ? Craft::$app->getCategories()->getGroupById($id)?->uid : null;

                if ($uid !== null) {
                    $this->storeSourceToggle('disabledCategoryGroupUids', $uid);
                }
            },
        );

        Event::on(
            \craft\controllers\GlobalsController::class,
            \craft\web\Controller::EVENT_AFTER_ACTION,
            function(\yii\base\ActionEvent $event) {
                if ($event->action->id !== 'save-set') {
                    return;
                }

                $id = (int)Craft::$app->getRequest()->getBodyParam('setId');
                $uid = $id ? Craft::$app->getGlobals()->getSetById($id)?->uid : null;

                if ($uid !== null) {
                    $this->storeSourceToggle('disabledGlobalSetUids', $uid);
                }
            },
        );
    }

    /**
     * Injects the toggle into the settings form.
     */
    private function renderSourceToggle(?string $uid, bool $on, string $instructions, string $anchor): void
    {
        // Everything this switch governs — the field buttons, the page button,
        // AI translations — is Pro only, so on Lite it would be a control that
        // does nothing.
        if (!$this->isPro()) {
            return;
        }

        if ($uid === null || !Craft::$app->getUser()->getIsAdmin()) {
            // A brand new section has no UID yet; it can be toggled once saved.
            return;
        }

        $html = \craft\helpers\Cp::lightswitchFieldHtml([
            'label' => Craft::t('translator-pro', 'Translator Pro'),
            'instructions' => $instructions,
            'id' => 'translatorProEnabled',
            'name' => 'translatorProEnabled',
            'on' => $on,
        ]);

        $js = <<<'JS'
(function() {
    var form = document.querySelector('#main-form') || document.querySelector('form');

    if (!form || form.querySelector('#translatorProEnabled')) {
        return;
    }

    var holder = document.createElement('div');
    holder.innerHTML = %s;

    var field = holder.firstElementChild;

    if (!field) {
        return;
    }

    // Sit with the settings it belongs beside, rather than at the bottom of the
    // form where it would read as an afterthought.
    var anchor = form.querySelector(%s);

    if (anchor) {
        anchor.insertAdjacentElement('afterend', field);
    } else {
        var buttons = form.querySelector('#settings-buttons, .buttons');

        if (buttons) {
            buttons.parentNode.insertBefore(field, buttons);
        } else {
            form.appendChild(field);
        }
    }

    // Required, not decorative: a lightswitch only changes the value it posts
    // once Craft has turned it into a Craft.LightSwitch. Injected markup misses
    // the control panel's own init pass, so it has to be wired here — and if it
    // isn't, the switch flips on screen while still submitting its old value.
    if (window.Craft && window.jQuery) {
        try {
            if (Craft.initUiElements) {
                Craft.initUiElements(jQuery(field));
            }
        } catch (e) {
            // Fall through to the direct attempt below.
        }

        try {
            var $switch = jQuery('.lightswitch', field);

            if ($switch.length && !$switch.data('lightswitch') && Craft.LightSwitch) {
                new Craft.LightSwitch($switch[0]);
            }
        } catch (e) {
            // Nothing more we can do; the field is still in the form.
        }
    }
})();
JS;

        Craft::$app->getView()->registerJs(sprintf(
            $js,
            \craft\helpers\Json::encode(trim($html)),
            \craft\helpers\Json::encode($anchor),
        ), View::POS_READY);
    }

    /**
     * Records the toggle after a section or global set is saved.
     *
     * Only acts on a real control panel save that carried the field — project
     * config syncs and console commands must not rewrite the setting.
     */
    private function storeSourceToggle(string $attribute, string $uid): void
    {
        // Matches renderSourceToggle(): if Lite never drew the field, a posted
        // value didn't come from us and must not rewrite the setting.
        if (!$this->isPro() || !Craft::$app instanceof WebApplication) {
            return;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || !$request->getIsPost()) {
            return;
        }

        $posted = $request->getBodyParam('translatorProEnabled');

        if ($posted === null) {
            return;
        }

        $settings = $this->getSettings();
        $updated = Settings::toggleUid($settings->$attribute, $uid, (bool)$posted);

        if ($updated === $settings->$attribute) {
            return;
        }

        $values = $settings->toArray();
        $values[$attribute] = $updated;

        Craft::$app->getPlugins()->savePluginSettings($this, $values);
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['translator-pro'] = 'translator-pro/static/index';

                // Static translations
                $event->rules['translator-pro/static'] = 'translator-pro/static/index';
                $event->rules['translator-pro/static/<category:[\w\-\.]+>'] = 'translator-pro/static/edit';

                // AI translations (Pro)
                $event->rules['translator-pro/ai'] = 'translator-pro/batch/index';
                $event->rules['translator-pro/ai/new'] = 'translator-pro/batch/new';

                // Settings
                $event->rules['translator-pro/settings'] = 'translator-pro/settings/index';
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('translator-pro', 'Translator Pro'),
                    'permissions' => [
                        self::PERMISSION_STATIC => [
                            'label' => Craft::t('translator-pro', 'Manage static translations'),
                        ],
                        self::PERMISSION_AI => [
                            'label' => Craft::t('translator-pro', 'Use AI translations'),
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Injects the field/page translate buttons into every control panel page.
     */
    private function registerCpAssets(): void
    {
        if (!Craft::$app instanceof WebApplication) {
            return;
        }

        $request = Craft::$app->getRequest();

        if (!$request->getIsCpRequest() || $request->getIsConsoleRequest()) {
            return;
        }

        $settings = $this->getSettings();

        if (!$settings->enableFieldButtons && !$settings->enablePageButton) {
            return;
        }

        // Whether the page button is allowed here is decided per render, in
        // cpJsSettings(), since one asset bundle serves every control panel page.

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function() {
                // Checked at render time: during init() the user isn't resolved
                // yet, and the verification state may have changed since.
                if (!$this->aiIsReady()) {
                    return;
                }

                // Settings, the plugin store, utilities, user accounts: screens
                // where you type configuration, not content. Nothing is loaded
                // there at all rather than loaded and then held back.
                $settings = $this->getSettings();
                $segments = Craft::$app->getRequest()->getSegments();

                if ($settings->excludesFieldButtons($segments)) {
                    return;
                }

                $view = Craft::$app->getView();
                $view->registerAssetBundle(FieldTranslateAsset::class);

                // Craft.t() only translates categories that have been handed to
                // the JS side, and the plural below is formatted in the browser.
                $view->registerTranslations('translator-pro', [
                    '{num, plural, =1{1 field translated} other{# fields translated}}',
                    'Translating into {language}',
                    'Translate into {target}, detecting the source language',
                    'Translate from {source} into {target}',
                ]);

                $view->registerJs(sprintf(
                    'window.TranslatorPro && window.TranslatorPro.init(%s);',
                    \craft\helpers\Json::encode($this->cpJsSettings()),
                ), View::POS_END);
            },
        );
    }

    /**
     * Whether Translator Pro is switched on for the section or global set being
     * edited, from the toggle on its own settings screen.
     *
     * @param string[] $segments
     */
    private function sourceIsEnabled(array $segments): bool
    {
        $settings = $this->getSettings();

        // globals/{handle}
        if (strcasecmp($segments[0] ?? '', 'globals') === 0 && isset($segments[1])) {
            $set = Craft::$app->getGlobals()->getSetByHandle($segments[1]);

            return $settings->allowsGlobalSet($set?->uid);
        }

        // categories/{groupHandle}/{id}-{slug}
        if (strcasecmp($segments[0] ?? '', 'categories') === 0 && isset($segments[1])) {
            $group = Craft::$app->getCategories()->getGroupByHandle($segments[1]);

            return $settings->allowsCategoryGroup($group?->uid);
        }

        // The entry's own section, resolved from the `{id}-{slug}` segment.
        // Singles sit under a `singles` path segment rather than their section
        // handle, so the ID is the only reliable route to the section.
        //
        // Scanned front to back on purpose: a draft URL ends in
        // `…/128-slug/drafts/12`, and working backwards would find the draft ID.
        foreach ($segments as $segment) {
            if (!preg_match('/^(\d+)(?:-|$)/', $segment, $match)) {
                continue;
            }

            $entry = \craft\elements\Entry::find()
                ->id((int)$match[1])
                ->status(null)
                ->drafts(null)
                ->revisions(null)
                ->site('*')
                ->unique()
                ->one();

            return $settings->allowsSection($entry?->getSection()?->uid);
        }

        return true;
    }

    /**
     * What the buttons on this page should translate into, and a key to
     * remember an override against.
     *
     * On a Formie form the answer comes from the form's own language setting —
     * a form isn't scoped to a site the way an entry is, so the site being
     * viewed would be a poor guess. Everywhere else it's the site being edited,
     * which is what you always want: you translate *into* where you are.
     *
     * @return array{string,string|null}
     */
    private function translationContext(): array
    {
        $segments = Craft::$app->getRequest()->getSegments();

        // admin/formie/forms/edit/{id}
        if (
            ($segments[0] ?? null) === 'formie' &&
            ($segments[1] ?? null) === 'forms' &&
            ($segments[2] ?? null) === 'edit' &&
            ctype_digit((string)($segments[3] ?? ''))
        ) {
            $formId = (int)$segments[3];

            if ($this->formie->isAvailable()) {
                return ['form:' . $formId, $this->formie->getTargetLanguage($formId)];
            }
        }

        // NOT getCurrentSite(): on a control panel request that's the primary
        // site whatever you're actually editing, which had the buttons offering
        // to translate an English entry into Dutch. `requestedSite()` reads the
        // `site` query param, so it follows the site switcher like Craft's own
        // screens do.
        $site = Cp::requestedSite() ?? Craft::$app->getSites()->getCurrentSite();

        return ['site:' . $site->id, $site->language];
    }

    /**
     * The configuration handed to the control panel JS.
     *
     * @return array<string,mixed>
     */
    private function cpJsSettings(): array
    {
        $settings = $this->getSettings();
        $sites = [];

        $i18n = Craft::$app->getI18n();

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sites[] = [
                'id' => $site->id,
                'name' => $site->name,
                // The menus talk about languages, not sites — two sites can share
                // one — and "English" reads better than "[EN] Main site".
                'languageName' => $i18n->getLocaleById($site->language)->getDisplayName(Craft::$app->language),
                'language' => $site->language,
                'handle' => $site->handle,
            ];
        }

        [$context, $targetLanguage] = $this->translationContext();
        $segments = Craft::$app->getRequest()->getSegments();
        $sourceEnabled = $this->sourceIsEnabled($segments);

        return [
            // The per-source toggle governs every Translator Pro feature on
            // that section or global set, not just the page button.
            'enableFieldButtons' => $sourceEnabled && $settings->enableFieldButtons,
            // Translating a whole page only makes sense where the page *is* a
            // piece of content. On a settings screen it would rewrite
            // configuration, so the button is limited to an explicit allowlist.
            'enablePageButton' => $sourceEnabled
                && $settings->enablePageButton
                && $settings->allowsPageButton($segments),
            'overwriteExisting' => $settings->overwriteExisting,
            'excludePatterns' => $settings->normalizedExcludedPatterns(),
            'sites' => $sites,
            'currentSiteId' => (Cp::requestedSite() ?? Craft::$app->getSites()->getCurrentSite())->id,
            'context' => $context,
            'targetLanguage' => $targetLanguage,
            // On a Formie form the buttons are limited to the same settings the
            // Translator Pro tab touches, so the two can't disagree. Empty
            // everywhere else, which means "no restriction".
            'attributeAllowlist' => str_starts_with($context, 'form:')
                ? FormieBridge::translatableAttributes()
                : [],
            'configured' => $settings->isConfigured(),
            'strings' => [
                'translate' => Craft::t('translator-pro', 'Translate'),
                'translatePage' => Craft::t('translator-pro', 'Translate page'),
                'translateFrom' => Craft::t('translator-pro', 'Translate from'),
                'autoDetect' => Craft::t('translator-pro', 'Detect automatically'),
                'autoShort' => Craft::t('translator-pro', 'AUTO'),
                'translatingInto' => Craft::t('translator-pro', 'Translating into {language}'),
                'noTarget' => Craft::t('translator-pro', 'No language is set for this page.'),
                'translating' => Craft::t('translator-pro', 'Translating…'),
                'translated' => Craft::t('translator-pro', 'Translated'),
                'nothingToTranslate' => Craft::t('translator-pro', 'Nothing to translate on this page.'),
                'notConfigured' => Craft::t('translator-pro', 'No AI provider is configured yet.'),
                'cancel' => Craft::t('translator-pro', 'Cancel'),
                'failed' => Craft::t('translator-pro', 'Translation failed'),
                'fieldsTranslated' => Craft::t('translator-pro', '{num, plural, =1{1 field translated} other{# fields translated}}'),
            ],
        ];
    }
}
