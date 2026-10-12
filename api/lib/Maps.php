<?php

declare(strict_types=1);

/**
 * Map courses (Stage 9): road graphs extracted from map images
 * (api/tools/extract_roads.php → api/maps/<slug>.json) and the loops horses
 * races run through them.
 *
 * Every race picks a fresh loop from its own seed: a random road, then the
 * shortest way back round on randomly weighted roads — half the time by way
 * of a random junction, for bigger loops — keeping the candidate nearest a
 * seeded target length and never one that doubles back on itself. A loop
 * through a planar road graph never crosses itself. It is frozen into the
 * race state (`race.course`), so a race replays the same and later map
 * edits cannot touch it; every rematch rides somewhere new.
 *
 * Horses keep a steady pace across a map: the map's size (its sidecar's
 * "size" or "secondsAcross") sets how long crossing its width takes, so a
 * longer loop — or a bigger map — is a longer race.
 */
final class Maps
{
    /** Shortest and longest loop, in map widths. */
    private const MIN_LOOP = 1.2;
    private const MAX_LOOP = 3.0;
    /** Loops tried per race; the one nearest a seeded target length wins. */
    private const TRIES = 60;
    /** A junction turning back by more than this many degrees spoils a loop. */
    private const HAIRPIN = 150.0;
    /** Bends tighter than this share of the map's width count as turns. */
    private const TURN_RADIUS = 0.1;
    /** Seconds to cross the map's width at a steady canter, by map size. */
    public const SIZES = ['small' => 19.0, 'medium' => 38.0, 'large' => 57.0];

    /** @var array<string, ?array> graphs already read this request */
    private static array $cache = [];

    /** Whether a course slug names a map course ("map:<slug>" or "map:*"). */
    public static function isMap(string $track): bool
    {
        return str_starts_with($track, 'map:');
    }

