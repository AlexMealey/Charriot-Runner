<?php

declare(strict_types=1);

function action_create_practice(): void
{
    $body = Http::body();
    // Validated like create_room, not clamped (code review M3); an absent
    // count keeps the old default of four.
    $n = $body['racers'] ?? 4;
    if (!is_int($n) || $n < 2 || $n > 8) {
        Http::error(400, 'bad_request', 'Chariots number 2 to 8.');
        return;
    }
    // Rules of the race (8.9): classic, or horses — every horse drawn from
    // the seed — on a course for 1–3 laps.
    [$setup, $problem] = Tracks::setup($body);
    if ($setup === null) {
        Http::error(400, 'bad_request', $problem);
        return;
    }
    $opts = [];
    if ($setup['mode'] === 'custom') {
        $plot = Tracks::load($setup['track']);
        $opts = $setup + ['horses' => array_fill(0, $n, null), 'segments' => $plot['segments']];
    }
    $doc = RaceEngine::create(RaceEngine::prngNew(), $n, $opts);
    $doc['practiceId'] = bin2hex(random_bytes(8));
    $doc['startedAt'] = microtime(true);
    $doc['finishedAt'] = null;
    $doc['names'] = RaceEngine::names($n);
    if (!RoomStore::put('practice', $doc['practiceId'], $doc)) {
        // never hand out a race that was not written (code review M1)
        Http::error(500, 'storage_error', 'The scroll would not unroll.');
        return;
    }
    echo json_encode([
        'practiceId' => $doc['practiceId'],
        'snapshot' => practiceSnapshot($doc),
    ]);
}
