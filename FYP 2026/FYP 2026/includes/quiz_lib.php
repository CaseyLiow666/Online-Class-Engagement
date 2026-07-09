<?php
declare(strict_types=1);

/**
 * Recalculate and persist a student's academic quiz grade from answered questions.
 *
 * @return array{score: float, max_score: float}
 */
function quiz_sync_academic_grade(mysqli $mysqli, int $studentId, int $quizId): array
{
    $score = 0.0;
    $maxScore = 0.0;

    $s = $mysqli->prepare(
        'SELECT COALESCE(SUM(qa.points_earned), 0) AS score
         FROM quiz_answers qa
         WHERE qa.student_id = ? AND qa.quiz_id = ?'
    );
    $s->bind_param('ii', $studentId, $quizId);
    $s->execute();
    $srow = $s->get_result()->fetch_assoc();
    $s->close();
    if ($srow) {
        $score = (float) $srow['score'];
    }

    $m = $mysqli->prepare(
        'SELECT COALESCE(SUM(points), 0) AS max_score FROM quiz_questions WHERE quiz_id = ?'
    );
    $m->bind_param('i', $quizId);
    $m->execute();
    $mrow = $m->get_result()->fetch_assoc();
    $m->close();
    if ($mrow) {
        $maxScore = (float) $mrow['max_score'];
    }

    $upsert = $mysqli->prepare(
        'INSERT INTO quiz_completions (student_id, quiz_id, score, max_score) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE score = VALUES(score), max_score = VALUES(max_score), updated_at = CURRENT_TIMESTAMP'
    );
    $upsert->bind_param('iidd', $studentId, $quizId, $score, $maxScore);
    $upsert->execute();
    $upsert->close();

    return ['score' => $score, 'max_score' => $maxScore];
}

/** True when the teacher owns the quiz. */
function quiz_teacher_owns(mysqli $mysqli, int $quizId, int $teacherId): bool
{
    $st = $mysqli->prepare('SELECT id FROM quizzes WHERE id = ? AND teacher_id = ? LIMIT 1');
    $st->bind_param('ii', $quizId, $teacherId);
    $st->execute();
    $ok = (bool) $st->get_result()->fetch_assoc();
    $st->close();

    return $ok;
}

/** System notification when a live quiz session starts. */
function quiz_live_notification_body(string $title, string $roomCode): string
{
    return 'Live Quiz \'' . $title . '\' has started! Join now using Room Code: ' . $roomCode;
}

/** System notification when a live quiz session ends. */
function quiz_ended_notification_body(string $title): string
{
    return 'Live Quiz \'' . $title . '\' has ended.';
}

/** Post end-of-quiz notification to the linked classroom (if any). */
function quiz_notify_ended(mysqli $mysqli, int $quizId, int $teacherId): void
{
    require_once __DIR__ . '/channel_lib.php';

    $st = $mysqli->prepare(
        'SELECT classroom_id, title FROM quizzes WHERE id = ? AND teacher_id = ? LIMIT 1'
    );
    $st->bind_param('ii', $quizId, $teacherId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        return;
    }

    $cid = (int) ($row['classroom_id'] ?? 0);
    if ($cid < 1) {
        return;
    }

    classroom_post_notification(
        $mysqli,
        $cid,
        $teacherId,
        quiz_ended_notification_body((string) $row['title'])
    );
}

/**
 * Live leaderboard rows for a quiz session.
 *
 * @return list<array{rank: int, student_id: int, display: string, points: float, correct_count: int, answered_count: int}>
 */
function quiz_fetch_leaderboard(mysqli $mysqli, int $quizId, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $sql =
        'SELECT u.id AS student_id, u.username, u.full_name,
                COALESCE(SUM(qa.points_earned), 0) AS points,
                COALESCE(SUM(qa.is_correct), 0) AS correct_count,
                COUNT(qa.id) AS answered_count
         FROM quiz_answers qa
         INNER JOIN users u ON u.id = qa.student_id
         WHERE qa.quiz_id = ?
         GROUP BY u.id, u.username, u.full_name
         ORDER BY points DESC, correct_count DESC, answered_count DESC, u.username ASC
         LIMIT ' . $limit;

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $quizId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    $rank = 1;
    while ($row = $res->fetch_assoc()) {
        $fn = trim((string) ($row['full_name'] ?? ''));
        $rows[] = [
            'rank' => $rank++,
            'student_id' => (int) $row['student_id'],
            'display' => $fn !== '' ? $fn : (string) $row['username'],
            'points' => (float) $row['points'],
            'correct_count' => (int) $row['correct_count'],
            'answered_count' => (int) $row['answered_count'],
        ];
    }
    $stmt->close();

    return $rows;
}

/**
 * Clear all answer/score data for a quiz so the live rank can restart.
 *
 * @return array{ok: true, rank_reset_at: string}|array{ok: false, error: string}
 */
function quiz_reset_rank(mysqli $mysqli, int $quizId, int $teacherId): array
{
    if (!quiz_teacher_owns($mysqli, $quizId, $teacherId)) {
        return ['ok' => false, 'error' => 'Forbidden'];
    }

    $mysqli->begin_transaction();
    try {
        $delAnswers = $mysqli->prepare('DELETE FROM quiz_answers WHERE quiz_id = ?');
        $delAnswers->bind_param('i', $quizId);
        $delAnswers->execute();
        $delAnswers->close();

        $delCompletions = $mysqli->prepare('DELETE FROM quiz_completions WHERE quiz_id = ?');
        $delCompletions->bind_param('i', $quizId);
        $delCompletions->execute();
        $delCompletions->close();

        $upd = $mysqli->prepare(
            'UPDATE quizzes SET rank_reset_at = CURRENT_TIMESTAMP WHERE id = ? AND teacher_id = ?'
        );
        $upd->bind_param('ii', $quizId, $teacherId);
        $upd->execute();
        $upd->close();

        $ts = $mysqli->prepare('SELECT rank_reset_at FROM quizzes WHERE id = ? LIMIT 1');
        $ts->bind_param('i', $quizId);
        $ts->execute();
        $trow = $ts->get_result()->fetch_assoc();
        $ts->close();

        $mysqli->commit();

        return [
            'ok' => true,
            'rank_reset_at' => $trow ? (string) ($trow['rank_reset_at'] ?? '') : '',
        ];
    } catch (Throwable $e) {
        $mysqli->rollback();
        return ['ok' => false, 'error' => 'Could not reset rank'];
    }
}
