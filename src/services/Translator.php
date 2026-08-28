<?php

namespace studionoir\translatorpro\services;

use Craft;
use craft\base\Component;
use studionoir\translatorpro\models\Settings;
use studionoir\translatorpro\translators\Anthropic;
use studionoir\translatorpro\translators\DeepL;
use studionoir\translatorpro\translators\Google;
use studionoir\translatorpro\translators\OpenAi;
use studionoir\translatorpro\translators\TranslatorException;
use studionoir\translatorpro\translators\TranslatorInterface;
use studionoir\translatorpro\TranslatorPro;

/**
 * The entry point for every AI translation in the plugin.
 *
 * Handles provider selection, chunking, and a result cache so the same string
 * is never paid for twice.
 */
class Translator extends Component
{
    private const CACHE_DURATION = 60 * 60 * 24 * 30;
    private const VERIFIED_DURATION = 60 * 60 * 24 * 30;
    private const FAILURE_DURATION = 60 * 10;

    private ?TranslatorInterface $provider = null;

    /**
     * @var array<string,class-string<TranslatorInterface>>
     */
    private const PROVIDERS = [
        Settings::PROVIDER_DEEPL => DeepL::class,
        Settings::PROVIDER_OPENAI => OpenAi::class,
        Settings::PROVIDER_ANTHROPIC => Anthropic::class,
        Settings::PROVIDER_GOOGLE => Google::class,
    ];

    /**
     * @throws TranslatorException if the edition or configuration doesn't allow it
     */
    public function getProvider(): TranslatorInterface
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $this->requirePro();

        $settings = TranslatorPro::$plugin->getSettings();
        $class = self::PROVIDERS[$settings->provider] ?? null;

        if ($class === null) {
            throw new TranslatorException(Craft::t('translator-pro', 'Unknown translation provider “{provider}”.', [
                'provider' => $settings->provider,
            ]));
        }

