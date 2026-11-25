<?php

declare(strict_types=1);

// Usage : php bin/seed.php — recharge la bibliothèque et le programme depuis data/*.json (sans toucher à l'historique).

use App\Config;
use App\Db;
use App\Seeder;

require dirname(__DIR__) . '/vendor/autoload.php';

$c = (new Seeder(Db::get(), Config::rootDir() . '/data'))->run();
printf("Seed OK : %d exercices, %d séances, %d blocs, %d exercices de programme.\n", $c['exercises'], $c['templates'], $c['blocks'], $c['items']);
