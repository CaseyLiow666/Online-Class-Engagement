<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = preg_replace('/\D/', '', (string) ($_POST['join_code'] ?? ''));
    if (strlen($raw) !== 6) {
        $error = 'Enter the 6-digit join code.';
    } else {
        $stmt = $mysqli->prepare('SELECT id, name FROM classrooms WHERE join_code = ? AND status = 1 LIMIT 1');
        $stmt->bind_param('s', $raw);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            $error = 'No open class found with that code.';
        } else {
            $cid = (int) $row['id'];
            $ins = $mysqli->prepare(
                'INSERT IGNORE INTO classroom_members (classroom_id, student_id) VALUES (?,?)'
            );
            $ins->bind_param('ii', $cid, $userId);
            $ins->execute();
            $ins->close();
            $_SESSION['flash'] = 'You joined ' . (string) $row['name'] . '.';
            header('Location: classroom.php?id=' . $cid);
            exit;
        }
    }
}

$sf = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$studentPageTitle = 'Join a class';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join class</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="student-dashboard">
    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>
    <main class="layout">
        <?php if ($sf !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($sf, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="card">
            <h2>Enter join code</h2>
            <p class="muted">Your teacher shares a 6-digit code for this class.</p>
            <form method="post" class="stack">
                <label class="field">
                    <span>6-digit code</span>
                    <input type="text" name="join_code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="off" placeholder="000000">
                </label>
                <button type="submit" class="btn btn-primary">Join class</button>
            </form>
        </div>
    </main>
</body>
</html>
