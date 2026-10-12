<?php

declare(strict_types=1);

/**
 * A rider stands ready — or stands down — in a horses-room lobby (8.4).
 * Ready locks the steed; the trumpet needs every rider ready (8.5).
 */
function action_set_ready(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    $ready = $body['ready'] ?? null;
    if (!is_bool($ready)) {
        Http::error(400, 'bad_request', 'Ready, or not ready — say which.');
        return;
    }
    if (RoomStore::getRoom($roomId) === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    $err = null;
    $room = RoomStore::mutate('rooms', $roomId, function (array $doc) use ($token, $ready, &$err): ?array {
        $self = riderIndex($doc, $token, $err);
        if ($self === null) {
            return null;
        }
        $doc['participants'][$self]['ready'] = $ready;
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
