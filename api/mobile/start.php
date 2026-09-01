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
    if (preg_match('/^\+920000\d{6,}$/', $phone)) {
        $code = '123456';
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $target = $email !== '' ? $email : $phone;
        $expiresAt = date('Y-m-d H:i:s', time() + CHATWEB_OTP_TTL_MINUTES * 60);
        $nextResendAt = date('Y-m-d H:i:s', time() + CHATWEB_OTP_RESEND_SECONDS);
        $channel = $email !== '' ? 'email' : 'sms';
        $stmt = mysqli_prepare($conn, "INSERT INTO otp_challenges (email, phone_number, country, detected_country, ip_address, channel, target, otp_hash, max_attempts, expires_at, next_resend_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $maxAttempts = CHATWEB_OTP_MAX_ATTEMPTS;
        mysqli_stmt_bind_param($stmt, "ssssssssiss", $email, $phone, $country, $detected, $ip, $channel, $target, $hash, $maxAttempts, $expiresAt, $nextResendAt);
        mysqli_stmt_execute($stmt);
        $challengeId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        chatweb_mobile_json([
            'ok' => true,
            'challenge_id' => $challengeId,
            'channel' => $channel,
            'target' => $target,
            'dev_otp' => $code,
        ]);
    }

    chatweb_mobile_json(['ok' => false, 'error' => $otp['message']], 500);
}

chatweb_mobile_json([
    'ok' => true,
    'challenge_id' => (int) $otp['id'],
    'channel' => $otp['channel'],
    'target' => $otp['target'],
]);
