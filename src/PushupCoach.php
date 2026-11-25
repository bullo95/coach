<?php

declare(strict_types=1);

namespace App;

/**
 * Règles du coach des pompes. Fonction pure : mêmes entrées, même conseil.
 *
 * Principe : on n'augmente que sur des journées bouclées et ressenties faciles, jamais avec une douleur récente,
 * une seule variable à la fois, et c'est toujours l'utilisateur qui valide.
 *
 * - objectif « endurance » (enchaîner plus de pompes d'affilée) → plus de pompes par série ;
 * - objectif « volume » (plus de pompes dans la journée, sans s'épuiser) → plus de séries ;
 * - objectif « equilibre » → on alterne.
 */
final class PushupCoach
{
    public const WINDOW = 3;        // journées avec ressenti regardées pour décider
    public const STEADY = 5;        // journées « correctes » d'affilée qui autorisent aussi une hausse
    public const GOALS = ['endurance', 'volume', 'equilibre'];

    /**
     * @param array{
     *   reps: int, sets: int, goal: string,
     *   feedback: list<array{effort: int, pain: ?string}>,
     *   missed7: int, last_increase: ?string
     * } $in feedback : ressentis depuis le dernier changement de programme, du plus récent au plus ancien
     *
     * @return array{action: string, title: string, reasons: list<string>, proposal: ?array{reps_per_set: int, sets: int}, kind: ?string}
     */
    public static function advise(array $in): array
    {
        $x = $in['reps'];
        $sets = $in['sets'];
        $fb = $in['feedback'];
        $recent = \array_slice($fb, 0, self::WINDOW);
        $pains = \count(array_filter($recent, static fn (array $f) => $f['pain'] !== null));
        $hard = \count(array_filter($recent, static fn (array $f) => $f['effort'] === 3));
        $easy = \count(array_filter($recent, static fn (array $f) => $f['effort'] === 1));

        // 1. La douleur passe avant tout.
        if ($pains >= 2) {
            $less = max(1, $x - max(1, (int) round($x * 0.15)));

            return self::out('decrease', 'On allège', [
                'Tu as signalé une douleur sur ' . $pains . ' de tes ' . \count($recent) . ' dernières journées.',
                'Moins de pompes par série soulage les articulations à chaque série.',
                'Si la douleur dure plus de quelques jours ou gêne au quotidien, parles-en à un médecin ou à un kiné.',
            ], $less < $x ? [$less, $sets] : null, 'coach_decrease');
        }
        if ($pains === 1) {
            return self::out('hold', 'On ne monte pas pour l\'instant', [
                'Une douleur a été signalée récemment.',
                'Le programme reste tel quel jusqu\'à ' . self::WINDOW . ' journées bouclées sans douleur.',
            ]);
        }

        // 2. La régularité avant l'intensité.
        if ($in['missed7'] >= 2) {
            return self::out('decrease', 'Retrouver la régularité', [
                $in['missed7'] . ' journées manquées sur les 7 derniers jours.',
                'Une série de moins par jour est plus facile à caser ; on remontera quand les journées seront bouclées.',
            ], $sets > 1 ? [$x, $sets - 1] : null, 'coach_decrease');
        }

        // 3. Pas assez de recul.
        if (\count($recent) < self::WINDOW) {
            $left = self::WINDOW - \count($recent);

            return self::out('hold', 'On observe', [
                'Encore ' . $left . ' journée' . ($left > 1 ? 's' : '') . ' bouclée' . ($left > 1 ? 's' : '') . ' avec ton ressenti avant un conseil.',
            ]);
        }

        // 4. Trop dur.
        if ($hard === self::WINDOW) {
            $less = max(1, $x - max(1, (int) round($x * 0.05)));

            return self::out('decrease', 'Un cran en dessous', [
                'Tes ' . self::WINDOW . ' dernières journées étaient difficiles.',
                'Progresser demande de finir ses séries avec un peu de marge.',
            ], $less < $x ? [$less, $sets] : null, 'coach_decrease');
        }
        if ($hard >= 1) {
            return self::out('hold', 'On consolide', [
                'Une journée difficile parmi les ' . self::WINDOW . ' dernières : le niveau actuel te fait encore travailler.',
                'On augmentera quand ce sera facile ' . self::WINDOW . ' jours de suite.',
            ]);
        }

        // 5. Prêt à augmenter : tout facile, ou régulier et jamais difficile sur une plus longue période.
        $steady = \count($fb) >= self::STEADY
            && \count(array_filter(\array_slice($fb, 0, self::STEADY), static fn (array $f) => $f['effort'] === 3 || $f['pain'] !== null)) === 0;
        if ($easy === self::WINDOW || $steady) {
            $why = $easy === self::WINDOW
                ? 'Tes ' . self::WINDOW . ' dernières journées étaient faciles, sans douleur.'
                : self::STEADY . ' journées bouclées d\'affilée, jamais difficiles, sans douleur.';
            $axis = match ($in['goal']) {
                'endurance' => 'reps',
                'volume' => 'sets',
                default => $in['last_increase'] === 'reps' ? 'sets' : 'reps',
            };
            if ($axis === 'sets' && $sets >= 30) {
                $axis = 'reps';
            }
            if ($axis === 'reps') {
                $more = min(200, $x + max(1, (int) round($x * 0.05)));

                return self::out('increase_reps', 'Plus de pompes par série', [
                    $why,
                    match ($in['goal']) {
                        'endurance' => 'Ton objectif est d\'enchaîner plus de pompes d\'affilée : c\'est la longueur des séries qui le fait progresser.',
                        'volume' => 'Tu es déjà au maximum de séries : on allonge les séries.',
                        default => $in['last_increase'] === null
                            ? 'Objectif équilibré : on commence par allonger les séries ; la prochaine hausse ajoutera une série.'
                            : 'Objectif équilibré : la dernière hausse a ajouté une série, on allonge cette fois les séries.',
                    },
                ], [$more, $sets], 'coach_reps');
            }

            return self::out('increase_sets', 'Une série de plus', [
                $why,
                $in['goal'] === 'volume'
                    ? 'Ton objectif est de faire plus de pompes dans la journée : une série de plus ajoute du volume sans durcir chaque série.'
                    : 'Objectif équilibré : la dernière hausse portait sur les pompes par série, on ajoute cette fois une série.',
            ], [$x, $sets + 1], 'coach_sets');
        }

        return self::out('hold', 'Bon niveau', [
            'Journées bouclées, ressenti correct : tu es au bon niveau de travail.',
            'On augmentera après ' . self::WINDOW . ' journées faciles de suite, ou ' . self::STEADY . ' journées jamais difficiles.',
        ]);
    }

    /**
     * @param list<string> $reasons
     * @param array{int, int}|null $proposal
     */
    private static function out(string $action, string $title, array $reasons, ?array $proposal = null, ?string $kind = null): array
    {
        return [
            'action' => $proposal === null && $action !== 'hold' ? 'hold' : $action,
            'title' => $title,
            'reasons' => $reasons,
            'proposal' => $proposal === null ? null : ['reps_per_set' => $proposal[0], 'sets' => $proposal[1]],
            'kind' => $proposal === null ? null : $kind,
        ];
    }
}
