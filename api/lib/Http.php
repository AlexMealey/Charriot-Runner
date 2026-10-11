<?php

declare(strict_types=1);

/**
 * Request/response helpers shared by the action handlers: one place for
 * JSON body parsing and the spec section 5 error envelope
 * {"error": {"code", "message"}}.
 */
class Http
{
    /** JSON request body; anything unreadable or non-object reads as []. */
    public static function body(): array
    {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        return is_array($body) ? $body : [];
    }

    /** Spec section 5 error envelope, sent with its HTTP status. */
    public static function error(int $status, string $code, string $message): void
    {
        http_response_code($status);
        echo json_encode([
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
