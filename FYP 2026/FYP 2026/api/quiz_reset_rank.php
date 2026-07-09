<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/quiz_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$teacherId = (int) $_SESSION['user_id'];
$quizId = (int) ($_POST['quiz_id'] ?? 0);

if ($quizId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad quiz']);
    exit;
}

$result = quiz_reset_rank($mysqli, $quizId, $teacherId);

if (!$result['ok']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Forbidden']);
    exit;
}

echo json_encode([
    'ok' => true,
    'rank_reset_at' => $result['rank_reset_at'],
    'ranks' => [],
]);
