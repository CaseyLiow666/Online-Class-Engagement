<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$classroomId = (int) ($_POST['classroom_id'] ?? 0);
$attendanceId = (int) ($_POST['attendance_id'] ?? 0);
$returnTab = trim((string) ($_POST['return_tab'] ?? 'reports'));
$returnRtab = trim((string) ($_POST['return_rtab'] ?? 'engagement'));
$repName = trim((string) ($_POST['rq'] ?? ''));
$repDate = trim((string) ($_POST['rdate'] ?? ''));
$repStatus = trim((string) ($_POST['rstatus'] ?? ''));

if ($classroomId < 1 || $attendanceId < 1 || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
    $_SESSION['flash'] = 'Invalid request.';
    header('Location: dashboard.php');
    exit;
}

$chk = $mysqli->prepare(
    'SELECT a.id FROM attendance a
     INNER JOIN classrooms cl ON cl.id = a.class_id AND cl.teacher_id = ?
     WHERE a.id = ? AND a.class_id = ?
     LIMIT 1'
);
$chk->bind_param('iii', $userId, $attendanceId, $classroomId);
$chk->execute();
$exists = (bool) $chk->get_result()->fetch_assoc();
$chk->close();

if (!$exists) {
    $_SESSION['flash'] = 'Attendance record not found.';
} else {
    $del = $mysqli->prepare('DELETE FROM attendance WHERE id = ?');
    $del->bind_param('i', $attendanceId);
    $del->execute();
    $del->close();
    $_SESSION['flash'] = 'Attendance record deleted.';
}

$redirect = 'classroom.php?id=' . $classroomId . '&tab=' . rawurlencode($returnTab);
if ($returnTab === 'reports') {
    $redirect .= '&rtab=' . rawurlencode($returnRtab !== '' ? $returnRtab : 'engagement');
}
if ($repName !== '') {
    $redirect .= '&rq=' . rawurlencode($repName);
}
if ($repDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $repDate)) {
    $redirect .= '&rdate=' . rawurlencode($repDate);
}
if (in_array($repStatus, ['present', 'absent'], true)) {
    $redirect .= '&rstatus=' . rawurlencode($repStatus);
}

header('Location: ' . $redirect);
exit;
