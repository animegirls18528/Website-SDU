<?php
/**
 * Time-based one-time passwords (RFC 6238, 30 s, 6 digits, SHA-1) for two-factor login.
 * Works with Google Authenticator, Microsoft Authenticator, etc.
 */

function base32Encode($bin) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32Decode($str) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $str))) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totpGenerateSecret() {
    return base32Encode(random_bytes(20));
}

function totpCode($secret, $timeSlice) {
    $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $timeSlice), base32Decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Accepts the current code and one step either side for clock drift. */
function totpVerify($secret, $code) {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== 6) return false;
    $slice = (int)floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totpCode($secret, $slice + $i), $code)) return true;
    }
    return false;
}

function totpUri($secret, $username, $issuer) {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

/** Ten one-time recovery codes: [plain codes to show once, hashes to store]. */
function totpRecoveryCodes() {
    $plain = [];
    $hashes = [];
    for ($i = 0; $i < 10; $i++) {
        $c = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
        $plain[] = $c;
        $hashes[] = password_hash($c, PASSWORD_DEFAULT);
    }
    return [$plain, $hashes];
}
