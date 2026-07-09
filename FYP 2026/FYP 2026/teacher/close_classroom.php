<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$classroomId = (int) ($_POST['classroom_id'] ?? 0);
if ($classroomId < 1 || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
    $_SESSION['flash'] = 'Class not found.';
    header('Location: dashboard.php');
    exit;
}

$upd = $mysqli->prepare('UPDATE classrooms SET status = 0 WHERE id = ? AND teacher_id = ?');
$upd->bind_param('ii', $classroomId, $userId);
$upd->execute();
$upd->close();

$_SESSION['flash'] = 'Class closed. Students can no longer access it or submit work.';
header('Location: dashboard.php');
exit;
