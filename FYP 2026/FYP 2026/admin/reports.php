<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$tab = $_GET['tab'] ?? 'performance';
if (!in_array($tab, ['performance', 'classes', 'attendance'], true)) {
    $tab = 'performance';
}

$performance = [];
$pf = $mysqli->query(
    'SELECT u.id, u.username, u.full_name, COALESCE(ts.total_points, 0) AS total_points
     FROM users u
     LEFT JOIN total_scores ts ON ts.user_id = u.id
     WHERE u.role = \'student\'
     ORDER BY total_points DESC, u.username ASC'
);
if ($pf) {
    $rank = 0;
    while ($row = $pf->fetch_assoc()) {
        $rank++;
        $row['rank'] = $rank;
        $performance[] = $row;
    }
}

$classActivity = [];
$ca = $mysqli->query(
    'SELECT c.id, c.name, c.join_code, c.created_at,
            u.username AS teacher_username,
            u.full_name AS teacher_name,
            (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = c.id) AS student_count
     FROM classrooms c
     INNER JOIN users u ON u.id = c.teacher_id
     ORDER BY c.name ASC'
);
if ($ca) {
    while ($row = $ca->fetch_assoc()) {
        $classActivity[] = $row;
    }
}

$attendanceRows = [];
$at = $mysqli->query(
    'SELECT a.id, a.status, a.date, a.created_at,
            c.name AS class_name,
            u.username, u.full_name
     FROM attendance a
     INNER JOIN classrooms c ON c.id = a.class_id
     INNER JOIN users u ON u.id = a.user_id
     ORDER BY a.date DESC, a.created_at DESC, c.name ASC
     LIMIT 500'
);
if ($at) {
    while ($row = $at->fetch_assoc()) {
        $attendanceRows[] = $row;
    }
}

$tabTitles = [
    'performance' => 'Student performance',
    'classes' => 'Class activity',
    'attendance' => 'Attendance summary',
];
$panelPageTitle = 'Reports — Admin';
$panelHeading = 'Reports';
$panelRole = 'admin';
$panelActiveNav = 'reports';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout-wide print-root">
        <div class="reports-toolbar no-print">
            <div class="classroom-tabs">
                <a href="reports.php?tab=performance" class="classroom-tab <?= $tab === 'performance' ? 'is-active' : '' ?>">Performance</a>
                <a href="reports.php?tab=classes" class="classroom-tab <?= $tab === 'classes' ? 'is-active' : '' ?>">Class activity</a>
                <a href="reports.php?tab=attendance" class="classroom-tab <?= $tab === 'attendance' ? 'is-active' : '' ?>">Attendance</a>
            </div>
            <button type="button" class="btn btn-primary" onclick="window.print()">Print report</button>
        </div>

        <div class="card print-section">
            <div class="print-header">
                <h2><?= htmlspecialchars($tabTitles[$tab], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="muted print-meta">Generated <?= htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') ?> · Classroom Engagement System</p>
            </div>

            <?php if ($tab === 'performance'): ?>
                <?php if ($performance === []): ?>
                    <p class="muted">No students registered.</p>
                <?php else: ?>
                    <div class="report-table-block">
                        <?php $reportSearchColumns = '1,2'; require dirname(__DIR__) . '/includes/report_search_bar.php'; ?>
                        <table class="data-table" data-report-search-table>
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Student</th>
                                    <th>Username</th>
                                    <th>Total points</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($performance as $row): ?>
                                    <?php
                                    $label = trim((string) ($row['full_name'] ?? ''));
                                    if ($label === '') {
                                        $label = '—';
                                    }
                                    $searchText = strtolower($label . ' ' . (string) $row['username']);
                                    ?>
                                    <tr data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                                        <td>#<?= (int) $row['rank'] ?></td>
                                        <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) $row['total_points'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="report-search-empty muted no-print" data-report-search-empty hidden>No students match your search.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'classes'): ?>
                <?php if ($classActivity === []): ?>
                    <p class="muted">No classes created yet.</p>
                <?php else: ?>
                    <div class="report-table-block">
                        <?php $reportSearchColumns = '0,2'; require dirname(__DIR__) . '/includes/report_search_bar.php'; ?>
                        <table class="data-table" data-report-search-table>
                            <thead>
                                <tr>
                                    <th>Class</th>
                                    <th>Join code</th>
                                    <th>Teacher</th>
                                    <th>Students</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classActivity as $row): ?>
                                    <?php
                                    $tname = trim((string) ($row['teacher_name'] ?? ''));
                                    if ($tname === '') {
                                        $tname = (string) $row['teacher_username'];
                                    }
                                    $searchText = strtolower(
                                        (string) $row['name'] . ' ' . $tname . ' ' . (string) $row['teacher_username']
                                    );
                                    ?>
                                    <tr data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                                        <td><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $row['join_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($tname, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) $row['student_count'] ?></td>
                                        <td><?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="report-search-empty muted no-print" data-report-search-empty hidden>No classes match your search.</p>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <?php if ($attendanceRows === []): ?>
                    <p class="muted">No attendance records yet. Teachers save attendance from each class.</p>
                <?php else: ?>
                    <div class="report-table-block">
                        <?php $reportSearchColumns = '2,3'; require dirname(__DIR__) . '/includes/report_search_bar.php'; ?>
                        <table class="data-table" data-report-search-table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Class</th>
                                    <th>Student</th>
                                    <th>Username</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendanceRows as $row): ?>
                                    <?php
                                    $sname = trim((string) ($row['full_name'] ?? ''));
                                    if ($sname === '') {
                                        $sname = '—';
                                    }
                                    $dateOnly = (string) ($row['date'] ?? substr((string) $row['created_at'], 0, 10));
                                    $searchText = strtolower($sname . ' ' . (string) $row['username']);
                                    ?>
                                    <tr data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                                        <td><?= htmlspecialchars($dateOnly, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $row['class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($sname, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) $row['status'] === 1 ? 'Present' : 'Absent' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="report-search-empty muted no-print" data-report-search-empty hidden>No attendance records match your search.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    <script src="../assets/js/report_live_search.js"></script>
</body>
</html>
