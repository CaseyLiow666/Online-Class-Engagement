<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$summaries = [];
$res = $mysqli->query(
    'SELECT u.id, u.username, u.full_name, COALESCE(ts.total_points, 0) AS total_points
     FROM users u
     LEFT JOIN total_scores ts ON ts.user_id = u.id
     WHERE u.role = \'student\'
     ORDER BY total_points DESC, u.username ASC'
);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $summaries[] = $row;
    }
}

$panelPageTitle = 'Participation summary';
$panelHeading = 'Admin — Participation summary';
$panelRole = 'admin';
$panelActiveNav = 'logs';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout">
        <div class="card">
            <h2>Student participation totals</h2>
            <p class="muted">Cumulative engagement points across all students (assignments and quiz participation).</p>
            <?php if ($summaries === []): ?>
                <p class="muted">No students registered yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Username</th>
                            <th>Total participation points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summaries as $row): ?>
                            <?php
                            $nm = trim((string) ($row['full_name'] ?? ''));
                            if ($nm === '') {
                                $nm = '—';
                            }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><strong><?= (int) $row['total_points'] ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
