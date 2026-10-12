<?php

declare(strict_types=1);

/** Every course a horses race may run (8.1): [{slug, name, loop}]. */
function action_tracks(): void
{
    echo json_encode(['tracks' => Tracks::all()]);
}
