<?php

declare(strict_types=1);

/** The horse roster (8.2) — the one source clients read stats from. */
function action_horses(): void
{
    echo json_encode(['horses' => RaceEngine::horseRoster()]);
}
