<?php

declare(strict_types=1);

require __DIR__ . '/lib/RaceEngine.php';
require __DIR__ . '/lib/RoomStore.php';

/** Practice snapshot shared by create_practice and practice_state. */
function practiceSnapshot(array $doc): array
{
    return [
        'tick' => $doc['tick'],
        't' => $doc['t'],
        'n' => $doc['n'],
        'finished' => $doc['finished'],
        'placements' => $doc['placements'],
        'racers' => $doc['racers'],
        'names' => $doc['names'],
        'startedAt' => $doc['startedAt'],
    ];
}

/** Room snapshot shared by join_room and room_state (spec §5). */
function roomSnapshot(array $room): array
{
    $participants = [];
    foreach ($room['participants'] as $p) {
        $participants[] = [
            'id' => $p['id'],
            'nickname' => $p['nickname'],
            'role' => $p['role'],
            'lane' => $p['lane'],
        ];
    }
    return [
        'id' => $room['id'],
        'status' => $room['status'],
        'config' => $room['config'],
        'participants' => $participants,
        'raceNo' => $room['raceNo'],
        'race' => raceSnapshot($room['race']),
    ];
}

/**
 * Stored engine race as clients see it (spec §4/§5): positions and placements
 * only, same shape as practiceSnapshot — seed, PRNG state, ranks and surge
 * parameters stay server-side (spec §9) and are used by the stored doc alone.
 */
