<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/award_points.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';
require_once dirname(__DIR__) . '/includes/assignment_lib.php';
require_once dirname(__DIR__) . '/includes/submission_paths.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: assignments.php');
    exit;
}

$action = strtolower(trim((string) ($_POST['action'] ?? 'create')));
if (!in_array($action, ['create', 'update', 'delete'], true)) {
    $action = 'create';
}

$aid = (int) ($_POST['assignment_id'] ?? 0);
$returnClassroomId = (int) ($_POST['return_classroom_id'] ?? 0);

if ($aid < 1) {
    $_SESSION['flash'] = 'Invalid assignment.';
    header('Location: assignments.php');
    exit;
}

$redirect = assignment_student_redirect($returnClassroomId);
if ($returnClassroomId > 0 && !classroom_student_member($mysqli, $userId, $returnClassroomId)) {
    $redirect = 'assignments.php';
}

$info = $mysqli->prepare('SELECT title, classroom_id, due_date FROM assignments WHERE id = ? LIMIT 1');
$info->bind_param('i', $aid);
$info->execute();
$arow = $info->get_result()->fetch_assoc();
$info->close();

if (!$arow) {
    $_SESSION['flash'] = 'Assignment not found.';
    header('Location: assignments.php');
    exit;
}

$classroomId = (int) $arow['classroom_id'];
if ($classroomId < 1 || !classroom_student_member($mysqli, $userId, $classroomId)) {
    $_SESSION['flash'] = 'You are not enrolled in this class.';
    header('Location: assignments.php');
    exit;
}

if (!classroom_is_open($mysqli, $classroomId)) {
    $_SESSION['flash'] = 'This class is closed — submissions are not accepted.';
    header('Location: ' . $redirect);
    exit;
}

$dueDate = isset($arow['due_date']) ? (string) $arow['due_date'] : null;
if (assignment_is_past_due($dueDate)) {
    $_SESSION['flash'] = 'The due date for this assignment has passed.';
    header('Location: ' . $redirect);
    exit;
}

$existing = $mysqli->prepare(
    'SELECT student_id FROM assignment_completions WHERE student_id = ? AND assignment_id = ? LIMIT 1'
);
$existing->bind_param('ii', $userId, $aid);
$existing->execute();
$hasSubmission = (bool) $existing->get_result()->fetch_assoc();
$existing->close();

if ($action === 'delete') {
    if (!$hasSubmission) {
        $_SESSION['flash'] = 'No submission to delete.';
        header('Location: ' . $redirect);
        exit;
    }

    submission_unlink_student_file($mysqli, $userId, $aid);

    $mysqli->begin_transaction();
    try {
        revoke_points($mysqli, $userId, 'assignment', (string) $aid);

        $del = $mysqli->prepare('DELETE FROM assignment_completions WHERE student_id = ? AND assignment_id = ?');
        $del->bind_param('ii', $userId, $aid);
        $del->execute();
        $del->close();

        $mysqli->commit();
        $_SESSION['flash'] = 'Submission deleted.';
    } catch (Throwable $e) {
        $mysqli->rollback();
        $_SESSION['flash'] = 'Could not delete submission — try again.';
    }
    header('Location: ' . $redirect);
    exit;
}

if ($action === 'update') {
    if (!$hasSubmission) {
        $_SESSION['flash'] = 'No submission to update.';
        header('Location: ' . $redirect);
        exit;
    }

    $upload = $_FILES['submission'] ?? null;
    if (!$upload) {
        $_SESSION['flash'] = 'Please choose a file to upload.';
        header('Location: ' . $redirect);
        exit;
    }

    $stored = assignment_store_submission_file($upload, $userId, $aid);
    if (!$stored['ok']) {
        $_SESSION['flash'] = $stored['error'];
        header('Location: ' . $redirect);
        exit;
    }

    $relPath = $stored['rel_path'];
    $destFs = $stored['dest_fs'];

    $oldFile = submission_resolve_student_file($mysqli, $userId, $aid);

    $upd = $mysqli->prepare(
        'UPDATE assignment_completions
         SET submitted_file_path = ?, completed_at = NOW()
         WHERE student_id = ? AND assignment_id = ?'
    );
    $upd->bind_param('sii', $relPath, $userId, $aid);
    if (!$upd->execute()) {
        @unlink($destFs);
        $_SESSION['flash'] = 'Could not update submission — try again.';
        header('Location: ' . $redirect);
        exit;
    }
    $upd->close();

    if ($oldFile !== null) {
        @unlink($oldFile[1]);
    }

    $_SESSION['flash'] = 'Submission updated.';
    header('Location: ' . $redirect);
    exit;
}

if ($hasSubmission) {
    $_SESSION['flash'] = 'Already completed.';
    header('Location: ' . $redirect);
    exit;
}

$upload = $_FILES['submission'] ?? null;
if (!$upload) {
    $_SESSION['flash'] = 'Please choose a file to upload.';
    header('Location: ' . $redirect);
    exit;
}

$stored = assignment_store_submission_file($upload, $userId, $aid);
if (!$stored['ok']) {
    $_SESSION['flash'] = $stored['error'];
    header('Location: ' . $redirect);
    exit;
}

$relPath = $stored['rel_path'];
$destFs = $stored['dest_fs'];

$mysqli->begin_transaction();
try {
    $ins = $mysqli->prepare(
        'INSERT INTO assignment_completions (student_id, assignment_id, submitted_file_path) VALUES (?,?,?)'
    );
    $ins->bind_param('iis', $userId, $aid, $relPath);
    $ins->execute();
    $ins->close();

    // 积分规则：任何作业提交一律固定奖励 1 分（写死，不读取作业分值）
    $title = (string) $arow['title'];
    $detail = $title . ': +1pt';
    award_points($mysqli, $userId, 1, 'assignment', (string) $aid, $detail);

    $mysqli->commit();
    $_SESSION['flash'] = 'Submitted — +1 point.';
} catch (Throwable $e) {
    $mysqli->rollback();
    @unlink($destFs);
    $_SESSION['flash'] = 'Could not complete — try again.';
}

header('Location: ' . $redirect);
exit;
