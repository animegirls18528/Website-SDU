<?php
// Serves uploaded documents to logged-in users only. uploads/ itself is blocked by .htaccess.
require_once '../config/auth.php';
startSecureSession();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$name = basename((string)($_GET['f'] ?? ''));
if (!preg_match('/^doc_[a-zA-Z0-9]+_\d+\.(pdf|jpe?g|png|tiff?)$/', $name)) {
    http_response_code(400);
    exit('Invalid file');
}

$path = __DIR__ . '/../uploads/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found');
}

$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'tif' => 'image/tiff', 'tiff' => 'image/tiff'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

session_write_close(); // don't hold the session lock while streaming
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
