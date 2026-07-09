<?php
declare(strict_types=1);

require_once __DIR__ . '/panel_layout.php';

$panelPageTitle = $panelPageTitle ?? 'Panel';
$panelRole = $panelRole ?? 'teacher';
$assetPrefix = $assetPrefix ?? '..';

$panelBodyClass = ($panelRole === 'admin' ? 'admin-app' : 'teacher-app') . ' panel-flat';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= panel_h($panelPageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= panel_h($assetPrefix) ?>/style.css">
    <link rel="stylesheet" href="<?= panel_h($assetPrefix) ?>/assets/css/admin_teacher_flat.css">
</head>
<body class="<?= panel_h($panelBodyClass) ?>">
