<?php

declare(strict_types=1);

function action_create_room(): void
{
    $body = Http::body();
    $maxRacers = $body['maxRacers'] ?? null;
    $maxJoiners = $body['maxJoiners'] ?? null;
    // Max joiners counts racers + spectators (spec §2), so never fewer
    // than the racers it must seat.
    if (
        !is_int($maxRacers) || $maxRacers < 2 || $maxRacers > 8 ||
        !is_int($maxJoiners) || $maxJoiners < 2 || $maxJoiners > 16 ||
        $maxJoiners < $maxRacers
    ) {
        Http::error(400, 'bad_request', 'Chariots number 2 to 8 and souls 2 to 16 — never fewer souls than chariots.');
        return;
    }
    $room = RoomStore::createRoom(['maxRacers' => $maxRacers, 'maxJoiners' => $maxJoiners]);
    if (!RoomStore::saveRoom($room)) {
        // never hand out a room that was not written (code review M1)
        Http::error(500, 'storage_error', 'The scroll would not unroll.');
        return;
    }
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    echo json_encode([
        'roomId' => $room['id'],
        'hostToken' => $room['hostToken'],
        'joinUrl' => $origin . '/?room=' . $room['id'],
    ]);
}
