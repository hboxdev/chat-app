<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS pinned_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        message_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_pin (message_id, user_id)
    )
");

$user_id = (int) $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
$action = $_POST['action'] ?? 'toggle';

if ($message_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid message"]);
    exit();
}

$checkStmt = mysqli_prepare($conn, "
    SELECT m.id
    FROM messages m
    JOIN conversation_members cm ON cm.conversation_id = m.conversation_id
    WHERE m.id = ?
    AND cm.user_id = ?
    AND m.is_deleted = 0
    LIMIT 1
");
mysqli_stmt_bind_param($checkStmt, "ii", $message_id, $user_id);
mysqli_stmt_execute($checkStmt);
mysqli_stmt_store_result($checkStmt);

if (mysqli_stmt_num_rows($checkStmt) === 0) {
    echo json_encode(["status" => "error", "message" => "Message not found"]);
    mysqli_stmt_close($checkStmt);
    exit();
}

mysqli_stmt_close($checkStmt);

$existsStmt = mysqli_prepare($conn, "SELECT id FROM pinned_messages WHERE message_id = ? AND user_id = ? LIMIT 1");
mysqli_stmt_bind_param($existsStmt, "ii", $message_id, $user_id);
mysqli_stmt_execute($existsStmt);
mysqli_stmt_store_result($existsStmt);
$exists = mysqli_stmt_num_rows($existsStmt) > 0;
mysqli_stmt_close($existsStmt);

if ($action === 'remove' || ($action === 'toggle' && $exists)) {
    $stmt = mysqli_prepare($conn, "DELETE FROM pinned_messages WHERE message_id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $message_id, $user_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $pinned = false;
} else {
    $stmt = mysqli_prepare($conn, "
        INSERT IGNORE INTO pinned_messages (message_id, user_id, created_at)
        VALUES (?, ?, NOW())
    ");
    mysqli_stmt_bind_param($stmt, "ii", $message_id, $user_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $pinned = true;
}

echo json_encode([
    "status" => $ok ? "success" : "error",
    "pinned" => $pinned
]);
?>
