<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    include __DIR__ . "/config/config.php";

    $user_id = (int) $_SESSION['user_id'];
    mysqli_query($conn, "UPDATE users SET status='offline', last_seen=NOW() WHERE id=$user_id");
}

session_unset();
session_destroy();

header("Location: pages/login.php");
exit();
?>
