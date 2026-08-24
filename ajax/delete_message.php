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

$current_user = (int) $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
$mode = $_POST['mode'] ?? 'me';
$mode = $mode === 'everyone' ? 'everyone' : 'me';

if ($message_id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid message"
    ]);
    exit();
}

$messageStmt = mysqli_prepare($conn, "
    SELECT m.id, m.sender_id, m.conversation_id
    FROM messages m
    JOIN conversation_members cm
        ON cm.conversation_id = m.conversation_id
        AND cm.user_id = ?
    WHERE m.id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($messageStmt, "ii", $current_user, $message_id);
mysqli_stmt_execute($messageStmt);
$messageResult = mysqli_stmt_get_result($messageStmt);
$message = mysqli_fetch_assoc($messageResult);
mysqli_stmt_close($messageStmt);

if (!$message) {
    echo json_encode([
        "status" => "error",
        "message" => "Message not found"
    ]);
    exit();
}

if ($mode === 'everyone') {
    if ((int) $message['sender_id'] !== $current_user) {
        echo json_encode([
            "status" => "error",
            "message" => "Only sender can delete for everyone"
        ]);
        exit();
    }

    $stmt = mysqli_prepare($conn, "
        UPDATE messages
        SET is_deleted = 1
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $message_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
} else {
    $stmt = mysqli_prepare($conn, "
        INSERT INTO deleted_messages (message_id, deleted_by, delete_type, created_at)
        SELECT ?, ?, 'me', NOW()
        WHERE NOT EXISTS (
            SELECT 1
            FROM deleted_messages
            WHERE message_id = ?
            AND deleted_by = ?
            AND delete_type = 'me'
        )
    ");
    mysqli_stmt_bind_param($stmt, "iiii", $message_id, $current_user, $message_id, $current_user);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

echo json_encode([
    "status" => $ok ? "success" : "error",
    "message" => $ok ? "Message deleted" : mysqli_error($conn)
]);
