<?php

declare(strict_types=1);

/**
 * Road extraction for map courses (Stage 9) — CLI only. Run it in the
 * container and redirect its output into the repo:
 *
 *   docker compose exec -T web php api/tools/extract_roads.php <slug> > api/maps/<slug>.json
 *   docker compose exec -T web php api/tools/extract_roads.php <slug> --preview > roads.png
 *
 * Reads maps/<slug>.avif, .webp or .png from the docroot — public/maps/ in the
 * repo — and its sidecar maps/<slug>.json ({name, size, secondsAcross,
 * greyMin, greyMax, opacityMin}), finds the roads — light ink that is
 * fairly opaque — and
 * prints their graph of junctions and roads as JSON, or with --preview a
 * PNG of the graph over the dimmed map to check by eye.
 *
 * Big images are first shrunk to about WORK px across by averaging blocks of
 * pixels (a 1920-px map's roads then match a 512-px one's, and PHP's memory
 * holds); the graph is in those working units and its width/height say so —
 * the race view lays the full image over the same box.
 *
 * Steps: mask → close one-pixel gaps → keep the sizeable networks → thin to
 * one-pixel centre lines (Zhang–Suen) → junctions (crossing number ≥ 3, or
 * dead ends), grown by a pixel so roads leaving one junction never fuse →
 * each run of line pixels between junctions is one road, ordered by a
 * shortest walk → prune dead ends, junction clutter and tiny rings → join
 * roads through pass-through junctions → simplify each road.
 * Needs GD with WebP (see the Dockerfile).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$slug = $argv[1] ?? '';
$preview = in_array('--preview', $argv, true);
if (preg_match('/^[a-z0-9_]+$/', $slug) !== 1) {
    fwrite(STDERR, "usage: php api/tools/extract_roads.php <slug> [--preview]\n");
    exit(1);
}
const WORK = 640; // working resolution, px across
$dir = dirname(__DIR__, 2) . '/maps'; // the docroot's maps/ (public/maps in the repo)
$file = null;
foreach (['avif', 'webp', 'png'] as $ext) {
    if ($file === null && is_file("$dir/$slug.$ext")) {
        $file = "$dir/$slug.$ext";
    }
}
if ($file === null) {
    fwrite(STDERR, "no map image at $dir/$slug.avif, .webp or .png\n");
    exit(1);
}
$reader = 'imagecreatefrom' . pathinfo($file, PATHINFO_EXTENSION);
if (!function_exists($reader)) {
    fwrite(STDERR, "GD cannot read ." . pathinfo($file, PATHINFO_EXTENSION) . " — rebuild the image (docker compose up --build)\n");
    exit(1);
}
$side = is_file("$dir/$slug.json") ? (json_decode((string) file_get_contents("$dir/$slug.json"), true) ?: []) : [];
$P = $side + [
    'name' => ucwords(str_replace('_', ' ', $slug)),
    // how big the map rides: small ≈ 40 s to the winner over a typical loop,
    // large ≈ 2 min; secondsAcross (a canter across the map's width) wins
    'size' => 'medium',
    'greyMin' => 98,     // raw (alpha-free) grey of the roads …
    'greyMax' => 140,
    'opacityMin' => 80,  // … where the ink is at least this opaque (0–255)
    'merge' => 6,        // junctions closer than this many px become one
    'minRing' => 40,     // rings through a single junction shorter than this go
    'simplify' => 0.8,   // Douglas–Peucker tolerance, px
];

$im = $reader($file);
if ($im === false) {
    fwrite(STDERR, "cannot read $file\n");
    exit(1);
}
// raw grey (alpha ignored) and opacity, averaged over f×f blocks when big
$f = max(1, (int) ceil(max(imagesx($im), imagesy($im)) / WORK));
$W = intdiv(imagesx($im), $f);
$H = intdiv(imagesy($im), $f);
$grey = [];
$opac = [];
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        $g = 0.0;
        $o = 0.0;
        for ($by = 0; $by < $f; $by++) {
            for ($bx = 0; $bx < $f; $bx++) {
                $c = imagecolorat($im, $x * $f + $bx, $y * $f + $by);
                $g += 0.2126 * (($c >> 16) & 0xFF) + 0.7152 * (($c >> 8) & 0xFF) + 0.0722 * ($c & 0xFF);
                $o += (127 - (($c >> 24) & 0x7F)) * 255 / 127; // GD alpha: 0 opaque … 127 transparent
            }
        }
        $grey[] = (int) round($g / ($f * $f));
        $opac[] = (int) round($o / ($f * $f));
    }
}
imagedestroy($im);

// --- mask, close, largest network -------------------------------------------
$N8 = [[0, -1], [1, -1], [1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1]]; // P2..P9 from north
$m = [];
for ($i = 0; $i < $W * $H; $i++) {
    $m[] = ($grey[$i] >= $P['greyMin'] && $grey[$i] <= $P['greyMax'] && $opac[$i] >= $P['opacityMin']) ? 1 : 0;
}
$morph = function (array $src, bool $all) use ($W, $H): array {
    $out = array_fill(0, $W * $H, 0);
    for ($y = 1; $y < $H - 1; $y++) {
        for ($x = 1; $x < $W - 1; $x++) {
            $s = 0;
            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    $s += $src[($y + $dy) * $W + $x + $dx];
                }
            }
            $out[$y * $W + $x] = ($all ? $s === 9 : $s > 0) ? 1 : 0;
        }
    }
    return $out;
};
$m = $morph($morph($m, false), true); // close: dilate, then erode

// road networks: every 8-connected piece at least a fifth the largest's size
// (a river or a wall can split a map's roads into a few separate networks)
$seen = array_fill(0, $W * $H, 0);
$pieces = [];
for ($i = 0; $i < $W * $H; $i++) {
    if (!$m[$i] || $seen[$i]) {
        continue;
    }
    $comp = [];
    $stack = [$i];
    $seen[$i] = 1;
    while ($stack) {
        $j = array_pop($stack);
        $comp[] = $j;
        $x = $j % $W;
        $y = intdiv($j, $W);
        foreach ($N8 as [$dx, $dy]) {
            $k = ($y + $dy) * $W + $x + $dx;
            if ($x + $dx >= 0 && $x + $dx < $W && $y + $dy >= 0 && $y + $dy < $H && $m[$k] && !$seen[$k]) {
                $seen[$k] = 1;
                $stack[] = $k;
            }
        }
    }
    $pieces[] = $comp;
}
$top = max(array_map('count', $pieces ?: [[]]));
$best = [];
foreach ($pieces as $comp) {
    if (count($comp) >= 0.2 * $top) {
        array_push($best, ...$comp);
    }
}
$m = array_fill(0, $W * $H, 0);
foreach ($best as $j) {
    $m[$j] = 1;
}

// --- Zhang–Suen thinning -----------------------------------------------------
$around = function (int $j) use (&$m, $W, $N8): array {
    $x = $j % $W;
    $y = intdiv($j, $W);
    $p = [];
    foreach ($N8 as [$dx, $dy]) {
        $p[] = $m[($y + $dy) * $W + $x + $dx];
    }
    return $p;
};
$pts = $best;
sort($pts);
do {
    $changed = false;
    foreach ([0, 1] as $step) {
        $kill = [];
        foreach ($pts as $j) {
            if (!$m[$j]) {
                continue;
            }
            $p = $around($j);
            $b = array_sum($p);
            if ($b < 2 || $b > 6) {
                continue;
            }
            $a = 0;
            for ($k = 0; $k < 8; $k++) {
                $a += ($p[$k] === 0 && $p[($k + 1) % 8] === 1) ? 1 : 0;
            }
            if ($a !== 1) {
                continue;
            }
            [$p2, , $p4, , $p6, , $p8] = $p;
            if ($step === 0 && ($p2 * $p4 * $p6 || $p4 * $p6 * $p8)) {
                continue;
            }
            if ($step === 1 && ($p2 * $p4 * $p8 || $p2 * $p6 * $p8)) {
                continue;
            }
            $kill[] = $j;
        }
        foreach ($kill as $j) {
            $m[$j] = 0;
        }
        $changed = $changed || $kill !== [];
    }
    $pts = array_values(array_filter($pts, fn (int $j): bool => $m[$j] === 1));
} while ($changed);

// --- junctions and roads -----------------------------------------------------
$nbrs = function (int $j) use (&$m, $W, $N8): array {
    $x = $j % $W;
    $y = intdiv($j, $W);
    $out = [];
    foreach ($N8 as [$dx, $dy]) {
        $k = ($y + $dy) * $W + $x + $dx;
        if ($m[$k]) {
            $out[] = $k;
        }
    }
    return $out;
};
$node = [];
foreach ($pts as $j) {
    $p = $around($j);
    $cross = 0;
    for ($k = 0; $k < 8; $k++) {
        $cross += ($p[$k] === 0 && $p[($k + 1) % 8] === 1) ? 1 : 0;
    }
    if (count($nbrs($j)) === 1 || $cross >= 3) {
        $node[$j] = true;
    }
}
$cl = [];
$cents = [];
foreach (array_keys($node) as $j) { // $pts is sorted, so these are in scan order
    if (isset($cl[$j])) {
        continue;
    }
    $cid = count($cents);
    $stack = [$j];
    $cl[$j] = $cid;
    $sx = $sy = 0;
    $n = 0;
    while ($stack) {
        $k = array_pop($stack);
        $sx += $k % $W;
        $sy += intdiv($k, $W);
        $n++;
        foreach ($nbrs($k) as $r) {
            if (isset($node[$r]) && !isset($cl[$r])) {
                $cl[$r] = $cid;
                $stack[] = $r;
            }
        }
    }
    $cents[] = [$sx / $n, $sy / $n];
}
// junctions grow by one pixel, so the first pixels of two roads leaving the
// same junction never touch and fuse into one run
foreach (array_keys($node) as $j) {
    foreach ($nbrs($j) as $r) {
        if (!isset($node[$r]) && !isset($cl[$r])) {
            $cl[$r] = $cl[$j];
        }
    }
}
$node = array_fill_keys(array_keys($cl), true);

$edges = []; // [a, b, pixel path]
$lab = [];
foreach ($pts as $j) {
    if (isset($node[$j]) || isset($lab[$j])) {
        continue;
    }
    $comp = [];
    $stack = [$j];
    $lab[$j] = true;
    while ($stack) {
        $k = array_pop($stack);
        $comp[] = $k;
        foreach ($nbrs($k) as $r) {
            if (!isset($node[$r]) && !isset($lab[$r])) {
                $lab[$r] = true;
                $stack[] = $r;
            }
        }
    }
    $inRun = array_fill_keys($comp, true);
    $by = []; // junction → the run's pixels touching it
    foreach ($comp as $k) {
        foreach ($nbrs($k) as $r) {
            if (isset($node[$r]) && !in_array($k, $by[$cl[$r]] ?? [], true)) {
                $by[$cl[$r]][] = $k;
            }
        }
    }
    if ($by === []) {
        continue; // a ring with no junction: no route can join it
    }
    $bfs = function (int $from) use ($nbrs, $inRun): array {
        $prev = [$from => -1];
        $queue = [$from];
        for ($h = 0; $h < count($queue); $h++) {
            foreach ($nbrs($queue[$h]) as $r) {
                if (isset($inRun[$r]) && !isset($prev[$r])) {
                    $prev[$r] = $queue[$h];
                    $queue[] = $r;
                }
            }
        }
        return $prev;
    };
    $walk = function (array $prev, int $to): array {
        $out = [];
        while ($to !== -1) {
            $out[] = $to;
            $to = $prev[$to];
        }
        return array_reverse($out);
    };
    $cls = array_keys($by);
    $a = $cls[0];
    $prev = $bfs($by[$a][0]);
    if (count($cls) === 1) {
        // one junction at both ends: a ring through it (or a stub)
        $far = $by[$a][0];
        foreach ($by[$a] as $k) {
            if (isset($prev[$k]) && count($walk($prev, $k)) > count($walk($prev, $far))) {
                $far = $k;
            }
        }
        $edges[] = [$a, $a, $walk($prev, $far)];
    } else {
        foreach (array_slice($cls, 1) as $b) {
            foreach ($by[$b] as $k) {
                if (isset($prev[$k])) {
                    $edges[] = [$a, $b, $walk($prev, $k)];
                    break;
                }
            }
        }
    }
}
// junctions touching directly (no line pixels between) are joined too
$pairs = [];
foreach (array_keys($node) as $j) {
    foreach ($nbrs($j) as $r) {
        if (isset($node[$r]) && $cl[$r] !== $cl[$j]) {
            $key = min($cl[$r], $cl[$j]) . '-' . max($cl[$r], $cl[$j]);
            if (!isset($pairs[$key])) {
                $pairs[$key] = true;
                $edges[] = [min($cl[$r], $cl[$j]), max($cl[$r], $cl[$j]), []];
            }
        }
    }
}

// roads as point lists from junction centre to junction centre
$E = [];
foreach ($edges as [$a, $b, $path]) {
    $pl = [$cents[$a]];
    foreach ($path as $k) {
        $pl[] = [$k % $W, intdiv($k, $W)];
    }
    $pl[] = $cents[$b];
    $E[] = ['a' => $a, 'b' => $b, 'points' => $pl];
}
$nodes = $cents;
$length = function (array $pl): float {
    $s = 0.0;
    for ($i = 1; $i < count($pl); $i++) {
        $s += hypot($pl[$i][0] - $pl[$i - 1][0], $pl[$i][1] - $pl[$i - 1][1]);
    }
    return $s;
};

// --- prune -------------------------------------------------------------------
do {
    $changed = false;
    // 1. dead ends go: no loop can use them
    $deg = array_fill(0, count($nodes), 0);
    foreach ($E as $e) {
        $deg[$e['a']]++;
        $deg[$e['b']]++;
    }
    $keep = array_values(array_filter($E, fn (array $e): bool => $deg[$e['a']] > 1 && $deg[$e['b']] > 1));
    if (count($keep) !== count($E)) {
        $E = $keep;
        $changed = true;
        continue;
    }
    // 2. very short roads contract (junction clutter)
    foreach ($E as $idx => $e) {
        if ($e['a'] !== $e['b'] && $length($e['points']) < $P['merge']) {
            [$a, $b] = [$e['a'], $e['b']];
            $nodes[$a] = [($nodes[$a][0] + $nodes[$b][0]) / 2, ($nodes[$a][1] + $nodes[$b][1]) / 2];
            unset($E[$idx]);
            $E = array_values($E);
            foreach ($E as &$f) {
                if ($f['a'] === $b) {
                    $f['a'] = $a;
                }
                if ($f['b'] === $b) {
                    $f['b'] = $a;
                }
                if ($f['a'] === $a) {
                    $f['points'][0] = $nodes[$a];
                }
                if ($f['b'] === $a) {
                    $f['points'][count($f['points']) - 1] = $nodes[$a];
                }
            }
            unset($f);
            $changed = true;
            continue 2;
        }
    }
    // 3. tiny rings go
    $keep = array_values(array_filter($E, fn (array $e): bool => !($e['a'] === $e['b'] && $length($e['points']) < $P['minRing'])));
    if (count($keep) !== count($E)) {
        $E = $keep;
        $changed = true;
        continue;
    }
    // 4. a junction where just two roads meet joins them into one road
    $ends = [];
    foreach ($E as $idx => $e) {
        $ends[$e['a']][] = [$idx, 'a'];
        $ends[$e['b']][] = [$idx, 'b'];
    }
    foreach ($ends as $list) {
        if (count($list) === 2 && $list[0][0] !== $list[1][0]) {
            [[$i1, $s1], [$i2, $s2]] = $list;
            $p1 = $s1 === 'b' ? $E[$i1]['points'] : array_reverse($E[$i1]['points']); // … → junction
            $p2 = $s2 === 'a' ? $E[$i2]['points'] : array_reverse($E[$i2]['points']); // junction → …
            $joined = [
                'a' => $s1 === 'b' ? $E[$i1]['a'] : $E[$i1]['b'],
                'b' => $s2 === 'a' ? $E[$i2]['b'] : $E[$i2]['a'],
                'points' => array_merge($p1, array_slice($p2, 1)),
            ];
            unset($E[$i1], $E[$i2]);
            $E = array_values($E);
            $E[] = $joined;
            $changed = true;
            continue 2;
        }
    }
} while ($changed);

// --- simplify and write --------------------------------------------------------
$simplify = function (array $pl, float $eps) use (&$simplify): array {
    if (count($pl) < 3) {
        return $pl;
    }
    [$x1, $y1] = $pl[0];
    [$x2, $y2] = $pl[count($pl) - 1];
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $len = hypot($dx, $dy);
    $far = -1.0;
    $idx = 0;
    for ($i = 1; $i < count($pl) - 1; $i++) {
        $d = $len > 0
            ? abs($dy * $pl[$i][0] - $dx * $pl[$i][1] + $x2 * $y1 - $y2 * $x1) / $len
            : hypot($pl[$i][0] - $x1, $pl[$i][1] - $y1);
        if ($d > $far) {
            $far = $d;
            $idx = $i;
        }
    }
    if ($far <= $eps) {
        return [$pl[0], $pl[count($pl) - 1]];
    }
    return array_merge(
        array_slice($simplify(array_slice($pl, 0, $idx + 1), $eps), 0, -1),
        $simplify(array_slice($pl, $idx), $eps)
    );
};
$used = [];
foreach ($E as $e) {
    $used[$e['a']] = true;
    $used[$e['b']] = true;
}
ksort($used);
$remap = array_flip(array_keys($used));
$sizes = ['small' => 19.0, 'medium' => 38.0, 'large' => 57.0]; // as Maps::SIZES
$out = [
    'slug' => $slug,
    'name' => (string) $P['name'],
    'image' => 'maps/' . basename($file),
    'width' => $W,
    'height' => $H,
    'secondsAcross' => (float) ($P['secondsAcross'] ?? $sizes[$P['size']] ?? $sizes['medium']),
    'nodes' => array_map(fn (int $n): array => [round($nodes[$n][0], 1), round($nodes[$n][1], 1)], array_keys($used)),
    'edges' => [],
];
foreach ($E as $e) {
    $pl = $simplify($e['points'], (float) $P['simplify']);
    $out['edges'][] = [
        'a' => $remap[$e['a']],
        'b' => $remap[$e['b']],
        'points' => array_map(fn (array $p): array => [round($p[0], 1), round($p[1], 1)], $pl),
        'length' => round($length($pl), 1),
    ];
}

if (!$preview) {
    echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
    fwrite(STDERR, sprintf("%s: %d junctions, %d roads\n", $slug, count($out['nodes']), count($out['edges'])));
    exit(0);
}

// --preview: the graph over the dimmed map, roads red and junctions gold
$img = imagecreatetruecolor($W, $H);
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        $v = intdiv($grey[$y * $W + $x], 3);
        imagesetpixel($img, $x, $y, imagecolorallocate($img, $v, $v, $v));
    }
}
imagesetthickness($img, 2);
$red = imagecolorallocate($img, 240, 80, 60);
$gold = imagecolorallocate($img, 255, 220, 90);
foreach ($out['edges'] as $e) {
    for ($i = 1; $i < count($e['points']); $i++) {
        imageline($img, (int) $e['points'][$i - 1][0], (int) $e['points'][$i - 1][1], (int) $e['points'][$i][0], (int) $e['points'][$i][1], $red);
    }
}
foreach ($out['nodes'] as [$x, $y]) {
    imagefilledellipse($img, (int) $x, (int) $y, 7, 7, $gold);
}
imagepng($img);
