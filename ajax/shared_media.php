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

$user_id = (int) $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;
$type = $_POST['type'] ?? 'all';
$allowed = ['all', 'image', 'video', 'audio', 'file'];

if ($conversation_id <= 0 || !in_array($type, $allowed, true)) {
    echo json_encode(["status" => "error", "message" => "Invalid request"]);
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

$typeSql = $type === 'all' ? "" : "AND m.message_type = ?";
$sql = "
    SELECT
        m.id,
        m.message,
        m.message_type,
        m.attachment,
        m.created_at,
        u.full_name AS sender_name
    FROM messages m
    JOIN users u ON u.id = m.sender_id
    WHERE m.conversation_id = ?
    AND m.is_deleted = 0
    AND m.attachment IS NOT NULL
    AND m.attachment != ''
    $typeSql
    AND NOT EXISTS (
        SELECT 1
        FROM deleted_messages dm
        WHERE dm.message_id = m.id
        AND dm.deleted_by = ?
        AND dm.delete_type = 'me'
    )
    ORDER BY m.id DESC
    LIMIT 80
";

$stmt = mysqli_prepare($conn, $sql);

if ($type === 'all') {
    mysqli_stmt_bind_param($stmt, "ii", $conversation_id, $user_id);
} else {
    mysqli_stmt_bind_param($stmt, "isi", $conversation_id, $type, $user_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$items = [];

while ($row = mysqli_fetch_assoc($result)) {
    $path = __DIR__ . "/../" . ($row['attachment'] ?? '');
    $row['id'] = (int) $row['id'];
    $row['file_size'] = is_file($path) ? filesize($path) : 0;
    $items[] = $row;
}

mysqli_stmt_close($stmt);

echo json_encode([
    "status" => "success",
    "items" => $items
]);
?>
