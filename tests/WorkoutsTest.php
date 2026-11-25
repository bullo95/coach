<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Auth;
use App\Http\Request;
use App\Workouts;

final class WorkoutsTest extends DatabaseTestCase
{
    private const S = '11111111-1111-4111-8111-111111111111';
    private int $uid;
    private Workouts $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris');
        $this->w = new Workouts($this->db, $this->uid);
    }

    private function item(string $ref): int
    {
        return (int) $this->db->query("SELECT id FROM template_item WHERE ref = '$ref'")->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function fullSession(): array
    {
        return [
            ['type' => 'session_start', 'client_uuid' => self::S, 'template_code' => 'A', 'planned_date' => '2026-11-23', 'started_at' => '2026-11-23T18:00:00+01:00'],
            ['type' => 'set', 'client_uuid' => '22222222-2222-4222-8222-222222222222', 'session_uuid' => self::S,
                'template_item_id' => $this->item('A-force-squat-gobelet'), 'round' => 1, 'reps_done' => 12, 'load_kg' => 12, 'logged_at' => '2026-11-23T17:08:00Z'],
            ['type' => 'set', 'client_uuid' => '33333333-3333-4333-8333-333333333333', 'session_uuid' => self::S,
                'template_item_id' => $this->item('A-force-rowing-barre'), 'round' => 1, 'reps_done' => 10, 'load_kg' => 22.5, 'logged_at' => '2026-11-23T17:10:00Z'],
            ['type' => 'set', 'client_uuid' => '44444444-4444-4444-8444-444444444444', 'session_uuid' => self::S,
                'template_item_id' => $this->item('A-abdos-planche'), 'round' => 1, 'seconds_done' => 40, 'logged_at' => '2026-11-23T17:20:00Z'],
            ['type' => 'session_end', 'client_uuid' => '55555555-5555-4555-8555-555555555555', 'session_uuid' => self::S,
                'ended_at' => '2026-11-23T17:29:00Z', 'status' => 'done', 'effort_1_5' => 3, 'pain_flag' => true, 'pain_area' => 'epaule', 'note' => ' Bonne séance '],
        ];
    }

    public function testSyncRecordsAFullSession(): void
    {
        $res = $this->w->sync($this->fullSession());
        self::assertCount(5, $res['accepted']);
        self::assertSame([], $res['rejected']);

        $history = $this->w->history();
        self::assertCount(1, $history);
        self::assertSame('A', $history[0]['code']);
        self::assertSame('done', $history[0]['status']);
        self::assertSame(29, $history[0]['duration_min']);
        self::assertSame(3, $history[0]['sets_count']);
        self::assertSame('2026-11-23T17:00:00Z', $history[0]['started_at'], 'horodatage converti en UTC');

        $detail = $this->w->session($history[0]['id']);
        self::assertSame('Bonne séance', $detail['note']);
        self::assertSame(['Squat gobelet', 'Rowing barre penché', 'Planche avant-bras'], array_column($detail['exercises'], 'exercise'));
        self::assertSame(22.5, $detail['exercises'][1]['sets'][0]['load_kg']);

        self::assertSame('2026-11-23', $this->db->query('SELECT program_start_date FROM settings')->fetchColumn());
        self::assertSame('epaule', $this->db->query("SELECT area FROM pain_report WHERE context = 'workout'")->fetchColumn());
    }

    public function testReplayingTheSameEventsCreatesNoDuplicate(): void
    {
        $this->w->sync($this->fullSession());
        $res = $this->w->sync($this->fullSession());

        self::assertCount(5, $res['accepted'], 'un renvoi est accepté sans erreur');
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM workout_session')->fetchColumn());
        self::assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM set_log')->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM pain_report')->fetchColumn());
    }

    public function testInvalidEventsAreRejectedWithoutBlockingTheOthers(): void
    {
        $events = $this->fullSession();
        $events[1]['load_kg'] = 999;                 // charge hors limites
        $events[2]['session_uuid'] = '99999999-9999-4999-8999-999999999999'; // séance inconnue
        $res = $this->w->sync($events);

        self::assertCount(3, $res['accepted']);
        self::assertCount(2, $res['rejected']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM set_log')->fetchColumn());
    }

    public function testLoadsPreferLastLoggedThenProgram(): void
    {
        $squat = $this->item('A-force-squat-gobelet');
        $rowing = $this->item('A-force-rowing-barre');
        $planche = $this->item('A-abdos-planche');

        $before = $this->w->loads();
        self::assertSame(12.0, $before[$squat], 'charge du programme');
        self::assertNull($before[$rowing], 'barre à régler');

        $this->w->sync($this->fullSession());
        $after = $this->w->loads();
        self::assertSame(22.5, $after[$rowing], 'dernière charge saisie');
        self::assertNull($after[$planche]);

        // Charge validée (progression) quand il n'y a pas encore de saisie.
        $sdt = $this->item('A-force-sdt');
        $this->db->exec("INSERT INTO user_item_load (user_id, template_item_id, load_kg, validated_at) VALUES ({$this->uid}, $sdt, 25, '2026-11-24T08:00:00Z')");
        self::assertSame(25.0, $this->w->loads()[$sdt]);
    }

    public function testApiSyncAndHistoryEndToEnd(): void
    {
        $_SESSION = ['user_id' => $this->uid];
        $body = json_encode(['events' => $this->fullSession()]);
        $res = App::handle(new Request('POST', '/api/sync', [], $body, ['content-type' => 'application/json']), $this->db);
        self::assertSame(200, $res->status);

        $list = json_decode(App::handle(new Request('GET', '/api/sessions'), $this->db)->body, true);
        self::assertCount(1, $list);
        self::assertSame(200, App::handle(new Request('GET', '/api/sessions/' . $list[0]['id']), $this->db)->status);
        self::assertSame(404, App::handle(new Request('GET', '/api/sessions/9999'), $this->db)->status);

        $today = json_decode(App::handle(new Request('GET', '/api/today'), $this->db)->body, true);
        self::assertSame('B', $today['next_template']);
        $_SESSION = [];
    }
}
