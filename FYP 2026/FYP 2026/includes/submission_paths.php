<?php
declare(strict_types=1);

/**
 * @return array{0: string, 1: string}|null [relative web path, absolute filesystem path]
 */
function submission_resolve_student_file(
    mysqli $mysqli,
    int $studentId,
    int $assignmentId
): ?array {
    $stmt = $mysqli->prepare(
        'SELECT submitted_file_path FROM assignment_completions WHERE student_id = ? AND assignment_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $studentId, $assignmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['submitted_file_path'])) {
        return null;
    }
    $rel = str_replace(['/', '\\'], '/', (string) $row['submitted_file_path']);
    if (strpos($rel, '..') !== false || !preg_match('#^uploads/assignments/[a-zA-Z0-9._-]+$#', $rel)) {
        return null;
    }
    $root = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'assignments');
    $full = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    if ($root === false || $full === false || strpos($full, $root) !== 0 || !is_file($full)) {
        return null;
    }
    return [$rel, $full];
}

/** Remove a student's stored submission file from disk if it exists. */
function submission_unlink_student_file(mysqli $mysqli, int $studentId, int $assignmentId): void
{
    $resolved = submission_resolve_student_file($mysqli, $studentId, $assignmentId);
    if ($resolved !== null) {
        @unlink($resolved[1]);
    }
}
