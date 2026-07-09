<?php
declare(strict_types=1);

/**
 * Render view / edit / delete controls for a student assignment submission.
 */
function student_submission_actions(
    int $assignmentId,
    int $returnClassroomId,
    bool $isSubmitted,
    ?string $filePath,
    bool $pastDue
): void {
    if (!$isSubmitted || $filePath === null || trim($filePath) === '') {
        return;
    }

    $aid = $assignmentId;
    $fileName = basename($filePath);
    ?>
    <div class="submission-row">
        <p class="submission-row__file">
            <a href="view_submission.php?assignment_id=<?= $aid ?>">View your submission</a>
            <span class="muted">(<?= htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') ?>)</span>
        </p>
        <?php if (!$pastDue): ?>
            <div class="submission-actions">
                <details class="submission-edit-panel">
                    <summary class="submission-action submission-action--edit">Edit</summary>
                    <form method="post" action="complete_assignment.php" enctype="multipart/form-data" class="stack submission-edit-form">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="assignment_id" value="<?= $aid ?>">
                        <?php if ($returnClassroomId > 0): ?>
                            <input type="hidden" name="return_classroom_id" value="<?= $returnClassroomId ?>">
                        <?php endif; ?>
                        <label class="field">
                            <span>Replace file</span>
                            <input type="file" name="submission" required accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.zip,.txt">
                        </label>
                        <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
                    </form>
                </details>
                <form method="post" action="complete_assignment.php" class="submission-delete-form"
                      onsubmit="return confirm('Are you sure you want to delete this submission?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="assignment_id" value="<?= $aid ?>">
                    <?php if ($returnClassroomId > 0): ?>
                        <input type="hidden" name="return_classroom_id" value="<?= $returnClassroomId ?>">
                    <?php endif; ?>
                    <button type="submit" class="submission-action submission-action--delete">Delete</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
