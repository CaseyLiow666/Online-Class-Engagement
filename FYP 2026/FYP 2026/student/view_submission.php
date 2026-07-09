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

[$rel, $full] = $resolved;
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$rawUrl = 'submission_raw.php?assignment_id=' . $aid;
$downloadUrl = htmlspecialchars($rawUrl . '&download=1', ENT_QUOTES, 'UTF-8');
$rawUrlEsc = htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8');
$previewPdf = $ext === 'pdf';
$previewImg = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
$fname = basename($full);
$studentPageTitle = 'Your submission';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your submission</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .submission-viewer { margin-top: 1rem; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; background: var(--surface2); min-height: 420px; }
        .submission-viewer iframe { width: 100%; height: 72vh; border: 0; display: block; }
        .submission-viewer img { max-width: 100%; height: auto; display: block; margin: 0 auto; }
        .submission-toolbar { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 0.75rem; }
    </style>
</head>
<body class="student-dashboard">
    <?php require dirname(__DIR__) . '/includes/student_topbar.php'; ?>
    <main class="layout">
        <div class="card">
            <div class="submission-toolbar">
                <strong><?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?></strong>
                <a class="btn btn-ghost btn-sm" href="<?= $downloadUrl ?>">Download</a>
            </div>

            <?php if ($previewPdf): ?>
                <div class="submission-viewer">
                    <iframe title="PDF preview" src="<?= $rawUrlEsc ?>"></iframe>
                </div>
            <?php elseif ($previewImg): ?>
                <div class="submission-viewer" style="padding:1rem;text-align:center">
                    <img src="<?= $rawUrlEsc ?>" alt="Submitted image">
                </div>
            <?php else: ?>
                <p class="muted">Preview is not available for this file type. Use Download to open it on your device.</p>
                <p><a class="btn btn-primary" href="<?= $downloadUrl ?>">Download file</a></p>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
