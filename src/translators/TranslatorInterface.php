<?php

namespace studionoir\translingua\translators;

/**
 * Contract every AI/machine translation provider implements.
 */
interface TranslatorInterface
{
    /**
     * Display name, e.g. `DeepL`.
     */
    public function getName(): string;

    /**
     * Translates a list of strings, preserving order.
     *
     * @param string[] $texts
     * @param string|null $sourceLanguage Source language code (`nl`, `en-GB`, …), or null to auto-detect.
     * @param string $targetLanguage Target language code.
     * @param string $format `text` or `html`.
     * @return string[] Translations, in the same order and of the same length as `$texts`.
     * @throws TranslatorException
     */
    public function translate(array $texts, ?string $sourceLanguage, string $targetLanguage, string $format = 'text'): array;

    /**
     * A cheap round trip used to validate the API key from the settings screen.
     *
     * @throws TranslatorException
     */
    public function testConnection(): bool;
}
