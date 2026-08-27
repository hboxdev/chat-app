<?php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/admin_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

chatweb_ensure_admin_schema($conn);
chatweb_admin_restore($conn);

function chatweb_admin_api_error($status, $message)
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit();
}

function chatweb_admin_api_require($conn, $permission = null)
{
    if (empty($_SESSION['admin_user_id'])) {
        chatweb_admin_api_error(401, 'Admin authentication required.');
    }

    if ($permission && !chatweb_admin_has_permission($conn, $permission)) {
        chatweb_admin_api_error(403, 'You do not have permission to access this admin API.');
    }
}
