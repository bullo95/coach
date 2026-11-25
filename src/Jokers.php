<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Quota de jokers du mois, commun aux pompes (journée couverte) et aux séances (séance sautée).
 */
final class Jokers
{
    /** @param string $month AAAA-MM */
    public static function used(PDO $db, int $userId, string $month): int
    {
        $stmt = $db->prepare(
            "SELECT (SELECT COUNT(*) FROM pushup_day WHERE user_id = :u AND status = 'joker' AND day LIKE :m)
                  + (SELECT COUNT(*) FROM workout_session WHERE user_id = :u AND joker = 1 AND planned_date LIKE :m)"
        );
        $stmt->execute(['u' => $userId, 'm' => $month . '-%']);

        return (int) $stmt->fetchColumn();
    }

    public static function left(PDO $db, int $userId, string $month): int
    {
        $stmt = $db->prepare('SELECT pushups_jokers_per_month FROM settings WHERE user_id = ?');
        $stmt->execute([$userId]);

        return max(0, (int) $stmt->fetchColumn() - self::used($db, $userId, $month));
    }
}
