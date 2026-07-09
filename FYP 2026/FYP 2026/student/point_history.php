<?php
declare(strict_types=1);

/**
 * Student point history — lists rows from point_logs for the logged-in student.
 * Supports optional GET filters: type (ptype) and date (pdate).
 */

require_once __DIR__ . '/auth.php';

// ---------------------------------------------------------------------------
// Read and validate filter inputs from the GET query string.
// Empty values mean "no filter" for that field.
// ---------------------------------------------------------------------------
$ptype = strtolower(trim((string) ($_GET['ptype'] ?? '')));
$allowedTypes = ['quiz', 'assignment'];
if ($ptype !== '' && !in_array($ptype, $allowedTypes, true)) {
    // Reject unknown type values so they cannot be injected into bind_param.
    $ptype = '';
}

$pdate = trim((string) ($_GET['pdate'] ?? ''));
if ($pdate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdate)) {
    // Ignore malformed dates instead of passing them to SQL.
    $pdate = '';
}

$hasFilters = $ptype !== '' || $pdate !== '';

// ---------------------------------------------------------------------------
// Build the point_logs query dynamically.
//
// Base condition: always scope to the current student (user_id = ?).
// Optional clauses are appended only when a filter is active:
//   - AND source_type = ?   when a type is selected
//   - AND DATE(created_at) = ?   when a date is selected
//
// $types and $params stay in sync for bind_param:
//   first param is always integer user_id ('i'),
//   then zero or more string params ('s') for type and/or date.
// ---------------------------------------------------------------------------
$sql =
    'SELECT points, source_type, source_ref, detail, created_at
     FROM point_logs
     WHERE user_id = ?';

$types = 'i';
$params = [$userId];

if ($ptype !== '') {
    $sql .= ' AND source_type = ?';
    $types .= 's';
    $params[] = $ptype;
}

if ($pdate !== '') {
    $sql .= ' AND DATE(created_at) = ?';
    $types .= 's';
    $params[] = $pdate;
}

$sql .= ' ORDER BY created_at DESC';

$logs = [];
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    // Spread $params so bind_param receives one argument per placeholder.
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
}

/**
 * Format source_type for table display (e.g. "assignment" -> "Assignment").
 */
function point_history_type_label(string $sourceType): string
{
    return ucfirst($sourceType);
}

$studentPageTitle = 'Point history';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point history</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="student-dashboard">
    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>
    <main class="layout">
        <div class="card">
            <h2>Your point activity</h2>
            <p class="muted">Filter by type or date. Each row is one +1 participation award.</p>

            <!-- Filter bar: GET keeps filters in the URL and preserves state on reload -->
            <form method="get" action="point_history.php" class="row filter-bar"
                  style="align-items:flex-end;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem">
                <label class="field" style="min-width:160px">
                    <span>Type</span>
                    <select name="ptype">
                        <option value="" <?= $ptype === '' ? 'selected' : '' ?>>All Types</option>
                        <option value="quiz" <?= $ptype === 'quiz' ? 'selected' : '' ?>>Quiz</option>
                        <option value="assignment" <?= $ptype === 'assignment' ? 'selected' : '' ?>>Assignment</option>
                    </select>
                </label>
                <label class="field" style="min-width:160px">
                    <span>Date</span>
                    <input type="date" name="pdate" value="<?= htmlspecialchars($pdate, ENT_QUOTES, 'UTF-8') ?>">
                </label>
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($hasFilters): ?>
                    <a class="btn btn-ghost" href="point_history.php">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ($logs === []): ?>
                <p class="muted">
                    <?= $hasFilters
                        ? 'No point activity matches your filters.'
                        : 'No activity yet — join a quiz or complete assignments.' ?>
                </p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(point_history_type_label((string) $row['source_type']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['points'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="muted" style="margin-top:0.75rem;font-size:0.85rem">
                    Showing <?= count($logs) ?> record<?= count($logs) === 1 ? '' : 's' ?>.
                </p>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
