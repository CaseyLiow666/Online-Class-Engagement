<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

$quizRows = [];
$qStmt = $mysqli->prepare(
    'SELECT c.name AS class_name, q.title,
            qc.score, qc.max_score, qc.updated_at AS last_answered
     FROM quiz_completions qc
     INNER JOIN quizzes q ON qc.quiz_id = q.id
     INNER JOIN classrooms c ON q.classroom_id = c.id
     INNER JOIN classroom_members cm ON cm.classroom_id = c.id AND cm.student_id = qc.student_id
     WHERE qc.student_id = ?
     ORDER BY qc.updated_at DESC'
);
$qStmt->bind_param('i', $userId);
$qStmt->execute();
$qRes = $qStmt->get_result();
while ($row = $qRes->fetch_assoc()) {
    $quizRows[] = $row;
}
$qStmt->close();

$gradeRows = [];
$gStmt = $mysqli->prepare(
    'SELECT cl.name AS class_name, a.title, a.total_marks,
            ac.grade, ac.feedback,
            (CASE WHEN ac.student_id IS NULL THEN 0 ELSE 1 END) AS submitted
     FROM assignments a
     INNER JOIN classrooms cl ON cl.id = a.classroom_id
     INNER JOIN classroom_members cm ON cm.classroom_id = cl.id AND cm.student_id = ?
     LEFT JOIN assignment_completions ac
       ON ac.assignment_id = a.id AND ac.student_id = ?
     ORDER BY a.created_at DESC'
);
$gStmt->bind_param('ii', $userId, $userId);
$gStmt->execute();
$gRes = $gStmt->get_result();
while ($row = $gRes->fetch_assoc()) {
    $gradeRows[] = $row;
}
$gStmt->close();

$studentPageTitle = 'My Grades';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Grades</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="student-dashboard">
    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>
    <main class="layout">
        <div class="card">
            <h2>My Grades</h2>
            <p class="muted">Academic quiz scores and lecturer-assigned assignment grades across all your classes.</p>

            <h3 style="margin:1rem 0 0.5rem">Quiz scores</h3>
            <?php if ($quizRows === []): ?>
                <p class="muted">You have not completed any quizzes yet.</p>
            <?php else: ?>
                <table class="data-table" style="margin-top:0.5rem">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Quiz</th>
                            <th>Grade</th>
                            <th>Last activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quizRows as $qz): ?>
                            <?php
                            $quizScore = (float) $qz['score'];
                            $quizMax = (float) $qz['max_score'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $qz['class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $qz['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($quizMax > 0): ?>
                                        <?= number_format($quizScore, 2) ?> / <?= number_format($quizMax, 2) ?>
                                    <?php else: ?>
                                        <?= number_format($quizScore, 2) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string) ($qz['last_answered'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3 style="margin:1.5rem 0 0.5rem">Assignment grades</h3>
            <?php if ($gradeRows === []): ?>
                <p class="muted">No assignments have been posted for your classes yet.</p>
            <?php else: ?>
                <table class="data-table" style="margin-top:0.5rem">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Assignment</th>
                            <th>Status</th>
                            <th>Your grade</th>
                            <th>Feedback</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gradeRows as $gr): ?>
                            <?php $submitted = (int) $gr['submitted'] === 1; ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $gr['class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $gr['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="badge <?= $submitted ? 'badge-student' : 'badge-admin' ?>">
                                        <?= $submitted ? 'Submitted' : 'Missing' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($gr['grade'] !== null): ?>
                                        <?php
                                        $maxM = $gr['total_marks'] !== null ? (float) $gr['total_marks'] : null;
                                        echo $maxM !== null && $maxM > 0
                                            ? number_format((float) $gr['grade'], 2) . ' / ' . number_format($maxM, 2)
                                            : number_format((float) $gr['grade'], 2);
                                        ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?= $submitted && trim((string) ($gr['feedback'] ?? '')) !== '' ? htmlspecialchars((string) $gr['feedback'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
