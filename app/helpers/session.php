<?php
declare(strict_types=1);

function request_is_post(): bool
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function flash(string $key, mixed $value = null): mixed
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }

    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }

    $stored = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $stored;
}
