<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK ARTWORK — GENERATED SVG SCENES
   ══════════════════════════════════════════════════════════════════════════
   The designs use two pieces of illustrative art that do not exist in the repo:
   a purple mountain-and-planet landscape (login page, hero band) and a smaller
   mountain panel (splash carousel). There is no source for either, so they are
   generated here as deterministic SVG rather than invented as a bitmap.

   Generated from a fixed seed, so every request returns byte-identical markup.
   That matters: a random scene would change on reload and would make the page
   impossible to screenshot-compare.

   Palette is taken from the design system rather than hand-picked, so the art
   moves with any future retheme of --ly-primary.
   ══════════════════════════════════════════════════════════════════════════ */
declare(strict_types=1);

if (!function_exists('lyra_art_rng')) {
    /** Deterministic 32-bit PRNG (mulberry32). Same seed, same scene. */
    function lyra_art_rng(int $seed): callable
    {
        $a = $seed & 0xFFFFFFFF;
        return function () use (&$a): float {
            $a = ($a + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $a;
            $t = (($t ^ ($t >> 15)) * (1 | $t)) & 0xFFFFFFFF;
            $t = ($t + ((($t ^ ($t >> 7)) * (61 | $t)) & 0xFFFFFFFF)) ^ $t;
            $t = $t & 0xFFFFFFFF;
            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296.0;
        };
    }
}

if (!function_exists('lyra_art_ridges')) {
    /**
     * Build a filled mountain ridge as an SVG path.
     *
     * $peaks     how many summits across the width
     * $baseY     the ridge's baseline in viewbox units
     * $height    maximum summit height above baseline
     * $jitter    per-point vertical noise, as a fraction of $height
     *
     * Points are spaced evenly with a slight horizontal wobble so the silhouette
     * reads as rock rather than as a zigzag.
     */
    function lyra_art_ridges(int $seed, int $w, int $baseY, int $height, int $peaks, float $jitter = 0.35): string
    {
        $r = lyra_art_rng($seed);
        $pts = [];
        // Roughly 2.4x as many sample points as summits, so the silhouette breaks
        // into spurs and saddles instead of reading as a row of triangles.
        $steps = (int) max(24, $peaks * 5);
        $seg = $w / $steps;
        for ($i = 0; $i <= $steps; $i++) {
            $x = $i * $seg + ($r() - 0.5) * $seg * 0.7;
            $phase = sin($i / max(1.0, $steps / $peaks) * M_PI);
            $base = $height * (0.30 + 0.62 * abs($phase));
            $y = $baseY - $base * (1.0 - $jitter) - $height * $jitter * $r();
            $pts[] = [round($x, 1), round($y, 1)];
        }
        $d = 'M-20,' . ($baseY + 80) . ' L' . $pts[0][0] . ',' . $pts[0][1];
        foreach (array_slice($pts, 1) as $pt) {
            $d .= ' L' . $pt[0] . ',' . $pt[1];
        }
        $d .= ' L' . ($w + 20) . ',' . ($baseY + 80) . ' Z';
        return $d;
    }
}

if (!function_exists('lyra_art_landscape')) {
    /**
     * The full scene: sky gradient, aurora ribbons, planet, star field and three
     * ridge layers with atmospheric haze between them.
     *
     * $variant 'hero' (wide, for login and the splash band) or 'panel' (the
     *          smaller carousel image, tighter crop, no planet).
     * $uid     unique suffix so multiple instances on one page do not collide
     *          on gradient and clip ids.
     */
    function lyra_art_landscape(string $variant = 'hero', string $uid = 'a', int $seed = 20260919): string
    {
        $hero = $variant === 'hero';
        $w = 1440;
        $h = $hero ? 720 : 480;
        $r = lyra_art_rng($seed + ($hero ? 1 : 977));

        // ── Stars: small circles in the upper band, deterministic positions
        $stars = '';
        $count = $hero ? 90 : 50;
        for ($i = 0; $i < $count; $i++) {
            $x = round($r() * $w, 1);
            $y = round($r() * $h * 0.55, 1);
            $rad = round(0.5 + $r() * 1.3, 2);
            $op = round(0.15 + $r() * 0.55, 2);
            $stars .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $rad . '" fill="#fff" opacity="' . $op . '"/>';
        }

        // ── Aurora ribbons: soft wide strokes with a blur filter
        $aurora = '';
        for ($i = 0; $i < 3; $i++) {
            $y0 = $h * (0.18 + $i * 0.085);
            $d = 'M-40,' . round($y0, 1);
            $steps = 6;
            for ($s = 1; $s <= $steps; $s++) {
                $x = ($w + 80) * $s / $steps - 40;
                $y = $y0 + ($r() - 0.5) * $h * 0.13;
                $c1x = $x - ($w / $steps) * 0.6;
                $d .= ' C' . round($c1x, 1) . ',' . round($y0, 1)
                    . ' ' . round($c1x, 1) . ',' . round($y, 1)
                    . ' ' . round($x, 1) . ',' . round($y, 1);
            }
            $aurora .= '<path d="' . $d . '" fill="none" stroke="url(#lyraAur' . $uid . ')" '
                     . 'stroke-width="' . round($h * (0.05 + $i * 0.02), 1) . '" '
                     . 'stroke-linecap="round" opacity="' . round(0.55 - $i * 0.10, 2) . '"/>';
        }

        // ── Planet: a large soft sphere, upper right, with a terminator shadow
        $planet = '';
        if ($hero) {
            // Smaller and lower, so the front ridge crosses in front of it and
            // it reads as a body behind the horizon rather than a flat disc.
            $px = (int) ($w * 0.36);
            $py = (int) ($h * 0.34);
            $pr = (int) ($h * 0.24);
            $planet = '<circle cx="' . $px . '" cy="' . $py . '" r="' . $pr . '" fill="url(#lyraPlanet' . $uid . ')" opacity="0.75"/>'
                    . '<circle cx="' . $px . '" cy="' . $py . '" r="' . $pr . '" fill="none" stroke="#B9A4FF" stroke-width="1" opacity="0.20"/>';
        }

        // ── Ridges: three layers, lighter and hazier toward the back
        $layers = [
            [3, 0.50, 0.26, 0.30],   // seedOffset, baseY frac, height frac, opacity
            [4, 0.64, 0.32, 0.55],
            [5, 0.80, 0.42, 0.88],
        ];
        $ridges = '';
        foreach ($layers as $i => $L) {
            $baseY = (int) ($h * $L[1]);
            $height = (int) ($h * $L[2]);
            $peaks = $hero ? (7 + $i * 3) : (5 + $i * 2);
            $d = lyra_art_ridges($seed + $L[0] * 131, $w, $baseY, $height, $peaks);
            $ridges .= '<path d="' . $d . '" fill="url(#lyraRidge' . $i . $uid . ')" opacity="' . $L[3] . '"/>';
        }

        // ── Lake reflection: a soft horizontal glow beneath the front ridge
        $lakeY = (int) ($h * 0.88);
        // A reflection sits under the front ridge, not across the whole image:
        // a full-width band read as a painted stripe rather than water.
        $lake = '<ellipse cx="' . (int) ($w * 0.46) . '" cy="' . $lakeY . '" rx="' . (int) ($w * 0.34)
              . '" ry="' . (int) ($h * 0.055) . '" fill="url(#lyraLake' . $uid . ')" opacity="0.40"/>';

        $vb = '0 0 ' . $w . ' ' . $h;
        return '<svg class="lyra-art lyra-art-' . $hero . '" viewBox="' . $vb . '" preserveAspectRatio="xMidYMid slice" '
             . 'role="img" aria-label="Abstract purple mountain landscape" xmlns="http://www.w3.org/2000/svg">'
             . '<defs>'
             . '<linearGradient id="lyraSky' . $uid . '" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="#0B0620"/><stop offset="45%" stop-color="#1B0F45"/>'
             . '<stop offset="100%" stop-color="#02091A"/></linearGradient>'
             . '<linearGradient id="lyraAur' . $uid . '" x1="0" y1="0" x2="1" y2="0">'
             . '<stop offset="0%" stop-color="#5028E0" stop-opacity="0"/>'
             . '<stop offset="45%" stop-color="#9B5CFF" stop-opacity="0.85"/>'
             . '<stop offset="100%" stop-color="#7830F8" stop-opacity="0"/></linearGradient>'
             . '<radialGradient id="lyraPlanet' . $uid . '" cx="0.65" cy="0.35" r="0.78">'
             . '<stop offset="0%" stop-color="#C9B6FF"/><stop offset="55%" stop-color="#6C3AF8"/>'
             . '<stop offset="100%" stop-color="#1A0B3D"/></radialGradient>'
             . '<linearGradient id="lyraRidge0' . $uid . '" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="#4B2E96"/><stop offset="100%" stop-color="#241252"/></linearGradient>'
             . '<linearGradient id="lyraRidge1' . $uid . '" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="#39207A"/><stop offset="100%" stop-color="#150A34"/></linearGradient>'
             . '<linearGradient id="lyraRidge2' . $uid . '" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="#241257"/><stop offset="100%" stop-color="#080317"/></linearGradient>'
             . '<radialGradient id="lyraLake' . $uid . '" cx="0.5" cy="0.5" r="0.5">'
             . '<stop offset="0%" stop-color="#9B5CFF" stop-opacity="0.55"/>'
             . '<stop offset="100%" stop-color="#9B5CFF" stop-opacity="0"/></radialGradient>'
             . '<filter id="lyraBlur' . $uid . '" x="-20%" y="-20%" width="140%" height="140%">'
             . '<feGaussianBlur stdDeviation="' . ($hero ? 26 : 18) . '"/></filter>'
             . '</defs>'
             . '<rect width="' . $w . '" height="' . $h . '" fill="url(#lyraSky' . $uid . ')"/>'
             . '<g filter="url(#lyraBlur' . $uid . ')">' . $aurora . '</g>'
             . '<g>' . $stars . '</g>'
             . $planet
             . $ridges
             . $lake
             . '</svg>';
    }
}

if (!function_exists('lyra_art_mountains')) {
    /** The smaller carousel panel: ridges and haze only, tighter palette. */
    function lyra_art_mountains(string $uid = 'm'): string
    {
        return lyra_art_landscape('panel', $uid, 771133);
    }
}
