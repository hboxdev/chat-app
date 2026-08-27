<?php
require __DIR__ . '/_guard.php';
chatweb_admin_require_permission($conn, 'roles.view');

$roles = mysqli_query($conn, "SELECT * FROM roles ORDER BY id");
$permissions = mysqli_query($conn, "SELECT * FROM permissions ORDER BY name");
$rolePerms = [];
$rp = mysqli_query($conn, "SELECT r.name role_name, p.name permission FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id");
while ($row = mysqli_fetch_assoc($rp)) {
    $rolePerms[$row['role_name']][$row['permission']] = true;
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Roles | Admin</title>
<style>body{margin:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827}.shell{display:flex;min-height:100dvh}.side{width:250px;background:#101828;color:#fff;padding:22px}.side a{display:block;color:#cbd5e1;text-decoration:none;padding:12px 14px;border-radius:8px;font-weight:800}.side a:hover{background:#1d2939;color:#fff}.main{flex:1;padding:28px}.panel{background:#fff;border:1px solid #dbe3ee;border-radius:8px;padding:18px;margin-bottom:16px;overflow:auto}.badge{display:inline-block;padding:5px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;margin:3px;font-size:12px;font-weight:800}@media(max-width:820px){.shell{display:block}.side{width:100%}.main{padding:16px}}</style></head>
<body><div class="shell"><aside class="side"><h2>Admin</h2><?php echo chatweb_admin_nav($conn); ?></aside><main class="main"><h1>Roles & Permissions</h1><p>Central RBAC registry. Super Admin receives all permissions automatically.</p>
<?php while($role=mysqli_fetch_assoc($roles)){ ?><section class="panel"><h2><?php echo htmlspecialchars($role['label']); ?></h2><?php mysqli_data_seek($permissions,0); while($perm=mysqli_fetch_assoc($permissions)){ if(!empty($rolePerms[$role['name']][$perm['name']]) || $role['name']==='SUPER_ADMIN'){ ?><span class="badge"><?php echo htmlspecialchars($perm['name']); ?></span><?php } } ?></section><?php } ?>
</main></div></body></html>

