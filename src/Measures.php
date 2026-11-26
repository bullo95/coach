<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpError;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Poids et tour de taille. Une pesée par semaine, le jour choisi : l'app dit quand elle est due.
 * Profil (sexe, date de naissance, taille) : sert à l'âge, à l'IMC et aux repères de tour de taille.
 */
final class Measures
{
    private DateTimeZone $tz;
    private int $weighDay;
    private string $weighTime;
    private ?string $sex;
    private ?string $birthDate;
    private ?float $heightCm;

    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
        $stmt = $db->prepare('SELECT u.timezone, s.weigh_day, s.weigh_time, s.sex, s.birth_date, s.height_cm FROM user u JOIN settings s ON s.user_id = u.id WHERE u.id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('Utilisateur inconnu.');
        }
        $this->tz = new DateTimeZone($row['timezone']);
        $this->weighDay = (int) $row['weigh_day'];
        $this->weighTime = (string) $row['weigh_time'];
        $this->sex = $row['sex'];
        $this->birthDate = $row['birth_date'];
        $this->heightCm = $row['height_cm'] !== null ? (float) $row['height_cm'] : null;
    }

    /** @return array<string, mixed> pesées (de la plus ancienne à la plus récente) et état de la pesée de la semaine */
    public function state(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz);
        $today = $now->format('Y-m-d');
        $weights = $this->series('weight_kg');
        $last = $weights ? $weights[\count($weights) - 1] : null;
        $daysSince = $last ? (int) (new DateTimeImmutable($last['date'], $this->tz))->diff(new DateTimeImmutable($today, $this->tz))->days : null;
        $isWeighDay = (int) $now->format('N') === $this->weighDay;

        // Due : jamais pesé, ou jour de pesée sans pesée aujourd'hui, ou plus de 7 jours sans pesée.
        $due = $last === null || ($isWeighDay && $last['date'] !== $today) || $daysSince > 7;
        $next = $now->setTime(0, 0);
        if (!$isWeighDay || ($last !== null && $last['date'] === $today)) {
            do {
                $next = $next->modify('+1 day');
            } while ((int) $next->format('N') !== $this->weighDay);
        }

        $first = $weights[0] ?? null;
        $prev = \count($weights) >= 2 ? $weights[\count($weights) - 2] : null;

        $waists = $this->series('waist_cm');
        $age = $this->birthDate !== null ? (new DateTimeImmutable($this->birthDate, $this->tz))->diff(new DateTimeImmutable($today, $this->tz))->y : null;

        return [
            'weights' => $weights,
            'waists' => $waists,
            'profile' => ['sex' => $this->sex, 'birth_date' => $this->birthDate, 'age' => $age, 'height_cm' => $this->heightCm],
            'body' => self::body($last['value'] ?? null, $waists ? $waists[\count($waists) - 1]['value'] : null, $this->heightCm, $this->sex, $age),
            'weigh' => [
                'day' => $this->weighDay,
                'time' => $this->weighTime,
                'due' => $due,
                'reason' => $last === null ? 'first' : ($isWeighDay && $last['date'] !== $today ? 'weigh_day' : ($daysSince > 7 ? 'overdue' : null)),
                'last_date' => $last['date'] ?? null,
                'days_since' => $daysSince,
                'next_date' => $due ? $today : $next->format('Y-m-d'),
            ],
            'summary' => [
                'current' => $last['value'] ?? null,
                'since_first' => $last && $first && $first !== $last ? round($last['value'] - $first['value'], 1) : null,
                'since_previous' => $last && $prev ? round($last['value'] - $prev['value'], 1) : null,
                'first_date' => $first['date'] ?? null,
            ],
        ];
    }

    /** Enregistre une mesure ; une seule par jour (la nouvelle remplace celle du même jour). */
    public function add(mixed $weightKg, mixed $waistCm, ?DateTimeImmutable $now = null): void
    {
        $weight = self::number($weightKg, 30, 300, 'Poids : entre 30 et 300 kg');
        $waist = self::number($waistCm, 40, 250, 'Tour de taille : entre 40 et 250 cm');
        if ($weight === null && $waist === null) {
            throw new HttpError(422, 'Indique un poids ou un tour de taille');
        }
        $day = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz)->format('Y-m-d');
        $stmt = $this->db->prepare('SELECT id, weight_kg, waist_cm FROM body_measure WHERE user_id = ? AND measured_at = ?');
        $stmt->execute([$this->userId, $day]);
        $same = $stmt->fetch();
        if ($same) {
            $this->db->prepare('UPDATE body_measure SET weight_kg = ?, waist_cm = ? WHERE id = ?')
                ->execute([$weight ?? $same['weight_kg'], $waist ?? $same['waist_cm'], $same['id']]);
        } else {
            $this->db->prepare('INSERT INTO body_measure (user_id, measured_at, weight_kg, waist_cm) VALUES (?, ?, ?, ?)')
                ->execute([$this->userId, $day, $weight, $waist]);
        }
    }

    /**
     * Repères de corpulence. IMC = poids / taille² ; classes de l'OMS pour l'adulte (pas de classe avant 18 ans).
     * Tour de taille : rapport à la taille (repère 0,5) et seuils européens selon le sexe (94 / 102 cm homme, 80 / 88 cm femme).
     *
     * @return array<string, mixed>
     */
    public static function body(?float $weightKg, ?float $waistCm, ?float $heightCm, ?string $sex, ?int $age): array
    {
        $m = $heightCm !== null ? $heightCm / 100 : null;
        $bmi = $weightKg !== null && $m !== null ? round($weightKg / ($m * $m), 1) : null;
        $category = null;
        if ($bmi !== null && ($age === null || $age >= 18)) {
            $category = $bmi < 18.5 ? 'insuffisant' : ($bmi < 25 ? 'normal' : ($bmi < 30 ? 'surpoids' : 'obesite'));
        }
        $limits = ['homme' => [94, 102], 'femme' => [80, 88]][$sex ?? ''] ?? null;

        return [
            'bmi' => $bmi,
            'bmi_category' => $category,
            'healthy_min_kg' => $m !== null ? round(18.5 * $m * $m, 1) : null,
            'healthy_max_kg' => $m !== null ? round(25 * $m * $m, 1) : null,
            'waist_cm' => $waistCm,
            'waist_ratio' => $waistCm !== null && $heightCm !== null ? round($waistCm / $heightCm, 2) : null,
            'waist_level' => $waistCm !== null && $limits !== null ? ($waistCm < $limits[0] ? 'ok' : ($waistCm < $limits[1] ? 'eleve' : 'tres_eleve')) : null,
            'waist_limits' => $limits,
        ];
    }

    /** @param array<string, mixed> $data sex, birth_date (AAAA-MM-JJ), height_cm ; une valeur vide efface le champ */
    public function setProfile(array $data, ?DateTimeImmutable $now = null): void
    {
        $sex = ($data['sex'] ?? '') === '' ? null : $data['sex'];
        if ($sex !== null && !\in_array($sex, ['homme', 'femme', 'autre'], true)) {
            throw new HttpError(422, 'Sexe : homme, femme ou autre');
        }
        $birth = ($data['birth_date'] ?? '') === '' ? null : $data['birth_date'];
        if ($birth !== null) {
            $d = \is_string($birth) ? DateTimeImmutable::createFromFormat('!Y-m-d', $birth, $this->tz) : false;
            $today = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz);
            if (!$d || $d->format('Y-m-d') !== $birth || $d > $today || $d->diff($today)->y > 120) {
                throw new HttpError(422, 'Date de naissance invalide');
            }
        }
        $height = self::number($data['height_cm'] ?? null, 100, 250, 'Taille : entre 100 et 250 cm');
        $this->db->prepare('UPDATE settings SET sex = ?, birth_date = ?, height_cm = ? WHERE user_id = ?')
            ->execute([$sex, $birth, $height, $this->userId]);
        [$this->sex, $this->birthDate, $this->heightCm] = [$sex, $birth, $height];
    }

    public function delete(string $day): void
    {
        $stmt = $this->db->prepare('DELETE FROM body_measure WHERE user_id = ? AND measured_at = ?');
        $stmt->execute([$this->userId, $day]);
        if ($stmt->rowCount() === 0) {
            throw new HttpError(404, 'Mesure introuvable');
        }
    }

    public function setSchedule(mixed $day, mixed $time): void
    {
        if (!is_numeric($day) || (int) $day < 1 || (int) $day > 7) {
            throw new HttpError(422, 'Jour de pesée invalide');
        }
        if (!\is_string($time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new HttpError(422, 'Heure invalide');
        }
        $this->db->prepare('UPDATE settings SET weigh_day = ?, weigh_time = ? WHERE user_id = ?')->execute([(int) $day, $time, $this->userId]);
        $this->weighDay = (int) $day;
        $this->weighTime = $time;
    }

    /** @return list<array{date: string, value: float}> */
    private function series(string $column): array
    {
        $stmt = $this->db->prepare("SELECT measured_at, $column AS v FROM body_measure WHERE user_id = ? AND $column IS NOT NULL ORDER BY measured_at");
        $stmt->execute([$this->userId]);

        return array_map(static fn (array $r) => ['date' => $r['measured_at'], 'value' => (float) $r['v']], $stmt->fetchAll());
    }

    private static function number(mixed $v, float $min, float $max, string $error): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (\is_string($v)) {
            $v = str_replace(',', '.', $v);
        }
        if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
            throw new HttpError(422, $error);
        }

        return round((float) $v, 1);
    }
}
