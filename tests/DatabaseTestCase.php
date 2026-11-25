<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Db;
use App\Migrator;
use App\Seeder;
use PDO;
use PHPUnit\Framework\TestCase;

/** Chaque test part d'une base SQLite neuve, migrée et chargée. */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'coach-test-') . '.sqlite';
        $this->db = Db::connect($this->file);
        (new Migrator($this->db, Config::rootDir() . '/migrations'))->migrate();
        (new Seeder($this->db, Config::rootDir() . '/data'))->run();
        Db::set($this->db);
    }

    protected function tearDown(): void
    {
        Db::set(null);
        unset($this->db);
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }
}
