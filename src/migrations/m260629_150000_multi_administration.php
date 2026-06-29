<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\migrations;

use craft\db\Migration;

/**
 * Lets one Craft user retain a token per Moneybird administration and mark one
 * active, instead of a single row overwritten on every connect.
 *
 * Each Moneybird OAuth token is scoped to exactly one administration, so
 * multi-administration support means multiple token rows per user — keyed on
 * (userId, administrationId) — with an `isActive` pointer naming the live one.
 *
 * Idempotent: checks DB state before each step.
 */
class m260629_150000_multi_administration extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%moneybird_tokens}}';
        $schema = $this->db->getSchema()->getTableSchema($table, true);

        if (!isset($schema->columns['isActive'])) {
            $this->addColumn($table, 'isActive', $this->boolean()->notNull()->defaultValue(false)->after('tokenExpiresAt'));
        }
        if (!isset($schema->columns['administrationName'])) {
            $this->addColumn($table, 'administrationName', $this->string()->after('administrationId'));
        }

        // Every existing row is the (sole) active administration for its user.
        $this->execute("UPDATE {$table} SET isActive = 1");

        // Replace the one-row-per-user unique index with one per administration.
        // Create the composite first: userId is its leftmost column, so the
        // userId foreign key can rely on it before the old index is dropped
        // (MySQL refuses to drop an index an FK still needs).
        if (!$this->uniqueIndexExists($table, ['userId', 'administrationId'])) {
            $this->createIndex(null, $table, ['userId', 'administrationId'], true);
        }
        $this->dropUniqueIndexOn($table, ['userId']);

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%moneybird_tokens}}';

        // Collapse to one row per user, then restore unique(userId) before
        // dropping the composite (FK on userId needs an index throughout).
        $this->execute("
            DELETE t1 FROM {$table} t1
            INNER JOIN {$table} t2
            WHERE t1.userId = t2.userId AND t1.id < t2.id
        ");
        if (!$this->uniqueIndexExists($table, ['userId'])) {
            $this->createIndex(null, $table, ['userId'], true);
        }
        $this->dropUniqueIndexOn($table, ['userId', 'administrationId']);

        $schema = $this->db->getSchema()->getTableSchema($table, true);
        if (isset($schema->columns['isActive'])) {
            $this->dropColumn($table, 'isActive');
        }
        if (isset($schema->columns['administrationName'])) {
            $this->dropColumn($table, 'administrationName');
        }

        return true;
    }

    /** True if a unique index on exactly these columns exists. */
    private function uniqueIndexExists(string $table, array $columns): bool
    {
        return $this->findUniqueIndex($table, $columns) !== null;
    }

    /** Drop the unique index covering exactly these columns, if present. */
    private function dropUniqueIndexOn(string $table, array $columns): void
    {
        $name = $this->findUniqueIndex($table, $columns);
        if ($name !== null) {
            $this->dropIndex($name, $table);
        }
    }

    private function findUniqueIndex(string $table, array $columns): ?string
    {
        $rawTable = $this->db->getSchema()->getRawTableName($table);
        $rows = $this->db->createCommand(
            "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND NON_UNIQUE = 0 AND INDEX_NAME != 'PRIMARY'
             GROUP BY INDEX_NAME",
            [':t' => $rawTable],
        )->queryAll();

        $target = implode(',', $columns);
        foreach ($rows as $row) {
            if ($row['cols'] === $target) {
                return $row['INDEX_NAME'];
            }
        }

        return null;
    }
}
