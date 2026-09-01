<?php

namespace studionoir\translingua\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\ElementInterface;
use craft\db\QueryBatcher;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\queue\BaseBatchedElementJob;
use studionoir\translingua\services\Batch;
use studionoir\translingua\Translingua;

/**
 * Translates a set of elements from one site into one or more others.
 *
 * Runs as a batched job: AI calls are slow, so the queue splits the work into
 * chunks rather than holding a single job open long enough to time out.
 */
class TranslateElementsJob extends BaseBatchedElementJob
{
    /** @var string One of the `Batch::TYPE_*` constants. */
    public string $elementType = Batch::TYPE_ENTRIES;

    /** @var int[] Section / category group / global set IDs. */
    public array $groupIds = [];

    public int $sourceSiteId = 0;

    /** @var int[] */
    public array $targetSiteIds = [];

    /** @var string[]|null Field handle chains, or null for every translatable field. */
    public ?array $paths = null;

    public bool $overwrite = false;

    /** @var string Identifies this run's summary in the cache. */
    public string $runId = '';

    public function init(): void
    {
        parent::init();

        // AI round trips dominate; small batches keep progress visible and
        // make a mid-run failure cheap to resume.
        $this->batchSize = 10;
    }

    protected function loadData(): Batchable
    {
        return new QueryBatcher($this->query());
    }

    protected function processItem(mixed $item): void
    {
        if (!$item instanceof ElementInterface || $item->id === null) {
            return;
        }

        $service = Translingua::$plugin->elementTranslator;
        $elementClass = Translingua::$plugin->batch->elementClass($this->elementType);

        foreach ($this->targetSiteIds as $targetSiteId) {
            $targetSiteId = (int)$targetSiteId;

            if ($targetSiteId === $this->sourceSiteId) {
                continue;
            }

            $result = $service->translate(
                $elementClass,
                $item->id,
                $this->sourceSiteId,
                $targetSiteId,
                $this->paths,
                $this->overwrite,
            );

            $this->recordSummary($result->toArray());

            foreach ($result->errors as $error) {
                Craft::warning("Translingua: $error", __METHOD__);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        $sourceSite = Craft::$app->getSites()->getSiteById($this->sourceSiteId);

        return Craft::t('translingua', 'Translating {type} from {site}', [
            'type' => mb_strtolower(Batch::elementTypeOptions()[$this->elementType] ?? $this->elementType),
            'site' => $sourceSite?->name ?? $this->sourceSiteId,
        ]);
    }

    /**
     * The source-site query the batches are drawn from.
     */
    private function query(): \craft\elements\db\ElementQueryInterface
    {
        $groupIds = array_map('intval', $this->groupIds);

        $query = match ($this->elementType) {
            Batch::TYPE_CATEGORIES => Category::find()->groupId($groupIds),
            Batch::TYPE_GLOBALS => GlobalSet::find()->id($groupIds),
            default => Entry::find()->sectionId($groupIds),
        };

        return $query
            ->siteId($this->sourceSiteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->orderBy(['elements.id' => SORT_ASC]);
    }

    /**
     * Accumulates a run summary so the AI translations screen can report on it.
     *
     * @param array<string,mixed> $result
     */
    private function recordSummary(array $result): void
    {
        if ($this->runId === '') {
            return;
        }

        $cache = Craft::$app->getCache();
        $key = 'translingua:run:' . $this->runId;
        $summary = $cache->get($key);

        if (!is_array($summary)) {
            $summary = [
                'translated' => 0,
                'skippedFilled' => 0,
                'skippedShared' => 0,
                'skippedEmpty' => 0,
                'elements' => 0,
                'errors' => [],
            ];
        }

        $summary['elements']++;

        foreach (['translated', 'skippedFilled', 'skippedShared', 'skippedEmpty'] as $counter) {
            $summary[$counter] += (int)($result[$counter] ?? 0);
        }

        foreach ((array)($result['errors'] ?? []) as $error) {
            // Keep the report readable when a misconfigured key fails on every element.
            if (count($summary['errors']) < 25 && !in_array($error, $summary['errors'], true)) {
                $summary['errors'][] = $error;
            }
        }

        $cache->set($key, $summary, 60 * 60 * 24);
    }
}
