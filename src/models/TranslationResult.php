<?php

namespace studionoir\translatorpro\models;

/**
 * The outcome of translating one element into one site.
 */
class TranslationResult
{
    /** @var int Values that were translated and written. */
    public int $translated = 0;

    /** @var int Values skipped because the target already had content. */
    public int $skippedFilled = 0;

    /** @var int Values skipped because they aren't translatable per site. */
    public int $skippedShared = 0;

    /** @var int Values skipped because the source was empty. */
    public int $skippedEmpty = 0;

    /** @var string[] */
    public array $errors = [];

    public function merge(self $other): void
    {
        $this->translated += $other->translated;
        $this->skippedFilled += $other->skippedFilled;
        $this->skippedShared += $other->skippedShared;
        $this->skippedEmpty += $other->skippedEmpty;
        $this->errors = array_merge($this->errors, $other->errors);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'translated' => $this->translated,
            'skippedFilled' => $this->skippedFilled,
            'skippedShared' => $this->skippedShared,
            'skippedEmpty' => $this->skippedEmpty,
            'errors' => $this->errors,
        ];
    }
}
