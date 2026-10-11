<?php

declare(strict_types=1);

/**
 * JSON API front controller: headers, CORS preflight and action dispatch.
 *
 * Each action handler lives in its own file under actions/ and answers
 * directly with JSON; shared server code lives in lib/ (RaceEngine sim,
 * RoomStore persistence, Http envelopes, Snapshots views).
 */

require __DIR__ . '/lib/RaceEngine.php';
require __DIR__ . '/lib/RoomStore.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Snapshots.php';

require __DIR__ . '/actions/health.php';
require __DIR__ . '/actions/echo.php';
require __DIR__ . '/actions/seed.php';
require __DIR__ . '/actions/hello.php';
require __DIR__ . '/actions/create_room.php';
require __DIR__ . '/actions/join_room.php';
require __DIR__ . '/actions/room_state.php';
require __DIR__ . '/actions/leave_room.php';
require __DIR__ . '/actions/start_race.php';
require __DIR__ . '/actions/rematch.php';
require __DIR__ . '/actions/set_prediction.php';
require __DIR__ . '/actions/create_practice.php';
require __DIR__ . '/actions/practice_state.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? 'hello';

$handlers = [
    'health' => 'action_health',
    'echo' => 'action_echo',
    'seed' => 'action_seed',
    'hello' => 'action_hello',
    'create_room' => 'action_create_room',
    'join_room' => 'action_join_room',
    'room_state' => 'action_room_state',
    'leave_room' => 'action_leave_room',
    'start_race' => 'action_start_race',
    'rematch' => 'action_rematch',
    'set_prediction' => 'action_set_prediction',
    'create_practice' => 'action_create_practice',
    'practice_state' => 'action_practice_state',
];

$handler = is_string($action) ? ($handlers[$action] ?? null) : null;
if ($handler === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown action: ' . $action]);
    exit;
}

$handler();
