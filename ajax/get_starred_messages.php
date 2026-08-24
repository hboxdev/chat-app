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
    CREATE TABLE IF NOT EXISTS starred_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        message_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_star (message_id, user_id)
    )
");

$user_id = (int) $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

if ($conversation_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid conversation"]);
    exit();
}

$stmt = mysqli_prepare($conn, "
    SELECT
        m.id,
        m.message,
        m.message_type,
        m.attachment,
        m.created_at,
        u.full_name AS sender_name
    FROM starred_messages sm
    JOIN messages m ON m.id = sm.message_id
    JOIN users u ON u.id = m.sender_id
    JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = sm.user_id
    WHERE sm.user_id = ?
    AND m.conversation_id = ?
    AND m.is_deleted = 0
    ORDER BY sm.id DESC
");
mysqli_stmt_bind_param($stmt, "ii", $user_id, $conversation_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$items = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row['id'] = (int) $row['id'];
    $items[] = $row;
}

mysqli_stmt_close($stmt);

echo json_encode([
    "status" => "success",
    "items" => $items
]);
?>
