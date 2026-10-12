<?php

declare(strict_types=1);

/**
 * Courses for horses races (Stage 8; decided in stage-6 §6.4).
 *
 * Each course is an SVG centre line kept on the server under api/tracks/:
 * one <path id="centerline" d="…"> in a viewBox, its display name in the
 * root's data-name. This class plots it into what the sim and every client
 * share — a polyline, its length, and the table of straights and turns —
 * so the picture and the race always agree on where a horse is turning.
 *
 * The race starts and finishes at the path's first point and runs the way
 * the path runs; a path closed with Z loops (laps allowed). Only one
 * sub-path of M L H V C Q Z (absolute or relative) is read — anything else
 * refuses the course.
 */
final class Tracks
{
    /** Resampling spacing for the plot, in path units. */
    private const STEP = 2.0;
    /** Heading change per path unit above which the course turns (radius under 400). */
    private const TURN_RATE = 0.0025;
    /** Straight or turn runs shorter than this fold into their neighbours. */
    private const MIN_RUN = 30.0;
    /** Most points a client plot carries. */
    private const CLIENT_POINTS = 400;

    /** @var array<string, ?array> plots already made this request */
    private static array $cache = [];

    /** Every course in file-name order: [{slug, name, loop}]. */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.svg') ?: [] as $file) {
            $plot = self::load(basename($file, '.svg'));
            if ($plot !== null) {
                $out[] = ['slug' => $plot['slug'], 'name' => $plot['name'], 'loop' => $plot['loop']];
            }
        }
        return $out;
    }

    /**
     * The plot of one course, or null when the slug is unknown or the file
     * cannot be read: {slug, name, loop, viewBox, d, length, turnShare,
     * segments, points}. `segments` are [{kind: "straight"|"turn", from,
     * to}] as fractions of one lap; `points` are evenly spaced along the
     * line ([x, y] pairs, path units).
     */
    public static function load(string $slug): ?array
    {
        if (array_key_exists($slug, self::$cache)) {
            return self::$cache[$slug];
        }
        self::$cache[$slug] = null;
        if (preg_match('/^[a-z0-9_]+$/', $slug) !== 1) {
            return null;
        }
        $file = self::dir() . '/' . $slug . '.svg';
        if (!is_file($file)) {
            return null;
        }
        $dom = new DOMDocument();
        if (!@$dom->load($file, LIBXML_NONET)) {
            return null;
        }
        $root = $dom->documentElement;
        $node = (new DOMXPath($dom))->query('//*[@id="centerline"]')->item(0);
        if ($root === null || !($node instanceof DOMElement)) {
            return null;
        }
        $d = trim($node->getAttribute('d'));
        $flat = self::flatten($d);
        if ($flat === null) {
            return null;
        }
        [$raw, $loop] = $flat;
        [$cum, $length] = self::cumulative($raw);
        if ($length <= 0.0) {
            return null;
        }
        $count = max(2, (int) round($length / self::STEP));
        $points = self::resample($raw, $cum, $length, $count);
        $segments = self::segments($points, $length, $loop);
        $turn = 0.0;
        foreach ($segments as $seg) {
            if ($seg['kind'] === 'turn') {
                $turn += $seg['to'] - $seg['from'];
            }
        }
        $name = trim($root->getAttribute('data-name'));
        return self::$cache[$slug] = [
            'slug' => $slug,
            'name' => $name !== '' ? $name : $slug,
            'loop' => $loop,
            'viewBox' => trim($root->getAttribute('viewBox')),
            'd' => $d,
            'length' => $length,
            'turnShare' => $turn,
            'segments' => $segments,
            'points' => $points,
        ];
    }

    /**
     * What the `track` action sends: the plot with its points thinned to at
     * most CLIENT_POINTS, still evenly spaced, as a flat [x0, y0, x1, …]
     * list rounded to 0.1 — enough to draw and to place every horse.
     */
    public static function clientPlot(array $plot): array
    {
        $count = min(self::CLIENT_POINTS, count($plot['points']));
        $src = $plot['points'];
        if ($plot['loop']) {
            $src[] = $src[0]; // close the ring so the last gap resamples too
        }
        [$cum, $length] = self::cumulative($src);
        $flat = [];
        foreach (self::resample($src, $cum, $length, $count) as [$x, $y]) {
            $flat[] = round($x, 1);
            $flat[] = round($y, 1);
        }
        $out = $plot;
        $out['points'] = $flat;
        $out['length'] = round($plot['length'], 1);
        $out['turnShare'] = round($plot['turnShare'], 3);
        return $out;
    }

    /**
     * Mode, course and laps from a create_room / create_practice body:
     * [setup, null] on success, [null, message] otherwise. Classic keeps
     * just ['mode' => 'classic']; horses ('custom') adds a known course
     * slug and laps 1–3 — forced to 1 on a course that does not loop.
     */
    public static function setup(array $body): array
    {
        $mode = $body['mode'] ?? 'classic';
        if ($mode === 'classic') {
            return [['mode' => 'classic'], null];
        }
        if ($mode !== 'custom') {
            return [null, 'The rules of the race are Classic or Horses.'];
        }
        $slug = $body['track'] ?? 'hippodrome';
        $plot = is_string($slug) ? self::load($slug) : null;
        if ($plot === null) {
            return [null, 'No such course is known to the heralds.'];
        }
        $laps = $body['laps'] ?? 1;
        if (!is_int($laps) || $laps < 1 || $laps > 3) {
            return [null, 'A race runs one to three laps.'];
        }
        return [['mode' => 'custom', 'track' => $slug, 'laps' => $plot['loop'] ? $laps : 1], null];
    }

    /* --- plotting ------------------------------------------------------- */

    /**
     * Path data → [raw points, loops?]. Curves are sampled about every
     * path unit; lines keep their end points. Null on anything unreadable.
     */
    private static function flatten(string $d): ?array
    {
        preg_match_all('/[MmLlHhVvCcQqZz]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $d, $m);
        $tok = $m[0];
        $n = count($tok);
        $i = 0;
        $cmd = '';
        $x = $y = $sx = $sy = 0.0;
        $pts = [];
        $loop = false;
        // the next $k numbers as floats, or null when the path runs short
        $take = function (int $k) use (&$i, $tok, $n): ?array {
            if ($i + $k > $n) {
                return null;
            }
            $v = [];
            for ($j = 0; $j < $k; $j++) {
                if (!is_numeric($tok[$i + $j])) {
                    return null;
                }
                $v[] = (float) $tok[$i + $j];
            }
            $i += $k;
            return $v;
        };
        while ($i < $n) {
            if (ctype_alpha($tok[$i])) {
                if ($loop) {
                    return null; // nothing may follow the close
                }
                $cmd = $tok[$i];
                $i++;
                if ($cmd === 'Z' || $cmd === 'z') {
                    $loop = true;
                    $x = $sx;
                    $y = $sy;
                    continue;
                }
            } elseif ($cmd === '' || $loop) {
                return null;
            }
            $rel = ctype_lower($cmd);
            switch (strtoupper($cmd)) {
                case 'M':
                    if ($pts !== []) {
                        return null; // one sub-path only
                    }
                    if (($v = $take(2)) === null) {
                        return null;
                    }
                    [$x, $y] = $rel ? [$x + $v[0], $y + $v[1]] : $v;
                    $sx = $x;
                    $sy = $y;
                    $pts[] = [$x, $y];
                    $cmd = $rel ? 'l' : 'L'; // further pairs are lines
                    break;
                case 'L':
                    if (($v = $take(2)) === null) {
                        return null;
                    }
                    [$x, $y] = $rel ? [$x + $v[0], $y + $v[1]] : $v;
                    $pts[] = [$x, $y];
                    break;
                case 'H':
                    if (($v = $take(1)) === null) {
                        return null;
                    }
                    $x = $rel ? $x + $v[0] : $v[0];
                    $pts[] = [$x, $y];
                    break;
                case 'V':
                    if (($v = $take(1)) === null) {
                        return null;
                    }
                    $y = $rel ? $y + $v[0] : $v[0];
                    $pts[] = [$x, $y];
                    break;
                case 'C':
                case 'Q':
                    $k = strtoupper($cmd) === 'C' ? 6 : 4;
                    if ($pts === [] || ($v = $take($k)) === null) {
                        return null;
                    }
                    $ctrl = [[$x, $y]];
                    for ($j = 0; $j < $k; $j += 2) {
                        $ctrl[] = $rel ? [$x + $v[$j], $y + $v[$j + 1]] : [$v[$j], $v[$j + 1]];
                    }
                    foreach (self::sampleCurve($ctrl) as $pt) {
                        $pts[] = $pt;
                    }
                    [$x, $y] = $ctrl[count($ctrl) - 1];
                    break;
                default:
                    return null;
            }
        }
        if (count($pts) < 2) {
            return null;
        }
        if ($loop && hypot($pts[count($pts) - 1][0] - $sx, $pts[count($pts) - 1][1] - $sy) > 1e-6) {
            $pts[] = [$sx, $sy]; // Z draws the closing line
        }
        return [$pts, $loop];
    }

    /** Points along a quadratic (3 controls) or cubic (4) Bézier, start excluded. */
    private static function sampleCurve(array $c): array
    {
        $est = 0.0;
        for ($j = 1; $j < count($c); $j++) {
            $est += hypot($c[$j][0] - $c[$j - 1][0], $c[$j][1] - $c[$j - 1][1]);
        }
        $steps = max(8, (int) ceil($est));
        $out = [];
        for ($s = 1; $s <= $steps; $s++) {
            $u = $s / $steps;
            $w = 1 - $u;
            if (count($c) === 4) {
                $a = $w * $w * $w;
                $b = 3 * $w * $w * $u;
                $e = 3 * $w * $u * $u;
                $f = $u * $u * $u;
                $out[] = [
                    $a * $c[0][0] + $b * $c[1][0] + $e * $c[2][0] + $f * $c[3][0],
                    $a * $c[0][1] + $b * $c[1][1] + $e * $c[2][1] + $f * $c[3][1],
                ];
            } else {
                $a = $w * $w;
                $b = 2 * $w * $u;
                $f = $u * $u;
                $out[] = [
                    $a * $c[0][0] + $b * $c[1][0] + $f * $c[2][0],
                    $a * $c[0][1] + $b * $c[1][1] + $f * $c[2][1],
                ];
            }
        }
        return $out;
    }

    /** [cumulative distance per point, total length]. */
    private static function cumulative(array $pts): array
    {
        $cum = [0.0];
        for ($j = 1; $j < count($pts); $j++) {
            $cum[] = $cum[$j - 1] + hypot($pts[$j][0] - $pts[$j - 1][0], $pts[$j][1] - $pts[$j - 1][1]);
        }
        return [$cum, $cum[count($cum) - 1]];
    }

    /** $count points evenly spaced along the polyline, from its start (end excluded). */
    private static function resample(array $pts, array $cum, float $length, int $count): array
    {
        $out = [];
        $j = 0;
        $last = count($pts) - 1;
        for ($s = 0; $s < $count; $s++) {
            $target = $length * $s / $count;
            while ($j < $last - 1 && $cum[$j + 1] < $target) {
                $j++;
            }
            $seg = $cum[$j + 1] - $cum[$j];
            $u = $seg > 0 ? ($target - $cum[$j]) / $seg : 0.0;
            $out[] = [
                $pts[$j][0] + ($pts[$j + 1][0] - $pts[$j][0]) * $u,
                $pts[$j][1] + ($pts[$j + 1][1] - $pts[$j][1]) * $u,
            ];
        }
        return $out;
    }

    /**
     * Straight/turn table over one lap from evenly spaced points: a sample
     * turns when its heading changes faster than TURN_RATE; runs shorter
     * than MIN_RUN fold into the run before them (the first into the one
     * after), so a slight wobble never splits a straight.
     */
    private static function segments(array $pts, float $length, bool $loop): array
    {
        $n = count($pts);
        $ds = $length / $n;
        $head = [];
        for ($j = 0; $j < $n; $j++) {
            $next = $j + 1 < $n ? $pts[$j + 1] : ($loop ? $pts[0] : null);
            $head[] = $next === null
                ? $head[$j - 1]
                : atan2($next[1] - $pts[$j][1], $next[0] - $pts[$j][0]);
        }
        $runs = [];
        for ($j = 0; $j < $n; $j++) {
            $prev = $j > 0 ? $head[$j - 1] : ($loop ? $head[$n - 1] : $head[0]);
            $dh = fmod($head[$j] - $prev + 3 * M_PI, 2 * M_PI) - M_PI;
            $kind = abs($dh) / $ds > self::TURN_RATE ? 'turn' : 'straight';
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['kind'] === $kind) {
                $runs[$last]['to'] = ($j + 1) * $ds;
            } else {
                $runs[] = ['kind' => $kind, 'from' => $j * $ds, 'to' => ($j + 1) * $ds];
            }
        }
        $changed = true;
        while ($changed && count($runs) > 1) {
            $changed = false;
            for ($r = 0; $r < count($runs); $r++) {
                if ($runs[$r]['to'] - $runs[$r]['from'] < self::MIN_RUN) {
                    $runs[$r]['kind'] = $runs[$r > 0 ? $r - 1 : $r + 1]['kind'];
                    $changed = true;
                }
            }
            $merged = [];
            foreach ($runs as $run) {
                $last = count($merged) - 1;
                if ($last >= 0 && $merged[$last]['kind'] === $run['kind']) {
                    $merged[$last]['to'] = $run['to'];
                } else {
                    $merged[] = $run;
                }
            }
            $runs = $merged;
        }
        foreach ($runs as &$run) {
            $run['from'] /= $length;
            $run['to'] /= $length;
        }
        unset($run);
        $runs[count($runs) - 1]['to'] = 1.0; // the table always covers the lap
        return $runs;
    }

    /** Where the course files live. */
    private static function dir(): string
    {
        return dirname(__DIR__) . '/tracks';
    }
}
