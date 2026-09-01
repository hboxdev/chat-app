<?php
require_once __DIR__ . '/../../config/mobile_api_helpers.php';

$user = chatweb_mobile_user($conn);
$username = $_GET['username'] ?? (chatweb_mobile_request_data()['username'] ?? '');
$result = chatweb_validate_username($conn, $username, (int) $user['id']);
chatweb_mobile_json([
    'ok' => true,
    'available' => (bool) $result['available'],
    'message' => $result['message'],
    'normalized' => $result['normalized'],
]);

