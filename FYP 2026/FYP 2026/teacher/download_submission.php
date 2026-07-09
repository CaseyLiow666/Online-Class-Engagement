<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$assignmentId = (int) ($_GET['assignment_id'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? 0);

if ($assignmentId < 1 || $studentId < 1) {
    http_response_code(400);
    exit('Bad request');
}

$own = $mysqli->prepare('SELECT id FROM assignments WHERE id = ? AND teacher_id = ? LIMIT 1');
$own->bind_param('ii', $assignmentId, $userId);
$own->execute();
if (!$own->get_result()->fetch_assoc()) {
    $own->close();
    http_response_code(403);
    exit('Forbidden');
}
$own->close();

$stmt = $mysqli->prepare(
    'SELECT submitted_file_path FROM assignment_completions WHERE assignment_id = ? AND student_id = ? LIMIT 1'
);
$stmt->bind_param('ii', $assignmentId, $studentId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['submitted_file_path'])) {
    http_response_code(404);
    exit('No submission file');
}

$rel = str_replace(['/', '\\'], '/', (string) $row['submitted_file_path']);
if (strpos($rel, '..') !== false || !preg_match('#^uploads/assignments/[a-zA-Z0-9._-]+$#', $rel)) {
    http_response_code(400);
    exit('Invalid path');
}

$root = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'assignments');
$full = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));

if ($root === false || $full === false || strpos($full, $root) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit('Missing file');
}

$mime = 'application/octet-stream';
if (function_exists('mime_content_type')) {
    $detected = @mime_content_type($full);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }
}

$downloadName = basename($full);
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
readfile($full);
exit;
