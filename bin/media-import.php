<?php

declare(strict_types=1);

// Usage : php bin/media-import.php [--force]
// Télécharge les visuels listés dans data/exercise_media.json (free-exercise-db), les convertit en WebP
// (800 px de large, 100 Ko au plus) dans public/media/, puis note les fichiers dans le JSON.
// Les visuels sont ensuite servis par l'app elle-même : aucun appel externe en production.

const MAX_BYTES = 100 * 1024;
const MAX_WIDTH = 800;
const BASE = 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/';

$root = dirname(__DIR__);
$file = $root . '/data/exercise_media.json';
$map = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$force = in_array('--force', $argv, true);
$ok = 0;
$skipped = 0;

foreach ($map as $slug => &$entry) {
    if ($slug === '_doc' || ($entry['source'] ?? null) !== 'free-exercise-db' || empty($entry['id'])) {
        continue;
    }
    $files = [];
    foreach ([0, 1] as $n) {
        $rel = "/media/$slug-$n.webp";
        $dest = $root . '/public' . $rel;
        if (!$force && is_file($dest)) {
            $files[] = $rel;
            ++$skipped;
            continue;
        }
        $jpg = @file_get_contents(BASE . rawurlencode($entry['id']) . "/$n.jpg", false, stream_context_create(['http' => ['timeout' => 30]]));
        if ($jpg === false) {
            fwrite(STDERR, "$slug : image $n introuvable ({$entry['id']})\n");
            continue;
        }
        $src = @imagecreatefromstring($jpg);
        if ($src === false) {
            fwrite(STDERR, "$slug : image $n illisible\n");
            continue;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w > MAX_WIDTH) {
            $scaled = imagescale($src, MAX_WIDTH, (int) round($h * MAX_WIDTH / $w), IMG_BICUBIC);
            $src = $scaled ?: $src;
        }
        for ($q = 80; $q >= 30; $q -= 10) {
            imagewebp($src, $dest, $q);
            clearstatcache(true, $dest);
            if (filesize($dest) <= MAX_BYTES) {
                break;
            }
        }
        $files[] = $rel;
        ++$ok;
        printf("%-34s %s  %d Ko\n", $slug, basename($rel), (int) ceil(filesize($dest) / 1024));
    }
    $entry['files'] = $files;
    $entry['license'] = 'Unlicense (domaine public)';
    $entry['attribution'] = 'free-exercise-db';
}
unset($entry);

file_put_contents($file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "$ok image(s) importée(s), $skipped déjà présente(s). Lancer ensuite : php bin/seed.php\n";
