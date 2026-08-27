<?php
require __DIR__ . '/_guard.php';
chatweb_admin_require_permission($conn, 'users.view');

$id = (int) ($_GET['id'] ?? 0);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    if (in_array($action, ['block','suspend','deactivate'], true) && $reason === '') {
        $message = 'Reason is required for this action.';
    } elseif ($action === 'block') {
        chatweb_admin_require_permission($conn, 'users.block');
        chatweb_set_user_account_status($conn, $id, 'BLOCKED', $reason);
        $message = 'User blocked and sessions revoked.';
    } elseif ($action === 'suspend') {
        chatweb_admin_require_permission($conn, 'users.suspend');
        $until = trim($_POST['suspended_until'] ?? '');
        if ($until === '') {
            $message = 'Suspension expiry is required.';
        } else {
            chatweb_set_user_account_status($conn, $id, 'SUSPENDED', $reason, date('Y-m-d H:i:s', strtotime($until)));
            $message = 'User suspended and sessions revoked.';
        }
    } elseif ($action === 'deactivate') {
        chatweb_admin_require_permission($conn, 'users.deactivate');
        chatweb_set_user_account_status($conn, $id, 'DEACTIVATED', $reason);
        $message = 'User deactivated and sessions revoked.';
    } elseif ($action === 'restore') {
        chatweb_admin_require_permission($conn, 'users.edit');
        chatweb_set_user_account_status($conn, $id, 'ACTIVE', $reason);
        $message = 'User restored.';
    } elseif ($action === 'revoke_sessions') {
        chatweb_admin_require_permission($conn, 'sessions.revoke');
        chatweb_revoke_user_sessions($conn, $id);
        chatweb_admin_log($conn, 'ALL_SESSIONS_REVOKED', 'user', (string) $id, ['reason' => $reason]);
        $message = 'All persistent sessions revoked.';
    }
}

$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user) {
    http_response_code(404);
    echo "User not found.";
    exit();
}

$conversationCount = 0;
$messageCount = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) total FROM conversation_members WHERE user_id=?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$conversationCount = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);
mysqli_stmt_close($stmt);
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) total FROM messages WHERE sender_id=?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$messageCount = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>User Detail | Admin</title>
<style>
body{margin:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827}.main{max-width:1120px;margin:0 auto;padding:28px}.back{display:inline-block;margin-bottom:18px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.card{background:#fff;border:1px solid #dbe3ee;border-radius:8px;box-shadow:0 14px 34px rgba(15,23,42,.07);padding:20px}.card span{color:#64748b}.card strong{display:block;font-size:26px;margin-top:6px}.info{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.row{padding:13px;border:1px solid #e5e7eb;border-radius:8px;background:#fff}.row span{display:block;color:#64748b;font-size:13px}.row strong{overflow-wrap:anywhere}.actions{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin:18px 0}.action{background:#fff;border:1px solid #dbe3ee;border-radius:8px;padding:16px}.action textarea,.action input{width:100%;min-height:42px;margin:8px 0;border:1px solid #cbd5e1;border-radius:8px;padding:9px}.btn{min-height:40px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:800;padding:0 14px}.danger{background:#dc2626}.warn{background:#d97706}.ok{background:#16a34a}.notice{padding:12px;border-radius:8px;background:#dbeafe;color:#1e3a8a}@media(max-width:760px){.grid,.info,.actions{grid-template-columns:1fr}.main{padding:16px}}
</style></head><body><main class="main">
<a class="back" href="users.php">Back to users</a>
<h1><?php echo htmlspecialchars($user['full_name'] ?: 'User'); ?></h1>
<?php if($message){ ?><p class="notice"><?php echo htmlspecialchars($message); ?></p><?php } ?>
<section class="grid">
    <div class="card"><span>Conversations</span><strong><?php echo $conversationCount; ?></strong></div>
    <div class="card"><span>Messages Sent</span><strong><?php echo $messageCount; ?></strong></div>
    <div class="card"><span>Status</span><strong><?php echo htmlspecialchars($user['status'] ?: 'offline'); ?></strong></div>
</section>
<section class="actions">
    <?php if(chatweb_admin_has_permission($conn,'users.block')){ ?>
    <form class="action" method="POST" onsubmit="return confirm('Block this user and revoke sessions?')"><h3>Block User</h3><textarea name="reason" placeholder="Reason" required></textarea><input type="hidden" name="action" value="block"><button class="btn danger">Block</button></form>
    <?php } ?>
    <?php if(chatweb_admin_has_permission($conn,'users.suspend')){ ?>
    <form class="action" method="POST" onsubmit="return confirm('Suspend this user and revoke sessions?')"><h3>Suspend User</h3><input type="datetime-local" name="suspended_until" required><textarea name="reason" placeholder="Reason" required></textarea><input type="hidden" name="action" value="suspend"><button class="btn warn">Suspend</button></form>
    <?php } ?>
    <?php if(chatweb_admin_has_permission($conn,'users.deactivate')){ ?>
    <form class="action" method="POST" onsubmit="return confirm('Deactivate this user?')"><h3>Deactivate User</h3><textarea name="reason" placeholder="Reason" required></textarea><input type="hidden" name="action" value="deactivate"><button class="btn danger">Deactivate</button></form>
    <?php } ?>
    <?php if(chatweb_admin_has_permission($conn,'users.edit')){ ?>
    <form class="action" method="POST" onsubmit="return confirm('Restore this account?')"><h3>Restore Account</h3><textarea name="reason" placeholder="Reason"></textarea><input type="hidden" name="action" value="restore"><button class="btn ok">Restore</button></form>
    <?php } ?>
    <?php if(chatweb_admin_has_permission($conn,'sessions.revoke')){ ?>
    <form class="action" method="POST" onsubmit="return confirm('Log out all devices for this user?')"><h3>Sessions</h3><textarea name="reason" placeholder="Reason"></textarea><input type="hidden" name="action" value="revoke_sessions"><button class="btn warn">Log out all devices</button></form>
    <?php } ?>
    <?php if(chatweb_admin_has_permission($conn,'chat.view')){ ?>
    <div class="action"><h3>Chats</h3><p>Administrative read-only chat viewer. Every access is audited.</p><a class="btn" href="user_chats.php?id=<?php echo (int)$user['id']; ?>">View Chats</a></div>
    <?php } ?>
</section>
<h2>Account</h2>
<section class="info">
    <?php foreach (['id','uuid','full_name','username','email','phone_number','phone','country','detected_country','ip_address','phone_verified','email_verified','verification_method','account_status','blocked_at','block_reason','suspended_at','suspended_until','suspension_reason','deactivated_at','deactivation_reason','onboarding_completed','created_at','last_login_at','last_seen'] as $field) { ?>
        <div class="row"><span><?php echo htmlspecialchars($field); ?></span><strong><?php echo htmlspecialchars((string)($user[$field] ?? '-')); ?></strong></div>
    <?php } ?>
</section>
</main></body></html>
