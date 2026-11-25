<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpError;
use PDO;

/**
 * Journal des séances : charges préremplies, synchronisation des saisies (idempotente), historique.
 */
final class Workouts
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const PAIN_AREAS = ['epaule', 'coude', 'dos', 'genou', 'autre'];

    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
    }

    /**
     * Charge préremplie par exercice du programme : dernière charge saisie,
     * sinon charge validée (user_item_load), sinon charge du programme.
     *
     * @return array<int, float|null> template_item_id → kg
     */
    public function loads(): array
    {
        $stmt = $this->db->prepare(
            "SELECT i.id,
                (SELECT l.load_kg FROM set_log l JOIN workout_session s ON s.id = l.session_id
                 WHERE l.template_item_id = i.id AND s.user_id = :u AND l.load_kg IS NOT NULL
                 ORDER BY l.logged_at DESC, l.id DESC LIMIT 1) AS last_load,
                (SELECT ul.load_kg FROM user_item_load ul
                 WHERE ul.template_item_id = i.id AND ul.user_id = :u AND ul.load_kg IS NOT NULL
                 ORDER BY ul.validated_at DESC LIMIT 1) AS validated_load,
                i.load_kg AS program_load
             FROM template_item i WHERE i.active = 1"
        );
        $stmt->execute(['u' => $this->userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $v = $r['last_load'] ?? $r['validated_load'] ?? $r['program_load'];
            $out[(int) $r['id']] = $v === null ? null : (float) $v;
        }

        return $out;
    }

    /**
     * Applique une liste d'événements saisis sur le téléphone (éventuellement hors ligne).
     * Chaque événement porte un client_uuid : le rejouer ne crée pas de doublon.
     *
     * @param list<array<string, mixed>> $events
     * @return array{accepted: list<string>, rejected: list<array{uuid: string, error: string}>}
     */
    public function sync(array $events): array
    {
        $accepted = [];
        $rejected = [];
        foreach ($events as $event) {
            $uuid = (string) ($event['client_uuid'] ?? '');
            try {
                if (!preg_match(self::UUID, $uuid)) {
                    throw new HttpError(422, 'client_uuid invalide');
                }
                $this->db->beginTransaction();
                match ($event['type'] ?? null) {
                    'session_start' => $this->sessionStart($event),
                    'set' => $this->set($event),
                    'session_end' => $this->sessionEnd($event),
                    'pushup' => (new Pushups($this->db, $this->userId))->log($event),
                    default => throw new HttpError(422, 'Type d\'événement inconnu'),
                };
                $this->db->commit();
                $accepted[] = $uuid;
            } catch (HttpError|\InvalidArgumentException $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $rejected[] = ['uuid' => $uuid, 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e; // erreur serveur : le téléphone garde l'événement et réessaiera
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 60): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.id, t.code, t.name, s.planned_date, s.started_at, s.ended_at, s.status, s.rounds_done,
                    s.effort_1_5, s.pain_flag, s.note, s.joker,
                    (SELECT COUNT(*) FROM set_log l WHERE l.session_id = s.id) AS sets_count
             FROM workout_session s JOIN workout_template t ON t.id = s.template_id
             WHERE s.user_id = ? AND (s.status IN ('done', 'in_progress') OR s.joker = 1)
             ORDER BY s.planned_date DESC, s.started_at DESC LIMIT ?"
        );
        $stmt->execute([$this->userId, $limit]);

        return array_map(self::sessionOut(...), $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function session(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.id, t.code, t.name, s.planned_date, s.started_at, s.ended_at, s.status, s.rounds_done,
                    s.effort_1_5, s.pain_flag, s.note, s.joker,
                    (SELECT COUNT(*) FROM set_log l WHERE l.session_id = s.id) AS sets_count
             FROM workout_session s JOIN workout_template t ON t.id = s.template_id
             WHERE s.id = ? AND s.user_id = ?"
        );
        $stmt->execute([$id, $this->userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $out = self::sessionOut($row);

        $stmt = $this->db->prepare(
            'SELECT l.template_item_id, l.round, l.reps_done, l.seconds_done, l.load_kg,
                    e.name_fr, i.per_side, b.position AS bpos, i.position AS ipos
             FROM set_log l
             JOIN template_item i ON i.id = l.template_item_id
             JOIN template_block b ON b.id = i.block_id
             JOIN exercise e ON e.id = i.exercise_id
             WHERE l.session_id = ? ORDER BY b.position, i.position, l.round, l.logged_at'
        );
        $stmt->execute([$id]);
        $byItem = [];
        foreach ($stmt->fetchAll() as $r) {
            $key = (int) $r['template_item_id'];
            $byItem[$key] ??= ['exercise' => $r['name_fr'], 'per_side' => (bool) $r['per_side'], 'sets' => []];
            $byItem[$key]['sets'][] = [
                'round' => (int) $r['round'],
                'reps' => $r['reps_done'] === null ? null : (int) $r['reps_done'],
                'seconds' => $r['seconds_done'] === null ? null : (int) $r['seconds_done'],
                'load_kg' => $r['load_kg'] === null ? null : (float) $r['load_kg'],
            ];
        }
        $out['exercises'] = array_values($byItem);

        return $out;
    }

    // --- Événements ----------------------------------------------------------------

    /** @param array<string, mixed> $e */
    private function sessionStart(array $e): void
    {
        $stmt = $this->db->prepare('SELECT id FROM workout_template WHERE code = ?');
        $stmt->execute([(string) ($e['template_code'] ?? '')]);
        $templateId = $stmt->fetchColumn();
        if ($templateId === false) {
            throw new HttpError(422, 'Séance inconnue');
        }
        $this->db->prepare(
            "INSERT INTO workout_session (client_uuid, user_id, template_id, planned_date, started_at, status)
             VALUES (?, ?, ?, ?, ?, 'in_progress') ON CONFLICT(client_uuid) DO NOTHING"
        )->execute([$e['client_uuid'], $this->userId, $templateId, self::day($e['planned_date'] ?? null), self::instant($e['started_at'] ?? null)]);

        $this->db->prepare('UPDATE settings SET program_start_date = ? WHERE user_id = ? AND program_start_date IS NULL')
            ->execute([self::day($e['planned_date'] ?? null), $this->userId]);
    }

    /** @param array<string, mixed> $e */
    private function set(array $e): void
    {
        $sessionId = $this->sessionId((string) ($e['session_uuid'] ?? ''));
        $item = $this->db->prepare('SELECT 1 FROM template_item WHERE id = ?');
        $item->execute([(int) ($e['template_item_id'] ?? 0)]);
        if (!$item->fetchColumn()) {
            throw new HttpError(422, 'Exercice du programme inconnu');
        }
        $this->db->prepare(
            'INSERT INTO set_log (client_uuid, session_id, template_item_id, round, reps_done, seconds_done, load_kg, logged_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(client_uuid) DO NOTHING'
        )->execute([
            $e['client_uuid'],
            $sessionId,
            (int) $e['template_item_id'],
            max(1, (int) ($e['round'] ?? 1)),
            self::optInt($e['reps_done'] ?? null, 0, 500),
            self::optInt($e['seconds_done'] ?? null, 0, 7200),
            self::optFloat($e['load_kg'] ?? null, 0, 300),
            self::instant($e['logged_at'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $e */
    private function sessionEnd(array $e): void
    {
        $sessionId = $this->sessionId((string) ($e['session_uuid'] ?? ''));
        $status = ($e['status'] ?? 'done') === 'skipped' ? 'skipped' : 'done';
        $pain = !empty($e['pain_flag']);
        $area = (string) ($e['pain_area'] ?? 'autre');
        if ($pain && !\in_array($area, self::PAIN_AREAS, true)) {
            throw new HttpError(422, 'Zone de douleur inconnue');
        }
        $effort = self::optInt($e['effort_1_5'] ?? null, 1, 5);
        $note = isset($e['note']) ? mb_substr(trim((string) $e['note']), 0, 2000) : null;

        $prev = $this->db->prepare('SELECT pain_flag FROM workout_session WHERE id = ?');
        $prev->execute([$sessionId]);
        $hadPain = (bool) $prev->fetchColumn();

        $this->db->prepare(
            'UPDATE workout_session SET ended_at = ?, status = ?, rounds_done = ?, effort_1_5 = ?, pain_flag = ?, note = ?
             WHERE id = ?'
        )->execute([
            self::instant($e['ended_at'] ?? null),
            $status,
            self::optInt($e['rounds_done'] ?? null, 0, 100),
            $effort,
            $pain ? 1 : 0,
            $note === '' ? null : $note,
            $sessionId,
        ]);

        if ($pain && !$hadPain) {
            $this->db->prepare(
                "INSERT INTO pain_report (user_id, reported_at, context, area, session_id) VALUES (?, ?, 'workout', ?, ?)"
            )->execute([$this->userId, self::instant($e['ended_at'] ?? null), $area, $sessionId]);
        }
    }

    // --- Utilitaires ---------------------------------------------------------------

    private function sessionId(string $uuid): int
    {
        $stmt = $this->db->prepare('SELECT id FROM workout_session WHERE client_uuid = ? AND user_id = ?');
        $stmt->execute([$uuid, $this->userId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new HttpError(422, 'Séance inconnue pour cette saisie');
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $r */
    private static function sessionOut(array $r): array
    {
        $duration = null;
        if ($r['started_at'] && $r['ended_at']) {
            $duration = (int) round((strtotime($r['ended_at']) - strtotime($r['started_at'])) / 60);
        }

        return [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'planned_date' => $r['planned_date'],
            'started_at' => $r['started_at'],
            'ended_at' => $r['ended_at'],
            'duration_min' => $duration,
            'status' => $r['status'],
            'rounds_done' => $r['rounds_done'] === null ? null : (int) $r['rounds_done'],
            'effort_1_5' => $r['effort_1_5'] === null ? null : (int) $r['effort_1_5'],
            'pain_flag' => (bool) $r['pain_flag'],
            'note' => $r['note'],
            'sets_count' => (int) $r['sets_count'],
            'joker' => (bool) $r['joker'],
        ];
    }

    private static function day(mixed $v): string
    {
        if (!\is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            throw new HttpError(422, 'Date invalide');
        }

        return $v;
    }

    private static function instant(mixed $v): string
    {
        try {
            $d = new \DateTimeImmutable(\is_string($v) ? $v : 'invalid');
        } catch (\Exception) {
            throw new HttpError(422, 'Horodatage invalide');
        }

        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function optInt(mixed $v, int $min, int $max): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v) || (int) $v < $min || (int) $v > $max) {
            throw new HttpError(422, "Valeur hors limites ($min à $max)");
        }

        return (int) $v;
    }

    private static function optFloat(mixed $v, float $min, float $max): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
            throw new HttpError(422, "Charge hors limites ($min à $max kg)");
        }

        return round((float) $v, 2);
    }
}
