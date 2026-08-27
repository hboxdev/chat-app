<?php
require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/config.php";

chatweb_ensure_auth_schema($conn);
chatweb_restore_login($conn);

if (!empty($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    if (!chatweb_user_access_allowed($conn, $userId)) {
        chatweb_clear_remember_cookie($conn);
        session_unset();
        header("Location: pages/login.php?account=restricted");
        exit();
    }

    $result = mysqli_query($conn, "SELECT profile_completed, onboarding_completed FROM users WHERE id=$userId LIMIT 1");
    $user = $result ? mysqli_fetch_assoc($result) : [];
    if (empty($user['profile_completed']) || empty($user['onboarding_completed'])) {
        header("Location: pages/setup_profile.php");
        exit();
    }

    header("Location: app/");
    exit();
}

header("Location: pages/register.php");
exit();
