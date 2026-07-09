<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/admin_users_lib.php';

$flashData = admin_users_consume_flash();
$flash = $flashData['message'];
$flashType = $flashData['type'];

if (isset($_GET['deleted']) && (string) $_GET['deleted'] === '1') {
    $flash = 'User deleted successfully.';
    $flashType = 'success';
}

$roleFilter = $_GET['role'] ?? 'all';
if (!in_array($roleFilter, ['all', 'teacher', 'student'], true)) {
    $roleFilter = 'all';
}
$searchTerm = trim((string) ($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $redirectRole = $_POST['list_role'] ?? $roleFilter;
    $redirectSearch = trim((string) ($_POST['list_search'] ?? $searchTerm));

    $role = $_POST['role'] ?? '';
    $uname = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    $full = trim((string) ($_POST['full_name'] ?? ''));

    if (!in_array($role, ['teacher', 'student'], true) || $uname === '' || strlen($pass) < 6) {
        admin_users_set_flash('Invalid input: role must be teacher/student, username required, password min 6 chars.', 'error');
    } elseif (admin_user_identity_exists($mysqli, $uname, $role)) {
        admin_users_set_flash('This user is already registered.', 'error');
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare(
            'INSERT INTO users (username, password_hash, role, full_name) VALUES (?,?,?,?)'
        );
        $stmt->bind_param('ssss', $uname, $hash, $role, $full);
        try {
            $stmt->execute();
            $newId = (int) $mysqli->insert_id;
            $stmt->close();
            $z = $mysqli->prepare('INSERT INTO total_scores (user_id, total_points) VALUES (?, 0)');
            $z->bind_param('i', $newId);
            $z->execute();
            $z->close();
            admin_users_set_flash('User created.', 'success');
        } catch (Throwable $e) {
            admin_users_set_flash('This user is already registered.', 'error');
        }
    }
    header('Location: ' . admin_users_list_url($redirectRole, $redirectSearch));
    exit;
}

$users = admin_users_fetch($mysqli, $roleFilter, $searchTerm);

$panelPageTitle = 'Manage users';
$panelHeading = 'Admin — Users';
$panelRole = 'admin';
$panelActiveNav = 'users';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';

$filterTabs = [
    'all' => 'All',
    'teacher' => 'Teachers',
    'student' => 'Students',
];

$hasActiveCriteria = $searchTerm !== '' || $roleFilter !== 'all';
$listBackQ = [];
if ($roleFilter !== 'all') {
    $listBackQ['role'] = $roleFilter;
}
if ($searchTerm !== '') {
    $listBackQ['search'] = $searchTerm;
}
$listBackQuery = $listBackQ !== [] ? http_build_query($listBackQ) : '';
?>
    <main class="layout">
        <?php if ($flash !== ''): ?>
            <div class="alert <?= $flashType === 'error' ? 'alert-error' : 'alert-success' ?>" role="alert"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card" id="create-user-card">
            <h2>Create teacher or student</h2>
            <form method="post" class="stack">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="list_role" value="<?= htmlspecialchars($roleFilter, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="list_search" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>">
                <div class="row">
                    <label class="field">
                        <span>Role</span>
                        <select name="role" required>
                            <option value="teacher">Teacher</option>
                            <option value="student">Student</option>
                        </select>
                    </label>
                    <label class="field" style="flex:1;min-width:160px">
                        <span>Username</span>
                        <input type="text" name="username" required autocomplete="off">
                    </label>
                    <label class="field" style="flex:1;min-width:160px">
                        <span>Password</span>
                        <input type="password" name="password" required minlength="6" autocomplete="new-password">
                    </label>
                </div>
                <label class="field">
                    <span>Full name (optional)</span>
                    <input type="text" name="full_name">
                </label>
                <button type="submit" class="btn btn-primary">Create account</button>
            </form>
        </div>

        <div class="card">
            <div class="users-list-header">
                <h2>Teachers &amp; students</h2>
                <p class="muted users-list-header__hint">
                    <?php if ($searchTerm !== '' && $roleFilter !== 'all'): ?>
                        Showing <?= htmlspecialchars($filterTabs[$roleFilter], ENT_QUOTES, 'UTF-8') ?> matching “<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>”
                    <?php elseif ($searchTerm !== ''): ?>
                        Showing results for “<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>”
                    <?php elseif ($roleFilter !== 'all'): ?>
                        Showing <?= htmlspecialchars($filterTabs[$roleFilter], ENT_QUOTES, 'UTF-8') ?> only
                    <?php else: ?>
                        Filter by role or search by name and username
                    <?php endif; ?>
                </p>
            </div>

            <div class="users-toolbar">
                <div class="classroom-tabs users-toolbar__filters" role="tablist" aria-label="Filter by role">
                    <?php foreach ($filterTabs as $key => $label): ?>
                        <?php
                        $tabQ = [];
                        if ($key !== 'all') {
                            $tabQ['role'] = $key;
                        }
                        if ($searchTerm !== '') {
                            $tabQ['search'] = $searchTerm;
                        }
                        $tabHref = 'users.php' . ($tabQ !== [] ? '?' . http_build_query($tabQ) : '');
                        ?>
                        <a href="<?= htmlspecialchars($tabHref, ENT_QUOTES, 'UTF-8') ?>"
                           class="classroom-tab <?= $roleFilter === $key ? 'is-active' : '' ?>"
                           role="tab"
                           aria-selected="<?= $roleFilter === $key ? 'true' : 'false' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endforeach; ?>
                </div>

                <form method="get" class="users-search-form" action="users.php" role="search">
                    <?php if ($roleFilter !== 'all'): ?>
                        <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <div class="users-search">
                        <span class="users-search__icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" fill="currentColor"/>
                            </svg>
                        </span>
                        <input type="search"
                               name="search"
                               class="users-search__input"
                               value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Search by name or username…"
                               autocomplete="off"
                               aria-label="Search users by name or username">
                        <?php if ($searchTerm !== ''): ?>
                            <a href="<?= htmlspecialchars(admin_users_list_url($roleFilter, ''), ENT_QUOTES, 'UTF-8') ?>"
                               class="users-search__clear"
                               title="Clear search"
                               aria-label="Clear search">×</a>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary users-search__btn">Search</button>
                </form>
            </div>

            <?php if ($users !== []): ?>
                <p class="muted users-result-count"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?> found</p>
            <?php endif; ?>

            <div class="users-table-wrap">
                <table class="data-table users-table">
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Role</th>
                            <th scope="col">Full name</th>
                            <th scope="col">Joined</th>
                            <th scope="col" class="users-table__actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users === []): ?>
                            <tr class="users-table__empty-row">
                                <td colspan="5">
                                    <?php if ($hasActiveCriteria): ?>
                                        <div class="users-empty-state users-empty-state--in-table" role="status">
                                            <span class="users-empty-state__icon" aria-hidden="true">
                                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" fill="currentColor" opacity="0.35"/>
                                                </svg>
                                            </span>
                                            <p class="users-empty-state__message">No users found matching your criteria.</p>
                                            <p class="users-empty-state__hint">Try a different keyword or role, or <a href="users.php">clear all filters</a>.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="users-empty-state users-empty-state--in-table users-empty-state--plain" role="status">
                                            <p class="users-empty-state__message">No teacher or student accounts yet.</p>
                                            <p class="users-empty-state__hint">Use the form above to create the first account.</p>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                                <?php
                                $uid = (int) $u['id'];
                                $editHref = 'user_edit.php?id=' . $uid . ($listBackQuery !== '' ? '&' . $listBackQuery : '');
                                $deleteHref = 'user_delete.php?id=' . $uid . ($listBackQuery !== '' ? '&' . $listBackQuery : '');
                                $displayName = trim((string) ($u['full_name'] ?? ''));
                                if ($displayName === '') {
                                    $displayName = '—';
                                }
                                $joined = substr((string) $u['created_at'], 0, 10);
                                ?>
                                <tr>
                                    <td>
                                        <strong class="users-table__username"><?= htmlspecialchars((string) $u['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge <?= $u['role'] === 'teacher' ? 'badge-teacher' : 'badge-student' ?>"><?= htmlspecialchars((string) $u['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="users-table__joined"><?= htmlspecialchars($joined, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="users-table__actions">
                                        <a href="<?= htmlspecialchars($editHref, ENT_QUOTES, 'UTF-8') ?>" class="users-action users-action--edit">Edit</a>
                                        <a href="<?= htmlspecialchars($deleteHref, ENT_QUOTES, 'UTF-8') ?>"
                                           class="users-action users-action--delete"
                                           data-delete-user
                                           onclick="return confirm('Are you sure you want to delete this user?');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
