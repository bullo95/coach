<?php

declare(strict_types=1);

// Usage : php bin/reminders.php [--dry-run] [--at="2026-11-27 18:00"]
// Lancé chaque minute par cron : envoie les rappels dus et clôt les journées de pompes passées.
// --dry-run : affiche ce qui partirait, sans rien envoyer. --at : fait comme s'il était cette heure (heure locale du compte).

use App\Db;
use App\Reminders;
use App\WebPushSender;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['dry-run', 'at:']);
$db = Db::get();
$status = 0;
foreach ($db->query('SELECT id, timezone FROM user')->fetchAll() as $user) {
    $now = isset($opts['at']) ? new DateTimeImmutable($opts['at'], new DateTimeZone($user['timezone'])) : null;
    $reminders = new Reminders($db, (int) $user['id']);
    if (isset($opts['dry-run'])) {
        $db->beginTransaction(); // le calcul clôt des journées de pompes : annulé, un essai ne doit rien écrire
        $due = $reminders->due($now);
        $db->rollBack();
        echo $due ? json_encode(Reminders::message($due), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL : 'Rien à envoyer.' . PHP_EOL;
        continue;
    }
    try {
        $r = $reminders->run(static fn () => new WebPushSender(), $now);
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . ' ' . $e->getMessage() . PHP_EOL);
        $status = 1;
        continue;
    }
    if ($r['due'] > 0) { // une ligne de journal seulement quand il se passe quelque chose
        echo date('c') . " dus={$r['due']} envoyés={$r['sent']} échecs={$r['failed']} retirés={$r['removed']}"
            . ($r['errors'] ? ' : ' . implode(' | ', $r['errors']) : '') . PHP_EOL;
    }
}
exit($status);
