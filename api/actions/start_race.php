<?php

declare(strict_types=1);

function action_start_race(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $hostToken = is_string($body['hostToken'] ?? null) ? $body['hostToken'] : '';
    $room = RoomStore::getRoom($roomId);
    if ($room === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    if ($room['hostToken'] !== $hostToken) {
        Http::error(403, 'not_host', 'The host alone may call the race.');
        return;
    }
    if ($room['status'] !== 'lobby') {
        Http::error(409, 'already_racing', 'The chariots are already upon the track.');
        return;
    }
    // Racers in lane order; lanes are renumbered 0..n-1 so one freed by
    // an early leave leaves no gap under the engine's lanes.
    $riders = [];
    foreach ($room['participants'] as $i => $p) {
        if ($p['role'] === 'racer' && empty($p['left'])) {
            $riders[$i] = $p['lane'];
        }
    }
    if (count($riders) < 2) {
        Http::error(409, 'too_few_racers', 'Two riders at least must take the track.');
        return;
    }
    asort($riders);
    $newLane = 0;
    foreach (array_keys($riders) as $i) {
        $room['participants'][$i]['lane'] = $newLane++;
    }
    $seed = RaceEngine::prngNew();
    $race = RaceEngine::create($seed, count($riders));
    $race['seed'] = base64_encode(pack('N', $seed));
    $race['startedAt'] = microtime(true);
    $race['finishedAt'] = null;
    $room['race'] = $race;
    $room['status'] = 'racing';
    $room['updatedAt'] = date(DATE_ATOM);
    RoomStore::saveRoom($room);
    echo json_encode(['ok' => true]);
}
