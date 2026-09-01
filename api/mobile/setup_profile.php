<?php
require_once __DIR__ . '/../../config/mobile_api_helpers.php';

$user = chatweb_mobile_user($conn);
$userId = (int) $user['id'];
$data = chatweb_mobile_request_data();
$fullName = trim(preg_replace('/\s+/', ' ', (string) ($data['full_name'] ?? $user['full_name'] ?? '')));
$username = chatweb_normalize_username($data['username'] ?? ($user['username_normalized'] ?: $user['username'] ?: ''));
$nameLength = function_exists('mb_strlen') ? mb_strlen($fullName) : strlen($fullName);

if ($nameLength < 2 || $nameLength > 80) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Full name must be between 2 and 80 characters.'], 422);
}

$validation = chatweb_validate_username($conn, $username, $userId);
if (!$validation['available']) {
    chatweb_mobile_json(['ok' => false, 'error' => $validation['message']], 422);
}
$username = $validation['normalized'];
$avatar = $user['profile_image'] ?? '';

if (!empty($_FILES['profile_image']['name']) && is_uploaded_file($_FILES['profile_image']['tmp_name'])) {
    if ((int) ($_FILES['profile_image']['size'] ?? 0) > 3 * 1024 * 1024) {
        chatweb_mobile_json(['ok' => false, 'error' => 'Profile photo must be 3 MB or smaller.'], 422);
    }
    $info = getimagesize($_FILES['profile_image']['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        chatweb_mobile_json(['ok' => false, 'error' => 'Upload a valid JPEG, PNG, or WebP image.'], 422);
    }
    $dir = __DIR__ . '/../../uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $avatar = 'profile_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $dir . '/' . $avatar)) {
        chatweb_mobile_json(['ok' => false, 'error' => 'Could not save the profile photo.'], 500);
    }
}

$stmt = mysqli_prepare($conn, "UPDATE users SET full_name=?, username=?, username_normalized=?, profile_image=?, onboarding_completed=1, profile_completed=1, onboarding_step='complete' WHERE id=?");
mysqli_stmt_bind_param($stmt, "ssssi", $fullName, $username, $username, $avatar, $userId);
$saved = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

mysqli_query($conn, "INSERT IGNORE INTO user_profiles (user_id) VALUES ($userId)");
$stmt = mysqli_prepare($conn, "UPDATE user_profiles SET display_name=?, avatar=?, setup_completed_at=NOW() WHERE user_id=?");
mysqli_stmt_bind_param($stmt, "ssi", $fullName, $avatar, $userId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$saved) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Could not complete setup.'], 500);
}

$fresh = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id=$userId LIMIT 1"));
chatweb_mobile_json(['ok' => true, 'user' => chatweb_mobile_public_user($fresh), 'setup_complete' => true]);

