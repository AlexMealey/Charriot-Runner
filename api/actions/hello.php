<?php

declare(strict_types=1);

function action_hello(): void
{
    echo json_encode([
        'message' => 'Hello from the Chariot Racing PHP API',
    ]);
}
