<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

$profStmt = $mysqli->prepare('SELECT username, full_name FROM users WHERE id = ? LIMIT 1');
$profStmt->bind_param('i', $userId);
$profStmt->execute();
$profRow = $profStmt->get_result()->fetch_assoc();
$profStmt->close();
$fullName = trim((string) ($profRow['full_name'] ?? ''));
$displayName = $fullName !== '' ? $fullName : $username;

$points = 0;
$stmt = $mysqli->prepare('SELECT total_points FROM total_scores WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($res) {
    $points = (int) $res['total_points'];
}

$rank = 1;
$rstmt = $mysqli->prepare(
    'SELECT 1 + COUNT(*) AS rnk
     FROM total_scores t2
     INNER JOIN users u ON u.id = t2.user_id AND u.role = \'student\'
     WHERE t2.total_points > ?'
);
$rstmt->bind_param('i', $points);
$rstmt->execute();
$rrow = $rstmt->get_result()->fetch_assoc();
$rstmt->close();
if ($rrow) {
    $rank = (int) $rrow['rnk'];
}

$pendStmt = $mysqli->prepare(
    'SELECT COUNT(*) AS c FROM assignments a
     INNER JOIN classrooms cl ON cl.id = a.classroom_id AND cl.status = 1
     INNER JOIN classroom_members cm ON cm.classroom_id = cl.id AND cm.student_id = ?
     WHERE NOT EXISTS (
         SELECT 1 FROM assignment_completions ac
         WHERE ac.assignment_id = a.id AND ac.student_id = ?
     )'
);
$pendStmt->bind_param('ii', $userId, $userId);
$pendStmt->execute();
$pendRow = $pendStmt->get_result()->fetch_assoc();
$pendStmt->close();
$pendingCount = $pendRow ? (int) $pendRow['c'] : 0;

$leaderPreview = [];
$lp = $mysqli->query(
    'SELECT u.username, ts.total_points
     FROM total_scores ts
     INNER JOIN users u ON u.id = ts.user_id AND u.role = \'student\'
     ORDER BY ts.total_points DESC, u.username ASC
     LIMIT 5'
);
if ($lp) {
    while ($row = $lp->fetch_assoc()) {
        $leaderPreview[] = $row;
    }
}

$myClasses = [];
$mc = $mysqli->prepare(
    'SELECT cl.id, cl.name, cl.join_code FROM classrooms cl
     INNER JOIN classroom_members cm ON cm.classroom_id = cl.id AND cm.student_id = ?
     WHERE cl.status = 1
     ORDER BY cl.name ASC'
);
$mc->bind_param('i', $userId);
$mc->execute();
$mr = $mc->get_result();
while ($row = $mr->fetch_assoc()) {
    $myClasses[] = $row;
}
$mc->close();
$studentPageTitle = 'Student dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Student</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="student-dashboard">
    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>

    <main class="layout-wide dashboard-main">
        <section class="dashboard-banner" aria-label="Welcome">
            <div class="dashboard-banner__inner">
                <div class="dashboard-banner__text">
                    <h2 class="dashboard-banner__greeting">Welcome back, <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>!</h2>
                    <p class="dashboard-banner__sub">Open a class below for team chat and assignments, or join a new class with your code.</p>
                    <p style="margin:0.75rem 0 0">
                        <a href="join_classroom.php" class="btn btn-primary">Join class</a>
                    </p>
                </div>
                <div class="dashboard-summary" role="group" aria-label="Points and rank">
                    <div class="summary-badge summary-badge--points">
                        <span class="summary-badge__label">Current points</span>
                        <span class="summary-badge__value"><?= $points ?></span>
                    </div>
                    <div class="summary-badge summary-badge--rank">
                        <span class="summary-badge__label">Rank</span>
                        <span class="summary-badge__value">#<?= $rank ?></span>
                        <span class="summary-badge__hint">Among all students</span>
                    </div>
                </div>
            </div>
        </section>

        <h3 class="dashboard-section-title">Your classes</h3>
        <?php if ($myClasses === []): ?>
            <div class="card">
                <p class="muted">You are not in any class yet. Use <strong>Join class</strong> with your teacher’s 6-digit code.</p>
            </div>
        <?php else: ?>
            <div class="course-grid">
                <?php foreach ($myClasses as $c): ?>
                    <?php
                    $cid = (int) $c['id'];
                    $stripe = classroom_card_color($cid);
                    ?>
                    <a href="classroom.php?id=<?= $cid ?>" class="course-card">
                        <span class="course-card__stripe" style="background:<?= htmlspecialchars($stripe, ENT_QUOTES, 'UTF-8') ?>"></span>
                        <span class="course-card__body">
                            <h3 class="course-card__title"><?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="course-card__meta">Open class · code <?= htmlspecialchars((string) $c['join_code'], ENT_QUOTES, 'UTF-8') ?></p>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h3 class="dashboard-section-title" style="margin-top:1.75rem">Quick links</h3>
        <div class="dashboard-blocks">
            <a href="join_quiz.php" class="dashboard-block dashboard-block--live">
                <span class="dashboard-block__kicker">Live session</span>
                <span class="dashboard-block__title">Live quiz</span>
                <span class="dashboard-block__desc">Join with a room code from your teacher.</span>
                <span class="dashboard-block__cta">Join now →</span>
            </a>

            <a href="assignments.php" class="dashboard-block dashboard-block--assign">
                <span class="dashboard-block__kicker">Coursework</span>
                <span class="dashboard-block__title">All assignments</span>
                <span class="dashboard-block__desc">
                    <?php if ($pendingCount === 0): ?>
                        No pending tasks in your classes.
                    <?php elseif ($pendingCount === 1): ?>
                        <strong>1</strong> pending task across your classes.
                    <?php else: ?>
                        <strong><?= $pendingCount ?></strong> pending tasks across your classes.
                    <?php endif; ?>
                </span>
                <span class="dashboard-block__cta">View assignments →</span>
            </a>

            <a href="point_history.php" class="dashboard-block dashboard-block--history">
                <span class="dashboard-block__kicker">Activity</span>
                <span class="dashboard-block__title">Point history</span>
                <span class="dashboard-block__desc">See how you earned points.</span>
                <span class="dashboard-block__cta">View history →</span>
            </a>

            <section class="dashboard-block dashboard-block--leaderboard" aria-labelledby="leaderboard-heading">
                <span class="dashboard-block__kicker">Competition</span>
                <span class="dashboard-block__title" id="leaderboard-heading">Leaderboard</span>
                <p class="dashboard-block__desc dashboard-block__desc--tight">Top 5 students. Admins and teachers excluded.</p>
                <?php if ($leaderPreview === []): ?>
                    <p class="dashboard-leader-empty muted">No rankings yet.</p>
                <?php else: ?>
                    <table class="dashboard-leader-table">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Student</th>
                                <th scope="col">Pts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaderPreview as $i => $row): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) $row['total_points'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
