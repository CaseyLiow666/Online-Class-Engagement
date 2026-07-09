<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$studentId = (int) $_SESSION['user_id'];

$quizId = (int) ($_GET['quiz_id'] ?? 0);
if ($quizId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad quiz']);
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT id, title, status, current_question, reveal_question_id, reveal_ends_at FROM quizzes WHERE id = ? LIMIT 1'
);
$stmt->bind_param('i', $quizId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT id FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order ASC'
);
$stmt->bind_param('i', $quizId);
$stmt->execute();
$res = $stmt->get_result();
$orderedIds = [];
while ($row = $res->fetch_assoc()) {
    $orderedIds[] = (int) $row['id'];
}
$stmt->close();

$total = count($orderedIds);
$idx = (int) $quiz['current_question'];

$payload = [
    'ok' => true,
    'status' => $quiz['status'],
    'current_index' => $idx,
    'total_questions' => $total,
    'title' => $quiz['title'],
    'question' => null,
];

/* Always return the active question while live (even during teacher reveal window). */
if ($quiz['status'] === 'live') {
    $activeId = ($idx >= 0 && $idx < $total) ? $orderedIds[$idx] : null;

    if ($activeId !== null) {
        $q = $mysqli->prepare(
            'SELECT id, question_text, opt_a, opt_b, opt_c, opt_d, points FROM quiz_questions WHERE id = ? LIMIT 1'
        );
        $q->bind_param('i', $activeId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if ($row) {
            $payload['question'] = [
                'id' => (int) $row['id'],
                'text' => $row['question_text'],
                'options' => [
                    'a' => $row['opt_a'],
                    'b' => $row['opt_b'],
                    'c' => $row['opt_c'],
                    'd' => $row['opt_d'],
                ],
                'points' => (int) $row['points'],
            ];
        }
    }
}

echo json_encode($payload);
