<?php

declare(strict_types=1);

function action_rematch(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $hostToken = is_string($body['hostToken'] ?? null) ? $body['hostToken'] : '';
    if (RoomStore::getRoom($roomId) === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    // Archive, bump and restart must land as one write (review C1): every
    // check and mutation rides the same locked read-modify-write that
    // set_prediction uses, so no poll can catch the room mid-rematch.
    $err = null;
    $room = RoomStore::mutate('rooms', $roomId, function (array $doc) use ($hostToken, &$err): ?array {
        if ($doc['hostToken'] !== $hostToken) {
            $err = ['status' => 403, 'code' => 'not_host', 'message' => 'The host alone may call the race.'];
            return null;
        }
        if ($doc['status'] === 'racing') {
            $err = ['status' => 409, 'code' => 'already_racing', 'message' => 'The chariots are already upon the track.'];
            return null;
        }
        if ($doc['status'] === 'lobby') {
            $err = ['status' => 409, 'code' => 'race_not_finished', 'message' => 'No race hath finished to re-run.'];
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
        $riderNames = [];
        foreach (array_keys($riders) as $i) {
            $doc['participants'][$i]['lane'] = $newLane++;
            $riderNames[] = $doc['participants'][$i]['nickname'];
        }
        // Archived placements label themselves from the nicknames frozen
        // into the finished race at its start (4.2) — never from the
        // lanes just renumbered for the next one.
        $names = $doc['race']['names'] ?? [];
        $winnerLane = null;
        $placements = [];
        foreach ($doc['race']['placements'] as $pl) {
            if ($pl['place'] === 1) {
                $winnerLane = $pl['lane'];
            }
            $placements[] = [
                'lane' => $pl['lane'],
                'place' => $pl['place'],
                'nickname' => $names[$pl['lane']] ?? null,
            ];
        }
        // A wager once sealed stands for the meeting: predictions ride
        // into the archive untouched, every non-null one judged by the
        // winner of the race it was made for.
        $predictions = [];
        foreach ($doc['participants'] as $p) {
            $pick = $p['prediction'] ?? null;
            if ($pick !== null) {
                $predictions[] = [
                    'nickname' => $p['nickname'],
                    'pick' => $pick,
                    'correct' => $pick === $winnerLane,
                ];
            }
        }
        // Archive the run just finished under the raceNo its tick file
        // was written with, then bump and start the next race (spec §4).
        $doc['history'][] = [
            'raceNo' => $doc['raceNo'],
            'seed' => $doc['race']['seed'],
            'placements' => $placements,
            'predictions' => $predictions,
            'ticks' => 'race-' . $doc['raceNo'] . '.ticks.jsonl.gz',
        ];
        $doc['raceNo']++;
        $seed = RaceEngine::prngNew();
        $race = RaceEngine::create($seed, count($riders));
        $race['seed'] = base64_encode(pack('N', $seed));
        $race['startedAt'] = microtime(true);
        $race['finishedAt'] = null;
        $race['names'] = $riderNames; // frozen for this race, as in start_race
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
