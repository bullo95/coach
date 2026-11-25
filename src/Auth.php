<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpError;
use App\Http\Request;
use PDO;

/**
 * Authentification d'un compte unique : mot de passe + session longue (1 an) sur l'appareil.
 * Protection CSRF : les écritures exigent un corps JSON (impossible depuis un formulaire d'un autre site)
 * et le cookie de session est en SameSite=Lax.
 */
final class Auth
{
    public const SESSION_LIFETIME = 365 * 24 * 3600;

    public static function startSession(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = Config::rootDir() . '/var/sessions';
        if (!is_dir($dir)) {
            mkdir($dir, 0o770, true);
        }
        session_save_path($dir);
        ini_set('session.gc_maxlifetime', (string) self::SESSION_LIFETIME);
        ini_set('session.use_strict_mode', '1');
        session_name('coach_sid');
        session_set_cookie_params([
            'lifetime' => self::SESSION_LIFETIME,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;

        return \is_int($id) ? $id : null;
    }

    public static function requireUser(Request $request): int
    {
        if ($request->method !== 'GET' && !$request->isJson()) {
            throw new HttpError(415, 'Requête JSON attendue');
        }
        $id = self::userId();
        if ($id === null) {
            throw new HttpError(401, 'Connexion requise');
        }

        return $id;
    }

    public static function attempt(PDO $db, string $email, string $password): ?int
    {
        $stmt = $db->prepare('SELECT id, password_hash FROM user WHERE email = ?');
        $stmt->execute([mb_strtolower(trim($email))]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return null;
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $db->prepare('UPDATE user SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }

        return (int) $row['id'];
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie('coach_sid', '', ['expires' => time() - 3600, 'path' => '/']);
    }

    /** Crée le compte unique avec ses réglages par défaut. */
    public static function createUser(PDO $db, string $email, string $password, string $timezone): int
    {
        if ((int) $db->query('SELECT COUNT(*) FROM user')->fetchColumn() > 0) {
            throw new \RuntimeException('Un compte existe déjà : l\'application est mono-utilisateur.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse e-mail invalide.');
        }
        if (mb_strlen($password) < 10) {
            throw new \InvalidArgumentException('Mot de passe trop court (10 caractères minimum).');
        }
        new \DateTimeZone($timezone); // lève une exception si le fuseau est inconnu

        $db->beginTransaction();
        $db->prepare('INSERT INTO user (email, password_hash, timezone, created_at) VALUES (?, ?, ?, ?)')
            ->execute([mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), $timezone, Db::now()]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO settings (user_id) VALUES (?)')->execute([$id]);
        $db->commit();

        return $id;
    }
}
