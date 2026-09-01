<?php
require_once __DIR__ . '/../../config/mobile_api_helpers.php';

$user = chatweb_mobile_user($conn);
$userId = (int) $user['id'];

$stmt = mysqli_prepare($conn, "
    SELECT
        c.id,
        c.type,
        c.title,
        c.image,
        c.created_at,
        other_user.id AS other_user_id,
        other_user.full_name AS other_full_name,
        other_user.username AS other_username,
        other_user.profile_image AS other_profile_image,
        other_user.status AS other_status,
        latest.message AS latest_message,
        latest.message_type AS latest_message_type,
        latest.created_at AS latest_at
    FROM conversations c
    JOIN conversation_members me ON me.conversation_id=c.id AND me.user_id=?
    LEFT JOIN conversation_members other_member ON other_member.conversation_id=c.id AND other_member.user_id<>?
    LEFT JOIN users other_user ON other_user.id=other_member.user_id
    LEFT JOIN messages latest ON latest.id = (
        SELECT m2.id
        FROM messages m2
        WHERE m2.conversation_id=c.id AND m2.is_deleted=0
        ORDER BY m2.id DESC
        LIMIT 1
    )
    ORDER BY COALESCE(latest.created_at, c.created_at) DESC
    LIMIT 100
");
mysqli_stmt_bind_param($stmt, "ii", $userId, $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$chats = [];
while ($row = mysqli_fetch_assoc($result)) {
    $avatar = $row['type'] === 'group' ? ($row['image'] ?? '') : ($row['other_profile_image'] ?? '');
    $chats[] = [
        'id' => (int) $row['id'],
        'type' => $row['type'],
        'title' => $row['type'] === 'group' ? ($row['title'] ?: 'Group') : ($row['other_full_name'] ?: 'Chat'),
        'subtitle' => $row['latest_message'] ?: ($row['latest_message_type'] ? ucfirst($row['latest_message_type']) : 'No messages yet'),
        'latest_at' => $row['latest_at'] ?: $row['created_at'],
        'avatar_url' => $avatar ? chatweb_mobile_base_url() . '/uploads/' . ltrim($avatar, '/') : '',
        'other_user_id' => (int) ($row['other_user_id'] ?? 0),
        'status' => $row['other_status'] ?? '',
    ];
}
mysqli_stmt_close($stmt);

chatweb_mobile_json(['ok' => true, 'chats' => $chats]);

