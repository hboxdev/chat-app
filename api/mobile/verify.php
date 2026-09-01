<?php
require_once __DIR__ . '/../../config/mobile_api_helpers.php';

$data = chatweb_mobile_request_data();
$challengeId = (int) ($data['challenge_id'] ?? 0);
$code = preg_replace('/\D/', '', (string) ($data['otp'] ?? ''));
$deviceName = trim($data['device_name'] ?? 'Expo app');

if ($challengeId <= 0 || !preg_match('/^\d{6}$/', $code)) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Enter the 6 digit verification code.'], 422);
}

$stmt = mysqli_prepare($conn, "SELECT * FROM otp_challenges WHERE id=? AND consumed_at IS NULL LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $challengeId);
mysqli_stmt_execute($stmt);
$challenge = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$challenge) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Verification session not found.'], 404);
}

if (strtotime($challenge['expires_at']) < time()) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Verification code has expired.'], 410);
}

if (!password_verify($code, $challenge['otp_hash'])) {
    mysqli_query($conn, "UPDATE otp_challenges SET attempts=attempts+1 WHERE id=$challengeId");
    chatweb_mobile_json(['ok' => false, 'error' => 'Invalid verification code.'], 422);
}

$phone = $challenge['phone_number'];
$email = $challenge['email'];
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE phone_number=? OR phone=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ss", $phone, $phone);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$phoneVerified = $challenge['channel'] === 'sms' ? 1 : 0;
$emailVerified = $challenge['channel'] === 'email' ? 1 : 0;
$method = $challenge['channel'];

if ($user) {
    $userId = (int) $user['id'];
    $stmt = mysqli_prepare($conn, "UPDATE users SET country=?, detected_country=?, ip_address=?, phone_verified=GREATEST(phone_verified, ?), email_verified=GREATEST(email_verified, ?), verification_method=?, last_login=NOW(), last_login_at=NOW(), status='online', last_seen=NOW() WHERE id=?");
    mysqli_stmt_bind_param($stmt, "sssiisi", $challenge['country'], $challenge['detected_country'], $challenge['ip_address'], $phoneVerified, $emailVerified, $method, $userId);
    $saved = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
} else {
    $username = chatweb_available_username($conn, 'user' . preg_replace('/\D/', '', $phone), 0);
    $fullName = 'User ' . substr($phone, -4);
    $uuid = uniqid('user_', true);
    $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $emailForDb = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    $stmt = mysqli_prepare($conn, "INSERT INTO users (uuid, full_name, username, username_normalized, email, password, profile_image, status, last_seen, is_active, phone, phone_number, country, detected_country, ip_address, phone_verified, email_verified, verification_method, last_login, last_login_at) VALUES (?, ?, ?, ?, ?, ?, '', 'online', NOW(), 1, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    mysqli_stmt_bind_param($stmt, "sssssssssssiis", $uuid, $fullName, $username, $username, $emailForDb, $passwordHash, $phone, $phone, $challenge['country'], $challenge['detected_country'], $challenge['ip_address'], $phoneVerified, $emailVerified, $method);
    $saved = mysqli_stmt_execute($stmt);
    $userId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
}

if (empty($saved)) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Could not complete login.'], 500);
}

mysqli_query($conn, "UPDATE otp_challenges SET consumed_at=NOW() WHERE id=$challengeId");
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$token = chatweb_mobile_issue_token($conn, $userId, $deviceName);

chatweb_mobile_json([
    'ok' => true,
    'token' => $token,
    'user' => chatweb_mobile_public_user($user),
    'setup_complete' => chatweb_profile_setup_complete($conn, $userId),
]);

