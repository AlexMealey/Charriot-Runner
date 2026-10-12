<?php

declare(strict_types=1);

function action_leave_room(): void
{
    $body = Http::body();
    $roomId = is_string($body['roomId'] ?? null) ? $body['roomId'] : '';
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    if (RoomStore::getRoom($roomId) === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    // One locked read-modify-write (review C1): a leave can neither undo a
    // concurrent join nor save a stale race over the catch-up.
    $err = null;
    $room = RoomStore::mutate('rooms', $roomId, function (array $doc) use ($token, &$err): ?array {
        $self = null;
        foreach ($doc['participants'] as $i => $p) {
            if ($p['token'] === $token) {
                $self = $i;
                break;
            }
        }
        if ($self === null) {
            $err = ['status' => 403, 'code' => 'not_a_participant', 'message' => 'Thou art not of this room.'];
            return null;
        }
        $was = $doc['participants'][$self];
        // The row survives the quitting (4.9): only flagged, so a kept
        // id+token can re-attach the player later. Counts ignore it — a
        // racer lane frees up (3.5).
        $doc['participants'][$self]['left'] = true;
        $doc['participants'][$self]['ready'] = false; // 8.4: a quitter is never ready
        // A rider quitting the lobby voids the wagers laid on their lane,
        // lest the next soul to claim that lane inherit them.
        if ($doc['status'] === 'lobby' && $was['role'] === 'racer' && empty($was['left'])) {
            foreach ($doc['participants'] as $j => $p) {
                if (($p['prediction'] ?? null) === $was['lane']) {
                    $doc['participants'][$j]['prediction'] = null;
                }
            }
        }
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
