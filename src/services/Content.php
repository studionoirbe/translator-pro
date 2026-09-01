<?php

namespace studionoir\translingua\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\base\Component;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\data\LinkData;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\models\EntryType;
use craft\models\FieldLayout;
use studionoir\translingua\models\FieldSlot;
use studionoir\translingua\models\TranslatableField;

/**
 * Understands which parts of an element hold translatable copy, and how to read
 * and write them — including inside Matrix blocks.
 */
class Content extends Component
{
    /**
     * How deep the walker will follow nested Matrix fields.
     */
    private const MAX_DEPTH = 4;

    /**
     * Table column types that hold free text.
     */
    private const TEXT_COLUMN_TYPES = ['singleline', 'multiline', 'heading'];

    /**
     * Link types whose label is the address itself — `info@example.com`, `+32 …` —
     * rather than copy. Translating those corrupts a working link.
     */
    public const UNTRANSLATABLE_LINK_TYPES = ['email', 'tel', 'sms'];

    /**
     * Field classes that hold rich text. Checked with `class_exists` so the
     * plugin never hard-depends on any of them being installed.
     *
     * @var string[]
     */
    private const HTML_FIELD_CLASSES = [
        'craft\\ckeditor\\Field',
        'craft\\redactor\\Field',
        'craft\\htmlfield\\HtmlField',
    ];

    // Field discovery — powers the batch translation field picker
    // =========================================================================

    /**
     * The translatable fields in a field layout, recursing into Matrix fields.
     *
     * @return TranslatableField[]
     */
    public function getTranslatableFields(
        FieldLayout $layout,
        int $depth = 0,
        string $prefix = '',
        ?string $groupLabel = null,
    ): array {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $fields = [];

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof Matrix) {
                foreach ($this->matrixEntryTypes($field) as $entryType) {
                    $nestedLabel = ($groupLabel !== null ? $groupLabel . ' › ' : '')
                        . $field->name . ' (' . $entryType->name . ')';

                    if ($entryType->hasTitleField && $entryType->titleTranslationMethod !== Field::TRANSLATION_METHOD_NONE) {
                        $fields[] = new TranslatableField([
                            'path' => $this->joinPath($prefix, $field->handle, 'title'),
                            'label' => Craft::t('translingua', 'Title'),
                            'groupLabel' => $nestedLabel,
                            'format' => 'text',
                            'type' => 'title',
                            'translatable' => true,
                            'depth' => $depth + 1,
                        ]);
                    }

                    $fields = array_merge($fields, $this->getTranslatableFields(
                        $entryType->getFieldLayout(),
                        $depth + 1,
                        $this->joinPath($prefix, $field->handle),
                        $nestedLabel,
                    ));
                }

                continue;
            }

            $format = $this->formatFor($field);

            if ($format === null) {
                continue;
            }

