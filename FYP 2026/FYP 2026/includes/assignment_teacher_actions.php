<?php
declare(strict_types=1);

/**
 * Unified Edit + Delete controls for a teacher-owned assignment row.
 *
 * Expected variables:
 *   $assignment — row with id, title, description, due_date, created_at
 *   $returnClassroomId — optional int; when set, post-save/delete returns to that class tab
 *   $openAssignmentId — optional int; auto-expands edit form when it matches assignment id
 */

if (!isset($assignment) || !is_array($assignment)) {
    return;
}

require_once dirname(__DIR__) . '/includes/assignment_lib.php';

$aid = (int) ($assignment['id'] ?? 0);
if ($aid < 1) {
    return;
}

$returnClassroomId = (int) ($returnClassroomId ?? 0);
$openAssignmentId = (int) ($openAssignmentId ?? 0);

$dueRaw = isset($assignment['due_date']) ? (string) $assignment['due_date'] : '';
$dueInput = '';
if ($dueRaw !== '') {
    $dueTs = strtotime($dueRaw);
    if ($dueTs !== false) {
        $dueInput = date('Y-m-d', $dueTs);
    }
}

$pubDay = assignment_publication_day((string) ($assignment['created_at'] ?? ''));
$editDueMin = ($dueInput !== '' && assignment_due_date_is_before_publication($dueInput, $pubDay)) ? '' : $pubDay;
$editOpen = $openAssignmentId === $aid;

$editAction = 'manage_assignments.php?assignment_id=' . $aid;
if ($returnClassroomId > 0) {
    $editAction .= '&return_classroom_id=' . $returnClassroomId;
}
?>
<div class="btn-group assignment-actions" role="group" aria-label="Assignment actions">
    <details class="assignment-edit" id="assignment-<?= $aid ?>"<?= $editOpen ? ' open' : '' ?>>
        <summary class="btn btn-ghost btn-sm">Edit</summary>
        <form method="post" action="<?= htmlspecialchars($editAction, ENT_QUOTES, 'UTF-8') ?>"
              class="stack assignment-edit__form edit-form"
              style="margin-top:0.75rem;min-width:260px"
              data-publication-min="<?= htmlspecialchars($pubDay, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= $aid ?>">
            <?php if ($returnClassroomId > 0): ?>
                <input type="hidden" name="return_classroom_id" value="<?= $returnClassroomId ?>">
            <?php endif; ?>
            <label class="field">
                <span>Title</span>
                <input type="text" name="title" required maxlength="255"
                       value="<?= htmlspecialchars((string) ($assignment['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label class="field">
                <span>Description</span>
                <textarea name="description" rows="3"><?= htmlspecialchars((string) ($assignment['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <label class="field" style="max-width:280px">
                <span>Due date (optional)</span>
                <input type="date" name="due_date"
                       <?php if ($editDueMin !== ''): ?>
                           min="<?= htmlspecialchars($editDueMin, ENT_QUOTES, 'UTF-8') ?>"
                           data-publication-min="<?= htmlspecialchars($editDueMin, ENT_QUOTES, 'UTF-8') ?>"
                       <?php endif; ?>
                       value="<?= htmlspecialchars($dueInput, ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
        </form>
    </details>
    <form method="post" action="manage_assignments.php" class="assignment-delete-form"
          onsubmit="return confirm('Are you sure you want to delete this assignment?');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $aid ?>">
        <?php if ($returnClassroomId > 0): ?>
            <input type="hidden" name="return_classroom_id" value="<?= $returnClassroomId ?>">
        <?php endif; ?>
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </form>
</div>
