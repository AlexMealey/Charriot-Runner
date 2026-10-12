<?php

declare(strict_types=1);

/**
 * Map courses (Stage 9): road graphs extracted from map images
 * (api/tools/extract_roads.php → api/maps/<slug>.json) and the loops horses
 * races run through them.
 *
 * Every race grows its loop from its own seed: a short first loop, then
 * road after road swapped for a detour through junctions the loop has not
 * visited, until it reaches a seeded length of at least COVER of the map's
 * longest loop (estimated once by the extractor, stored as `maxLoop`). A
 * loop through a planar road graph never crosses itself, and one that
 * doubles back at a junction is never kept. Optionally every lap rides a
 * loop of its own, all through one starting junction.
 *
 * The loops are frozen into the race (`race.course`, with the points in a
 * file beside the room), so a race replays the same and later map edits
 * cannot touch it; every rematch rides somewhere new.
 *
 * Pace: crossing the map's width at a canter takes its size's seconds
 * (sidecar "size" or "secondsAcross"); laps run PACE times faster than
 * that, each lap's time rounded up to whole LAP_ROUND seconds.
 */
final class Maps
{
    /** Every race loop is at least this share of the map's longest loop. */
    private const COVER = 0.75;
    /** First loops tried per race loop, detours tried per loop, and tries for the longest loop. */
    private const TRIES = 32;
    private const GROW = 80;
    private const LONGEST_TRIES = 300;
    private const LONGEST_GROW = 150;
    /** A junction turning back by more than this many degrees spoils a loop. */
    private const HAIRPIN = 150.0;
    /** Bends tighter than this share of the map's width count as turns. */
    private const TURN_RADIUS = 0.1;
    /** Laps run this much faster than a canter at the map's size … */
    private const PACE = 1.4;
    /** … and each lap's time rounds up to a whole number of these seconds. */
    private const LAP_ROUND = 10;
    /** Seconds to cross the map's width at a canter, by map size. */
    public const SIZES = ['small' => 19.0, 'medium' => 38.0, 'large' => 57.0];

    /** @var array<string, ?array> graphs already read this request */
    private static array $cache = [];

    /** Whether a course slug names a map course ("map:<slug>" or "map:*"). */
    public static function isMap(string $track): bool
    {
        return str_starts_with($track, 'map:');
    }

