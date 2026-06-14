<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\DirectMessage;
use App\Models\Notification;
use App\Security\Auth;

require_installed();

$user = Auth::user();
if (!$user) {
    api_response(false, 'No autenticado.', [], 401);
}

$userId = (int) ($user['id'] ?? 0);
api_response(true, 'OK', [
    'unread_count' => Notification::unreadCount($userId),
    'unread_messages' => DirectMessage::unreadCount($userId),
]);
