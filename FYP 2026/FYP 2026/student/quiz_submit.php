<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/award_points.php';
require_once dirname(__DIR__) . '/includes/quiz_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$studentId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method']);
    exit;
}

$quizId = (int) ($_POST['quiz_id'] ?? 0);
$questionId = (int) ($_POST['question_id'] ?? 0);
$chosen = strtolower(trim((string) ($_POST['chosen'] ?? '')));

if ($quizId < 1 || $questionId < 1 || !in_array($chosen, ['a', 'b', 'c', 'd'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT id, status, current_question FROM quizzes WHERE id = ? LIMIT 1'
);
$stmt->bind_param('i', $quizId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz || $quiz['status'] !== 'live') {
    echo json_encode(['ok' => false, 'error' => 'Quiz is not live']);
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
$expectedId = ($idx >= 0 && $idx < $total) ? $orderedIds[$idx] : null;

if ($expectedId === null || $expectedId !== $questionId) {
    echo json_encode(['ok' => false, 'error' => 'That question is not active']);
    exit;
}

$chk = $mysqli->prepare(
    'SELECT id FROM quiz_answers WHERE student_id = ? AND question_id = ? LIMIT 1'
);
$chk->bind_param('ii', $studentId, $questionId);
$chk->execute();
if ($chk->get_result()->fetch_assoc()) {
    $chk->close();
    echo json_encode(['ok' => false, 'error' => 'Already answered']);
    exit;
}
$chk->close();

$q = $mysqli->prepare(
    'SELECT correct, points, opt_a, opt_b, opt_c, opt_d FROM quiz_questions WHERE id = ? AND quiz_id = ? LIMIT 1'
);
$q->bind_param('ii', $questionId, $quizId);
$q->execute();
$qrow = $q->get_result()->fetch_assoc();
$q->close();

if (!$qrow) {
    echo json_encode(['ok' => false, 'error' => 'Question missing']);
    exit;
}

$correct = strtolower((string) $qrow['correct']);
$maxPts = (int) $qrow['points'];
$isCorrect = ($chosen === $correct) ? 1 : 0;
$earned = $isCorrect ? $maxPts : 0;

$optKey = 'opt_' . $correct;
$correctText = isset($qrow[$optKey]) ? (string) $qrow[$optKey] : '';

$grade = ['score' => 0.0, 'max_score' => 0.0];

$mysqli->begin_transaction();
try {
    $lock = $mysqli->prepare(
        'SELECT id FROM quiz_answers WHERE student_id = ? AND question_id = ? LIMIT 1 FOR UPDATE'
    );
    $lock->bind_param('ii', $studentId, $questionId);
    $lock->execute();
    if ($lock->get_result()->fetch_assoc()) {
        $lock->close();
        $mysqli->rollback();
        echo json_encode(['ok' => false, 'error' => 'Already answered']);
        exit;
    }
    $lock->close();

    $ins = $mysqli->prepare(
        'INSERT INTO quiz_answers (student_id, quiz_id, question_id, chosen, is_correct, points_earned) VALUES (?,?,?,?,?,?)'
    );
    $ins->bind_param('iiisii', $studentId, $quizId, $questionId, $chosen, $isCorrect, $earned);
    $ins->execute();
    $ins->close();

    // +1 engagement point for quiz participation (once per quiz; academic score stays in quiz_answers).
    $qt = $mysqli->prepare('SELECT title FROM quizzes WHERE id = ? LIMIT 1');
    $qt->bind_param('i', $quizId);
    $qt->execute();
    $trow = $qt->get_result()->fetch_assoc();
    $qt->close();
    $title = $trow ? (string) $trow['title'] : 'Quiz';
    award_points($mysqli, $studentId, 1, 'quiz', (string) $quizId, $title . ': +1pt participation');

    $grade = quiz_sync_academic_grade($mysqli, $studentId, $quizId);

    $mysqli->commit();
} catch (mysqli_sql_exception $e) {
    $mysqli->rollback();
    if ($e->getCode() === 1062) {
        echo json_encode(['ok' => false, 'error' => 'Already answered']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save']);
    exit;
} catch (Throwable $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save']);
    exit;
}

echo json_encode([
    'ok' => true,
    'correct' => (bool) $isCorrect,
    'points_earned' => $earned,
    'question_max_points' => $maxPts,
    'academic_score' => $grade['score'],
    'academic_max' => $grade['max_score'],
    'correct_answer' => $correctText,
    'correct_letter' => $correct,
]);
