<?php
require_once __DIR__ . '/../../config/mobile_api_helpers.php';

$user = chatweb_mobile_user($conn);
$userId = (int) $user['id'];
$data = chatweb_mobile_request_data();
$conversationId = (int) ($_GET['conversation_id'] ?? ($data['conversation_id'] ?? 0));

if ($conversationId <= 0) {
    chatweb_mobile_json(['ok' => false, 'error' => 'No conversation selected.'], 422);
}

$memberStmt = mysqli_prepare($conn, "SELECT id FROM conversation_members WHERE conversation_id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($memberStmt, "ii", $conversationId, $userId);
mysqli_stmt_execute($memberStmt);
$allowed = mysqli_num_rows(mysqli_stmt_get_result($memberStmt)) > 0;
mysqli_stmt_close($memberStmt);
if (!$allowed) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Conversation access denied.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($data['message'] ?? '');
    if ($message === '') {
        chatweb_mobile_json(['ok' => false, 'error' => 'Message is required.'], 422);
    }
    $stmt = mysqli_prepare($conn, "INSERT INTO messages (conversation_id, sender_id, message_type, message, created_at) VALUES (?, ?, 'text', ?, NOW())");
    mysqli_stmt_bind_param($stmt, "iis", $conversationId, $userId, $message);
    $saved = mysqli_stmt_execute($stmt);
    $messageId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    if (!$saved) {
        chatweb_mobile_json(['ok' => false, 'error' => 'Message could not be sent.'], 500);
    }
    chatweb_mobile_json(['ok' => true, 'message_id' => $messageId]);
}

$stmt = mysqli_prepare($conn, "
    SELECT m.id, m.sender_id, m.message_type, m.message, m.attachment, m.created_at, u.full_name
    FROM messages m
    JOIN users u ON u.id=m.sender_id
    WHERE m.conversation_id=? AND m.is_deleted=0
    ORDER BY m.id ASC
    LIMIT 300
");
mysqli_stmt_bind_param($stmt, "i", $conversationId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$messages = [];
while ($row = mysqli_fetch_assoc($result)) {
    $messages[] = [
        'id' => (int) $row['id'],
        'sender_id' => (int) $row['sender_id'],
        'mine' => (int) $row['sender_id'] === $userId,
        'message_type' => $row['message_type'],
        'message' => $row['message'],
        'attachment_url' => $row['attachment'] ? chatweb_mobile_base_url() . '/uploads/' . ltrim($row['attachment'], '/') : '',
        'created_at' => $row['created_at'],
        'sender_name' => $row['full_name'],
    ];
}
mysqli_stmt_close($stmt);

chatweb_mobile_json(['ok' => true, 'messages' => $messages]);

