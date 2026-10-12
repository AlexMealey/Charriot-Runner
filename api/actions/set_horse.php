<?php

declare(strict_types=1);

/**
 * A rider picks their steed in a horses-room lobby (8.4): a roster slug, or
 * null for Random. Refused once the race is on, for spectators, and while
 * the rider stands ready — un-ready to change.
 */
function action_set_horse(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    $horse = $body['horse'] ?? null;
    if ($horse !== null && (!is_string($horse) || !isset(RaceEngine::HORSES[$horse]))) {
        Http::error(400, 'bad_request', 'No such horse in the stables.');
        return;
    }
    if (RoomStore::getRoom($roomId) === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    $err = null;
    $room = RoomStore::mutate('rooms', $roomId, function (array $doc) use ($token, $horse, &$err): ?array {
        $self = riderIndex($doc, $token, $err);
        if ($self === null) {
            return null;
        }
        if (!empty($doc['participants'][$self]['ready'])) {
            $err = ['status' => 409, 'code' => 'horse_locked', 'message' => 'Thou art ready — stand down to change thy steed.'];
            return null;
        }
        $doc['participants'][$self]['horse'] = $horse;
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

/**
 * The calling rider's participant index in a horses-room lobby, or null with
 * $err set: unknown token 403, race already begun 409, not a horses room or
 * not a rider 403. Shared by set_horse and set_ready.
 */
function riderIndex(array $doc, string $token, ?array &$err): ?int
{
    $self = null;
    foreach ($doc['participants'] as $i => $p) {
        if ($p['token'] === $token && empty($p['left'])) {
            $self = $i;
            break;
        }
    }
    if ($self === null) {
        $err = ['status' => 403, 'code' => 'not_a_participant', 'message' => 'Thou art not of this room.'];
        return null;
    }
    if ($doc['status'] !== 'lobby') {
        $err = ['status' => 409, 'code' => 'race_started', 'message' => 'The race hath begun — the stables are shut.'];
        return null;
    }
    if (($doc['config']['mode'] ?? 'classic') !== 'custom' || $doc['participants'][$self]['role'] !== 'racer') {
        $err = ['status' => 403, 'code' => 'not_a_rider', 'message' => 'Only riders of a horses race choose a steed.'];
        return null;
    }
    return $self;
}
