<?php
/**
 * ============================================================================
 * 文件：teacher/update_class.php（教师 - 修改班级名称处理器）
 * ----------------------------------------------------------------------------
 * 作用：接收 teacher/classroom.php 中「编辑班级名称」表单的 POST 提交，
 *       校验权限与非空后，更新 classrooms.name 字段。
 * ============================================================================
 */

declare(strict_types=1); // 严格类型模式

require_once __DIR__ . '/auth.php';                            // 教师身份验证（提供 $mysqli、$userId）
require_once dirname(__DIR__) . '/includes/classroom_lib.php'; // 班级归属校验函数

// 只允许 POST 提交；非 POST 直接回到仪表盘
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$classroomId = (int) ($_POST['classroom_id'] ?? 0); // 读取班级 ID（强制整数）
$newName = trim((string) ($_POST['class_name'] ?? '')); // 读取新名称并去除首尾空格

// 【权限校验】班级必须存在且属于当前登录教师，否则拒绝
if ($classroomId < 1 || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
    $_SESSION['flash'] = 'Class not found.';
    header('Location: dashboard.php');
    exit;
}

// 【非空校验】班级名称不能为空白
if ($newName === '') {
    $_SESSION['flash'] = 'Class name cannot be empty.';
    header('Location: classroom.php?id=' . $classroomId);
    exit;
}

// 【长度保护】数据库字段为 VARCHAR(255)，超长则截断，避免写入失败
if (mb_strlen($newName) > 255) {
    $newName = mb_substr($newName, 0, 255);
}

// 【UPDATE 预处理语句】仅更新「属于本教师」的该班级名称，防止越权修改
$upd = $mysqli->prepare('UPDATE classrooms SET name = ? WHERE id = ? AND teacher_id = ?');
$upd->bind_param('sii', $newName, $classroomId, $userId); // 's'=名称, 'i'=班级ID, 'i'=教师ID
$upd->execute();
$upd->close();

$_SESSION['flash'] = 'Class name updated.'; // 成功提示
header('Location: classroom.php?id=' . $classroomId); // 跳回该班级页面
exit;
