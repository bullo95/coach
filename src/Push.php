<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpError;
use PDO;

/**
 * Abonnements Web Push de l'utilisateur (un par appareil) et envoi à tous ses appareils.
 */
final class Push
{
    /** Services push acceptés : le serveur n'envoie rien ailleurs. */
    private const HOSTS = '/(^|\.)(fcm\.googleapis\.com|push\.apple\.com|push\.services\.mozilla\.com|notify\.windows\.com)$/';

    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
    }

    public static function configured(): bool
    {
        return self::missing() === null;
    }

    /** Ce qui manque dans .env pour envoyer, ou null si tout y est. */
    public static function missing(): ?string
    {
        if (!Config::get('VAPID_PUBLIC_KEY') || !Config::get('VAPID_PRIVATE_KEY')) {
            return 'clés VAPID absentes de .env (lancer « php bin/vapid-keys.php »)';
        }
        if (!preg_match('#^(mailto:.+@.+|https://.+)#', (string) Config::get('VAPID_SUBJECT'))) {
            return 'VAPID_SUBJECT absent de .env (une adresse « mailto:… » ou l\'adresse https de l\'app)';
        }

        return null;
    }

    public function count(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM push_subscription WHERE user_id = ?');
        $stmt->execute([$this->userId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data abonnement tel que le navigateur le donne (endpoint, keys.p256dh, keys.auth) */
    public function subscribe(array $data, ?string $userAgent): void
    {
        $endpoint = (string) ($data['endpoint'] ?? '');
        $p256dh = (string) ($data['keys']['p256dh'] ?? '');
        $auth = (string) ($data['keys']['auth'] ?? '');
        $url = parse_url($endpoint);
        if (($url['scheme'] ?? '') !== 'https' || !preg_match(self::HOSTS, (string) ($url['host'] ?? '')) || \strlen($endpoint) > 1000) {
            throw new HttpError(400, 'Service de notification non reconnu');
        }
        if (!preg_match('/^[\w\-+\/=]{20,200}$/', $p256dh) || !preg_match('/^[\w\-+\/=]{10,100}$/', $auth)) {
            throw new HttpError(400, 'Abonnement incomplet');
        }
        $this->db->prepare(
            'INSERT INTO push_subscription (user_id, endpoint, p256dh, auth, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh, auth = excluded.auth,
                user_agent = excluded.user_agent'
        )->execute([$this->userId, $endpoint, $p256dh, $auth, $userAgent !== null ? mb_substr($userAgent, 0, 250) : null, Db::now()]);
    }

    public function unsubscribe(string $endpoint): void
    {
        $this->db->prepare('DELETE FROM push_subscription WHERE user_id = ? AND endpoint = ?')->execute([$this->userId, $endpoint]);
    }

    /**
     * Envoie le message à tous les appareils ; retire les abonnements que le service push déclare périmés.
     *
     * @param array{title: string, body: string, url: string, tag: string} $message
     * @return array{sent: int, failed: int, removed: int, errors: list<string>}
     */
    public function sendAll(array $message, PushSender $sender): array
    {
        $stmt = $this->db->prepare('SELECT endpoint, p256dh, auth FROM push_subscription WHERE user_id = ?');
        $stmt->execute([$this->userId]);
        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $out = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => []];
        foreach ($stmt->fetchAll() as $sub) {
            try {
                $r = $sender->send($sub, $payload);
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'expired' => false, 'reason' => $e->getMessage()];
            }
            if ($r['ok']) {
                ++$out['sent'];
                continue;
            }
            ++$out['failed'];
            $out['errors'][] = $r['reason'];
            if ($r['expired']) {
                $this->unsubscribe($sub['endpoint']);
                ++$out['removed'];
            }
        }

        return $out;
    }
}
