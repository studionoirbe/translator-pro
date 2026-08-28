<?php

namespace studionoir\translatorpro\records;

use craft\db\ActiveRecord;
use studionoir\translatorpro\db\Table;

/**
 * Translation settings for one Formie form.
 *
 * @property int $id
 * @property int $formId
 * @property string|null $targetLanguage The language the form is written in.
 * @property string|null $sourceLanguage The language it was last translated from.
 */
class FormSettings extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::FORM_SETTINGS;
    }
}
