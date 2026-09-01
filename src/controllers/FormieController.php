<?php

namespace studionoir\translingua\controllers;

use Craft;
use studionoir\translingua\Translingua;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Backs the Translingua tab in Formie's form builder.
 */
class FormieController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        if (!Translingua::$plugin->formie->isAvailable()) {
            throw new NotFoundHttpException('Formie isn’t installed.');
        }

        return true;
    }

    /**
     * Stores the language a form is written in.
     */
    public function actionSaveSettings(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $formId = (int)$request->getRequiredBodyParam('formId');
        $targetLanguage = (string)$request->getBodyParam('targetLanguage', '');
        $sourceLanguage = (string)$request->getBodyParam('sourceLanguage', '');

        $this->requireFormPermission($formId);

        $languages = Translingua::$plugin->translations->getTargetLocales();

        foreach ([$targetLanguage, $sourceLanguage] as $language) {
            if ($language !== '' && !in_array($language, $languages, true)) {
                $this->setFailFlash(Craft::t('translingua', 'Unknown language “{language}”.', ['language' => $language]));

                return null;
            }
        }

        Translingua::$plugin->formie->saveSettings($formId, $targetLanguage, $sourceLanguage);

        $this->setSuccessFlash(Craft::t('translingua', 'Translation settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Translates the whole form in place.
     */
    public function actionTranslate(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Translingua::$plugin;
        $request = Craft::$app->getRequest();
        $formId = (int)$request->getRequiredBodyParam('formId');

        $this->requireFormPermission($formId);

        if (!$plugin->isPlus()) {
            $this->setFailFlash(Craft::t('translingua', 'AI translations require the Plus edition.'));

            return null;
        }

        $this->requirePermission(Translingua::PERMISSION_AI);

        if (!$plugin->translator->verify()) {
            $this->setFailFlash(Craft::t('translingua', 'The connection to the AI provider isn’t working: {message}', [
                'message' => $plugin->translator->getVerificationError() ?? '',
            ]));

            return null;
        }

        $form = $plugin->formie->getForm($formId);

        if ($form === null) {
            throw new NotFoundHttpException('Form not found.');
        }

        // Blank means "let the provider detect it".
        $sourceLanguage = trim((string)$request->getBodyParam('sourceLanguage', ''));
        $targetLanguage = (string)$request->getBodyParam('targetLanguage', '')
            ?: (string)$plugin->formie->getTargetLanguage($formId);
        $overwrite = (bool)$request->getBodyParam('overwrite', true);

        if ($targetLanguage === '') {
            $this->setFailFlash(Craft::t('translingua', 'Set the language of this form first.'));

            return null;
        }

        $result = $plugin->formie->translateForm($form, $sourceLanguage ?: null, $targetLanguage, $overwrite);

        // Remember what it was translated from, so the tab reopens on that choice.
        $plugin->formie->saveSettings($formId, $targetLanguage, $sourceLanguage);

        if ($result->hasErrors()) {
            $this->setFailFlash(implode(' ', $result->errors));

            return null;
        }

        if ($result->translated === 0) {
            $this->setSuccessFlash(Craft::t('translingua', 'Nothing left to translate.'));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t('translingua', '{count} strings translated into {language}.', [
            'count' => $result->translated,
            'language' => $targetLanguage,
        ]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Editing a form's translations is editing the form.
     */
    private function requireFormPermission(int $formId): void
    {
        $form = Translingua::$plugin->formie->getForm($formId);

        if ($form === null) {
            throw new NotFoundHttpException('Form not found.');
        }

        $user = Craft::$app->getUser();

        if (
            !$user->checkPermission('formie-manageForms') &&
            !$user->checkPermission('formie-manageForms:' . $form->uid)
        ) {
            throw new ForbiddenHttpException('You aren’t allowed to manage this form.');
        }
    }
}
