<?php
declare(strict_types=1);

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_response(bool $success, string $message = 'OK', array $data = [], int $status = 200): never
{
    json_response([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], $status);
}
