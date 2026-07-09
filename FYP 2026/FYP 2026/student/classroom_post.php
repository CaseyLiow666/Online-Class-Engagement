<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';
require_once dirname(__DIR__) . '/includes/channel_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$classroomId = (int) ($_POST['classroom_id'] ?? 0);
$body = trim((string) ($_POST['body'] ?? ''));

if ($classroomId < 1 || $body === '' || strlen($body) > 4000) {
    $_SESSION['flash'] = 'Message cannot be empty (max 4000 characters).';
    header('Location: classroom.php?id=' . $classroomId . '&tab=chat');
    exit;
}

if (!classroom_student_member($mysqli, $userId, $classroomId)) {
    header('Location: index.php');
    exit;
}

$channelId = classroom_ensure_general_channel($mysqli, $classroomId);
$messageType = 'text';
$stmt = $mysqli->prepare(
    'INSERT INTO classroom_messages (classroom_id, channel_id, user_id, body, message_type) VALUES (?,?,?,?,?)'
);
$stmt->bind_param('iiiss', $classroomId, $channelId, $userId, $body, $messageType);
$stmt->execute();
$stmt->close();

header('Location: classroom.php?id=' . $classroomId . '&tab=chat');
exit;
