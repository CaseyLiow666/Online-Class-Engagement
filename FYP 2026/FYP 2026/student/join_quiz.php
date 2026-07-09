<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['room_code'] ?? '')));
    if (strlen($code) < 4) {
        $error = 'Enter a valid room code.';
    } else {
        $stmt = $mysqli->prepare('SELECT id FROM quizzes WHERE room_code = ? LIMIT 1');
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            $error = 'No quiz found for that code.';
        } else {
            $_SESSION['live_quiz_id'] = (int) $row['id'];
            header('Location: quiz_play.php');
            exit;
        }
    }
}
$studentPageTitle = 'Join quiz';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join quiz</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="student-dashboard">
    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>
    <main class="layout">
        <div class="card">
            <h2>Enter room code</h2>
            <p class="muted">Your teacher shares this on the host screen.</p>
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="post" class="stack">
                <label class="field">
                    <span>Room code</span>
                    <input type="text" name="room_code" required maxlength="16" autocomplete="off" placeholder="e.g. ABC123" style="text-transform:uppercase">
                </label>
                <button type="submit" class="btn btn-primary">Join</button>
            </form>
        </div>
    </main>
</body>
</html>
