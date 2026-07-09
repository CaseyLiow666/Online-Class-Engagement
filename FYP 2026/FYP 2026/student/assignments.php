<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';
require_once dirname(__DIR__) . '/includes/assignment_lib.php';
require_once dirname(__DIR__) . '/includes/student_submission_actions.php';

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$assignments = [];
$stmt = $mysqli->prepare(
    'SELECT a.id, a.title, a.description, a.due_date, a.created_at,
            u.username AS teacher, cl.name AS class_name,
            (CASE WHEN ac.student_id IS NULL THEN 0 ELSE 1 END) AS done,
            ac.submitted_file_path AS file_path
     FROM assignments a
     INNER JOIN classrooms cl ON cl.id = a.classroom_id AND cl.status = 1
     INNER JOIN classroom_members cm ON cm.classroom_id = cl.id AND cm.student_id = ?
     INNER JOIN users u ON u.id = a.teacher_id
     LEFT JOIN assignment_completions ac ON ac.assignment_id = a.id AND ac.student_id = ?
     ORDER BY a.created_at DESC'
);
if ($stmt) {
    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $assignments[] = $row;
    }
    $stmt->close();
}
$studentPageTitle = 'All assignments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="student-dashboard">
    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>
    <main class="layout">
        <?php if ($flash !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Work across your classes</h2>
            <p class="muted">Only open classes you joined appear here. Open a class from Home for chat and the class tab.</p>
            <p class="muted">Submit a file (max 5 MB). Allowed: PDF, Word, PNG, JPG, ZIP, TXT.</p>
            <?php if ($assignments === []): ?>
                <p class="muted">No assignments — join a class or check back later.</p>
            <?php else: ?>
                <?php foreach ($assignments as $a): ?>
                    <?php
                    $pastDue = assignment_is_past_due(isset($a['due_date']) ? (string) $a['due_date'] : null);
                    $canSubmit = !(int) $a['done'] && !$pastDue;
                    ?>
                    <div class="question-block">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
                            <div>
                                <strong><?= htmlspecialchars((string) $a['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="badge badge-teacher" style="margin-left:0.5rem"><?= htmlspecialchars((string) $a['class_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="badge <?= (int) $a['done'] ? 'badge-student' : ($pastDue ? 'badge-admin' : 'badge-admin') ?>" style="margin-left:0.35rem">
                                    <?php if ((int) $a['done']): ?>
                                        Submitted
                                    <?php elseif ($pastDue): ?>
                                        Past due
                                    <?php else: ?>
                                        Open
                                    <?php endif; ?>
                                </span>
                                <p class="muted" style="margin:0.35rem 0 0">Teacher: <?= htmlspecialchars((string) $a['teacher'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p style="margin:0.5rem 0 0"><?= nl2br(htmlspecialchars((string) ($a['description'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                                <p class="muted" style="margin:0.5rem 0 0;font-size:0.85rem">
                                    Due: <?= htmlspecialchars(assignment_format_due_date(isset($a['due_date']) ? (string) $a['due_date'] : null), ENT_QUOTES, 'UTF-8') ?>
                                    · Posted <?= htmlspecialchars((string) $a['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <?php if ((int) $a['done'] && !empty($a['file_path'])): ?>
                                    <?php student_submission_actions(
                                        (int) $a['id'],
                                        0,
                                        true,
                                        (string) $a['file_path'],
                                        $pastDue
                                    ); ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($canSubmit): ?>
                                <form method="post" action="complete_assignment.php" enctype="multipart/form-data" class="stack" style="min-width:220px">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $a['id'] ?>">
                                    <label class="field">
                                        <span>Upload file</span>
                                        <input type="file" name="submission" required accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.zip,.txt">
                                    </label>
                                    <button type="submit" class="btn btn-primary">Submit</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
