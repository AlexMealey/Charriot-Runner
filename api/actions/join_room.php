<?php

declare(strict_types=1);

function action_join_room(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $nickname = is_string($body['nickname'] ?? null) ? trim($body['nickname']) : '';
    $nickname = mb_substr($nickname, 0, 24);
    // Optional cached identity (4.9): a quitter's kept id+token.
    $participantId = is_string($body['participantId'] ?? null) ? $body['participantId'] : '';
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    if ($nickname === '') {
        Http::error(400, 'bad_request', 'The heralds need a name (24 characters at most).');
        return;
    }
    if (RoomStore::getRoom($roomId) === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    // Seats, lanes and the roster are judged inside one locked
    // read-modify-write (review C1): two souls joining at once can neither
    // overwrite each other nor claim the same lane, and a mid-race join
    // never saves a stale race over the catch-up.
    $err = null;
    $me = null;
    $room = RoomStore::mutate('rooms', $roomId, function (array $doc) use ($nickname, $participantId, $token, &$err, &$me): ?array {
        // A cached identity (4.9): quitting only flags the row `left`, so a
        // matching id+token re-attaches the same player — name, wager and
        // id kept, no second roster row.
        $re = null;
        foreach ($doc['participants'] as $i => $p) {
            if ($p['id'] === $participantId && $p['token'] === $token) {
                $re = $i;
                break;
            }
        }
        // Left rows hold no seat (4.9), so a quit one frees a seat too. A
        // returning quitter needs a free seat like any newcomer; a reload
        // (row never left) already holds one.
        $seats = 0;
        foreach ($doc['participants'] as $p) {
            if (empty($p['left'])) {
                $seats++;
            }
        }
        $needsSeat = $re === null || !empty($doc['participants'][$re]['left']);
        if ($needsSeat && $seats >= $doc['config']['maxJoiners']) {
            $err = ['status' => 409, 'code' => 'room_full', 'message' => 'Every seat in this room is taken.'];
            return null;
        }
        if ($re !== null) {
            $was = $doc['participants'][$re];
            // The seat is re-claimed as a fresh join claims it (spec §11):
            // their own racer lane back while it stands free, else the
            // lowest open one, else spectator.
            $taken = [];
            foreach ($doc['participants'] as $j => $p) {
                if ($j !== $re && empty($p['left']) && $p['role'] === 'racer') {
                    $taken[] = $p['lane'];
                }
            }
            if ($was['role'] === 'racer' && count($taken) < $doc['config']['maxRacers']) {
                $role = 'racer';
                $lane = $was['lane'];
                if (in_array($lane, $taken, true)) {
                    $lane = 0;
                    while (in_array($lane, $taken, true)) {
                        $lane++;
                    }
                }
            } else {
                $role = 'spectator';
                $lane = null;
            }
            $doc['participants'][$re]['role'] = $role;
            $doc['participants'][$re]['lane'] = $lane;
            unset($doc['participants'][$re]['left']);
            $doc['updatedAt'] = date(DATE_ATOM);
            $me = $doc['participants'][$re];
            return $doc;
        }
        // Rider while a racer slot is free: lanes go in join order, the
        // lowest open one; past the cap (or after the start) one spectates.
        $taken = [];
        foreach ($doc['participants'] as $p) {
            if ($p['role'] === 'racer' && empty($p['left'])) {
                $taken[] = $p['lane'];
            }
        }
        if ($doc['status'] !== 'racing' && count($taken) < $doc['config']['maxRacers']) {
            $role = 'racer';
            $lane = 0;
            while (in_array($lane, $taken, true)) {
                $lane++;
            }
        } else {
            $role = 'spectator';
            $lane = null;
        }
        $me = [
            'id' => bin2hex(random_bytes(8)),
            'token' => bin2hex(random_bytes(32)),
            'nickname' => $nickname,
            'role' => $role,
            'lane' => $lane,
            'joinedAt' => date(DATE_ATOM),
            'prediction' => null,
            // Horses rooms (8.4): no steed picked yet (Random), not ready.
            'horse' => null,
            'ready' => false,
        ];
        $doc['participants'][] = $me;
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
    echo json_encode([
        'participantId' => $me['id'],
        'token' => $me['token'],
        'role' => $me['role'],
        'snapshot' => roomSnapshot($room),
    ]);
}
