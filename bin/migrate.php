<?php

declare(strict_types=1);

// Usage : php bin/migrate.php

use App\Config;
use App\Db;
use App\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$applied = (new Migrator(Db::get(), Config::rootDir() . '/migrations'))->migrate();
echo $applied ? 'Migrations appliquées : ' . implode(', ', $applied) . PHP_EOL : 'Base à jour.' . PHP_EOL;
