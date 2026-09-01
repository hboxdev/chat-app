<?php
require_once __DIR__ . '/../../config/mobile_api_helpers.php';

$user = chatweb_mobile_user($conn);
$currentUser = (int) $user['id'];
$data = chatweb_mobile_request_data();
$receiverId = (int) ($data['user_id'] ?? 0);

if ($receiverId <= 0 || $receiverId === $currentUser) {
    chatweb_mobile_json(['ok' => false, 'error' => 'Invalid user selected.'], 422);
}

$existingSql = "
SELECT c.id
FROM conversations c
JOIN conversation_members cm1 ON cm1.conversation_id=c.id AND cm1.user_id=?
JOIN conversation_members cm2 ON cm2.conversation_id=c.id AND cm2.user_id=?
WHERE c.type='private'
AND (SELECT COUNT(*) FROM conversation_members cm_count WHERE cm_count.conversation_id=c.id)=2
LIMIT 1
";
$stmt = mysqli_prepare($conn, $existingSql);
mysqli_stmt_bind_param($stmt, "ii", $currentUser, $receiverId);
mysqli_stmt_execute($stmt);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if ($existing) {
    chatweb_mobile_json(['ok' => true, 'conversation_id' => (int) $existing['id']]);
}

mysqli_begin_transaction($conn);
try {
    $stmt = mysqli_prepare($conn, "INSERT INTO conversations (type, created_by, created_at) VALUES ('private', ?, NOW())");
    mysqli_stmt_bind_param($stmt, "i", $currentUser);
    mysqli_stmt_execute($stmt);
    $conversationId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
    mysqli_stmt_bind_param($stmt, "ii", $conversationId, $currentUser);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_param($stmt, "ii", $conversationId, $receiverId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    mysqli_commit($conn);
    chatweb_mobile_json(['ok' => true, 'conversation_id' => $conversationId]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    chatweb_mobile_json(['ok' => false, 'error' => 'Conversation could not be started.'], 500);
}

