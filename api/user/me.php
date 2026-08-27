<?php
require_once __DIR__ . '/_guard.php';

chatweb_user_api_require($conn);

$id = (int) $_SESSION['user_id'];
$result = mysqli_query($conn, "SELECT id, full_name, username, email, phone_number, detected_country, profile_image, phone_verified, email_verified, onboarding_completed, profile_completed, account_status FROM users WHERE id=$id LIMIT 1");
$user = mysqli_fetch_assoc($result);

echo json_encode(['ok' => true, 'user' => $user]);
