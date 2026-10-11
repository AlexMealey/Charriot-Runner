<?php

declare(strict_types=1);

function action_create_room(): void
{
    $body = Http::body();
    $maxRacers = $body['maxRacers'] ?? null;
    $maxJoiners = $body['maxJoiners'] ?? null;
    if (
        !is_int($maxRacers) || $maxRacers < 2 || $maxRacers > 8 ||
        !is_int($maxJoiners) || $maxJoiners < 2 || $maxJoiners > 16
    ) {
        Http::error(400, 'bad_request', 'maxRacers must be 2..8 and maxJoiners 2..16');
        return;
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
}
