<?php

namespace studionoir\translingua\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use studionoir\translingua\models\FieldSlot;
use studionoir\translingua\models\TranslationResult;
use studionoir\translingua\translators\TranslatorException;
use studionoir\translingua\Translingua;

/**
 * Translates a real element from one site's content into another's.
 *
 * The pairing is structural, not positional-by-luck: `Content::collectSlots()`
 * keys every value by its field path plus, inside Matrix fields, block index and
 * entry type. Only slots present on both sides are considered, and only when
 * Craft says the value can actually differ per site.
 */
class ElementTranslator extends Component
{
    /**
     * Translates one element's content from `$sourceSiteId` into `$targetSiteId`.
     *
     * @param class-string<ElementInterface> $elementType
     * @param string[]|null $paths Field handle chains to translate, or null for all.
     */
    public function translate(
        string $elementType,
        int $elementId,
        int $sourceSiteId,
        int $targetSiteId,
        ?array $paths = null,
        bool $overwrite = false,
    ): TranslationResult {
        $result = new TranslationResult();

        if ($sourceSiteId === $targetSiteId) {
            $result->errors[] = Craft::t('translingua', 'Source and target site are the same.');
            return $result;
        }

        $source = $this->loadElement($elementType, $elementId, $sourceSiteId);
        $target = $this->loadElement($elementType, $elementId, $targetSiteId);

        if ($source === null || $target === null) {
            $result->errors[] = Craft::t('translingua', 'Element {id} doesn’t exist in both sites.', ['id' => $elementId]);
            return $result;
        }

        $sourceLanguage = Craft::$app->getSites()->getSiteById($sourceSiteId)?->language;
        $targetLanguage = Craft::$app->getSites()->getSiteById($targetSiteId)?->language;

        if ($targetLanguage === null) {
            $result->errors[] = Craft::t('translingua', 'Unknown target site.');
            return $result;
        }

        $content = Translingua::$plugin->content;
        $sourceSlots = $content->collectSlots($source, $paths);
        $targetSlots = $content->collectSlots($target, $paths);

        /** @var array<string,FieldSlot> $pending */
        $pending = [];
        $texts = [];

        foreach ($sourceSlots as $key => $sourceSlot) {
            $targetSlot = $targetSlots[$key] ?? null;

            if ($targetSlot === null) {
                // The block only exists in the source site — nothing to write into.
                continue;
            }

            // Identical translation keys mean Craft stores one value for both
            // sites; writing a translation would destroy the source.
            if ($sourceSlot->translationKey === $targetSlot->translationKey) {
                $result->skippedShared++;
                continue;
            }

            if ($sourceSlot->isEmpty()) {
                $result->skippedEmpty++;
                continue;
            }

            if (!$overwrite && !$targetSlot->isEmpty()) {
                $result->skippedFilled++;
                continue;
            }

            $pending[$key] = $targetSlot;
            $texts[$key] = $sourceSlot->getValue();
        }

        if ($pending === []) {
            return $result;
        }

        // Group by format so HTML is sent with the right handling.
        $byFormat = [];

        foreach ($texts as $key => $text) {
            $byFormat[$pending[$key]->format][$key] = $text;
        }

        $translated = [];

        foreach ($byFormat as $format => $group) {
            try {
                $translated += Translingua::$plugin->translator->translate(
                    $group,
                    $sourceLanguage,
                    $targetLanguage,
                    $format,
                );
            } catch (TranslatorException $e) {
                $result->errors[] = $e->getMessage();
                return $result;
            }
        }

        // Apply, tracking which elements actually changed.
        $touched = [];

        foreach ($translated as $key => $value) {
            $slot = $pending[$key];

            if ($value === '' || $value === $slot->getValue()) {
                continue;
            }

            $slot->setValue($value);
            $result->translated++;

            $touched[spl_object_id($slot->element)] = $slot->element;
        }

        if ($touched === []) {
            return $result;
        }

        $this->save($target, $touched, $result);

        return $result;
    }

    /**
     * Saves the elements that changed.
     *
     * Nested entries go first: if the owner's Matrix field is marked required,
     * saving the owner re-saves its nested elements, and we want that to happen
     * on top of already-persisted translations rather than before them.
     *
     * @param array<int,ElementInterface> $touched
     */
    private function save(ElementInterface $target, array $touched, TranslationResult $result): void
    {
        $elementsService = Craft::$app->getElements();
        $settings = Translingua::$plugin->getSettings();
        $ownerId = spl_object_id($target);

        // Nested entries first, owner last.
        $ordered = $touched;
        unset($ordered[$ownerId]);

        if (isset($touched[$ownerId])) {
            $ordered[$ownerId] = $touched[$ownerId];
        }

        if ($settings->createRevisions && !$target->getIsDraft() && !$target->getIsRevision() && $target->id !== null) {
            try {
                Craft::$app->getRevisions()->createRevision(
                    $target,
                    Craft::$app->getUser()->getId(),
                    Craft::t('translingua', 'Before AI translation'),
                );
            } catch (\Throwable $e) {
                // A missing revision must never block the translation itself.
                Craft::warning("Couldn't create a revision for element {$target->id}: {$e->getMessage()}", __METHOD__);
            }
        }

        foreach ($ordered as $element) {
            try {
                if (!$elementsService->saveElement($element)) {
                    $result->errors[] = sprintf(
                        '%s #%s: %s',
                        $element::displayName(),
                        $element->id,
                        implode(' ', array_merge(...array_values($element->getErrors()))),
                    );
                }
            } catch (\Throwable $e) {
                $result->errors[] = sprintf('%s #%s: %s', $element::displayName(), $element->id, $e->getMessage());
                Craft::error("Couldn't save translated element {$element->id}: {$e->getMessage()}", __METHOD__);
            }
        }
    }

    /**
     * @param class-string<ElementInterface> $elementType
     */
    private function loadElement(string $elementType, int $elementId, int $siteId): ?ElementInterface
    {
        /** @var ElementInterface|null */
        return $elementType::find()
            ->id($elementId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->one();
    }
}
