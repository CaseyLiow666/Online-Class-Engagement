<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/submission_paths.php';

$aid = (int) ($_GET['assignment_id'] ?? 0);
if ($aid < 1) {
    http_response_code(400);
    exit('Bad request');
}

$resolved = submission_resolve_student_file($mysqli, $userId, $aid);
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
