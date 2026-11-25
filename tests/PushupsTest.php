<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth;
use App\Http\HttpError;
use App\Pushups;
use DateTimeImmutable;

final class PushupsTest extends DatabaseTestCase
{
    private int $uid;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris'); // séances lun/mer/ven
    }

    private function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new \DateTimeZone('Europe/Paris'));
    }

    private function log(string $local, int $count = 23, ?string $uuid = null): void
    {
        (new Pushups($this->db, $this->uid))->log([
            'client_uuid' => $uuid ?? sprintf('00000000-0000-4000-8000-%012d', ++$this->n),
            'count' => $count,
            'logged_at' => $this->at($local)->format(DATE_ATOM),
        ]);
    }

    private function state(string $local): array
    {
        return (new Pushups($this->db, $this->uid))->state($this->at($local));
    }

    public function testDailyTargetIsTenSetsOfX(): void
    {
        $s = $this->state('2026-11-24 09:00'); // mardi, pas de séance
        self::assertSame(230, $s['target']);
        self::assertSame(10, $s['sets_target']);
        self::assertSame(23, $s['reps_per_set']);
        self::assertFalse($s['light_day']);
        self::assertSame(3, $s['jokers_left']);
    }

    public function testSessionDayIsALightDay(): void
    {
        $s = $this->state('2026-11-25 09:00'); // mercredi
        self::assertTrue($s['light_day']);
        self::assertSame(5, $s['sets_target']);
        self::assertSame(115, $s['target']);
    }

    public function testProgressThroughTheDay(): void
    {
        $this->log('2026-11-24 08:00');
        $this->log('2026-11-24 10:00');
        $this->log('2026-11-24 12:00', 10); // série incomplète
        $s = $this->state('2026-11-24 12:05');
        self::assertSame(56, $s['done']);
        self::assertSame(2, $s['sets_done']);
        self::assertSame(174, $s['remaining']);
        self::assertSame('in_progress', $s['status']);
        self::assertCount(3, $s['logs']);
    }

    public function testReachingTheTargetAndUndo(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->log(sprintf('2026-11-24 %02d:00', 8 + $i));
        }
        $s = $this->state('2026-11-24 19:00');
        self::assertSame('reached', $s['status']);
        self::assertSame(1, $s['streak']);

        (new Pushups($this->db, $this->uid))->undoLast($this->at('2026-11-24 19:01'));
        $s = $this->state('2026-11-24 19:02');
        self::assertSame('in_progress', $s['status']);
        self::assertSame(207, $s['done']);
    }

    public function testSameUuidIsCountedOnce(): void
    {
        $this->log('2026-11-24 08:00', 23, '11111111-1111-4111-8111-111111111111');
        $this->log('2026-11-24 08:00', 23, '11111111-1111-4111-8111-111111111111');
        self::assertSame(23, $this->state('2026-11-24 09:00')['done']);
    }

    public function testMissedDayUsesAnAutomaticJokerAndKeepsTheStreak(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->log(sprintf('2026-11-24 %02d:00', 8 + $i)); // mardi atteint
        }
        $this->log('2026-11-25 08:00'); // mercredi : 1 série sur 5, journée ratée
        $s = $this->state('2026-11-26 08:00'); // jeudi

        self::assertSame('joker', $s['yesterday']['status']);
        self::assertSame('auto', $s['yesterday']['joker_mode']);
        self::assertSame(2, $s['jokers_left']);
        self::assertSame(2, $s['streak'], 'mardi atteint + mercredi couvert par un joker');
    }

    public function testFourthMissedDayOfTheMonthBreaksTheStreakAndJokersResetNextMonth(): void
    {
        $this->log('2026-11-10 08:00', 230); // jour 1 atteint
        // 11, 12, 13 : rien → 3 jokers ; 14 : rien → manqué.
        $s = $this->state('2026-11-15 08:00');
        self::assertSame(0, $s['jokers_left']);
        self::assertSame('missed', $s['yesterday']['status']);
        self::assertSame(0, $s['streak']);
        self::assertSame(4, $s['record_streak'], 'record : 1 jour atteint + 3 jokers');
        self::assertFalse($s['can_joker_yesterday']);

        self::assertSame(3, $this->state('2026-12-01 08:00')['jokers_left']);
    }

    public function testManualJokerForTodayIsGivenBackWhenTargetIsReached(): void
    {
        $p = new Pushups($this->db, $this->uid);
        $p->state($this->at('2026-11-24 08:00'));
        $p->useJoker('today', $this->at('2026-11-24 08:01'));
        $s = $this->state('2026-11-24 08:02');
        self::assertSame('joker', $s['status']);
        self::assertSame(2, $s['jokers_left']);
        self::assertSame(1, $s['streak']);

        $this->log('2026-11-24 09:00', 230);
        $s = $this->state('2026-11-24 09:01');
        self::assertSame('reached', $s['status']);
        self::assertSame(3, $s['jokers_left'], 'joker rendu');
    }

    public function testManualJokerForYesterdayWhenAutoIsOff(): void
    {
        $this->db->exec('UPDATE settings SET pushups_joker_auto = 0');
        $this->log('2026-11-23 08:00', 115); // lundi (allégé) atteint
        $this->log('2026-11-24 08:00');      // mardi raté
        $s = $this->state('2026-11-25 08:00');
        self::assertSame('missed', $s['yesterday']['status']);
        self::assertTrue($s['can_joker_yesterday']);
        self::assertSame(0, $s['streak']);

        (new Pushups($this->db, $this->uid))->useJoker('yesterday', $this->at('2026-11-25 08:01'));
        $s = $this->state('2026-11-25 08:02');
        self::assertSame('joker', $s['yesterday']['status']);
        self::assertSame(2, $s['streak']);
    }

    public function testNoJokerLeftIsRefused(): void
    {
        $this->db->exec('UPDATE settings SET pushups_jokers_per_month = 0');
        $p = new Pushups($this->db, $this->uid);
        $p->state($this->at('2026-11-24 08:00'));
        $this->expectException(HttpError::class);
        $p->useJoker('today', $this->at('2026-11-24 08:01'));
    }

    public function testChangingXDoesNotRewritePastDays(): void
    {
        $this->log('2026-11-24 08:00', 230);
        $this->db->exec('UPDATE settings SET pushups_reps_per_set = 25');
        $history = (new Pushups($this->db, $this->uid))->history(35, $this->at('2026-11-26 08:00'));
        $byDay = array_column($history, null, 'day');
        self::assertSame(230, $byDay['2026-11-24']['target']);
        self::assertSame(250, $byDay['2026-11-26']['target'], 'jeudi : 10 × 25');
    }

    public function testLateEveningIsCountedOnTheLocalDay(): void
    {
        $this->log('2026-11-24 23:30'); // 22 h 30 UTC
        self::assertSame(23, $this->state('2026-11-24 23:45')['done']);
        self::assertSame(0, $this->state('2026-11-25 00:10')['done']);
    }
}
