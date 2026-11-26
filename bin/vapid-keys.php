<?php

declare(strict_types=1);

// Usage : php bin/vapid-keys.php [sujet]
// Crée les clés VAPID (signature des notifications) dans .env si elles n'y sont pas, et y range le sujet s'il est donné.
// sujet : « mailto:adresse@exemple.fr » ou l'adresse https de l'app ; les services push d'Apple et de Google le reçoivent.
// Ne jamais régénérer des clés en service : tous les appareils devraient se réabonner.

use App\Config;
use Minishlink\WebPush\VAPID;

require dirname(__DIR__) . '/vendor/autoload.php';

$file = Config::rootDir() . '/.env';
$env = is_file($file) ? (string) file_get_contents($file) : '';
$set = static function (string $key, string $value) use (&$env): void {
    $line = "$key=$value";
    $env = preg_match("/^$key=.*$/m", $env) ? (string) preg_replace("/^$key=.*$/m", $line, $env) : rtrim($env) . PHP_EOL . $line . PHP_EOL;
};

if (Config::get('VAPID_PUBLIC_KEY') && Config::get('VAPID_PRIVATE_KEY')) {
    echo 'Clés déjà présentes : conservées.' . PHP_EOL;
} else {
    $keys = VAPID::createVapidKeys();
    $set('VAPID_PUBLIC_KEY', $keys['publicKey']);
    $set('VAPID_PRIVATE_KEY', $keys['privateKey']);
    echo 'Clés VAPID créées dans .env.' . PHP_EOL;
}
$subject = $argv[1] ?? null;
if ($subject !== null) {
    if (!preg_match('#^(mailto:.+@.+|https://.+)#', $subject)) {
        fwrite(STDERR, 'Sujet attendu : « mailto:adresse@exemple.fr » ou une adresse https.' . PHP_EOL);
        exit(1);
    }
    $set('VAPID_SUBJECT', $subject);
    echo 'Sujet enregistré.' . PHP_EOL;
} elseif (!Config::get('VAPID_SUBJECT')) {
    echo 'Il manque le sujet : relancer avec « php bin/vapid-keys.php mailto:adresse@exemple.fr ».' . PHP_EOL;
}
file_put_contents($file, $env);
chmod($file, 0o640); // lisible par le groupe du serveur web, pas par les autres
