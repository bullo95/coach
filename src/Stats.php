<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Séries de données des graphes de suivi : séances par semaine, pompes par jour.
 */
final class Stats
{
    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
    }

    /** @return array<string, mixed> */
    public function overview(?DateTimeImmutable $now = null, int $weeks = 8, int $days = 14): array
    {
        $stmt = $this->db->prepare('SELECT u.timezone, s.session_days FROM user u JOIN settings s ON s.user_id = u.id WHERE u.id = ?');
        $stmt->execute([$this->userId]);
        $row = $stmt->fetch();
        $tz = new DateTimeZone($row['timezone']);
        $planned = \count(json_decode($row['session_days'], true) ?: []);
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0);
        $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');

        // Séances : jours avec séance faite, par semaine (lundi → dimanche).
        $q = $this->db->prepare(
            "SELECT COUNT(DISTINCT CASE WHEN status = 'done' THEN planned_date END) AS done, COALESCE(SUM(joker), 0) AS jokers
             FROM workout_session WHERE user_id = ? AND planned_date BETWEEN ? AND ?"
        );
        $weekRows = [];
        for ($i = $weeks - 1; $i >= 0; --$i) {
            $start = $monday->modify("-$i weeks");
            $q->execute([$this->userId, $start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')]);
            $r = $q->fetch();
            $weekRows[] = ['start' => $start->format('Y-m-d'), 'done' => (int) $r['done'], 'jokers' => (int) $r['jokers'], 'planned' => $planned];
        }

        // Pompes : total par jour (0 pour un jour sans ligne).
        $from = $today->modify('-' . ($days - 1) . ' days');
        $q = $this->db->prepare('SELECT day, done, target, status FROM pushup_day WHERE user_id = ? AND day >= ? ORDER BY day');
        $q->execute([$this->userId, $from->format('Y-m-d')]);
        $byDay = array_column($q->fetchAll(), null, 'day');
        $dayRows = [];
        for ($d = $from; $d <= $today; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $dayRows[] = [
                'day' => $k,
                'done' => (int) ($byDay[$k]['done'] ?? 0),
                'target' => isset($byDay[$k]) ? (int) $byDay[$k]['target'] : null,
                'status' => $byDay[$k]['status'] ?? null,
            ];
        }

        $totals = $this->db->prepare("SELECT COUNT(DISTINCT planned_date) FROM workout_session WHERE user_id = ? AND status = 'done'");
        $totals->execute([$this->userId]);
        $push = $this->db->prepare('SELECT COALESCE(SUM(count), 0) FROM pushup_log WHERE user_id = ?');
        $push->execute([$this->userId]);

        return [
            'weeks' => $weekRows,
            'pushups' => $dayRows,
            'totals' => ['sessions' => (int) $totals->fetchColumn(), 'pushups' => (int) $push->fetchColumn()],
        ];
    }
}
