<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/quiz_lib.php';
require_once dirname(__DIR__) . '/includes/channel_lib.php';

$quizId = (int) ($_GET['id'] ?? 0);
if ($quizId < 1) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT id, title, room_code, status, current_question, reveal_question_id, reveal_ends_at, classroom_id
     FROM quizzes WHERE id = ? AND teacher_id = ? LIMIT 1'
);
$stmt->bind_param('ii', $quizId, $userId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    header('Location: dashboard.php');
    exit;
}

$questions = [];
$qs = $mysqli->prepare(
    'SELECT id, question_text, correct, points FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order ASC'
);
$qs->bind_param('i', $quizId);
$qs->execute();
$qr = $qs->get_result();
while ($row = $qr->fetch_assoc()) {
    $questions[] = $row;
}
$qs->close();
$totalQ = count($questions);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    $refetch = static function () use ($mysqli, $quizId, $userId): ?array {
        $st = $mysqli->prepare(
            'SELECT current_question, status FROM quizzes WHERE id = ? AND teacher_id = ? LIMIT 1'
        );
        $st->bind_param('ii', $quizId, $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    };

    if ($act === 'start') {
        $prev = $mysqli->prepare(
            'SELECT status, classroom_id, title, room_code FROM quizzes WHERE id = ? AND teacher_id = ? LIMIT 1'
        );
        $prev->bind_param('ii', $quizId, $userId);
        $prev->execute();
        $prevRow = $prev->get_result()->fetch_assoc();
        $prev->close();

        $st = $mysqli->prepare(
            "UPDATE quizzes SET status = 'live', current_question = 0, reveal_question_id = NULL, reveal_ends_at = NULL WHERE id = ? AND teacher_id = ?"
        );
        $st->bind_param('ii', $quizId, $userId);
        $st->execute();
        $st->close();

        if ($prevRow && (string) $prevRow['status'] !== 'live') {
            $cid = (int) ($prevRow['classroom_id'] ?? 0);
            if ($cid > 0) {
                classroom_post_notification(
                    $mysqli,
                    $cid,
                    $userId,
                    quiz_live_notification_body(
                        (string) $prevRow['title'],
                        (string) $prevRow['room_code']
                    )
                );
            }
        }
    } elseif ($act === 'next' && $totalQ > 0) {
        $liveQuiz = $refetch();
        if ($liveQuiz) {
            $wasLive = (string) $liveQuiz['status'] === 'live';
            $cur = (int) $liveQuiz['current_question'];
            $oldQId = isset($questions[$cur]) ? (int) $questions[$cur]['id'] : null;
            $next = $cur + 1;

            if ($next >= $totalQ) {
                $last = max(0, $totalQ - 1);
                $st = $mysqli->prepare(
                    "UPDATE quizzes SET status = 'finished', current_question = ?, reveal_question_id = ?, reveal_ends_at = DATE_ADD(NOW(), INTERVAL 5 SECOND) WHERE id = ? AND teacher_id = ?"
                );
                $st->bind_param('iiii', $last, $oldQId, $quizId, $userId);
                $st->execute();
                $st->close();
                if ($wasLive) {
                    quiz_notify_ended($mysqli, $quizId, $userId);
                }
            } else {
                $st = $mysqli->prepare(
                    'UPDATE quizzes SET current_question = ?, reveal_question_id = ?, reveal_ends_at = DATE_ADD(NOW(), INTERVAL 5 SECOND) WHERE id = ? AND teacher_id = ?'
                );
                $st->bind_param('iiii', $next, $oldQId, $quizId, $userId);
                $st->execute();
                $st->close();
            }
        }
    } elseif ($act === 'finish') {
        $liveQuiz = $refetch();
        if ($liveQuiz && $totalQ > 0) {
            $wasLive = (string) $liveQuiz['status'] === 'live';
            $cur = (int) $liveQuiz['current_question'];
            $oldQId = isset($questions[$cur]) ? (int) $questions[$cur]['id'] : null;
            $st = $mysqli->prepare(
                "UPDATE quizzes SET status = 'finished', current_question = ?, reveal_question_id = ?, reveal_ends_at = DATE_ADD(NOW(), INTERVAL 5 SECOND) WHERE id = ? AND teacher_id = ?"
            );
            $st->bind_param('iiii', $cur, $oldQId, $quizId, $userId);
            $st->execute();
            $st->close();
            if ($wasLive) {
                quiz_notify_ended($mysqli, $quizId, $userId);
            }
        } elseif ($liveQuiz) {
            $wasLive = (string) $liveQuiz['status'] === 'live';
            $st = $mysqli->prepare(
                "UPDATE quizzes SET status = 'finished' WHERE id = ? AND teacher_id = ?"
            );
            $st->bind_param('ii', $quizId, $userId);
            $st->execute();
            $st->close();
            if ($wasLive) {
                quiz_notify_ended($mysqli, $quizId, $userId);
            }
        }
    } elseif ($act === 'reset_rank') {
        require_once dirname(__DIR__) . '/includes/quiz_lib.php';
        $result = quiz_reset_rank($mysqli, $quizId, $userId);
        if (!$result['ok']) {
            $_SESSION['flash'] = $result['error'] ?? 'Could not reset rank.';
        } else {
            $_SESSION['flash'] = 'Quiz rank and scores cleared.';
        }
    } elseif ($act === 'reset_draft') {
        $st = $mysqli->prepare(
            "UPDATE quizzes SET status = 'draft', current_question = 0, reveal_question_id = NULL, reveal_ends_at = NULL WHERE id = ? AND teacher_id = ?"
        );
        $st->bind_param('ii', $quizId, $userId);
        $st->execute();
        $st->close();
    }

    header('Location: run_quiz.php?id=' . $quizId);
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT id, title, room_code, status, current_question, reveal_question_id, reveal_ends_at, classroom_id
     FROM quizzes WHERE id = ? AND teacher_id = ? LIMIT 1'
);
$stmt->bind_param('ii', $quizId, $userId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

$idx = (int) $quiz['current_question'];
$current = ($quiz['status'] === 'live' && isset($questions[$idx])) ? $questions[$idx] : null;
$panelPageTitle = 'Host quiz — ' . (string) $quiz['title'];
$panelHeading = 'Host — ' . (string) $quiz['title'];
$panelRole = 'teacher';
$panelActiveNav = 'quizzes';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout">
        <div class="card">
            <p class="muted">Students open <strong>Join quiz</strong>, enter room code <strong><?= htmlspecialchars((string) $quiz['room_code'], ENT_QUOTES, 'UTF-8') ?></strong>. Each <strong>Next</strong> shows the correct answer for <strong>5 seconds</strong> before the next question appears.</p>
            <p>Status: <span class="badge badge-teacher"><?= htmlspecialchars((string) $quiz['status'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($quiz['status'] === 'live'): ?>
                    — Question <?= $idx + 1 ?> / <?= $totalQ ?>
                <?php endif; ?>
            </p>
            <div class="form-actions">
                <?php if ($quiz['status'] !== 'live'): ?>
                    <form method="post">
                        <input type="hidden" name="act" value="start">
                        <button type="submit" class="btn btn-primary" <?= $totalQ < 1 ? 'disabled' : '' ?>>Start live</button>
                    </form>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="act" value="next">
                        <button type="submit" class="btn btn-primary"><?= ($idx + 1 >= $totalQ) ? 'End quiz' : 'Next question' ?></button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="act" value="finish">
                        <button type="submit" class="btn btn-danger">End now</button>
                    </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Reset quiz to draft?');">
                    <input type="hidden" name="act" value="reset_draft">
                    <button type="submit" class="btn btn-ghost">Reset to draft</button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2>Presenter view</h2>
            <?php if ($quiz['status'] !== 'live'): ?>
                <p class="muted">Start the quiz to display the active question to the room.</p>
            <?php elseif ($current === null): ?>
                <p class="muted">No question at this index.</p>
            <?php else: ?>
                <div class="question-panel"><?= htmlspecialchars((string) $current['question_text'], ENT_QUOTES, 'UTF-8') ?></div>
                <p class="muted">Correct key (private): <strong><?= htmlspecialchars(strtoupper((string) $current['correct']), ENT_QUOTES, 'UTF-8') ?></strong> · Points: <?= (int) $current['points'] ?></p>
            <?php endif; ?>
        <div class="card quiz-rank-card">
            <div class="quiz-rank-card__header">
                <h2>Live rank</h2>
                <button type="button" id="reset-rank-btn" class="btn btn-danger btn-sm">Reset rank</button>
            </div>
            <p class="muted">Top students by points earned this session (updates automatically).</p>
            <div id="quiz-rank-wrap">
                <p id="quiz-rank-empty" class="muted">No scores yet.</p>
                <table id="quiz-rank-table" class="data-table" style="margin-top:0.5rem;display:none">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Points</th>
                            <th>Correct</th>
                            <th>Answered</th>
                        </tr>
                    </thead>
                    <tbody id="quiz-rank-body"></tbody>
                </table>
            </div>
        </div>
    </main>
    <script>
    (function () {
        const quizId = <?= (int) $quizId ?>;
        const rankUrl = '../api/quiz_rank_poll.php?quiz_id=' + encodeURIComponent(quizId) + '&limit=10';
        const resetUrl = '../api/quiz_reset_rank.php';
        const rankBody = document.getElementById('quiz-rank-body');
        const rankTable = document.getElementById('quiz-rank-table');
        const rankEmpty = document.getElementById('quiz-rank-empty');
        const resetBtn = document.getElementById('reset-rank-btn');

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function renderRanks(ranks) {
            if (!ranks || ranks.length === 0) {
                rankTable.style.display = 'none';
                rankEmpty.style.display = '';
                rankEmpty.textContent = 'No scores yet.';
                rankBody.innerHTML = '';
                return;
            }
            rankEmpty.style.display = 'none';
            rankTable.style.display = '';
            rankBody.innerHTML = ranks.map(function (r) {
                return '<tr><td>' + r.rank + '</td><td>' + escapeHtml(r.display) + '</td><td><strong>' +
                    Number(r.points).toFixed(2) + '</strong></td><td>' + r.correct_count + '</td><td>' +
                    r.answered_count + '</td></tr>';
            }).join('');
        }

        function pollRank() {
            fetch(rankUrl, { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) return;
                    renderRanks(data.ranks || []);
                })
                .catch(function () {});
        }

        resetBtn.addEventListener('click', function () {
            if (!window.confirm('Are you sure you want to clear all scores and reset the leaderboard for this quiz?')) {
                return;
            }
            const body = new URLSearchParams();
            body.set('quiz_id', String(quizId));
            fetch(resetUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin',
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        alert(data.error || 'Could not reset rank.');
                        return;
                    }
                    renderRanks([]);
                })
                .catch(function () {
                    alert('Could not reset rank.');
                });
        });

        setInterval(pollRank, 2000);
        pollRank();
    })();
    </script>
</body>
</html>
