<?php

namespace studionoir\translatorpro\controllers;

use Craft;
use craft\helpers\StringHelper;
use studionoir\translatorpro\jobs\TranslateElementsJob;
use studionoir\translatorpro\services\Batch;
use studionoir\translatorpro\TranslatorPro;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The AI translations screen: translate entries, categories and globals in bulk.
 */
class BatchController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!TranslatorPro::$plugin->isPro()) {
            throw new ForbiddenHttpException(Craft::t('translator-pro', 'AI translations require the Pro edition.'));
        }

        $this->requirePermission(TranslatorPro::PERMISSION_AI);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = TranslatorPro::$plugin;
        $sites = Craft::$app->getSites()->getAllSites();
        $request = Craft::$app->getRequest();

        $elementType = (string)$request->getParam('elementType', Batch::TYPE_ENTRIES);

        if (!isset(Batch::elementTypeOptions()[$elementType])) {
            $elementType = Batch::TYPE_ENTRIES;
        }

        $sourceSiteId = (int)$request->getParam('sourceSiteId', Craft::$app->getSites()->getPrimarySite()->id);
        $groupIds = array_filter(array_map('intval', (array)$request->getParam('groupIds', [])));

        // Admin screen, so a live check is fine — and it re-arms the buttons
        // after a cache flush without anyone having to press anything.
        $verified = $plugin->translator->verify();

        return $this->renderTemplate('translator-pro/ai/index', [
            'title' => Craft::t('translator-pro', 'AI translations'),
            'elementType' => $elementType,
            'elementTypeOptions' => Batch::elementTypeOptions(),
            'sites' => $sites,
            'sourceSiteId' => $sourceSiteId,
            'groupIds' => $groupIds,
            'groups' => $plugin->batch->getGroups($elementType, $sourceSiteId),
            'fields' => $groupIds !== [] ? $plugin->batch->getFields($elementType, $groupIds) : [],
            'lastRun' => $this->lastRun(),
            'configured' => $plugin->getSettings()->isConfigured(),
            'verified' => $verified,
            'verificationError' => $plugin->translator->getVerificationError(),
            'provider' => $plugin->getSettings()->provider,
        ]);
    }

    /**
     * Returns the groups and fields available for a given element type + site,
     * so the form can rebuild itself without a page load.
     */
    public function actionOptions(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementType = (string)$request->getParam('elementType', Batch::TYPE_ENTRIES);
        $sourceSiteId = (int)$request->getParam('sourceSiteId', 0);
        $groupIds = array_filter(array_map('intval', (array)$request->getParam('groupIds', [])));

        $plugin = TranslatorPro::$plugin;
        $fields = [];

        foreach ($plugin->batch->getFields($elementType, $groupIds) as $field) {
            $fields[] = [
                'path' => $field->path,
                'label' => $field->getFullLabel(),
                'format' => $field->format,
                'translatable' => $field->translatable,
                'depth' => $field->depth,
            ];
        }

        return $this->asJson([
            'groups' => $plugin->batch->getGroups($elementType, $sourceSiteId ?: null),
            'fields' => $fields,
        ]);
    }

    /**
     * Validates the form and queues the translation job.
     */
    public function actionRun(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = TranslatorPro::$plugin;

        $elementType = (string)$request->getBodyParam('elementType', Batch::TYPE_ENTRIES);
        $groupIds = array_values(array_filter(array_map('intval', (array)$request->getBodyParam('groupIds', []))));
        $sourceSiteId = (int)$request->getBodyParam('sourceSiteId', 0);
        $targetSiteIds = array_values(array_filter(array_map('intval', (array)$request->getBodyParam('targetSiteIds', []))));
        $paths = array_values(array_filter((array)$request->getBodyParam('paths', []), 'is_string'));
        $overwrite = (bool)$request->getBodyParam('overwrite', false);

        // Drop anything switched off on its own settings screen, so a stale form
        // or a hand-made request can't queue a source that isn't offered.
        $groupIds = array_values(array_intersect(
            $groupIds,
            array_keys($plugin->batch->getGroups($elementType)),
        ));

        $errors = [];

        if (!$plugin->getSettings()->isConfigured()) {
            $errors[] = Craft::t('translator-pro', 'No API key is configured. Add one in the plugin settings first.');
        } elseif (!$plugin->translator->verify()) {
            $errors[] = Craft::t('translator-pro', 'The connection to the AI provider isn’t working: {message}', [
                'message' => $plugin->translator->getVerificationError() ?? '',
            ]);
        }

        if ($groupIds === []) {
            $errors[] = Craft::t('translator-pro', 'Select at least one source to translate.');
        }

        if ($sourceSiteId === 0) {
            $errors[] = Craft::t('translator-pro', 'Select the site to translate from.');
        }

        $targetSiteIds = array_values(array_diff($targetSiteIds, [$sourceSiteId]));

        if ($targetSiteIds === []) {
            $errors[] = Craft::t('translator-pro', 'Select at least one site to translate into.');
        }

        if ($paths === []) {
            $errors[] = Craft::t('translator-pro', 'Select at least one field to translate.');
        }

        if ($errors !== []) {
            $this->setFailFlash(implode(' ', $errors));

            return $this->redirectToPostedUrl();
        }

        $count = count($plugin->batch->getElementIds($elementType, $groupIds, $sourceSiteId));

        if ($count === 0) {
            $this->setFailFlash(Craft::t('translator-pro', 'Nothing to translate — no elements matched that selection.'));

            return $this->redirectToPostedUrl();
        }

        $runId = StringHelper::UUID();

        Craft::$app->getQueue()->push(new TranslateElementsJob([
            'elementType' => $elementType,
            'groupIds' => $groupIds,
            'sourceSiteId' => $sourceSiteId,
            'targetSiteIds' => $targetSiteIds,
            'paths' => $paths,
            'overwrite' => $overwrite,
            'runId' => $runId,
        ]));

        Craft::$app->getSession()->set('translator-pro.lastRunId', $runId);

        $this->setSuccessFlash(Craft::t('translator-pro', 'Queued {count} elements for translation into {sites} site(s).', [
            'count' => $count,
            'sites' => count($targetSiteIds),
        ]));

        return $this->redirectToPostedUrl();
    }

    /**
     * The running total for the most recently queued batch, if there is one.
     *
     * @return array<string,mixed>|null
     */
    private function lastRun(): ?array
    {
        $runId = Craft::$app->getSession()->get('translator-pro.lastRunId');

        if (!is_string($runId) || $runId === '') {
            return null;
        }

        $summary = Craft::$app->getCache()->get('translator-pro:run:' . $runId);

        return is_array($summary) ? $summary : null;
    }
}
