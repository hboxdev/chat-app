<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/admin_helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$email = trim($argv[1] ?? '');
$password = $argv[2] ?? '';
$name = trim($argv[3] ?? 'Super Admin');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    fwrite(STDERR, "Usage: php scripts/create_super_admin.php email@example.com StrongPassword [Name]\n");
    exit(1);
}

chatweb_ensure_admin_schema($conn);

$role = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM roles WHERE name='SUPER_ADMIN' LIMIT 1"));
if (!$role) {
    fwrite(STDERR, "SUPER_ADMIN role was not found.\n");
    exit(1);
}

$roleId = (int) $role['id'];
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = mysqli_prepare($conn, "INSERT INTO admin_users (role_id, full_name, email, password, is_active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id), full_name=VALUES(full_name), password=VALUES(password), is_active=1");
mysqli_stmt_bind_param($stmt, 'isss', $roleId, $name, $email, $hash);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo "Super Admin ready: $email\n";
