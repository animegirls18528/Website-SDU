<?php
/**
 * PDPA helpers: find Thai national ID numbers and bank account numbers in document text, mask them for
 * people without permission, and decide who may open documents that contain personal data.
 *
 * Scanned images cannot be redacted reliably without word positions, so for flagged documents people
 * without permission see masked index data and cannot open the files at all.
 */
require_once __DIR__ . '/helpers.php';

const PII_ID_PATTERN = '/(?<!\d)(\d)[\s-]?(\d{4})[\s-]?(\d{5})[\s-]?(\d{2})[\s-]?(\d)(?!\d)/u';
const PII_ACCOUNT_PATTERN = '/(?<!\d)(\d{3})-(\d)-(\d{5})-(\d)(?!\d)/u';

/** Thai national ID checksum (13th digit). */
function thaiIdValid($digits) {
    if (!preg_match('/^\d{13}$/', $digits)) return false;
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)$digits[$i] * (13 - $i);
    return (11 - $sum % 11) % 10 === (int)$digits[12];
}

/** ['ids' => n, 'accounts' => n] found in the text. */
function detectPii($text) {
    $ids = 0;
    if (preg_match_all(PII_ID_PATTERN, (string)$text, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            if (thaiIdValid($x[1] . $x[2] . $x[3] . $x[4] . $x[5])) $ids++;
        }
    }
    $accounts = preg_match_all(PII_ACCOUNT_PATTERN, (string)$text);
    return ['ids' => $ids, 'accounts' => (int)$accounts];
}

function piiSummary(array $found) {
    $parts = [];
    if ($found['ids']) $parts[] = "เลขบัตรประชาชน {$found['ids']} รายการ";
    if ($found['accounts']) $parts[] = "เลขบัญชี {$found['accounts']} รายการ";
    return implode(', ', $parts);
}

/** Mask ID and account numbers, keeping the last digits so staff can still tell records apart. */
function maskPii($text) {
    $text = preg_replace_callback(PII_ID_PATTERN, function ($x) {
        return thaiIdValid($x[1] . $x[2] . $x[3] . $x[4] . $x[5]) ? "X-XXXX-XXXXX-{$x[4]}-X" : $x[0];
    }, (string)$text);
    return preg_replace_callback(PII_ACCOUNT_PATTERN, fn($x) => 'XXX-X-XX' . substr($x[3], -3) . '-X', $text);
}

function maskPiiDeep($value) {
    if (is_array($value)) return array_map('maskPiiDeep', $value);
    return is_string($value) ? maskPii($value) : $value;
}

function canSeePii($pdo) {
    if (isAdmin()) return true;
    $roles = array_map('trim', explode(',', getSetting($pdo, 'pii_roles', 'admin')));
    return in_array($_SESSION['role'] ?? '', $roles, true);
}

/** Re-evaluate the automatic flag after the document's text changed (a manual flag, 2, is kept). */
function updatePiiFlag($pdo, $docId) {
    $stmt = $pdo->prepare("SELECT OcrText, CustomData, PiiFlag FROM documents WHERE ID = ?");
    $stmt->execute([$docId]);
    $d = $stmt->fetch();
    if (!$d || (int)$d['PiiFlag'] === 2) return;
    $found = detectPii($d['OcrText'] . ' ' . $d['CustomData']);
    $flag = ($found['ids'] || $found['accounts']) ? 1 : 0;
    $pdo->prepare("UPDATE documents SET PiiFlag = ?, PiiSummary = ? WHERE ID = ?")
        ->execute([$flag, $flag ? piiSummary($found) : null, $docId]);
}
