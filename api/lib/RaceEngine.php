<?php

declare(strict_types=1);

/**
 * Race simulation engine (server-authoritative).
 *
 * Holds the seeded PRNG (a faithful port of the browser's mulberry32), race
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
     */
    public static function create(int $prngState, int $n): array
    {
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
     * N single steps.
     */
    public static function advance(array &$state, int $targetTick): void
    {
        for ($steps = 0; $state['tick'] < $targetTick && $steps < 900; $steps++) {
            self::step($state);
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