        return $this->provider = new $class($settings);
    }

    /**
     * Builds a provider for a specific set of settings, bypassing the cached one.
     * Used by the settings screen to test a key before it's saved.
     */
    public function getProviderFor(Settings $settings): TranslatorInterface
    {
        $class = self::PROVIDERS[$settings->provider] ?? null;

        if ($class === null) {
            throw new TranslatorException(Craft::t('translator-pro', 'Unknown translation provider “{provider}”.', [
                'provider' => $settings->provider,
            ]));
        }

        return new $class($settings);
    }

    /**
     * Translates a batch of strings, preserving order and array keys.
     *
     * @param array<int|string,string> $texts
     * @return array<int|string,string>
     * @throws TranslatorException
     */
    public function translate(array $texts, ?string $sourceLanguage, string $targetLanguage, string $format = 'text'): array
    {
        if ($texts === []) {
            return [];
        }

        $provider = $this->getProvider();
        $settings = TranslatorPro::$plugin->getSettings();
        $cache = Craft::$app->getCache();

        $results = [];
        $pending = [];

        foreach ($texts as $key => $text) {
            $text = (string)$text;

            if (trim($text) === '') {
                $results[$key] = $text;
                continue;
            }

            $cacheKey = $this->cacheKey($text, $sourceLanguage, $targetLanguage, $format);
            $cached = $cache->get($cacheKey);

            if (is_string($cached)) {
                $results[$key] = $cached;
                continue;
            }

            $pending[$key] = $text;
        }

        foreach (array_chunk($pending, max(1, $settings->batchSize), true) as $chunk) {
            $keys = array_keys($chunk);
            $translated = $provider->translate(array_values($chunk), $sourceLanguage, $targetLanguage, $format);

            foreach ($keys as $i => $key) {
                $value = $translated[$i] ?? $chunk[$key];
                $results[$key] = $value;

                $cache->set(
                    $this->cacheKey($chunk[$key], $sourceLanguage, $targetLanguage, $format),
                    $value,
                    self::CACHE_DURATION,
                );
            }
        }

        // Restore the caller's original ordering.
        $ordered = [];

        foreach (array_keys($texts) as $key) {
            $ordered[$key] = $results[$key] ?? (string)$texts[$key];
        }

        return $ordered;
    }

    /**
     * Convenience wrapper for a single string.
     *
     * @throws TranslatorException
     */
    public function translateOne(string $text, ?string $sourceLanguage, string $targetLanguage, string $format = 'text'): string
    {
        $result = $this->translate([$text], $sourceLanguage, $targetLanguage, $format);

        return (string)reset($result);
    }

    // Connection verification
    // =========================================================================

    /**
     * Whether the configured provider is known to work.
     *
     * Cache-only, so it's safe to call on every control panel page render. An
     * unknown state counts as not verified: the AI buttons stay hidden until
     * something has actually talked to the provider successfully.
     */
    public function isVerified(): bool
    {
        $settings = TranslatorPro::$plugin->getSettings();

        if (!TranslatorPro::$plugin->isPro() || !$settings->isConfigured()) {
            return false;
        }

        return (bool)Craft::$app->getCache()->get($this->verifiedKey($settings->credentialsFingerprint()));
    }

    /**
     * The reason the last verification attempt failed, if there was one.
     */
    public function getVerificationError(): ?string
    {
        $settings = TranslatorPro::$plugin->getSettings();
        $error = Craft::$app->getCache()->get($this->failedKey($settings->credentialsFingerprint()));

        return is_string($error) ? $error : null;
    }

    /**
     * Verifies the connection, hitting the provider only when the answer isn't
     * already known. Call this from admin screens — never from a page render.
     *
     * @param bool $force Re-check even if the result is already cached.
     */
    public function verify(bool $force = false, ?Settings $settings = null): bool
    {
        $settings ??= TranslatorPro::$plugin->getSettings();

        if (!TranslatorPro::$plugin->isPro() || !$settings->isConfigured()) {
            return false;
        }

        $fingerprint = $settings->credentialsFingerprint();
        $cache = Craft::$app->getCache();

        if (!$force) {
            if ($cache->get($this->verifiedKey($fingerprint))) {
                return true;
            }

            // A failed check is remembered briefly so a bad key doesn't get
            // retried on every admin page load.
            if ($cache->get($this->failedKey($fingerprint)) !== false) {
                return false;
            }
        }

        try {
            // Built fresh: the settings may have changed earlier in this request.
            $ok = $this->getProviderFor($settings)->testConnection();
        } catch (\Throwable $e) {
            $cache->delete($this->verifiedKey($fingerprint));
            $cache->set($this->failedKey($fingerprint), $e->getMessage(), self::FAILURE_DURATION);

            return false;
        }

        if (!$ok) {
            $cache->delete($this->verifiedKey($fingerprint));
            $cache->set(
                $this->failedKey($fingerprint),
                Craft::t('translator-pro', 'The connection test didn’t succeed.'),
                self::FAILURE_DURATION,
            );

            return false;
        }

        $cache->delete($this->failedKey($fingerprint));
        $cache->set($this->verifiedKey($fingerprint), true, self::VERIFIED_DURATION);

        return true;
    }

    /**
     * Drops any remembered verification, e.g. after the settings change.
     */
    public function forgetVerification(?Settings $settings = null): void
    {
        $settings ??= TranslatorPro::$plugin->getSettings();
        $fingerprint = $settings->credentialsFingerprint();

        Craft::$app->getCache()->delete($this->verifiedKey($fingerprint));
        Craft::$app->getCache()->delete($this->failedKey($fingerprint));
    }

    private function verifiedKey(string $fingerprint): string
    {
        return 'translator-pro:verified:' . $fingerprint;
    }

    private function failedKey(string $fingerprint): string
    {
        return 'translator-pro:unverified:' . $fingerprint;
    }

    /**
     * Language code for a site, e.g. `nl`, used as the provider's target.
     */
    public function languageForSite(int $siteId): ?string
    {
        return Craft::$app->getSites()->getSiteById($siteId)?->language;
    }

    /**
     * @throws TranslatorException
     */
    private function requirePro(): void
    {
        if (!TranslatorPro::$plugin->isPro()) {
            throw new TranslatorException(Craft::t(
                'translator-pro',
                'AI translations require the Pro edition of Translator Pro.',
            ));
        }
    }

    private function cacheKey(string $text, ?string $source, string $target, string $format): string
    {
        $settings = TranslatorPro::$plugin->getSettings();

        return 'translator-pro:t:' . md5(implode('|', [
            $settings->provider,
            $settings->model,
            $source ?? 'auto',
            $target,
            $format,
            $text,
        ]));
    }
}
