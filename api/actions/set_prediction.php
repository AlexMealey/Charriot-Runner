<?php

declare(strict_types=1);

function action_set_prediction(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    $pick = $body['pick'] ?? null;
    if (RoomStore::getRoom($roomId) === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    // Identity, the lobby gate and the pick are judged inside one locked
    // read-modify-write (review C1): a wager can never land on a document
    // the start has already moved on.
    $err = null;
    $room = RoomStore::mutate('rooms', $roomId, function (array $doc) use ($token, $pick, &$err): ?array {
        $self = null;
        foreach ($doc['participants'] as $idx => $p) {
            if ($p['token'] === $token) {
                $self = $idx;
                break;
            }
        }
        if ($self === null) {
            $err = ['status' => 403, 'code' => 'not_a_participant', 'message' => 'Thou art not of this room.'];
            return null;
        }
        if ($doc['status'] !== 'lobby') {
            $err = ['status' => 409, 'code' => 'race_started', 'message' => 'The wagers are sealed — the race hath begun.'];
            return null;
        }
        $lanes = [];
        foreach ($doc['participants'] as $p) {
            if ($p['role'] === 'racer' && empty($p['left'])) {
                $lanes[] = $p['lane'];
            }
        }
        if (!is_int($pick) || !in_array($pick, $lanes, true)) {
            $err = ['status' => 400, 'code' => 'bad_request', 'message' => 'Back one of the racers upon the track.'];
            return null;
        }
        $doc['participants'][$self]['prediction'] = $pick;
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
