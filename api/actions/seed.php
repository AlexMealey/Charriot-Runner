<?php

declare(strict_types=1);

function action_seed(): void
{
    // 8 random seed bytes masked with a per-request key: base64((seed ^ key) || key)
    $seed = random_bytes(8);
    $key = random_bytes(8);
    echo json_encode([
        'seed' => base64_encode(($seed ^ $key) . $key),
    ]);
}
