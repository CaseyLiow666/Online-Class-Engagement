<?php

declare(strict_types=1);



require_once __DIR__ . '/auth.php';



$counts = [

    'students' => 0,

    'classrooms' => 0,

    'quizzes' => 0,

    'point_events' => 0,

];



$r = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role = 'student'");

if ($row = $r->fetch_assoc()) {

    $counts['students'] = (int) $row['c'];

}

$r = $mysqli->query('SELECT COUNT(*) AS c FROM classrooms');

if ($row = $r->fetch_assoc()) {

    $counts['classrooms'] = (int) $row['c'];

}

$r = $mysqli->query('SELECT COUNT(*) AS c FROM quizzes');

if ($row = $r->fetch_assoc()) {

    $counts['quizzes'] = (int) $row['c'];

}

$r = $mysqli->query('SELECT COUNT(*) AS c FROM point_logs');

if ($row = $r->fetch_assoc()) {

    $counts['point_events'] = (int) $row['c'];

}



$panelPageTitle = 'Admin Dashboard';

$panelHeading = 'Admin — Dashboard';

$panelRole = 'admin';

$panelActiveNav = 'dashboard';

$assetPrefix = '..';

require dirname(__DIR__) . '/includes/panel_head.php';

require dirname(__DIR__) . '/includes/panel_topbar.php';

?>

    <main class="layout-wide">

        <div class="metric-grid metric-grid--4">

            <div class="metric-card metric-card--blue">

                <div class="metric-card__accent" aria-hidden="true"></div>

                <div class="metric-card__content">

                    <span class="metric-card__icon" aria-hidden="true">👥</span>

                    <span class="metric-card__label">Total students</span>

                    <span class="metric-card__value"><?= $counts['students'] ?></span>

                </div>

            </div>

            <div class="metric-card metric-card--green">

                <div class="metric-card__accent" aria-hidden="true"></div>

                <div class="metric-card__content">

                    <span class="metric-card__icon" aria-hidden="true">🏫</span>

                    <span class="metric-card__label">Active classes</span>

                    <span class="metric-card__value"><?= $counts['classrooms'] ?></span>

                </div>

            </div>

            <div class="metric-card metric-card--amber">

                <div class="metric-card__accent" aria-hidden="true"></div>

                <div class="metric-card__content">

                    <span class="metric-card__icon" aria-hidden="true">📝</span>

                    <span class="metric-card__label">Quizzes</span>

                    <span class="metric-card__value"><?= $counts['quizzes'] ?></span>

                </div>

            </div>

            <div class="metric-card metric-card--purple">

                <div class="metric-card__accent" aria-hidden="true"></div>

                <div class="metric-card__content">

                    <span class="metric-card__icon" aria-hidden="true">⭐</span>

                    <span class="metric-card__label">Point logs</span>

                    <span class="metric-card__value"><?= $counts['point_events'] ?></span>

                </div>

            </div>

        </div>



        <p class="no-print" style="margin-bottom:1.25rem">
            <a href="reports.php" class="btn btn-primary">Open reports hub</a>
            <a href="view_logs.php" class="btn btn-ghost">Participation summary</a>
        </p>

    </main>

</body>

</html>

