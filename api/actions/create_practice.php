<?php

declare(strict_types=1);

function action_create_practice(): void
{
    $body = Http::body();
    $n = max(2, min(8, (int) ($body['racers'] ?? 4)));
    $doc = RaceEngine::create(RaceEngine::prngNew(), $n);
    $doc['practiceId'] = bin2hex(random_bytes(8));
    $doc['startedAt'] = microtime(true);
    $doc['names'] = RaceEngine::names($n);
    RoomStore::put('practice', $doc['practiceId'], $doc);
    echo json_encode([
        'practiceId' => $doc['practiceId'],
        'snapshot' => practiceSnapshot($doc),
    ]);
}
