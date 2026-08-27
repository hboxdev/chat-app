<?php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

chatweb_restore_login($conn);

function chatweb_user_api_error($status, $message)
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit();
}

function chatweb_user_api_require($conn)
{
    if (empty($_SESSION['user_id'])) {
        chatweb_user_api_error(401, 'User authentication required.');
    }

    if (!chatweb_user_access_allowed($conn, (int) $_SESSION['user_id'])) {
        chatweb_clear_remember_cookie($conn);
        session_unset();
        chatweb_user_api_error(403, 'This account cannot access WebChat.');
    }
}
