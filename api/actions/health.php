<?php

declare(strict_types=1);

function action_health(): void
{
    echo json_encode([
        'status' => 'ok',
        'time' => date(DATE_ATOM),
        'php' => PHP_VERSION,
    ]);
}
