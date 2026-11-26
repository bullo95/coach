<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth;
use App\Http\HttpError;
use App\Measures;
use App\Push;
use App\PushSender;
use App\Pushups;
use App\Reminders;
use DateTimeImmutable;

/** Compte par défaut : séances lun/mer/ven à 18:00, pompes 10 × 23 de 08:00 à 21:00, silence 22:00 → 07:30, pesée lundi 07:00. */
final class RemindersTest extends DatabaseTestCase
{
    private int $uid;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris');
    }

    private function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new \DateTimeZone('Europe/Paris'));
    }

    /** @return list<string> types dus à cette heure */
    private function due(string $local): array
    {
        return array_column((new Reminders($this->db, $this->uid))->due($this->at($local)), 'type');
    }

    private function weigh(string $local): void
    {
        (new Measures($this->db, $this->uid))->add(82.0, null, $this->at($local));
    }

    private function pushups(string $local, int $sets): void
    {
        for ($i = 0; $i < $sets; ++$i) {
            (new Pushups($this->db, $this->uid))->log([
                'client_uuid' => sprintf('00000000-0000-4000-8000-%012d', ++$this->n),
                'count' => 23,
                'logged_at' => $this->at($local)->format(DATE_ATOM),
            ]);
        }
    }

    private function subscribe(string $id = 'abc'): void
    {
        (new Push($this->db, $this->uid))->subscribe([
            'endpoint' => "https://web.push.apple.com/$id",
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ], 'test');
    }

    /** @param list<array{ok: bool, expired: bool, reason: string}> $results */
    private function sender(array $results = []): PushSender
    {
        return new class($results) implements PushSender {
            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function __construct(private array $results)
            {
            }

            public function send(array $subscription, string $payload): array
            {
                $this->sent[] = json_decode($payload, true);

                return array_shift($this->results) ?? ['ok' => true, 'expired' => false, 'reason' => 'OK'];
            }
        };
    }

    public function testSessionReminderAtChosenTimeOnSessionDaysOnly(): void
    {
        $this->weigh('2026-11-23 07:40');
        $this->pushups('2026-11-23 08:00', 10);
        self::assertSame([], $this->due('2026-11-23 17:59')); // lundi, avant l'heure
        self::assertSame(['session'], $this->due('2026-11-23 18:00'));
        self::assertSame(['session'], $this->due('2026-11-23 18:19'));
        self::assertSame([], $this->due('2026-11-23 18:20')); // pas de rattrapage après 20 min
        self::assertSame(['session_missed'], $this->due('2026-11-23 20:00'));

        $this->pushups('2026-11-24 08:00', 10);
        self::assertSame([], $this->due('2026-11-24 18:00')); // mardi : pas de séance
    }

    public function testNoSessionReminderOnceDoneOrSkipped(): void
    {
        $this->weigh('2026-11-23 07:40');
        $this->pushups('2026-11-23 08:00', 10);
        (new \App\Planner($this->db, $this->uid))->skipToday($this->at('2026-11-23 12:00'));
        self::assertSame([], $this->due('2026-11-23 18:00'));
        self::assertSame([], $this->due('2026-11-23 20:00'));
    }

    public function testPushupSlotsOnlyWhenBehind(): void
    {
        $this->weigh('2026-11-24 07:40');
        // 10 créneaux de 08:00 à 21:00 : 08:00, 09:27, 10:53, 12:20…
        self::assertSame(['pushups'], $this->due('2026-11-24 08:00'));
        self::assertSame([], $this->due('2026-11-24 09:00'));
        self::assertSame(['pushups'], $this->due('2026-11-24 09:27'));

        $this->pushups('2026-11-24 09:30', 3); // en avance : 3 séries faites
        self::assertSame([], $this->due('2026-11-24 09:35'));
        self::assertSame([], $this->due('2026-11-24 10:53')); // 3e créneau, déjà fait
        $d = (new Reminders($this->db, $this->uid))->due($this->at('2026-11-24 12:20')); // 4e créneau
        self::assertSame('Pompes : série 4 sur 10', $d[0]['title']);
        self::assertStringContainsString('Encore 161', $d[0]['body']);

        $this->pushups('2026-11-24 13:00', 7); // objectif atteint
        self::assertSame([], $this->due('2026-11-24 21:00'));
    }

    public function testQuietHoursShiftMorningAndDropEvening(): void
    {
        // Lundi : la pesée de 07:00 tombe dans le silence (jusqu'à 07:30) → décalée à 07:30.
        self::assertSame([], $this->due('2026-11-23 07:00'));
        self::assertSame(['weight'], $this->due('2026-11-23 07:30'));

        // Séance à 21:00 : la relance de 23:00 tombe dans le silence du soir → abandonnée.
        (new Reminders($this->db, $this->uid))->update(['session_time' => '21:00', 'types' => ['pushups' => false, 'weight' => false]]);
        self::assertSame(['session'], $this->due('2026-11-23 21:00'));
        self::assertSame([], $this->due('2026-11-23 23:00'));
        self::assertSame([], $this->due('2026-11-24 07:30'));
    }

    public function testWeightReminderOnlyWhenDue(): void
    {
        $this->weigh('2026-11-23 07:10'); // lundi
        self::assertSame([], $this->due('2026-11-23 07:30'));
        (new Reminders($this->db, $this->uid))->update(['types' => ['pushups' => false]]);
        self::assertSame([], $this->due('2026-11-27 07:30'));
        self::assertSame(['weight'], $this->due('2026-11-30 07:30')); // lundi suivant
    }

    public function testDisabledTypeIsNotSent(): void
    {
        $r = new Reminders($this->db, $this->uid);
        $r->update(['types' => ['weight' => false]]);
        self::assertSame([], $this->due('2026-11-23 07:30'));
        self::assertFalse($r->enabled()['weight']);
        self::assertTrue($r->enabled()['session']);
    }

    public function testRunSendsOnceAndGroupsSimultaneousReminders(): void
    {
        $this->subscribe();
        (new Reminders($this->db, $this->uid))->update(['session_time' => '08:00', 'quiet_end' => '08:00']);
        $sender = $this->sender();
        $r = new Reminders($this->db, $this->uid);

        $out = $r->run($sender, $this->at('2026-11-23 08:00')); // séance, pesée décalée et pompes en même temps
        self::assertSame(3, $out['due']);
        self::assertSame(1, $out['sent']);
        self::assertCount(1, $sender->sent);
        self::assertSame('Coach : 3 rappels', $sender->sent[0]['title']);
        self::assertSame('/#/', $sender->sent[0]['url']);

        $out = $r->run($sender, $this->at('2026-11-23 08:01')); // déjà envoyés
        self::assertSame(0, $out['due']);
        self::assertCount(1, $sender->sent);
    }

    public function testFailedSendIsRetriedAndExpiredSubscriptionRemoved(): void
    {
        $this->subscribe('one');
        $r = new Reminders($this->db, $this->uid);
        $sender = $this->sender([['ok' => false, 'expired' => false, 'reason' => 'timeout']]);
        self::assertSame(0, $r->run($sender, $this->at('2026-11-24 08:00'))['sent']);
        self::assertSame(1, $r->run($sender, $this->at('2026-11-24 08:01'))['sent']); // réessayé la minute suivante

        $gone = $this->sender([['ok' => false, 'expired' => true, 'reason' => '410 Gone']]);
        $out = $r->run($gone, $this->at('2026-11-24 09:27'));
        self::assertSame(1, $out['removed']);
        self::assertSame(0, (new Push($this->db, $this->uid))->count());
    }

    public function testNothingIsSentWithoutSubscription(): void
    {
        $out = (new Reminders($this->db, $this->uid))->run(fn () => self::fail('aucun envoi attendu'), $this->at('2026-11-24 08:00'));
        self::assertSame(['due' => 1, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => []], $out);
    }

    public function testSubscriptionEndpointMustBeAKnownPushService(): void
    {
        $this->subscribe();
        $this->subscribe(); // même appareil : pas de doublon
        self::assertSame(1, (new Push($this->db, $this->uid))->count());

        $this->expectException(HttpError::class);
        (new Push($this->db, $this->uid))->subscribe([
            'endpoint' => 'https://exemple.fr/push', 'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ], null);
    }

    public function testUpdateRejectsInvalidTimes(): void
    {
        $r = new Reminders($this->db, $this->uid);
        try {
            $r->update(['pushups_window_start' => '21:00', 'pushups_window_end' => '08:00']);
            self::fail('plage inversée acceptée');
        } catch (HttpError $e) {
            self::assertSame(400, $e->status);
        }
        $this->expectException(HttpError::class);
        $r->update(['session_time' => '25:00']);
    }
}
