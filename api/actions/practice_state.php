<?php

declare(strict_types=1);

function action_practice_state(): void
{
    $id = (string) ($_GET['race'] ?? '');
    $doc = RoomStore::get('practice', $id);
    if ($doc === null) {
        Http::error(404, 'practice_not_found', 'No such practice race');
        return;
    }
    // Catch up one fixed tick at a time, recording each tick for the
    // history (advance() has no recording hook; same 900-step cap).
    $targetTick = (int) floor((microtime(true) - (float) $doc['startedAt']) * 60);
    $lines = [];
    for ($steps = 0; $doc['tick'] < $targetTick && $steps < 900; $steps++) {
        RaceEngine::step($doc);
        $lines[] = json_encode([
            'tick' => $doc['tick'],
            't' => $doc['t'],
            'racers' => slimRacers($doc['racers']),
            'placements' => $doc['placements'],
        ]);
    }
    RoomStore::appendTicks('practice', $id, 1, $lines);
    RoomStore::put('practice', $id, $doc);
    echo json_encode(practiceSnapshot($doc));
}
