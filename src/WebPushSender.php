<?php

declare(strict_types=1);

namespace App;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Envoi réel, signé avec les clés VAPID du fichier .env. */
final class WebPushSender implements PushSender
{
    private WebPush $webPush;

    public function __construct()
    {
        if (!Push::configured()) {
            throw new \RuntimeException('Notifications non réglées : ' . Push::missing());
        }
        $this->webPush = new WebPush(['VAPID' => [
            'subject' => (string) Config::get('VAPID_SUBJECT'),
            'publicKey' => (string) Config::get('VAPID_PUBLIC_KEY'),
            'privateKey' => (string) Config::get('VAPID_PRIVATE_KEY'),
        ]], ['TTL' => 1800], 15);
    }

    public function send(array $subscription, string $payload): array
    {
        $report = $this->webPush->sendOneNotification(new Subscription(
            $subscription['endpoint'],
            $subscription['p256dh'],
            $subscription['auth'],
            'aes128gcm',
        ), $payload);

        return ['ok' => $report->isSuccess(), 'expired' => $report->isSubscriptionExpired(), 'reason' => $report->getReason()];
    }
}
