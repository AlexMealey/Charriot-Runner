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
    $room = RoomStore::getRoom($roomId);
    if ($room === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    // A cached identity (4.9): quitting only flags the row `left`, so a
    // matching id+token re-attaches the same player — name, wager and
    // id kept, no second roster row.
    $re = null;
    foreach ($room['participants'] as $i => $p) {
        if ($p['id'] === $participantId && $p['token'] === $token) {
            $re = $i;
            break;
        }
    }
    if ($re !== null) {
        $me = $room['participants'][$re];
        // The seat is re-claimed as a fresh join claims it (spec §11):
        // their own racer lane back while it stands free, else the
        // lowest open one, else spectator.
        $taken = [];
        foreach ($room['participants'] as $j => $p) {
            if ($j !== $re && empty($p['left']) && $p['role'] === 'racer') {
                $taken[] = $p['lane'];
            }
        }
        if ($me['role'] === 'racer' && count($taken) < $room['config']['maxRacers']) {
            $role = 'racer';
            $lane = $me['lane'];
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
        $room['participants'][$re]['role'] = $role;
        $room['participants'][$re]['lane'] = $lane;
        unset($room['participants'][$re]['left']);
        $room['updatedAt'] = date(DATE_ATOM);
        RoomStore::saveRoom($room);
        echo json_encode([
            'participantId' => $me['id'],
            'token' => $me['token'],
            'role' => $role,
            'snapshot' => roomSnapshot($room),
        ]);
        return;
    }
    // Left rows hold no seat (4.9), so a quit one frees a seat too.
    $seats = 0;
    foreach ($room['participants'] as $p) {
        if (empty($p['left'])) {
            $seats++;
        }
    }
    if ($seats >= $room['config']['maxJoiners']) {
        Http::error(409, 'room_full', 'Every seat in this room is taken.');
        return;
    }
    // Rider while a racer slot is free: lanes go in join order, the
    // lowest open one; past the cap (or after the start) one spectates.
    $taken = [];
    foreach ($room['participants'] as $p) {
        if ($p['role'] === 'racer' && empty($p['left'])) {
            $taken[] = $p['lane'];
        }
    }
    if ($room['status'] !== 'racing' && count($taken) < $room['config']['maxRacers']) {
        $role = 'racer';
        $lane = 0;
        while (in_array($lane, $taken, true)) {
            $lane++;
        }
    } else {
        $role = 'spectator';
        $lane = null;
    }
    $participant = [
        'id' => bin2hex(random_bytes(8)),
        'token' => bin2hex(random_bytes(32)),
        'nickname' => $nickname,
        'role' => $role,
        'lane' => $lane,
        'joinedAt' => date(DATE_ATOM),
        'prediction' => null,
    ];
    $room['participants'][] = $participant;
    $room['updatedAt'] = date(DATE_ATOM);
    RoomStore::saveRoom($room);
    echo json_encode([
        'participantId' => $participant['id'],
        'token' => $participant['token'],
        'role' => $participant['role'],
        'snapshot' => roomSnapshot($room),
    ]);
}
