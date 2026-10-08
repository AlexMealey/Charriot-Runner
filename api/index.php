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
