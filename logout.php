<?php
require_once __DIR__ . "/config/session.php";
include __DIR__ . "/config/config.php";

chatweb_ensure_auth_schema($conn);

if (isset($_SESSION['user_id'])) {
    $user_id = (int) $_SESSION['user_id'];
    mysqli_query($conn, "UPDATE users SET status='offline', last_seen=NOW() WHERE id=$user_id");
}

chatweb_clear_remember_cookie($conn);
session_unset();
session_destroy();

header("Location: index.php");
exit();
?>
