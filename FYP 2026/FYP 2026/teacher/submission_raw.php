<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/submission_paths.php';

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

$resolved = submission_resolve_student_file($mysqli, $studentId, $assignmentId);
if ($resolved === null) {
    http_response_code(404);
    exit('Not found');
}

[, $full] = $resolved;
$download = isset($_GET['download']) && $_GET['download'] === '1';

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
$disp = $download ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($downloadName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($full);
exit;
