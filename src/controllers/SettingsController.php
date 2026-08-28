<?php

namespace studionoir\translatorpro\controllers;

use Craft;
use studionoir\translatorpro\models\Settings;
use studionoir\translatorpro\translators\DeepL;
use studionoir\translatorpro\translators\TranslatorException;
use studionoir\translatorpro\TranslatorPro;
use yii\web\Response;

class SettingsController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = TranslatorPro::$plugin;
        $settings = $plugin->getSettings();

        // Admin screens are the right place to spend a live check: this
        // re-verifies after a cache flush so the buttons come back on their own.
        $verified = $plugin->translator->verify();

        $usage = null;

        if ($settings->provider === Settings::PROVIDER_DEEPL && $verified) {
            $usage = (new DeepL($settings))->getUsage();
        }

        return $this->renderTemplate('translator-pro/settings', [
            'title' => Craft::t('translator-pro', 'Settings'),
            'plugin' => $plugin,
            'settings' => $settings,
            'providerOptions' => Settings::providerOptions(),
            'isPro' => $plugin->isPro(),
            'usage' => $usage,
            'verified' => $verified,
            'verificationError' => $plugin->translator->getVerificationError(),
            'overrides' => Craft::$app->getConfig()->getConfigFromFile('translator-pro'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = TranslatorPro::$plugin;
        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            $this->setFailFlash(Craft::t('translator-pro', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $plugin->getSettings(),
            ]);

            return null;
        }

        // The credentials may have changed, so anything we knew about the old
        // ones is meaningless — then prove the new ones actually work.
        $plugin->translator->forgetVerification();

        if ($plugin->isPro() && $plugin->getSettings()->isConfigured() && !$plugin->translator->verify(true)) {
            $this->setFailFlash(Craft::t(
                'translator-pro',
                'Settings saved, but the connection test failed: {message} The translate buttons stay hidden until it succeeds.',
                ['message' => $plugin->translator->getVerificationError() ?? ''],
            ));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t('translator-pro', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Makes one cheap call to the provider so a bad key is caught here rather
     * than halfway through a batch.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = TranslatorPro::$plugin;

        if (!$plugin->isPro()) {
            return $this->asFailure(Craft::t('translator-pro', 'AI translations require the Pro edition.'));
        }

        // Test what's in the form, not what was last saved.
        $posted = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        $settings = new Settings();
        $settings->setAttributes(array_merge($plugin->getSettings()->toArray(), $posted), false);

        if (!$settings->isConfigured()) {
            return $this->asFailure(Craft::t('translator-pro', 'Enter an API key first.'));
        }

        try {
            $translator = $plugin->translator->getProviderFor($settings);
            $translator->testConnection();
        } catch (TranslatorException $e) {
            return $this->asFailure($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error("Connection test failed: {$e->getMessage()}", __METHOD__);

            return $this->asFailure($e->getMessage());
        }

        // Remember the result against these exact credentials. If they match
        // what's saved, the translate buttons light up without a second check.
        $plugin->translator->verify(true, $settings);

        return $this->asSuccess(Craft::t('translator-pro', 'Connected to {provider}.', [
            'provider' => $translator->getName(),
        ]));
    }
}