            $fields[] = new TranslatableField([
                'path' => $this->joinPath($prefix, $field->handle),
                'label' => $field->name,
                'groupLabel' => $groupLabel,
                'format' => $format,
                'type' => $field::class,
                'translatable' => $field->translationMethod !== Field::TRANSLATION_METHOD_NONE,
                'depth' => $depth,
            ]);
        }

        return $fields;
    }

    /**
     * The translatable fields across a set of field layouts, de-duplicated by path.
     *
     * @param FieldLayout[] $layouts
     * @return TranslatableField[]
     */
    public function getTranslatableFieldsForLayouts(
        array $layouts,
        bool $includeTitle = true,
        bool $titleTranslatable = true,
    ): array {
        $fields = [];

        if ($includeTitle) {
            $fields['title'] = new TranslatableField([
                'path' => 'title',
                'label' => Craft::t('translingua', 'Title'),
                'format' => 'text',
                'type' => 'title',
                'translatable' => $titleTranslatable,
                'depth' => 0,
            ]);
        }

        foreach ($layouts as $layout) {
            foreach ($this->getTranslatableFields($layout) as $field) {
                // The same handle in two entry types is one selectable field.
                $fields[$field->path] ??= $field;
            }
        }

        return array_values($fields);
    }

    // Slot walking
    // =========================================================================

    /**
     * Every translatable value on an element, keyed by structural position so
     * the same value on the same element in another site produces the same key.
     *
     * @param string[]|null $paths Restrict to these handle chains, or null for everything.
     * @return array<string,FieldSlot>
     */
    public function collectSlots(
        ElementInterface $element,
        ?array $paths = null,
        string $pathPrefix = '',
        string $keyPrefix = '',
        int $depth = 0,
    ): array {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $slots = [];
        $titlePath = $this->joinPath($pathPrefix, 'title');

        if ($this->titleIsEditable($element) && $this->pathWanted($titlePath, $paths)) {
            $key = $keyPrefix . 'title';

            $slots[$key] = new FieldSlot(
                key: $key,
                element: $element,
                path: $titlePath,
                label: Craft::t('translingua', 'Title'),
                format: 'text',
                translationKey: $element->getTitleTranslationKey(),
                getter: static fn() => (string)$element->title,
                setter: static function(string $value) use ($element) {
                    $element->title = $value;
                },
            );
        }

        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return $slots;
        }

        foreach ($layout->getCustomFields() as $field) {
            $path = $this->joinPath($pathPrefix, $field->handle);

            if ($field instanceof Matrix) {
                if (!$this->prefixWanted($path, $paths)) {
                    continue;
                }

                foreach ($this->nestedEntries($element, $field) as $index => $nested) {
                    $nestedKeyPrefix = sprintf(
                        '%s%s[%d:%s]>',
                        $keyPrefix,
                        $field->handle,
                        $index,
                        $nested->getType()->handle,
                    );

                    $slots += $this->collectSlots($nested, $paths, $path, $nestedKeyPrefix, $depth + 1);
                }

                continue;
            }

            if (!$this->pathWanted($path, $paths)) {
                continue;
            }

            $slots += $this->slotsForField($element, $field, $path, $keyPrefix . $field->handle);
        }

        return $slots;
    }

    /**
     * @return array<string,FieldSlot>
     */
    private function slotsForField(ElementInterface $element, FieldInterface $field, string $path, string $key): array
    {
        $format = $this->formatFor($field);

        if ($format === null) {
            return [];
        }

        try {
            $value = $element->getFieldValue($field->handle);
        } catch (\Throwable $e) {
            Craft::warning("Couldn't read field {$field->handle}: {$e->getMessage()}", __METHOD__);
            return [];
        }

        $translationKey = $field->getTranslationKey($element);

        // Link fields: only the editor-authored label is copy. The fallback label
        // is derived from the target, so translating it would invent a custom one.
        if ($field instanceof Link) {
            if (!$value instanceof LinkData || !$field->showLabelField) {
                return [];
            }

            if (in_array($value->getType(), self::UNTRANSLATABLE_LINK_TYPES, true)) {
                return [];
            }

            $label = $value->getLabel(true);

            if ($label === null || trim($label) === '') {
                return [];
            }

            return [
                $key => new FieldSlot(
                    key: $key,
                    element: $element,
                    path: $path,
                    label: $field->name,
                    format: 'text',
                    translationKey: $translationKey,
                    getter: static fn() => (string)$value->getLabel(true),
                    setter: static function(string $new) use ($value, $element, $field) {
                        $value->setLabel($new);
                        $element->setFieldValue($field->handle, $value);
                    },
                ),
            ];
        }

        if ($field instanceof Table) {
            return $this->slotsForTable($element, $field, $path, $key, $translationKey, $value);
        }

        if (!is_scalar($value) && !$this->isStringable($value)) {
            return [];
        }

        $current = (string)$value;

        return [
            $key => new FieldSlot(
                key: $key,
                element: $element,
                path: $path,
                label: $field->name,
                format: $format,
                translationKey: $translationKey,
                getter: static fn() => $current,
                setter: static function(string $new) use ($element, $field) {
                    $element->setFieldValue($field->handle, $new);
                },
            ),
        ];
    }

    /**
     * Table fields get one slot per text cell.
     *
     * @return array<string,FieldSlot>
     */
    private function slotsForTable(
        ElementInterface $element,
        Table $field,
        string $path,
        string $key,
        string $translationKey,
        mixed $value,
    ): array {
        if (!is_array($value)) {
            return [];
        }

        $textColumns = [];

        foreach ($field->columns as $colId => $column) {
            if (in_array($column['type'] ?? '', self::TEXT_COLUMN_TYPES, true)) {
                $textColumns[$colId] = $column['handle'] ?? $colId;
            }
        }

        if ($textColumns === []) {
            return [];
        }

        $slots = [];

        foreach ($value as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($textColumns as $colId => $handle) {
                // Rows are keyed by column handle when the column has one, else by ID.
                $cellKey = array_key_exists($handle, $row) ? $handle : $colId;

                if (!array_key_exists($cellKey, $row) || !is_scalar($row[$cellKey])) {
                    continue;
                }

                $cellValue = (string)$row[$cellKey];

                if (trim($cellValue) === '') {
                    continue;
                }

                $slotKey = sprintf('%s#%s#%s', $key, $rowIndex, $cellKey);

                $slots[$slotKey] = new FieldSlot(
                    key: $slotKey,
                    element: $element,
                    path: $path,
                    label: sprintf('%s (%s)', $field->name, $handle),
                    format: 'text',
                    translationKey: $translationKey,
                    getter: static fn() => $cellValue,
                    setter: static function(string $new) use ($element, $field, $rowIndex, $cellKey) {
                        $rows = $element->getFieldValue($field->handle);

                        if (is_array($rows) && isset($rows[$rowIndex]) && is_array($rows[$rowIndex])) {
                            $rows[$rowIndex][$cellKey] = $new;
                            $element->setFieldValue($field->handle, $rows);
                        }
                    },
                );
            }
        }

        return $slots;
    }

    // Helpers
    // =========================================================================

    /**
     * `text`, `html`, or null when the field holds nothing translatable.
     */
    public function formatFor(FieldInterface $field): ?string
    {
        foreach (self::HTML_FIELD_CLASSES as $class) {
            if (class_exists($class) && $field instanceof $class) {
                return 'html';
            }
        }

        if ($field instanceof PlainText || $field instanceof Table || $field instanceof Link) {
            return 'text';
        }

        return null;
    }

    /**
     * The entry types a Matrix field can contain.
     *
     * @return EntryType[]
     */
    private function matrixEntryTypes(Matrix $field): array
    {
        try {
            return $field->getEntryTypes();
        } catch (\Throwable $e) {
            Craft::warning("Couldn't read entry types for Matrix field {$field->handle}: {$e->getMessage()}", __METHOD__);
            return [];
        }
    }

    /**
     * The nested entries of a Matrix field, in the element's own site.
     *
     * @return Entry[]
     */
    private function nestedEntries(ElementInterface $element, Matrix $field): array
    {
        try {
            $value = $element->getFieldValue($field->handle);
        } catch (\Throwable $e) {
            Craft::warning("Couldn't read Matrix field {$field->handle}: {$e->getMessage()}", __METHOD__);
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, static fn($v) => $v instanceof Entry));
        }

        if (!$value instanceof ElementQueryInterface) {
            return [];
        }

        try {
            // Disabled blocks still hold copy that should be translated.
            return array_values($value->status(null)->limit(null)->all());
        } catch (\Throwable $e) {
            Craft::warning("Couldn't load Matrix blocks for {$field->handle}: {$e->getMessage()}", __METHOD__);
            return [];
        }
    }

    /**
     * Whether the element's title is something an editor types.
     *
     * Entry types can generate the title from a format such as `{fieldTitle}`,
     * in which case Craft rewrites it on every save — offering it for
     * translation would report work that silently gets thrown away.
     */
    private function titleIsEditable(ElementInterface $element): bool
    {
        if (!$element::hasTitles()) {
            return false;
        }

        if ($element instanceof Entry) {
            try {
                return $element->getType()->hasTitleField;
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    private function isStringable(mixed $value): bool
    {
        return is_object($value) && method_exists($value, '__toString');
    }

    private function joinPath(string $prefix, string ...$parts): string
    {
        $segments = array_merge($prefix !== '' ? [$prefix] : [], array_filter($parts));

        return implode('>', $segments);
    }

    /**
     * @param string[]|null $paths
     */
    private function pathWanted(string $path, ?array $paths): bool
    {
        return $paths === null || in_array($path, $paths, true);
    }

    /**
     * Whether any selected path lives under this Matrix field.
     *
     * @param string[]|null $paths
     */
    private function prefixWanted(string $path, ?array $paths): bool
    {
        if ($paths === null) {
            return true;
        }

        foreach ($paths as $candidate) {
            if ($candidate === $path || str_starts_with($candidate, $path . '>')) {
                return true;
            }
        }

        return false;
    }
}
