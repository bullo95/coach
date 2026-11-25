<?php

declare(strict_types=1);

use App\App;
use App\Auth;
use App\Db;
use App\Http\Request;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Serveur de dev (php -S) : laisser servir les fichiers statiques existants.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

// Hors API : renvoyer la coquille de l'application (navigation gérée côté client).
if (!str_starts_with($path, '/api/')) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile(__DIR__ . '/index.html');

    return;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$secure = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
Auth::startSession($secure);

App::handle(Request::fromGlobals(), Db::get())->send();
