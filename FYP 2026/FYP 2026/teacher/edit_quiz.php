<?php
/**
 * ============================================================================
 * 文件：teacher/edit_quiz.php（教师 - 编辑测验）
 * ----------------------------------------------------------------------------
 * 作用：允许教师编辑某个已存在测验（quiz）的：
 *        - 测验标题 title
 *        - 每道题的题干 question_text
 *        - 四个选项 A / B / C / D（opt_a ~ opt_d）
 *        - 正确答案 correct（a/b/c/d）与分值 points
 *
 * 处理方式：
 *   GET  → 载入测验与题目，渲染可编辑表单（已有题目预填）。
 *   POST → 在事务中「更新标题 + 删除旧题目 + 重新插入新题目」，保证一致性。
 *
 * 注意：由于 quiz_answers 对 quiz_questions 设置了 ON DELETE CASCADE，
 *       重写题目会清除该测验已有的学生作答记录（编辑后视为新一轮），
 *       因此页面上向教师给出提示。
 * ============================================================================
 */

declare(strict_types=1); // 严格类型模式

require_once __DIR__ . '/auth.php'; // 教师身份验证（提供 $mysqli、$userId、$username）

$error = '';   // 错误提示
$success = ''; // 成功提示

// 读取要编辑的测验 ID（GET 或 POST 都可能携带）
$quizId = (int) ($_GET['id'] ?? $_POST['quiz_id'] ?? 0);
if ($quizId < 1) {
    header('Location: dashboard.php');
    exit;
}

// 【归属校验】确认该测验确实属于当前登录教师，防止越权编辑他人测验
$own = $mysqli->prepare('SELECT id, title FROM quizzes WHERE id = ? AND teacher_id = ? LIMIT 1');
$own->bind_param('ii', $quizId, $userId);
$own->execute();
$quizRow = $own->get_result()->fetch_assoc();
$own->close();
if (!$quizRow) { // 找不到或不属于自己 → 退回仪表盘
    header('Location: dashboard.php');
    exit;
}

