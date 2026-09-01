<?php

namespace studionoir\translingua\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use studionoir\translingua\models\TranslationResult;
use studionoir\translingua\records\FormSettings;
use studionoir\translingua\translators\TranslatorException;
use studionoir\translingua\Translingua;

/**
 * Everything Formie-specific lives here, behind `isAvailable()`, so the rest of
 * the plugin never assumes Formie is installed.
 *
 * A Formie form isn't localised the way an entry is: its labels live in the
 * field layout, not in per-site content. So translating a form rewrites it in
 * place — which is why each form carries the language it's written in, and why
 * the usual workflow is to duplicate a form before translating the copy.
 */
class FormieBridge extends Component
{
    /**
     * Field properties that hold editor-facing copy.
     *
     * `handle` is deliberately absent and must stay that way: it's the key used
     * in submissions, notifications, integrations and templates. The same goes
     * for option *values* — only their labels are translated.
     */
    public const FIELD_STRINGS = [
        'label',
        'placeholder',
        'instructions',
        'errorMessage',
        'defaultValue',
    ];

    /**
     * Form-level properties, from Formie's Behaviour tab. Both are rich text,
     * stored as ProseMirror JSON.
     */
    public const FORM_RICH_TEXT = [
        'submitActionMessage',
        'errorMessage',
    ];

    /**
     * Every attribute the Formie integration will touch, for the control panel
     * JS to match its per-field buttons against.
     *
     * @return string[]
     */
    public static function translatableAttributes(): array
    {
        return array_values(array_unique(array_merge(self::FIELD_STRINGS, self::FORM_RICH_TEXT)));
    }

