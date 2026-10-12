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
    $doc = RaceEngine::create(RaceEngine::prngNew(), $n);
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
