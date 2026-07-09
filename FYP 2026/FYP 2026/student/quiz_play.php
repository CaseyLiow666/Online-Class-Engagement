<?php

declare(strict_types=1);



require_once __DIR__ . '/auth.php';



$quizId = (int) ($_SESSION['live_quiz_id'] ?? 0);

if ($quizId < 1) {

    header('Location: join_quiz.php');

    exit;

}

$studentPageTitle = 'Live quiz';
?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Live quiz</title>

    <link rel="stylesheet" href="../style.css">

</head>

<body class="student-dashboard">

    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>

    <main class="layout">

        <div class="card">

            <p id="status-line" class="muted pulse-wait">Connecting…</p>

            <h2 id="quiz-title"></h2>

            <div id="question-wrap" hidden>

                <div class="question-panel" id="q-text"></div>

                <p class="muted">Worth <span id="q-points"></span> pt(s)</p>

                <div class="quiz-options" id="q-options"></div>

                <p id="feedback" class="quiz-feedback" hidden></p>

            </div>

        </div>

        <div class="card quiz-rank-card">
            <h2>Live leaderboard</h2>
            <p class="muted">Top ranks update in real time.</p>
            <p id="quiz-rank-empty" class="muted">No scores yet.</p>
            <table id="quiz-rank-table" class="data-table" style="margin-top:0.5rem;display:none">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Points</th>
                        <th>Correct</th>
                    </tr>
                </thead>
                <tbody id="quiz-rank-body"></tbody>
            </table>
        </div>

    </main>

    <script>

    (function () {

        const quizId = <?= json_encode($quizId) ?>;

        const pollUrl = '../api/quiz_poll.php?quiz_id=' + encodeURIComponent(quizId);

        const rankUrl = '../api/quiz_rank_poll.php?quiz_id=' + encodeURIComponent(quizId) + '&limit=10';

        const submitUrl = 'quiz_submit.php';



        const statusLine = document.getElementById('status-line');

        const quizTitle = document.getElementById('quiz-title');

        const wrap = document.getElementById('question-wrap');

        const qText = document.getElementById('q-text');

        const qPoints = document.getElementById('q-points');

        const qOptions = document.getElementById('q-options');

        const feedback = document.getElementById('feedback');

        const rankBody = document.getElementById('quiz-rank-body');

        const rankTable = document.getElementById('quiz-rank-table');

        const rankEmpty = document.getElementById('quiz-rank-empty');



        let lastQuestionId = null;

        let answeredThis = false;

        let currentQuestionData = null;

        let lastRankResetAt = null;

        const viewerId = <?= (int) $userId ?>;



        function setButtonsDisabled(disabled) {

            qOptions.querySelectorAll('button').forEach(function (b) {

                b.disabled = disabled;

            });

        }



        function showFeedback(ok, correctText) {

            feedback.hidden = false;

            feedback.className = 'quiz-feedback ' + (ok ? 'quiz-feedback--ok' : 'quiz-feedback--bad');

            if (ok) {

                feedback.textContent = 'Correct!';

            } else {

                feedback.textContent = 'Incorrect. Correct answer is: ' + (correctText || '');

            }

        }



        function renderQuestion(q) {

            if (!q) {

                wrap.hidden = true;

                return;

            }

            currentQuestionData = q;

            wrap.hidden = false;

            feedback.hidden = true;

            feedback.textContent = '';

            qText.textContent = q.text;

            qPoints.textContent = String(q.points);

            qOptions.innerHTML = '';

            ['a','b','c','d'].forEach(function (key) {

                const btn = document.createElement('button');

                btn.type = 'button';

                btn.className = 'quiz-opt-btn';

                btn.textContent = key.toUpperCase() + ': ' + q.options[key];

                btn.addEventListener('click', function () {

                    submitAnswer(q.id, key);

                });

                qOptions.appendChild(btn);

            });

        }



        function submitAnswer(questionId, chosen) {

            if (answeredThis) return;

            setButtonsDisabled(true);

            feedback.hidden = true;



            const body = new URLSearchParams();

            body.set('quiz_id', String(quizId));

            body.set('question_id', String(questionId));

            body.set('chosen', chosen);



            fetch(submitUrl, {

                method: 'POST',

                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },

                body: body.toString(),

                credentials: 'same-origin'

            }).then(function (r) { return r.json(); }).then(function (data) {

                if (!data.ok) {

                    feedback.hidden = false;

                    feedback.className = 'quiz-feedback quiz-feedback--bad';

                    feedback.textContent = data.error || 'Could not submit.';

                    if (data.error !== 'Already answered') {

                        setButtonsDisabled(false);

                    } else {

                        answeredThis = true;

                    }

                    return;

                }

                answeredThis = true;

                const correctText = data.correct_answer || '';

                showFeedback(data.correct, correctText);

                if (data.correct && data.points_earned > 0) {
                    let msg = 'Correct! +' + data.points_earned + ' mark(s) for this question.';
                    if (typeof data.academic_max !== 'undefined' && data.academic_max > 0) {
                        msg += ' Academic grade: ' + data.academic_score + '/' + data.academic_max;
                    }
                    feedback.textContent = msg;
                } else if (!data.correct && typeof data.academic_max !== 'undefined' && data.academic_max > 0) {
                    feedback.textContent = 'Academic grade so far: ' + data.academic_score + '/' + data.academic_max;
                }

            }).catch(function () {

                feedback.hidden = false;

                feedback.className = 'quiz-feedback quiz-feedback--bad';

                feedback.textContent = 'Network error.';

                setButtonsDisabled(false);

            });

        }



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

                const isMe = Number(r.student_id) === viewerId;

                const rowClass = isMe ? ' class="quiz-rank-row--me"' : '';

                return '<tr' + rowClass + '><td>' + r.rank + '</td><td>' + escapeHtml(r.display) +

                    (isMe ? ' <span class="badge badge-student">You</span>' : '') +

                    '</td><td><strong>' + Number(r.points).toFixed(2) + '</strong></td><td>' +

                    r.correct_count + '</td></tr>';

            }).join('');

        }



        function onRankReset() {

            answeredThis = false;

            setButtonsDisabled(false);

            feedback.hidden = true;

            feedback.textContent = '';

        }



        function pollRank() {

            fetch(rankUrl, { credentials: 'same-origin' })

                .then(function (r) { return r.json(); })

                .then(function (data) {

                    if (!data.ok) return;

                    const resetAt = data.rank_reset_at || '';

                    if (lastRankResetAt !== null && resetAt !== lastRankResetAt) {

                        onRankReset();

                    }

                    lastRankResetAt = resetAt;

                    renderRanks(data.ranks || []);

                })

                .catch(function () {});

        }



        function tick() {

            fetch(pollUrl, { credentials: 'same-origin' })

                .then(function (r) { return r.json(); })

                .then(function (data) {

                    if (!data.ok) {

                        statusLine.textContent = data.error || 'Error';

                        return;

                    }

                    quizTitle.textContent = data.title || '';



                    const st = data.status;

                    if (st === 'draft') {

                        statusLine.textContent = 'Waiting for host to start…';

                        wrap.hidden = true;

                        lastQuestionId = null;

                        answeredThis = false;

                        return;

                    }

                    if (st === 'finished') {

                        statusLine.textContent = 'Quiz ended — check Home for totals.';

                        wrap.hidden = true;

                        lastQuestionId = null;

                        answeredThis = false;

                        return;

                    }



                    /* Skip teacher reveal phase — advance immediately when question changes. */

                    const q = data.question;

                    if (!q) {

                        statusLine.textContent = 'Live — preparing question…';

                        wrap.hidden = true;

                        return;

                    }



                    statusLine.textContent = 'Live — question ' + (data.current_index + 1) + ' / ' + data.total_questions;



                    if (q.id !== lastQuestionId) {

                        lastQuestionId = q.id;

                        answeredThis = false;

                        renderQuestion(q);

                    }

                })

                .catch(function () {

                    statusLine.textContent = 'Connection issue — retrying…';

                });

        }



        setInterval(tick, 1500);

        setInterval(pollRank, 2000);

        tick();

        pollRank();

    })();

    </script>

</body>

</html>

