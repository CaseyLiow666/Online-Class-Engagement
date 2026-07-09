<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$classroomId = (int) ($_POST['classroom_id'] ?? 0);
$attDate = trim((string) ($_POST['attendance_date'] ?? ''));
$statuses = $_POST['status'] ?? [];
$roster = $_POST['roster'] ?? [];

if ($classroomId < 1 || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
    $_SESSION['flash'] = 'Invalid class.';
    header('Location: dashboard.php');
    exit;
}

// Sanitize only: require a valid calendar date string; no past/future restrictions.
if ($attDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attDate)) {
    $_SESSION['flash'] = 'Error: Please choose a valid session date (YYYY-MM-DD).';
    header('Location: classroom.php?id=' . $classroomId . '&tab=attendance');
    exit;
}
$parts = array_map('intval', explode('-', $attDate));
if (count($parts) !== 3 || !checkdate($parts[1], $parts[2], $parts[0])) {
    $_SESSION['flash'] = 'Error: Please choose a valid session date (YYYY-MM-DD).';
    header('Location: classroom.php?id=' . $classroomId . '&tab=attendance');
    exit;
}

if (!is_array($statuses)) {
    $statuses = [];
}
if (!is_array($roster)) {
    $roster = [];
}

$allMembers = [];
$ms = $mysqli->prepare('SELECT cm.student_id FROM classroom_members cm WHERE cm.classroom_id = ?');
$ms->bind_param('i', $classroomId);
$ms->execute();
$mr = $ms->get_result();
while ($row = $mr->fetch_assoc()) {
    $allMembers[(int) $row['student_id']] = true;
}
$ms->close();

$targets = [];
if ($roster !== []) {
    foreach ($roster as $rid) {
        $rid = (int) $rid;
        if ($rid > 0 && isset($allMembers[$rid])) {
            $targets[$rid] = true;
        }
    }
    $targets = array_keys($targets);
} else {
    $targets = array_keys($allMembers);
}

$mysqli->begin_transaction();
try {
    $upsert = $mysqli->prepare(
        'INSERT INTO attendance (class_id, user_id, status, date) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), created_at = CURRENT_TIMESTAMP'
    );

    foreach ($targets as $sid) {
        $present = isset($statuses[(string) $sid]) || isset($statuses[$sid]);
        $st = $present ? 1 : 0;
        $upsert->bind_param('iiis', $classroomId, $sid, $st, $attDate);
        $upsert->execute();
    }
    $upsert->close();

    $mysqli->commit();
    $_SESSION['flash'] = 'Attendance saved for ' . $attDate . '.';
} catch (Throwable $e) {
    $mysqli->rollback();
    $_SESSION['flash'] = 'Could not save attendance.';
}

header('Location: classroom.php?id=' . $classroomId . '&tab=attendance&date=' . urlencode($attDate));
exit;
