<?php

namespace studionoir\translingua\controllers;

use Craft;
use studionoir\translingua\models\Settings;
use studionoir\translingua\translators\DeepL;
use studionoir\translingua\translators\TranslatorException;
use studionoir\translingua\Translingua;
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
        $plugin = Translingua::$plugin;
        $settings = $plugin->getSettings();

        // Admin screens are the right place to spend a live check: this
        // re-verifies after a cache flush so the buttons come back on their own.
        $verified = $plugin->translator->verify();

        $usage = null;

        if ($settings->provider === Settings::PROVIDER_DEEPL && $verified) {
            $usage = (new DeepL($settings))->getUsage();
        }

        // The Plugin Store hands Craft an edition handle from the listing, and
        // Craft quietly falls back to the first edition when it doesn't
        // recognise it — which is how picking Standard can end up installing Plus.
        // If the licence names an edition this build doesn't define, that
        // mismatch is worth saying out loud rather than leaving to be
        // discovered by installing the wrong thing.
        $licensedEdition = Craft::$app->getPlugins()->getPluginInfo($plugin->id)['licensedEdition'] ?? null;

        if ($licensedEdition !== null && in_array($licensedEdition, Translingua::editions(), true)) {
            $licensedEdition = null;
        }

        return $this->renderTemplate('translingua/settings', [
            'title' => Craft::t('translingua', 'Settings'),
            'plugin' => $plugin,
            'settings' => $settings,
            'providerOptions' => Settings::providerOptions(),
            'isPlus' => $plugin->isPlus(),
            'edition' => $plugin->edition,
            'unknownLicensedEdition' => $licensedEdition,
            'usage' => $usage,
            'verified' => $verified,
            'verificationError' => $plugin->translator->getVerificationError(),
            'overrides' => Craft::$app->getConfig()->getConfigFromFile('translingua'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Translingua::$plugin;
        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            $this->setFailFlash(Craft::t('translingua', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $plugin->getSettings(),
            ]);

            return null;
        }

        // The credentials may have changed, so anything we knew about the old
        // ones is meaningless — then prove the new ones actually work.
        $plugin->translator->forgetVerification();

        if ($plugin->isPlus() && $plugin->getSettings()->isConfigured() && !$plugin->translator->verify(true)) {
            $this->setFailFlash(Craft::t(
                'translingua',
                'Settings saved, but the connection test failed: {message} The translate buttons stay hidden until it succeeds.',
                ['message' => $plugin->translator->getVerificationError() ?? ''],
            ));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t('translingua', 'Settings saved.'));

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

        $plugin = Translingua::$plugin;

        if (!$plugin->isPlus()) {
            return $this->asFailure(Craft::t('translingua', 'AI translations require the Plus edition.'));
        }

        // Test what's in the form, not what was last saved.
        $posted = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        $settings = new Settings();
        $settings->setAttributes(array_merge($plugin->getSettings()->toArray(), $posted), false);

        if (!$settings->isConfigured()) {
            return $this->asFailure(Craft::t('translingua', 'Enter an API key first.'));
        }

        try {
            $translator = $plugin->translator->getProviderFor($settings);
        } catch (TranslatorException $e) {
            return $this->asFailure($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error("Connection test failed: {$e->getMessage()}", __METHOD__);

            return $this->asFailure($e->getMessage());
        }

        // One round trip, not two: verify() runs the same check and remembers
        // the result against these exact credentials, so a pass here lights the
        // buttons up without a second call. Its answer is what gets reported —
        // a provider that responds but fails the check is not a success, and
        // saying otherwise would contradict the banner at the top of the page.
        if (!$plugin->translator->verify(true, $settings)) {
            return $this->asFailure(
                $plugin->translator->getVerificationError($settings)
                    ?? Craft::t('translingua', 'The connection test didn’t succeed.'),
            );
        }

        return $this->asSuccess(Craft::t('translingua', 'Connected to {provider}.', [
            'provider' => $translator->getName(),
        ]));
    }
}
