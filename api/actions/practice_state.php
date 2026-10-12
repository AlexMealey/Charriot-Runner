<?php

declare(strict_types=1);

function action_practice_state(): void
{
    $id = (string) ($_GET['race'] ?? '');
    if (RoomStore::get('practice', $id) === null) {
        Http::error(404, 'practice_not_found', 'No such practice race');
        return;
    }
    // Read, catch-up, tick appends and save held under one lock, so two
    // tabs on the same race can neither duplicate ticks nor lose steps
    // (code review C1). A finished race stays frozen: no frozen ticks, and
    // finishedAt is stamped exactly once (code review C2).
    $doc = RoomStore::mutate('practice', $id, function (array $doc) use ($id): array {
        RoomStore::appendTicks('practice', $id, 1, catchUpRace($doc));
        if ($doc['finished'] >= $doc['n'] && ($doc['finishedAt'] ?? null) === null) {
            $doc['finishedAt'] = microtime(true);
        }
        return $doc;
    });
    if ($doc === null) {
        Http::error(500, 'storage_error', 'The scroll would not unroll.');
        return;
    }
    echo json_encode(practiceSnapshot($doc));
}
