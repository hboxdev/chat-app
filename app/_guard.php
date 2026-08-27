<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/admin_helpers.php";

chatweb_ensure_admin_schema($conn);
chatweb_require_login($conn, '../index.php');

$currentUserId = (int) $_SESSION['user_id'];
$currentUser = mysqli_fetch_assoc(mysqli_query($conn, "SELECT profile_completed, onboarding_completed FROM users WHERE id=$currentUserId LIMIT 1")) ?: [];
if (empty($currentUser['profile_completed']) || empty($currentUser['onboarding_completed'])) {
    header("Location: ../pages/setup_profile.php");
    exit();
}
