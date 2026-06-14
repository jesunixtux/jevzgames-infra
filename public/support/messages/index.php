<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\Support;
use App\Security\Auth;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$ticketId = (int) ($_GET['ticket_id'] ?? 0);
$afterId = (int) ($_GET['after_id'] ?? 0);

if ($ticketId <= 0) {
    api_response(false, 'Ticket invalido.', [], 400);
}

$ticket = Support::findTicket($ticketId, $userId);
if (!$ticket) {
    api_response(false, 'Ticket no encontrado.', [], 404);
}

api_response(true, 'OK', [
    'ticket' => [
        'id' => (int) $ticket['id'],
        'status' => $ticket['status'],
        'can_reply' => Support::canReply($ticket),
        'time_left_seconds' => Support::timeLeftSeconds($ticket),
    ],
    'messages' => Support::messages($ticketId, $afterId),
]);
