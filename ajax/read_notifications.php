<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$notification_id = isset($_POST['notification_id']) ? (int) $_POST['notification_id'] : 0;
$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

if ($conversation_id > 0) {
    $stmt = mysqli_prepare($conn, "
        UPDATE notifications n
        JOIN messages m ON m.id = n.message_id
        SET n.is_read = 1
        WHERE n.user_id = ?
        AND m.conversation_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $conversation_id);
} elseif ($notification_id > 0) {
    $stmt = mysqli_prepare($conn, "
        UPDATE notifications
        SET is_read = 1
        WHERE id = ?
        AND user_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $notification_id, $user_id);
} else {
    $stmt = mysqli_prepare($conn, "
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
}

$ok = mysqli_stmt_execute($stmt);

echo json_encode([
    "status" => $ok ? "success" : "error",
    "message" => $ok ? "Notifications updated" : mysqli_error($conn)
]);

mysqli_stmt_close($stmt);
