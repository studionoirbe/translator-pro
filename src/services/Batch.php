<?php

namespace studionoir\translingua\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\models\FieldLayout;
use studionoir\translingua\models\TranslatableField;
use studionoir\translingua\Translingua;

/**
 * Builds the batch translation screen: what can be translated, which fields it
 * has, and which elements a given selection resolves to.
 */
class Batch extends Component
{
    public const TYPE_ENTRIES = 'entries';
    public const TYPE_CATEGORIES = 'categories';
    public const TYPE_GLOBALS = 'globals';

    /**
     * @return array<string,string>
     */
    public static function elementTypeOptions(): array
    {
        return [
            self::TYPE_ENTRIES => Craft::t('app', 'Entries'),
            self::TYPE_CATEGORIES => Craft::t('app', 'Categories'),
            self::TYPE_GLOBALS => Craft::t('app', 'Globals'),
        ];
    }

    /**
     * @return class-string<ElementInterface>
     */
    public function elementClass(string $type): string
    {
        return match ($type) {
            self::TYPE_CATEGORIES => Category::class,
            self::TYPE_GLOBALS => GlobalSet::class,
            default => Entry::class,
        };
    }

    /**
     * The selectable groups for an element type, as `id => label`.
     * Only groups that exist in the given site are returned.
     *
     * @return array<int,string>
     */
    public function getGroups(string $type, ?int $siteId = null): array
    {
        $groups = [];
        $settings = Translingua::$plugin->getSettings();

        switch ($type) {
            case self::TYPE_CATEGORIES:
                foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
                    // Switched off on its own settings screen.
                    if (!$settings->allowsCategoryGroup($group->uid)) {
                        continue;
                    }

                    if ($siteId === null || $this->groupHasSite($group->getSiteSettings(), $siteId)) {
                        $groups[$group->id] = $group->name;
                    }
                }
                break;

            case self::TYPE_GLOBALS:
                foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
                    // Switched off on its own settings screen.
                    if (!$settings->allowsGlobalSet($set->uid)) {
                        continue;
                    }

                    $groups[$set->id] = $set->name;
                }
                break;

            default:
                foreach (Craft::$app->getEntries()->getAllSections() as $section) {
                    if (!$settings->allowsSection($section->uid)) {
                        continue;
                    }

                    if ($siteId === null || $this->groupHasSite($section->getSiteSettings(), $siteId)) {
                        $groups[$section->id] = $section->name;
                    }
                }
        }

        asort($groups);

        return $groups;
    }

    /**
     * The translatable fields available across the selected groups.
     *
     * @param int[] $groupIds
     * @return TranslatableField[]
     */
    public function getFields(string $type, array $groupIds): array
    {
        $layouts = [];
        $includeTitle = true;
        $titleTranslatable = true;

        switch ($type) {
            case self::TYPE_CATEGORIES:
                foreach ($groupIds as $id) {
                    $group = Craft::$app->getCategories()->getGroupById((int)$id);

                    if ($group !== null) {
                        $layouts[] = $group->getFieldLayout();
                    }
                }
                break;

            case self::TYPE_GLOBALS:
                // Global sets have a name, not a translatable title.
                $includeTitle = false;

                foreach ($groupIds as $id) {
                    $set = Craft::$app->getGlobals()->getSetById((int)$id);

                    if ($set !== null) {
                        $layouts[] = $set->getFieldLayout();
                    }
                }
                break;

            default:
                $titleTranslatable = false;

                foreach ($groupIds as $id) {
                    $section = Craft::$app->getEntries()->getSectionById((int)$id);

                    if ($section === null) {
                        continue;
                    }

                    foreach ($section->getEntryTypes() as $entryType) {
                        $layouts[] = $entryType->getFieldLayout();

                        if ($entryType->hasTitleField && $entryType->titleTranslationMethod !== \craft\base\Field::TRANSLATION_METHOD_NONE) {
                            $titleTranslatable = true;
                        }
                    }
                }
        }

        return Translingua::$plugin->content->getTranslatableFieldsForLayouts(
            $this->uniqueLayouts($layouts),
            $includeTitle,
            $titleTranslatable,
        );
    }

    /**
     * Resolves a selection to concrete element IDs in the source site.
     *
     * @param int[] $groupIds
     * @return int[]
     */
    public function getElementIds(string $type, array $groupIds, int $sourceSiteId): array
    {
        // Intersect with what's actually offered: a posted ID for a source that
        // was switched off shouldn't be honoured.
        $groupIds = array_values(array_intersect(
            array_map('intval', $groupIds),
            array_keys($this->getGroups($type)),
        ));

        if ($groupIds === []) {
            return [];
        }

        $query = match ($type) {
            self::TYPE_CATEGORIES => Category::find()->groupId($groupIds),
            self::TYPE_GLOBALS => GlobalSet::find()->id($groupIds),
            default => Entry::find()->sectionId($groupIds),
        };

        return $query
            ->siteId($sourceSiteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->limit(null)
            ->ids();
    }

    /**
     * @param array<\craft\models\Site_SettingsModel|\craft\models\CategoryGroup_SiteSettings|mixed> $siteSettings
     */
    private function groupHasSite(array $siteSettings, int $siteId): bool
    {
        foreach ($siteSettings as $key => $settings) {
            $candidate = is_object($settings) && isset($settings->siteId) ? (int)$settings->siteId : (int)$key;

            if ($candidate === $siteId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param FieldLayout[] $layouts
     * @return FieldLayout[]
     */
    private function uniqueLayouts(array $layouts): array
    {
        $unique = [];

        foreach ($layouts as $layout) {
            $unique[$layout->id ?? spl_object_id($layout)] = $layout;
        }

        return array_values($unique);
    }
}
