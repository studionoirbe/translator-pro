<?php

namespace studionoir\translatorpro\translators;

use Craft;

/**
 * DeepL, via the v2 REST API.
 *
 * Free keys end in `:fx` and must hit a different host — that's detected
 * automatically, so the "free API" setting is only a manual override.
 */
class DeepL extends BaseTranslator
{
    private const HOST_PRO = 'https://api.deepl.com';
    private const HOST_FREE = 'https://api-free.deepl.com';

    /**
     * DeepL insists on a regional variant for these targets.
     */
    private const TARGET_VARIANTS = [
        'en' => 'EN-GB',
        'pt' => 'PT-PT',
        'zh' => 'ZH-HANS',
    ];

    public function getName(): string
    {
        return 'DeepL';
    }

    public function translate(array $texts, ?string $sourceLanguage, string $targetLanguage, string $format = 'text'): array
    {
        if ($texts === []) {
            return [];
        }

        $body = [
            'text' => array_values($texts),
            'target_lang' => $this->targetLang($targetLanguage),
        ];

        if ($sourceLanguage !== null) {
            $body['source_lang'] = strtoupper($this->baseLanguage($sourceLanguage));
        }

        if ($format === 'html') {
            $body['tag_handling'] = 'html';
        }

        $response = $this->request('POST', $this->host() . '/v2/translate', [
            'headers' => $this->headers(),
            'json' => $body,
        ]);

        if (!isset($response['translations']) || !is_array($response['translations'])) {
            throw new TranslatorException(Craft::t('translator-pro', 'DeepL returned no translations.'));
        }

        $translations = [];

        foreach ($response['translations'] as $translation) {
            $translations[] = (string)($translation['text'] ?? '');
        }

        if (count($translations) !== count($texts)) {
            throw new TranslatorException(Craft::t(
                'translator-pro',
                '{provider} returned {got} translations for {expected} strings.',
                ['provider' => 'DeepL', 'got' => count($translations), 'expected' => count($texts)],
            ));
        }

        return $translations;
    }

    public function testConnection(): bool
    {
        $response = $this->request('GET', $this->host() . '/v2/usage', [
            'headers' => $this->headers(),
        ]);

        return isset($response['character_count']) || isset($response['character_limit']);
    }

    /**
     * Remaining character allowance, for the settings screen.
     *
     * @return array{used:int,limit:int|null}|null
     */
    public function getUsage(): ?array
    {
        try {
            $response = $this->request('GET', $this->host() . '/v2/usage', [
                'headers' => $this->headers(),
            ]);
        } catch (TranslatorException) {
            return null;
        }

        if (!isset($response['character_count'])) {
            return null;
        }

        $limit = $response['character_limit'] ?? null;

        return [
            'used' => (int)$response['character_count'],
            // DeepL reports an absurd sentinel limit on unmetered plans.
            'limit' => is_numeric($limit) && (int)$limit < 1_000_000_000 ? (int)$limit : null,
        ];
    }

    private function host(): string
    {
        if (str_ends_with($this->apiKey(), ':fx')) {
            return self::HOST_FREE;
        }

        return $this->settings->deeplFreeApi ? self::HOST_FREE : self::HOST_PRO;
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey(),
            'Content-Type' => 'application/json',
        ];
    }

    private function targetLang(string $language): string
    {
        $base = $this->baseLanguage($language);
        $region = strtoupper(substr($language, 3, 2));

        // Honour an explicit region when DeepL supports one for that language.
        if ($base === 'en' && in_array($region, ['US', 'GB'], true)) {
            return 'EN-' . $region;
        }

        if ($base === 'pt' && in_array($region, ['BR', 'PT'], true)) {
            return 'PT-' . $region;
        }

        return self::TARGET_VARIANTS[$base] ?? strtoupper($base);
    }
}
