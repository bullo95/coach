<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth;
use App\Http\HttpError;
use App\PushupCoach;
use App\Pushups;
use DateTimeImmutable;

final class PushupCoachTest extends DatabaseTestCase
{
    private int $uid;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris');
        $this->db->exec('UPDATE settings SET pushups_light_day_enabled = 0'); // 10 séries tous les jours pour ces tests
    }

    /** @param list<array{int, ?string}> $feedback du plus récent au plus ancien */
    private function advise(string $goal, array $feedback, int $missed = 0, ?string $last = null, int $reps = 23, int $sets = 10): array
    {
        return PushupCoach::advise([
            'reps' => $reps, 'sets' => $sets, 'goal' => $goal,
            'feedback' => array_map(static fn (array $f) => ['effort' => $f[0], 'pain' => $f[1]], $feedback),
            'missed7' => $missed, 'last_increase' => $last,
        ]);
    }

    // --- Règles (fonction pure) ----------------------------------------------------

    public function testNeedsThreeDaysOfFeedbackBeforeAdvising(): void
    {
        $a = $this->advise('endurance', [[1, null], [1, null]]);
        self::assertSame('hold', $a['action']);
        self::assertNull($a['proposal']);
    }

    public function testThreeEasyDaysEnduranceGoalAddsRepsPerSet(): void
    {
        $a = $this->advise('endurance', [[1, null], [1, null], [1, null]]);
        self::assertSame('increase_reps', $a['action']);
        self::assertSame(['reps_per_set' => 24, 'sets' => 10], $a['proposal']);
    }

    public function testThreeEasyDaysVolumeGoalAddsASet(): void
    {
        $a = $this->advise('volume', [[1, null], [1, null], [1, null]]);
        self::assertSame('increase_sets', $a['action']);
        self::assertSame(['reps_per_set' => 23, 'sets' => 11], $a['proposal']);
    }

    public function testBalancedGoalAlternates(): void
    {
        $easy = [[1, null], [1, null], [1, null]];
        self::assertSame('increase_reps', $this->advise('equilibre', $easy)['action']);
        self::assertSame('increase_sets', $this->advise('equilibre', $easy, 0, 'reps')['action']);
        self::assertSame('increase_reps', $this->advise('equilibre', $easy, 0, 'sets')['action']);
    }

    public function testFiveSteadyDaysAlsoAllowAnIncrease(): void
    {
        $ok = [[2, null], [2, null], [1, null], [2, null], [2, null]];
        self::assertSame('increase_reps', $this->advise('endurance', $ok)['action']);
        self::assertSame('hold', $this->advise('endurance', \array_slice($ok, 0, 4))['action']);
    }

    public function testOneHardDayHoldsAndThreeHardDaysDecrease(): void
    {
        self::assertSame('hold', $this->advise('endurance', [[1, null], [3, null], [1, null]])['action']);
        $a = $this->advise('endurance', [[3, null], [3, null], [3, null]]);
        self::assertSame('decrease', $a['action']);
        self::assertSame(['reps_per_set' => 22, 'sets' => 10], $a['proposal']);
    }

    public function testPainBlocksAnyIncreaseEvenWhenEasy(): void
    {
        $a = $this->advise('volume', [[1, null], [1, 'epaule'], [1, null]]);
        self::assertSame('hold', $a['action']);
        self::assertNull($a['proposal']);
    }

    public function testRepeatedPainLowersRepsPerSet(): void
    {
        $a = $this->advise('endurance', [[1, 'coude'], [2, 'coude'], [1, null]]);
        self::assertSame('decrease', $a['action']);
        self::assertSame(['reps_per_set' => 20, 'sets' => 10], $a['proposal']); // −15 %
    }

    public function testMissedDaysLowerTheNumberOfSets(): void
    {
        $a = $this->advise('endurance', [[1, null], [1, null], [1, null]], 2);
        self::assertSame('decrease', $a['action']);
        self::assertSame(['reps_per_set' => 23, 'sets' => 9], $a['proposal']);
    }

    // --- Parcours complet ------------------------------------------------------------

    private function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new \DateTimeZone('Europe/Paris'));
    }

    private function doDay(Pushups $p, string $day, int $total, int $effort, ?string $pain = null): void
    {
        $p->log(['client_uuid' => sprintf('00000000-0000-4000-8000-%012d', ++$this->n), 'count' => $total, 'logged_at' => $this->at("$day 18:00")->format(DATE_ATOM)]);
        $p->saveFeedback($effort, $pain, $this->at("$day 18:05"));
    }

    public function testUserDefinesThePlanAndItAppliesToAnUntouchedDay(): void
    {
        $p = new Pushups($this->db, $this->uid);
        self::assertFalse($p->state($this->at('2026-12-01 08:00'))['plan']['defined']);

        $p->setPlan(['reps_per_set' => 15, 'sets' => 6, 'goal' => 'volume'], 'manual', $this->at('2026-12-01 08:01'));
        $s = $p->state($this->at('2026-12-01 08:02'));
        self::assertTrue($s['plan']['defined']);
        self::assertSame(90, $s['target'], '6 × 15, appliqué dès aujourd\'hui car aucune série faite');
        self::assertSame('volume', $s['goal']);
        self::assertSame(3, $s['plan']['light_day_sets'], 'moitié des séries les jours de séance');
    }

    public function testPlanChangedMidDayAppliesTomorrow(): void
    {
        $p = new Pushups($this->db, $this->uid);
        $p->log(['client_uuid' => '00000000-0000-4000-8000-00000000aaaa', 'count' => 23, 'logged_at' => $this->at('2026-12-01 09:00')->format(DATE_ATOM)]);
        $p->setPlan(['reps_per_set' => 25, 'sets' => 10], 'manual', $this->at('2026-12-01 10:00'));
        self::assertSame(230, $p->state($this->at('2026-12-01 10:01'))['target']);
        self::assertSame(250, (new Pushups($this->db, $this->uid))->state($this->at('2026-12-02 08:00'))['target']);
    }

    public function testFeedbackIsAskedOnlyWhenTheDayIsDone(): void
    {
        $p = new Pushups($this->db, $this->uid);
        $p->state($this->at('2026-12-01 08:00'));
        try {
            $p->saveFeedback(1, null, $this->at('2026-12-01 08:01'));
            self::fail('ressenti refusé tant que les séries ne sont pas finies');
        } catch (HttpError $e) {
            self::assertSame(409, $e->status);
        }
        $p->log(['client_uuid' => '00000000-0000-4000-8000-00000000bbbb', 'count' => 230, 'logged_at' => $this->at('2026-12-01 18:00')->format(DATE_ATOM)]);
        self::assertTrue($p->state($this->at('2026-12-01 18:01'))['feedback_needed']);
        $p->saveFeedback(2, 'poignet', $this->at('2026-12-01 18:02'));
        $s = $p->state($this->at('2026-12-01 18:03'));
        self::assertFalse($s['feedback_needed']);
        self::assertSame(['effort' => 2, 'pain_area' => 'poignet'], $s['feedback']);
        self::assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM pain_report WHERE context = 'pushups'")->fetchColumn());
    }

    public function testThreeEasyDaysThenAcceptRaisesThePlanAndResetsTheWindow(): void
    {
        $p = new Pushups($this->db, $this->uid);
        $p->setPlan(['reps_per_set' => 20, 'sets' => 10, 'goal' => 'endurance'], 'manual', $this->at('2026-12-01 07:00'));
        $this->doDay($p, '2026-12-01', 200, 1);
        $this->doDay($p, '2026-12-02', 200, 1);
        self::assertNull($p->state($this->at('2026-12-02 19:00'))['coach']['proposal']);
        $this->doDay($p, '2026-12-03', 200, 1);

        $coach = $p->state($this->at('2026-12-03 19:00'))['coach'];
        self::assertSame('increase_reps', $coach['action']);
        self::assertSame(['reps_per_set' => 21, 'sets' => 10], $coach['proposal']);

        $p->acceptAdvice($this->at('2026-12-03 19:01'));
        $s = (new Pushups($this->db, $this->uid))->state($this->at('2026-12-04 08:00'));
        self::assertSame(210, $s['target']);
        self::assertSame('hold', $s['coach']['action'], 'on repart pour 3 journées d\'observation');
        self::assertSame('coach_reps', $p->planHistory()[0]['kind']);
    }

    public function testDecliningWaitsForNewFeedback(): void
    {
        $p = new Pushups($this->db, $this->uid);
        $p->setPlan(['reps_per_set' => 20, 'sets' => 10, 'goal' => 'volume'], 'manual', $this->at('2026-12-01 07:00'));
        foreach (['2026-12-01', '2026-12-02', '2026-12-03'] as $d) {
            $this->doDay($p, $d, 200, 1);
        }
        self::assertSame('increase_sets', $p->state($this->at('2026-12-03 19:00'))['coach']['action']);
        $p->declineAdvice($this->at('2026-12-03 19:01'));
        $s = $p->state($this->at('2026-12-03 19:02'));
        self::assertNull($s['coach']['proposal']);
        self::assertSame(200, (new Pushups($this->db, $this->uid))->state($this->at('2026-12-04 08:00'))['target']);
    }

    public function testInvalidPlanIsRefused(): void
    {
        $this->expectException(HttpError::class);
        (new Pushups($this->db, $this->uid))->setPlan(['reps_per_set' => 0, 'sets' => 10]);
    }
}
