<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Install migration — creates the Moneybird token storage table.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%moneybird_tokens}}';

        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'moneybirdUserId' => $this->string()->notNull(),
            'administrationId' => $this->string()->notNull(),
            'accessToken' => $this->text()->notNull(),
            'refreshToken' => $this->text(),
            'tokenExpiresAt' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['userId'], true);
        $this->createIndex(null, $table, ['moneybirdUserId'], false);

        $this->addForeignKey(
            null,
            $table,
            ['userId'],
            Table::USERS,
            ['id'],
            'CASCADE',
            null,
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%moneybird_tokens}}');

        return true;
    }
}
