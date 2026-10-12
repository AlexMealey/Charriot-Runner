<?php

declare(strict_types=1);

/**
 * Race simulation engine (server-authoritative).
 *
 * Holds the seeded PRNG (a faithful port of JS mulberry32, which the browser
 * sim used before Stage 1), race
 * creation and stepping (ports of the browser's createRace / stepRace). The
 * PRNG state is a single serializable 32-bit integer, so a race can be
 * stored mid-flight and advanced later between requests.
 */
class RaceEngine
{
    /** Fixed timestep (seconds) and base speed (laps per second). */
    public const STEP = 1 / 60;
    public const BASE_SPEED = 0.12;

    /** Word-names for the heralds to cry (copied from public/index.html). */
    public const NAME_A = [
        "Swift", "Grim", "Golden", "Iron", "Silent", "Wild", "Bold", "Fierce",
        "Proud", "Stormbound", "Ember", "Dusky", "Noble", "Cunning", "Valiant", "Shadow",
    ];
    public const NAME_B = [
        "Badger", "Quill", "Falcon", "Stag", "Raven", "Fox", "Boar", "Hound",
        "Wyvern", "Gryphon", "Hart", "Adder", "Otter", "Crow", "Wolf", "Mare",
    ];

    /**
     * Horses for horses races (Stage 8; roster rebalanced in stage-6 §6.4).
     * Canter is the cruising speed, Turning the speed through the turns,
     * Gallop the speed along the straights; Stamina is how many ticks of
     * gallop the horse holds before it is blown.
     */
    public const HORSES = [
        'zebra' => ['name' => 'Zebra', 'canter' => 'slow', 'turning' => 'normal', 'gallop' => 'fast', 'stamina' => 90],
        'fiery_tail' => ['name' => 'Fiery Tail', 'canter' => 'fast', 'turning' => 'fast', 'gallop' => 'fast', 'stamina' => 40],
        'thundercloud' => ['name' => 'Thundercloud', 'canter' => 'fast', 'turning' => 'fast', 'gallop' => 'normal', 'stamina' => 50],
        'blazing' => ['name' => 'Blazing', 'canter' => 'normal', 'turning' => 'slow', 'gallop' => 'fast', 'stamina' => 140],
        'brunellus' => ['name' => 'Brunellus', 'canter' => 'normal', 'turning' => 'normal', 'gallop' => 'fast', 'stamina' => 80],
        'purebred_bay' => ['name' => 'Purebred Bay', 'canter' => 'normal', 'turning' => 'normal', 'gallop' => 'normal', 'stamina' => 150],
        'night_charger' => ['name' => 'Night Charger', 'canter' => 'fast', 'turning' => 'normal', 'gallop' => 'fast', 'stamina' => 60],
        'sirocco' => ['name' => 'Sirocco', 'canter' => 'slow', 'turning' => 'slow', 'gallop' => 'fast', 'stamina' => 160],
        'purebred_bay_mare' => ['name' => 'Purebred Bay Mare', 'canter' => 'slow', 'turning' => 'normal', 'gallop' => 'normal', 'stamina' => 180],
        'shadow' => ['name' => 'Shadow', 'canter' => 'slow', 'turning' => 'fast', 'gallop' => 'normal', 'stamina' => 100],
        'phantom' => ['name' => 'Phantom', 'canter' => 'fast', 'turning' => 'normal', 'gallop' => 'normal', 'stamina' => 80],
    ];

    /** Horse speeds as multiples of BASE_SPEED; every gallop beats every canter. */
    public const SPEED = [
        'canter' => ['slow' => 0.93, 'normal' => 0.96, 'fast' => 0.99],
        'turning' => ['slow' => 0.85, 'normal' => 0.88, 'fast' => 0.91],
        'gallop' => ['slow' => 1.11, 'normal' => 1.15, 'fast' => 1.19],
    ];

    /** Stamina won back per tick a horse is not galloping. */
    private const STAMINA_REGEN = 0.5;
    /** Lane-change look-ahead in laps of a drawn course (≈ 1.5 horse lengths) and its hold time in ticks. */
    private const PASS_GAP = 0.018;
    private const SIDE_HOLD = 30;

    /** Fresh 32-bit PRNG state. */
    public static function prngNew(): int
    {
        return random_int(0, 0xFFFFFFFF);
    }

    /**
     * Port of JS mulberry32: advances $state in place, returns the next
     * float in [0, 1).
     */
    public static function prngNext(int &$state): float
    {
        $a = self::i32($state + 0x6d2b79f5);
        $state = $a;
        $t = self::imul($a ^ self::ushr($a, 15), 1 | $a);
        $t = self::i32(($t + self::imul($t ^ self::ushr($t, 7), 61 | $t)) ^ $t);
        return (($t ^ self::ushr($t, 14)) & 0xFFFFFFFF) / 4294967296;
    }