    /**
     * Every map course: [{slug: "map:<slug>", name, loop, kind: "map",
     * image, size}], plus "map:*" (kind "any") when there are two or more.
     * `size` is the sidecar's small/medium/large, or "custom" for an exact
     * secondsAcross.
     */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $g = self::graph(basename($file, '.json'));
            if ($g !== null) {
                $size = array_search((float) ($g['secondsAcross'] ?? self::SIZES['medium']), self::SIZES, true);
                $out[] = [
                    'slug' => 'map:' . $g['slug'],
                    'name' => $g['name'],
                    'loop' => true,
                    'kind' => 'map',
                    'image' => $g['image'],
                    'size' => $size === false ? 'custom' : $size,
                ];
            }
        }
        if (count($out) > 1) {
            $out[] = ['slug' => 'map:*', 'name' => 'Any map', 'loop' => true, 'kind' => 'any'];
        }
        return $out;
    }

    /** Whether "map:<slug>" names a map with a graph ("map:*": whether any does). */
    public static function exists(string $track): bool
    {
        $slug = substr($track, 4);
        return $slug === '*' ? self::all() !== [] : self::graph($slug) !== null;
    }

    /**
     * A map's road graph {slug, name, image, width, height, secondsAcross,
     * maxLoop, nodes, edges}, or null. A graph written before `maxLoop`
     * existed gets it estimated here (more slowly than the extractor).
     */
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
        $g['maxLoop'] ??= self::longest($g);
        return self::$cache[$slug] = $g;
    }

    /**
     * The map's longest loop, estimated: LONGEST_TRIES seeded first loops,
     * each grown as far as detours allow. Fixed seed, so the extractor
     * writes the same figure every time.
     */
    public static function longest(array $g): float
    {
        $E = $g['edges'];
        $adj = self::adjacency($E);
        $state = 12345;
        $best = 0.0;
        for ($t = 0; $t < self::LONGEST_TRIES; $t++) {
            $loop = self::seedLoop($E, $adj, $state, null);
            if ($loop !== null) {
                $loop = self::grow($E, $adj, $state, $loop, INF, self::LONGEST_GROW);
                $best = max($best, self::length($E, $loop));
            }
        }
        return $best;
    }

    /**
     * The course a race runs on "map:<slug>" (or "map:*", any map), grown
     * from $seed: ['sim' => what the engine keeps in the race — track, map,
     * perLap, lapTimes, passGap, loops[{segments}] — 'client' => what the
     * race view draws — the same plus name, image, viewBox and each loop's
     * points and length]. With $perLap every one of $laps laps rides its own
     * loop, all through one junction; otherwise one loop serves every lap.
     * Null when the map has no loop to give.
     */
    public static function course(string $track, int $seed, int $laps = 1, bool $perLap = false): ?array
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
        $E = $g['edges'];
        $adj = self::adjacency($E);
        $first = self::raceLoop($E, $adj, $state, (float) $g['maxLoop'], null);
        if ($first === null) {
            return null;
        }
        $perLap = $perLap && $laps > 1;
        $loops = [self::plotLoop($g, $first, null)];
        if ($perLap) {
            // every lap through one junction: the first loop's nearest to its start
            $start = $loops[0]['at'];
            $hub = null;
            foreach ($first as [$k, $fwd]) {
                $n = self::startOf($E, $k, $fwd);
                if ($hub === null || hypot($g['nodes'][$n][0] - $start[0], $g['nodes'][$n][1] - $start[1])
                    < hypot($g['nodes'][$hub][0] - $start[0], $g['nodes'][$hub][1] - $start[1])) {
                    $hub = $n;
                }
            }
            $at = $g['nodes'][$hub];
            $loops = [self::plotLoop($g, $first, $at)];
            for ($lap = 1; $lap < $laps; $lap++) {
                $loop = self::raceLoop($E, $adj, $state, (float) $g['maxLoop'], $hub) ?? $first;
                $loops[] = self::plotLoop($g, $loop, $at);
            }
        }
        $lapTimes = [];
        for ($lap = 0; $lap < $laps; $lap++) {
            $lapTimes[] = $loops[$perLap ? $lap : 0]['lapSeconds'];
        }
        // lanes swing to pass within about 1.5 horse lengths (a horse ≈ a
        // 45th of the map), measured in seconds at this course's pace
        $W = (float) max($g['width'], $g['height']);
        $passGap = 1.5 * ($W / 45) / ($loops[0]['length'] / $loops[0]['lapSeconds']);
        $sim = [
            'track' => 'map:' . $slug,
            'map' => $slug,
            'perLap' => $perLap,
            'lapTimes' => $lapTimes,
            'passGap' => $passGap,
            'loops' => array_map(fn (array $l): array => ['segments' => $l['segments']], $loops),
        ];
        $client = [
            'track' => 'map:' . $slug,
            'map' => $slug,
            'name' => $g['name'],
            'image' => $g['image'],
            'viewBox' => '0 0 ' . $g['width'] . ' ' . $g['height'],
            'loop' => true,
            'perLap' => $perLap,
            'lapTimes' => $lapTimes,
            'loops' => array_map(fn (array $l): array => [
                'points' => $l['points'],
                'segments' => $l['segments'],
                'length' => $l['length'],
                'lapSeconds' => $l['lapSeconds'],
            ], $loops),
        ];
        return ['sim' => $sim, 'client' => $client];
    }

    /**
     * One race loop, as [[edge, forward?], …]: a seeded target between
     * COVER and all of $maxLoop, then up to TRIES first loops (through
     * $through when given) grown towards it — the first to reach COVER of
     * the longest wins, else the one nearest the target.
     */
    private static function raceLoop(array $E, array $adj, int &$state, float $maxLoop, ?int $through): ?array
    {
        $floor = self::COVER * $maxLoop;
        $target = $floor + RaceEngine::prngNext($state) * ($maxLoop - $floor);
        $best = null;
        $bestScore = INF;
        for ($t = 0; $t < self::TRIES; $t++) {
            $loop = self::seedLoop($E, $adj, $state, $through);
            if ($loop === null) {
                continue;
            }
            $loop = self::grow($E, $adj, $state, $loop, $target, self::GROW);
            $length = self::length($E, $loop);
            $score = abs($length - $target) + ($length >= $floor ? 0 : 1e6);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $loop;
            }
            if ($length >= $floor) {
                break;
            }
        }
        return $best;
    }

    /** A first, short loop — a random road (or one leaving $through) and the way back — or null. */
    private static function seedLoop(array $E, array $adj, int &$state, ?int $through): ?array
    {
        for ($t = 0; $t < 40; $t++) {
            if ($through === null) {
                $k0 = (int) floor(RaceEngine::prngNext($state) * count($E));
                $fwd0 = true;
            } else {
                $out = $adj[$through] ?? [];
                if ($out === []) {
                    return null;
                }
                [$k0, $fwd0] = $out[(int) floor(RaceEngine::prngNext($state) * count($out))];
            }
            if ($E[$k0]['a'] === $E[$k0]['b']) {
                return [[$k0, $fwd0]];
            }
            $a = self::startOf($E, $k0, $fwd0);
            $b = self::endOf($E, $k0, $fwd0);
            $path = self::bfsPath($E, $adj, $state, $b, $a, $k0, []);
            if ($path === null) {
                continue;
            }
            $loop = array_merge([[$k0, $fwd0]], $path);
            if (self::worstTurn($E, $loop) <= self::HAIRPIN) {
                return $loop;
            }
        }
        return null;
    }

    /**
     * Grows $loop towards $target: up to $rounds times, one of its roads
     * u → v is swapped for a detour from u to v through junctions the loop
     * has not visited — still a simple loop, longer each time — unless the
     * detour would double back at a junction.
     */
    private static function grow(array $E, array $adj, int &$state, array $loop, float $target, int $rounds): array
    {
        for ($r = 0; $r < $rounds && self::length($E, $loop) < $target; $r++) {
            $i = (int) floor(RaceEngine::prngNext($state) * count($loop));
            [$k, $fwd] = $loop[$i];
            $u = self::startOf($E, $k, $fwd);
            $v = self::endOf($E, $k, $fwd);
            $on = [];
            foreach ($loop as [$kk, $ff]) {
                $on[self::startOf($E, $kk, $ff)] = true;
            }
            unset($on[$u], $on[$v]);
            $path = self::bfsPath($E, $adj, $state, $u, $v, $k, $on);
            if ($path === null) {
                continue;
            }
            $grown = array_merge(array_slice($loop, 0, $i), $path, array_slice($loop, $i + 1));
            if (self::length($E, $grown) <= self::length($E, $loop) || self::worstTurn($E, $grown) > self::HAIRPIN) {
                continue;
            }
            $loop = $grown;
        }
        return $loop;
    }

    /**
     * A path $from → $to as [[edge, forward?], …] by breadth-first search
     * with each junction's roads in seeded order — never along $banned, and
     * never through a junction in $avoid (save $to). Null when there is none.
     */
    private static function bfsPath(array $E, array $adj, int &$state, int $from, int $to, int $banned, array $avoid): ?array
    {
        $prev = [$from => null];
        $queue = [$from];
        for ($h = 0; $h < count($queue); $h++) {
            $n = $queue[$h];
            if ($n === $to) {
                break;
            }
            $roads = $adj[$n] ?? [];
            for ($i = count($roads) - 1; $i > 0; $i--) { // seeded shuffle
                $j = (int) floor(RaceEngine::prngNext($state) * ($i + 1));
                [$roads[$i], $roads[$j]] = [$roads[$j], $roads[$i]];
            }
            foreach ($roads as [$k, $fwd]) {
                $m = self::endOf($E, $k, $fwd);
                if ($k === $banned || array_key_exists($m, $prev) || (isset($avoid[$m]) && $m !== $to)) {
                    continue;
                }
                $prev[$m] = [$n, $k, $fwd];
                $queue[] = $m;
            }
        }
        if ($to === $from || !isset($prev[$to])) {
            return null;
        }
        $path = [];
        for ($n = $to; $n !== $from; $n = $prev[$n][0]) {
            $path[] = [$prev[$n][1], $prev[$n][2]];
        }
        return array_reverse($path);
    }

    /**
     * A loop plotted for the race: smoothed lightly, evenly spaced, its
     * straight/turn table, and started at the head of its longest straight
     * — or, given $at, at the point nearest $at (every lap's loop through
     * one junction). {points (client), segments, length, lapSeconds, at}.
     */
    private static function plotLoop(array $g, array $loop, ?array $at): array
    {
        $W = (float) max($g['width'], $g['height']);
        $opt = ['step' => 1.0, 'turnRate' => 1 / (self::TURN_RADIUS * $W), 'minRun' => 0.03 * $W, 'span' => 5];
        $ring = self::chaikin(self::loopPoints($g, $loop));
        $ring[] = $ring[0];
        $plot = Tracks::plot($ring, true, $opt);
        $pts = $plot['points'];
        $shift = 0;
        if ($at === null) {
            $longest = -1.0;
            foreach ($plot['segments'] as $seg) {
                if ($seg['kind'] === 'straight' && $seg['to'] - $seg['from'] > $longest) {
                    $longest = $seg['to'] - $seg['from'];
                    $shift = (int) round($seg['from'] * count($pts)) % count($pts);
                }
            }
        } else {
            $near = INF;
            foreach ($pts as $i => [$x, $y]) {
                $d = hypot($x - $at[0], $y - $at[1]);
                if ($d < $near) {
                    $near = $d;
                    $shift = $i;
                }
            }
        }
        $pts = array_merge(array_slice($pts, $shift), array_slice($pts, 0, $shift));
        $first = $pts[0];
        $pts[] = $pts[0];
        $plot = Tracks::plot($pts, true, $opt);
        $client = Tracks::clientPlot(['loop' => true] + $plot);
        // a canter across the map takes secondsAcross; laps run PACE faster,
        // each rounded up to whole LAP_ROUND seconds
        $raw = $plot['length'] / $W * (float) ($g['secondsAcross'] ?? self::SIZES['medium']) / self::PACE;
        return [
            'points' => $client['points'],
            'segments' => $plot['segments'],
            'length' => $client['length'],
            'lapSeconds' => (int) max(self::LAP_ROUND, ceil($raw / self::LAP_ROUND) * self::LAP_ROUND),
            'at' => $first,
        ];
    }

    /** Edge lists as junction → [[edge, leaving forward?], …]. */
    private static function adjacency(array $E): array
    {
        $adj = [];
        foreach ($E as $k => $e) {
            $adj[$e['a']][] = [$k, true];
            $adj[$e['b']][] = [$k, false];
        }
        return $adj;
    }

    private static function startOf(array $E, int $k, bool $fwd): int
    {
        return $fwd ? $E[$k]['a'] : $E[$k]['b'];
    }

    private static function endOf(array $E, int $k, bool $fwd): int
    {
        return $fwd ? $E[$k]['b'] : $E[$k]['a'];
    }

    private static function length(array $E, array $loop): float
    {
        $s = 0.0;
        foreach ($loop as [$k]) {
            $s += $E[$k]['length'];
        }
        return $s;
    }

    /** The sharpest turn (degrees) at any junction round the loop. */
    private static function worstTurn(array $E, array $loop): float
    {
        if (count($loop) < 2) {
            return 0.0;
        }
        $worst = 0.0;
        foreach ($loop as $i => [$k, $fwd]) {
            [$k2, $fwd2] = $loop[($i + 1) % count($loop)];
            $worst = max($worst, self::turnAt(self::oriented($E[$k], $fwd), self::oriented($E[$k2], $fwd2)));
        }
        return $worst;
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
