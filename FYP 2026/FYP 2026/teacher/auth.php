<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$username = (string) ($_SESSION['username'] ?? '');
