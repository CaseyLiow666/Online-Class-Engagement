<?php
declare(strict_types=1);

/**
 * Student global top navigation — include only from student pages after auth.php.
 *
 * Optional variables:
 *   $studentPageTitle   — <h1> text (default: Student)
 *   $studentClassroomId — if > 0, show "This class" instead of "Join class"
 *   $studentNavMinimal  — if true, show only Home (+ class link) and Logout
 */

$studentPageTitle = $studentPageTitle ?? 'Student';
$studentClassroomId = (int) ($studentClassroomId ?? 0);
$studentNavMinimal = (bool) ($studentNavMinimal ?? false);
?>
<header class="topbar topbar--dashboard">
    <h1><?= htmlspecialchars((string) $studentPageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <nav>
        <a href="index.php">Home</a>
        <?php if ($studentClassroomId > 0): ?>
            <a href="classroom.php?id=<?= $studentClassroomId ?>">This class</a>
        <?php else: ?>
            <a href="join_classroom.php">Join class</a>
        <?php endif; ?>
        <?php if (!$studentNavMinimal): ?>
            <a href="join_quiz.php">Live quiz</a>
            <a href="assignments.php">Assignments</a>
            <a href="point_history.php">Point history</a>
            <a href="my_report.php">My Report</a>
            <a href="my_grades.php">My Grades</a>
        <?php endif; ?>
        <span class="user-pill"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
        <a href="../logout.php" class="btn btn-ghost btn-sm">Logout</a>
    </nav>
</header>
