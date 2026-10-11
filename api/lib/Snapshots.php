<?php

declare(strict_types=1);

/**
 * Client-facing views of race and room state (spec sections 3-5).
 *
 * Every snapshot strips the server-only fields before anything reaches a
 * browser: seed, PRNG state, ranks and surge parameters stay in the stored
 * document alone (spec section 9).
 */

/** Racers as the history and snapshots carry them: slim shapes only (section 3). */
function slimRacers(array $racers): array
{
    $slim = [];
    foreach ($racers as $rc) {
        $slim[] = [
            'lane' => $rc['lane'],
            'p' => $rc['p'],
            'done' => $rc['done'],
            'place' => $rc['place'],
        ];
    }
    return $slim;
}

/** Placements as the history and snapshots carry them: lane and place. */
function slimPlacements(array $placements): array
{
    $slim = [];
    foreach ($placements as $pl) {
        $slim[] = ['lane' => $pl['lane'], 'place' => $pl['place']];
    }
    return $slim;
}

/** Practice snapshot shared by create_practice and practice_state. */
function practiceSnapshot(array $doc): array
{
    return [
        'tick' => $doc['tick'],
        't' => $doc['t'],
        'n' => $doc['n'],
        'finished' => $doc['finished'],
        'placements' => $doc['placements'],
        'racers' => $doc['racers'],
        'names' => $doc['names'],
        'startedAt' => $doc['startedAt'],
    ];
}

/** Room snapshot shared by join_room and room_state (spec §5). */
function roomSnapshot(array $room): array
{
    $participants = [];
    foreach ($room['participants'] as $p) {
        // A quitter's row stays behind for a later re-join (4.9) but holds
        // no seat: the roster — and every count built on it — ignores it.
        if (!empty($p['left'])) {
            continue;
        }
        $participants[] = [
            'id' => $p['id'],
            'nickname' => $p['nickname'],
            'role' => $p['role'],
            'lane' => $p['lane'],
            // The lobby picker reads picks back off the roster poll (4.7).
            'prediction' => $p['prediction'] ?? null,
        ];
    }

    // Racer nicknames by lane (4.2): the shared race view labels its
    // chariots from this; null while the room has no race yet.
    $names = null;
    if ($room['race'] !== null) {
        $names = array_fill(0, (int) $room['race']['n'], null);
        foreach ($room['participants'] as $p) {
            if ($p['role'] === 'racer' && isset($names[$p['lane']])) {
                $names[$p['lane']] = $p['nickname'];
            }
        }
    }

    return [
        'id' => $room['id'],
        'status' => $room['status'],
        'config' => $room['config'],
        'participants' => $participants,
        'raceNo' => $room['raceNo'],
        'race' => raceSnapshot($room['race']),
        'names' => $names,
    ];
}

/**
 * Stored engine race as clients see it (spec §4/§5): positions and placements
 * only, same shape as practiceSnapshot — seed, PRNG state, ranks and surge
 * parameters stay server-side (spec §9) and are used by the stored doc alone.
 */
function raceSnapshot(?array $race): ?array
{
    if ($race === null) {
        return null;
    }
    return [
        'tick' => $race['tick'],
        't' => $race['t'],
        'n' => $race['n'],
        'finished' => $race['finished'],
        'placements' => slimPlacements($race['placements']),
        'racers' => slimRacers($race['racers']),
        'startedAt' => $race['startedAt'],
        'finishedAt' => $race['finishedAt'],
    ];
}

/**
 * Catch a racing room's sim up to now (spec §3): one fixed tick at a time
 * so every tick lands in the history, same 900-step cap as the practice
 * poll — a paused tab resumes from the stored tick on its next poll.
 * Stops the moment the race is run: no frozen ticks are appended, and the
 * finish stamps status/finishedAt exactly once (code review C2). Runs
 * inside RoomStore::mutate, so the history append shares the room's lock
 * (code review C1).
 */
function catchUpRoomRace(array &$room): void
{
    $race = &$room['race'];
    $targetTick = (int) floor((microtime(true) - (float) $race['startedAt']) * 60);
    $lines = [];
    $steps = 0;
    while (
        $race['finished'] < $race['n'] &&
        $race['tick'] < $targetTick &&
        $steps < 900
    ) {
        $steps++;
        RaceEngine::step($race);
        // slim shapes only (§3): seed, PRNG and surge parameters stay put
        $lines[] = json_encode([
            'tick' => $race['tick'],
            't' => $race['t'],
            'racers' => slimRacers($race['racers']),
            'placements' => slimPlacements($race['placements']),
        ]);
    }
    // empty $lines (nothing advanced) are dropped inside appendTicks
    RoomStore::appendTicks('rooms', $room['id'], $room['raceNo'], $lines);
    if ($race['finished'] >= $race['n']) {
        $race['finishedAt'] = microtime(true);
        $room['status'] = 'finished';
    }
}
