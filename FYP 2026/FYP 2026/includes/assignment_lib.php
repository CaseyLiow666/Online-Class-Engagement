<?php
declare(strict_types=1);

/** Human-readable due date or fallback label. */
function assignment_format_due_date(?string $dueDate): string
{
    if ($dueDate === null || trim($dueDate) === '') {
        return 'No due date';
    }
    $ts = strtotime($dueDate);
    if ($ts === false) {
        return 'No due date';
    }
    return date('Y-m-d H:i', $ts);
}

/** True when a due datetime is set and already passed. */
function assignment_is_past_due(?string $dueDate): bool
{
    if ($dueDate === null || trim($dueDate) === '') {
        return false;
    }
    $ts = strtotime($dueDate);
    if ($ts === false) {
        return false;
    }
    return $ts < time();
}

/**
 * Normalize optional due date from form input to SQL datetime or null.
 */
function assignment_parse_due_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

/** Calendar day (Y-m-d) from an assignment publication/created timestamp. */
function assignment_publication_day(?string $createdAt): string
{
    if ($createdAt === null || trim($createdAt) === '') {
        return date('Y-m-d');
    }
    $ts = strtotime($createdAt);
    if ($ts === false) {
        return date('Y-m-d');
    }
    return date('Y-m-d', $ts);
}

/** Minimum selectable due date: cannot be before publication day. */
function assignment_due_date_input_min(?string $publicationDay = null): string
{
    if ($publicationDay !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $publicationDay)) {
        return $publicationDay;
    }
    return date('Y-m-d');
}

/**
 * True when a non-empty due date falls on a calendar day before the publication day.
 * Empty due dates are allowed (optional field).
 */
function assignment_due_date_is_before_publication(string $raw, string $publicationDay): bool
{
    $raw = trim($raw);
    if ($raw === '') {
        return false;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $publicationDay)) {
        $publicationDay = date('Y-m-d');
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return false;
    }
    return date('Y-m-d', $ts) < $publicationDay;
}

/** Notification body when a new assignment is posted. */
function assignment_notification_body(string $title, ?string $dueDate): string
{
    $dueLabel = ($dueDate !== null && trim($dueDate) !== '')
        ? assignment_format_due_date($dueDate)
        : 'No due date';
    return 'New Assignment Posted: ' . $title . '. Due Date: ' . $dueLabel . '. Please submit on time!';
}

/** Redirect target after assignment submission actions. */
function assignment_student_redirect(int $returnClassroomId): string
{
    if ($returnClassroomId > 0) {
        return 'classroom.php?id=' . $returnClassroomId . '&tab=assignments';
    }
    return 'assignments.php';
}

/**
 * Validate and store an uploaded assignment file.
 *
 * @return array{ok: true, rel_path: string, dest_fs: string}|array{ok: false, error: string}
 */
function assignment_store_submission_file(array $upload, int $userId, int $assignmentId): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please choose a file to upload.'];
    }
    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed — try a smaller file or different format.'];
    }

    $maxBytes = 5242880;
    $allowedExt = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'zip', 'txt'];

    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > $maxBytes) {
        return ['ok' => false, 'error' => 'File must be between 1 byte and 5 MB.'];
    }

    $origName = (string) ($upload['name'] ?? '');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'Allowed types: PDF, Word, PNG, JPG, ZIP, TXT.'];
    }

    $tmp = (string) ($upload['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }

    $assignDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'assignments';
    if (!is_dir($assignDir) && !mkdir($assignDir, 0755, true)) {
        return ['ok' => false, 'error' => 'Server could not store file.'];
    }

    $safeExt = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'dat';
    $newBase = $userId . '_' . $assignmentId . '_' . bin2hex(random_bytes(8)) . '.' . $safeExt;
    $destFs = $assignDir . DIRECTORY_SEPARATOR . $newBase;
    $relPath = 'uploads/assignments/' . $newBase;

    if (!move_uploaded_file($tmp, $destFs)) {
        return ['ok' => false, 'error' => 'Could not save upload.'];
    }

    return ['ok' => true, 'rel_path' => $relPath, 'dest_fs' => $destFs];
}
