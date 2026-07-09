<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';
require_once dirname(__DIR__) . '/includes/channel_lib.php';

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $desc = trim((string) ($_POST['description'] ?? ''));
    if ($name === '') {
        $_SESSION['flash'] = 'Class name is required.';
    } else {
        try {
            $code = classroom_generate_join_code($mysqli);
            $stmt = $mysqli->prepare(
                'INSERT INTO classrooms (teacher_id, name, description, join_code) VALUES (?,?,?,?)'
            );
            $stmt->bind_param('isss', $userId, $name, $desc, $code);
            $stmt->execute();
            $newId = (int) $mysqli->insert_id;
            $stmt->close();
            classroom_ensure_general_channel($mysqli, $newId);
            $_SESSION['flash'] = 'Class created. Join code: ' . $code;
            header('Location: classroom.php?id=' . $newId);
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Could not create class. Try again.';
        }
    }
    header('Location: create_classroom.php');
    exit;
}
$panelPageTitle = 'Create class';
$panelHeading = 'Create classroom';
$panelRole = 'teacher';
$panelActiveNav = 'new_class';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout">
        <?php if ($flash !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="card">
            <h2>New class</h2>
            <p class="muted">A unique 6-digit join code is generated automatically. Share it with students so they can join from their dashboard.</p>
            <form method="post" class="stack">
                <label class="field">
                    <span>Class name</span>
                    <input type="text" name="name" required maxlength="255" placeholder="e.g. Web Programming">
                </label>
                <label class="field">
                    <span>Description (optional)</span>
                    <textarea name="description" placeholder="Syllabus or welcome note"></textarea>
                </label>
                <button type="submit" class="btn btn-primary">Create class</button>
            </form>
        </div>
    </main>
</body>
</html>
