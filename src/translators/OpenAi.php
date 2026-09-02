<?php

namespace studionoir\translingua\translators;

use Craft;

/**
 * OpenAI, via the Chat Completions API in JSON mode.
 */
class OpenAi extends BaseTranslator
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function getName(): string
    {
        return 'OpenAI';
    }

    protected function defaultModel(): string
    {
        return 'gpt-4o-mini';
    }

    public function translate(array $texts, ?string $sourceLanguage, string $targetLanguage, string $format = 'text'): array
    {
        if ($texts === []) {
            return [];
        }

        $response = $this->request('POST', self::ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model(),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt($sourceLanguage, $targetLanguage, $format),
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode(
                            ['strings' => array_values($texts)],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ),
                    ],
                ],
            ],
        ]);

        $content = $response['choices'][0]['message']['content'] ?? null;

        if (!is_string($content)) {
            throw new TranslatorException(Craft::t('translingua', 'OpenAI returned an empty response.'));
        }

        return $this->parseLlmResponse($content, $texts);
    }

    protected function runConnectionTest(): bool
    {
        $result = $this->translate(['Hello'], 'en', 'nl');

        return $result !== [] && $result[0] !== '';
    }
}