    /**
     * Port of JS createRace: builds the initial race state for $n lanes.
     *
     * Deterministic in $prngState: a seeded shuffle of 0..n-1 sets each
     * lane's rank (0 = seeded winner), then per-racer surge parameters are
     * drawn. The updated PRNG state is kept in the result so the sim stays
     * serializable, and the whole state survives a json_encode round-trip.
     *
     * $opts['mode'] === 'custom' builds a horses race instead (createCustom);
     * without it this classic path is exactly as before.
     */
    public static function create(int $prngState, int $n, array $opts = []): array
    {
        if (($opts['mode'] ?? 'classic') === 'custom') {
            return self::createCustom($prngState, $n, $opts);
        }
        $prng = $prngState;

        // Seeded Fisher-Yates shuffle (exact port of the JS loop).
        $order = range(0, $n - 1);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = (int) floor(self::prngNext($prng) * ($i + 1));
            $tmp = $order[$i];
            $order[$i] = $order[$j];
            $order[$j] = $tmp;
        }

        $racers = [];
        for ($lane = 0; $lane < $n; $lane++) {
            $racers[] = [
                'lane' => $lane,
                'rank' => array_search($lane, $order, true),
                'p' => -0.012,
                'done' => false,
                'place' => 0,
                'surgeAmp' => 0.1 + self::prngNext($prng) * 0.12,
                'surgeW' => 1.2 + self::prngNext($prng) * 1.2,
                'surgePhase' => self::prngNext($prng) * M_PI * 2,
            ];
        }

