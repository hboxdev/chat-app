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

    if (!chatweb_profile_setup_complete($conn, $userId)) {
        header("Location: pages/setup_profile.php");
        exit();
    }

    header("Location: app/");
    exit();
}

define('CHATWEB_INDEX_REGISTER', true);
require __DIR__ . "/pages/register.php";
