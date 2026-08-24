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
$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

if ($conversation_id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "No conversation selected"
    ]);
    exit();
}

$memberStmt = mysqli_prepare($conn, "
    SELECT id
    FROM conversation_members
    WHERE conversation_id = ?
    AND user_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($memberStmt, "ii", $conversation_id, $current_user);
mysqli_stmt_execute($memberStmt);
mysqli_stmt_store_result($memberStmt);

if (mysqli_stmt_num_rows($memberStmt) === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Conversation access denied"
    ]);
    mysqli_stmt_close($memberStmt);
    exit();
}

mysqli_stmt_close($memberStmt);

$stmt = mysqli_prepare($conn, "
    INSERT INTO deleted_messages (message_id, deleted_by, delete_type, created_at)
    SELECT m.id, ?, 'me', NOW()
    FROM messages m
    WHERE m.conversation_id = ?
    AND m.is_deleted = 0
    AND NOT EXISTS (
        SELECT 1
        FROM deleted_messages dm
        WHERE dm.message_id = m.id
        AND dm.deleted_by = ?
        AND dm.delete_type = 'me'
    )
");
mysqli_stmt_bind_param($stmt, "iii", $current_user, $conversation_id, $current_user);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode([
    "status" => $ok ? "success" : "error",
    "message" => $ok ? "Chat cleared" : mysqli_error($conn)
]);
?>
