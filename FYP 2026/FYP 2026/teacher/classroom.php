<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';
require_once dirname(__DIR__) . '/includes/assignment_lib.php';
require_once dirname(__DIR__) . '/includes/channel_lib.php';

$classroomId = (int) ($_GET['id'] ?? 0);
if ($classroomId < 1 || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
    header('Location: dashboard.php');
    exit;
}

// 当前标签页：新增 'students'（学生名单）与 'reports'（班级报表）标签
$tab = $_GET['tab'] ?? 'chat';
if (!in_array($tab, ['chat', 'announcements', 'assignments', 'quizzes', 'attendance', 'students', 'reports'], true)) {
    $tab = 'chat';
}

// 【学生搜索关键字】Students 标签页使用：按姓名或用户名进行 LIKE 模糊搜索
$studentSearch = trim((string) ($_GET['q'] ?? ''));

// 【Reports 标签页 - 考勤报表筛选参数】
//   使用独立的 GET 键（rq/rdate/rstatus），避免与 Students 标签的 q、
//   Attendance 标签的 date 互相干扰。空值表示「不限制」。
$repName = trim((string) ($_GET['rq'] ?? ''));       // 学生姓名/用户名关键字
$repDate = trim((string) ($_GET['rdate'] ?? ''));    // 日期 YYYY-MM-DD
$repStatus = trim((string) ($_GET['rstatus'] ?? '')); // present / absent / 空
if ($repDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $repDate)) {
    $repDate = ''; // 日期格式非法则忽略
}

// Reports sub-tab: engagement (participation points) vs academic (grades).
$reportTab = $_GET['rtab'] ?? 'engagement';
if (!in_array($reportTab, ['engagement', 'academic'], true)) {
    $reportTab = 'engagement';
}

// 【作业提交报表搜索关键字】可同时匹配「学生姓名/用户名」或「作业标题」
$repAssign = trim((string) ($_GET['aq'] ?? ''));

$attDate = trim((string) ($_GET['date'] ?? ''));
// Default display to today when no date chosen; any valid Y-m-d (past or future) is accepted.
if ($attDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attDate)) {
    $attDate = date('Y-m-d');
} elseif ($attDate === '') {
    $attDate = date('Y-m-d');
}

// 【Attendance 标签页 - 学生搜索关键字】按姓名或用户名 LIKE 过滤点名名单
$attSearch = trim((string) ($_GET['asq'] ?? ''));

$classStudents = [];
$ss = $mysqli->prepare(
    'SELECT u.id, u.username, u.full_name
     FROM classroom_members cm
     INNER JOIN users u ON u.id = cm.student_id
     WHERE cm.classroom_id = ?
     ORDER BY u.full_name ASC, u.username ASC'
);
$ss->bind_param('i', $classroomId);
$ss->execute();
$sr = $ss->get_result();
while ($row = $sr->fetch_assoc()) {
    $classStudents[] = $row;
}
$ss->close();

// 【学生总数】用于页头与卡片显示 "Students Enrolled: X"
$studentCount = count($classStudents);

classroom_ensure_general_channel($mysqli, $classroomId);
// ---------------------------------------------------------------------------
// 【Attendance 标签页 - 点名名单（可搜索）】
//   $attStudents 是「用于点名/显示」的名单：若教师在考勤搜索框输入关键字，
//   则用 SQL LIKE 同时匹配 full_name 或 username；否则等同于全班名单。
//   注意：仅过滤「显示」的名单，保存时只更新这份名单内的学生（见 attendance_save.php
//   的 roster[] 机制），从而不会误伤未显示学生的当日考勤。
// ---------------------------------------------------------------------------
$attStudents = [];
if ($attSearch !== '') {
    $attLike = '%' . $attSearch . '%';
    $asq = $mysqli->prepare(
        'SELECT u.id, u.username, u.full_name
         FROM classroom_members cm
         INNER JOIN users u ON u.id = cm.student_id
         WHERE cm.classroom_id = ?
           AND (u.full_name LIKE ? OR u.username LIKE ?)
         ORDER BY u.full_name ASC, u.username ASC'
    );
    $asq->bind_param('iss', $classroomId, $attLike, $attLike);
    $asq->execute();
    $asr = $asq->get_result();
    while ($row = $asr->fetch_assoc()) {
        $attStudents[] = $row;
    }
    $asq->close();
} else {
    // 无搜索关键字时复用已加载的全班名单，避免重复查询
    $attStudents = $classStudents;
}

