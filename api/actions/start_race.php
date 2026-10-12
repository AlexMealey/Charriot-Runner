<?php

declare(strict_types=1);

function action_start_race(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $hostToken = is_string($body['hostToken'] ?? null) ? $body['hostToken'] : '';
    // Horses rooms (8.5): the host may force the start once the grace is up.
    $force = ($body['force'] ?? false) === true;
    if (RoomStore::getRoom($roomId) === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    // Host, lobby gate and roster are judged inside one locked
    // read-modify-write (review C1): of two quick trumpet calls the second
    // finds the race begun and answers 409 already_racing (spec §11).
    $err = null;
    $room = RoomStore::mutate('rooms', $roomId, function (array $doc) use ($hostToken, $force, &$err): ?array {
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
        $custom = ($doc['config']['mode'] ?? 'classic') === 'custom';
        if ($custom) {
            // Every rider ready, or the trumpet's grace spent and forced
            // (8.5). The first call starts the grace — stamped and saved
            // even though it answers 409, so every lobby sees the countdown.
            $unready = false;
            foreach (array_keys($riders) as $i) {
                $unready = $unready || empty($doc['participants'][$i]['ready']);
            }
            $now = microtime(true);
            if ($unready && !isset($doc['trumpetAt'])) {
                $doc['trumpetAt'] = $now;
                $doc['updatedAt'] = date(DATE_ATOM);
                $err = ['status' => 409, 'code' => 'riders_not_ready', 'message' => 'Not every rider is ready — they have ' . (int) TRUMPET_GRACE . ' s to make ready.'];
                return $doc;
            }
            if ($unready && !($force && $now >= $doc['trumpetAt'] + TRUMPET_GRACE)) {
                $left = (int) ceil(max(0.0, $doc['trumpetAt'] + TRUMPET_GRACE - $now));
                $err = $left > 0
                    ? ['status' => 409, 'code' => 'riders_not_ready', 'message' => "The riders have {$left} s yet to make ready."]
                    : ['status' => 409, 'code' => 'riders_not_ready', 'message' => 'Not every rider is ready — start regardless?'];
                return null;
            }
        }
        // Classic racers keep lane order; horses take the grid in join
        // order (8.6) — slots 0/1 the front row, 2/3 behind, and so on.
        if (!$custom) {
            asort($riders);
        }
        $newLane = 0;
        $laneMap = [];
        $riderNames = [];
        $riderIds = [];
        $riderHorses = [];
        foreach ($riders as $i => $oldLane) {
            $laneMap[$oldLane] = $newLane;
            $doc['participants'][$i]['lane'] = $newLane++;
            $riderNames[] = $doc['participants'][$i]['nickname'];
            $riderIds[] = $doc['participants'][$i]['id'];
            $riderHorses[] = $doc['participants'][$i]['horse'] ?? null;
        }
        // Wagers follow their rider into the renumbered lanes; one on a
        // lane no rider holds any more is void.
        foreach ($doc['participants'] as $i => $p) {
            $pick = $p['prediction'] ?? null;
            if ($pick !== null) {
                $doc['participants'][$i]['prediction'] = $laneMap[$pick] ?? null;
            }
        }
        $opts = [];
        if ($custom) {
            $plot = Tracks::load((string) ($doc['config']['track'] ?? ''));
            if ($plot === null) {
                $err = ['status' => 404, 'code' => 'track_not_found', 'message' => 'The course hath vanished from the scrolls.'];
                return null;
            }
            $opts = [
                'mode' => 'custom',
                'horses' => $riderHorses, // null = Random, drawn from the seed
                'track' => $plot['slug'],
                'laps' => (int) ($doc['config']['laps'] ?? 1),
                'segments' => $plot['segments'],
            ];
            unset($doc['trumpetAt']);
        }
        $seed = RaceEngine::prngNew();
        $race = RaceEngine::create($seed, count($riders), $opts);
        $race['seed'] = base64_encode(pack('N', $seed));
        $race['startedAt'] = microtime(true);
        $race['finishedAt'] = null;
        // Nicknames and participant ids by lane, frozen at the start (4.2):
        // later joins, leaves and re-attaches can never relabel a chariot
        // of this race, and a rematch knows whom each wager backed (5.3).
        $race['names'] = $riderNames;
        $race['riders'] = $riderIds;
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
