<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . "/../config/config.php";

if (isset($_SESSION['user_id'])) {
    $user_id = (int) $_SESSION['user_id'];
    mysqli_query($conn, "UPDATE users SET status='offline', last_seen=NOW() WHERE id=$user_id");
}

http_response_code(204);
?>
