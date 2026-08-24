<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit();
}

include __DIR__ . "/../config/config.php";

$current_user = (int)$_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;

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

mysqli_stmt_bind_param($memberStmt, "ii", $conversation_id, $current_user);
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

/*
Mark all messages as seen
that were NOT sent by me.
*/

$sql = "
UPDATE messages
SET
    is_read = 1,
    seen_at = NOW()
WHERE
    conversation_id = ?
AND
    sender_id != ?
AND
    is_read = 0
";

$stmt = mysqli_prepare($conn, $sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $conversation_id,
    $current_user
);

if (mysqli_stmt_execute($stmt)) {

    echo json_encode([
        "status" => "success"
    ]);

} else {

    echo json_encode([
        "status" => "error",
        "message" => mysqli_error($conn)
    ]);

}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>
