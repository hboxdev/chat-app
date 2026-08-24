<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Login required"
    ]);
    exit();
}

include __DIR__ . "/../config/config.php";
include __DIR__ . "/../config/notifications.php";

$sender_id = (int) $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;
$message = trim($_POST['message'] ?? '');
$is_view_once = isset($_POST['is_view_once']) ? (int) $_POST['is_view_once'] : 0;
$is_view_once = $is_view_once === 1 ? 1 : 0;
$reply_to = isset($_POST['reply_to']) ? (int) $_POST['reply_to'] : 0;

if ($conversation_id <= 0 || $message === '') {
    echo json_encode([
        "status" => "error",
        "message" => "Missing data"
    ]);
    exit();
}

$memberStmt = mysqli_prepare($conn, "
    SELECT id
    FROM conversation_members
    WHERE conversation_id = ? AND user_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($memberStmt, "ii", $conversation_id, $sender_id);
mysqli_stmt_execute($memberStmt);
mysqli_stmt_store_result($memberStmt);

if (mysqli_stmt_num_rows($memberStmt) === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Conversation access denied"
    ]);
    exit();
}

mysqli_stmt_close($memberStmt);

$blockStmt = mysqli_prepare($conn, "
    SELECT uc.id
    FROM conversation_members cm
    JOIN user_contacts uc
        ON (
            (uc.user_id = ? AND uc.contact_id = cm.user_id)
            OR (uc.user_id = cm.user_id AND uc.contact_id = ?)
        )
        AND uc.is_blocked = 1
    WHERE cm.conversation_id = ?
    AND cm.user_id != ?
    LIMIT 1
");
mysqli_stmt_bind_param($blockStmt, "iiii", $sender_id, $sender_id, $conversation_id, $sender_id);
mysqli_stmt_execute($blockStmt);
mysqli_stmt_store_result($blockStmt);

if (mysqli_stmt_num_rows($blockStmt) > 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Messages are blocked in this conversation"
    ]);
    mysqli_stmt_close($blockStmt);
    exit();
}

mysqli_stmt_close($blockStmt);

$stmt = mysqli_prepare($conn, "
    INSERT INTO messages (conversation_id, sender_id, message_type, message, reply_to, is_view_once, created_at)
    VALUES (?, ?, 'text', ?, ?, ?, NOW())
");

mysqli_stmt_bind_param($stmt, "iisii", $conversation_id, $sender_id, $message, $reply_to, $is_view_once);

if (mysqli_stmt_execute($stmt)) {
    $message_id = mysqli_insert_id($conn);
    createMessageNotifications($conn, $conversation_id, $sender_id, $message_id);

    echo json_encode([
        "status" => "success",
        "message" => "Message sent"
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => mysqli_error($conn)
    ]);
}

mysqli_stmt_close($stmt);
