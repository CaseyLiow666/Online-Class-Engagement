<?php
declare(strict_types=1);

session_start();

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') {
        header('Location: admin/dashboard.php');
        exit;
    }
    if ($role === 'teacher') {
        header('Location: teacher/dashboard.php');
        exit;
    }
    if ($role === 'student') {
        header('Location: student/index.php');
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/db.php';

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Enter username and password.';
    } else {
        $stmt = $mysqli->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];

            if ($row['role'] === 'admin') {
                header('Location: admin/dashboard.php');
                exit;
            }
            if ($row['role'] === 'teacher') {
                header('Location: teacher/dashboard.php');
                exit;
            }
            header('Location: student/index.php');
            exit;
        }
        $error = 'Invalid credentials.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Classroom Engagement</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: url('geraltboard.jpg') !important;
            background-size: cover !important;
            background-position: center !important;
            background-attachment: fixed !important;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body class="auth-body">
    <div class="auth-card card login-card">
        <h1 class="login-welcome-title">Welcome to LLL Classroom</h1>
        <p class="login-subtitle">Your all-in-one learning and engagement platform</p>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" action="login.php" class="stack">
            <label class="field">
                <span>Username</span>
                <input type="text" name="username" required autocomplete="username" autofocus>
            </label>
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
        </form>
    </div>
</body>
</html>
