<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$returnClassroomId = (int) ($_POST['return_classroom_id'] ?? 0);

if ($assignmentId < 1) {
    $_SESSION['flash'] = 'Invalid assignment.';
    header('Location: dashboard.php');
    exit;
}

$own = $mysqli->prepare('SELECT classroom_id FROM assignments WHERE id = ? AND teacher_id = ? LIMIT 1');
$own->bind_param('ii', $assignmentId, $userId);
$own->execute();
$ownRow = $own->get_result()->fetch_assoc();
$own->close();

if (!$ownRow) {
    $_SESSION['flash'] = 'Forbidden.';
    header('Location: dashboard.php');
    exit;
}

$classroomId = (int) $ownRow['classroom_id'];
$grades = $_POST['grade'] ?? [];
$feedbacks = $_POST['feedback'] ?? [];
$totalMarksRaw = trim((string) ($_POST['total_marks'] ?? ''));

if ($totalMarksRaw !== '') {
    if (is_numeric($totalMarksRaw)) {
        $totalMarks = round((float) $totalMarksRaw, 2);
        if ($totalMarks >= 0 && $totalMarks <= 999.99) {
            $tm = $mysqli->prepare('UPDATE assignments SET total_marks = ? WHERE id = ? AND teacher_id = ?');
            $tm->bind_param('dii', $totalMarks, $assignmentId, $userId);
            $tm->execute();
            $tm->close();
        }
    }
} else {
    $tm = $mysqli->prepare('UPDATE assignments SET total_marks = NULL WHERE id = ? AND teacher_id = ?');
    $tm->bind_param('ii', $assignmentId, $userId);
    $tm->execute();
    $tm->close();
}

$maxStmt = $mysqli->prepare('SELECT total_marks FROM assignments WHERE id = ? AND teacher_id = ? LIMIT 1');
$maxStmt->bind_param('ii', $assignmentId, $userId);
$maxStmt->execute();
$maxRow = $maxStmt->get_result()->fetch_assoc();
$maxStmt->close();
$assignmentMaxMarks = ($maxRow && $maxRow['total_marks'] !== null) ? (float) $maxRow['total_marks'] : null;

$redirectGradeCenter = static function () use ($mysqli, $userId, $assignmentId, $returnClassroomId): void {
    if ($returnClassroomId > 0 && classroom_teacher_owns($mysqli, $userId, $returnClassroomId)) {
        header('Location: grade_assignment.php?assignment_id=' . $assignmentId . '&classroom_id=' . $returnClassroomId);
    } else {
        header('Location: grade_assignment.php?assignment_id=' . $assignmentId);
    }
    exit;
};

if (!is_array($grades)) {
    $grades = [];
}
if (!is_array($feedbacks)) {
    $feedbacks = [];
}

$upd = $mysqli->prepare(
    'UPDATE assignment_completions SET grade = ?, feedback = ?
     WHERE assignment_id = ? AND student_id = ?'
);

$saved = 0;
foreach ($grades as $studentIdRaw => $gradeRaw) {
    $studentId = (int) $studentIdRaw;
    if ($studentId < 1) {
        continue;
    }

    $gradeStr = trim((string) $gradeRaw);
    $gradeVal = null;
    if ($gradeStr !== '') {
        if (!is_numeric($gradeStr)) {
            continue;
        }
        $gradeVal = round((float) $gradeStr, 2);
        if ($gradeVal < 0 || $gradeVal > 999.99) {
            continue;
        }
        if ($assignmentMaxMarks !== null && $gradeVal > $assignmentMaxMarks) {
            $_SESSION['flash'] = 'Error: Given score exceeds assignment maximum limit.';
            $redirectGradeCenter();
        }
    }

    $feedback = trim((string) ($feedbacks[$studentIdRaw] ?? $feedbacks[$studentId] ?? ''));

    $chk = $mysqli->prepare(
        'SELECT 1 FROM assignment_completions ac
         INNER JOIN classroom_members cm ON cm.student_id = ac.student_id AND cm.classroom_id = ?
         WHERE ac.assignment_id = ? AND ac.student_id = ? LIMIT 1'
    );
    $chk->bind_param('iii', $classroomId, $assignmentId, $studentId);
    $chk->execute();
    $exists = (bool) $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$exists) {
        continue;
    }

    if ($gradeVal === null) {
        $updNull = $mysqli->prepare(
            'UPDATE assignment_completions SET grade = NULL, feedback = ?
             WHERE assignment_id = ? AND student_id = ?'
        );
        $updNull->bind_param('sii', $feedback, $assignmentId, $studentId);
        $updNull->execute();
        $updNull->close();
    } else {
        $upd->bind_param('dsii', $gradeVal, $feedback, $assignmentId, $studentId);
        $upd->execute();
    }
    $saved++;
}
$upd->close();

$_SESSION['flash'] = ($saved > 0 || $totalMarksRaw !== '') ? 'Grades saved.' : 'No grades updated.';

$redirectGradeCenter();