// ---------------------------------------------------------------------------
// 【POST：保存编辑】
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) ($_POST['title'] ?? '')); // 测验标题
    $raw = $_POST['questions'] ?? [];                 // 题目数组（来自动态表单）

    if ($title === '') {
        $error = 'Quiz title is required.';
    } elseif (!is_array($raw) || count($raw) < 1) {
        $error = 'Add at least one question.';
    } else {
        // 【逐题校验与清洗】只保留「题干 + 四选项 + 合法正确答案」都齐全的题目
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
            $correct = strtolower(trim((string) ($block['correct'] ?? ''))); // 统一转小写
            $pts = (int) ($block['points'] ?? 1);
            if ($pts < 1) {
                $pts = 1; // 分值至少为 1
            }
            // 任一必填项缺失或正确答案非法 → 跳过该题
            if ($qt === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($correct, ['a', 'b', 'c', 'd'], true)) {
                continue;
            }
            $questions[] = [
                'text' => $qt, 'opt_a' => $a, 'opt_b' => $b, 'opt_c' => $c,
                'opt_d' => $d, 'correct' => $correct, 'points' => $pts,
            ];
        }

        if ($questions === []) {
            $error = 'Each question needs text, four options, and a valid correct answer.';
        } else {
            // 【事务】更新标题 + 清空旧题 + 插入新题，三步要么全成功要么全回滚
            $mysqli->begin_transaction();
            try {
                // 1) 更新测验标题
                $ut = $mysqli->prepare('UPDATE quizzes SET title = ? WHERE id = ? AND teacher_id = ?');
                $ut->bind_param('sii', $title, $quizId, $userId);
                $ut->execute();
                $ut->close();

                // 2) 删除该测验的所有旧题目（其关联作答会因外键级联一并删除）
                $dq = $mysqli->prepare('DELETE FROM quiz_questions WHERE quiz_id = ?');
                $dq->bind_param('i', $quizId);
                $dq->execute();
                $dq->close();

                // 3) 按表单顺序重新插入题目，sort_order 保证题目顺序
                $qstmt = $mysqli->prepare(
                    'INSERT INTO quiz_questions (quiz_id, question_text, opt_a, opt_b, opt_c, opt_d, correct, points, sort_order) VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $ord = 0;
                foreach ($questions as $q) {
                    $t = $q['text']; $oa = $q['opt_a']; $ob = $q['opt_b'];
                    $oc = $q['opt_c']; $od = $q['opt_d']; $cor = $q['correct']; $pts = $q['points'];
                    // 类型串 'issssssii'：1整数quiz_id + 6字符串 + 2整数(points, sort_order)
                    $qstmt->bind_param('issssssii', $quizId, $t, $oa, $ob, $oc, $od, $cor, $pts, $ord);
                    $qstmt->execute();
                    $ord++;
                }
                $qstmt->close();

                $mysqli->commit(); // 全部成功 → 提交
                $success = 'Quiz updated successfully.';
            } catch (Throwable $e) {
                $mysqli->rollback(); // 出错 → 回滚，保持原数据不变
                $error = 'Could not update quiz. Try again.';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 【载入当前测验信息与题目】（POST 保存后也重新读取，确保表单显示最新数据）
// ---------------------------------------------------------------------------
$titleStmt = $mysqli->prepare('SELECT title FROM quizzes WHERE id = ? AND teacher_id = ? LIMIT 1');
$titleStmt->bind_param('ii', $quizId, $userId);
$titleStmt->execute();
$cur = $titleStmt->get_result()->fetch_assoc();
$titleStmt->close();
$quizTitle = (string) ($cur['title'] ?? '');

$existingQuestions = [];
$qs = $mysqli->prepare(
    'SELECT question_text, opt_a, opt_b, opt_c, opt_d, correct, points
     FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order ASC'
);
$qs->bind_param('i', $quizId);
$qs->execute();
$qr = $qs->get_result();
while ($row = $qr->fetch_assoc()) {
    $existingQuestions[] = $row;
}
$qs->close();

$panelPageTitle = 'Edit quiz';
$panelHeading = 'Edit quiz';
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
            <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Edit quiz</h2>
            <p class="muted">Update the title, questions, options (A–D) and the correct answer. Saving re-writes the question set, so previous answers for this quiz are cleared.</p>
            <form method="post" id="quiz-form" class="stack">
                <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
                <label class="field">
                    <span>Quiz title</span>
                    <input type="text" name="title" required maxlength="255"
                           value="<?= htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8') ?>">
                </label>
                <div id="questions-wrap"></div>
                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" id="add-q">Add question</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                    <a class="btn btn-ghost" href="dashboard.php">Back</a>
                </div>
            </form>
        </div>
    </main>

    <!-- 题目模板：新增题目时被克隆 -->
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
        let qIndex = 0; // 题目唯一序号，用于生成表单字段 name

        // 把数据库中已有的题目（PHP 输出为 JSON）交给 JS 预填渲染
        const existing = <?= json_encode($existingQuestions, JSON_UNESCAPED_UNICODE) ?>;

        // 重新编号显示「Question 1、2、3 ...」
        function renumber() {
            wrap.querySelectorAll('[data-q]').forEach(function (el, i) {
                const n = el.querySelector('[data-q-num]');
                if (n) n.textContent = String(i + 1);
            });
        }

        // 新增一道题（可选地用 data 预填已有内容）
        function addQuestion(data) {
            const id = qIndex++;
            const node = tpl.content.cloneNode(true);
            node.querySelectorAll('[data-name]').forEach(function (inp) {
                const key = inp.getAttribute('data-name');
                inp.name = 'questions[' + id + '][' + key + ']'; // 绑定数组式字段名
                if (data) {
                    // 根据数据库字段映射回表单：text 对应 question_text
                    if (key === 'text' && data.question_text != null) {
                        inp.value = data.question_text;
                    } else if (data[key] != null) {
                        inp.value = data[key];
                    }
                }
            });
            wrap.appendChild(node);
            renumber();
        }

        // 删除题目（事件委托）
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

        addBtn.addEventListener('click', function () { addQuestion(null); });

        // 初始化：把已有题目逐条渲染；若没有任何题目则给出一个空白题
        if (existing.length > 0) {
            existing.forEach(function (q) { addQuestion(q); });
        } else {
            addQuestion(null);
        }
    })();
    </script>
</body>
</html>
