<?php

namespace studionoir\translatorpro\models;

use craft\base\ElementInterface;

/**
 * A single translatable value found on a concrete element.
 *
 * Slots are paired between the source and target site by `key`, which encodes
 * the value's *structural* position — field handle, plus block index and entry
 * type handle for anything inside a Matrix. Element IDs deliberately don't come
 * into it: a Matrix field whose propagation method isn't `all` has entirely
 * different nested entry IDs per site, so an ID-based pairing would find nothing.
 * Carrying the entry type handle in the key keeps a translation from ever landing
 * in a block of the wrong type.
 */
class FieldSlot
{
    public function __construct(
        public readonly string $key,
        public readonly ElementInterface $element,
        public readonly string $path,
        public readonly string $label,
        public readonly string $format,
        /**
         * Craft's translation key for this value. When the source and target
         * site produce the same one, the value is shared between them and must
         * not be translated — doing so would overwrite the source.
         */
        public readonly string $translationKey,
        /** @var callable(): string */
        public readonly mixed $getter,
        /** @var callable(string): void */
        public readonly mixed $setter,
    ) {
    }

    public function getValue(): string
    {
        return (string)($this->getter)();
    }

    public function setValue(string $value): void
    {
        ($this->setter)($value);
    }

    public function isEmpty(): bool
    {
        return trim(strip_tags($this->getValue())) === '';
    }
}
