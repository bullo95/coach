<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Charge data/exercises.json et data/program.json en base.
 * Idempotent : met à jour par clé stable (slug, code, ref) sans recréer, donc sans toucher à l'historique.
 * Un bloc ou un exercice retiré du JSON est supprimé s'il n'a jamais servi, sinon désactivé.
 */
final class Seeder
{
    public function __construct(private readonly PDO $db, private readonly string $dataDir)
    {
    }

    /** @return array{exercises: int, templates: int, blocks: int, items: int} */
    public function run(): array
    {
        $exercises = self::readJson($this->dataDir . '/exercises.json');
        $program = self::readJson($this->dataDir . '/program.json');

        $this->db->beginTransaction();
        try {
            $exerciseIds = $this->seedExercises($exercises);
            $counts = $this->seedProgram($program['templates'] ?? [], $exerciseIds);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['exercises' => \count($exerciseIds)] + $counts;
    }

    /**
     * @param list<array<string, mixed>> $exercises
     * @return array<string, int> slug → id
     */
    private function seedExercises(array $exercises): array
    {
        $upsert = $this->db->prepare(
            'INSERT INTO exercise (slug, name_fr, name_en, muscles, cues, mistake, easier_variant_text)
             VALUES (:slug, :name_fr, :name_en, :muscles, :cues, :mistake, :variant_text)
             ON CONFLICT(slug) DO UPDATE SET name_fr = excluded.name_fr, name_en = excluded.name_en,
                muscles = excluded.muscles, cues = excluded.cues, mistake = excluded.mistake,
                easier_variant_text = excluded.easier_variant_text'
        );
        $ids = [];
        foreach ($exercises as $ex) {
            $slug = (string) $ex['slug'];
            $cues = $ex['cues'] ?? [];
            if (\count($cues) !== 3) {
                throw new \InvalidArgumentException("Exercice $slug : 3 consignes attendues, " . \count($cues) . ' trouvées.');
            }
            $upsert->execute([
                'slug' => $slug,
                'name_fr' => $ex['name_fr'],
                'name_en' => $ex['name_en'] ?? null,
                'muscles' => $ex['muscles'] ?? null,
                'cues' => json_encode($cues, JSON_UNESCAPED_UNICODE),
                'mistake' => $ex['mistake'] ?? null,
                'variant_text' => $ex['easier_variant_text'] ?? null,
            ]);
            $ids[$slug] = $this->idOf('exercise', 'slug', $slug);
        }

        // Deuxième passe : liens vers les variantes plus faciles.
        $link = $this->db->prepare('UPDATE exercise SET easier_variant_id = ? WHERE id = ?');
        foreach ($exercises as $ex) {
            $variant = $ex['easier_variant'] ?? null;
            if ($variant !== null && !isset($ids[$variant])) {
                throw new \InvalidArgumentException("Exercice {$ex['slug']} : variante inconnue « $variant ».");
            }
            $link->execute([$variant !== null ? $ids[$variant] : null, $ids[$ex['slug']]]);
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $templates
     * @param array<string, int> $exerciseIds
     * @return array{templates: int, blocks: int, items: int}
     */
    private function seedProgram(array $templates, array $exerciseIds): array
    {
        $upsertTemplate = $this->db->prepare(
            'INSERT INTO workout_template (code, name, est_duration_min) VALUES (?, ?, ?)
             ON CONFLICT(code) DO UPDATE SET name = excluded.name, est_duration_min = excluded.est_duration_min'
        );
        $upsertBlock = $this->db->prepare(
            'INSERT INTO template_block (template_id, ref, position, name, type, rounds, rest_between_rounds_s, duration_s, active)
             VALUES (:template_id, :ref, :position, :name, :type, :rounds, :rest, :duration, 1)
             ON CONFLICT(ref) DO UPDATE SET template_id = excluded.template_id, position = excluded.position,
                name = excluded.name, type = excluded.type, rounds = excluded.rounds,
                rest_between_rounds_s = excluded.rest_between_rounds_s, duration_s = excluded.duration_s, active = 1'
        );
        $upsertItem = $this->db->prepare(
            'INSERT INTO template_item (block_id, ref, exercise_id, position, target_reps, target_reps_max, target_seconds,
                target_seconds_max, per_side, load_kg, load_label, rest_s, active)
             VALUES (:block_id, :ref, :exercise_id, :position, :reps, :reps_max, :seconds, :seconds_max, :per_side,
                :load_kg, :load_label, :rest_s, 1)
             ON CONFLICT(ref) DO UPDATE SET block_id = excluded.block_id, exercise_id = excluded.exercise_id,
                position = excluded.position, target_reps = excluded.target_reps, target_reps_max = excluded.target_reps_max,
                target_seconds = excluded.target_seconds, target_seconds_max = excluded.target_seconds_max,
                per_side = excluded.per_side, load_kg = excluded.load_kg, load_label = excluded.load_label,
                rest_s = excluded.rest_s, active = 1'
        );

        $blockRefs = [];
        $itemRefs = [];
        foreach ($templates as $t) {
            $upsertTemplate->execute([$t['code'], $t['name'], $t['est_duration_min']]);
            $templateId = $this->idOf('workout_template', 'code', $t['code']);
            foreach (array_values($t['blocks']) as $bPos => $b) {
                $upsertBlock->execute([
                    'template_id' => $templateId,
                    'ref' => $b['ref'],
                    'position' => $bPos + 1,
                    'name' => $b['name'],
                    'type' => $b['type'],
                    'rounds' => $b['rounds'] ?? 1,
                    'rest' => $b['rest_between_rounds_s'] ?? null,
                    'duration' => $b['duration_s'] ?? null,
                ]);
                $blockId = $this->idOf('template_block', 'ref', $b['ref']);
                $blockRefs[] = $b['ref'];
                foreach (array_values($b['items']) as $iPos => $i) {
                    if (!isset($exerciseIds[$i['exercise']])) {
                        throw new \InvalidArgumentException("{$i['ref']} : exercice inconnu « {$i['exercise']} ».");
                    }
                    $upsertItem->execute([
                        'block_id' => $blockId,
                        'ref' => $i['ref'],
                        'exercise_id' => $exerciseIds[$i['exercise']],
                        'position' => $iPos + 1,
                        'reps' => $i['target_reps'] ?? null,
                        'reps_max' => $i['target_reps_max'] ?? null,
                        'seconds' => $i['target_seconds'] ?? null,
                        'seconds_max' => $i['target_seconds_max'] ?? null,
                        'per_side' => !empty($i['per_side']) ? 1 : 0,
                        'load_kg' => $i['load_kg'] ?? null,
                        'load_label' => $i['load_label'] ?? null,
                        'rest_s' => $i['rest_s'] ?? null,
                    ]);
                    $itemRefs[] = $i['ref'];
                }
            }
        }

        $this->retireMissing('template_item', $itemRefs, 'SELECT 1 FROM set_log WHERE template_item_id = template_item.id');
        $this->retireMissing('template_block', $blockRefs, 'SELECT 1 FROM template_item WHERE block_id = template_block.id');

        return ['templates' => \count($templates), 'blocks' => \count($blockRefs), 'items' => \count($itemRefs)];
    }

    /** @param list<string> $keptRefs */
    private function retireMissing(string $table, array $keptRefs, string $usedSubquery): void
    {
        $in = implode(',', array_fill(0, \count($keptRefs), '?')) ?: "''";
        $this->db->prepare("DELETE FROM $table WHERE ref NOT IN ($in) AND NOT EXISTS ($usedSubquery)")->execute($keptRefs);
        $this->db->prepare("UPDATE $table SET active = 0 WHERE ref NOT IN ($in)")->execute($keptRefs);
    }

    private function idOf(string $table, string $column, string $value): int
    {
        $stmt = $this->db->prepare("SELECT id FROM $table WHERE $column = ?");
        $stmt->execute([$value]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<mixed> */
    private static function readJson(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException("$file : JSON invalide.");
        }

        return $data;
    }
}
