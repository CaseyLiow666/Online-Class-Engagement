<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

$totalPoints = 0;
$ptsStmt = $mysqli->prepare('SELECT total_points FROM total_scores WHERE user_id = ? LIMIT 1');
$ptsStmt->bind_param('i', $userId);
$ptsStmt->execute();
$ptsRow = $ptsStmt->get_result()->fetch_assoc();
$ptsStmt->close();
if ($ptsRow) {
    $totalPoints = (int) $ptsRow['total_points'];
}

$classReports = [];
$crStmt = $mysqli->prepare(
    'SELECT cl.id, cl.name,
            (SELECT COUNT(*) FROM attendance a
             WHERE a.class_id = cl.id AND a.user_id = ? AND a.status = 1) AS present_days,
            (SELECT COUNT(DISTINCT a.date) FROM attendance a WHERE a.class_id = cl.id) AS session_days
     FROM classrooms cl
     INNER JOIN classroom_members cm ON cm.classroom_id = cl.id AND cm.student_id = ?
     WHERE cl.status = 1
     ORDER BY cl.name ASC'
);
$crStmt->bind_param('ii', $userId, $userId);
$crStmt->execute();
$crRes = $crStmt->get_result();
while ($row = $crRes->fetch_assoc()) {
    $present = (int) $row['present_days'];
    $sessions = (int) $row['session_days'];
    $row['attendance_pct'] = $sessions > 0 ? round(100 * $present / $sessions, 1) : null;
    $classReports[] = $row;
}
$crStmt->close();

$studentPageTitle = 'My Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Report</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="student-dashboard">
    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>
    <main class="layout">
        <div class="card">
            <h2>Engagement Report</h2>
            <p class="muted">Participation and attendance across all your classes — separate from academic grades.</p>

            <div class="dashboard-summary" style="margin-top:1rem">
                <div class="summary-badge summary-badge--points">
                    <span class="summary-badge__label">Total participation points</span>
                    <span class="summary-badge__value"><?= $totalPoints ?></span>
                </div>
            </div>

            <h3 style="margin:1.5rem 0 0.5rem">Attendance by class</h3>
            <?php if ($classReports === []): ?>
                <p class="muted">You are not enrolled in any open classes yet.</p>
            <?php else: ?>
                <table class="data-table" style="margin-top:0.5rem">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Present days</th>
                            <th>Sessions conducted</th>
                            <th>Attendance rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classReports as $cr): ?>
                            <tr>
                                <td>
                                    <a href="classroom.php?id=<?= (int) $cr['id'] ?>&amp;tab=my_report">
                                        <?= htmlspecialchars((string) $cr['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </td>
                                <td><?= (int) $cr['present_days'] ?></td>
                                <td><?= (int) $cr['session_days'] ?></td>
                                <td>
                                    <?= $cr['attendance_pct'] !== null ? $cr['attendance_pct'] . '%' : '—' ?>
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
