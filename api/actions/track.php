<?php

declare(strict_types=1);

/**
 * One course's plot (8.1) for the race view: name, loop, viewBox, path,
 * length, straight/turn table and evenly spaced points to draw and place
 * the horses on.
 */
function action_track(): void
{
    $slug = $_GET['slug'] ?? '';
    $plot = is_string($slug) ? Tracks::load($slug) : null;
    if ($plot === null) {
        Http::error(404, 'track_not_found', 'No such course is known to the heralds.');
        return;
    }
    echo json_encode(Tracks::clientPlot($plot));
}
