<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? 'hello';

switch ($action) {
    case 'health':
        echo json_encode([
            'status' => 'ok',
            'time' => date(DATE_ATOM),
            'php' => PHP_VERSION,
        ]);
        break;

    case 'echo':
        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'body' => $body,
        ]);
        break;

    case 'seed':
        // 8 random seed bytes masked with a per-request key: base64((seed ^ key) || key)
        $seed = random_bytes(8);
        $key = random_bytes(8);
        echo json_encode([
            'seed' => base64_encode(($seed ^ $key) . $key),
        ]);
        break;

    case 'hello':
        echo json_encode([
            'message' => 'Hello from the Chariot Racing PHP API',
        ]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action: ' . $action]);
}
