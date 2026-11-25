<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Lecture du programme (séances A/B/C → blocs → exercices) et de la bibliothèque d'exercices.
 */
final class Program
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function exercises(): array
    {
        $rows = $this->db->query(
            'SELECT e.*, v.slug AS easier_variant_slug, v.name_fr AS easier_variant_name
             FROM exercise e LEFT JOIN exercise v ON v.id = e.easier_variant_id
             ORDER BY e.name_fr'
        )->fetchAll();

        return array_map(self::exerciseOut(...), $rows);
    }

    /** @return array<string, mixed>|null */
    public function exercise(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, v.slug AS easier_variant_slug, v.name_fr AS easier_variant_name
             FROM exercise e LEFT JOIN exercise v ON v.id = e.easier_variant_id WHERE e.slug = ?'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row ? self::exerciseOut($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        $templates = $this->db->query('SELECT * FROM workout_template ORDER BY code')->fetchAll();
        $blocks = $this->db->query('SELECT * FROM template_block WHERE active = 1 ORDER BY template_id, position')->fetchAll();
        $items = $this->db->query(
            'SELECT i.*, e.slug AS exercise_slug, e.name_fr AS exercise_name
             FROM template_item i JOIN exercise e ON e.id = i.exercise_id
             WHERE i.active = 1 ORDER BY i.block_id, i.position'
        )->fetchAll();

        $itemsByBlock = [];
        foreach ($items as $item) {
            $itemsByBlock[$item['block_id']][] = [
                'id' => (int) $item['id'],
                'ref' => $item['ref'],
                'exercise' => ['slug' => $item['exercise_slug'], 'name' => $item['exercise_name']],
                'target_reps' => self::int($item['target_reps']),
                'target_reps_max' => self::int($item['target_reps_max']),
                'target_seconds' => self::int($item['target_seconds']),
                'target_seconds_max' => self::int($item['target_seconds_max']),
                'per_side' => (bool) $item['per_side'],
                'load_kg' => $item['load_kg'] === null ? null : (float) $item['load_kg'],
                'load_label' => $item['load_label'],
                'rest_s' => self::int($item['rest_s']),
            ];
        }
        $blocksByTemplate = [];
        foreach ($blocks as $block) {
            $blocksByTemplate[$block['template_id']][] = [
                'id' => (int) $block['id'],
                'ref' => $block['ref'],
                'name' => $block['name'],
                'type' => $block['type'],
                'rounds' => (int) $block['rounds'],
                'rest_between_rounds_s' => self::int($block['rest_between_rounds_s']),
                'duration_s' => self::int($block['duration_s']),
                'items' => $itemsByBlock[$block['id']] ?? [],
            ];
        }

        return array_map(static fn (array $t) => [
            'id' => (int) $t['id'],
            'code' => $t['code'],
            'name' => $t['name'],
            'est_duration_min' => (int) $t['est_duration_min'],
            'blocks' => $blocksByTemplate[$t['id']] ?? [],
        ], $templates);
    }

    /** @param array<string, mixed> $row */
    private static function exerciseOut(array $row): array
    {
        return [
            'slug' => $row['slug'],
            'name_fr' => $row['name_fr'],
            'name_en' => $row['name_en'],
            'muscles' => $row['muscles'],
            'cues' => json_decode((string) $row['cues'], true) ?: [],
            'mistake' => $row['mistake'],
            'easier_variant' => $row['easier_variant_slug']
                ? ['slug' => $row['easier_variant_slug'], 'name' => $row['easier_variant_name']]
                : null,
            'easier_variant_text' => $row['easier_variant_text'],
            'media_type' => $row['media_type'],
            'media_paths' => json_decode((string) $row['media_paths'], true) ?: [],
            'source' => $row['source'],
            'license' => $row['license'],
            'attribution' => $row['attribution'],
        ];
    }

    private static function int(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
