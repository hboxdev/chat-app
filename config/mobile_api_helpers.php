<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helpers.php';

chatweb_ensure_auth_schema($conn);
chatweb_ensure_mobile_schema($conn);

function chatweb_mobile_json($payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

function chatweb_mobile_ensure_schema($conn)
{
    chatweb_ensure_mobile_schema($conn);
}

function chatweb_ensure_mobile_schema($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mobile_auth_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        device_name VARCHAR(180) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_mobile_token_hash (token_hash),
        KEY idx_mobile_token_user (user_id),
        CONSTRAINT fk_mobile_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function chatweb_mobile_request_data()
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $json = json_decode(file_get_contents('php://input'), true);
        return is_array($json) ? $json : [];
    }

    return $_POST;
}

function chatweb_mobile_issue_token($conn, $userId, $deviceName = '')
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + CHATWEB_REMEMBER_DAYS * 86400);
    $stmt = mysqli_prepare($conn, "INSERT INTO mobile_auth_tokens (user_id, token_hash, device_name, expires_at) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "isss", $userId, $hash, $deviceName, $expires);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $token;
}

function chatweb_mobile_user($conn)
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!preg_match('/Bearer\s+([a-f0-9]{64})/i', $header, $matches)) {
        chatweb_mobile_json(['ok' => false, 'error' => 'Missing auth token.'], 401);
    }

    $hash = hash('sha256', strtolower($matches[1]));
    $stmt = mysqli_prepare($conn, "
        SELECT u.*
        FROM mobile_auth_tokens t
        JOIN users u ON u.id=t.user_id
        WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at>NOW()
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "s", $hash);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$user || !chatweb_user_access_allowed($conn, (int) $user['id'])) {
        chatweb_mobile_json(['ok' => false, 'error' => 'Invalid auth token.'], 401);
    }

    mysqli_query($conn, "UPDATE mobile_auth_tokens SET last_used_at=NOW() WHERE token_hash='" . mysqli_real_escape_string($conn, $hash) . "'");
    return $user;
}

function chatweb_mobile_public_user($user)
{
    $image = $user['profile_image'] ?? '';
    return [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'] ?? '',
        'username' => $user['username_normalized'] ?: ($user['username'] ?? ''),
        'email' => $user['email'] ?? '',
        'phone_number' => $user['phone_number'] ?? ($user['phone'] ?? ''),
        'profile_image' => $image,
        'profile_image_url' => $image !== '' ? chatweb_mobile_base_url() . '/uploads/' . ltrim($image, '/') : '',
        'onboarding_completed' => (int) ($user['onboarding_completed'] ?? 0),
        'profile_completed' => (int) ($user['profile_completed'] ?? 0),
    ];
}

function chatweb_mobile_base_url()
{
    $https = chatweb_is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/\\');
    return $https . '://' . $host . ($base === '' ? '' : $base);
}