// ---------------------------------------------------------------------------
// 【Students 标签页 - 带搜索的学生名单】
//   若教师在搜索框输入了关键字，则用 SQL LIKE 同时匹配 full_name 或 username；
//   否则返回该班级的全部学生。使用预处理语句绑定参数，防止 SQL 注入。
// ---------------------------------------------------------------------------
$studentList = [];
if ($studentSearch !== '') {
    // 关键字两侧加通配符 % ，实现「包含匹配」
    $like = '%' . $studentSearch . '%';
    $sl = $mysqli->prepare(
        'SELECT u.id, u.username, u.full_name, cm.joined_at
         FROM classroom_members cm
         INNER JOIN users u ON u.id = cm.student_id
         WHERE cm.classroom_id = ?
           AND (u.full_name LIKE ? OR u.username LIKE ?)
         ORDER BY u.full_name ASC, u.username ASC'
    );
    // 'i' = 班级ID；两个 's' 分别绑定姓名与用户名的 LIKE 关键字
    $sl->bind_param('iss', $classroomId, $like, $like);
} else {
    $sl = $mysqli->prepare(
        'SELECT u.id, u.username, u.full_name, cm.joined_at
         FROM classroom_members cm
         INNER JOIN users u ON u.id = cm.student_id
         WHERE cm.classroom_id = ?
         ORDER BY u.full_name ASC, u.username ASC'
    );
    $sl->bind_param('i', $classroomId);
}
$sl->execute();
$slr = $sl->get_result();
while ($row = $slr->fetch_assoc()) {
    $studentList[] = $row;
}
$sl->close();

