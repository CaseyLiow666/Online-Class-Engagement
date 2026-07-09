<?php
declare(strict_types=1);

/** Check whether username + role is already taken (optionally excluding one user id). */
function admin_user_identity_exists(mysqli $mysqli, string $username, string $role, int $excludeId = 0): bool
{
    if ($excludeId > 0) {
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? AND role = ? AND id != ? LIMIT 1');
        $stmt->bind_param('ssi', $username, $role, $excludeId);
    } else {
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? AND role = ? LIMIT 1');
        $stmt->bind_param('ss', $username, $role);
    }
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

/** @return non-empty-string */
function admin_users_list_url(string $role, string $search, array $extra = []): string
{
    $role = in_array($role, ['all', 'teacher', 'student'], true) ? $role : 'all';
    $search = trim($search);
    $q = $extra;
    if ($role !== 'all') {
        $q['role'] = $role;
    }
    if ($search !== '') {
        $q['search'] = $search;
    }
    return 'users.php' . ($q !== [] ? '?' . http_build_query($q) : '');
}

/**
 * @return list<array<string, mixed>>
 */
function admin_users_fetch(mysqli $mysqli, string $roleFilter, string $searchTerm): array
{
    $users = [];
    $query = 'SELECT id, username, role, full_name, created_at FROM users WHERE role IN (\'teacher\', \'student\')';
    $params = [];
    $types = '';

    if ($roleFilter !== 'all') {
        $query .= ' AND role = ?';
        $params[] = $roleFilter;
        $types .= 's';
    }

    if ($searchTerm !== '') {
        $like = '%' . $searchTerm . '%';
        $query .= ' AND (username LIKE ? OR full_name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }

    $query .= ' ORDER BY role ASC, username ASC';

    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        return [];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    return $users;
}

function admin_users_consume_flash(): array
{
    $flash = $_SESSION['flash'] ?? '';
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
    if (!in_array($flashType, ['success', 'error'], true)) {
        $flashType = 'success';
    }
    return ['message' => $flash, 'type' => $flashType];
}

function admin_users_set_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = $type;
}
