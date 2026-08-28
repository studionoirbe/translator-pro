<?php

namespace studionoir\translatorpro\models;

use craft\base\Model;

/**
 * One selectable field in the batch translation UI.
 *
 * Matrix fields are flattened: a field inside a Matrix block gets a `path` of
 * `matrixHandle>fieldHandle`, and its label carries the block type name.
 */
class TranslatableField extends Model
{
    /**
     * @var string Handle chain, e.g. `fieldIntro` or `fieldBlocks>fieldText`.
     */
    public string $path = '';

    public string $label = '';

    /**
     * @var string|null The Matrix block type this field lives in, for display.
     */
    public ?string $groupLabel = null;

    /**
     * @var string `text` or `html`.
     */
    public string $format = 'text';

    /**
     * @var string Field class or `title`.
     */
    public string $type = '';

    /**
     * @var bool Whether the value can differ per site. Fields that can't are
     * never translated, since writing to one site would overwrite every site.
     */
    public bool $translatable = true;

    /**
     * @var int Nesting depth — 0 for the element's own fields.
     */
    public int $depth = 0;

    public function getFullLabel(): string
    {
        return $this->groupLabel !== null
            ? $this->groupLabel . ' › ' . $this->label
            : $this->label;
    }
}
