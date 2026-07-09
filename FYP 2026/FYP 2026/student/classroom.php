<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';
require_once dirname(__DIR__) . '/includes/channel_lib.php';

$classroomId = (int) ($_GET['id'] ?? 0);
if ($classroomId < 1 || !classroom_student_member($mysqli, $userId, $classroomId)) {
    header('Location: index.php');
    exit;
}

$tab = $_GET['tab'] ?? 'chat';
if (!in_array($tab, ['chat', 'announcements', 'assignments', 'my_report', 'my_grades'], true)) {
    $tab = 'chat';
}

classroom_ensure_general_channel($mysqli, $classroomId);

$stmt = $mysqli->prepare(
    'SELECT cl.id, cl.name, cl.description, cl.join_code, cl.status, cl.teacher_id,
            u.username AS teacher_name
     FROM classrooms cl
     INNER JOIN users u ON u.id = cl.teacher_id
     WHERE cl.id = ? LIMIT 1'
);
$stmt->bind_param('i', $classroomId);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$class) {
    header('Location: index.php');
    exit;
}

if ((int) ($class['status'] ?? 0) !== 1) {
    $_SESSION['flash'] = 'This class is closed.';
    header('Location: index.php');
    exit;
}

require_once dirname(__DIR__) . '/includes/assignment_lib.php';
require_once dirname(__DIR__) . '/includes/student_submission_actions.php';

$icon = classroom_card_icon($classroomId);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$assignments = [];
$aq = $mysqli->prepare(
    'SELECT a.id, a.title, a.description, a.due_date, a.created_at,
            (CASE WHEN ac.student_id IS NULL THEN 0 ELSE 1 END) AS done,
            ac.submitted_file_path AS file_path
     FROM assignments a
     LEFT JOIN assignment_completions ac ON ac.assignment_id = a.id AND ac.student_id = ?
     WHERE a.classroom_id = ?
     ORDER BY a.created_at DESC'
);
$aq->bind_param('ii', $userId, $classroomId);
$aq->execute();
$ar = $aq->get_result();
while ($row = $ar->fetch_assoc()) {
    $assignments[] = $row;
}
$aq->close();

// My Report data — loaded only when that tab is active (class + student scoped).
$myPresentDays = 0;
$myMarkedDays = 0;
$classSessionDays = 0;
$myAttendancePct = 0.0;
$myEngagementPoints = 0;
$myAttendanceHistory = [];
$myAbsentDays = 0;
$myQuizScores = [];
$myAssignmentStatus = [];
$myGradeRows = [];

if ($tab === 'my_report' || $tab === 'my_grades') {
    $pStmt = $mysqli->prepare(
        'SELECT COUNT(*) AS c FROM attendance
         WHERE class_id = ? AND user_id = ? AND status = 1'
    );
    $pStmt->bind_param('ii', $classroomId, $userId);
    $pStmt->execute();
    $prow = $pStmt->get_result()->fetch_assoc();
    $pStmt->close();
    $myPresentDays = $prow ? (int) $prow['c'] : 0;

    $sStmt = $mysqli->prepare(
        'SELECT COUNT(DISTINCT date) AS c FROM attendance WHERE class_id = ?'
    );
    $sStmt->bind_param('i', $classroomId);
    $sStmt->execute();
    $srow = $sStmt->get_result()->fetch_assoc();
    $sStmt->close();
    $classSessionDays = $srow ? (int) $srow['c'] : 0;

    if ($classSessionDays > 0) {
        $myAttendancePct = round(100 * $myPresentDays / $classSessionDays, 1);
    }
}

