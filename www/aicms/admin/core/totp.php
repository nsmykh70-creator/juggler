<?php
/**
 * TOTP (Time-based One-Time Password) helper
 * RFC 6238 compatible with Google Authenticator, Authy, etc.
 */

/**
 * Generate a new random TOTP secret (Base32 encoded).
 */
function totp_generate_secret($length = 16) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, 31)];
    }
    return $secret;
}

/**
 * Generate the otpauth:// URI for QR code.
 * otpauth://totp/Label?secret=SECRET&issuer=ISSUER
 */
function totp_generate_uri($secret, $label, $issuer = 'AICMS') {
    $label = rawurlencode($issuer . ':' . $label);
    return "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * Generate QR code URL using Google Charts API (or inline SVG fallback).
 */
function totp_generate_qr_url($secret, $label, $issuer = 'AICMS') {
    $uri = totp_generate_uri($secret, $label, $issuer);
    // Use api.qrserver.com (free, no API key)
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($uri);
}

/**
 * Verify a TOTP code.
 * $window = number of periods to check before/after current time (default 2 = ±60 seconds)
 */
function totp_verify($secret, $code, $window = 2) {
    if (empty($secret) || empty($code)) return false;
    $secret_bin = _totp_base32_decode($secret);
    if (!$secret_bin) return false;
    $time = floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $t = $time + $i;
        $bin = pack('N*', 0) . pack('N*', $t);
        $hash = hash_hmac('sha1', $bin, $secret_bin, true);
        $offset = ord($hash[19]) & 0xf;
        $otp = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;
        if (str_pad($otp, 6, '0', STR_PAD_LEFT) === $code) return true;
    }
    return false;
}

function _totp_base32_decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    $b32 = preg_replace('/[^A-Z2-7]/', '', $b32);
    $bits = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $binary = '';
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $byte = substr($bits, $i, 8);
        if (strlen($byte) < 8) break;
        $binary .= chr(bindec($byte));
    }
    return $binary;
}

/**
 * Generate 10 single-use backup codes (8 chars each, format: XXXX-XXXX).
 * Stored hashed (sha256) in users.json['totp_backup_codes'].
 * Returns the plain-text codes for ONE-TIME display to user during setup.
 */
function totp_generate_backup_codes($count = 10) {
    $codes = [];
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';  // no I,O,0,1 to avoid confusion
    for ($i = 0; $i < $count; $i++) {
        $raw = '';
        for ($j = 0; $j < 8; $j++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4);
    }
    return $codes;
}

/**
 * Hash backup codes for storage (never store plain-text).
 */
function totp_hash_backup_code($code) {
    return hash('sha256', strtoupper(trim($code)));
}

/**
 * Verify a backup code against the stored hashes.
 * Returns the index of the matched code (so it can be invalidated), or -1.
 */
function totp_verify_backup_code($entered_code, array $hashed_codes) {
    $entered_hash = totp_hash_backup_code($entered_code);
    foreach ($hashed_codes as $idx => $hash) {
        if (hash_equals($hash, $entered_hash)) return $idx;
    }
    return -1;
}

/**
 * Invalidate a used backup code (remove from list).
 */
function totp_invalidate_backup_code(array $hashed_codes, $index) {
    unset($hashed_codes[$index]);
    return array_values($hashed_codes);  // re-index
}
