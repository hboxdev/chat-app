<?php
require __DIR__ . '/_guard.php';
chatweb_admin_require_permission($conn, 'users.view');

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$like = '%' . $search . '%';
$where = [];
$params = [];
$types = '';

if ($search !== '') {
    if (ctype_digit($search)) {
        $where[] = "(id=? OR full_name LIKE ? OR email LIKE ? OR phone_number LIKE ? OR phone LIKE ? OR username LIKE ?)";
        $idSearch = (int) $search;
        array_push($params, $idSearch, $like, $like, $like, $like, $like);
        $types .= 'isssss';
    } else {
        $where[] = "(full_name LIKE ? OR email LIKE ? OR phone_number LIKE ? OR phone LIKE ? OR username LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
        $types .= 'sssss';
    }
}

if ($statusFilter !== '' && in_array($statusFilter, ['ACTIVE','BLOCKED','SUSPENDED','DEACTIVATED','PENDING_VERIFICATION'], true)) {
    $where[] = "COALESCE(account_status,'ACTIVE')=?";
    $params[] = $statusFilter;
    $types .= 's';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$countSql = "SELECT COUNT(*) total FROM users $whereSql";
$stmt = mysqli_prepare($conn, $countSql);
if ($params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$total = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);
mysqli_stmt_close($stmt);

$sql = "SELECT id, full_name, username, email, phone_number, phone, country, status, COALESCE(account_status,'ACTIVE') account_status, is_active, phone_verified, email_verified, onboarding_completed, created_at FROM users $whereSql ORDER BY id DESC LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($conn, $sql);
$paramsWithLimit = array_merge($params, [$perPage, $offset]);
$typesWithLimit = $types . 'ii';
mysqli_stmt_bind_param($stmt, $typesWithLimit, ...$paramsWithLimit);
mysqli_stmt_execute($stmt);
$users = mysqli_stmt_get_result($stmt);
$totalPages = max(1, (int) ceil($total / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users | Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827}.shell{min-height:100dvh;display:flex}.side{width:250px;background:#101828;color:#fff;padding:22px}.side a{display:block;color:#cbd5e1;text-decoration:none;padding:12px 14px;border-radius:8px;font-weight:800}.side a.active,.side a:hover{background:#1d2939;color:#fff}.main{flex:1;min-width:0;padding:28px}.bar{display:flex;justify-content:space-between;gap:14px;align-items:center;margin-bottom:18px}.search{display:flex;gap:8px}.search input{min-height:42px;border:1px solid #cbd5e1;border-radius:8px;padding:0 12px}.search button{border:0;border-radius:8px;background:#2563eb;color:#fff;padding:0 16px;font-weight:800}.panel{background:#fff;border:1px solid #dbe3ee;border-radius:8px;box-shadow:0 14px 34px rgba(15,23,42,.07);overflow:auto}table{width:100%;border-collapse:collapse;min-width:860px}th,td{text-align:left;padding:14px;border-bottom:1px solid #eef2f7}th{font-size:13px;color:#64748b}.badge{display:inline-flex;padding:5px 9px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:800;font-size:12px}@media(max-width:800px){.shell{display:block}.side{width:100%}.bar{display:block}.main{padding:16px}}
</style>
</head>
<body><div class="shell">
<aside class="side"><h2>Admin</h2><?php echo chatweb_admin_nav($conn); ?></aside>
<main class="main">
    <div class="bar">
        <div><h1>Users</h1><p>Search, inspect, and manage WebChat users.</p></div>
        <form class="search" method="GET">
            <input name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="ID, username, phone, email">
            <select name="status"><option value="">All statuses</option><?php foreach(['ACTIVE','SUSPENDED','BLOCKED','DEACTIVATED','PENDING_VERIFICATION'] as $s){ ?><option value="<?php echo $s; ?>" <?php echo $statusFilter===$s?'selected':''; ?>><?php echo $s; ?></option><?php } ?></select>
            <button>Search</button>
        </form>
    </div>
    <section class="panel"><table>
        <thead><tr><th>User</th><th>Phone</th><th>Country</th><th>Verified</th><th>Onboarding</th><th>Status</th><th>Joined</th><th></th></tr></thead>
        <tbody>
        <?php while ($user = mysqli_fetch_assoc($users)) { ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($user['full_name'] ?: 'User'); ?></strong><br><small>@<?php echo htmlspecialchars($user['username'] ?: '-'); ?> · <?php echo htmlspecialchars($user['email'] ?: '-'); ?></small></td>
                <td><?php echo htmlspecialchars($user['phone_number'] ?: $user['phone'] ?: '-'); ?></td>
                <td><?php echo htmlspecialchars($user['country'] ?: '-'); ?></td>
                <td><span class="badge"><?php echo ((int)$user['phone_verified'] || (int)$user['email_verified']) ? 'Verified' : 'Pending'; ?></span></td>
                <td><span class="badge"><?php echo (int)$user['onboarding_completed'] ? 'Complete' : 'Incomplete'; ?></span></td>
                <td><?php echo htmlspecialchars($user['account_status']); ?><br><small><?php echo htmlspecialchars($user['status'] ?: 'offline'); ?></small></td>
                <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                <td><a href="user.php?id=<?php echo (int) $user['id']; ?>">View</a></td>
            </tr>
        <?php } ?>
        </tbody>
    </table></section>
    <p>Page <?php echo $page; ?> of <?php echo $totalPages; ?> · <?php echo $total; ?> users
    <?php if($page>1){ ?><a href="?q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $page-1; ?>">Previous</a><?php } ?>
    <?php if($page<$totalPages){ ?><a href="?q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $page+1; ?>">Next</a><?php } ?></p>
</main></div></body></html>