// ---------------------------------------------------------------------------
// 【Reports 标签页数据】仅在该标签激活时查询，避免其它标签的多余开销。
// ---------------------------------------------------------------------------
$reportAttendance = [];
$reportEngagement = [];
$reportQuiz = [];
$reportAssignGrades = [];
$reportCourseworkTotals = [];
if ($tab === 'reports') {
    if ($reportTab === 'engagement') {
        $re = $mysqli->prepare(
            'SELECT u.id AS student_id, u.full_name, u.username,
                    COALESCE(SUM(pl.points), 0) AS engagement_points,
                    COUNT(DISTINCT CASE WHEN pl.source_type = \'assignment\' THEN pl.source_ref END) AS assignments_completed,
                    COUNT(DISTINCT CASE WHEN pl.source_type = \'quiz\' THEN pl.source_ref END) AS quizzes_participated
             FROM classroom_members cm
             INNER JOIN users u ON u.id = cm.student_id
             LEFT JOIN point_logs pl ON pl.user_id = u.id AND pl.source_type IN (\'assignment\', \'quiz\')
             WHERE cm.classroom_id = ?
             GROUP BY u.id, u.full_name, u.username
             ORDER BY engagement_points DESC, u.full_name ASC'
        );
        $re->bind_param('i', $classroomId);
        $re->execute();
        $rer = $re->get_result();
        while ($row = $rer->fetch_assoc()) {
            $reportEngagement[] = $row;
        }
        $re->close();

        $rsql =
            'SELECT a.id, a.date, a.status, u.full_name, u.username
             FROM attendance a
             INNER JOIN users u ON u.id = a.user_id
             INNER JOIN classroom_members cm ON cm.student_id = u.id AND cm.classroom_id = ?
             WHERE a.class_id = ?';
        $rtypes = 'ii';
        $rparams = [$classroomId, $classroomId];

        if ($repName !== '') {
            $rsql .= ' AND (u.full_name LIKE ? OR u.username LIKE ?)';
            $rlike = '%' . $repName . '%';
            $rtypes .= 'ss';
            $rparams[] = $rlike;
            $rparams[] = $rlike;
        }
        if ($repDate !== '') {
            $rsql .= ' AND a.date = ?';
            $rtypes .= 's';
            $rparams[] = $repDate;
        }
        if ($repStatus === 'present' || $repStatus === 'absent') {
            $rsql .= ' AND a.status = ?';
            $rtypes .= 'i';
            $rparams[] = $repStatus === 'present' ? 1 : 0;
        }
        $rsql .= ' ORDER BY a.date DESC, u.full_name ASC';

        $ra = $mysqli->prepare($rsql);
        $ra->bind_param($rtypes, ...$rparams);
        $ra->execute();
        $rar = $ra->get_result();
        while ($row = $rar->fetch_assoc()) {
            $reportAttendance[] = $row;
        }
        $ra->close();
    } else {
        $rq = $mysqli->prepare(
            'SELECT u.full_name, u.username, q.title AS quiz_title, q.room_code,
                    qc.score,
                    qc.max_score,
                    qc.updated_at AS last_answered
             FROM classroom_members cm
             INNER JOIN users u ON u.id = cm.student_id
             INNER JOIN quiz_completions qc ON qc.student_id = u.id
             INNER JOIN quizzes q ON q.id = qc.quiz_id AND q.classroom_id = ?
             WHERE cm.classroom_id = ?
             ORDER BY u.full_name ASC, q.title ASC'
        );
        $rq->bind_param('ii', $classroomId, $classroomId);
        $rq->execute();
        $rqr = $rq->get_result();
        while ($row = $rqr->fetch_assoc()) {
            $reportQuiz[] = $row;
        }
        $rq->close();

        $asql =
            'SELECT u.id AS student_id, u.full_name, u.username,
                    a.id AS assignment_id, a.title AS assignment_title, a.due_date, a.total_marks,
                    ac.completed_at, ac.submitted_file_path, ac.grade, ac.feedback
             FROM assignments a
             INNER JOIN classroom_members cm ON cm.classroom_id = ?
             INNER JOIN users u ON u.id = cm.student_id
             LEFT JOIN assignment_completions ac ON ac.assignment_id = a.id AND ac.student_id = u.id
             WHERE a.classroom_id = ? AND a.teacher_id = ?';
        $atypes = 'iii';
        $aparams = [$classroomId, $classroomId, $userId];

        if ($repAssign !== '') {
            $asql .= ' AND (u.full_name LIKE ? OR u.username LIKE ? OR a.title LIKE ?)';
            $alike = '%' . $repAssign . '%';
            $atypes .= 'sss';
            $aparams[] = $alike;
            $aparams[] = $alike;
            $aparams[] = $alike;
        }
        $asql .= ' ORDER BY a.created_at DESC, u.full_name ASC, u.username ASC';

        $ras = $mysqli->prepare($asql);
        $ras->bind_param($atypes, ...$aparams);
        $ras->execute();
        $rasr = $ras->get_result();
        while ($row = $rasr->fetch_assoc()) {
            $reportAssignGrades[] = $row;
        }
        $ras->close();

        $ct = $mysqli->prepare(
            'SELECT u.id AS student_id, u.full_name, u.username,
                    COALESCE(quiz_tot.quiz_score, 0) AS quiz_earned_total,
                    COALESCE(class_quiz.class_quiz_max, 0) AS quiz_max_total,
                    COALESCE(assign_tot.assign_earned_total, 0) AS assignment_earned_total,
                    COALESCE(assign_tot.assign_max_total, 0) AS assignment_max_total,
                    COALESCE(assign_tot.assign_count, 0) AS assignments_graded
             FROM classroom_members cm
             INNER JOIN users u ON u.id = cm.student_id
             LEFT JOIN (
                 SELECT qc.student_id,
                        SUM(qc.score) AS quiz_score
                 FROM quiz_completions qc
                 INNER JOIN quizzes q ON q.id = qc.quiz_id AND q.classroom_id = ?
                 GROUP BY qc.student_id
             ) quiz_tot ON quiz_tot.student_id = u.id
             LEFT JOIN (
                 SELECT q.classroom_id,
                        COALESCE(SUM(qpts.quiz_max), 0) AS class_quiz_max
                 FROM quizzes q
                 INNER JOIN (
                     SELECT quiz_id, SUM(points) AS quiz_max
                     FROM quiz_questions
                     GROUP BY quiz_id
                 ) qpts ON qpts.quiz_id = q.id
                 WHERE q.classroom_id = ?
                 GROUP BY q.classroom_id
             ) class_quiz ON class_quiz.classroom_id = cm.classroom_id
             LEFT JOIN (
                 SELECT ac.student_id,
                        SUM(CASE WHEN ac.grade IS NOT NULL THEN ac.grade ELSE 0 END) AS assign_earned_total,
                        SUM(CASE WHEN ac.grade IS NOT NULL AND a.total_marks IS NOT NULL THEN a.total_marks ELSE 0 END) AS assign_max_total,
                        COUNT(CASE WHEN ac.grade IS NOT NULL THEN 1 END) AS assign_count
                 FROM assignment_completions ac
                 INNER JOIN assignments a ON a.id = ac.assignment_id AND a.classroom_id = ?
                 GROUP BY ac.student_id
             ) assign_tot ON assign_tot.student_id = u.id
             WHERE cm.classroom_id = ?
             ORDER BY u.full_name ASC'
        );
        $ct->bind_param('iiii', $classroomId, $classroomId, $classroomId, $classroomId);
        $ct->execute();
        $ctr = $ct->get_result();
        while ($row = $ctr->fetch_assoc()) {
            $reportCourseworkTotals[] = $row;
        }
        $ct->close();
    }
}

$todayAttendance = [];
if ($classStudents !== []) {
    $at = $mysqli->prepare(
        'SELECT user_id, status FROM attendance
         WHERE class_id = ? AND date = ?'
    );
    $at->bind_param('is', $classroomId, $attDate);
    $at->execute();
    $atr = $at->get_result();
    while ($row = $atr->fetch_assoc()) {
        $todayAttendance[(int) $row['user_id']] = (int) $row['status'];
    }
    $at->close();
}

$stmt = $mysqli->prepare(
    'SELECT cl.id, cl.name, cl.description, cl.join_code, cl.status, cl.created_at
     FROM classrooms cl
     WHERE cl.id = ? AND cl.teacher_id = ?
     LIMIT 1'
);
$stmt->bind_param('ii', $classroomId, $userId);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$class) {
    header('Location: dashboard.php');
    exit;
}

$icon = classroom_card_icon($classroomId);

$assignList = [];
$as = $mysqli->prepare(
    'SELECT a.id, a.title, a.description, a.due_date, a.created_at
     FROM assignments a
     WHERE a.classroom_id = ? AND a.teacher_id = ?
     ORDER BY a.created_at DESC'
);
$as->bind_param('ii', $classroomId, $userId);
$as->execute();
$ar = $as->get_result();
while ($row = $ar->fetch_assoc()) {
    $assignList[] = $row;
}
$as->close();

$quizList = [];
$qz = $mysqli->prepare(
    'SELECT q.id, q.title, q.room_code, q.status, q.created_at
     FROM quizzes q
     WHERE q.classroom_id = ? AND q.teacher_id = ?
     ORDER BY q.created_at DESC'
);
$qz->bind_param('ii', $classroomId, $userId);
$qz->execute();
$qzr = $qz->get_result();
while ($row = $qzr->fetch_assoc()) {
    $quizList[] = $row;
}
$qz->close();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$chatPollUrl = '../api/classroom_feed_poll.php?classroom_id=' . $classroomId . '&feed=chat';
$announcePollUrl = '../api/classroom_feed_poll.php?classroom_id=' . $classroomId . '&feed=announcements';
$messageActionUrl = '../api/classroom_message_action.php';

$panelPageTitle = (string) $class['name'];
$panelHeading = (string) $class['name'];
$panelRole = 'teacher';
$panelActiveNav = '';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout">
        <?php if ($flash !== ''): ?>
            <?php $flashIsError = str_starts_with($flash, 'Error:'); ?>
            <div class="alert <?= $flashIsError ? 'alert-error' : 'alert-success' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php $heroColor = classroom_card_color($classroomId); ?>
        <?php $isOpen = (int) ($class['status'] ?? 1) === 1; // 班级是否处于开放状态 ?>
        <div class="classroom-hero-bar" style="border-top: 4px solid <?= htmlspecialchars($heroColor, ENT_QUOTES, 'UTF-8') ?>">
            <span class="classroom-hero-bar__icon" aria-hidden="true"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span>
            <div>
                <p class="muted" style="margin:0">Join code: <strong><?= htmlspecialchars((string) $class['join_code'], ENT_QUOTES, 'UTF-8') ?></strong></p>
                <!-- 动态显示当前班级的学生人数 -->
                <p class="muted" style="margin:0.25rem 0 0">Students Enrolled: <strong><?= $studentCount ?></strong></p>
                <?php if (trim((string) ($class['description'] ?? '')) !== ''): ?>
                    <p style="margin:0.35rem 0 0"><?= nl2br(htmlspecialchars((string) $class['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>

                <!-- 【内联编辑班级名称】点击展开一个小表单，提交到 update_class.php -->
                <details class="class-edit-name">
                    <summary class="class-edit-name__toggle">Edit class name</summary>
                    <form method="post" action="update_class.php" class="row class-edit-name__form">
                        <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                        <label class="field" style="flex:1;min-width:200px">
                            <span>Class name</span>
                            <!-- required 确保不为空；value 预填当前名称 -->
                            <input type="text" name="class_name" required maxlength="255"
                                   value="<?= htmlspecialchars((string) $class['name'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end">Save</button>
                    </form>
                </details>
            </div>

            <!-- 【开放/关闭班级 切换按钮】根据 status 显示不同按钮与处理器 -->
            <?php if ($isOpen): ?>
                <form method="post" action="close_classroom.php" class="classroom-leave-form"
                      onsubmit="return confirm('Close this class? Students will lose access and cannot submit work.');">
                    <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">Close Class</button>
                </form>
            <?php else: ?>
                <!-- status = 0（已关闭）→ 显示「Open Class」，提交到 open_classroom.php 设回 status = 1 -->
                <form method="post" action="open_classroom.php" class="classroom-leave-form">
                    <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Open Class</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="classroom-tabs">
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=chat" class="classroom-tab <?= $tab === 'chat' ? 'is-active' : '' ?>">Chat</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=announcements" class="classroom-tab <?= $tab === 'announcements' ? 'is-active' : '' ?>">Announcements</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=assignments" class="classroom-tab <?= $tab === 'assignments' ? 'is-active' : '' ?>">Assignments</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=quizzes" class="classroom-tab <?= $tab === 'quizzes' ? 'is-active' : '' ?>">Quizzes</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=attendance" class="classroom-tab <?= $tab === 'attendance' ? 'is-active' : '' ?>">Attendance</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=students" class="classroom-tab <?= $tab === 'students' ? 'is-active' : '' ?>">Students</a>
            <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=reports" class="classroom-tab <?= $tab === 'reports' ? 'is-active' : '' ?>">Reports</a>
        </div>

        <?php if ($tab === 'reports'): ?>
            <div class="classroom-tabs classroom-tabs--sub">
                <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=reports&amp;rtab=engagement" class="classroom-tab <?= $reportTab === 'engagement' ? 'is-active' : '' ?>">Engagement Report</a>
                <a href="classroom.php?id=<?= $classroomId ?>&amp;tab=reports&amp;rtab=academic" class="classroom-tab <?= $reportTab === 'academic' ? 'is-active' : '' ?>">Academic Grade Report</a>
            </div>

            <?php if ($reportTab === 'engagement'): ?>
            <div class="card">
                <div class="reports-toolbar">
                    <h2>Engagement Report</h2>
                    <button type="button" onclick="window.print()" class="btn btn-secondary no-print">Print Report</button>
                </div>
                <p class="print-only"><strong>Class:</strong> <?= htmlspecialchars((string) $class['name'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="muted">Behavioral participation points from assignment submissions (not academic grades).</p>

                <?php if ($reportEngagement === []): ?>
                    <p class="muted" style="margin-top:1rem">No engagement data yet.</p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:1rem">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Participation points</th>
                                <th>Assignments (+1)</th>
                                <th>Quizzes (+1)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportEngagement as $r): ?>
                                <?php
                                $nm = trim((string) ($r['full_name'] ?? ''));
                                if ($nm === '') {
                                    $nm = (string) $r['username'];
                                }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><strong><?= (int) $r['engagement_points'] ?></strong></td>
                                    <td><?= (int) $r['assignments_completed'] ?></td>
                                    <td><?= (int) ($r['quizzes_participated'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3 style="margin:1.5rem 0 0.5rem">Attendance sessions</h3>
                <form method="get" action="classroom.php" class="row filter-bar" style="align-items:flex-end;gap:0.5rem;flex-wrap:wrap">
                    <input type="hidden" name="id" value="<?= $classroomId ?>">
                    <input type="hidden" name="tab" value="reports">
                    <input type="hidden" name="rtab" value="engagement">
                    <label class="field" style="flex:2;min-width:180px">
                        <span>Student name / username</span>
                        <input type="text" name="rq" placeholder="Type to search…"
                               value="<?= htmlspecialchars($repName, ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label class="field" style="min-width:150px">
                        <span>Date</span>
                        <input type="date" name="rdate" value="<?= htmlspecialchars($repDate, ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label class="field" style="min-width:140px">
                        <span>Status</span>
                        <select name="rstatus">
                            <option value="" <?= $repStatus === '' ? 'selected' : '' ?>>All</option>
                            <option value="present" <?= $repStatus === 'present' ? 'selected' : '' ?>>Present</option>
                            <option value="absent" <?= $repStatus === 'absent' ? 'selected' : '' ?>>Absent</option>
                        </select>
                    </label>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>
                <?php if ($reportAttendance === []): ?>
                    <p class="muted" style="margin-top:0.75rem">No attendance records match your filters.</p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:0.75rem">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportAttendance as $r): ?>
                                <?php
                                $nm = trim((string) ($r['full_name'] ?? ''));
                                if ($nm === '') {
                                    $nm = (string) $r['username'];
                                }
                                $present = (int) $r['status'] === 1;
                                $attId = (int) $r['id'];
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $r['date'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge <?= $present ? 'badge-student' : 'badge-admin' ?>">
                                            <?= $present ? 'Present' : 'Absent' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" action="attendance_delete.php" class="inline-delete-form"
                                              onsubmit="return confirm('Are you sure you want to delete this attendance record?');">
                                            <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                                            <input type="hidden" name="attendance_id" value="<?= $attId ?>">
                                            <input type="hidden" name="return_tab" value="reports">
                                            <input type="hidden" name="return_rtab" value="engagement">
                                            <input type="hidden" name="rq" value="<?= htmlspecialchars($repName, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="rdate" value="<?= htmlspecialchars($repDate, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="rstatus" value="<?= htmlspecialchars($repStatus, ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="reports-toolbar">
                    <h2>Academic Grade Report</h2>
                    <button type="button" onclick="window.print()" class="btn btn-secondary no-print">Print Report</button>
                </div>
                <p class="muted">Quiz scores and lecturer-assigned grades — separate from participation points.</p>

                <h3 style="margin:1rem 0 0.5rem">Coursework totals</h3>
                <?php if ($reportCourseworkTotals === []): ?>
                    <p class="muted">No students enrolled.</p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:0.5rem">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Quiz totals</th>
                                <th>Assignment grades</th>
                                <th>Graded assignments</th>
                                <th>Combined total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportCourseworkTotals as $r): ?>
                                <?php
                                $nm = trim((string) ($r['full_name'] ?? ''));
                                if ($nm === '') {
                                    $nm = (string) $r['username'];
                                }
                                $quizEarned = (float) $r['quiz_earned_total'];
                                $quizMax = (float) $r['quiz_max_total'];
                                $assignEarned = (float) $r['assignment_earned_total'];
                                $assignMax = (float) $r['assignment_max_total'];
                                $combined = $quizEarned + $assignEarned;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($quizMax > 0): ?>
                                            <?= number_format($quizEarned, 2) ?> / <?= number_format($quizMax, 2) ?>
                                        <?php elseif ($quizEarned > 0): ?>
                                            <?= number_format($quizEarned, 2) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($assignMax > 0): ?>
                                            <?= number_format($assignEarned, 2) ?> / <?= number_format($assignMax, 2) ?>
                                        <?php elseif ($assignEarned > 0): ?>
                                            <?= number_format($assignEarned, 2) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int) $r['assignments_graded'] ?></td>
                                    <td><strong><?= number_format($combined, 2) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3 style="margin:1.5rem 0 0.5rem">Quiz performance</h3>
                <?php if ($reportQuiz === []): ?>
                    <p class="muted">No quiz activity yet.</p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:0.5rem">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Quiz</th>
                                <th>Academic grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportQuiz as $r): ?>
                                <?php
                                $nm = trim((string) ($r['full_name'] ?? ''));
                                if ($nm === '') {
                                    $nm = (string) $r['username'];
                                }
                                $qScore = (float) $r['score'];
                                $qMax = (float) $r['max_score'];
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $r['quiz_title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($qMax > 0): ?>
                                            <?= number_format($qScore, 2) ?> / <?= number_format($qMax, 2) ?>
                                        <?php else: ?>
                                            <?= number_format($qScore, 2) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3 style="margin:1.5rem 0 0.5rem">Assignment grades</h3>
                <form method="get" action="classroom.php" class="row filter-bar" style="align-items:flex-end;gap:0.5rem;flex-wrap:wrap">
                    <input type="hidden" name="id" value="<?= $classroomId ?>">
                    <input type="hidden" name="tab" value="reports">
                    <input type="hidden" name="rtab" value="academic">
                    <label class="field" style="flex:2;min-width:200px">
                        <span>Student name or assignment title</span>
                        <input type="text" name="aq" placeholder="Type to search…"
                               value="<?= htmlspecialchars($repAssign, ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <?php if ($reportAssignGrades === []): ?>
                    <p class="muted" style="margin-top:0.75rem">No assignment data yet.</p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:0.75rem">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assignment</th>
                                <th>Status</th>
                                <th>Grade</th>
                                <th>Feedback</th>
                                <th>File</th>
                                <th>Grade Center</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $lastAssignId = 0;
                            foreach ($reportAssignGrades as $r):
                                $nm = trim((string) ($r['full_name'] ?? ''));
                                if ($nm === '') {
                                    $nm = (string) $r['username'];
                                }
                                $submitted = !empty($r['completed_at']);
                                $aidR = (int) $r['assignment_id'];
                                $sidR = (int) $r['student_id'];
                                $filePath = (string) ($r['submitted_file_path'] ?? '');
                                $assignScore = $r['grade'] !== null ? (float) $r['grade'] : null;
                                $assignMax = $r['total_marks'] !== null ? (float) $r['total_marks'] : null;
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $r['assignment_title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge <?= $submitted ? 'badge-student' : 'badge-admin' ?>">
                                            <?= $submitted ? 'Submitted' : 'Missing' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($assignMax !== null && $assignMax > 0): ?>
                                            <?= $assignScore !== null ? number_format($assignScore, 2) : '—' ?> / <?= number_format($assignMax, 2) ?>
                                        <?php elseif ($assignScore !== null): ?>
                                            <?= number_format($assignScore, 2) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $submitted && trim((string) ($r['feedback'] ?? '')) !== '' ? htmlspecialchars((string) $r['feedback'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                    <td>
                                        <?php if ($submitted && $filePath !== ''): ?>
                                            <a href="view_submission.php?assignment_id=<?= $aidR ?>&amp;student_id=<?= $sidR ?>">View</a>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($aidR !== $lastAssignId): ?>
                                            <a href="grade_assignment.php?assignment_id=<?= $aidR ?>&amp;classroom_id=<?= $classroomId ?>">Open</a>
                                            <?php $lastAssignId = $aidR; ?>
                                        <?php else: ?>
                                            <span class="muted">↑</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php elseif ($tab === 'students'): ?>
            <!-- ================= Students 标签页：学生名单 + 搜索 ================= -->
            <div class="card">
                <h2>Students Enrolled: <?= $studentCount ?></h2>
                <p class="muted">Search students by name or username to quickly find anyone in this class.</p>

                <!-- 【搜索表单】GET 提交回本页面，保留 id 与 tab，附带搜索关键字 q -->
                <form method="get" action="classroom.php" class="row" style="align-items:flex-end;gap:0.5rem">
                    <input type="hidden" name="id" value="<?= $classroomId ?>">
                    <input type="hidden" name="tab" value="students">
                    <label class="field" style="flex:1;min-width:220px">
                        <span>Search</span>
                        <input type="text" name="q" placeholder="Type a name or username…"
                               value="<?= htmlspecialchars($studentSearch, ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($studentSearch !== ''): ?>
                        <!-- 清除搜索：回到无关键字的学生列表 -->
                        <a class="btn btn-ghost" href="classroom.php?id=<?= $classroomId ?>&amp;tab=students">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if ($studentList === []): ?>
                    <p class="muted" style="margin-top:1rem">
                        <?= $studentSearch !== '' ? 'No students match your search.' : 'No students have joined this class yet.' ?>
                    </p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:1rem">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentList as $stu): ?>
                                <?php
                                // 优先显示全名；若没有全名则回退显示用户名
                                $displayName = trim((string) ($stu['full_name'] ?? ''));
                                if ($displayName === '') {
                                    $displayName = (string) $stu['username'];
                                }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $stu['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($stu['joined_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php elseif ($tab === 'attendance'): ?>
            <div class="card">
                <h2>Daily attendance</h2>
                <p class="muted">Choose a session date to load the roster, mark students present or absent, then save. Any calendar date is allowed.</p>

                <form method="get" action="classroom.php" class="row attendance-toolbar" style="align-items:flex-end;gap:0.5rem;flex-wrap:wrap">
                    <input type="hidden" name="id" value="<?= $classroomId ?>">
                    <input type="hidden" name="tab" value="attendance">
                    <label class="field" style="min-width:200px">
                        <span>Select Session Date:</span>
                        <input type="date" name="date" value="<?= htmlspecialchars($attDate, ENT_QUOTES, 'UTF-8') ?>"
                               onchange="this.form.submit()">
                    </label>
                    <label class="field" style="flex:1;min-width:200px">
                        <span>Search student (name / username)</span>
                        <input type="text" name="asq" placeholder="Type to filter…"
                               value="<?= htmlspecialchars($attSearch, ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($attSearch !== ''): ?>
                        <a class="btn btn-ghost" href="classroom.php?id=<?= $classroomId ?>&amp;tab=attendance&amp;date=<?= htmlspecialchars($attDate, ENT_QUOTES, 'UTF-8') ?>">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if ($classStudents === []): ?>
                    <p class="muted" style="margin-top:1rem">No students have joined this class yet.</p>
                <?php elseif ($attStudents === []): ?>
                    <p class="muted" style="margin-top:1rem">No students match your search.</p>
                <?php else: ?>
                    <form method="post" action="attendance_save.php" class="stack" style="margin-top:1rem">
                        <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                        <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($attDate, ENT_QUOTES, 'UTF-8') ?>">
                        <table class="data-table attendance-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Present</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attStudents as $st): ?>
                                    <?php
                                    $sid = (int) $st['id'];
                                    $label = trim((string) ($st['full_name'] ?? ''));
                                    if ($label === '') {
                                        $label = (string) $st['username'];
                                    }
                                    // 已有当天记录则用其状态；否则默认出勤(present)
                                    $wasPresent = array_key_exists($sid, $todayAttendance)
                                        ? $todayAttendance[$sid] === 1
                                        : true;
                                    ?>
                                    <tr>
                                        <!-- 登记本行学生 ID 到 roster，保存时据此限定更新范围 -->
                                        <input type="hidden" name="roster[]" value="<?= $sid ?>">
                                        <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <label class="attendance-toggle">
                                                <input type="checkbox" name="status[<?= $sid ?>]" value="1" <?= $wasPresent ? 'checked' : '' ?>>
                                                <span class="attendance-toggle__label">Present</span>
                                            </label>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Save attendance</button>
                            <a class="btn btn-ghost" href="classroom.php?id=<?= $classroomId ?>&amp;tab=attendance&amp;date=<?= htmlspecialchars($attDate, ENT_QUOTES, 'UTF-8') ?><?= $attSearch !== '' ? '&amp;asq=' . urlencode($attSearch) : '' ?>">Reload</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif ($tab === 'announcements'): ?>
            <div class="card announcements-panel">
                <h2>Announcements</h2>
                <p class="muted">Official teacher announcements and system notifications — separate from class chat.</p>
                <div id="announce-feed"
                     class="chat-feed announcements-feed"
                     data-feed-mode="announcements"
                     data-classroom-id="<?= $classroomId ?>"
                     data-poll-url="<?= htmlspecialchars($announcePollUrl, ENT_QUOTES, 'UTF-8') ?>"
                     data-action-url="<?= htmlspecialchars($messageActionUrl, ENT_QUOTES, 'UTF-8') ?>"
                     data-viewer-id="<?= $userId ?>"
                     data-viewer-is-teacher="1"
                     aria-live="polite"></div>
                <form method="post" action="classroom_announcement_post.php" class="stack chat-compose">
                    <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                    <label class="field">
                        <span>New announcement</span>
                        <textarea name="body" required maxlength="4000" rows="3" placeholder="Post an official announcement to all students…"></textarea>
                    </label>
                    <button type="submit" class="btn btn-primary">Publish announcement</button>
                </form>
            </div>
            <script src="../assets/js/classroom_feed.js"></script>
        <?php elseif ($tab === 'chat'): ?>
            <div class="card classroom-chat-card">
                <h2>Class chat</h2>
                <p class="muted">Regular discussion between students and teachers. Announcements are posted separately.</p>
                <div id="chat-feed"
                     class="chat-feed"
                     data-feed-mode="chat"
                     data-classroom-id="<?= $classroomId ?>"
                     data-poll-url="<?= htmlspecialchars($chatPollUrl, ENT_QUOTES, 'UTF-8') ?>"
                     data-action-url="<?= htmlspecialchars($messageActionUrl, ENT_QUOTES, 'UTF-8') ?>"
                     data-viewer-id="<?= $userId ?>"
                     data-viewer-is-teacher="1"
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
                <h2>Assignments for this class</h2>
                <p class="muted">Only students in this class see these tasks.</p>
                <p><a class="btn btn-primary" href="manage_assignments.php?classroom_id=<?= $classroomId ?>">Post new assignment</a></p>
                <?php if ($assignList === []): ?>
                    <p class="muted">No assignments yet.</p>
                <?php else: ?>
                    <?php foreach ($assignList as $a): ?>
                        <div class="question-block">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
                                <div>
                                    <strong><?= htmlspecialchars((string) $a['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <p class="muted" style="margin:0.35rem 0 0"><?= nl2br(htmlspecialchars((string) ($a['description'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                                    <p class="muted" style="margin:0.5rem 0 0;font-size:0.85rem">
                                        Due: <?= htmlspecialchars(assignment_format_due_date(isset($a['due_date']) ? (string) $a['due_date'] : null), ENT_QUOTES, 'UTF-8') ?>
                                        · Posted <?= htmlspecialchars((string) $a['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <?php
                                $assignment = $a;
                                $returnClassroomId = $classroomId;
                                $openAssignmentId = 0;
                                require dirname(__DIR__) . '/includes/assignment_teacher_actions.php';
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php elseif ($tab === 'quizzes'): ?>
            <div class="card">
                <h2>Quizzes for this class</h2>
                <p class="muted">Live quizzes scoped to this classroom. Students join with the room code from the dashboard.</p>
                <p><a class="btn btn-primary" href="create_quiz.php?classroom_id=<?= $classroomId ?>">Create quiz</a></p>
                <?php if ($quizList === []): ?>
                    <p class="muted">No quizzes for this class yet.</p>
                <?php else: ?>
                    <table class="data-table" style="margin-top:0.75rem">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Room code</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quizList as $qz): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars((string) $qz['title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td><?= htmlspecialchars((string) $qz['room_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge badge-teacher"><?= htmlspecialchars((string) $qz['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td><?= htmlspecialchars((string) $qz['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <div class="btn-group btn-group--compact">
                                            <a class="btn btn-primary btn-sm" href="run_quiz.php?id=<?= (int) $qz['id'] ?>">Host live</a>
                                            <a class="btn btn-ghost btn-sm" href="edit_quiz.php?id=<?= (int) $qz['id'] ?>">Edit</a>
                                            <form method="post" action="quiz_delete.php" class="inline-delete-form"
                                                  onsubmit="return confirm('Are you sure you want to permanently delete this quiz and all its associated answers?');">
                                                <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                                                <input type="hidden" name="quiz_id" value="<?= (int) $qz['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="date"][name="due_date"]').forEach(function (input) {
            const form = input.closest('form');
            const pubMin = input.getAttribute('data-publication-min')
                || (form && form.getAttribute('data-publication-min'))
                || input.getAttribute('min');
            if (!pubMin) {
                return;
            }
            if (!form || !form.classList.contains('edit-form') || !input.value || input.value >= pubMin) {
                input.setAttribute('min', pubMin);
            }
        });
    });
    </script>
</body>
</html>
