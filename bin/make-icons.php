<?php

declare(strict_types=1);

// Usage : php bin/make-icons.php — génère les icônes PNG de la PWA (kettlebell stylisée) dans public/icons/.

$out = dirname(__DIR__) . '/public/icons';
if (!is_dir($out)) {
    mkdir($out, 0o775, true);
}

/** @param float $scale taille du motif (1 = pleine icône, < 1 pour la zone sûre des icônes « maskable ») */
function icon(int $size, float $scale, bool $rounded): GdImage
{
    $f = 4; // suréchantillonnage pour lisser les bords
    $s = $size * $f;
    $im = imagecreatetruecolor($s, $s);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
    $bg = imagecolorallocate($im, 0x0f, 0x14, 0x19);
    $green = imagecolorallocate($im, 0x4a, 0xde, 0x80);
    imagefill($im, 0, 0, $transparent);

    if ($rounded) {
        $r = (int) ($s * 0.22);
        imagefilledrectangle($im, $r, 0, $s - $r, $s, $bg);
        imagefilledrectangle($im, 0, $r, $s, $s - $r, $bg);
        foreach ([[$r, $r], [$s - $r, $r], [$r, $s - $r], [$s - $r, $s - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $bg);
        }
    } else {
        imagefilledrectangle($im, 0, 0, $s, $s, $bg);
    }

    $c = $s / 2;
    $u = $s * $scale;
    // Anse : anneau.
    $handleCy = (int) ($c - 0.16 * $u);
    imagefilledellipse($im, (int) $c, $handleCy, (int) (0.46 * $u), (int) (0.42 * $u), $green);
    imagefilledellipse($im, (int) $c, $handleCy, (int) (0.26 * $u), (int) (0.22 * $u), $bg);
    // Corps.
    imagefilledellipse($im, (int) $c, (int) ($c + 0.12 * $u), (int) (0.58 * $u), (int) (0.54 * $u), $green);
    // Base aplatie.
    imagefilledrectangle($im, (int) ($c - 0.2 * $u), (int) ($c + 0.36 * $u), (int) ($c + 0.2 * $u), (int) ($c + 0.4 * $u), $bg);

    $final = imagecreatetruecolor($size, $size);
    imagesavealpha($final, true);
    imagealphablending($final, false);
    imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
    imagecopyresampled($final, $im, 0, 0, 0, 0, $size, $size, $s, $s);

    return $final;
}

imagepng(icon(192, 0.8, true), "$out/icon-192.png");
imagepng(icon(512, 0.8, true), "$out/icon-512.png");
imagepng(icon(512, 0.6, false), "$out/icon-maskable-512.png");
imagepng(icon(180, 0.8, false), "$out/apple-touch-icon.png"); // iOS arrondit lui-même les coins
echo "Icônes générées dans public/icons/\n";
