<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Connexion SQLite unique : mode WAL (lectures pendant les écritures du cron), clés étrangères actives.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(?string $path = null): PDO
    {
        $path ??= Config::dbPath();
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o770, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        return $pdo;
    }

    public static function get(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    /** Pour les tests : remplace la connexion partagée. */
    public static function set(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
