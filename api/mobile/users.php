<?php
require_once __DIR__ . '/../../config/mobile_api_helpers.php';

$user = chatweb_mobile_user($conn);
$userId = (int) $user['id'];
$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    chatweb_mobile_json(['ok' => true, 'users' => []]);
}

$like = '%' . $q . '%';
$stmt = mysqli_prepare($conn, "
    SELECT id, full_name, username, username_normalized, profile_image, status
    FROM users
    WHERE id<>? AND is_active=1 AND (full_name LIKE ? OR username LIKE ? OR username_normalized LIKE ? OR phone_number LIKE ?)
    ORDER BY full_name ASC
    LIMIT 30
");
mysqli_stmt_bind_param($stmt, "issss", $userId, $like, $like, $like, $like);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $image = $row['profile_image'] ?? '';
    $users[] = [
        'id' => (int) $row['id'],
        'full_name' => $row['full_name'],
        'username' => $row['username_normalized'] ?: $row['username'],
        'profile_image_url' => $image ? chatweb_mobile_base_url() . '/uploads/' . ltrim($image, '/') : '',
        'status' => $row['status'],
    ];
}
mysqli_stmt_close($stmt);

chatweb_mobile_json(['ok' => true, 'users' => $users]);

