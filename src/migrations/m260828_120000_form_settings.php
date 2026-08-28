<?php

namespace studionoir\translatorpro\migrations;

use craft\db\Migration;
use studionoir\translatorpro\db\Table;

/**
 * Adds the per-form settings table to installs that predate the Formie tab.
 */
class m260828_120000_form_settings extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Table::FORM_SETTINGS)) {
            return true;
        }

        (new Install())->createFormSettingsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::FORM_SETTINGS);

        return true;
    }
}
