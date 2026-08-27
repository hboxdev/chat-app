<?php
require __DIR__ . '/_guard.php';
chatweb_admin_require_permission($conn, 'admins.view');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        chatweb_admin_require_permission($conn, 'admins.create');
        $name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $role = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM roles WHERE id=$roleId LIMIT 1"));
        if (($role['name'] ?? '') === 'SUPER_ADMIN' && ($_SESSION['admin_role'] ?? '') !== 'SUPER_ADMIN') {
            http_response_code(403);
            exit('403 Forbidden');
        }
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || $roleId <= 0) {
            $message = 'Enter valid admin details. Password must be at least 8 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "INSERT INTO admin_users (role_id, full_name, email, password) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "isss", $roleId, $name, $email, $hash);
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Administrator created.';
                chatweb_admin_log($conn, 'ADMIN_CREATED', 'admin', (string) mysqli_insert_id($conn), ['email' => $email, 'role_id' => $roleId]);
            } else {
                $message = 'Could not create administrator. Email may already exist.';
            }
            mysqli_stmt_close($stmt);
        }
    }

    if ($action === 'toggle') {
        chatweb_admin_require_permission($conn, 'admins.disable');
        $adminId = (int) ($_POST['admin_id'] ?? 0);
        if ($adminId === (int) $_SESSION['admin_user_id']) {
            $message = 'You cannot disable your own admin account.';
        } else {
            mysqli_query($conn, "UPDATE admin_users SET is_active=1-is_active WHERE id=$adminId");
            mysqli_query($conn, "UPDATE admin_sessions SET revoked_at=NOW() WHERE admin_user_id=$adminId AND revoked_at IS NULL");
            chatweb_admin_log($conn, 'ADMIN_STATUS_TOGGLED', 'admin', (string) $adminId);
            $message = 'Administrator status updated.';
        }
    }
}

$roles = mysqli_query($conn, "SELECT id, name, label FROM roles WHERE name<>'USER' ORDER BY id");
$admins = mysqli_query($conn, "SELECT au.*, r.name role_name, r.label role_label FROM admin_users au LEFT JOIN roles r ON r.id=au.role_id ORDER BY au.id DESC");
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Administrators | Admin</title>
<style>body{margin:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827}.shell{display:flex;min-height:100dvh}.side{width:250px;background:#101828;color:#fff;padding:22px}.side a{display:block;color:#cbd5e1;text-decoration:none;padding:12px 14px;border-radius:8px;font-weight:800}.side a:hover{background:#1d2939;color:#fff}.main{flex:1;padding:28px}.panel{background:#fff;border:1px solid #dbe3ee;border-radius:8px;padding:18px;margin-bottom:16px;overflow:auto}input,select{min-height:42px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px;margin:4px}.btn{min-height:40px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:800;padding:0 14px}table{width:100%;border-collapse:collapse;min-width:720px}th,td{text-align:left;padding:12px;border-bottom:1px solid #eef2f7}@media(max-width:820px){.shell{display:block}.side{width:100%}.main{padding:16px}}</style></head>
<body><div class="shell"><aside class="side"><h2>Admin</h2><?php echo chatweb_admin_nav($conn); ?></aside><main class="main">
<h1>Administrators</h1><?php if($message){ ?><p class="panel"><?php echo htmlspecialchars($message); ?></p><?php } ?>
<?php if(chatweb_admin_has_permission($conn,'admins.create')){ ?><section class="panel"><h2>Create Admin</h2><form method="POST"><input type="hidden" name="action" value="create"><input name="full_name" placeholder="Full name" required><input type="email" name="email" placeholder="Email" required><input type="password" name="password" placeholder="Password" required><select name="role_id"><?php mysqli_data_seek($roles,0); while($r=mysqli_fetch_assoc($roles)){ ?><option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['label']); ?></option><?php } ?></select><button class="btn">Create</button></form></section><?php } ?>
<section class="panel"><h2>Admin Accounts</h2><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead><tbody><?php while($a=mysqli_fetch_assoc($admins)){ ?><tr><td><?php echo htmlspecialchars($a['full_name']); ?></td><td><?php echo htmlspecialchars($a['email']); ?></td><td><?php echo htmlspecialchars($a['role_label'] ?: '-'); ?></td><td><?php echo (int)$a['is_active'] ? 'Active' : 'Disabled'; ?></td><td><?php echo htmlspecialchars($a['last_login_at'] ?: '-'); ?></td><td><?php if(chatweb_admin_has_permission($conn,'admins.disable')){ ?><form method="POST"><input type="hidden" name="action" value="toggle"><input type="hidden" name="admin_id" value="<?php echo (int)$a['id']; ?>"><button class="btn" onclick="return confirm('Change admin status?')">Toggle</button></form><?php } ?></td></tr><?php } ?></tbody></table></section>
</main></div></body></html>

