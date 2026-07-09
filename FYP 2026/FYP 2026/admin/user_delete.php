<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$userId = (int) ($_GET['id'] ?? 0);
$listRole = $_GET['role'] ?? 'all';
if (!in_array($listRole, ['all', 'teacher', 'student'], true)) {
    $listRole = 'all';
}
$listSearch = trim((string) ($_GET['search'] ?? ''));

if ($userId < 1) {
    header('Location: users.php');
    exit;
}

$stmt = $mysqli->prepare('DELETE FROM users WHERE id = ? AND role IN (\'teacher\', \'student\')');
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->close();

$redirect = 'users.php?deleted=1';
if ($listRole !== 'all') {
    $redirect .= '&role=' . rawurlencode($listRole);
}
if ($listSearch !== '') {
    $redirect .= '&search=' . rawurlencode($listSearch);
}

header('Location: ' . $redirect);
exit;
