<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpError;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Rappels par notification (cahier des charges, « Rappels »).
 * Le script cron appelle run() chaque minute : il calcule les créneaux du jour, garde ceux qui tombent maintenant,
 * dont la condition est remplie et qui n'ont pas déjà été envoyés, puis envoie une seule notification.
 *
 * Règles :
 * - rien pendant la plage silencieuse ; un créneau du matin pris dedans est décalé à la fin de la plage,
 *   un créneau du soir pris dedans est abandonné ;
 * - un créneau n'est envoyé que dans les GRACE_MIN minutes qui suivent son heure (pas de rattrapage) ;
 * - plusieurs rappels dus au même moment partent dans une seule notification.
 */
final class Reminders
{
    /** Types en service. « protein » et « waist » attendent leurs écrans de saisie. */
    public const TYPES = ['session', 'session_missed', 'pushups', 'weight'];
    public const GRACE_MIN = 20;
    public const MISSED_DELAY_MIN = 120;

    private DateTimeZone $tz;
    /** @var array<string, mixed> */
    private array $settings;

    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
        $stmt = $db->prepare('SELECT u.timezone, s.* FROM user u JOIN settings s ON s.user_id = u.id WHERE u.id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('Utilisateur inconnu.');
        }
        $this->tz = new DateTimeZone($row['timezone']);
        $this->settings = $row;
    }

    /** @return array<string, bool> type → activé (activé tant qu'il n'a pas été coupé) */
    public function enabled(): array
    {
        $stmt = $this->db->prepare('SELECT type, enabled FROM reminder WHERE user_id = ?');
        $stmt->execute([$this->userId]);
        $saved = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach (self::TYPES as $t) {
            $out[$t] = !isset($saved[$t]) || (bool) $saved[$t];
        }

        return $out;
    }

    /** @return array<string, mixed> réglages et horaires du jour, pour l'écran Réglages */
    public function state(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz);
        $today = [];
        foreach ($this->plan($now) as $p) {
            if ($p['at'] !== null && $p['active']) {
                $today[] = ['type' => $p['type'], 'time' => self::hhmm($p['at'])];
            }
        }
        usort($today, static fn (array $a, array $b) => $a['time'] <=> $b['time']);

        return [
            'types' => $this->enabled(),
            'session_time' => $this->settings['session_time'],
            'pushups_window_start' => $this->settings['pushups_window_start'],
            'pushups_window_end' => $this->settings['pushups_window_end'],
            'quiet_start' => $this->settings['quiet_start'],
            'quiet_end' => $this->settings['quiet_end'],
            'weigh_time' => $this->settings['weigh_time'],
            'today' => $today,
        ];
    }

    /** @param array<string, mixed> $data types (type → booléen) et heures « HH:MM » ; seules les clés présentes changent */
    public function update(array $data): void
    {
        $times = [];
        foreach (['session_time', 'pushups_window_start', 'pushups_window_end', 'quiet_start', 'quiet_end'] as $key) {
            if (isset($data[$key])) {
                if (!\is_string($data[$key]) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $data[$key])) {
                    throw new HttpError(400, 'Heure invalide');
                }
                $times[$key] = $data[$key];
            }
        }
        $start = $times['pushups_window_start'] ?? $this->settings['pushups_window_start'];
        $end = $times['pushups_window_end'] ?? $this->settings['pushups_window_end'];
        if ($start >= $end) {
            throw new HttpError(400, 'Pompes : l\'heure de fin doit être après l\'heure de début');
        }
        foreach ($times as $key => $value) {
            $this->db->prepare("UPDATE settings SET $key = ? WHERE user_id = ?")->execute([$value, $this->userId]);
            $this->settings[$key] = $value;
        }
        foreach ((array) ($data['types'] ?? []) as $type => $on) {
            if (!\in_array($type, self::TYPES, true)) {
                throw new HttpError(400, 'Type de rappel inconnu');
            }
            $this->db->prepare(
                'INSERT INTO reminder (user_id, type, enabled) VALUES (?, ?, ?)
                 ON CONFLICT(user_id, type) DO UPDATE SET enabled = excluded.enabled'
            )->execute([$this->userId, $type, $on ? 1 : 0]);
        }
    }

    /**
     * Rappels à envoyer maintenant.
     *
     * @return list<array{type: string, slot: string, title: string, body: string, url: string}>
     */
    public function due(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz);
        $minute = (int) $now->format('G') * 60 + (int) $now->format('i');
        if ($this->isQuiet($minute)) {
            return [];
        }
        $sent = $this->db->prepare('SELECT 1 FROM reminder_log WHERE user_id = ? AND slot = ?');
        $due = [];
        foreach ($this->plan($now) as $p) {
            if (!$p['active'] || $p['at'] === null || $minute < $p['at'] || $minute >= $p['at'] + self::GRACE_MIN) {
                continue;
            }
            $sent->execute([$this->userId, $p['slot']]);
            if ($sent->fetchColumn()) {
                continue;
            }
            unset($p['at'], $p['active']);
            $due[$p['type']] = $p; // un seul par type (pompes : le créneau le plus récent)
        }

        return array_values($due);
    }

    /**
     * Appelé chaque minute par bin/reminders.php. Clôt aussi les journées de pompes passées (jokers automatiques).
     *
     * @return array{due: int, sent: int, failed: int, removed: int, errors: list<string>}
     */
    public function run(PushSender|\Closure $sender, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $due = $this->due($now); // le calcul de l'état des pompes clôt les journées passées
        $out = ['due' => \count($due), 'sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => []];
        $push = new Push($this->db, $this->userId);
        if (!$due || $push->count() === 0) {
            return $out;
        }
        if ($sender instanceof \Closure) {
            $sender = $sender(); // créé seulement quand il y a quelque chose à envoyer
        }
        $out = ['due' => \count($due)] + $push->sendAll(self::message($due), $sender);
        if ($out['sent'] > 0) {
            $at = Db::now();
            foreach ($due as $d) {
                $this->db->prepare('INSERT OR IGNORE INTO reminder_log (user_id, slot, sent_at) VALUES (?, ?, ?)')
                    ->execute([$this->userId, $d['slot'], $at]);
                $this->db->prepare(
                    'INSERT INTO reminder (user_id, type, last_sent_at) VALUES (?, ?, ?)
                     ON CONFLICT(user_id, type) DO UPDATE SET last_sent_at = excluded.last_sent_at'
                )->execute([$this->userId, $d['type'], $at]);
            }
            $this->db->prepare('DELETE FROM reminder_log WHERE user_id = ? AND sent_at < ?')
                ->execute([$this->userId, $now->modify('-60 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')]);
        }

        return $out;
    }

    /**
     * Regroupe les rappels dus en une seule notification.
     *
     * @param non-empty-list<array{type: string, slot: string, title: string, body: string, url: string}> $due
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public static function message(array $due): array
    {
        if (\count($due) === 1) {
            return ['title' => $due[0]['title'], 'body' => $due[0]['body'], 'url' => $due[0]['url'], 'tag' => $due[0]['type']];
        }

        return [
            'title' => 'Coach : ' . \count($due) . ' rappels',
            'body' => implode("\n", array_map(static fn (array $d) => $d['title'] . ' — ' . $d['body'], $due)),
            'url' => '/#/',
            'tag' => 'coach',
        ];
    }

    /**
     * Tous les créneaux du jour. at : minute locale d'envoi après application de la plage silencieuse (null = abandonné).
     * active : le type est activé et sa condition d'envoi est remplie à cet instant.
     *
     * @return list<array{type: string, slot: string, at: ?int, active: bool, title: string, body: string, url: string}>
     */
    private function plan(DateTimeImmutable $now): array
    {
        $on = $this->enabled();
        $day = $now->format('Y-m-d');
        $plan = [];

        // Séance du jour, puis relance 2 h après si elle n'est toujours pas faite.
        $today = (new Planner($this->db, $this->userId))->today($now);
        $pending = $today['proposed_today'] && !$this->sessionStarted($day);
        $code = (string) $today['next_template'];
        $stmt = $this->db->prepare('SELECT name, est_duration_min FROM workout_template WHERE code = ?');
        $stmt->execute([$code]);
        $tpl = $stmt->fetch() ?: ['name' => '', 'est_duration_min' => 30];
        $sessionAt = self::minutes($this->settings['session_time']);
        $plan[] = [
            'type' => 'session', 'slot' => "session:$day", 'at' => $this->shift($sessionAt), 'active' => $on['session'] && $pending,
            'title' => "Séance $code aujourd'hui",
            'body' => trim($tpl['name'] . ' — ' . $tpl['est_duration_min'] . ' min. C\'est l\'heure.', ' —'),
            'url' => "/#/seances/$code",
        ];
        $missedAt = $sessionAt + self::MISSED_DELAY_MIN;
        $plan[] = [
            'type' => 'session_missed', 'slot' => "session_missed:$day", 'at' => $missedAt < 1440 ? $this->shift($missedAt) : null,
            'active' => $on['session_missed'] && $pending,
            'title' => "Séance $code pas encore faite",
            'body' => $tpl['est_duration_min'] . ' min suffisent. Sinon, elle passe au prochain jour prévu.',
            'url' => "/#/seances/$code",
        ];

        // Pesée : à l'heure choisie, le jour de pesée ou tant qu'elle est en retard.
        $weigh = (new Measures($this->db, $this->userId))->state($now)['weigh'];
        $plan[] = [
            'type' => 'weight', 'slot' => "weight:$day", 'at' => $this->shift(self::minutes($this->settings['weigh_time'])),
            'active' => $on['weight'] && $weigh['due'],
            'title' => 'Pesée de la semaine',
            'body' => 'Le matin, à jeun, sur la même balance. Note ton poids dans l\'app.',
            'url' => '/#/',
        ];

        // Pompes : un créneau par série, réparti sur la plage ; envoyé seulement si cette série n'est pas encore faite.
        $p = (new Pushups($this->db, $this->userId))->state($now);
        $n = (int) $p['sets_target'];
        $start = self::minutes($this->settings['pushups_window_start']);
        $end = self::minutes($this->settings['pushups_window_end']);
        $left = $n - (int) $p['sets_done'];
        for ($i = 0; $i < $n; ++$i) {
            $at = $n === 1 ? $start : $start + (int) round($i * ($end - $start) / ($n - 1));
            $last = $i === $n - 1;
            $plan[] = [
                'type' => 'pushups', 'slot' => "pushups:$day:$i", 'at' => $this->shift($at),
                'active' => $on['pushups'] && $p['status'] === 'in_progress' && (int) $p['sets_done'] <= $i,
                'title' => $last && $left > 1 ? 'Pompes : dernier rappel du jour' : 'Pompes : série ' . ((int) $p['sets_done'] + 1) . " sur $n",
                'body' => $last && $left > 1
                    ? "Encore $left séries ({$p['remaining']} pompes) pour boucler la journée."
                    : "{$p['reps_per_set']} pompes maintenant. Encore {$p['remaining']} aujourd'hui.",
                'url' => '/#/',
            ];
        }

        return $plan;
    }

    private function sessionStarted(string $day): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM workout_session WHERE user_id = ? AND planned_date = ? AND status = 'in_progress' LIMIT 1");
        $stmt->execute([$this->userId, $day]);

        return (bool) $stmt->fetchColumn();
    }

    /** Applique la plage silencieuse à un créneau : inchangé, décalé à la fin de la plage (matin) ou abandonné (soir). */
    private function shift(int $minute): ?int
    {
        if (!$this->isQuiet($minute)) {
            return $minute;
        }
        $qs = self::minutes($this->settings['quiet_start']);
        $qe = self::minutes($this->settings['quiet_end']);

        return $qs > $qe && $minute >= $qs ? null : $qe;
    }

    private function isQuiet(int $minute): bool
    {
        $qs = self::minutes($this->settings['quiet_start']);
        $qe = self::minutes($this->settings['quiet_end']);
        if ($qs === $qe) {
            return false;
        }

        return $qs < $qe ? ($minute >= $qs && $minute < $qe) : ($minute >= $qs || $minute < $qe);
    }

    private static function minutes(string $hhmm): int
    {
        return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
    }

    private static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
