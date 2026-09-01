<?php
require_once __DIR__ . '/../../config/mobile_api_helpers.php';

$user = chatweb_mobile_user($conn);
chatweb_mobile_json([
    'ok' => true,
    'user' => chatweb_mobile_public_user($user),
    'setup_complete' => chatweb_profile_setup_complete($conn, (int) $user['id']),
]);

