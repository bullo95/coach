<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Migrator;
use PDO;

final class SchemaTest extends DatabaseTestCase
{
    public function testCreatesTheEighteenBusinessTables(): void
    {
        $tables = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([
            'body_measure', 'exercise', 'food', 'food_favorite', 'pain_report', 'protein_log', 'push_subscription',
            'pushup_day', 'pushup_log', 'reminder', 'set_log', 'settings', 'template_block', 'template_item', 'user',
            'user_item_load', 'workout_session', 'workout_template',
        ], $tables);
    }

    public function testMigrationsAreAppliedOnce(): void
    {
        self::assertSame([], (new Migrator($this->db, Config::rootDir() . '/migrations'))->migrate());
    }

    public function testForeignKeysAreEnforced(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->exec("INSERT INTO pushup_log (client_uuid, user_id, logged_at, count) VALUES ('x', 999, '2026-11-25T08:00:00Z', 23)");
    }

    public function testClientUuidPreventsDuplicates(): void
    {
        $this->db->exec("INSERT INTO user (email, password_hash, created_at) VALUES ('a@b.fr', 'h', '2026-11-25')");
        $insert = "INSERT INTO pushup_log (client_uuid, user_id, logged_at, count) VALUES ('same-uuid', 1, '2026-11-25T08:00:00Z', 23)";
        $this->db->exec($insert);
        $this->expectException(\PDOException::class);
        $this->db->exec($insert);
    }

    public function testProteinCoefficientIsBounded(): void
    {
        $this->db->exec("INSERT INTO user (email, password_hash, created_at) VALUES ('a@b.fr', 'h', '2026-11-25')");
        $this->db->exec('INSERT INTO settings (user_id) VALUES (1)');
        $this->expectException(\PDOException::class);
        $this->db->exec('UPDATE settings SET protein_coef = 2.5 WHERE user_id = 1');
    }
}
