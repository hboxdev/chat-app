<?php
require_once __DIR__ . '/../../config/mobile_api_helpers.php';

$data = chatweb_mobile_request_data();
$country = trim($data['country'] ?? 'Pakistan');
$phoneInput = trim($data['phone_number'] ?? '');
$email = strtolower(trim($data['email'] ?? ''));
$phone = chatweb_normalize_phone($phoneInput);
$ip = chatweb_client_ip();
$detected = chatweb_detect_country($ip);

if (!chatweb_valid_phone($phone)) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Enter phone number in international format, for example +923001234567.'], 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
}

$existingEmail = false;
if ($email !== '') {
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email=? AND COALESCE(phone_number, phone, '')<>? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $email, $phone);
    mysqli_stmt_execute($stmt);
    $existingEmail = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
}

if ($existingEmail) {
    chatweb_mobile_json(['ok' => false, 'error' => 'That email is already linked with another account.'], 409);
}

$otp = chatweb_create_otp_challenge($conn, $email, $phone, $country, $detected, $ip);
if (!$otp['ok']) {
    chatweb_mobile_json(['ok' => false, 'error' => $otp['message']], 500);
}

chatweb_mobile_json([
    'ok' => true,
    'challenge_id' => (int) $otp['id'],
    'channel' => $otp['channel'],
    'target' => $otp['target'],
]);

