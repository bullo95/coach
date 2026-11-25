<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Applique dans l'ordre les fichiers migrations/NNN_nom.sql pas encore passés.
 * Table technique : schema_migrations (hors des 18 tables métier).
 */
final class Migrator
{
    public function __construct(private readonly PDO $db, private readonly string $dir)
    {
    }

    /** @return list<string> versions appliquées par cet appel */
    public function migrate(): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $done = $this->db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (\in_array($version, $done, true)) {
                continue;
            }
            $this->db->beginTransaction();
            try {
                $this->db->exec((string) file_get_contents($file));
                $this->db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
                    ->execute([$version, Db::now()]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw new \RuntimeException("Migration $version en échec : " . $e->getMessage(), 0, $e);
            }
            $applied[] = $version;
        }

        return $applied;
    }
}
