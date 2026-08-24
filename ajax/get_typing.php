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
$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

if ($conversation_id <= 0) {
    echo json_encode([
        "typing" => false
    ]);
    exit();
}

$stmt = mysqli_prepare($conn, "
    SELECT u.full_name
    FROM typing_status t
    JOIN users u ON u.id = t.user_id
    JOIN conversation_members cm ON cm.conversation_id = t.conversation_id AND cm.user_id = ?
    WHERE t.conversation_id = ?
    AND t.user_id != ?
    AND t.is_typing = 1
    AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, "iii", $user_id, $conversation_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    echo json_encode([
        "typing" => true,
        "name" => $row['full_name']
    ]);
} else {
    echo json_encode([
        "typing" => false
    ]);
}

mysqli_stmt_close($stmt);
