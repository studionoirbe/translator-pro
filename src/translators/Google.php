<?php

namespace studionoir\translatorpro\translators;

use Craft;

/**
 * Google Gemini, via the Generative Language API.
 */
class Google extends BaseTranslator
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function getName(): string
    {
        return 'Google Gemini';
    }

    protected function defaultModel(): string
    {
        return 'gemini-2.0-flash';
    }

    public function translate(array $texts, ?string $sourceLanguage, string $targetLanguage, string $format = 'text'): array
    {
        if ($texts === []) {
            return [];
        }

        $url = sprintf(self::ENDPOINT, rawurlencode($this->model()));

        $response = $this->request('POST', $url, [
            'headers' => [
                'x-goog-api-key' => $this->apiKey(),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $this->systemPrompt($sourceLanguage, $targetLanguage, $format)],
                    ],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => json_encode(
                                    ['strings' => array_values($texts)],
                                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                                ),
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                ],
            ],
        ]);

        $content = '';

        foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
            $content .= (string)($part['text'] ?? '');
        }

        if (trim($content) === '') {
            throw new TranslatorException(Craft::t('translator-pro', 'Google Gemini returned an empty response.'));
        }

        return $this->parseLlmResponse($content, $texts);
    }

    public function testConnection(): bool
    {
        $result = $this->translate(['Hello'], 'en', 'nl');

        return $result !== [] && $result[0] !== '';
    }
}
