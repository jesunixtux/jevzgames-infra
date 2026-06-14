<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\DirectMessage;
use App\Security\Auth;

require_installed();

$user = Auth::user();
if (!$user) {
    api_response(false, 'No autenticado.', [], 401);
}

$userId = (int) ($user['id'] ?? 0);
$threadId = (int) ($_GET['thread'] ?? 0);
$afterId = (int) ($_GET['after_id'] ?? 0);

try {
    $messages = DirectMessage::messagesAfter($threadId, $userId, $afterId);
    api_response(true, 'OK', [
        'messages' => array_map(static function (array $message): array {
            return [
                'id' => (int) $message['id'],
                'sender_user_id' => (int) $message['sender_user_id'],
                'sender_username' => (string) $message['sender_username'],
                'message' => (string) $message['message'],
                'created_at' => (string) $message['created_at'],
            ];
        }, $messages),
        'unread_messages' => DirectMessage::unreadCount($userId),
    ]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
