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
$message_id = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
$reaction = trim($_POST['reaction'] ?? '');
$allowed = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

if ($message_id <= 0 || !in_array($reaction, $allowed, true)) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid reaction"
    ]);
    exit();
}

$checkStmt = mysqli_prepare($conn, "
    SELECT m.id
    FROM messages m
    JOIN conversation_members cm
        ON cm.conversation_id = m.conversation_id
        AND cm.user_id = ?
    WHERE m.id = ?
    AND m.is_deleted = 0
    LIMIT 1
");
mysqli_stmt_bind_param($checkStmt, "ii", $user_id, $message_id);
mysqli_stmt_execute($checkStmt);
mysqli_stmt_store_result($checkStmt);

if (mysqli_stmt_num_rows($checkStmt) === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Message not found"
    ]);
    exit();
}

mysqli_stmt_close($checkStmt);

$deleteStmt = mysqli_prepare($conn, "
    DELETE FROM message_reactions
    WHERE message_id = ?
    AND user_id = ?
");
mysqli_stmt_bind_param($deleteStmt, "ii", $message_id, $user_id);
mysqli_stmt_execute($deleteStmt);
mysqli_stmt_close($deleteStmt);

$stmt = mysqli_prepare($conn, "
    INSERT INTO message_reactions (message_id, user_id, reaction, created_at)
    VALUES (?, ?, ?, NOW())
");
mysqli_stmt_bind_param($stmt, "iis", $message_id, $user_id, $reaction);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode([
    "status" => $ok ? "success" : "error",
    "message" => $ok ? "Reaction saved" : mysqli_error($conn)
]);
