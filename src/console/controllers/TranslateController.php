<?php

namespace studionoir\translatorpro\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use studionoir\translatorpro\jobs\TranslateElementsJob;
use studionoir\translatorpro\services\Batch;
use studionoir\translatorpro\TranslatorPro;
use yii\console\ExitCode;

/**
 * Command line access to the same translation work the control panel queues.
 */
class TranslateController extends Controller
{
    /**
     * @var string Element type: `entries`, `categories` or `globals`.
     */
    public string $type = Batch::TYPE_ENTRIES;

    /**
     * @var string Comma separated section / category group / global set names or IDs.
     * Defaults to all of them.
     */
    public string $groups = '';

    /**
     * @var string Source site handle.
     */
    public string $from = '';

    /**
     * @var string Comma separated target site handles. Defaults to every other site.
     */
    public string $to = '';

    /**
     * @var bool Overwrite target fields that already have content.
     */
    public bool $overwrite = false;

    /**
     * @var bool Run inline instead of pushing onto the queue.
     */
    public bool $inline = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'type', 'groups', 'from', 'to', 'overwrite', 'inline',
        ]);
    }

    /**
     * Rescans the templates for translatable strings.
     */
    public function actionScan(): int
    {
        TranslatorPro::$plugin->scanner->invalidate();
        $strings = TranslatorPro::$plugin->scanner->getAllStrings();

        foreach ($strings as $category => $found) {
            $this->stdout(sprintf("%-20s %d strings" . PHP_EOL, $category, count($found)));
        }

        if ($strings === []) {
            $this->stdout('No translatable strings found.' . PHP_EOL, Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Lists every translation source the plugin can edit.
     */
    public function actionSources(): int
    {
        foreach (TranslatorPro::$plugin->sources->getAll() as $source) {
            $this->stdout(sprintf(
                "%-24s %-12s %d strings" . PHP_EOL,
                $source->category,
                $source->type,
                $source->stringCount,
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Translates elements from one site into others.
     */
    public function actionElements(): int
    {
        if (!TranslatorPro::$plugin->isPro()) {
            $this->stderr('AI translations require the Pro edition.' . PHP_EOL, Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        if (!TranslatorPro::$plugin->getSettings()->isConfigured()) {
            $this->stderr('No API key is configured.' . PHP_EOL, Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $sites = Craft::$app->getSites();
        $sourceSite = $this->from !== '' ? $sites->getSiteByHandle($this->from) : $sites->getPrimarySite();

        if ($sourceSite === null) {
            $this->stderr("Unknown site “{$this->from}”." . PHP_EOL, Console::FG_RED);

            return ExitCode::USAGE;
        }

        $targetSiteIds = [];

        if ($this->to !== '') {
            foreach (explode(',', $this->to) as $handle) {
                $site = $sites->getSiteByHandle(trim($handle));

                if ($site === null) {
                    $this->stderr("Unknown site “$handle”." . PHP_EOL, Console::FG_RED);

                    return ExitCode::USAGE;
                }

                $targetSiteIds[] = $site->id;
            }
        } else {
            foreach ($sites->getAllSites() as $site) {
                if ($site->id !== $sourceSite->id) {
                    $targetSiteIds[] = $site->id;
                }
            }
        }

        $groupIds = $this->resolveGroupIds();

        if ($groupIds === []) {
            $this->stderr('No sources matched --groups.' . PHP_EOL, Console::FG_RED);

            return ExitCode::USAGE;
        }

        $job = new TranslateElementsJob([
            'elementType' => $this->type,
            'groupIds' => $groupIds,
            'sourceSiteId' => $sourceSite->id,
            'targetSiteIds' => $targetSiteIds,
            'paths' => null,
            'overwrite' => $this->overwrite,
            'runId' => '',
        ]);

        if ($this->inline) {
            $job->execute(Craft::$app->getQueue());
            $this->stdout('Done.' . PHP_EOL, Console::FG_GREEN);
        } else {
            Craft::$app->getQueue()->push($job);
            $this->stdout('Queued.' . PHP_EOL, Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * @return int[]
     */
    private function resolveGroupIds(): array
    {
        $wanted = array_filter(array_map('trim', explode(',', $this->groups)));
        $available = TranslatorPro::$plugin->batch->getGroups($this->type);

        if ($wanted === []) {
            return array_keys($available);
        }

        $ids = [];

        foreach ($wanted as $needle) {
            if (ctype_digit($needle) && isset($available[(int)$needle])) {
                $ids[] = (int)$needle;
                continue;
            }

            foreach ($available as $id => $name) {
                if (strcasecmp($name, $needle) === 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
