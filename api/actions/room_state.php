<?php

declare(strict_types=1);

function action_room_state(): void
{
    $id = (string) ($_GET['room'] ?? '');
    if (RoomStore::getRoom($id) === null) {
        Http::error(404, 'room_not_found', 'No such room.');
        return;
    }
    // A poll is an interaction (spec §8): it bumps updatedAt and, while
    // the race runs, catches the sim up to now — read, catch-up, tick
    // appends and save held under one lock (step 4.2, review C1).
    // `tick`/`token` stay accepted but unused (spec §5 delta form: not built).
    $room = RoomStore::mutate('rooms', $id, function (array $doc): array {
        $doc['updatedAt'] = date(DATE_ATOM);
        if ($doc['status'] === 'racing') {
            catchUpRoomRace($doc);
        }
        return $doc;
    });
    if ($room === null) {
        Http::error(500, 'storage_error', 'The scroll would not unroll.');
        return;
    }
    echo json_encode(roomSnapshot($room));
}
