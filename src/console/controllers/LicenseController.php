<?php

namespace studionoir\translingua\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use studionoir\translingua\Translingua;
use yii\console\ExitCode;

/**
 * Switches the plugin edition from the command line.
 *
 * On a site bought through the Craft Plugin Store the edition is managed for
 * you. This exists for local development and for installs that run the plugin
 * from a path repository, where the store never gets involved.
 */
class LicenseController extends Controller
{
    public $defaultAction = 'edition';

    /**
     * Prints the active edition.
     */
    public function actionEdition(): int
    {
        $this->stdout('Translingua edition: ', Console::FG_GREY);
        $this->stdout(Translingua::$plugin->edition . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Switches the edition.
     *
     * @param string $edition `lite` or `plus`
     */
    public function actionSetEdition(string $edition): int
    {
        $editions = Translingua::editions();

        if (!in_array($edition, $editions, true)) {
            $this->stderr(sprintf(
                'Unknown edition “%s”. Expected one of: %s.' . PHP_EOL,
                $edition,
                implode(', ', $editions),
            ), Console::FG_RED);

            return ExitCode::USAGE;
        }

        try {
            Craft::$app->getPlugins()->switchEdition(Translingua::$plugin->id, $edition);
        } catch (\Throwable $e) {
            $this->stderr("Couldn't switch edition: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Translingua is now on the $edition edition." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
