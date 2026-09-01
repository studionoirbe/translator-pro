<?php

namespace studionoir\translingua\translators;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use studionoir\translingua\models\Settings;

/**
 * Shared plumbing for the providers: HTTP, error handling and prompt building.
 */
abstract class BaseTranslator extends Component implements TranslatorInterface
{
    protected Settings $settings;
    private ?Client $client = null;

    public function __construct(Settings $settings, array $config = [])
    {
        $this->settings = $settings;
        parent::__construct($config);
    }

    /**
     * Default model for providers that take one.
     */
    protected function defaultModel(): string
    {
        return '';
    }

    protected function model(): string
    {
        return $this->settings->model !== '' ? $this->settings->model : $this->defaultModel();
    }

    protected function apiKey(): string
    {
        $key = $this->settings->getResolvedApiKey();

        if ($key === '') {
            throw new TranslatorException(Craft::t('translingua', 'No API key is configured for {provider}.', [
                'provider' => $this->getName(),
            ]));
        }

        return $key;
    }

    protected function client(): Client
    {
        return $this->client ??= Craft::createGuzzleClient([
            'timeout' => 120,
            'connect_timeout' => 15,
        ]);
    }

    /**
     * Performs a JSON request and decodes the response.
     *
     * @param array<string,mixed> $options
     * @return array<mixed>
     * @throws TranslatorException
     */
    protected function request(string $method, string $url, array $options = []): array
    {
        try {
            $response = $this->client()->request($method, $url, $options);
        } catch (RequestException $e) {
            $body = $e->getResponse()?->getBody()->getContents() ?? '';
            $status = $e->getResponse()?->getStatusCode();

            Craft::error(sprintf(
                '%s request failed (%s): %s',
                $this->getName(),
                $status ?? 'no response',
                $body !== '' ? $body : $e->getMessage(),
            ), __METHOD__);

            throw new TranslatorException($this->friendlyError($status, $body, $e->getMessage()), 0, $e);
        } catch (GuzzleException $e) {
            Craft::error("{$this->getName()} request failed: {$e->getMessage()}", __METHOD__);
            throw new TranslatorException($e->getMessage(), 0, $e);
        }

        $contents = (string)$response->getBody();
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new TranslatorException(Craft::t('translingua', '{provider} returned an unreadable response.', [
                'provider' => $this->getName(),
            ]));
        }

        return $decoded;
    }

    /**
     * Turns an HTTP failure into something worth showing a content editor.
     */
    protected function friendlyError(?int $status, string $body, string $fallback): string
    {
        $detail = '';
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $detail = (string)(
                $decoded['error']['message']
                ?? $decoded['message']
                ?? $decoded['error']
                ?? ''
            );
        }

        return match ($status) {
            401, 403 => Craft::t('translingua', 'The {provider} API key was rejected.', ['provider' => $this->getName()]),
            429 => Craft::t('translingua', '{provider} rate limit or quota reached. Try again shortly.', ['provider' => $this->getName()]),
            456 => Craft::t('translingua', 'Your {provider} character quota is used up.', ['provider' => $this->getName()]),
            default => $detail !== '' ? $detail : $fallback,
        };
    }

    /**
     * The instruction shared by every LLM provider.
     */
    protected function systemPrompt(?string $sourceLanguage, string $targetLanguage, string $format): string
    {
        $source = $sourceLanguage !== null
            ? "from $sourceLanguage "
            : '';

        $prompt = <<<PROMPT
            You are a professional translator working on website copy in a CMS.
            Translate each string {$source}into {$targetLanguage}.

            Rules:
            - Return a JSON object shaped exactly as {"translations": ["...", "..."]}.
            - The "translations" array MUST have exactly the same number of items as the input, in the same order.
            - Translate only the text. Never add commentary, notes or quotes around a translation.
            - Preserve the original tone, register and capitalisation style.
            - Leave untouched: URLs, email addresses, file paths, code, and placeholders such as {name}, {0}, %s, :count, #{var}.
            - If a string is a proper noun, brand name or already in the target language, return it unchanged.
            - Preserve leading and trailing whitespace exactly as given.
            PROMPT;

        if ($format === 'html') {
            $prompt .= "\n- The strings contain HTML. Translate only the text nodes and the values of"
                . " `alt`, `title` and `aria-label`. Keep every tag, attribute and entity byte-for-byte intact.";
        }

        if (trim($this->settings->promptContext) !== '') {
            $prompt .= "\n\nAdditional context for this project:\n" . trim($this->settings->promptContext);
        }

        return $prompt;
    }

    /**
     * Validates and normalises the array an LLM handed back.
     *
     * @param string[] $texts
     * @return string[]
     * @throws TranslatorException
     */
    protected function parseLlmResponse(string $content, array $texts): array
    {
        $content = trim($content);

        // Models occasionally wrap JSON in a fenced code block.
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content) ?? $content;
            $content = trim($content);
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded) && isset($decoded['translations']) && is_array($decoded['translations'])) {
            $translations = array_values($decoded['translations']);
        } elseif (is_array($decoded) && array_is_list($decoded)) {
            $translations = $decoded;
        } else {
            throw new TranslatorException(Craft::t('translingua', '{provider} did not return valid JSON.', [
                'provider' => $this->getName(),
            ]));
        }

        if (count($translations) !== count($texts)) {
            throw new TranslatorException(Craft::t(
                'translingua',
                '{provider} returned {got} translations for {expected} strings.',
                [
                    'provider' => $this->getName(),
                    'got' => count($translations),
                    'expected' => count($texts),
                ],
            ));
        }

        $result = [];

        foreach ($translations as $i => $translation) {
            // Never let a null or object collapse a field to an empty value.
            $result[] = is_scalar($translation) ? (string)$translation : $texts[$i];
        }

        return $result;
    }

    /**
     * Most APIs want a bare two-letter code rather than Craft's `nl-BE` style.
     */
    protected function baseLanguage(string $language): string
    {
        return strtolower(substr($language, 0, 2));
    }
}
