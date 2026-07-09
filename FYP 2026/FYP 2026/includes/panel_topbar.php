<?php
declare(strict_types=1);

require_once __DIR__ . '/panel_layout.php';

$panelHeading = $panelHeading ?? ($panelPageTitle ?? 'Panel');
$panelRole = $panelRole ?? 'teacher';
$panelActiveNav = $panelActiveNav ?? '';
$assetPrefix = $assetPrefix ?? '..';

$topbarMod = $panelRole === 'admin' ? 'topbar--admin' : 'topbar--teacher';
$navItems = panel_nav_items($panelRole);
?>
    <header class="topbar <?= panel_h($topbarMod) ?>">
        <h1><?= panel_h($panelHeading) ?></h1>
        <nav>
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?= panel_h($item['href']) ?>" class="<?= $key === $panelActiveNav ? 'is-active' : '' ?>"><?= panel_h($item['label']) ?></a>
            <?php endforeach; ?>
            <span class="user-pill"><?= panel_h($username ?? '') ?></span>
            <a href="<?= panel_h($assetPrefix) ?>/logout.php" class="btn btn-ghost btn-sm">Logout</a>
        </nav>
    </header>
