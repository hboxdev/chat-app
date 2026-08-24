<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include __DIR__ . "/../config/config.php";

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS starred_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        message_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_star (message_id, user_id)
    )
");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

if ($conversation_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid conversation"]);
    exit();
}

$memberStmt = mysqli_prepare($conn, "
    SELECT id
    FROM conversation_members
    WHERE conversation_id = ?
    AND user_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($memberStmt, "ii", $conversation_id, $user_id);
mysqli_stmt_execute($memberStmt);
mysqli_stmt_store_result($memberStmt);

if (mysqli_stmt_num_rows($memberStmt) === 0) {
    echo json_encode(["status" => "error", "message" => "Conversation access denied"]);
    mysqli_stmt_close($memberStmt);
    exit();
}

mysqli_stmt_close($memberStmt);

$attachments = [];
$attachmentStmt = mysqli_prepare($conn, "
    SELECT attachment
    FROM messages
    WHERE conversation_id = ?
    AND attachment IS NOT NULL
    AND attachment != ''
");
mysqli_stmt_bind_param($attachmentStmt, "i", $conversation_id);
mysqli_stmt_execute($attachmentStmt);
$attachmentResult = mysqli_stmt_get_result($attachmentStmt);

while ($row = mysqli_fetch_assoc($attachmentResult)) {
    $attachments[] = $row['attachment'];
}

mysqli_stmt_close($attachmentStmt);

mysqli_begin_transaction($conn);

$ok = true;
$queries = [
    "DELETE n FROM notifications n JOIN messages m ON m.id = n.message_id WHERE m.conversation_id = ?",
    "DELETE r FROM message_reactions r JOIN messages m ON m.id = r.message_id WHERE m.conversation_id = ?",
    "DELETE ms FROM message_status ms JOIN messages m ON m.id = ms.message_id WHERE m.conversation_id = ?",
    "DELETE sm FROM starred_messages sm JOIN messages m ON m.id = sm.message_id WHERE m.conversation_id = ?",
    "DELETE dm FROM deleted_messages dm JOIN messages m ON m.id = dm.message_id WHERE m.conversation_id = ?",
    "DELETE FROM typing_status WHERE conversation_id = ?",
    "DELETE FROM messages WHERE conversation_id = ?",
    "DELETE FROM conversation_members WHERE conversation_id = ?",
    "DELETE FROM conversations WHERE id = ?"
];

foreach ($queries as $sql) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $conversation_id);
    $ok = $ok && mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        break;
    }
}

if ($ok) {
    mysqli_commit($conn);

    foreach ($attachments as $attachment) {
        $path = realpath(__DIR__ . "/../" . $attachment);
        $uploadsRoot = realpath(__DIR__ . "/../uploads");

        if ($path && $uploadsRoot && str_starts_with($path, $uploadsRoot) && is_file($path)) {
            @unlink($path);
        }
    }
} else {
    mysqli_rollback($conn);
}

echo json_encode([
    "status" => $ok ? "success" : "error",
    "message" => $ok ? "Conversation deleted" : mysqli_error($conn)
]);
?>