if ($tab === 'my_report') {
    $attHistStmt = $mysqli->prepare(
        'SELECT date, status FROM attendance
         WHERE user_id = ? AND class_id = ?
         ORDER BY date DESC'
    );
    $attHistStmt->bind_param('ii', $userId, $classroomId);
    $attHistStmt->execute();
    $attHistRes = $attHistStmt->get_result();
    while ($attHistRow = $attHistRes->fetch_assoc()) {
        $myAttendanceHistory[] = $attHistRow;
        if ((int) ($attHistRow['status'] ?? 0) === 0) {
            $myAbsentDays++;
        }
    }
    $attHistStmt->close();

    $engStmt = $mysqli->prepare(
        'SELECT COALESCE(SUM(pl.points), 0) AS pts
         FROM point_logs pl
         WHERE pl.user_id = ? AND pl.source_type IN (\'assignment\', \'quiz\')'
    );
    $engStmt->bind_param('i', $userId);
    $engStmt->execute();
    $engRow = $engStmt->get_result()->fetch_assoc();
    $engStmt->close();
    $myEngagementPoints = $engRow ? (int) $engRow['pts'] : 0;
}

if ($tab === 'my_grades') {
    $qStmt = $mysqli->prepare(
        'SELECT q.id, q.title, q.room_code,
                qc.score, qc.max_score,
                qc.updated_at AS last_answered
         FROM quiz_completions qc
         INNER JOIN quizzes q ON qc.quiz_id = q.id
         INNER JOIN classrooms c ON q.classroom_id = c.id
         INNER JOIN classroom_members cm ON cm.classroom_id = c.id AND cm.student_id = qc.student_id
         WHERE qc.student_id = ? AND c.id = ?
         ORDER BY qc.updated_at DESC'
    );
    $qStmt->bind_param('ii', $userId, $classroomId);
    $qStmt->execute();
    $qres = $qStmt->get_result();
    while ($row = $qres->fetch_assoc()) {
        $myQuizScores[] = $row;
    }
    $qStmt->close();

    $gStmt = $mysqli->prepare(
        'SELECT a.id, a.title, a.due_date, a.total_marks,
                ac.completed_at, ac.grade, ac.feedback,
                (CASE WHEN ac.student_id IS NULL THEN 0 ELSE 1 END) AS submitted
         FROM assignments a
         LEFT JOIN assignment_completions ac
           ON ac.assignment_id = a.id AND ac.student_id = ?
         WHERE a.classroom_id = ?
         ORDER BY a.created_at DESC'
    );
    $gStmt->bind_param('ii', $userId, $classroomId);
    $gStmt->execute();
    $gres = $gStmt->get_result();
    while ($row = $gres->fetch_assoc()) {
        $myGradeRows[] = $row;
    }
    $gStmt->close();
}

$chatPollUrl = '../api/classroom_feed_poll.php?classroom_id=' . $classroomId . '&feed=chat';
$announcePollUrl = '../api/classroom_feed_poll.php?classroom_id=' . $classroomId . '&feed=announcements';
$messageActionUrl = '../api/classroom_message_action.php';

$studentPageTitle = (string) $class['name'];
$studentClassroomId = $classroomId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string) $class['name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="student-dashboard">
    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>
    <main class="layout">
        <?php if ($flash !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="classroom-hero-bar">
            <span class="classroom-hero-bar__icon" aria-hidden="true"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span>
            <div>
                <p class="muted" style="margin:0">Teacher: <?= htmlspecialchars((string) $class['teacher_name'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (trim((string) ($class['description'] ?? '')) !== ''): ?>
                    <p style="margin:0.35rem 0 0"><?= nl2br(htmlspecialchars((string) $class['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
            </div>
            <form method="post" action="leave_classroom.php" class="classroom-leave-form"
                  onsubmit="return confirm('Are you sure you want to leave this class?');">
                <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                <button type="submit" class="btn btn-ghost btn-sm">Leave Class</button>
            </form>
        </div>

        <div class="classroom-tabs">
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=chat" class="classroom-tab <?= $tab === 'chat' ? 'is-active' : '' ?>">Chat</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=announcements" class="classroom-tab <?= $tab === 'announcements' ? 'is-active' : '' ?>">Announcements</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=assignments" class="classroom-tab <?= $tab === 'assignments' ? 'is-active' : '' ?>">Assignments</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=my_report" class="classroom-tab <?= $tab === 'my_report' ? 'is-active' : '' ?>">My Report</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=my_grades" class="classroom-tab <?= $tab === 'my_grades' ? 'is-active' : '' ?>">My Grades</a>
        </div>

        <?php if ($tab === 'my_report'): ?>
            <div class="card">
                <h2>Engagement Report</h2>
                <p class="muted">Participation and attendance for this class — separate from academic grades.</p>

                <h3 style="margin:1rem 0 0.5rem">Attendance</h3>
                <div class="dashboard-summary" style="margin-top:0.5rem">
                    <div class="summary-badge summary-badge--points">
                        <span class="summary-badge__label">Present days</span>
                        <span class="summary-badge__value"><?= $myPresentDays ?></span>
                    </div>
                    <div class="summary-badge">
                        <span class="summary-badge__label">Class sessions conducted</span>
                        <span class="summary-badge__value"><?= $classSessionDays ?></span>
                    </div>
                    <div class="summary-badge">
                        <span class="summary-badge__label">Attendance rate</span>
                        <span class="summary-badge__value"><?= $classSessionDays > 0 ? $myAttendancePct . '%' : '—' ?></span>
                    </div>
                    <div class="summary-badge">
                        <span class="summary-badge__label">Absent days</span>
                        <span class="summary-badge__value"><?= $myAbsentDays ?></span>
                    </div>
                    <div class="summary-badge">
                        <span class="summary-badge__label">Participation points</span>
                        <span class="summary-badge__value"><?= (int) $myEngagementPoints ?></span>
                    </div>
                </div>
                <?php if ($classSessionDays === 0): ?>
                    <p class="muted" style="margin-top:0.75rem">No attendance sessions recorded for this class yet.</p>
                <?php endif; ?>

                <h3 style="margin:1.5rem 0 0.5rem">Attendance history</h3>
                <p class="muted" style="margin:0 0 0.75rem">All recorded sessions for you in this class, newest first.</p>
                <?php if ($myAttendanceHistory === []): ?>
                    <p class="muted">No attendance records for you in this class yet.</p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myAttendanceHistory as $attRow): ?>
                                <?php $present = (int) ($attRow['status'] ?? 0) === 1; ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $attRow['date'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge <?= $present ? 'badge-student' : 'badge-admin' ?>">
                                            <?= $present ? 'Present' : 'Absent' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php elseif ($tab === 'my_grades'): ?>
            <div class="card">
                <h2>My Grades</h2>
                <p class="muted">Academic quiz scores and lecturer-assigned assignment grades.</p>

                <h3 style="margin:1rem 0 0.5rem">Quiz scores</h3>
                <?php if ($myQuizScores === []): ?>
                    <p class="muted">You have not completed any quizzes for this class yet.</p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:0.5rem">
                        <thead>
                            <tr>
                                <th>Quiz</th>
                                <th>Grade</th>
                                <th>Last activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myQuizScores as $qz): ?>
                                <?php
                                $quizScore = (float) $qz['score'];
                                $quizMax = (float) $qz['max_score'];
                                ?>
                                <tr>
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
                <?php if ($myGradeRows === []): ?>
                    <p class="muted">No assignments have been posted for this class yet.</p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:0.5rem">
                        <thead>
                            <tr>
                                <th>Assignment</th>
                                <th>Status</th>
                                <th>Your grade</th>
                                <th>Feedback</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myGradeRows as $gr): ?>
                                <?php $submitted = (int) $gr['submitted'] === 1; ?>
                                <tr>
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
        <?php elseif ($tab === 'announcements'): ?>
            <div class="card announcements-panel">
                <h2>Announcements</h2>
                <p class="muted">Official updates from your teacher and system notifications (read-only).</p>
                <div id="announce-feed"
                     class="chat-feed announcements-feed"
                     data-feed-mode="announcements"
                     data-classroom-id="<?= $classroomId ?>"
                     data-poll-url="<?= htmlspecialchars($announcePollUrl, ENT_QUOTES, 'UTF-8') ?>"
                     data-action-url="<?= htmlspecialchars($messageActionUrl, ENT_QUOTES, 'UTF-8') ?>"
                     data-viewer-id="<?= $userId ?>"
                     data-viewer-is-teacher="0"
                     aria-live="polite"></div>
            </div>
            <script src="../assets/js/classroom_feed.js"></script>
        <?php elseif ($tab === 'chat'): ?>
            <div class="card classroom-chat-card">
                <h2>Class chat</h2>
                <p class="muted">Discuss with classmates and your teacher. Announcements are in a separate tab.</p>
                <div id="chat-feed"
                     class="chat-feed"
                     data-feed-mode="chat"
                     data-classroom-id="<?= $classroomId ?>"
                     data-poll-url="<?= htmlspecialchars($chatPollUrl, ENT_QUOTES, 'UTF-8') ?>"
                     data-action-url="<?= htmlspecialchars($messageActionUrl, ENT_QUOTES, 'UTF-8') ?>"
                     data-viewer-id="<?= $userId ?>"
                     data-viewer-is-teacher="0"
                     aria-live="polite"></div>
                <form method="post" action="classroom_post.php" class="stack chat-compose">
                    <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                    <label class="field">
                        <span>Message</span>
                        <textarea name="body" required maxlength="4000" rows="3" placeholder="Say something to your class…"></textarea>
                    </label>
                    <button type="submit" class="btn btn-primary">Send</button>
                </form>
            </div>
            <script src="../assets/js/classroom_feed.js"></script>
        <?php elseif ($tab === 'assignments'): ?>
            <div class="card">
                <h2>Assignments</h2>
                <p class="muted">Work posted for this class only. Submit a file (max 5 MB).</p>
                <?php if ($assignments === []): ?>
                    <p class="muted">No assignments yet.</p>
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
                                    <span class="badge <?= (int) $a['done'] ? 'badge-student' : 'badge-admin' ?>" style="margin-left:0.5rem">
                                        <?php if ((int) $a['done']): ?>
                                            Submitted
                                        <?php elseif ($pastDue): ?>
                                            Past due
                                        <?php else: ?>
                                            Open
                                        <?php endif; ?>
                                    </span>
                                    <p style="margin:0.5rem 0 0"><?= nl2br(htmlspecialchars((string) ($a['description'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                                    <p class="muted" style="margin:0.5rem 0 0;font-size:0.85rem">
                                        Due: <?= htmlspecialchars(assignment_format_due_date(isset($a['due_date']) ? (string) $a['due_date'] : null), ENT_QUOTES, 'UTF-8') ?>
                                        · <?= htmlspecialchars((string) $a['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                    <?php if ((int) $a['done'] && !empty($a['file_path'])): ?>
                                        <?php student_submission_actions(
                                            (int) $a['id'],
                                            $classroomId,
                                            true,
                                            (string) $a['file_path'],
                                            $pastDue
                                        ); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($canSubmit): ?>
                                    <form method="post" action="complete_assignment.php" enctype="multipart/form-data" class="stack" style="min-width:220px">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="return_classroom_id" value="<?= $classroomId ?>">
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
        <?php endif; ?>
    </main>
</body>
</html>
