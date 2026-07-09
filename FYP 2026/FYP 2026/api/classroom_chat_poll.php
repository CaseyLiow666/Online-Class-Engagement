<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$uid = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? '');
$classroomId = (int) ($_GET['classroom_id'] ?? 0);
$channelId = (int) ($_GET['channel_id'] ?? 0);
$since = (int) ($_GET['since'] ?? 0);

if ($classroomId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$allowed = false;
if ($role === 'teacher') {
    $allowed = classroom_teacher_owns($mysqli, $uid, $classroomId);
} elseif ($role === 'student') {
    $allowed = classroom_student_member($mysqli, $uid, $classroomId);
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if ($channelId < 1) {
    require_once dirname(__DIR__) . '/includes/channel_lib.php';
    $channelId = classroom_ensure_general_channel($mysqli, $classroomId);
} else {
    $chk = $mysqli->prepare('SELECT id FROM channels WHERE id = ? AND classroom_id = ? LIMIT 1');
    $chk->bind_param('ii', $channelId, $classroomId);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        $chk->close();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid channel']);
        exit;
    }
    $chk->close();
}

$rows = [];
$baseSql =
    'SELECT m.id, m.body, m.message_type, m.created_at, m.user_id, u.username, u.role AS user_role, u.full_name
     FROM classroom_messages m
     INNER JOIN users u ON u.id = m.user_id
     WHERE m.classroom_id = ? AND m.channel_id = ?';

if ($since === 0) {
    $stmt = $mysqli->prepare($baseSql . ' ORDER BY m.id DESC LIMIT 80');
    $stmt->bind_param('ii', $classroomId, $channelId);
    $stmt->execute();
    $res = $stmt->get_result();
    $tmp = [];
    while ($row = $res->fetch_assoc()) {
        $tmp[] = $row;
    }
    $stmt->close();
    $tmp = array_reverse($tmp);
    foreach ($tmp as $row) {
        $fn = trim((string) ($row['full_name'] ?? ''));
        $rows[] = [
            'id' => (int) $row['id'],
            'body' => $row['body'],
            'message_type' => (string) ($row['message_type'] ?? 'text'),
            'created_at' => $row['created_at'],
            'username' => $row['username'],
            'display' => $fn !== '' ? $fn : $row['username'],
            'is_teacher' => $row['user_role'] === 'teacher',
        ];
    }
} else {
    $stmt = $mysqli->prepare($baseSql . ' AND m.id > ? ORDER BY m.id ASC LIMIT 120');
    $stmt->bind_param('iii', $classroomId, $channelId, $since);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $fn = trim((string) ($row['full_name'] ?? ''));
        $rows[] = [
            'id' => (int) $row['id'],
            'body' => $row['body'],
            'message_type' => (string) ($row['message_type'] ?? 'text'),
            'created_at' => $row['created_at'],
            'username' => $row['username'],
            'display' => $fn !== '' ? $fn : $row['username'],
            'is_teacher' => $row['user_role'] === 'teacher',
        ];
    }
    $stmt->close();
}

echo json_encode(['ok' => true, 'messages' => $rows]);
