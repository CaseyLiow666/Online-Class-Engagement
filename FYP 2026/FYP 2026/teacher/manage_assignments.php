<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/classroom_lib.php';
require_once dirname(__DIR__) . '/includes/assignment_lib.php';
require_once dirname(__DIR__) . '/includes/channel_lib.php';

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$classroomFilter = (int) ($_GET['classroom_id'] ?? 0);
if ($classroomFilter > 0 && !classroom_teacher_owns($mysqli, $userId, $classroomFilter)) {
    $classroomFilter = 0;
}

$myClasses = [];
$clStmt = $mysqli->prepare(
    'SELECT cl.id, cl.name, cl.join_code, cl.status
     FROM classrooms cl
     WHERE cl.teacher_id = ?
     ORDER BY cl.name ASC'
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        $cid = (int) ($_POST['classroom_id'] ?? 0);
        $dueDate = assignment_parse_due_date((string) ($_POST['due_date'] ?? ''));
        $rawDuePost = (string) ($_POST['due_date'] ?? '');
        $pubDay = date('Y-m-d');

        if ($title === '') {
            $_SESSION['flash'] = 'Title is required.';
        } elseif (assignment_due_date_is_before_publication($rawDuePost, $pubDay)) {
            $_SESSION['flash'] = 'Error: Due date cannot be earlier than the publication date.';
        } elseif ($cid < 1 || !classroom_teacher_owns($mysqli, $userId, $cid)) {
            $_SESSION['flash'] = 'Select a valid class.';
        } else {
            $openChk = $mysqli->prepare('SELECT id FROM classrooms cl WHERE cl.id = ? AND cl.teacher_id = ? AND cl.status = 1 LIMIT 1');
            $openChk->bind_param('ii', $cid, $userId);
            $openChk->execute();
            $openOk = (bool) $openChk->get_result()->fetch_assoc();
            $openChk->close();

            if (!$openOk) {
                $_SESSION['flash'] = 'Assignments can only be posted to open classes.';
            } else {
                // 数据库无 points 列：完成作业固定奖励 1 分的逻辑在提交处理器中写死
                $stmt = $mysqli->prepare(
                    'INSERT INTO assignments (classroom_id, teacher_id, title, description, due_date) VALUES (?,?,?,?,?)'
                );
                // 类型串 'iisss'：班级ID、教师ID（整数）；标题、描述、截止日期（字符串）
                $stmt->bind_param('iisss', $cid, $userId, $title, $desc, $dueDate);
                $stmt->execute();
                $stmt->close();
                classroom_post_notification(
                    $mysqli,
                    $cid,
                    $userId,
                    assignment_notification_body($title, $dueDate)
                );
                $_SESSION['flash'] = 'Assignment posted to class.';
            }
        }
        $redir = 'manage_assignments.php';
        if ($classroomFilter > 0) {
            $redir .= '?classroom_id=' . $classroomFilter;
        }
        header('Location: ' . $redir);
        exit;
    }
    // ===================================================================
    // 【编辑作业】更新标题、描述、截止日期、分值。
    //   WHERE id = ? AND teacher_id = ? 双重条件确保教师只能改自己的作业。
    //   支持 return_classroom_id：若来自班级页面的编辑，保存后跳回该班级
    //   的 Assignments 标签页。
    // ===================================================================
    if ($action === 'edit') {
        $aid = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        $dueDate = assignment_parse_due_date((string) ($_POST['due_date'] ?? ''));
        $returnClassroomId = (int) ($_POST['return_classroom_id'] ?? 0);

        if ($aid < 1) {
            $_SESSION['flash'] = 'Invalid assignment.';
        } elseif ($title === '') {
            $_SESSION['flash'] = 'Title is required.';
        } else {
            $rawDuePost = (string) ($_POST['due_date'] ?? '');
            $rejectDue = false;

            $prev = $mysqli->prepare('SELECT due_date, created_at FROM assignments WHERE id = ? AND teacher_id = ? LIMIT 1');
            $prev->bind_param('ii', $aid, $userId);
            $prev->execute();
            $prevRow = $prev->get_result()->fetch_assoc();
            $prev->close();

            if (!$prevRow) {
                $_SESSION['flash'] = 'Invalid assignment.';
                if ($returnClassroomId > 0 && classroom_teacher_owns($mysqli, $userId, $returnClassroomId)) {
                    header('Location: classroom.php?id=' . $returnClassroomId . '&tab=assignments');
                } else {
                    header('Location: manage_assignments.php' . ($classroomFilter > 0 ? '?classroom_id=' . $classroomFilter : ''));
                }
                exit;
            }

            $pubDay = assignment_publication_day((string) $prevRow['created_at']);

            if (assignment_due_date_is_before_publication($rawDuePost, $pubDay)) {
                $rejectDue = true;
                if ($prevRow['due_date'] !== null && trim((string) $prevRow['due_date']) !== '') {
                    $newDay = date('Y-m-d', strtotime(trim($rawDuePost)));
                    $oldDay = date('Y-m-d', strtotime((string) $prevRow['due_date']));
                    if ($newDay === $oldDay) {
                        $rejectDue = false;
                    }
                }
            }

            if ($rejectDue) {
                $_SESSION['flash'] = 'Error: Due date cannot be earlier than the publication date.';
            } else {
                $upd = $mysqli->prepare(
                    'UPDATE assignments SET title = ?, description = ?, due_date = ? WHERE id = ? AND teacher_id = ?'
                );
                $upd->bind_param('sssii', $title, $desc, $dueDate, $aid, $userId);
                $upd->execute();
                $upd->close();
                $_SESSION['flash'] = 'Assignment updated.';
            }
        }

        if ($returnClassroomId > 0 && classroom_teacher_owns($mysqli, $userId, $returnClassroomId)) {
            header('Location: classroom.php?id=' . $returnClassroomId . '&tab=assignments');
        } else {
            $redir = 'manage_assignments.php';
            if ($classroomFilter > 0) {
                $redir .= '?classroom_id=' . $classroomFilter;
            }
            header('Location: ' . $redir);
        }
        exit;
    }
    if ($action === 'delete') {
        $aid = (int) ($_POST['id'] ?? 0);
        $returnClassroomId = (int) ($_POST['return_classroom_id'] ?? 0);
        if ($aid > 0) {
            $own = $mysqli->prepare('SELECT id FROM assignments WHERE id = ? AND teacher_id = ? LIMIT 1');
            $own->bind_param('ii', $aid, $userId);
            $own->execute();
            $owned = (bool) $own->get_result()->fetch_assoc();
            $own->close();

            if ($owned) {
                require_once dirname(__DIR__) . '/includes/award_points.php';
                $mysqli->begin_transaction();
                try {
                    revoke_points_for_source($mysqli, 'assignment', (string) $aid);
                    $stmt = $mysqli->prepare('DELETE FROM assignments WHERE id = ? AND teacher_id = ?');
                    $stmt->bind_param('ii', $aid, $userId);
                    $stmt->execute();
                    $stmt->close();
                    $mysqli->commit();
                    $_SESSION['flash'] = 'Assignment removed.';
                } catch (Throwable $e) {
                    $mysqli->rollback();
                    $_SESSION['flash'] = 'Could not delete assignment — try again.';
                }
            } else {
                $_SESSION['flash'] = 'Assignment not found.';
            }
        }
        if ($returnClassroomId > 0 && classroom_teacher_owns($mysqli, $userId, $returnClassroomId)) {
            header('Location: classroom.php?id=' . $returnClassroomId . '&tab=assignments');
        } else {
            header('Location: manage_assignments.php' . ($classroomFilter > 0 ? '?classroom_id=' . $classroomFilter : ''));
        }
        exit;
    }
}

