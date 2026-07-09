<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/admin_users_lib.php';

$userId = (int) ($_GET['id'] ?? 0);
if ($userId < 1) {
    header('Location: users.php');
    exit;
}

$listRole = $_GET['role'] ?? 'all';
if (!in_array($listRole, ['all', 'teacher', 'student'], true)) {
    $listRole = 'all';
}
$listSearch = trim((string) ($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $uname = trim((string) ($_POST['username'] ?? ''));
    $full = trim((string) ($_POST['full_name'] ?? ''));
    $role = $_POST['role'] ?? '';
    $pass = (string) ($_POST['password'] ?? '');
    $redirectRole = $_POST['list_role'] ?? $listRole;
    $redirectSearch = trim((string) ($_POST['list_search'] ?? $listSearch));

    if ($id < 1 || $id !== $userId || $uname === '' || !in_array($role, ['teacher', 'student'], true)) {
        admin_users_set_flash('Invalid update.', 'error');
    } elseif (admin_user_identity_exists($mysqli, $uname, $role, $id)) {
        admin_users_set_flash('This user is already registered.', 'error');
    } elseif ($pass !== '' && strlen($pass) < 6) {
        admin_users_set_flash('Password must be at least 6 characters.', 'error');
    } else {
        if ($pass !== '') {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare(
                'UPDATE users SET username = ?, full_name = ?, role = ?, password_hash = ? WHERE id = ? AND role IN (\'teacher\',\'student\')'
            );
            $stmt->bind_param('ssssi', $uname, $full, $role, $hash, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare(
                'UPDATE users SET username = ?, full_name = ?, role = ? WHERE id = ? AND role IN (\'teacher\',\'student\')'
            );
            $stmt->bind_param('sssi', $uname, $full, $role, $id);
            $stmt->execute();
            $stmt->close();
        }
        admin_users_set_flash('User updated.', 'success');
        header('Location: ' . admin_users_list_url($redirectRole, $redirectSearch));
        exit;
    }

    header('Location: user_edit.php?' . http_build_query(array_filter([
        'id' => $userId,
        'role' => $redirectRole !== 'all' ? $redirectRole : null,
        'search' => $redirectSearch !== '' ? $redirectSearch : null,
    ], static fn ($v) => $v !== null && $v !== '')));
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT id, username, role, full_name, created_at FROM users WHERE id = ? AND role IN (\'teacher\', \'student\') LIMIT 1'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    admin_users_set_flash('User not found.', 'error');
    header('Location: ' . admin_users_list_url($listRole, $listSearch));
    exit;
}

$flashData = admin_users_consume_flash();
$flash = $flashData['message'];
$flashType = $flashData['type'];

$panelPageTitle = 'Edit user';
$panelHeading = 'Edit user';
$panelRole = 'admin';
$panelActiveNav = 'users';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout">
        <?php if ($flash !== ''): ?>
            <div class="alert <?= $flashType === 'error' ? 'alert-error' : 'alert-success' ?>" role="alert"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <p class="users-edit-back">
            <a href="<?= htmlspecialchars(admin_users_list_url($listRole, $listSearch), ENT_QUOTES, 'UTF-8') ?>">← Back to users</a>
        </p>

        <div class="card">
            <h2>Edit #<?= (int) $user['id'] ?> — <?= htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="muted">Joined <?= htmlspecialchars((string) $user['created_at'], ENT_QUOTES, 'UTF-8') ?></p>
            <form method="post" class="stack">
                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                <input type="hidden" name="list_role" value="<?= htmlspecialchars($listRole, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="list_search" value="<?= htmlspecialchars($listSearch, ENT_QUOTES, 'UTF-8') ?>">
                <div class="row">
                    <label class="field" style="flex:1;min-width:160px">
                        <span>Username</span>
                        <input type="text" name="username" value="<?= htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="off">
                    </label>
                    <label class="field">
                        <span>Role</span>
                        <select name="role">
                            <option value="teacher" <?= $user['role'] === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                            <option value="student" <?= $user['role'] === 'student' ? 'selected' : '' ?>>Student</option>
                        </select>
                    </label>
                </div>
                <label class="field">
                    <span>Full name</span>
                    <input type="text" name="full_name" value="<?= htmlspecialchars((string) ($user['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </label>
                <label class="field">
                    <span>New password (leave blank to keep current)</span>
                    <input type="password" name="password" autocomplete="new-password" placeholder="Optional">
                </label>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save changes</button>
                    <a href="<?= htmlspecialchars(admin_users_list_url($listRole, $listSearch), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </main>
    <?php if ($flash !== '' && $flashType === 'error'): ?>
    <script>
    (function () {
        var msg = <?= json_encode($flash, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        if (msg) {
            window.alert(msg);
        }
    })();
    </script>
    <?php endif; ?>
</body>
</html>
