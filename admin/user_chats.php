<?php
require __DIR__ . '/_guard.php';
chatweb_admin_require_permission($conn, 'chat.view');

$userId = (int) ($_GET['id'] ?? 0);
$conversationId = (int) ($_GET['conversation_id'] ?? 0);
$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, full_name, username, phone_number FROM users WHERE id=$userId LIMIT 1"));
if (!$user) {
    http_response_code(404);
    echo "User not found.";
    exit();
}

chatweb_admin_log($conn, 'CHAT_VIEWED_BY_ADMIN', 'user', (string) $userId, ['conversation_id' => $conversationId ?: null]);

$conversations = mysqli_query($conn, "
    SELECT c.id, c.type, c.title, c.created_at, COUNT(m.id) message_count
    FROM conversations c
    JOIN conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=$userId
    LEFT JOIN messages m ON m.conversation_id=c.id
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 50
");

$messages = null;
if ($conversationId > 0) {
    $member = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM conversation_members WHERE user_id=$userId AND conversation_id=$conversationId LIMIT 1"));
    if ($member) {
        chatweb_admin_log($conn, 'CONVERSATION_VIEWED_BY_ADMIN', 'conversation', (string) $conversationId, ['target_user_id' => $userId]);
        $messages = mysqli_query($conn, "
            SELECT m.id, m.sender_id, u.full_name sender_name, m.message_type, m.message, m.attachment, m.created_at
            FROM messages m
            LEFT JOIN users u ON u.id=m.sender_id
            WHERE m.conversation_id=$conversationId
            ORDER BY m.created_at DESC
            LIMIT 100
        ");
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>User Chats | Admin</title>
<style>body{margin:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827}.main{max-width:1180px;margin:0 auto;padding:28px}.notice{padding:12px;border-radius:8px;background:#fef3c7;color:#92400e}.layout{display:grid;grid-template-columns:340px 1fr;gap:16px}.panel{background:#fff;border:1px solid #dbe3ee;border-radius:8px;padding:16px;overflow:auto}.conv{display:block;padding:12px;border-bottom:1px solid #eef2f7;text-decoration:none;color:#111827}.msg{padding:12px;border-bottom:1px solid #eef2f7}.msg small{color:#64748b}@media(max-width:820px){.layout{grid-template-columns:1fr}.main{padding:16px}}</style></head>
<body><main class="main">
<a href="user.php?id=<?php echo $userId; ?>">Back to user</a>
<h1>Administrative Chat Viewer</h1>
<p class="notice">You are viewing a read-only administrative copy. This access is permission-controlled and audited.</p>
<h2><?php echo htmlspecialchars($user['full_name'] ?: $user['username'] ?: 'User'); ?></h2>
<section class="layout">
    <aside class="panel"><h3>Conversations</h3><?php while($c=mysqli_fetch_assoc($conversations)){ ?><a class="conv" href="?id=<?php echo $userId; ?>&conversation_id=<?php echo (int)$c['id']; ?>"><strong>#<?php echo (int)$c['id']; ?> <?php echo htmlspecialchars($c['title'] ?: $c['type']); ?></strong><br><small><?php echo (int)$c['message_count']; ?> messages · <?php echo htmlspecialchars($c['created_at']); ?></small></a><?php } ?></aside>
    <section class="panel"><h3>Messages</h3><?php if(!$messages){ ?><p>Select a conversation.</p><?php } else { while($m=mysqli_fetch_assoc($messages)){ ?><div class="msg"><strong><?php echo htmlspecialchars($m['sender_name'] ?: ('User #' . $m['sender_id'])); ?></strong> <small><?php echo htmlspecialchars($m['created_at']); ?> · <?php echo htmlspecialchars($m['message_type']); ?></small><p><?php echo nl2br(htmlspecialchars($m['message'] ?: '[attachment]')); ?></p><?php if($m['attachment']){ ?><small>Attachment: <?php echo htmlspecialchars($m['attachment']); ?></small><?php } ?></div><?php } } ?></section>
</section>
</main></body></html>

