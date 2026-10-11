<?php

declare(strict_types=1);

function action_echo(): void
{
    $body = Http::body();
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'body' => $body,
    ]);
}
