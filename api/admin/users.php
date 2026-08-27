<?php
require_once __DIR__ . '/_guard.php';

chatweb_admin_api_require($conn, 'users.view');

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;
$where = ['deleted_at IS NULL'];
$types = '';
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(CAST(id AS CHAR) = ? OR full_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone_number LIKE ?)';
    $types .= 'sssss';
    array_push($params, $q, $like, $like, $like, $like);
}

if ($status !== '') {
    $where[] = 'account_status = ?';
    $types .= 's';
    $params[] = $status;
}

$whereSql = implode(' AND ', $where);
$countSql = "SELECT COUNT(*) total FROM users WHERE $whereSql";
$stmt = mysqli_prepare($conn, $countSql);
if ($types) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$total = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);
mysqli_stmt_close($stmt);

$sql = "SELECT id, full_name, username, email, phone_number, detected_country, account_status, phone_verified, email_verified, created_at, last_login_at
        FROM users
        WHERE $whereSql
        ORDER BY id DESC
        LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($conn, $sql);
$listTypes = $types . 'ii';
$listParams = array_merge($params, [$limit, $offset]);
mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $users[] = $row;
}
mysqli_stmt_close($stmt);

echo json_encode([
    'ok' => true,
    'page' => $page,
    'limit' => $limit,
    'total' => $total,
    'users' => $users,
]);
