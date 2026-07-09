<?php
declare(strict_types=1);

/**
 * Unified Admin / Teacher panel chrome.
 *
 * Set before including panel_head.php + panel_topbar.php:
 *   $panelPageTitle  — <title>
 *   $panelHeading    — topbar h1 (defaults to page title)
 *   $panelRole       — 'admin' | 'teacher'
 *   $panelActiveNav  — nav highlight key (optional)
 *   $assetPrefix     — path to project root from current folder (default '..')
 */

function panel_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @return array<string, array{href: string, label: string}>
 */
function panel_nav_items(string $role): array
{
    if ($role === 'admin') {
        return [
            'dashboard' => ['href' => 'dashboard.php', 'label' => 'Dashboard'],
            'reports'   => ['href' => 'reports.php', 'label' => 'Reports'],
            'users'     => ['href' => 'users.php', 'label' => 'Users'],
            'logs'      => ['href' => 'view_logs.php', 'label' => 'Participation summary'],
        ];
    }

    // 注意：教师导航不再包含全局「Reports」。报表已迁移到每个班级内部
    //       （classroom.php 的 Reports 标签页），教师只能在具体班级中查看报表。
    return [
        'home'         => ['href' => 'dashboard.php', 'label' => 'Home'],
        'new_class'    => ['href' => 'create_classroom.php', 'label' => 'New class'],
        'quizzes'      => ['href' => 'create_quiz.php', 'label' => 'Quizzes'],
        'assignments'  => ['href' => 'manage_assignments.php', 'label' => 'Assignments'],
    ];
}
