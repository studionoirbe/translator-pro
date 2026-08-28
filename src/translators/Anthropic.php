<?php

namespace studionoir\translatorpro\translators;

use Craft;

/**
 * Anthropic Claude, via the Messages API.
 */
class Anthropic extends BaseTranslator
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function getName(): string
    {
        return 'Anthropic';
    }

    protected function defaultModel(): string
    {
        return 'claude-sonnet-5';
    }

    public function translate(array $texts, ?string $sourceLanguage, string $targetLanguage, string $format = 'text'): array
    {
        if ($texts === []) {
            return [];
        }

        $payload = json_encode(
            ['strings' => array_values($texts)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $response = $this->request('POST', self::ENDPOINT, [
            'headers' => [
                'x-api-key' => $this->apiKey(),
                'anthropic-version' => self::API_VERSION,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model(),
                'max_tokens' => $this->maxTokens($texts),
                'temperature' => 0,
                'system' => $this->systemPrompt($sourceLanguage, $targetLanguage, $format),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $payload,
                    ],
                    [
                        // Prefilling the opening brace keeps the reply to bare JSON.
                        'role' => 'assistant',
                        'content' => '{"translations":',
                    ],
                ],
            ],
        ]);

        $content = '';

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= (string)($block['text'] ?? '');
            }
        }

        if (trim($content) === '') {
            throw new TranslatorException(Craft::t('translator-pro', 'Anthropic returned an empty response.'));
        }

        // Re-attach the prefill so the payload is valid JSON again.
        return $this->parseLlmResponse('{"translations":' . $content, $texts);
    }

    public function testConnection(): bool
    {
        $result = $this->translate(['Hello'], 'en', 'nl');

        return $result !== [] && $result[0] !== '';
    }

    /**
     * Roughly four characters per token, doubled to leave room for a longer target
     * language, then floored and capped at something sane.
     *
     * @param string[] $texts
     */
    private function maxTokens(array $texts): int
    {
        $characters = array_sum(array_map('mb_strlen', $texts));

        return max(1024, min(16384, (int)ceil($characters / 4) * 2 + 512));
    }
}