function raceSnapshot(?array $race): ?array
{
    if ($race === null) {
        return null;
    }
    $racers = [];
    foreach ($race['racers'] as $rc) {
        $racers[] = [
            'lane' => $rc['lane'],
            'p' => $rc['p'],
            'done' => $rc['done'],
            'place' => $rc['place'],
        ];
    }
    $placements = [];
    foreach ($race['placements'] as $pl) {
        $placements[] = ['lane' => $pl['lane'], 'place' => $pl['place']];
    }
    return [
        'tick' => $race['tick'],
        't' => $race['t'],
        'n' => $race['n'],
        'finished' => $race['finished'],
        'placements' => $placements,
        'racers' => $racers,
        'startedAt' => $race['startedAt'],
        'finishedAt' => $race['finishedAt'],
    ];
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? 'hello';

switch ($action) {
    case 'health':
        echo json_encode([
            'status' => 'ok',
            'time' => date(DATE_ATOM),
            'php' => PHP_VERSION,
        ]);
        break;

    case 'echo':
        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'body' => $body,
        ]);
        break;

    case 'seed':
        // 8 random seed bytes masked with a per-request key: base64((seed ^ key) || key)
        $seed = random_bytes(8);
        $key = random_bytes(8);
        echo json_encode([
            'seed' => base64_encode(($seed ^ $key) . $key),
        ]);
        break;

    case 'hello':
        echo json_encode([
            'message' => 'Hello from the Chariot Racing PHP API',
        ]);
        break;

    case 'create_room':
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($body)) {
            $body = [];
        }
        $maxRacers = $body['maxRacers'] ?? null;
        $maxJoiners = $body['maxJoiners'] ?? null;
        if (
            !is_int($maxRacers) || $maxRacers < 2 || $maxRacers > 8 ||
            !is_int($maxJoiners) || $maxJoiners < 2 || $maxJoiners > 16
        ) {
            http_response_code(400);
            echo json_encode([
                'error' => ['code' => 'bad_request', 'message' => 'maxRacers must be 2..8 and maxJoiners 2..16'],
            ]);
            break;
        }
        $room = RoomStore::createRoom(['maxRacers' => $maxRacers, 'maxJoiners' => $maxJoiners]);
        RoomStore::saveRoom($room);
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        echo json_encode([
            'roomId' => $room['id'],
            'hostToken' => $room['hostToken'],
            'joinUrl' => $origin . '/?room=' . $room['id'],
        ]);
        break;

    case 'join_room':
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($body)) {
            $body = [];
        }
        $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
        $nickname = is_string($body['nickname'] ?? null) ? trim($body['nickname']) : '';
        $nickname = mb_substr($nickname, 0, 24);
        if ($nickname === '') {
            http_response_code(400);
            echo json_encode([
                'error' => ['code' => 'bad_request', 'message' => 'The heralds need a name (24 characters at most).'],
            ]);
            break;
        }
        $room = RoomStore::getRoom($roomId);
        if ($room === null) {
            http_response_code(404);
            echo json_encode([
                'error' => ['code' => 'room_not_found', 'message' => 'No such room.'],
            ]);
            break;
        }
        if (count($room['participants']) >= $room['config']['maxJoiners']) {
            http_response_code(409);
            echo json_encode([
                'error' => ['code' => 'room_full', 'message' => 'Every seat in this room is taken.'],
            ]);
            break;
        }
        // Rider while a racer slot is free: lanes go in join order, the
        // lowest open one; past the cap (or after the start) one spectates.
        $taken = [];
        foreach ($room['participants'] as $p) {
            if ($p['role'] === 'racer') {
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
        break;

    case 'room_state':
        $id = (string) ($_GET['room'] ?? '');
        $room = RoomStore::getRoom($id);
        if ($room === null) {
            http_response_code(404);
            echo json_encode([
                'error' => ['code' => 'room_not_found', 'message' => 'No such room.'],
            ]);
            break;
        }
        // A poll is an interaction (spec §8): it only bumps updatedAt — the
        // snapshot itself stays put until Stage 4.2 teaches it race catch-up.
        // `tick`/`token` are accepted (spec §5) but unused until then.
        $room['updatedAt'] = date(DATE_ATOM);
        RoomStore::saveRoom($room);
        echo json_encode(roomSnapshot($room));
        break;

    case 'leave_room':
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($body)) {
            $body = [];
        }
        $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
        $token = is_string($body['token'] ?? null) ? $body['token'] : '';
        $room = RoomStore::getRoom($roomId);
        if ($room === null) {
            http_response_code(404);
            echo json_encode([
                'error' => ['code' => 'room_not_found', 'message' => 'No such room.'],
            ]);
            break;
        }
        $left = null;
        foreach ($room['participants'] as $i => $p) {
            if ($p['token'] === $token) {
                unset($room['participants'][$i]); // a racer lane frees up
                $left = $p;
                break;
            }
        }
        if ($left === null) {
            http_response_code(403);
            echo json_encode([
                'error' => ['code' => 'not_a_participant', 'message' => 'Thou art not of this room.'],
            ]);
            break;
        }
        $room['participants'] = array_values($room['participants']);
        $room['updatedAt'] = date(DATE_ATOM);
        RoomStore::saveRoom($room);
        echo json_encode(['ok' => true]);
        break;

    case 'start_race':
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($body)) {
            $body = [];
        }
        $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
        $hostToken = is_string($body['hostToken'] ?? null) ? $body['hostToken'] : '';
        $room = RoomStore::getRoom($roomId);
        if ($room === null) {
            http_response_code(404);
            echo json_encode([
                'error' => ['code' => 'room_not_found', 'message' => 'No such room.'],
            ]);
            break;
        }
        if ($room['hostToken'] !== $hostToken) {
            http_response_code(403);
            echo json_encode([
                'error' => ['code' => 'not_host', 'message' => 'The host alone may call the race.'],
            ]);
            break;
        }
        if ($room['status'] !== 'lobby') {
            http_response_code(409);
            echo json_encode([
                'error' => ['code' => 'already_racing', 'message' => 'The chariots are already upon the track.'],
            ]);
            break;
        }
        // Racers in lane order; lanes are renumbered 0..n-1 so one freed by
        // an early leave leaves no gap under the engine's lanes.
        $riders = [];
        foreach ($room['participants'] as $i => $p) {
            if ($p['role'] === 'racer') {
                $riders[$i] = $p['lane'];
            }
        }
        if (count($riders) < 2) {
            http_response_code(409);
            echo json_encode([
                'error' => ['code' => 'too_few_racers', 'message' => 'Two riders at least must take the track.'],
            ]);
            break;
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
        break;

    case 'create_practice':
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($body)) {
            $body = [];
        }
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
        break;

    case 'practice_state':
        $id = (string) ($_GET['race'] ?? '');
        $doc = RoomStore::get('practice', $id);
        if ($doc === null) {
            http_response_code(404);
            echo json_encode([
                'error' => ['code' => 'practice_not_found', 'message' => 'No such practice race'],
            ]);
            break;
        }
        // Catch up one fixed tick at a time, recording each tick for the
        // history (advance() has no recording hook; same 900-step cap).
        $targetTick = (int) floor((microtime(true) - (float) $doc['startedAt']) * 60);
        $lines = [];
        for ($steps = 0; $doc['tick'] < $targetTick && $steps < 900; $steps++) {
            RaceEngine::step($doc);
            $slim = [];
            foreach ($doc['racers'] as $rc) {
                $slim[] = [
                    'lane' => $rc['lane'],
                    'p' => $rc['p'],
                    'done' => $rc['done'],
                    'place' => $rc['place'],
                ];
            }
            $lines[] = json_encode([
                'tick' => $doc['tick'],
                't' => $doc['t'],
                'racers' => $slim,
                'placements' => $doc['placements'],
            ]);
        }
        RoomStore::appendTicks('practice', $id, 1, $lines);
        RoomStore::put('practice', $id, $doc);
        echo json_encode(practiceSnapshot($doc));
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action: ' . $action]);
}
