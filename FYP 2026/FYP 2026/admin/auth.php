<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$username = (string) ($_SESSION['username'] ?? '');
