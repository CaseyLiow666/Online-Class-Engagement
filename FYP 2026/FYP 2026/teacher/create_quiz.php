<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';

$error = '';
$success = '';

$classroomFilter = (int) ($_GET['classroom_id'] ?? 0);
if ($classroomFilter > 0 && !classroom_teacher_owns($mysqli, $userId, $classroomFilter)) {
    $classroomFilter = 0;
}

$myClasses = [];
$clStmt = $mysqli->prepare(
    'SELECT id, name, join_code FROM classrooms WHERE teacher_id = ? ORDER BY name ASC'
);
if ($clStmt) {
    $clStmt->bind_param('i', $userId);
    $clStmt->execute();
    $cr = $clStmt->get_result();
    while ($row = $cr->fetch_assoc()) {
        $myClasses[] = $row;
    }
    $clStmt->close();
}

function random_room_code(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len = strlen($chars);
    $out = '';
    for ($i = 0; $i < 6; $i++) {
        $out .= $chars[random_int(0, $len - 1)];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) ($_POST['title'] ?? ''));
    $classroomId = (int) ($_POST['classroom_id'] ?? 0);
    $raw = $_POST['questions'] ?? [];

    if ($classroomId < 1 || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
        $error = 'Select a valid class for this quiz.';
    } elseif ($title === '') {
        $error = 'Quiz title is required.';
    } elseif (!is_array($raw) || count($raw) < 1) {
        $error = 'Add at least one question.';
    } else {
        $questions = [];
        foreach ($raw as $block) {
            if (!is_array($block)) {
                continue;
            }
            $qt = trim((string) ($block['text'] ?? ''));
            $a = trim((string) ($block['opt_a'] ?? ''));
            $b = trim((string) ($block['opt_b'] ?? ''));
            $c = trim((string) ($block['opt_c'] ?? ''));
            $d = trim((string) ($block['opt_d'] ?? ''));
            $correct = strtolower(trim((string) ($block['correct'] ?? '')));
            $pts = (int) ($block['points'] ?? 1);
            if ($pts < 1) {
                $pts = 1;
            }
            if ($qt === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($correct, ['a', 'b', 'c', 'd'], true)) {
                continue;
            }
            $questions[] = [
                'text' => $qt,
                'opt_a' => $a,
                'opt_b' => $b,
                'opt_c' => $c,
                'opt_d' => $d,
                'correct' => $correct,
                'points' => $pts,
            ];
        }

        if ($questions === []) {
            $error = 'Each question needs text, four options, and a valid correct answer.';
        } else {
            $mysqli->begin_transaction();
            try {
                $quizId = 0;
                $room = random_room_code();
                for ($attempt = 0; $attempt < 8; $attempt++) {
                    $ins = $mysqli->prepare(
                        'INSERT INTO quizzes (teacher_id, classroom_id, title, room_code, status, current_question) VALUES (?,?,?,?,?,0)'
                    );
                    $status = 'draft';
                    $ins->bind_param('iisss', $userId, $classroomId, $title, $room, $status);
                    try {
                        $ins->execute();
                        $quizId = (int) $mysqli->insert_id;
                        $ins->close();
                        break;
                    } catch (Throwable $e) {
                        $ins->close();
                        $room = random_room_code();
                        $quizId = 0;
                    }
                }
                if (empty($quizId)) {
                    throw new RuntimeException('Could not allocate room code.');
                }

                $qstmt = $mysqli->prepare(
                    'INSERT INTO quiz_questions (quiz_id, question_text, opt_a, opt_b, opt_c, opt_d, correct, points, sort_order) VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $ord = 0;
                foreach ($questions as $q) {
                    $t = $q['text'];
                    $oa = $q['opt_a'];
                    $ob = $q['opt_b'];
                    $oc = $q['opt_c'];
                    $od = $q['opt_d'];
                    $cor = $q['correct'];
                    $pts = $q['points'];
                    $qstmt->bind_param(
                        'issssssii',
                        $quizId,
                        $t,
                        $oa,
                        $ob,
                        $oc,
                        $od,
                        $cor,
                        $pts,
                        $ord
                    );
                    $qstmt->execute();
                    $ord++;
                }
                $qstmt->close();
                $mysqli->commit();
                $success = 'Quiz saved. Room code: ' . htmlspecialchars($room, ENT_QUOTES, 'UTF-8') . ' — share this with students.';
            } catch (Throwable $e) {
                $mysqli->rollback();
                $error = 'Could not save quiz. Try again.';
            }
        }
    }
}
$panelPageTitle = 'Create quiz';
$panelHeading = 'Create quiz';
$panelRole = 'teacher';
$panelActiveNav = 'quizzes';
$assetPrefix = '..';
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout">
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>New Kahoot-style quiz</h2>
            <p class="muted">Students join with the room code from the dashboard after you save. Use <strong>Host live</strong> to push questions in real time.</p>
            <form method="post" id="quiz-form" class="stack">
                <label class="field">
                    <span>Class</span>
                    <select name="classroom_id" required>
                        <option value="">— Select class —</option>
                        <?php foreach ($myClasses as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $classroomFilter === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars((string) $c['join_code'], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($myClasses === []): ?>
                    <p class="muted">Create a class first from the dashboard.</p>
                <?php endif; ?>
                <label class="field">
                    <span>Quiz title</span>
                    <input type="text" name="title" required maxlength="255" placeholder="e.g. Chapter 3 review">
                </label>
                <div id="questions-wrap"></div>
                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" id="add-q">Add question</button>
                    <button type="submit" class="btn btn-primary" <?= $myClasses === [] ? 'disabled' : '' ?>>Save quiz</button>
                </div>
            </form>
        </div>
    </main>
    <template id="tpl-q">
        <div class="question-block" data-q>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem">
                <strong>Question <span data-q-num></span></strong>
                <button type="button" class="btn btn-danger btn-sm" data-remove>Remove</button>
            </div>
            <label class="field">
                <span>Question text</span>
                <textarea data-name="text" required></textarea>
            </label>
            <div class="row">
                <label class="field" style="flex:1">
                    <span>Option A</span>
                    <input type="text" data-name="opt_a" required>
                </label>
                <label class="field" style="flex:1">
                    <span>Option B</span>
                    <input type="text" data-name="opt_b" required>
                </label>
            </div>
            <div class="row">
                <label class="field" style="flex:1">
                    <span>Option C</span>
                    <input type="text" data-name="opt_c" required>
                </label>
                <label class="field" style="flex:1">
                    <span>Option D</span>
                    <input type="text" data-name="opt_d" required>
                </label>
            </div>
            <div class="row">
                <label class="field">
                    <span>Correct</span>
                    <select data-name="correct" required>
                        <option value="a">A</option>
                        <option value="b">B</option>
                        <option value="c">C</option>
                        <option value="d">D</option>
                    </select>
                </label>
                <label class="field">
                    <span>Marks for this question</span>
                    <input type="number" data-name="points" value="1" min="1" max="999" step="1">
                </label>
            </div>
        </div>
    </template>
    <script>
    (function () {
        const wrap = document.getElementById('questions-wrap');
        const tpl = document.getElementById('tpl-q');
        const addBtn = document.getElementById('add-q');
        let qIndex = 0;

        function renumber() {
            wrap.querySelectorAll('[data-q]').forEach(function (el, i) {
                const n = el.querySelector('[data-q-num]');
                if (n) n.textContent = String(i + 1);
            });
        }

        function addQuestion() {
            const id = qIndex++;
            const node = tpl.content.cloneNode(true);
            node.querySelectorAll('[data-name]').forEach(function (inp) {
                const key = inp.getAttribute('data-name');
                inp.name = 'questions[' + id + '][' + key + ']';
            });
            wrap.appendChild(node);
            renumber();
        }

        wrap.addEventListener('click', function (e) {
            const t = e.target;
            if (t && t.getAttribute && t.getAttribute('data-remove') !== null) {
                const block = t.closest('[data-q]');
                if (block) {
                    block.remove();
                    renumber();
                }
            }
        });

        addBtn.addEventListener('click', addQuestion);
        addQuestion();
    })();
    </script>
</body>
</html>
