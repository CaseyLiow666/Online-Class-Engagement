<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';
require_once dirname(__DIR__) . '/includes/quiz_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$classroomId = (int) ($_POST['classroom_id'] ?? 0);
$quizId = (int) ($_POST['quiz_id'] ?? 0);
$returnTo = trim((string) ($_POST['return_to'] ?? 'classroom'));

if ($quizId < 1) {
    $_SESSION['flash'] = 'Invalid request.';
    header('Location: dashboard.php');
    exit;
}

if ($returnTo === 'dashboard') {
    if (!quiz_teacher_owns($mysqli, $quizId, $userId)) {
        $_SESSION['flash'] = 'Quiz not found.';
    } else {
        $del = $mysqli->prepare('DELETE FROM quizzes WHERE id = ? AND teacher_id = ?');
        $del->bind_param('ii', $quizId, $userId);
        $del->execute();
        $del->close();
        $_SESSION['flash'] = 'Quiz deleted.';
    }

    header('Location: dashboard.php');
    exit;
}

if ($classroomId < 1 || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
    $_SESSION['flash'] = 'Invalid request.';
    header('Location: dashboard.php');
    exit;
}

$chk = $mysqli->prepare(
    'SELECT q.id FROM quizzes q
     WHERE q.id = ? AND q.teacher_id = ? AND q.classroom_id = ?
     LIMIT 1'
);
$chk->bind_param('iii', $quizId, $userId, $classroomId);
$chk->execute();
$exists = (bool) $chk->get_result()->fetch_assoc();
$chk->close();

if (!$exists) {
    $_SESSION['flash'] = 'Quiz not found.';
} else {
    $del = $mysqli->prepare('DELETE FROM quizzes WHERE id = ? AND teacher_id = ?');
    $del->bind_param('ii', $quizId, $userId);
    $del->execute();
    $del->close();
    $_SESSION['flash'] = 'Quiz deleted.';
}

header('Location: classroom.php?id=' . $classroomId . '&tab=quizzes');
exit;
