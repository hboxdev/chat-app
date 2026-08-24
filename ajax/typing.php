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
$is_typing = isset($_POST['is_typing']) ? (int) $_POST['is_typing'] : 0;
$is_typing = $is_typing === 1 ? 1 : 0;

if ($conversation_id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid conversation"
    ]);
    exit();
}

$memberStmt = mysqli_prepare($conn, "
    SELECT id
    FROM conversation_members
    WHERE conversation_id = ? AND user_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($memberStmt, "ii", $conversation_id, $user_id);
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

$checkStmt = mysqli_prepare($conn, "
    SELECT id
    FROM typing_status
    WHERE conversation_id = ? AND user_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($checkStmt, "ii", $conversation_id, $user_id);
mysqli_stmt_execute($checkStmt);
mysqli_stmt_store_result($checkStmt);

if (mysqli_stmt_num_rows($checkStmt) > 0) {
    $stmt = mysqli_prepare($conn, "
        UPDATE typing_status
        SET is_typing = ?, updated_at = NOW()
        WHERE conversation_id = ? AND user_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "iii", $is_typing, $conversation_id, $user_id);
} else {
    $stmt = mysqli_prepare($conn, "
        INSERT INTO typing_status (conversation_id, user_id, is_typing, updated_at)
        VALUES (?, ?, ?, NOW())
    ");
    mysqli_stmt_bind_param($stmt, "iii", $conversation_id, $user_id, $is_typing);
}

mysqli_stmt_execute($stmt);

echo json_encode([
    "status" => "success"
]);

mysqli_stmt_close($checkStmt);
mysqli_stmt_close($stmt);
