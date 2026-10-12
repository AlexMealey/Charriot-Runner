<?php

declare(strict_types=1);

function action_start_race(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $hostToken = is_string($body['hostToken'] ?? null) ? $body['hostToken'] : '';
    if (RoomStore::getRoom($roomId) === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    // Host, lobby gate and roster are judged inside one locked
    // read-modify-write (review C1): of two quick trumpet calls the second
    // finds the race begun and answers 409 already_racing (spec §11).
    $err = null;
    $room = RoomStore::mutate('rooms', $roomId, function (array $doc) use ($hostToken, &$err): ?array {
        if ($doc['hostToken'] !== $hostToken) {
            $err = ['status' => 403, 'code' => 'not_host', 'message' => 'The host alone may call the race.'];
            return null;
        }
        if ($doc['status'] !== 'lobby') {
            $err = ['status' => 409, 'code' => 'already_racing', 'message' => 'The chariots are already upon the track.'];
            return null;
        }
        // Racers in lane order; lanes are renumbered 0..n-1 so one freed by
        // an early leave leaves no gap under the engine's lanes.
        $riders = [];
        foreach ($doc['participants'] as $i => $p) {
            if ($p['role'] === 'racer' && empty($p['left'])) {
                $riders[$i] = $p['lane'];
            }
        }
        if (count($riders) < 2) {
            $err = ['status' => 409, 'code' => 'too_few_racers', 'message' => 'Two riders at least must take the track.'];
            return null;
        }
        asort($riders);
        $newLane = 0;
        $laneMap = [];
        foreach ($riders as $i => $oldLane) {
            $laneMap[$oldLane] = $newLane;
            $doc['participants'][$i]['lane'] = $newLane++;
        }
        // Wagers follow their rider into the renumbered lanes; one on a
        // lane no rider holds any more is void.
        foreach ($doc['participants'] as $i => $p) {
            $pick = $p['prediction'] ?? null;
            if ($pick !== null) {
                $doc['participants'][$i]['prediction'] = $laneMap[$pick] ?? null;
            }
        }
        $seed = RaceEngine::prngNew();
        $race = RaceEngine::create($seed, count($riders));
        $race['seed'] = base64_encode(pack('N', $seed));
        $race['startedAt'] = microtime(true);
        $race['finishedAt'] = null;
        $doc['race'] = $race;
        $doc['status'] = 'racing';
        $doc['updatedAt'] = date(DATE_ATOM);
        return $doc;
    });
    if ($err !== null) {
        Http::error($err['status'], $err['code'], $err['message']);
        return;
    }
    if ($room === null) {
        Http::error(500, 'storage_error', 'The scroll would not unroll.');
        return;
    }
    echo json_encode(['ok' => true]);
}
