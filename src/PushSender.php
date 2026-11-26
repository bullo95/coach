<?php

declare(strict_types=1);

namespace App;

/** Envoi d'un message à un abonnement Web Push (remplacé par un faux dans les tests). */
interface PushSender
{
    /**
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     * @return array{ok: bool, expired: bool, reason: string}
     */
    public function send(array $subscription, string $payload): array;
}
