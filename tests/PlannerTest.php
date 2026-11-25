<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth;
use App\Planner;
use DateTimeImmutable;

final class PlannerTest extends DatabaseTestCase
{
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris'); // lundi, mercredi, vendredi
    }

    private function done(string $code, string $date): void
    {
        $this->db->prepare(
            "INSERT INTO workout_session (client_uuid, user_id, template_id, planned_date, started_at, ended_at, status)
             VALUES (?, ?, (SELECT id FROM workout_template WHERE code = ?), ?, ?, ?, 'done')"
        )->execute([bin2hex(random_bytes(8)), $this->uid, $code, $date, $date . 'T17:00:00Z', $date . 'T17:30:00Z']);
    }

    private function at(string $local): array
    {
        return (new Planner($this->db, $this->uid))->today(new DateTimeImmutable($local, new \DateTimeZone('Europe/Paris')));
    }

    public function testFirstSessionIsAOnASessionDay(): void
    {
        $t = $this->at('2026-11-23 09:00'); // lundi
        self::assertTrue($t['proposed_today']);
        self::assertSame('A', $t['next_template']);
        self::assertSame(['done' => 0, 'planned' => 3, 'jokers' => 0], $t['week']);
    }

    public function testRotationFollowsLastDoneSession(): void
    {
        $this->done('A', '2026-11-23');
        self::assertSame('B', $this->at('2026-11-25 09:00')['next_template']);
        $this->done('B', '2026-11-25');
        self::assertSame('C', $this->at('2026-11-27 09:00')['next_template']);
        $this->done('C', '2026-11-27');
        self::assertSame('A', $this->at('2026-11-30 09:00')['next_template']);
    }

    public function testMissedSessionIsNotLostRotationResumes(): void
    {
        // Lundi A manqué : mercredi propose toujours A.
        $t = $this->at('2026-11-25 09:00');
        self::assertTrue($t['proposed_today']);
        self::assertSame('A', $t['next_template']);
    }

    public function testRestDayPointsToNextSessionDay(): void
    {
        $t = $this->at('2026-11-24 09:00'); // mardi
        self::assertFalse($t['proposed_today']);
        self::assertSame('2026-11-25', $t['next_session_date']);
    }

    public function testNeverTwoDaysInARow(): void
    {
        // Séance faite un mardi (hors planning) : le mercredi n'est plus proposé.
        $this->done('A', '2026-11-24');
        $t = $this->at('2026-11-25 09:00');
        self::assertFalse($t['proposed_today']);
        self::assertSame('seance_hier', $t['rest_reason']);
        self::assertSame('2026-11-27', $t['next_session_date']);
    }

    public function testDoneTodayAndWeekCount(): void
    {
        $this->done('A', '2026-11-23');
        $this->done('B', '2026-11-25');
        $t = $this->at('2026-11-25 20:00');
        self::assertTrue($t['done_today']);
        self::assertFalse($t['proposed_today']);
        self::assertSame(2, $t['week']['done']);
        self::assertSame('2026-11-27', $t['next_session_date']);
        // La semaine suivante repart de zéro.
        self::assertSame(0, $this->at('2026-11-30 08:00')['week']['done']);
    }

    public function testDayIsComputedInUserTimezone(): void
    {
        // 23 h 30 UTC un dimanche = lundi 0 h 30 à Paris.
        $t = (new Planner($this->db, $this->uid))->today(new DateTimeImmutable('2026-11-22 23:30', new \DateTimeZone('UTC')));
        self::assertSame('2026-11-23', $t['date']);
        self::assertTrue($t['is_session_day']);
    }

    public function testPostponeOnlyWhenItDoesNotCreateConsecutiveSessions(): void
    {
        $p = new Planner($this->db, $this->uid);
        self::assertFalse($p->canPostpone('2026-11-23'), 'lundi → mardi, veille du mercredi');
        self::assertFalse($p->canPostpone('2026-11-25'), 'mercredi → jeudi, veille du vendredi');
        self::assertTrue($p->canPostpone('2026-11-27'), 'vendredi → samedi');
    }
}
