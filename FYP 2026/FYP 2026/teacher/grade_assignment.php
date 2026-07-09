<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';
require_once dirname(__DIR__) . '/includes/assignment_lib.php';
require_once dirname(__DIR__) . '/includes/submission_paths.php';

$assignmentId = (int) ($_GET['assignment_id'] ?? 0);
$returnClassroomId = (int) ($_GET['classroom_id'] ?? 0);

if ($assignmentId < 1) {
    header('Location: dashboard.php');
    exit;
}

$as = $mysqli->prepare(
    'SELECT a.id, a.title, a.classroom_id, a.due_date, a.created_at, a.total_marks
     FROM assignments a
     WHERE a.id = ? AND a.teacher_id = ?
     LIMIT 1'
);
$as->bind_param('ii', $assignmentId, $userId);
$as->execute();
$assignment = $as->get_result()->fetch_assoc();
$as->close();

if (!$assignment) {
    http_response_code(403);
    exit('Forbidden');
}

$classroomId = (int) $assignment['classroom_id'];
if ($returnClassroomId < 1) {
    $returnClassroomId = $classroomId;
}

$rows = [];
$st = $mysqli->prepare(
    'SELECT u.id AS student_id, u.username, u.full_name,
            ac.completed_at, ac.submitted_file_path, ac.grade, ac.feedback
     FROM classroom_members cm
     INNER JOIN users u ON u.id = cm.student_id
     LEFT JOIN assignment_completions ac
       ON ac.student_id = u.id AND ac.assignment_id = ?
     WHERE cm.classroom_id = ?
     ORDER BY u.full_name ASC, u.username ASC'
);
$st->bind_param('ii', $assignmentId, $classroomId);
$st->execute();
$res = $st->get_result();
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$st->close();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$assignmentMaxMarks = $assignment['total_marks'] !== null ? (float) $assignment['total_marks'] : null;

$panelPageTitle = 'Manual Grading Center — ' . (string) $assignment['title'];
$panelHeading = 'Manual Grading Center';
$panelRole = 'teacher';
$panelActiveNav = '';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout">
        <?php if ($flash !== ''): ?>
            <?php $flashIsError = stripos($flash, 'error') !== false; ?>
            <div class="alert <?= $flashIsError ? 'alert-error' : 'alert-success' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="reports-toolbar">
                <div>
                    <h2><?= htmlspecialchars((string) $assignment['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="muted" style="margin:0.35rem 0 0">
                        Due: <?= htmlspecialchars(assignment_format_due_date(isset($assignment['due_date']) ? (string) $assignment['due_date'] : null), ENT_QUOTES, 'UTF-8') ?>
                        · Posted <?= htmlspecialchars((string) $assignment['created_at'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
                <a class="btn btn-ghost btn-sm" href="classroom.php?id=<?= $returnClassroomId ?>&amp;tab=reports&amp;rtab=academic">Back to class reports</a>
            </div>

            <form method="post" action="grade_save.php" class="stack" id="grade-assignment-form" style="margin-top:1rem">
                <input type="hidden" name="assignment_id" value="<?= $assignmentId ?>">
                <input type="hidden" name="return_classroom_id" value="<?= $returnClassroomId ?>">
                <label class="field" style="max-width:200px">
                    <span>Total marks for this assignment (e.g. 10)</span>
                    <input type="number" name="total_marks" id="assignment-total-marks" min="0" max="999.99" step="0.01"
                           value="<?= $assignment['total_marks'] !== null ? htmlspecialchars((string) $assignment['total_marks'], ENT_QUOTES, 'UTF-8') : '' ?>"
                           placeholder="10">
                </label>
                <p class="muted">Academic scores below are separate from the +1 participation point students earn on submission.</p>

                <table class="data-table grade-center-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Submitted</th>
                            <th>File</th>
                                <th>Score (academic)</th>
                            <th>Feedback</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $sid = (int) $r['student_id'];
                            $nm = trim((string) ($r['full_name'] ?? ''));
                            if ($nm === '') {
                                $nm = (string) $r['username'];
                            }
                            $submitted = !empty($r['completed_at']);
                            $filePath = (string) ($r['submitted_file_path'] ?? '');
                            $gradeVal = $r['grade'] !== null ? (string) $r['grade'] : '';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($submitted): ?>
                                        <?= htmlspecialchars((string) $r['completed_at'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php else: ?>
                                        <span class="muted">Not submitted</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submitted && $filePath !== ''): ?>
                                        <a href="view_submission.php?assignment_id=<?= $assignmentId ?>&amp;student_id=<?= $sid ?>">View</a>
                                        ·
                                        <a href="download_submission.php?assignment_id=<?= $assignmentId ?>&amp;student_id=<?= $sid ?>"><?= htmlspecialchars(basename($filePath), ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submitted): ?>
                                        <input type="number" name="grade[<?= $sid ?>]" class="grade-input"
                                               min="0"
                                               <?php if ($assignmentMaxMarks !== null): ?>
                                                   max="<?= htmlspecialchars((string) $assignmentMaxMarks, ENT_QUOTES, 'UTF-8') ?>"
                                               <?php endif; ?>
                                               step="0.01"
                                               value="<?= htmlspecialchars($gradeVal, ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="0.00">
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submitted): ?>
                                        <textarea name="feedback[<?= $sid ?>]" rows="2" class="grade-feedback-input"
                                                  placeholder="Comments for student"><?= htmlspecialchars((string) ($r['feedback'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save grades</button>
                </div>
            </form>
        </div>
    </main>
    <script>
    (function () {
        const form = document.getElementById('grade-assignment-form');
        const totalInput = document.getElementById('assignment-total-marks');
        if (!form || !totalInput) {
            return;
        }

        function getMaxMarks() {
            const raw = totalInput.value.trim();
            if (raw === '') {
                return null;
            }
            const value = parseFloat(raw);
            return Number.isFinite(value) && value >= 0 ? value : null;
        }

        function formatMarks(value) {
            return value.toFixed(2);
        }

        function syncScoreMaxAttributes() {
            const max = getMaxMarks();
            form.querySelectorAll('.grade-input').forEach(function (input) {
                if (max !== null) {
                    input.setAttribute('max', String(max));
                } else {
                    input.removeAttribute('max');
                }
            });
        }

        totalInput.addEventListener('input', syncScoreMaxAttributes);
        syncScoreMaxAttributes();

        form.addEventListener('submit', function (event) {
            const max = getMaxMarks();
            if (max === null) {
                return;
            }

            for (const input of form.querySelectorAll('.grade-input')) {
                const raw = input.value.trim();
                if (raw === '') {
                    continue;
                }
                const score = parseFloat(raw);
                if (Number.isFinite(score) && score > max) {
                    event.preventDefault();
                    alert('Score cannot exceed the maximum allowed marks (' + formatMarks(max) + ')!');
                    input.focus();
                    return;
                }
            }
        });
    })();
    </script>
</body>
</html>
