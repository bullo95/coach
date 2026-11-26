<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth;
use App\Http\HttpError;
use App\Measures;
use App\Stats;
use DateTimeImmutable;

final class MeasuresTest extends DatabaseTestCase
{
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris'); // pesée le lundi
    }

    private function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new \DateTimeZone('Europe/Paris'));
    }

    public function testFirstWeighInIsDueImmediately(): void
    {
        $s = (new Measures($this->db, $this->uid))->state($this->at('2026-11-25 09:00')); // mercredi
        self::assertTrue($s['weigh']['due']);
        self::assertSame('first', $s['weigh']['reason']);
        self::assertSame([], $s['weights']);
    }

    public function testWeeklyRhythm(): void
    {
        $m = new Measures($this->db, $this->uid);
        $m->add('82,4', null, $this->at('2026-11-23 07:10')); // lundi, virgule française acceptée
        $s = $m->state($this->at('2026-11-23 07:11'));
        self::assertFalse($s['weigh']['due']);
        self::assertSame('2026-11-30', $s['weigh']['next_date']);
        self::assertSame(82.4, $s['summary']['current']);

        self::assertFalse($m->state($this->at('2026-11-27 09:00'))['weigh']['due'], 'vendredi : rien à faire');

        $s = $m->state($this->at('2026-11-30 06:30')); // lundi suivant
        self::assertTrue($s['weigh']['due']);
        self::assertSame('weigh_day', $s['weigh']['reason']);
    }

    public function testOverdueWhenTheWeighDayWasSkipped(): void
    {
        $m = new Measures($this->db, $this->uid);
        $m->add(82, null, $this->at('2026-11-23 07:00'));
        $s = $m->state($this->at('2026-12-02 09:00')); // mercredi, 9 jours après
        self::assertTrue($s['weigh']['due']);
        self::assertSame('overdue', $s['weigh']['reason']);
    }

    public function testSameDayReplacesAndTrendIsComputed(): void
    {
        $m = new Measures($this->db, $this->uid);
        $m->add(83.0, null, $this->at('2026-11-16 07:00'));
        $m->add(82.5, 96, $this->at('2026-11-23 07:00'));
        $m->add(82.2, null, $this->at('2026-11-23 07:05')); // correction le même jour
        $s = $m->state($this->at('2026-11-23 08:00'));
        self::assertCount(2, $s['weights']);
        self::assertSame(-0.8, $s['summary']['since_first']);
        self::assertSame(-0.8, $s['summary']['since_previous']);
        self::assertSame([['date' => '2026-11-23', 'value' => 96.0]], $s['waists'], 'le tour de taille du jour est conservé');
    }

    public function testInvalidValuesAndSchedule(): void
    {
        $m = new Measures($this->db, $this->uid);
        $m->setSchedule(6, '08:30');
        self::assertSame(6, $m->state()['weigh']['day']);
        $this->expectException(HttpError::class);
        $m->add(500, null);
    }

    public function testStatsOverview(): void
    {
        $this->db->exec("INSERT INTO workout_session (client_uuid, user_id, template_id, planned_date, status) VALUES
            ('a', 1, 1, '2026-11-16', 'done'), ('b', 1, 1, '2026-11-18', 'done'), ('c', 1, 1, '2026-11-23', 'done')");
        $this->db->exec("INSERT INTO workout_session (client_uuid, user_id, template_id, planned_date, status, joker) VALUES ('d', 1, 1, '2026-11-25', 'skipped', 1)");
        $this->db->exec("INSERT INTO pushup_day (user_id, day, reps_per_set, sets_target, target, done, status) VALUES (1, '2026-11-24', 23, 10, 230, 230, 'reached')");

        $o = (new Stats($this->db, $this->uid))->overview($this->at('2026-11-25 12:00'));
        self::assertCount(8, $o['weeks']);
        self::assertSame(['start' => '2026-11-23', 'done' => 1, 'jokers' => 1, 'planned' => 3], $o['weeks'][7]);
        self::assertSame(2, $o['weeks'][6]['done']);
        self::assertCount(14, $o['pushups']);
        self::assertSame(230, $o['pushups'][12]['done']);
        self::assertNull($o['pushups'][0]['target'], 'jour sans donnée');
        self::assertSame(3, $o['totals']['sessions']);
    }
}
