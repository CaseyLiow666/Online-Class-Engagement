<?php
declare(strict_types=1);

/**
 * MySQLi connection — edit credentials for your environment.
 */
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'classroom_engagement';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
$mysqli->set_charset('utf8mb4');
