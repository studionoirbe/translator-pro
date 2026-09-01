<?php

namespace studionoir\translingua\migrations;

use craft\db\Migration;
use studionoir\translingua\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createFormSettingsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::FORM_SETTINGS);

        return true;
    }

    /**
     * Per-form translation settings — currently just the language a Formie form
     * is written in, which becomes the target for its translations.
     */
    public function createFormSettingsTable(): void
    {
        if ($this->db->tableExists(Table::FORM_SETTINGS)) {
            return;
        }

        $this->createTable(Table::FORM_SETTINGS, [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'targetLanguage' => $this->string(12),
            'sourceLanguage' => $this->string(12),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::FORM_SETTINGS, ['formId'], true);

        // Settings for a deleted form are meaningless, so let them go with it.
        $this->addForeignKey(
            null,
            Table::FORM_SETTINGS,
            ['formId'],
            \craft\db\Table::ELEMENTS,
            ['id'],
            'CASCADE',
            null,
        );
    }
}
