<?php

declare(strict_types=1);

function action_leave_room(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    $room = RoomStore::getRoom($roomId);
    if ($room === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    $left = null;
    foreach ($room['participants'] as $i => $p) {
        if ($p['token'] === $token) {
            // The row survives the quitting (4.9): only flagged, so a
            // kept id+token can re-attach the player later. Counts
            // ignore it — a racer lane frees up (3.5).
            $room['participants'][$i]['left'] = true;
            $left = $p;
            break;
        }
    }
    if ($left === null) {
        Http::error(403, 'not_a_participant', 'Thou art not of this room.');
        return;
    }
    $room['updatedAt'] = date(DATE_ATOM);
    RoomStore::saveRoom($room);
    echo json_encode(['ok' => true]);
}
