<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpError;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Pompes quotidiennes (cahier des charges, F3) : 10 séries de X par jour, série de jours, jokers.
 *
 * Une ligne pushup_day par jour fige l'objectif du jour. Les journées passées sont clôturées
 * au premier accès suivant (pas besoin d'attendre le cron) : atteinte, joker automatique ou manquée.
 */
final class Pushups
{
    public const MILESTONES = [7, 14, 30, 60, 100, 200, 365];

    /** @var array<string, mixed> */
    private array $settings;
    private DateTimeZone $tz;

    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
        $stmt = $db->prepare('SELECT u.timezone, s.* FROM user u JOIN settings s ON s.user_id = u.id WHERE u.id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('Utilisateur inconnu.');
        }
        $this->settings = $row;
        $this->tz = new DateTimeZone($row['timezone']);
    }

    /** @return array<string, mixed> état du jour */
    public function state(?DateTimeImmutable $now = null): array
    {
        $today = $this->localDay($now);
        $this->closePastDays($today);
        $day = $this->ensureDay($today);
        $yesterday = $this->dayRow($this->shift($today, -1));

        $x = (int) $day['reps_per_set'];
        $done = (int) $day['done'];
        $setsTarget = (int) $day['sets_target'];
        $setsDone = min($setsTarget, intdiv($done, max(1, $x)));
        [$streak, $record] = $this->streaks($today);
        $jokersLeft = $this->jokersLeft(substr($today, 0, 7));

        $logs = $this->db->prepare(
            'SELECT id, count, logged_at FROM pushup_log WHERE user_id = ? AND logged_at >= ? AND logged_at < ? ORDER BY logged_at DESC, id DESC'
        );
        [$from, $to] = $this->utcBounds($today);
        $logs->execute([$this->userId, $from, $to]);

        $month = $this->db->prepare(
            "SELECT SUM(status = 'reached') AS reached, SUM(status = 'joker') AS jokers, COUNT(*) AS days
             FROM pushup_day WHERE user_id = ? AND day LIKE ? AND day <= ?"
        );
        $month->execute([$this->userId, substr($today, 0, 7) . '-%', $today]);
        $m = $month->fetch();

        return [
            'date' => $today,
            'reps_per_set' => $x,
            'sets_target' => $setsTarget,
            'sets_done' => $setsDone,
            'target' => (int) $day['target'],
            'done' => $done,
            'remaining' => max(0, (int) $day['target'] - $done),
            'status' => $day['status'],
            'light_day' => $setsTarget < (int) $this->settings['pushups_sets'],
            'streak' => $streak,
            'record_streak' => $record,
            'jokers_left' => $jokersLeft,
            'jokers_per_month' => (int) $this->settings['pushups_jokers_per_month'],
            'can_joker_today' => $day['status'] === 'in_progress' && $jokersLeft > 0,
            'can_joker_yesterday' => $yesterday !== null && $yesterday['status'] === 'missed'
                && $this->jokersLeft(substr($yesterday['day'], 0, 7)) > 0,
            'yesterday' => $yesterday ? ['day' => $yesterday['day'], 'status' => $yesterday['status'], 'joker_mode' => $yesterday['joker_mode']] : null,
            'month' => ['reached' => (int) $m['reached'], 'jokers' => (int) $m['jokers'], 'days' => (int) $m['days']],
            'goal' => $this->settings['pushups_goal'],
            'plan' => [
                'reps_per_set' => (int) $this->settings['pushups_reps_per_set'],
                'sets' => (int) $this->settings['pushups_sets'],
                'light_day_enabled' => (bool) $this->settings['pushups_light_day_enabled'],
                'light_day_sets' => (int) $this->settings['pushups_light_day_sets'],
                'defined' => $this->lastChange() !== null,
            ],
            'feedback' => $this->feedbackOn($today),
            'feedback_needed' => $day['status'] === 'reached' && $this->feedbackOn($today) === null,
            'coach' => $this->advice($today),
            'logs' => array_map(static fn (array $l) => ['id' => (int) $l['id'], 'count' => (int) $l['count'], 'logged_at' => $l['logged_at']], $logs->fetchAll()),
        ];
    }

    /**
     * Enregistre une série (idempotent par client_uuid). Appelé par la synchronisation.
     *
     * @param array<string, mixed> $event
     */
    public function log(array $event): void
    {
        $count = $event['count'] ?? null;
        if (!is_numeric($count) || (int) $count < 1 || (int) $count > 1000) {
            throw new HttpError(422, 'Nombre de pompes invalide (1 à 1000)');
        }
        try {
            $at = (new DateTimeImmutable((string) ($event['logged_at'] ?? 'invalid')))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new HttpError(422, 'Horodatage invalide');
        }
        $stmt = $this->db->prepare(
            'INSERT INTO pushup_log (client_uuid, user_id, logged_at, count) VALUES (?, ?, ?, ?) ON CONFLICT(client_uuid) DO NOTHING'
        );
        $stmt->execute([(string) $event['client_uuid'], $this->userId, $at->format('Y-m-d\TH:i:s\Z'), (int) $count]);
        if ($stmt->rowCount() === 0) {
            return; // déjà reçue
        }
        $this->recount($at->setTimezone($this->tz)->format('Y-m-d'));
    }

    /** Annule la dernière série du jour. */
    public function undoLast(?DateTimeImmutable $now = null): void
    {
        $today = $this->localDay($now);
        [$from, $to] = $this->utcBounds($today);
        $stmt = $this->db->prepare(
            'SELECT id FROM pushup_log WHERE user_id = ? AND logged_at >= ? AND logged_at < ? ORDER BY logged_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$this->userId, $from, $to]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new HttpError(409, 'Aucune série à annuler aujourd\'hui');
        }
        $this->db->prepare('DELETE FROM pushup_log WHERE id = ?')->execute([$id]);
        $this->recount($today);
    }

    /** Pose un joker à la main : aujourd'hui (journée en cours) ou hier (journée manquée). */
    public function useJoker(string $which, ?DateTimeImmutable $now = null): void
    {
        $today = $this->localDay($now);
        $this->closePastDays($today);
        $this->ensureDay($today);
        $day = $which === 'yesterday' ? $this->shift($today, -1) : $today;
        $row = $this->dayRow($day);
        $expected = $which === 'yesterday' ? 'missed' : 'in_progress';
        if ($row === null || $row['status'] !== $expected) {
            throw new HttpError(409, $which === 'yesterday' ? 'La journée d\'hier n\'est pas à rattraper' : 'La journée est déjà validée');
        }
        if ($this->jokersLeft(substr($day, 0, 7)) < 1) {
            throw new HttpError(409, 'Plus de joker ce mois-ci');
        }
        $this->db->prepare("UPDATE pushup_day SET status = 'joker', joker_mode = 'manual', closed_at = COALESCE(closed_at, ?) WHERE id = ?")
            ->execute([$which === 'yesterday' ? Db::now() : null, $row['id']]);
    }

    /** @return list<array<string, mixed>> jours du plus récent au plus ancien */
    public function history(int $days = 35, ?DateTimeImmutable $now = null): array
    {
        $today = $this->localDay($now);
        $this->closePastDays($today);
        $this->ensureDay($today);
        $stmt = $this->db->prepare('SELECT day, target, done, status, joker_mode FROM pushup_day WHERE user_id = ? AND day >= ? ORDER BY day DESC');
        $stmt->execute([$this->userId, $this->shift($today, -($days - 1))]);

        return array_map(static fn (array $r) => [
            'day' => $r['day'], 'target' => (int) $r['target'], 'done' => (int) $r['done'],
            'status' => $r['status'], 'joker_mode' => $r['joker_mode'],
        ], $stmt->fetchAll());
    }

    // --- Règles ------------------------------------------------------------------

    /** Clôture toutes les journées antérieures à aujourd'hui, en créant celles sans aucune série. */
    private function closePastDays(string $today): void
    {
        $first = $this->db->prepare('SELECT MIN(day) FROM pushup_day WHERE user_id = ?');
        $first->execute([$this->userId]);
        $start = $first->fetchColumn();
        if (!$start) {
            return; // routine pas encore commencée
        }
        for ($d = $start; $d < $today; $d = $this->shift($d, 1)) {
            $row = $this->ensureDay($d);
            if ($row['status'] !== 'in_progress') {
                continue;
            }
            if ((int) $row['done'] >= (int) $row['target']) {
                $status = 'reached';
                $mode = null;
            } elseif ((int) $this->settings['pushups_joker_auto'] === 1 && $this->jokersLeft(substr($d, 0, 7)) > 0) {
                $status = 'joker';
                $mode = 'auto';
            } else {
                $status = 'missed';
                $mode = null;
            }
            $this->db->prepare('UPDATE pushup_day SET status = ?, joker_mode = ?, closed_at = ? WHERE id = ?')
                ->execute([$status, $mode, Db::now(), $row['id']]);
        }
    }

    /** Recalcule le total d'une journée d'après les séries, et met à jour son statut. */
    private function recount(string $day): void
    {
        $row = $this->ensureDay($day);
        [$from, $to] = $this->utcBounds($day);
        $sum = $this->db->prepare('SELECT COALESCE(SUM(count), 0) FROM pushup_log WHERE user_id = ? AND logged_at >= ? AND logged_at < ?');
        $sum->execute([$this->userId, $from, $to]);
        $done = (int) $sum->fetchColumn();

        $status = $row['status'];
        $mode = $row['joker_mode'];
        if ($done >= (int) $row['target']) {
            $status = 'reached'; // un joker posé ce jour-là est rendu
            $mode = null;
        } elseif ($status === 'reached') {
            $status = $row['closed_at'] === null ? 'in_progress' : 'missed'; // série annulée
        }
        $this->db->prepare('UPDATE pushup_day SET done = ?, status = ?, joker_mode = ? WHERE id = ?')
            ->execute([$done, $status, $mode, $row['id']]);
    }

    /** @return array<string, mixed> ligne du jour, créée avec l'objectif en vigueur si besoin */
    private function ensureDay(string $day): array
    {
        $row = $this->dayRow($day);
        if ($row !== null) {
            return $row;
        }
        [$x, $sets] = $this->targetFor($day);
        $this->db->prepare(
            'INSERT INTO pushup_day (user_id, day, reps_per_set, sets_target, target) VALUES (?, ?, ?, ?, ?) ON CONFLICT(user_id, day) DO NOTHING'
        )->execute([$this->userId, $day, $x, $sets, $sets * $x]);

        return $this->dayRow($day) ?? throw new \RuntimeException('Journée introuvable.');
    }

    /** @return array{int, int} pompes par série et nombre de séries pour ce jour, d'après le programme en vigueur */
    private function targetFor(string $day): array
    {
        $x = (int) $this->settings['pushups_reps_per_set'];
        $sessionDays = array_map('intval', json_decode((string) $this->settings['session_days'], true) ?: []);
        $isSessionDay = \in_array((int) (new DateTimeImmutable($day, $this->tz))->format('N'), $sessionDays, true);
        $sets = (int) $this->settings['pushups_light_day_enabled'] === 1 && $isSessionDay
            ? min((int) $this->settings['pushups_light_day_sets'], (int) $this->settings['pushups_sets'])
            : (int) $this->settings['pushups_sets'];

        return [$x, $sets];
    }

    // --- Coach ---------------------------------------------------------------------

    /**
     * Règle le programme : pompes par série, séries, objectif. S'applique dès aujourd'hui si aucune série
     * n'est encore faite, sinon à partir de demain (la journée en cours garde son objectif).
     *
     * @param array<string, mixed> $plan
     */
    public function setPlan(array $plan, string $kind = 'manual', ?DateTimeImmutable $now = null): void
    {
        $reps = $plan['reps_per_set'] ?? null;
        $sets = $plan['sets'] ?? null;
        if (!is_numeric($reps) || (int) $reps < 1 || (int) $reps > 200) {
            throw new HttpError(422, 'Pompes par série : entre 1 et 200');
        }
        if (!is_numeric($sets) || (int) $sets < 1 || (int) $sets > 30) {
            throw new HttpError(422, 'Séries par jour : entre 1 et 30');
        }
        $reps = (int) $reps;
        $sets = (int) $sets;
        $goal = (string) ($plan['goal'] ?? $this->settings['pushups_goal']);
        if (!\in_array($goal, PushupCoach::GOALS, true)) {
            throw new HttpError(422, 'Objectif inconnu');
        }
        $lightEnabled = \array_key_exists('light_day_enabled', $plan)
            ? (!empty($plan['light_day_enabled']) ? 1 : 0)
            : (int) $this->settings['pushups_light_day_enabled'];
        $light = isset($plan['light_day_sets']) && is_numeric($plan['light_day_sets'])
            ? (int) $plan['light_day_sets']
            : (int) max(1, round($sets / 2));
        $light = max(1, min($light, $sets));

        $today = $this->localDay($now);
        $changed = $reps !== (int) $this->settings['pushups_reps_per_set'] || $sets !== (int) $this->settings['pushups_sets'];
        $this->db->prepare(
            'UPDATE settings SET pushups_reps_per_set = ?, pushups_sets = ?, pushups_light_day_enabled = ?,
                pushups_light_day_sets = ?, pushups_goal = ? WHERE user_id = ?'
        )->execute([$reps, $sets, $lightEnabled, $light, $goal, $this->userId]);
        $this->settings = array_merge($this->settings, [
            'pushups_reps_per_set' => $reps, 'pushups_sets' => $sets, 'pushups_light_day_enabled' => $lightEnabled,
            'pushups_light_day_sets' => $light, 'pushups_goal' => $goal,
        ]);
        if ($changed || $this->lastChange() === null) {
            $this->db->prepare(
                'INSERT INTO pushup_plan_change (user_id, changed_at, day, reps_per_set, sets, kind) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$this->userId, Db::now(), $today, $reps, $sets, $kind]);
        }

        // Journée en cours pas encore entamée : elle prend le nouveau programme.
        $row = $this->dayRow($today);
        if ($row !== null && (int) $row['done'] === 0 && $row['status'] === 'in_progress') {
            [$x, $n] = $this->targetFor($today);
            $this->db->prepare('UPDATE pushup_day SET reps_per_set = ?, sets_target = ?, target = ? WHERE id = ?')
                ->execute([$x, $n, $x * $n, $row['id']]);
        }
    }

    /** Ressenti de la journée bouclée : 1 facile, 2 correct, 3 difficile ; douleur éventuelle. */
    public function saveFeedback(mixed $effort, ?string $painArea, ?DateTimeImmutable $now = null): void
    {
        $today = $this->localDay($now);
        $row = $this->dayRow($today);
        if ($row === null || $row['status'] !== 'reached') {
            throw new HttpError(409, 'Le ressenti se donne une fois les séries du jour terminées');
        }
        if (!\in_array($effort, [1, 2, 3], true)) {
            throw new HttpError(422, 'Ressenti attendu : 1 (facile), 2 (correct) ou 3 (difficile)');
        }
        if ($painArea !== null && !\in_array($painArea, ['epaule', 'coude', 'poignet', 'dos', 'autre'], true)) {
            throw new HttpError(422, 'Zone de douleur inconnue');
        }
        $had = $this->feedbackOn($today);
        $this->db->prepare(
            'INSERT INTO pushup_feedback (user_id, day, effort, pain_area, created_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(user_id, day) DO UPDATE SET effort = excluded.effort, pain_area = excluded.pain_area'
        )->execute([$this->userId, $today, $effort, $painArea, Db::now()]);
        if ($painArea !== null && ($had === null || $had['pain_area'] === null)) {
            $this->db->prepare("INSERT INTO pain_report (user_id, reported_at, context, area) VALUES (?, ?, 'pushups', ?)")
                ->execute([$this->userId, Db::now(), $painArea === 'poignet' ? 'autre' : $painArea]);
        }
    }

    /** Applique le conseil du coach en cours. */
    public function acceptAdvice(?DateTimeImmutable $now = null): void
    {
        $advice = $this->advice($this->localDay($now));
        if ($advice['proposal'] === null) {
            throw new HttpError(409, 'Aucun changement proposé pour l\'instant');
        }
        $this->setPlan($advice['proposal'], (string) $advice['kind'], $now);
    }

    /** Refuse le conseil : le coach attend de nouveaux ressentis avant de reproposer. */
    public function declineAdvice(?DateTimeImmutable $now = null): void
    {
        $today = $this->localDay($now);
        if ($this->advice($today)['proposal'] === null) {
            throw new HttpError(409, 'Aucun changement proposé pour l\'instant');
        }
        $this->db->prepare(
            "INSERT INTO pushup_plan_change (user_id, changed_at, day, reps_per_set, sets, kind) VALUES (?, ?, ?, ?, ?, 'declined')"
        )->execute([$this->userId, Db::now(), $today, (int) $this->settings['pushups_reps_per_set'], (int) $this->settings['pushups_sets']]);
    }

    /** @return list<array<string, mixed>> historique des réglages, du plus récent au plus ancien */
    public function planHistory(): array
    {
        $stmt = $this->db->prepare(
            "SELECT day, reps_per_set, sets, kind FROM pushup_plan_change WHERE user_id = ? AND kind <> 'declined' ORDER BY id DESC LIMIT 30"
        );
        $stmt->execute([$this->userId]);

        return array_map(static fn (array $r) => [
            'day' => $r['day'], 'reps_per_set' => (int) $r['reps_per_set'], 'sets' => (int) $r['sets'], 'kind' => $r['kind'],
        ], $stmt->fetchAll());
    }

    /** @return array<string, mixed> conseil du coach d'après les ressentis depuis le dernier changement */
    private function advice(string $today): array
    {
        $last = $this->lastChange();
        // Un changement repart de zéro : seuls comptent les ressentis qui le suivent. Une décision sur un conseil
        // (accepté ou refusé) vient après le ressenti du jour, donc on compte à partir du lendemain ;
        // un réglage manuel vaut dès le jour même.
        $from = $last === null ? '' : ($last['kind'] === 'manual' ? $last['day'] : $this->shift($last['day'], 1));
        $stmt = $this->db->prepare(
            'SELECT effort, pain_area FROM pushup_feedback WHERE user_id = ? AND day >= ? ORDER BY day DESC LIMIT 10'
        );
        $stmt->execute([$this->userId, $from]);
        $feedback = array_map(static fn (array $r) => ['effort' => (int) $r['effort'], 'pain' => $r['pain_area']], $stmt->fetchAll());

        $missed = $this->db->prepare("SELECT COUNT(*) FROM pushup_day WHERE user_id = ? AND status = 'missed' AND day >= ? AND day > ?");
        $missed->execute([$this->userId, $this->shift($today, -7), $last['day'] ?? '']);

        $inc = $this->db->prepare(
            "SELECT kind FROM pushup_plan_change WHERE user_id = ? AND kind IN ('coach_reps', 'coach_sets') ORDER BY id DESC LIMIT 1"
        );
        $inc->execute([$this->userId]);
        $kind = $inc->fetchColumn();

        return PushupCoach::advise([
            'reps' => (int) $this->settings['pushups_reps_per_set'],
            'sets' => (int) $this->settings['pushups_sets'],
            'goal' => (string) $this->settings['pushups_goal'],
            'feedback' => $feedback,
            'missed7' => (int) $missed->fetchColumn(),
            'last_increase' => $kind === 'coach_reps' ? 'reps' : ($kind === 'coach_sets' ? 'sets' : null),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function lastChange(): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pushup_plan_change WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$this->userId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array{effort: int, pain_area: ?string}|null */
    private function feedbackOn(string $day): ?array
    {
        $stmt = $this->db->prepare('SELECT effort, pain_area FROM pushup_feedback WHERE user_id = ? AND day = ?');
        $stmt->execute([$this->userId, $day]);
        $r = $stmt->fetch();

        return $r ? ['effort' => (int) $r['effort'], 'pain_area' => $r['pain_area']] : null;
    }

    /** @return array<string, mixed>|null */
    private function dayRow(string $day): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pushup_day WHERE user_id = ? AND day = ?');
        $stmt->execute([$this->userId, $day]);

        return $stmt->fetch() ?: null;
    }

    private function jokersLeft(string $month): int
    {
        return Jokers::left($this->db, $this->userId, $month);
    }

    /**
     * Série de jours en cours (jours atteints ou couverts par un joker, sans trou) et record.
     * Une journée d'aujourd'hui encore en cours ne casse pas la série.
     *
     * @return array{int, int}
     */
    private function streaks(string $today): array
    {
        $stmt = $this->db->prepare('SELECT day, status FROM pushup_day WHERE user_id = ? ORDER BY day');
        $stmt->execute([$this->userId]);
        $run = 0;
        $record = 0;
        foreach ($stmt->fetchAll() as $r) {
            if ($r['status'] === 'reached' || $r['status'] === 'joker') {
                ++$run;
                $record = max($record, $run);
            } elseif ($r['day'] !== $today) {
                $run = 0;
            }
        }

        return [$run, $record];
    }

    private function localDay(?DateTimeImmutable $now): string
    {
        return ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz)->format('Y-m-d');
    }

    private function shift(string $day, int $days): string
    {
        return (new DateTimeImmutable($day, $this->tz))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /** @return array{string, string} bornes UTC [début, fin[ d'un jour local */
    private function utcBounds(string $day): array
    {
        $start = new DateTimeImmutable($day . ' 00:00:00', $this->tz);
        $utc = new DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
