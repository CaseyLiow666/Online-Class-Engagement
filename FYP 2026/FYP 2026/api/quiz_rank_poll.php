<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/quiz_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$role = (string) ($_SESSION['role'] ?? '');
$uid = (int) $_SESSION['user_id'];
$quizId = (int) ($_GET['quiz_id'] ?? 0);
$limit = (int) ($_GET['limit'] ?? 10);

if ($quizId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad quiz']);
    exit;
}

if ($role === 'teacher') {
    if (!quiz_teacher_owns($mysqli, $quizId, $uid)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
} elseif ($role !== 'student') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$chk = $mysqli->prepare('SELECT id, rank_reset_at FROM quizzes WHERE id = ? LIMIT 1');
$chk->bind_param('i', $quizId);
$chk->execute();
$quiz = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$quiz) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

$ranks = quiz_fetch_leaderboard($mysqli, $quizId, $limit);

echo json_encode([
    'ok' => true,
    'ranks' => $ranks,
    'rank_reset_at' => $quiz['rank_reset_at'] ?? null,
    'viewer_id' => $uid,
]);
