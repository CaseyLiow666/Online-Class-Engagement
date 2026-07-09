<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/submission_paths.php';

$assignmentId = (int) ($_GET['assignment_id'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? 0);
if ($assignmentId < 1 || $studentId < 1) {
    http_response_code(400);
    exit('Bad request');
}

// 校验作业归属并取出其所属班级 ID（用于返回链接指向该班级的报表标签）
$own = $mysqli->prepare('SELECT classroom_id FROM assignments WHERE id = ? AND teacher_id = ? LIMIT 1');
$own->bind_param('ii', $assignmentId, $userId);
$own->execute();
$ownRow = $own->get_result()->fetch_assoc();
$own->close();
if (!$ownRow) {
    http_response_code(403);
    exit('Forbidden');
}
$backClassroomId = (int) $ownRow['classroom_id']; // 返回目标班级 ID

$resolved = submission_resolve_student_file($mysqli, $studentId, $assignmentId);
if ($resolved === null) {
    http_response_code(404);
    exit('Not found');
}

$comp = $mysqli->prepare(
    'SELECT ac.grade, ac.feedback, ac.completed_at, a.title
     FROM assignment_completions ac
     INNER JOIN assignments a ON a.id = ac.assignment_id
     WHERE ac.assignment_id = ? AND ac.student_id = ? LIMIT 1'
);
$comp->bind_param('ii', $assignmentId, $studentId);
$comp->execute();
$compRow = $comp->get_result()->fetch_assoc();
$comp->close();

[, $full] = $resolved;
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$rawUrl = 'submission_raw.php?assignment_id=' . $assignmentId . '&student_id=' . $studentId;
$downloadUrl = htmlspecialchars($rawUrl . '&download=1', ENT_QUOTES, 'UTF-8');
$rawUrlEsc = htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8');
$previewPdf = $ext === 'pdf';
$previewImg = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
$fname = basename($full);

$stu = $mysqli->prepare('SELECT username, full_name FROM users WHERE id = ? LIMIT 1');
$stu->bind_param('i', $studentId);
$stu->execute();
$srow = $stu->get_result()->fetch_assoc();
$stu->close();
$who = $srow ? (trim((string) ($srow['full_name'] ?? '')) ?: (string) $srow['username']) : 'Student';
$panelPageTitle = 'Submission — ' . $who;
$panelHeading = 'View submission';
$panelRole = 'teacher';
$panelActiveNav = '';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout">
        <div class="card">
            <p class="muted" style="margin:0">Student: <strong><?= htmlspecialchars($who, ENT_QUOTES, 'UTF-8') ?></strong></p>
            <div class="submission-toolbar">
                <strong><?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?></strong>
                <a class="btn btn-ghost btn-sm" href="<?= $downloadUrl ?>">Download</a>
                <!-- 返回到该作业所属班级的 Reports 标签页 -->
                <a class="btn btn-ghost btn-sm" href="grade_assignment.php?assignment_id=<?= $assignmentId ?>&amp;classroom_id=<?= $backClassroomId ?>">Manual Grading Center</a>
                <a class="btn btn-ghost btn-sm" href="classroom.php?id=<?= $backClassroomId ?>&amp;tab=reports&amp;rtab=academic">Back to class reports</a>
            </div>

            <?php if ($compRow): ?>
                <p class="muted" style="margin:0.75rem 0 0">
                    Grade:
                    <strong><?= $compRow['grade'] !== null ? number_format((float) $compRow['grade'], 2) : 'Not graded yet' ?></strong>
                    <?php if (trim((string) ($compRow['feedback'] ?? '')) !== ''): ?>
                        · Feedback: <?= htmlspecialchars((string) $compRow['feedback'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($previewPdf): ?>
                <div class="submission-viewer">
                    <iframe title="PDF preview" src="<?= $rawUrlEsc ?>"></iframe>
                </div>
            <?php elseif ($previewImg): ?>
                <div class="submission-viewer" style="padding:1rem;text-align:center">
                    <img src="<?= $rawUrlEsc ?>" alt="Submission">
                </div>
            <?php else: ?>
                <p class="muted">Preview is not available for this file type.</p>
                <p><a class="btn btn-primary" href="<?= $downloadUrl ?>">Download file</a></p>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