    /** Every map course: [{slug: "map:<slug>", name, loop}], plus "map:*" when there are two or more. */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $g = self::graph(basename($file, '.json'));
            if ($g !== null) {
                $out[] = ['slug' => 'map:' . $g['slug'], 'name' => $g['name'] . ' — a new road each race', 'loop' => true];
            }
        }
        if (count($out) > 1) {
            $out[] = ['slug' => 'map:*', 'name' => 'Any map — a new road each race', 'loop' => true];
        }
        return $out;
    }

    /** Whether "map:<slug>" names a map with a graph ("map:*": whether any does). */
    public static function exists(string $track): bool
    {
        $slug = substr($track, 4);
        return $slug === '*' ? self::all() !== [] : self::graph($slug) !== null;
    }

    /** A map's road graph {slug, name, image, width, height, secondsAcross, nodes, edges}, or null. */
    public static function graph(string $slug): ?array
    {
        if (array_key_exists($slug, self::$cache)) {
            return self::$cache[$slug];
        }
        self::$cache[$slug] = null;
        if (preg_match('/^[a-z0-9_]+$/', $slug) !== 1 || !is_file(self::dir() . "/$slug.json")) {
            return null;
        }
        $g = json_decode((string) file_get_contents(self::dir() . "/$slug.json"), true);
        if (!is_array($g) || empty($g['edges']) || empty($g['width'])) {
            return null;
        }
        $g['slug'] = $slug;
        return self::$cache[$slug] = $g;
    }

    /**
     * The course a race runs on "map:<slug>" (or "map:*", any map), picked
     * from $seed: {track, map, name, image, viewBox, loop, length,
     * turnShare, segments, points, baseSpeed, lapSeconds} — `points` thinned
     * for clients as Tracks::clientPlot makes them, `baseSpeed` the laps per
     * second of a steady canter on this loop. Null when no loop can be found.
     */
    public static function course(string $track, int $seed): ?array
    {
        $state = RaceEngine::i32($seed ^ 0x2545F491); // a stream of its own: the horses' draws stay put
        $slug = substr($track, 4);
        if ($slug === '*') {
            $maps = array_values(array_filter(self::all(), fn (array $m): bool => $m['slug'] !== 'map:*'));
            if ($maps === []) {
                return null;
            }
            $slug = substr($maps[(int) floor(RaceEngine::prngNext($state) * count($maps))]['slug'], 4);
        }
        $g = self::graph($slug);
        if ($g === null) {
            return null;
        }
        $loop = self::pickLoop($g, $state);
        if ($loop === null) {
            return null;
        }
        $W = (float) max($g['width'], $g['height']);
        $ring = self::chaikin(self::loopPoints($g, $loop));
        $opt = ['step' => 1.0, 'turnRate' => 1 / (self::TURN_RADIUS * $W), 'minRun' => 0.03 * $W, 'span' => 5];
        $ring[] = $ring[0];
        $plot = Tracks::plot($ring, true, $opt);
        if ($plot === null) {
            return null;
        }
        // start (and finish) at the head of the longest straight: room for the grid
        $start = 0.0;
        $longest = -1.0;
        foreach ($plot['segments'] as $seg) {
            if ($seg['kind'] === 'straight' && $seg['to'] - $seg['from'] > $longest) {
                $longest = $seg['to'] - $seg['from'];
                $start = $seg['from'];
            }
        }
        $pts = $plot['points'];
        $shift = (int) round($start * count($pts)) % count($pts);
        $pts = array_merge(array_slice($pts, $shift), array_slice($pts, 0, $shift));
        $pts[] = $pts[0];
        $plot = Tracks::plot($pts, true, $opt);
        if ($plot === null) {
            return null;
        }
        $client = Tracks::clientPlot(['loop' => true] + $plot);
        // a steady pace: the map's width takes secondsAcross at a canter
        $across = (float) ($g['secondsAcross'] ?? self::SIZES['medium']);
        $lapSeconds = $plot['length'] / $W * $across;
        return [
            'track' => 'map:' . $slug,
            'map' => $slug,
            'name' => $g['name'],
            'image' => $g['image'],
            'viewBox' => '0 0 ' . $g['width'] . ' ' . $g['height'],
            'loop' => true,
            'length' => $client['length'],
            'turnShare' => $client['turnShare'],
            'segments' => $plot['segments'],
            'points' => $client['points'],
            'baseSpeed' => 1 / $lapSeconds,
            'lapSeconds' => round($lapSeconds, 1),
        ];
    }

    /**
     * A loop through the graph as [[edge, forward?], …], drawn from $state:
     * a seeded target length first, then per try a random road, randomly
     * weighted roads, and half the time a random junction to pass through.
     * Loops outside MIN_LOOP..MAX_LOOP map widths only win when nothing
     * else does; loops that turn back on themselves never do.
     */
    private static function pickLoop(array $g, int &$state): ?array
    {
        $E = $g['edges'];
        $nodes = count($g['nodes']);
        $W = (float) max($g['width'], $g['height']);
        $min = self::MIN_LOOP * $W;
        $max = self::MAX_LOOP * $W;
        $adj = [];
        foreach ($E as $k => $e) {
            $adj[$e['a']][] = [$k, true];
            $adj[$e['b']][] = [$k, false];
        }
        $target = $min + RaceEngine::prngNext($state) * ($max - $min);
        $best = null;
        $bestScore = INF;
        for ($t = 0; $t < self::TRIES; $t++) {
            $k0 = (int) floor(RaceEngine::prngNext($state) * count($E));
            $e0 = $E[$k0];
            $w = [];
            foreach ($E as $e) {
                $w[] = $e['length'] * (0.5 + RaceEngine::prngNext($state));
            }
            $detour = RaceEngine::prngNext($state) < 0.5;
            $way = (int) floor(RaceEngine::prngNext($state) * $nodes);
            if ($e0['a'] === $e0['b']) {
                $loop = [[$k0, true]];
            } else {
                if ($detour && $way !== $e0['a'] && $way !== $e0['b']) {
                    $p1 = self::dijkstra($E, $adj, $w, $e0['b'], $way, $k0, [$e0['a'] => true]);
                    if ($p1 === null) {
                        continue;
                    }
                    $used = array_fill_keys(self::nodesOf($E, $p1, $e0['b']), true);
                    unset($used[$way]);
                    $p2 = self::dijkstra($E, $adj, $w, $way, $e0['a'], $k0, $used);
                    if ($p2 === null) {
                        continue;
                    }
                    $path = array_merge($p1, $p2);
                } else {
                    $path = self::dijkstra($E, $adj, $w, $e0['b'], $e0['a'], $k0, []);
                    if ($path === null) {
                        continue;
                    }
                }
                $loop = array_merge([[$k0, true]], $path);
            }
            $length = 0.0;
            foreach ($loop as [$k]) {
                $length += $E[$k]['length'];
            }
            if (count($loop) > 1) {
                $worst = 0.0;
                foreach ($loop as $i => [$k, $fwd]) {
                    [$k2, $fwd2] = $loop[($i + 1) % count($loop)];
                    $worst = max($worst, self::turnAt(self::oriented($E[$k], $fwd), self::oriented($E[$k2], $fwd2)));
                }
                if ($worst > self::HAIRPIN) {
                    continue;
                }
            }
            $score = abs($length - $target) + ($length >= $min && $length <= $max ? 0 : 10000);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $loop;
            }
        }
        return $best;
    }

    /**
     * Cheapest path $from → $to on weights $w as [[edge, forward?], …],
     * never using $banned or passing through $avoid (except at $to); null
     * when there is none. The graphs are small, so a plain O(V²) search.
     */
    private static function dijkstra(array $E, array $adj, array $w, int $from, int $to, int $banned, array $avoid): ?array
    {
        $dist = [$from => 0.0];
        $prev = [];
        $done = [];
        while (true) {
            $u = null;
            foreach ($dist as $n => $d) {
                if (!isset($done[$n]) && ($u === null || $d < $dist[$u])) {
                    $u = $n;
                }
            }
            if ($u === null || $u === $to) {
                break;
            }
            $done[$u] = true;
            foreach ($adj[$u] ?? [] as [$k, $fwd]) {
                if ($k === $banned) {
                    continue;
                }
                $m = $fwd ? $E[$k]['b'] : $E[$k]['a'];
                if (isset($avoid[$m]) && $m !== $to) {
                    continue;
                }
                $nd = $dist[$u] + $w[$k];
                if (!isset($dist[$m]) || $nd < $dist[$m]) {
                    $dist[$m] = $nd;
                    $prev[$m] = [$u, $k, $fwd];
                }
            }
        }
        if ($to !== $from && !isset($prev[$to])) {
            return null;
        }
        $path = [];
        for ($n = $to; $n !== $from; $n = $prev[$n][0]) {
            $path[] = [$prev[$n][1], $prev[$n][2]];
        }
        return array_reverse($path);
    }

    /** The junctions a path from $start visits, in order. */
    private static function nodesOf(array $E, array $path, int $start): array
    {
        $out = [$start];
        foreach ($path as [$k, $fwd]) {
            $out[] = $fwd ? $E[$k]['b'] : $E[$k]['a'];
        }
        return $out;
    }

    /** A road's points in the direction it is ridden. */
    private static function oriented(array $e, bool $fwd): array
    {
        return $fwd ? $e['points'] : array_reverse($e['points']);
    }

    /** Degrees a rider turns at a junction: along $in's last ~6 px, then $out's first. */
    private static function turnAt(array $in, array $out): float
    {
        $i = count($in) - 1;
        $d = 0.0;
        while ($i > 0 && $d < 6) {
            $d += hypot($in[$i][0] - $in[$i - 1][0], $in[$i][1] - $in[$i - 1][1]);
            $i--;
        }
        $back = atan2($in[count($in) - 1][1] - $in[$i][1], $in[count($in) - 1][0] - $in[$i][0]);
        $j = 0;
        $d = 0.0;
        while ($j < count($out) - 1 && $d < 6) {
            $d += hypot($out[$j + 1][0] - $out[$j][0], $out[$j + 1][1] - $out[$j][1]);
            $j++;
        }
        $fwd = atan2($out[$j][1] - $out[0][1], $out[$j][0] - $out[0][0]);
        return rad2deg(abs(fmod($fwd - $back + 3 * M_PI, 2 * M_PI) - M_PI));
    }

    /** The loop's roads joined into one ring of points (no repeated first point). */
    private static function loopPoints(array $g, array $loop): array
    {
        $pts = [];
        foreach ($loop as [$k, $fwd]) {
            $pl = self::oriented($g['edges'][$k], $fwd);
            $pts = $pts === [] ? $pl : array_merge($pts, array_slice($pl, 1));
        }
        $last = $pts[count($pts) - 1];
        if (count($pts) > 1 && hypot($last[0] - $pts[0][0], $last[1] - $pts[0][1]) < 1e-6) {
            array_pop($pts);
        }
        return $pts;
    }

    /** One round of Chaikin corner cutting on a closed ring: clean, still jagged. */
    private static function chaikin(array $pts): array
    {
        $out = [];
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            [$px, $py] = $pts[$i];
            [$qx, $qy] = $pts[($i + 1) % $n];
            $out[] = [0.75 * $px + 0.25 * $qx, 0.75 * $py + 0.25 * $qy];
            $out[] = [0.25 * $px + 0.75 * $qx, 0.25 * $py + 0.75 * $qy];
        }
        return $out;
    }

    /** Where the road graphs live. */
    private static function dir(): string
    {
        return dirname(__DIR__) . '/maps';
    }
}
