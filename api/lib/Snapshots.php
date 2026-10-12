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

/**
 * Practice snapshot shared by create_practice and practice_state: the same
 * slim shape as room_state.race (spec §5) plus the generated `names` —
 * ranks and surge parameters stay in the stored doc.
 */
function practiceSnapshot(array $doc): array
{
    return [
        'tick' => $doc['tick'],
        't' => $doc['t'],
        'n' => $doc['n'],
        'finished' => $doc['finished'],
        'placements' => slimPlacements($doc['placements']),
        'racers' => slimRacers($doc['racers']),
        'names' => $doc['names'],
        'startedAt' => $doc['startedAt'],
        'finishedAt' => $doc['finishedAt'] ?? null,
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
    // chariots from the names frozen into the race at its start — riders
    // who quit mid-race keep theirs, and no later join can relabel a
    // chariot. null while the room has no race yet; a race stored before
    // names were frozen keeps the array shape with blank labels.
    $names = null;
    if ($room['race'] !== null) {
        $names = $room['race']['names'] ?? array_fill(0, (int) $room['race']['n'], null);
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
 * Catch a stored race (room or practice) up to now (spec §3): one fixed
 * tick at a time via RaceEngine::advance, so every tick becomes a history
 * line — same 900-step cap, and a paused tab resumes from the stored tick
 * on its next poll. Stops the moment the race is run, so no frozen ticks
 * are recorded (code review C2). Returns the new tick lines in slim shapes
 * only (§3): seed, PRNG and surge parameters stay put.
 */
function catchUpRace(array &$race): array
{
    $targetTick = (int) floor((microtime(true) - (float) $race['startedAt']) * 60);
    $lines = [];
    RaceEngine::advance($race, $targetTick, function (array $r) use (&$lines): void {
        $lines[] = json_encode([
            'tick' => $r['tick'],
            't' => $r['t'],
            'racers' => slimRacers($r['racers']),
            'placements' => slimPlacements($r['placements']),
        ]);
    });
    return $lines;
}

/**
 * Catch a racing room's sim up to now (catchUpRace above); the finish
 * stamps status/finishedAt exactly once (code review C2). Runs inside
 * RoomStore::mutate, so the history append shares the room's lock (code
 * review C1).
 */
function catchUpRoomRace(array &$room): void
{
    $race = &$room['race'];
    // empty lines (nothing advanced) are dropped inside appendTicks
    RoomStore::appendTicks('rooms', $room['id'], $room['raceNo'], catchUpRace($race));
    if ($race['finished'] >= $race['n']) {
        $race['finishedAt'] = microtime(true);
        $room['status'] = 'finished';
    }
}