    public function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('formie')
            && class_exists(\verbb\formie\Formie::class);
    }

    /**
     * The form, or null if Formie isn't installed or the ID is unknown.
     */
    public function getForm(int $formId): mixed
    {
        if (!$this->isAvailable()) {
            return null;
        }

        return \verbb\formie\Formie::$plugin->getForms()->getFormById($formId);
    }

    // Per-form settings
    // =========================================================================

    /**
     * The language a form is written in — an explicit choice if one was made,
     * otherwise the language of the site the form belongs to.
     */
    public function getTargetLanguage(int $formId): ?string
    {
        $record = FormSettings::findOne(['formId' => $formId]);

        if ($record?->targetLanguage) {
            return $record->targetLanguage;
        }

        $form = $this->getForm($formId);

        if ($form === null) {
            return null;
        }

        return Craft::$app->getSites()->getSiteById($form->siteId)?->language;
    }

    /**
     * The language this form was last translated from.
     */
    public function getSourceLanguage(int $formId): ?string
    {
        return FormSettings::findOne(['formId' => $formId])?->sourceLanguage;
    }

    /**
     * Whether the language was set explicitly rather than inherited from the site.
     */
    public function hasExplicitLanguage(int $formId): bool
    {
        return (bool)FormSettings::findOne(['formId' => $formId])?->targetLanguage;
    }

    public function saveSettings(int $formId, ?string $targetLanguage, ?string $sourceLanguage = null): void
    {
        $record = FormSettings::findOne(['formId' => $formId]) ?? new FormSettings(['formId' => $formId]);

        $record->targetLanguage = $targetLanguage ?: null;

        if ($sourceLanguage !== null) {
            $record->sourceLanguage = $sourceLanguage ?: null;
        }

        if (!$record->save()) {
            // A silently dropped save is how you end up translating into the
            // wrong language and not knowing why.
            Craft::error(sprintf(
                "Couldn't save Translingua settings for form %d: %s",
                $formId,
                Json::encode($record->getErrors()),
            ), __METHOD__);

            throw new \RuntimeException(Craft::t('translingua', 'Couldn’t save the form’s translation settings.'));
        }
    }

    // Translating a form
    // =========================================================================

    /**
     * Translates a form's labels, placeholders, instructions and other copy in
     * place. Handles, option values and every other identifier are left alone.
     */
    public function translateForm(
        mixed $form,
        ?string $sourceLanguage,
        string $targetLanguage,
        bool $overwrite = false,
    ): TranslationResult {
        $result = new TranslationResult();

        if ($form === null) {
            $result->errors[] = Craft::t('translingua', 'Form not found.');
            return $result;
        }

        if ($sourceLanguage !== null && $sourceLanguage === $targetLanguage) {
            $result->errors[] = Craft::t('translingua', 'The source and target language are the same.');
            return $result;
        }

        $slots = $this->collectSlots($form);

        if ($slots === []) {
            return $result;
        }

        $texts = [];

        foreach ($slots as $key => $slot) {
            $value = (string)$slot['value'];

            if (trim($value) === '') {
                $result->skippedEmpty++;
                continue;
            }

            $texts[$key] = $value;
        }

        if ($texts === []) {
            return $result;
        }

        // Split by format so rich text keeps its markup handling.
        $byFormat = [];

        foreach ($texts as $key => $text) {
            $byFormat[$slots[$key]['format']][$key] = $text;
        }

        $translated = [];

        foreach ($byFormat as $format => $group) {
            try {
                $translated += Translingua::$plugin->translator->translate(
                    $group,
                    $sourceLanguage,
                    $targetLanguage,
                    $format,
                );
            } catch (TranslatorException $e) {
                $result->errors[] = $e->getMessage();
                return $result;
            }
        }

        foreach ($translated as $key => $value) {
            if ($value === '' || $value === $slots[$key]['value']) {
                continue;
            }

            $slots[$key]['set']($value);
            $result->translated++;
        }

        if ($result->translated === 0) {
            return $result;
        }

        if (!Craft::$app->getElements()->saveElement($form)) {
            $result->errors[] = implode(' ', array_merge(...array_values($form->getErrors())));
        }

        return $result;
    }

    /**
     * Every translatable string on a form, as `key => [value, format, label, set]`.
     *
     * @return array<string,array{value:string,format:string,label:string,set:callable}>
     */
    public function collectSlots(mixed $form): array
    {
        if ($form === null) {
            return [];
        }

        $slots = [];

        $this->collectFormSettings($form, $slots);
        $this->collectLayout($form, $slots);

        return $slots;
    }

    /**
     * @param array<string,mixed> $slots
     */
    private function collectFormSettings(mixed $form, array &$slots): void
    {
        $settings = $form->settings ?? null;

        if ($settings === null) {
            return;
        }

        $labels = [
            'submitActionMessage' => Craft::t('translingua', 'Submission message'),
            'errorMessage' => Craft::t('translingua', 'Error message'),
        ];

        foreach (self::FORM_RICH_TEXT as $attr) {
            $label = $labels[$attr] ?? $attr;

            if (!property_exists($settings, $attr)) {
                continue;
            }

            $this->collectRichText(
                $settings->$attr,
                'settings:' . $attr,
                $label,
                $slots,
                function($v) use ($settings, $attr) {
                    $settings->$attr = $v;
                },
            );
        }
    }

    /**
     * @param array<string,mixed> $slots
     */
    /**
     * @param array<string,mixed> $slots
     */
    private function collectLayout(mixed $form, array &$slots): void
    {
        $layout = $form->getFormLayout();

        // Page names and button labels are deliberately not collected: the
        // agreed scope is field settings plus the two Behaviour messages.
        foreach ($layout->getPages() as $pageIndex => $page) {
            foreach ($page->getFields() as $field) {
                $this->collectField($field, "page:$pageIndex:field:{$field->handle}", $slots);
            }
        }
    }

    /**
     * @param array<string,mixed> $slots
     */
    /**
     * @param array<string,mixed> $slots
     */
    private function collectField(mixed $field, string $prefix, array &$slots): void
    {
        foreach (self::FIELD_STRINGS as $attr) {
            if (!property_exists($field, $attr)) {
                continue;
            }

            $value = $field->$attr ?? null;

            // `defaultValue` is a string on a text field but a bool on Agree, an
            // array on Checkboxes and a date elsewhere. Only translate real copy.
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $slots["$prefix:$attr"] = [
                'value' => $value,
                'format' => 'text',
                'label' => sprintf('%s — %s', $field->label ?? $field->handle, $this->attributeLabel($attr)),
                'set' => function(string $v) use ($field, $attr) {
                    $field->$attr = $v;
                },
            ];
        }
    }

    /**
     * Formie stores rich text as ProseMirror JSON. Each text node becomes its
     * own slot so the document structure survives untouched.
     *
     * @param array<string,mixed> $slots
     */
    private function collectRichText(mixed $value, string $prefix, string $label, array &$slots, callable $setWhole): void
    {
        $doc = is_string($value) ? Json::decodeIfJson($value) : $value;

        if (!is_array($doc)) {
            return;
        }

        $paths = [];
        $this->findTextNodes($doc, [], $paths);

        foreach ($paths as $i => $path) {
            $text = $this->valueAtPath($doc, $path);

            if (!is_string($text) || trim($text) === '') {
                continue;
            }

            $slots["$prefix:text:$i"] = [
                'value' => $text,
                'format' => 'text',
                'label' => $label,
                'set' => function(string $v) use (&$doc, $path, $setWhole, $value) {
                    $this->setValueAtPath($doc, $path, $v);
                    $setWhole(is_string($value) ? Json::encode($doc) : $doc);
                },
            ];
        }
    }

    /**
     * Collects the path to every `text` value in a ProseMirror document.
     *
     * @param array<int,string|int> $path
     * @param array<int,array<int,string|int>> $found
     */
    private function findTextNodes(array $node, array $path, array &$found): void
    {
        foreach ($node as $key => $value) {
            $childPath = array_merge($path, [$key]);

            if ($key === 'text' && is_string($value)) {
                $found[] = $childPath;
            } elseif (is_array($value)) {
                $this->findTextNodes($value, $childPath, $found);
            }
        }
    }

    /**
     * @param array<int,string|int> $path
     */
    private function valueAtPath(array $doc, array $path): mixed
    {
        $current = $doc;

        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }

            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @param array<int,string|int> $path
     */
    private function setValueAtPath(array &$doc, array $path, mixed $value): void
    {
        $current = &$doc;

        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return;
            }

            $current = &$current[$key];
        }

        $current = $value;
    }

    private function attributeLabel(string $attr): string
    {
        return match ($attr) {
            'label' => Craft::t('translingua', 'Label'),
            'placeholder' => Craft::t('translingua', 'Placeholder'),
            'instructions' => Craft::t('translingua', 'Instructions'),
            'errorMessage' => Craft::t('translingua', 'Error message'),
            'defaultValue' => Craft::t('translingua', 'Default value'),
            default => $attr,
        };
    }
}
