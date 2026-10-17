<?php
/**
 * Who may download files, and when files are served with a watermark.
 * Note: a browser can always save what it displays, so "view only" stops the download button and
 * direct downloads, and the watermark identifies who a printed or saved copy came from.
 */
require_once __DIR__ . '/helpers.php';

function canDownloadFiles($pdo) {
    if (isAdmin()) return true;
    $roles = array_map('trim', explode(',', getSetting($pdo, 'download_roles', 'admin,staff,viewer')));
    return in_array($_SESSION['role'] ?? '', $roles, true);
}

/** off | download | all */
function watermarkMode($pdo) {
    $m = getSetting($pdo, 'watermark_mode', 'download');
    return in_array($m, ['off', 'download', 'all'], true) ? $m : 'download';
}
