<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$classroomId = (int) ($_POST['classroom_id'] ?? 0);
if ($classroomId < 1 || !classroom_student_member($mysqli, $userId, $classroomId)) {
    $_SESSION['flash'] = 'You are not enrolled in that class.';
    header('Location: index.php');
    exit;
}

$del = $mysqli->prepare('DELETE FROM classroom_members WHERE classroom_id = ? AND student_id = ?');
$del->bind_param('ii', $classroomId, $userId);
$del->execute();
$del->close();

$_SESSION['flash'] = 'You have left the class.';
header('Location: index.php');
exit;
