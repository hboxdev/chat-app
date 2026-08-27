<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";

chatweb_ensure_auth_schema($conn);
chatweb_require_login($conn, '../index.php');

header('Content-Type: application/json');

$username = $_GET['username'] ?? '';
$currentUserId = (int) $_SESSION['user_id'];
$result = chatweb_validate_username($conn, $username, $currentUserId);

echo json_encode([
    'available' => $result['available'],
    'normalized' => $result['normalized'],
    'message' => $result['message'],
]);

