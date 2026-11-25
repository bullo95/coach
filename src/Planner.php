<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Planification des séances (cahier des charges, F4 « Planification des séances ») :
 * - rotation A → B → C quel que soit le jour ; une séance manquée passe au prochain jour prévu ;
 * - jamais deux séances deux jours de suite ;
 * - compteur de la semaine (lundi → dimanche, fuseau de l'utilisateur).
 * Les jours sont des dates locales AAAA-MM-JJ (workout_session.planned_date).
 */
final class Planner
{
    /** @var list<int> jours ISO (1 = lundi … 7 = dimanche) */
    private array $sessionDays;
    private DateTimeZone $tz;

    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
        $stmt = $db->prepare('SELECT u.timezone, s.session_days FROM user u JOIN settings s ON s.user_id = u.id WHERE u.id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('Utilisateur inconnu.');
        }
        $this->tz = new DateTimeZone($row['timezone']);
        $this->sessionDays = array_map('intval', json_decode($row['session_days'], true) ?: []);
    }

    /** @return array<string, mixed> état du jour pour l'accueil */
    public function today(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz);
        $today = $now->setTime(0, 0);
        $todayStr = $today->format('Y-m-d');
        $yesterdayStr = $today->modify('-1 day')->format('Y-m-d');

        $doneToday = $this->doneOn($todayStr);
        $doneYesterday = $this->doneOn($yesterdayStr);
        $isSessionDay = $this->isSessionDay($today);
        $skippedToday = $this->jokerSessionOn($todayStr);
        $proposedToday = $isSessionDay && !$doneToday && !$doneYesterday && !$skippedToday;
        $jokersLeft = Jokers::left($this->db, $this->userId, substr($todayStr, 0, 7));

        $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
        $sunday = $monday->modify('+6 days');
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT planned_date) FROM workout_session WHERE user_id = ? AND status = 'done' AND planned_date BETWEEN ? AND ?"
        );
        $stmt->execute([$this->userId, $monday->format('Y-m-d'), $sunday->format('Y-m-d')]);

        return [
            'date' => $todayStr,
            'is_session_day' => $isSessionDay,
            'done_today' => $doneToday,
            'proposed_today' => $proposedToday,
            'skipped_today' => $skippedToday,
            'can_joker' => $proposedToday && $jokersLeft > 0,
            'jokers_left' => $jokersLeft,
            'rest_reason' => $isSessionDay && !$proposedToday && !$doneToday && !$skippedToday ? 'seance_hier' : null,
            'next_template' => $this->nextTemplateCode(),
            'next_session_date' => $proposedToday ? $todayStr : $this->nextSessionDate($today, $doneToday),
            'week' => ['done' => (int) $stmt->fetchColumn(), 'planned' => \count($this->sessionDays), 'jokers' => $this->weekJokers($monday, $sunday)],
        ];
    }

    /** Séance suivante dans la rotation, d'après la dernière séance réalisée. */
    public function nextTemplateCode(): ?string
    {
        $codes = $this->db->query('SELECT code FROM workout_template ORDER BY code')->fetchAll(PDO::FETCH_COLUMN);
        if (!$codes) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT t.code FROM workout_session s JOIN workout_template t ON t.id = s.template_id
             WHERE s.user_id = ? AND s.status = 'done'
             ORDER BY s.planned_date DESC, COALESCE(s.ended_at, s.started_at) DESC, s.id DESC LIMIT 1"
        );
        $stmt->execute([$this->userId]);
        $last = $stmt->fetchColumn();
        if ($last === false) {
            return $codes[0];
        }
        $i = array_search($last, $codes, true);

        return $codes[$i === false ? 0 : ($i + 1) % \count($codes)];
    }

    /**
     * Report d'une séance manquée au lendemain : permis seulement si le lendemain du report
     * n'est pas un jour de séance (sinon deux séances d'affilée).
     */
    public function canPostpone(string $missedDate): bool
    {
        $missed = new DateTimeImmutable($missedDate, $this->tz);

        return !$this->isSessionDay($missed->modify('+2 days'));
    }

    private function nextSessionDate(DateTimeImmutable $today, bool $doneToday): ?string
    {
        if (!$this->sessionDays) {
            return null;
        }
        for ($i = 1; $i <= 14; ++$i) {
            $d = $today->modify("+$i days");
            if ($i === 1 && $doneToday) {
                continue; // pas deux jours de suite
            }
            if ($this->isSessionDay($d)) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    private function isSessionDay(DateTimeImmutable $day): bool
    {
        return \in_array((int) $day->format('N'), $this->sessionDays, true);
    }

    /**
     * Saute la séance du jour avec un joker du mois (quota commun avec les pompes).
     * La séance sautée reste la prochaine dans la rotation.
     */
    public function skipToday(?DateTimeImmutable $now = null): void
    {
        $t = $this->today($now);
        if (!$t['proposed_today']) {
            throw new \App\Http\HttpError(409, 'Pas de séance à sauter aujourd\'hui');
        }
        if ($t['jokers_left'] < 1) {
            throw new \App\Http\HttpError(409, 'Plus de joker ce mois-ci');
        }
        $h = bin2hex(random_bytes(16));
        $uuid = sprintf('%s-%s-4%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
        $this->db->prepare(
            "INSERT INTO workout_session (client_uuid, user_id, template_id, planned_date, status, joker)
             VALUES (?, ?, (SELECT id FROM workout_template WHERE code = ?), ?, 'skipped', 1)"
        )->execute([$uuid, $this->userId, $t['next_template'], $t['date']]);
    }

    /** Annule le joker posé aujourd'hui sur la séance : elle est de nouveau proposée et le joker est rendu. */
    public function cancelSkipToday(?DateTimeImmutable $now = null): void
    {
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz)->format('Y-m-d');
        $stmt = $this->db->prepare('DELETE FROM workout_session WHERE user_id = ? AND joker = 1 AND planned_date = ?');
        $stmt->execute([$this->userId, $today]);
        if ($stmt->rowCount() === 0) {
            throw new \App\Http\HttpError(409, 'Aucun joker à annuler aujourd\'hui');
        }
    }

    private function jokerSessionOn(string $date): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM workout_session WHERE user_id = ? AND joker = 1 AND planned_date = ? LIMIT 1');
        $stmt->execute([$this->userId, $date]);

        return (bool) $stmt->fetchColumn();
    }

    private function weekJokers(DateTimeImmutable $monday, DateTimeImmutable $sunday): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM workout_session WHERE user_id = ? AND joker = 1 AND planned_date BETWEEN ? AND ?');
        $stmt->execute([$this->userId, $monday->format('Y-m-d'), $sunday->format('Y-m-d')]);

        return (int) $stmt->fetchColumn();
    }

    private function doneOn(string $date): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM workout_session WHERE user_id = ? AND status = 'done' AND planned_date = ? LIMIT 1");
        $stmt->execute([$this->userId, $date]);

        return (bool) $stmt->fetchColumn();
    }
}
