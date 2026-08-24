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

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS starred_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        message_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_star (message_id, user_id)
    )
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS pinned_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        message_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_pin (message_id, user_id)
    )
");

if ($conversation_id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "No conversation"
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

$stmt = mysqli_prepare($conn, "
    SELECT
        m.id,
        m.sender_id,
        m.message_type,
        m.message,
        m.attachment,
        m.reply_to,
        rm.message AS reply_message,
        ru.full_name AS reply_sender,
        m.is_view_once,
        m.view_once_opened_at,
        m.is_read,
        m.seen_at,
        m.created_at,
        u.full_name,
        EXISTS (
            SELECT 1 FROM starred_messages sm
            WHERE sm.message_id = m.id AND sm.user_id = ?
        ) AS is_starred,
        EXISTS (
            SELECT 1 FROM pinned_messages pm
            WHERE pm.message_id = m.id AND pm.user_id = ?
        ) AS is_pinned
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    LEFT JOIN messages rm ON rm.id = m.reply_to
    LEFT JOIN users ru ON ru.id = rm.sender_id
    WHERE m.conversation_id = ?
    AND m.is_deleted = 0
    AND NOT EXISTS (
        SELECT 1
        FROM deleted_messages dm
        WHERE dm.message_id = m.id
        AND dm.deleted_by = ?
        AND dm.delete_type = 'me'
    )
    AND (
        m.is_view_once = 0
        OR m.sender_id = ?
        OR m.view_once_opened_at IS NULL
    )
    ORDER BY m.id ASC
");

mysqli_stmt_bind_param($stmt, "iiiii", $current_user, $current_user, $conversation_id, $current_user, $current_user);
mysqli_stmt_execute($stmt);
$query = mysqli_stmt_get_result($stmt);

$messages = [];

while ($row = mysqli_fetch_assoc($query)) {
    $row['is_view_once'] = (int) $row['is_view_once'];
    $row['is_read'] = (int) $row['is_read'];
    $row['is_starred'] = (int) $row['is_starred'];
    $row['is_pinned'] = (int) $row['is_pinned'];
    $row['reply_to'] = isset($row['reply_to']) ? (int) $row['reply_to'] : 0;
    $row['reactions'] = [];
    $messages[] = $row;
}

$reactionStmt = mysqli_prepare($conn, "
    SELECT
        r.message_id,
        r.reaction,
        COUNT(*) AS total
    FROM message_reactions r
    JOIN messages m ON m.id = r.message_id
    WHERE m.conversation_id = ?
    GROUP BY r.message_id, r.reaction
");

mysqli_stmt_bind_param($reactionStmt, "i", $conversation_id);
mysqli_stmt_execute($reactionStmt);
$reactionResult = mysqli_stmt_get_result($reactionStmt);
$reactionMap = [];

while ($reaction = mysqli_fetch_assoc($reactionResult)) {
    $messageId = (int) $reaction['message_id'];

    if (!isset($reactionMap[$messageId])) {
        $reactionMap[$messageId] = [];
    }

    $reactionMap[$messageId][] = [
        'reaction' => $reaction['reaction'],
        'total' => (int) $reaction['total']
    ];
}

mysqli_stmt_close($reactionStmt);

foreach ($messages as &$message) {
    $message['reactions'] = $reactionMap[(int) $message['id']] ?? [];
}

unset($message);

$viewOnceStmt = mysqli_prepare($conn, "
    UPDATE messages
    SET view_once_opened_at = NOW()
    WHERE conversation_id = ?
    AND sender_id != ?
    AND is_view_once = 1
    AND view_once_opened_at IS NULL
    AND is_deleted = 0
");

mysqli_stmt_bind_param($viewOnceStmt, "ii", $conversation_id, $current_user);
mysqli_stmt_execute($viewOnceStmt);
mysqli_stmt_close($viewOnceStmt);

echo json_encode([
    "status" => "success",
    "messages" => $messages
]);

mysqli_stmt_close($stmt);
