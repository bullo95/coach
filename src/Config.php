<?php

declare(strict_types=1);

namespace App;

/**
 * Configuration lue dans le fichier .env (format CLE=valeur), surchargée par les variables d'environnement.
 */
final class Config
{
    /** @var array<string, string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function rootDir(): string
    {
        return \dirname(__DIR__);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }

        return self::$values[$key] ?? $default;
    }

    public static function isDev(): bool
    {
        return self::get('APP_ENV', 'prod') === 'dev';
    }

    /** Chemin absolu du fichier SQLite. */
    public static function dbPath(): string
    {
        $path = self::get('DB_PATH', 'var/coach.sqlite');

        return str_starts_with($path, '/') ? $path : self::rootDir() . '/' . $path;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $file = self::rootDir() . '/.env';
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            self::$values[trim($key)] = trim(trim($value), '"\'');
        }
    }
}
