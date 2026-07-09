<?php
/**
 * ============================================================================
 * 文件：teacher/open_classroom.php（教师 - 重新开放班级处理器）
 * ----------------------------------------------------------------------------
 * 作用：与 close_classroom.php 相对应。把一个已关闭（status = 0）的班级
 *       重新设置为开放（status = 1），让学生可以再次访问与提交作业。
 * ============================================================================
 */

declare(strict_types=1); // 严格类型模式

require_once __DIR__ . '/auth.php';                            // 教师身份验证
require_once dirname(__DIR__) . '/includes/classroom_lib.php'; // 班级归属校验

// 只允许 POST 提交
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$classroomId = (int) ($_POST['classroom_id'] ?? 0); // 读取班级 ID

// 【权限校验】班级必须属于当前教师
if ($classroomId < 1 || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
    $_SESSION['flash'] = 'Class not found.';
    header('Location: dashboard.php');
    exit;
}

// 【UPDATE】把状态设回 1（开放）
$upd = $mysqli->prepare('UPDATE classrooms SET status = 1 WHERE id = ? AND teacher_id = ?');
$upd->bind_param('ii', $classroomId, $userId);
$upd->execute();
$upd->close();

$_SESSION['flash'] = 'Class re-opened. Students can access it again.'; // 成功提示
header('Location: classroom.php?id=' . $classroomId);
exit;
