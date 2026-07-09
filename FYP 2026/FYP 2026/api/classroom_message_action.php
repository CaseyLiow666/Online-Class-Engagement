<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/classroom_message_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$uid = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? '');

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$messageId = (int) ($_POST['message_id'] ?? 0);
$classroomId = (int) ($_POST['classroom_id'] ?? 0);
$body = (string) ($_POST['body'] ?? '');

if ($messageId < 1 || $classroomId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

if ($action === 'delete') {
    $result = classroom_message_delete($mysqli, $uid, $role, $messageId, $classroomId);
    if (!$result['ok']) {
        http_response_code($result['code']);
        echo json_encode(['ok' => false, 'error' => $result['error']]);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'edit') {
    $result = classroom_message_update($mysqli, $uid, $role, $messageId, $classroomId, $body);
    if (!$result['ok']) {
        http_response_code($result['code']);
        echo json_encode(['ok' => false, 'error' => $result['error']]);
        exit;
    }
    echo json_encode(['ok' => true, 'body' => $result['body']]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
