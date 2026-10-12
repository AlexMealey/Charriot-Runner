<?php

declare(strict_types=1);

/**
 * JSON API front controller: headers and action dispatch. Same-origin only
 * — the SPA is served by this very container, so no CORS headers (code
 * review minor: CORS).
 *
 * Each action handler lives in its own file under actions/ and answers
 * directly with JSON; shared server code lives in lib/ (RaceEngine sim,
 * RoomStore persistence, Http envelopes, Snapshots views, Tracks courses).
 */

require __DIR__ . '/lib/RaceEngine.php';
require __DIR__ . '/lib/RoomStore.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Snapshots.php';
require __DIR__ . '/lib/Tracks.php';

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
require __DIR__ . '/actions/tracks.php';
require __DIR__ . '/actions/track.php';
require __DIR__ . '/actions/horses.php';
require __DIR__ . '/actions/set_horse.php';
require __DIR__ . '/actions/set_ready.php';

header('Content-Type: application/json; charset=utf-8');

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
    'tracks' => 'action_tracks',
    'track' => 'action_track',
    'horses' => 'action_horses',
    'set_horse' => 'action_set_horse',
    'set_ready' => 'action_set_ready',
];

$handler = is_string($action) ? ($handlers[$action] ?? null) : null;
if ($handler === null) {
    // spec §5 envelope like every other error (code review M5); the raw
    // action is never echoed — it may not even be a string
    Http::error(404, 'unknown_action', 'The sages know no such action.');
    exit;
}

$handler();
