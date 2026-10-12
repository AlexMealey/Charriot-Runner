<?php

declare(strict_types=1);

/**
 * The map course (Stage 9) the current race of a room or practice rides:
 * its frozen loops (one, or one per lap — points, straight/turn tables,
 * lengths, lap times), the map image and its viewBox, as stored beside
 * the race when it began. Fetched once per race — snapshots carry a
 * `courseKey` that changes with every race. Drawn courses come from
 * `track` instead.
 */
function action_course(): void
{
    $room = $_GET['room'] ?? '';
    $practice = $_GET['practice'] ?? '';
    $course = null;
    if (is_string($room) && $room !== '') {
        $doc = RoomStore::getRoom($room);
        if ($doc !== null) {
            $course = RoomStore::getExtra('rooms', $room, 'course-' . (int) $doc['raceNo']);
        }
    } elseif (is_string($practice) && $practice !== '') {
        $course = RoomStore::getExtra('practice', $practice, 'course-1');
    }
    if (!is_array($course) || !isset($course['loops'])) {
        Http::error(404, 'course_not_found', 'No road is laid for this race.');
        return;
    }
    echo json_encode($course, JSON_UNESCAPED_SLASHES);
}
