<?php
/**
 * Reset a user's password from the server console (for a forgotten admin password).
 *   php tools/reset_password.php admin
 * Also turns off two-factor authentication for that user and signs out all their devices.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/database.php';

$username = $argv[1] ?? '';
if ($username === '') exit("Usage: php tools/reset_password.php <username>\n");

$stmt = $pdo->prepare("SELECT ID FROM users WHERE Username = ?");
$stmt->execute([$username]);
$id = $stmt->fetchColumn();
if (!$id) exit("User not found: $username\n");

echo "New password for $username (at least 8 characters): ";
$pw = trim((string)fgets(STDIN));
if (mb_strlen($pw) < 8) exit("Password too short, nothing changed.\n");

$pdo->prepare("UPDATE users SET Password = ?, TotpEnabled = 0, TotpSecret = NULL, RecoveryCodes = NULL WHERE ID = ?")
    ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
$pdo->prepare("DELETE FROM sessions WHERE UserID = ?")->execute([$id]);
echo "Password changed for $username. Two-factor authentication was turned off; all devices were signed out.\n";
