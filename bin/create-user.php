<?php

declare(strict_types=1);

// Usage : php bin/create-user.php votre@email.fr [Europe/Paris]
// Crée le compte unique ; le mot de passe est demandé sans être affiché.

use App\Auth;
use App\Config;
use App\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$email = $argv[1] ?? null;
if ($email === null) {
    fwrite(STDERR, "Usage : php bin/create-user.php email [fuseau]\n");
    exit(1);
}
$timezone = $argv[2] ?? Config::get('APP_TIMEZONE', 'Europe/Paris');

$ask = static function (string $prompt): string {
    echo $prompt;
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }

    return $value;
};

$password = $ask('Mot de passe (10 caractères minimum) : ');
if ($password !== $ask('Confirmer : ')) {
    fwrite(STDERR, "Les mots de passe ne correspondent pas.\n");
    exit(1);
}

try {
    $id = Auth::createUser(Db::get(), $email, $password, $timezone);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
echo "Compte créé (id $id).\n";