$openAssignmentId = (int) ($_GET['assignment_id'] ?? 0);

$list = [];
if ($classroomFilter > 0) {
    $stmt = $mysqli->prepare(
        'SELECT a.id, a.title, a.description, a.due_date, a.created_at,
                cl.name AS class_name, cl.status AS class_status
         FROM assignments a
         INNER JOIN classrooms cl ON cl.id = a.classroom_id
         WHERE a.teacher_id = ? AND a.classroom_id = ?
         ORDER BY a.created_at DESC'
    );
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $classroomFilter);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $list[] = $row;
        }
        $stmt->close();
    }
} else {
    $stmt = $mysqli->prepare(
        'SELECT a.id, a.title, a.description, a.due_date, a.created_at,
                cl.name AS class_name, cl.status AS class_status
         FROM assignments a
         INNER JOIN classrooms cl ON cl.id = a.classroom_id
         WHERE a.teacher_id = ?
         ORDER BY a.created_at DESC'
    );
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $list[] = $row;
        }
        $stmt->close();
    }
}

$panelPageTitle = 'Assignments';
$panelHeading = 'Assignments';
$panelRole = 'teacher';
$panelActiveNav = 'assignments';
$assetPrefix = '..';
$dueDateMin = assignment_due_date_input_min(date('Y-m-d'));
require dirname(__DIR__) . '/includes/panel_head.php';
require dirname(__DIR__) . '/includes/panel_topbar.php';
?>
    <main class="layout">
        <?php if ($flash !== ''): ?>
            <?php $flashIsError = str_starts_with($flash, 'Error:'); ?>
            <div class="alert <?= $flashIsError ? 'alert-error' : 'alert-success' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Post assignment to a class</h2>
            <p class="muted">Assignments belong to one open class; only enrolled students see them.</p>
            <form method="post" class="stack">
                <input type="hidden" name="action" value="create">
                <label class="field">
                    <span>Class</span>
                    <select name="classroom_id" required>
                        <option value="">— Select —</option>
                        <?php foreach ($myClasses as $c): ?>
                            <?php $isOpen = (int) ($c['status'] ?? 1) === 1; ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $classroomFilter === (int) $c['id'] ? 'selected' : '' ?> <?= $isOpen ? '' : 'disabled' ?>>
                                <?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars((string) $c['join_code'], ENT_QUOTES, 'UTF-8') ?>)
                                <?= $isOpen ? '' : ' — closed' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($myClasses === []): ?>
                    <p class="muted">Create a class first from the dashboard.</p>
                <?php endif; ?>
                <label class="field">
                    <span>Title</span>
                    <input type="text" name="title" required maxlength="255">
                </label>
                <label class="field">
                    <span>Description</span>
                    <textarea name="description" placeholder="Instructions for students"></textarea>
                </label>
                <label class="field" style="max-width:280px">
                    <span>Due date (optional)</span>
                    <input type="date" name="due_date" min="<?= htmlspecialchars($dueDateMin, ENT_QUOTES, 'UTF-8') ?>" data-publication-min="<?= htmlspecialchars($dueDateMin, ENT_QUOTES, 'UTF-8') ?>">
                </label>
                <!-- 说明：每份作业完成固定奖励 1 分，无需也无法设置分值 -->
                <button type="submit" class="btn btn-primary" <?= $myClasses === [] ? 'disabled' : '' ?>>Publish</button>
            </form>
        </div>

        <div class="card">
            <h2>Your assignments</h2>
            <?php if ($classroomFilter > 0): ?>
                <p class="muted">Filtered to one class. <a href="manage_assignments.php">Show all</a>.</p>
            <?php endif; ?>
            <?php if ($list === []): ?>
                <p class="muted">None yet.</p>
            <?php else: ?>
                <?php foreach ($list as $a): ?>
                    <div class="question-block">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
                            <div>
                                <strong><?= htmlspecialchars((string) $a['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="badge badge-teacher" style="margin-left:0.5rem"><?= htmlspecialchars((string) $a['class_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ((int) ($a['class_status'] ?? 1) !== 1): ?>
                                    <span class="badge badge-admin" style="margin-left:0.35rem">Class closed</span>
                                <?php endif; ?>
                                <p class="muted" style="margin:0.35rem 0 0"><?= nl2br(htmlspecialchars((string) ($a['description'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                                <p class="muted" style="margin:0.5rem 0 0;font-size:0.85rem">
                                    Due: <?= htmlspecialchars(assignment_format_due_date(isset($a['due_date']) ? (string) $a['due_date'] : null), ENT_QUOTES, 'UTF-8') ?>
                                    · Posted <?= htmlspecialchars((string) $a['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                            <?php
                            $assignment = $a;
                            $returnClassroomId = 0;
                            require dirname(__DIR__) . '/includes/assignment_teacher_actions.php';
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="date"][name="due_date"]').forEach(function (input) {
            const form = input.closest('form');
            const pubMin = input.getAttribute('data-publication-min')
                || (form && form.getAttribute('data-publication-min'))
                || input.getAttribute('min');
            if (!pubMin) {
                return;
            }
            if (!form || !form.classList.contains('edit-form') || !input.value || input.value >= pubMin) {
                input.setAttribute('min', pubMin);
            }
        });
    });
    </script>
</body>
</html>
