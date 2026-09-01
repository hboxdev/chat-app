<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/admin_helpers.php";

chatweb_ensure_admin_schema($conn);
chatweb_require_login($conn, '../index.php');

$currentUserId = (int) $_SESSION['user_id'];
if (!chatweb_profile_setup_complete($conn, $currentUserId)) {
    header("Location: ../pages/setup_profile.php");
    exit();
}
