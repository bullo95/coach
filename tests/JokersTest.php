<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth;
use App\Http\HttpError;
use App\Planner;
use App\Pushups;
use App\Workouts;
use DateTimeImmutable;

/** Quota de jokers commun aux pompes et aux séances. */
final class JokersTest extends DatabaseTestCase
{
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris'); // séances lun/mer/ven
    }

    private function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new \DateTimeZone('Europe/Paris'));
    }

    public function testSkippingTodaysSessionUsesAJoker(): void
    {
        $p = new Planner($this->db, $this->uid);
        $now = $this->at('2026-11-23 09:00'); // lundi, séance A proposée
        self::assertTrue($p->today($now)['can_joker']);

        $p->skipToday($now);
        $t = $p->today($now);
        self::assertTrue($t['skipped_today']);
        self::assertFalse($t['proposed_today']);
        self::assertFalse($t['can_joker']);
        self::assertSame(2, $t['jokers_left']);
        self::assertSame(1, $t['week']['jokers']);
        self::assertSame(0, $t['week']['done']);
        self::assertSame('A', $t['next_template'], 'la séance sautée reste la prochaine');
        self::assertSame('2026-11-25', $t['next_session_date']);

        // Le quota est commun : les pompes voient 2 jokers restants.
        self::assertSame(2, (new Pushups($this->db, $this->uid))->state($now)['jokers_left']);
        // La séance sautée apparaît dans l'historique.
        $history = (new Workouts($this->db, $this->uid))->history();
        self::assertTrue($history[0]['joker']);
    }

    public function testCancelGivesTheJokerBack(): void
    {
        $p = new Planner($this->db, $this->uid);
        $now = $this->at('2026-11-23 09:00');
        $p->skipToday($now);
        $p->cancelSkipToday($now);
        $t = $p->today($now);
        self::assertTrue($t['proposed_today']);
        self::assertSame(3, $t['jokers_left']);
    }

    public function testCannotSkipOnARestDay(): void
    {
        $this->expectException(HttpError::class);
        (new Planner($this->db, $this->uid))->skipToday($this->at('2026-11-24 09:00')); // mardi
    }

    public function testSharedQuotaIsExhaustedAcrossPushupsAndSessions(): void
    {
        $planner = new Planner($this->db, $this->uid);
        $pushups = new Pushups($this->db, $this->uid);

        $planner->skipToday($this->at('2026-11-23 09:00'));             // joker 1 : séance du lundi
        $pushups->state($this->at('2026-11-23 09:00'));
        $pushups->useJoker('today', $this->at('2026-11-23 09:01'));     // joker 2 : pompes du lundi
        $planner->skipToday($this->at('2026-11-25 09:00'));             // joker 3 : séance du mercredi

        $t = $planner->today($this->at('2026-11-27 09:00'));            // vendredi
        self::assertSame(0, $t['jokers_left']);
        self::assertFalse($t['can_joker']);
        self::assertFalse($pushups->state($this->at('2026-11-27 09:00'))['can_joker_today']);

        $this->expectException(HttpError::class);
        $planner->skipToday($this->at('2026-11-27 09:00'));
    }

    public function testSessionJokersLeaveNoneForAutomaticPushupJokers(): void
    {
        $this->db->exec('UPDATE settings SET pushups_jokers_per_month = 1');
        (new Pushups($this->db, $this->uid))->state($this->at('2026-11-23 08:00')); // la routine démarre lundi
        (new Planner($this->db, $this->uid))->skipToday($this->at('2026-11-23 09:00'));

        $s = (new Pushups($this->db, $this->uid))->state($this->at('2026-11-24 08:00'));
        self::assertSame('missed', $s['yesterday']['status'], 'plus de joker pour couvrir les pompes de lundi');
    }
}
