<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Login required"
    ]);
    exit();
}

$current_user = (int) $_SESSION['user_id'];
$receiver_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

if ($receiver_id <= 0 || $receiver_id === $current_user) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid user selected"
    ]);
    exit();
}

$userCheck = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
mysqli_stmt_bind_param($userCheck, "i", $receiver_id);
mysqli_stmt_execute($userCheck);
mysqli_stmt_store_result($userCheck);

if (mysqli_stmt_num_rows($userCheck) === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "User not found"
    ]);
    exit();
}

mysqli_stmt_close($userCheck);

$existingSql = "
SELECT c.id
FROM conversations c
JOIN conversation_members cm1 ON cm1.conversation_id = c.id AND cm1.user_id = ?
JOIN conversation_members cm2 ON cm2.conversation_id = c.id AND cm2.user_id = ?
WHERE c.type = 'private'
AND (
    SELECT COUNT(*)
    FROM conversation_members cm_count
    WHERE cm_count.conversation_id = c.id
) = 2
ORDER BY c.id DESC
LIMIT 1
";

$existingStmt = mysqli_prepare($conn, $existingSql);
mysqli_stmt_bind_param($existingStmt, "ii", $current_user, $receiver_id);
mysqli_stmt_execute($existingStmt);
$existingResult = mysqli_stmt_get_result($existingStmt);

if ($row = mysqli_fetch_assoc($existingResult)) {
    echo json_encode([
        "status" => "success",
        "conversation_id" => (int) $row['id']
    ]);
    exit();
}

mysqli_stmt_close($existingStmt);

mysqli_begin_transaction($conn);

try {
    $conversationStmt = mysqli_prepare($conn, "
        INSERT INTO conversations (type, created_by, created_at)
        VALUES ('private', ?, NOW())
    ");
    mysqli_stmt_bind_param($conversationStmt, "i", $current_user);
    mysqli_stmt_execute($conversationStmt);
    $conversation_id = mysqli_insert_id($conn);
    mysqli_stmt_close($conversationStmt);

    $memberStmt = mysqli_prepare($conn, "
        INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
        VALUES (?, ?, 'member', NOW())
    ");

    mysqli_stmt_bind_param($memberStmt, "ii", $conversation_id, $current_user);
    mysqli_stmt_execute($memberStmt);

    mysqli_stmt_bind_param($memberStmt, "ii", $conversation_id, $receiver_id);
    mysqli_stmt_execute($memberStmt);
    mysqli_stmt_close($memberStmt);

    mysqli_commit($conn);

    echo json_encode([
        "status" => "success",
        "conversation_id" => $conversation_id
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);

    echo json_encode([
        "status" => "error",
        "message" => "Conversation could not be started"
    ]);
}
