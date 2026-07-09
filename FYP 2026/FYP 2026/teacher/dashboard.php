<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

$classes = [];
// 通过相关子查询统计每个班级的学生人数（student_count）：
//   子查询 COUNT(*) 在 classroom_members 中按班级 ID 计数，
//   这样每张班级卡片都能显示 "Students Enrolled: X"。
$stmt = $mysqli->prepare(
    'SELECT cl.id, cl.name, cl.join_code, cl.status, cl.created_at,
            (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = cl.id) AS student_count
     FROM classrooms cl
     WHERE cl.teacher_id = ?
     ORDER BY cl.name ASC'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $classes[] = $row;
}
$stmt->close();

$quizzes = [];
$stmt = $mysqli->prepare(
    'SELECT q.id, q.title, q.room_code, q.status, q.current_question, q.created_at,
            cl.name AS class_name
     FROM quizzes q
     LEFT JOIN classrooms cl ON cl.id = q.classroom_id
     WHERE q.teacher_id = ?
     ORDER BY q.created_at DESC'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $quizzes[] = $row;
}
$stmt->close();

$panelPageTitle = 'Teacher dashboard';
$panelHeading = 'Teacher dashboard';
$panelRole = 'teacher';
$panelActiveNav = 'home';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout-wide">
        <section class="panel-hero dashboard-banner dashboard-banner--teacher">
            <div class="dashboard-banner__inner">
                <div class="dashboard-banner__text">
                    <h2 class="panel-hero__title dashboard-banner__greeting">Your classes</h2>
                    <p class="panel-hero__sub dashboard-banner__sub">Create a class, share the 6-digit join code, then open a class for chat, attendance, and coursework.</p>
                </div>
                <a href="create_classroom.php" class="btn btn-primary" style="align-self:center">+ Create class</a>
            </div>
        </section>

        <?php if ($classes === []): ?>
            <div class="card">
                <p class="muted">You have no classes yet. Create one to get a join code for students.</p>
            </div>
        <?php else: ?>
            <div class="gc-class-grid">
                <?php foreach ($classes as $c): ?>
                    <?php
                    $cid = (int) $c['id'];
                    $bannerColor = classroom_card_color($cid);
                    $isOpen = (int) ($c['status'] ?? 1) === 1;
                    ?>
                    <a href="classroom.php?id=<?= $cid ?>" class="gc-class-card">
                        <div class="gc-class-card__banner" style="background:<?= htmlspecialchars($bannerColor, ENT_QUOTES, 'UTF-8') ?>">
                            <h3 class="gc-class-card__title"><?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                        </div>
                        <div class="gc-class-card__body">
                            <p class="gc-class-card__meta">
                                Join code <?= htmlspecialchars((string) $c['join_code'], ENT_QUOTES, 'UTF-8') ?>
                                · <?= $isOpen ? 'Open' : 'Closed' ?>
                            </p>
                            <!-- 每张卡片显示该班级的学生人数 -->
                            <p class="gc-class-card__meta">Students Enrolled: <?= (int) ($c['student_count'] ?? 0) ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top:1.5rem">
            <h2>Live quizzes</h2>
            <?php if ($quizzes === []): ?>
                <p class="muted">No quizzes yet. <a href="create_quiz.php">Create one</a>.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Class</th>
                            <th>Room code</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quizzes as $q): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $q['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if (!empty($q['class_name'])): ?>
                                        <?= htmlspecialchars((string) $q['class_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars((string) $q['room_code'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><?= htmlspecialchars((string) $q['status'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="btn-group btn-group--compact">
                                        <a class="btn btn-primary btn-sm" href="run_quiz.php?id=<?= (int) $q['id'] ?>">Host live</a>
                                        <a class="btn btn-ghost btn-sm" href="edit_quiz.php?id=<?= (int) $q['id'] ?>">Edit</a>
                                        <form method="post" action="quiz_delete.php" class="inline-delete-form">
                                            <input type="hidden" name="quiz_id" value="<?= (int) $q['id'] ?>">
                                            <input type="hidden" name="return_to" value="dashboard">
                                            <button type="submit" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Are you sure you want to permanently delete this quiz and all its score data?');">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