        return [
            'tick' => 0,
            't' => 0,
            'prng' => $prng,
            'n' => $n,
            'finished' => 0,
            'placements' => [],
            'racers' => $racers,
        ];
    }

    /**
     * Port of JS stepRace: advances the race one fixed tick in place.
     *
     * Surges and PRNG jitter trade the lead; rubber-banding pulls the field
     * together mid-race; the endgame (smoothstep over the leader's progress)
     * lets raw rank strength decide. The "hold the line" rule keeps the
     * finish order seeded: a racer reaching the line waits at 0.994 until
     * every stronger rank has finished.
     */
    public static function step(array &$state): void
    {
        if (($state['mode'] ?? 'classic') === 'custom') {
            self::stepCustom($state);
            return;
        }
        $state['tick'] += 1;
        $state['t'] += self::STEP;

        $maxP = -1;
        foreach ($state['racers'] as $rc) {
            if (!$rc['done'] && $rc['p'] > $maxP) {
                $maxP = $rc['p'];
            }
        }
        $endgame = self::smoothstep(0.55, 0.9, max(0, $maxP));

        for ($i = 0, $n = count($state['racers']); $i < $n; $i++) {
            $rc = &$state['racers'][$i];
            if ($rc['done']) {
                continue;
            }
            $gap = $maxP - $rc['p'];
            $surge = $rc['surgeAmp'] * sin($rc['surgePhase'] + $state['t'] * $rc['surgeW']);
            $jitter = (self::prngNext($state['prng']) - 0.5) * 0.05;
            // rubber banding: stragglers surge, the leader is reined in — but
            // only mid-race; the endgame lets raw rank strength decide.
            $band = ($gap - 0.015) * 3 * (1 - $endgame);
            $strength = 1 + (1.5 - $rc['rank']) * 0.06 * (0.25 + 2.5 * $endgame);
            $v = self::BASE_SPEED * $strength * (1 + $surge + $jitter + $band);
            $rc['p'] += max(0.03, $v) * self::STEP;
            if ($rc['p'] >= 1) {
                $blocked = false;
                foreach ($state['racers'] as $o) {
                    if (!$o['done'] && $o['rank'] < $rc['rank']) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    $rc['p'] = 0.994; // hold the line until the seeded order is clear
                } else {
                    $rc['done'] = true;
                    $state['finished'] += 1;
                    $rc['place'] = $state['finished'];
                    $state['placements'][] = $rc;
                }
            }
            unset($rc);
        }
    }

    /** JS smoothstep: clamped hermite interpolation between $e0 and $e1. */
    public static function smoothstep(float $e0, float $e1, float $x): float
    {
        $t = min(1, max(0, ($x - $e0) / ($e1 - $e0)));
        return $t * $t * (3 - 2 * $t);
    }

    /**
     * Catch the race up to $targetTick, one fixed step per tick.
     *
     * Capped at 900 steps per call so a long-idle race cannot stall a
     * request; the next call continues from the stored tick. Stepping is
     * deterministic in the stored PRNG state, so one call to tick N equals
     * N single steps. Stops once every racer is done, so a finished race
     * keeps its final tick (code review C2). $onTick, when given, sees the
     * state after every step (the tick history hooks in here).
     */
    public static function advance(array &$state, int $targetTick, ?callable $onTick = null): void
    {
        for (
            $steps = 0;
            $state['finished'] < $state['n'] && $state['tick'] < $targetTick && $steps < 900;
            $steps++
        ) {
            self::step($state);
            if ($onTick !== null) {
                $onTick($state);
            }
        }
    }

    /** n word-names ("Swift Badger"), rolled from the bundled word lists. */
    public static function names(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $a = self::NAME_A[random_int(0, count(self::NAME_A) - 1)];
            $b = self::NAME_B[random_int(0, count(self::NAME_B) - 1)];
            $out[] = $a . ' ' . $b;
        }
        return $out;
    }

    /* --- horses races (Stage 8, mode "custom") --------------------------- */

    /**
     * A horses race for $n riders in slot order (slots 0/1 the front row,
     * 2/3 behind, … — the grid is drawn, never raced). $opts: `horses` (a
     * roster slug or null = Random per slot), `track` (course slug), `laps`,
     * `segments` (the course's straight/turn table, Tracks::load). Random
     * horses are drawn from the PRNG here, so seed + picks + course + laps
     * replay the same race. No seeded rank: the horses decide the order.
     */
    private static function createCustom(int $prngState, int $n, array $opts): array
    {
        $prng = $prngState;
        $slugs = array_keys(self::HORSES);
        $horses = [];
        $racers = [];
        for ($lane = 0; $lane < $n; $lane++) {
            $slug = $opts['horses'][$lane] ?? null;
            if (!is_string($slug) || !isset(self::HORSES[$slug])) {
                $slug = $slugs[(int) floor(self::prngNext($prng) * count($slugs))];
            }
            $horses[] = $slug;
            $racers[] = [
                'lane' => $lane,
                'p' => 0.0,
                'done' => false,
                'place' => 0,
                'horse' => $slug,
                'stamina' => (float) self::HORSES[$slug]['stamina'],
                'blown' => false,
                'side' => $lane % 2,
                'sideTick' => -self::SIDE_HOLD,
                'surgeAmp' => 0.1 + self::prngNext($prng) * 0.12,
                'surgeW' => 1.2 + self::prngNext($prng) * 1.2,
                'surgePhase' => self::prngNext($prng) * M_PI * 2,
            ];
        }
        return [
            'mode' => 'custom',
            'tick' => 0,
            't' => 0,
            'prng' => $prng,
            'n' => $n,
            'finished' => 0,
            'placements' => [],
            'racers' => $racers,
            'horses' => $horses,
            'track' => (string) ($opts['track'] ?? ''),
            'laps' => max(1, (int) ($opts['laps'] ?? 1)),
            // a map course (Stage 9) arrives whole — loop, pace and all
            'course' => $opts['course'] ?? ['segments' => $opts['segments'] ?? []],
        ];
    }

    /**
     * One tick of a horses race. Every horse reads the same straight/turn
     * table: Turning speed in a turn (stamina +½), Gallop on a straight while
     * not blown and stamina lasts (−1 a tick), else Canter and blown until
     * the next turn (stamina +½). Luck is today's seeded surge at half
     * strength plus per-tick jitter; no rank, rubber band or hold-the-line.
     * Same-tick finishers are placed by how far into the tick each crossed.
     */
    private static function stepCustom(array &$state): void
    {
        $state['tick'] += 1;
        $state['t'] += self::STEP;
        $course = $state['course'];
        // p is the race's progress in time at a steady canter: every lap
        // takes its own seconds — a map course sets them (its loop's length,
        // each lap maybe a loop of its own), drawn courses ride BASE_SPEED
        $times = self::lapTimes($state);
        $total = array_sum($times);
        $finishers = [];
        for ($i = 0, $n = count($state['racers']); $i < $n; $i++) {
            $rc = &$state['racers'][$i];
            if ($rc['done']) {
                unset($rc);
                continue;
            }
            $horse = self::HORSES[$rc['horse']];
            $full = (float) $horse['stamina'];
            [$lap, $u] = self::lapAt($times, $rc['p'] * $total);
            $segments = isset($course['loops'])
                ? $course['loops'][empty($course['perLap']) ? 0 : $lap]['segments']
                : $course['segments'];
            if (self::inTurn($segments, $u)) {
                $speed = self::SPEED['turning'][$horse['turning']];
                $rc['stamina'] = min($full, $rc['stamina'] + self::STAMINA_REGEN);
                $rc['blown'] = false;
            } elseif (!$rc['blown'] && $rc['stamina'] >= 1) {
                $speed = self::SPEED['gallop'][$horse['gallop']];
                $rc['stamina'] -= 1;
                if ($rc['stamina'] < 1) {
                    $rc['blown'] = true;
                }
            } else {
                $speed = self::SPEED['canter'][$horse['canter']];
                $rc['stamina'] = min($full, $rc['stamina'] + self::STAMINA_REGEN);
                $rc['blown'] = true;
            }
            $surge = $rc['surgeAmp'] * sin($rc['surgePhase'] + $state['t'] * $rc['surgeW']);
            $jitter = (self::prngNext($state['prng']) - 0.5) * 0.05;
            $pace = $speed * (1 + 0.5 * $surge + $jitter);
            $before = $rc['p'];
            $rc['p'] += max(0.25, $pace) * self::STEP / $total; // never below a quarter pace
            if ($rc['p'] >= 1) {
                $rc['done'] = true;
                // how far into this tick the line was crossed: earlier wins
                $finishers[] = [(1 - $before) / ($rc['p'] - $before), $i];
            }
            unset($rc);
        }
        sort($finishers); // by crossing fraction, then slot
        foreach ($finishers as [, $i]) {
            $state['finished'] += 1;
            $state['racers'][$i]['place'] = $state['finished'];
            $state['placements'][] = ['lane' => $state['racers'][$i]['lane'], 'place' => $state['finished']];
        }
        self::steerSides($state);
    }

    /** Seconds each lap takes at a steady canter (see stepCustom). */
    private static function lapTimes(array $state): array
    {
        return $state['course']['lapTimes'] ?? array_fill(0, max(1, (int) $state['laps']), 1 / self::BASE_SPEED);
    }

    /** [lap, position 0..1 within it] for $t seconds of the race at a canter. */
    private static function lapAt(array $times, float $t): array
    {
        $last = count($times) - 1;
        foreach ($times as $k => $lapTime) {
            if ($t < $lapTime || $k === $last) {
                return [$k, max(0.0, min(1.0, $t / $lapTime))];
            }
            $t -= $lapTime;
        }
        return [0, 0.0];
    }

    /** Whether lap position $u (0..1) lies in a turn of the course table. */
    private static function inTurn(array $segments, float $u): bool
    {
        foreach ($segments as $seg) {
            if ($u >= $seg['from'] && $u < $seg['to']) {
                return $seg['kind'] === 'turn';
            }
        }
        return false;
    }

    /**
     * Which of the course's two lanes each horse is drawn in — presentation
     * only, never speed. Leaders first, a horse closing on another in its
     * lane within PASS_GAP swings into the other lane when that lane is
     * clear for the same distance; otherwise it stays and passes through.
     * A swing holds for SIDE_HOLD ticks so no horse flickers between lanes.
     */
    private static function steerSides(array &$state): void
    {
        // distances in seconds of the race at a canter; the look-ahead is the
        // course's own (a map's pace) or PASS_GAP laps of a drawn course
        $total = array_sum(self::lapTimes($state));
        $gap = (float) ($state['course']['passGap'] ?? self::PASS_GAP / self::BASE_SPEED);
        $dist = [];
        foreach ($state['racers'] as $i => $rc) {
            if (!$rc['done']) {
                $dist[$i] = $rc['p'] * $total;
            }
        }
        arsort($dist); // stable: ties keep slot order
        foreach ($dist as $i => $d) {
            $me = $state['racers'][$i];
            if ($state['tick'] - $me['sideTick'] < self::SIDE_HOLD) {
                continue;
            }
            $blocked = false;
            $clear = true;
            foreach ($dist as $j => $dj) {
                if ($j === $i) {
                    continue;
                }
                $ahead = $dj - $d;
                if ($state['racers'][$j]['side'] === $me['side']) {
                    $blocked = $blocked || ($ahead > 0 && $ahead < $gap);
                } elseif (abs($ahead) < $gap) {
                    $clear = false;
                }
            }
            if ($blocked && $clear) {
                $state['racers'][$i]['side'] = 1 - $me['side'];
                $state['racers'][$i]['sideTick'] = $state['tick'];
            }
        }
    }

    /** The horse roster in its fixed order, as the `horses` action serves it. */
    public static function horseRoster(): array
    {
        $out = [];
        foreach (self::HORSES as $slug => $horse) {
            $out[] = ['slug' => $slug] + $horse;
        }
        return $out;
    }

    /* --- 32-bit emulation: JS bitwise semantics on 64-bit PHP ints ------- */

    /** JS `x | 0`: truncate to signed 32-bit. */
    public static function i32(int $x): int
    {
        $x &= 0xFFFFFFFF;
        return $x >= 0x80000000 ? $x - 0x100000000 : $x;
    }

    /** JS `x >>> n`: logical right shift of the 32-bit pattern. */
    public static function ushr(int $x, int $n): int
    {
        return ($x & 0xFFFFFFFF) >> $n;
    }

    /** JS Math.imul: 32-bit wrapping multiplication. */
    public static function imul(int $a, int $b): int
    {
        return self::i32(self::i32($a) * self::i32($b));
    }
}
