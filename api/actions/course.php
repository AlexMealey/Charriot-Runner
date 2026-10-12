<?php

declare(strict_types=1);

/**
 * The map course (Stage 9) the current race of a room or practice rides:
 * its frozen loop (points, straight/turn table, length), the map image and
 * its viewBox. Fetched once per race — snapshots carry a `courseKey` that
 * changes with every race. Drawn courses come from `track` instead.
 */
function action_course(): void
{
    $room = $_GET['room'] ?? '';
    $practice = $_GET['practice'] ?? '';
    $race = null;
    if (is_string($room) && $room !== '') {
        $race = RoomStore::getRoom($room)['race'] ?? null;
    } elseif (is_string($practice) && $practice !== '') {
        $race = RoomStore::get('practice', $practice);
    }
    $course = is_array($race) ? ($race['course'] ?? null) : null;
    if (!is_array($course) || !isset($course['points'])) {
        Http::error(404, 'course_not_found', 'No road is laid for this race.');
        return;
    }
    echo json_encode($course, JSON_UNESCAPED_SLASHES);
}
