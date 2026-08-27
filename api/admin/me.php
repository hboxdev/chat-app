<?php
require_once __DIR__ . '/_guard.php';

chatweb_admin_api_require($conn, 'admin.dashboard.view');

echo json_encode([
    'ok' => true,
    'admin' => [
        'id' => (int) $_SESSION['admin_user_id'],
        'name' => $_SESSION['admin_name'] ?? '',
        'email' => $_SESSION['admin_email'] ?? '',
        'role' => $_SESSION['admin_role'] ?? '',
        'permissions' => array_keys(chatweb_admin_permissions($conn)),
    ],
]);
