<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Db;
use App\Program;
use App\Seeder;
use PDO;

final class SeederTest extends DatabaseTestCase
{
    public function testLoadsProgramABC(): void
    {
        $templates = (new Program($this->db))->templates();

        self::assertSame(['A', 'B', 'C'], array_column($templates, 'code'));
        foreach ($templates as $t) {
            self::assertNotEmpty($t['blocks'], "Séance {$t['code']} sans bloc");
            self::assertSame(30, $t['est_duration_min']);
        }
    }

    public function testEveryProgramExerciseHasACardWithThreeCues(): void
    {
        $rows = $this->db->query(
            'SELECT DISTINCT e.slug, e.cues, e.mistake FROM template_item i JOIN exercise e ON e.id = i.exercise_id'
        )->fetchAll();

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertCount(3, json_decode($row['cues'], true), $row['slug']);
            self::assertNotEmpty($row['mistake'], $row['slug']);
        }
    }

    public function testEveryExerciseHasAnEasierVariant(): void
    {
        $missing = $this->db->query(
            'SELECT slug FROM exercise WHERE easier_variant_id IS NULL AND easier_variant_text IS NULL'
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([], $missing);
    }

    public function testSeedIsIdempotentAndKeepsIds(): void
    {
        $before = $this->db->query('SELECT ref, id FROM template_item ORDER BY ref')->fetchAll(PDO::FETCH_KEY_PAIR);
        (new Seeder($this->db, Config::rootDir() . '/data'))->run();
        $after = $this->db->query('SELECT ref, id FROM template_item ORDER BY ref')->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertSame($before, $after);
    }

    public function testRemovedItemUsedInHistoryIsDeactivatedNotDeleted(): void
    {
        // Une série enregistrée sur un exercice du programme…
        $this->db->exec("INSERT INTO user (email, password_hash, created_at) VALUES ('a@b.fr', 'h', '2026-11-25')");
        $this->db->exec("INSERT INTO workout_session (client_uuid, user_id, template_id, planned_date, status)
                         VALUES ('s1', 1, (SELECT id FROM workout_template WHERE code = 'A'), '2026-11-23', 'done')");
        $itemId = (int) $this->db->query("SELECT id FROM template_item WHERE ref = 'A-abdos-planche'")->fetchColumn();
        $this->db->exec("INSERT INTO set_log (client_uuid, session_id, template_item_id, seconds_done, logged_at)
                         VALUES ('l1', 1, $itemId, 40, '2026-11-23T18:20:00Z')");

        // …puis l'exercice est retiré du programme.
        $dir = sys_get_temp_dir() . '/coach-data-' . bin2hex(random_bytes(4));
        mkdir($dir);
        copy(Config::rootDir() . '/data/exercises.json', "$dir/exercises.json");
        $program = json_decode((string) file_get_contents(Config::rootDir() . '/data/program.json'), true);
        $items = &$program['templates'][0]['blocks'][2]['items'];
        $items = array_values(array_filter($items, static fn ($i) => $i['ref'] !== 'A-abdos-planche'));
        unset($items);
        file_put_contents("$dir/program.json", json_encode($program));

        (new Seeder($this->db, $dir))->run();
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);

        $row = $this->db->query("SELECT active FROM template_item WHERE id = $itemId")->fetch();
        self::assertSame(0, (int) $row['active'], 'élément conservé mais désactivé');
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM set_log')->fetchColumn(), 'historique intact');
        $names = array_column(array_merge(...array_column((new Program(Db::get()))->templates()[0]['blocks'], 'items')), 'ref');
        self::assertNotContains('A-abdos-planche', $names);
    }
}
