<?php

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Karachi');

const CHATWEB_REMEMBER_COOKIE = 'chatweb_remember';
const CHATWEB_REMEMBER_DAYS = 3650;
const CHATWEB_OTP_TTL_MINUTES = 10;
const CHATWEB_OTP_RESEND_SECONDS = 30;
const CHATWEB_OTP_MAX_ATTEMPTS = 5;

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

function chatweb_is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function chatweb_start_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        $secure = chatweb_is_https();
        session_set_cookie_params([
            'lifetime' => CHATWEB_REMEMBER_DAYS * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.gc_maxlifetime', (string) (CHATWEB_REMEMBER_DAYS * 86400));
        session_start();
    }
}

function chatweb_ensure_auth_schema($conn)
{
    $columns = [
        'phone_number' => "VARCHAR(32) DEFAULT NULL",
        'detected_country' => "VARCHAR(100) DEFAULT NULL",
        'ip_address' => "VARCHAR(64) DEFAULT NULL",
        'phone_verified' => "TINYINT(1) NOT NULL DEFAULT 0",
        'email_verified' => "TINYINT(1) NOT NULL DEFAULT 0",
        'verification_method' => "VARCHAR(20) DEFAULT NULL",
        'role' => "VARCHAR(30) NOT NULL DEFAULT 'USER'",
        'profile_completed' => "TINYINT(1) NOT NULL DEFAULT 0",
        'username_normalized' => "VARCHAR(100) DEFAULT NULL",
        'onboarding_step' => "VARCHAR(30) NOT NULL DEFAULT 'name'",
        'onboarding_completed' => "TINYINT(1) NOT NULL DEFAULT 0",
        'account_status' => "VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'",
        'blocked_at' => "DATETIME DEFAULT NULL",
        'blocked_by' => "INT UNSIGNED DEFAULT NULL",
        'block_reason' => "TEXT DEFAULT NULL",
        'suspended_at' => "DATETIME DEFAULT NULL",
        'suspended_until' => "DATETIME DEFAULT NULL",
        'suspended_by' => "INT UNSIGNED DEFAULT NULL",
        'suspension_reason' => "TEXT DEFAULT NULL",
        'deactivated_at' => "DATETIME DEFAULT NULL",
        'deactivated_by' => "INT UNSIGNED DEFAULT NULL",
        'deactivation_reason' => "TEXT DEFAULT NULL",
        'deleted_at' => "DATETIME DEFAULT NULL",
        'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
        'last_login_at' => "DATETIME DEFAULT NULL",
    ];

    foreach ($columns as $column => $definition) {
        $safe = mysqli_real_escape_string($conn, $column);
        $exists = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '$safe'");
        if ($exists && mysqli_num_rows($exists) === 0) {
            mysqli_query($conn, "ALTER TABLE users ADD COLUMN `$column` $definition");
        }
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS persistent_logins (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        selector CHAR(24) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        user_agent_hash CHAR(64) DEFAULT NULL,
        ip_address VARCHAR(64) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_selector (selector),
        KEY idx_persistent_user (user_id),
        CONSTRAINT fk_persistent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS otp_challenges (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        purpose VARCHAR(30) NOT NULL DEFAULT 'registration',
        email VARCHAR(150) DEFAULT NULL,
        phone_number VARCHAR(32) NOT NULL,
        country VARCHAR(100) NOT NULL,
        detected_country VARCHAR(100) DEFAULT NULL,
        ip_address VARCHAR(64) DEFAULT NULL,
        channel ENUM('sms','email') NOT NULL,
        target VARCHAR(180) NOT NULL,
        otp_hash VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
        expires_at DATETIME NOT NULL,
        next_resend_at DATETIME NOT NULL,
        consumed_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_otp_email (email),
        KEY idx_otp_phone (phone_number),
        KEY idx_otp_lookup (email, phone_number, consumed_at, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "ALTER TABLE otp_challenges MODIFY email VARCHAR(150) DEFAULT NULL");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS auth_rate_limits (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        action VARCHAR(40) NOT NULL,
        rate_key VARCHAR(190) NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 1,
        window_start DATETIME NOT NULL,
        blocked_until DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_action_key (action, rate_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS reserved_usernames (
        username VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT DEFAULT NULL,
        updated_by INT UNSIGNED DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
        ('site_name', 'Chat Web'),
        ('support_email', ''),
        ('registration_enabled', '1'),
        ('maintenance_mode', '0'),
        ('otp_ttl_minutes', '10'),
        ('otp_resend_seconds', '30'),
        ('otp_max_attempts', '5'),
        ('default_country', 'Pakistan'),
        ('email_from_name', 'Chat Web'),
        ('sms_sender_name', 'ChatWeb'),
        ('sms_api_url', ''),
        ('smtp_host', ''),
        ('smtp_port', '587'),
        ('smtp_encryption', 'tls'),
        ('smtp_username', ''),
        ('smtp_password', ''),
        ('smtp_from', ''),
        ('geoip_api_url', '')");

    mysqli_query($conn, "INSERT IGNORE INTO reserved_usernames (username) VALUES
        ('admin'), ('administrator'), ('support'), ('help'), ('webchat'),
        ('system'), ('api'), ('root'), ('moderator'), ('official')");

    mysqli_query($conn, "UPDATE users SET username_normalized=LOWER(username) WHERE username IS NOT NULL AND username<>'' AND (username_normalized IS NULL OR username_normalized='')");
    mysqli_query($conn, "UPDATE users SET onboarding_completed=1, onboarding_step='complete' WHERE profile_completed=1 AND onboarding_completed=0");
    $idx = mysqli_query($conn, "SHOW INDEX FROM users WHERE Key_name='unique_username_normalized'");
    if ($idx && mysqli_num_rows($idx) === 0) {
        mysqli_query($conn, "CREATE UNIQUE INDEX unique_username_normalized ON users (username_normalized)");
    }
}

function chatweb_app_setting($conn, $key, $default = '')
{
    $safe = mysqli_real_escape_string($conn, $key);
    $result = mysqli_query($conn, "SELECT setting_value FROM app_settings WHERE setting_key='$safe' LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        return $default;
    }
    $row = mysqli_fetch_assoc($result);
    return $row['setting_value'] ?? $default;
}

function chatweb_app_setting_int($conn, $key, $default, $min = null, $max = null)
{
    $value = (int) chatweb_app_setting($conn, $key, (string) $default);
    if ($min !== null) {
        $value = max($min, $value);
    }
    if ($max !== null) {
        $value = min($max, $value);
    }
    return $value;
}

function chatweb_set_app_setting($conn, $key, $value, $adminId = null)
{
    $stmt = mysqli_prepare($conn, "INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)");
    $adminId = $adminId !== null ? (int) $adminId : null;
    mysqli_stmt_bind_param($stmt, "ssi", $key, $value, $adminId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function chatweb_profile_setup_complete($conn, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }

    $result = mysqli_query($conn, "SELECT full_name, username, username_normalized, profile_completed, onboarding_completed FROM users WHERE id=$userId LIMIT 1");
    $user = $result ? mysqli_fetch_assoc($result) : [];
    if (!$user) {
        return false;
    }

    $username = trim((string) ($user['username_normalized'] ?: $user['username'] ?: ''));
    $name = trim((string) ($user['full_name'] ?? ''));
    if ($name === '' || str_starts_with($name, 'User ') || $username === '') {
        return false;
    }

    if (empty($user['username_normalized'])) {
        $safeUsername = mysqli_real_escape_string($conn, strtolower($username));
        mysqli_query($conn, "UPDATE users SET username_normalized='$safeUsername' WHERE id=$userId");
    }

    if (empty($user['profile_completed']) || empty($user['onboarding_completed'])) {
        mysqli_query($conn, "UPDATE users SET profile_completed=1, onboarding_completed=1, onboarding_step='complete' WHERE id=$userId");
        mysqli_query($conn, "INSERT IGNORE INTO user_profiles (user_id) VALUES ($userId)");
        mysqli_query($conn, "UPDATE user_profiles SET setup_completed_at=COALESCE(setup_completed_at, NOW()) WHERE user_id=$userId");
    }

    return true;
}

function chatweb_backend_config($conn, $settingKey, $envKey, $default = '')
{
    $value = chatweb_app_setting($conn, $settingKey, '');
    if ($value !== '') {
        return $value;
    }
    $envValue = getenv($envKey);
    return $envValue !== false && $envValue !== '' ? $envValue : $default;
}

function chatweb_client_ip()
{
    $candidates = [];
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            foreach (explode(',', $_SERVER[$key]) as $part) {
                $candidates[] = trim($part);
            }
        }
    }

    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }

    return filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : '';
}

function chatweb_detect_country($ip)
{
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }

    $base = isset($GLOBALS['conn'])
        ? chatweb_backend_config($GLOBALS['conn'], 'geoip_api_url', 'GEOIP_API_URL', 'http://ip-api.com/json/%s?fields=status,country')
        : (getenv('GEOIP_API_URL') ?: 'http://ip-api.com/json/%s?fields=status,country');
    $url = sprintf($base, rawurlencode($ip));
    $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $json = @file_get_contents($url, false, $context);
    if (!$json) {
        return '';
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return '';
    }

    return (($data['status'] ?? 'success') === 'success') ? trim((string) ($data['country'] ?? '')) : '';
}

function chatweb_normalize_phone($phone)
{
    $phone = trim((string) $phone);
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (strpos($phone, '00') === 0) {
        $phone = '+' . substr($phone, 2);
    }
    return $phone;
}

function chatweb_valid_phone($phone)
{
    return (bool) preg_match('/^\+[1-9]\d{7,14}$/', $phone);
}

function chatweb_normalize_username($username)
{
    return strtolower(trim((string) $username));
}

function chatweb_validate_username($conn, $username, $currentUserId = 0)
{
    $normalized = chatweb_normalize_username($username);

    if (!preg_match('/^[a-z0-9_\.]{3,24}$/', $normalized)) {
        return [
            'ok' => false,
            'available' => false,
            'normalized' => $normalized,
            'message' => 'Use 3-24 lowercase letters, numbers, underscore, or period.',
        ];
    }

    if (preg_match('/[._]{2,}/', $normalized) || $normalized[0] === '.' || substr($normalized, -1) === '.') {
        return [
            'ok' => false,
            'available' => false,
            'normalized' => $normalized,
            'message' => 'Username cannot start/end with a period or contain repeated separators.',
        ];
    }

    $stmt = mysqli_prepare($conn, "SELECT username FROM reserved_usernames WHERE username=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $normalized);
    mysqli_stmt_execute($stmt);
    $reserved = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
    if ($reserved) {
        return [
            'ok' => false,
            'available' => false,
            'normalized' => $normalized,
            'message' => 'This username is reserved.',
        ];
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username_normalized=? AND id<>? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "si", $normalized, $currentUserId);
    mysqli_stmt_execute($stmt);
    $taken = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);

    return [
        'ok' => !$taken,
        'available' => !$taken,
        'normalized' => $normalized,
        'message' => $taken ? 'Username already taken.' : 'Username available.',
    ];
}

function chatweb_available_username($conn, $preferred, $currentUserId = 0)
{
    $base = preg_replace('/[^a-z0-9_.]/', '', chatweb_normalize_username($preferred));
    $base = trim($base, '._');
    $base = preg_replace('/[._]{2,}/', '_', $base);
    if ($base === '' || strlen($base) < 3) {
        $base = 'user' . (int) $currentUserId;
    }
    $base = substr($base, 0, 24);

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = $attempt === 0 ? '' : (string) random_int(10, 9999);
        $candidate = substr($base, 0, 24 - strlen($suffix)) . $suffix;
        $validation = chatweb_validate_username($conn, $candidate, $currentUserId);
        if ($validation['available']) {
            return $validation['normalized'];
        }
    }

    return substr('user' . (int) $currentUserId . bin2hex(random_bytes(3)), 0, 24);
}

function chatweb_rate_limit($conn, $action, $key, $limit, $windowSeconds, $blockSeconds)
{
    return chatweb_rate_limit_hit($conn, $action, $key, $limit, $windowSeconds, $blockSeconds)['allowed'];
}

function chatweb_rate_limit_hit($conn, $action, $key, $limit, $windowSeconds, $blockSeconds)
{
    $rateKey = hash('sha256', $key);
    $stmt = mysqli_prepare($conn, "SELECT id, attempts, window_start, blocked_until FROM auth_rate_limits WHERE action=? AND rate_key=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $action, $rateKey);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($row && !empty($row['blocked_until']) && strtotime($row['blocked_until']) > time()) {
        return [
            'allowed' => false,
            'retry_after' => max(1, strtotime($row['blocked_until']) - time()),
            'attempts' => (int) $row['attempts'],
        ];
    }

    if (!$row || strtotime($row['window_start']) < time() - $windowSeconds) {
        $stmt = mysqli_prepare($conn, "REPLACE INTO auth_rate_limits (action, rate_key, attempts, window_start, blocked_until) VALUES (?, ?, 1, NOW(), NULL)");
        mysqli_stmt_bind_param($stmt, "ss", $action, $rateKey);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return ['allowed' => true, 'retry_after' => 0, 'attempts' => 1];
    }

    $attempts = (int) $row['attempts'] + 1;
    $blockedUntilSql = $attempts > $limit ? "DATE_ADD(NOW(), INTERVAL " . (int) $blockSeconds . " SECOND)" : "NULL";
    mysqli_query($conn, "UPDATE auth_rate_limits SET attempts=$attempts, blocked_until=$blockedUntilSql WHERE id=" . (int) $row['id']);

    return [
        'allowed' => $attempts <= $limit,
        'retry_after' => $attempts <= $limit ? 0 : $blockSeconds,
        'attempts' => $attempts,
    ];
}

function chatweb_rate_limit_decrement($conn, $action, $key)
{
    $rateKey = hash('sha256', $key);
    $stmt = mysqli_prepare($conn, "
        UPDATE auth_rate_limits
        SET attempts = GREATEST(attempts - 1, 0),
            blocked_until = NULL
        WHERE action=? AND rate_key=?
    ");
    mysqli_stmt_bind_param($stmt, "ss", $action, $rateKey);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    mysqli_query($conn, "DELETE FROM auth_rate_limits WHERE attempts=0");
}

function chatweb_format_retry_after($seconds)
{
    $seconds = max(1, (int) $seconds);
    if ($seconds < 60) {
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }

    $minutes = (int) ceil($seconds / 60);
    return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
}

function chatweb_cleanup_rate_limits($conn)
{
    mysqli_query($conn, "DELETE FROM auth_rate_limits WHERE blocked_until IS NOT NULL AND blocked_until < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    mysqli_query($conn, "DELETE FROM auth_rate_limits WHERE blocked_until IS NULL AND window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)");
}

function chatweb_active_otp_challenge($conn, $phone)
{
    $stmt = mysqli_prepare($conn, "
        SELECT *
        FROM otp_challenges
        WHERE phone_number=? AND consumed_at IS NULL AND expires_at>NOW()
        ORDER BY id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "s", $phone);
    mysqli_stmt_execute($stmt);
    $challenge = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $challenge ?: null;
}

function chatweb_load_user_session($conn, $user)
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['uuid'] = $user['uuid'] ?? '';
    $_SESSION['full_name'] = $user['full_name'] ?? '';
    $_SESSION['username'] = $user['username'] ?? '';
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['profile_image'] = $user['profile_image'] ?? '';
    $_SESSION['status'] = 'online';
    $_SESSION['is_active'] = $user['is_active'] ?? 1;

    $id = (int) $user['id'];
    mysqli_query($conn, "UPDATE users SET status='online', last_seen=NOW(), last_login=NOW(), last_login_at=NOW() WHERE id=$id");
}

function chatweb_issue_remember_cookie($conn, $userId)
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $uaHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = chatweb_client_ip();
    $expires = date('Y-m-d H:i:s', time() + CHATWEB_REMEMBER_DAYS * 86400);

    $stmt = mysqli_prepare($conn, "INSERT INTO persistent_logins (user_id, selector, token_hash, user_agent_hash, ip_address, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "isssss", $userId, $selector, $hash, $uaHash, $ip, $expires);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    setcookie(CHATWEB_REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires' => time() + CHATWEB_REMEMBER_DAYS * 86400,
        'path' => '/',
        'secure' => chatweb_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function chatweb_clear_remember_cookie($conn)
{
    if (!empty($_COOKIE[CHATWEB_REMEMBER_COOKIE])) {
        $parts = explode(':', $_COOKIE[CHATWEB_REMEMBER_COOKIE], 2);
        $selector = $parts[0] ?? '';
        if ($selector !== '') {
            $stmt = mysqli_prepare($conn, "UPDATE persistent_logins SET revoked_at=NOW() WHERE selector=?");
            mysqli_stmt_bind_param($stmt, "s", $selector);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    setcookie(CHATWEB_REMEMBER_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => chatweb_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function chatweb_restore_login($conn)
{
    if (!empty($_SESSION['user_id']) || empty($_COOKIE[CHATWEB_REMEMBER_COOKIE])) {
        return;
    }

    $parts = explode(':', $_COOKIE[CHATWEB_REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) {
        return;
    }

    [$selector, $validator] = $parts;
    $stmt = mysqli_prepare($conn, "SELECT pl.*, u.* FROM persistent_logins pl JOIN users u ON u.id=pl.user_id WHERE pl.selector=? AND pl.revoked_at IS NULL AND pl.expires_at>NOW() AND u.is_active=1 AND COALESCE(u.account_status,'ACTIVE')='ACTIVE' LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $selector);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row || !hash_equals($row['token_hash'], hash('sha256', $validator))) {
        return;
    }

    chatweb_load_user_session($conn, $row);
    mysqli_query($conn, "UPDATE persistent_logins SET last_used_at=NOW() WHERE id=" . (int) $row['id']);
}

function chatweb_user_access_allowed($conn, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }

    $user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT is_active, COALESCE(account_status,'ACTIVE') account_status, suspended_until FROM users WHERE id=$userId LIMIT 1"));
    if (!$user) {
        return false;
    }

    if ($user['account_status'] === 'SUSPENDED' && !empty($user['suspended_until']) && strtotime($user['suspended_until']) <= time()) {
        mysqli_query($conn, "UPDATE users SET account_status='ACTIVE', is_active=1, suspended_at=NULL, suspended_until=NULL, suspended_by=NULL, suspension_reason=NULL WHERE id=$userId");
        return true;
    }

    return (int) $user['is_active'] === 1 && $user['account_status'] === 'ACTIVE';
}

function chatweb_send_sms_otp($phone, $code)
{
    $url = isset($GLOBALS['conn']) ? chatweb_backend_config($GLOBALS['conn'], 'sms_api_url', 'SMS_API_URL') : (getenv('SMS_API_URL') ?: '');
    if ($url === '') {
        return false;
    }

    $payload = json_encode([
        'to' => $phone,
        'message' => "Your Chat Web verification code is $code",
    ]);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);

    return @file_get_contents($url, false, $context) !== false;
}

function chatweb_send_email_otp($email, $code)
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'Your Chat Web verification code';
    $ttlMinutes = isset($GLOBALS['conn']) ? chatweb_app_setting_int($GLOBALS['conn'], 'otp_ttl_minutes', CHATWEB_OTP_TTL_MINUTES, 1, 60) : CHATWEB_OTP_TTL_MINUTES;
    $body = "Your verification code is: $code\n\nThis code expires in " . $ttlMinutes . " minutes.";
    $from = isset($GLOBALS['conn']) ? chatweb_backend_config($GLOBALS['conn'], 'smtp_from', 'SMTP_FROM', getenv('MAIL_FROM') ?: 'no-reply@chatweb.local') : (getenv('SMTP_FROM') ?: (getenv('MAIL_FROM') ?: 'no-reply@chatweb.local'));
    $fromName = isset($GLOBALS['conn']) ? chatweb_backend_config($GLOBALS['conn'], 'email_from_name', 'SMTP_FROM_NAME', 'Chat Web') : (getenv('SMTP_FROM_NAME') ?: 'Chat Web');

    $hasSmtp = isset($GLOBALS['conn'])
        ? (chatweb_backend_config($GLOBALS['conn'], 'smtp_host', 'SMTP_HOST') !== '' || chatweb_backend_config($GLOBALS['conn'], 'smtp_username', 'SMTP_USERNAME') !== '')
        : (getenv('SMTP_HOST') || getenv('SMTP_USERNAME'));
    if ($hasSmtp) {
        return chatweb_send_smtp_mail($email, $subject, $body, $from, $fromName);
    }

    $headers = "From: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n";

    return @mail($email, $subject, $body, $headers);
}

function chatweb_smtp_read($socket)
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function chatweb_smtp_command($socket, $command, $expectedCodes)
{
    fwrite($socket, $command . "\r\n");
    $response = chatweb_smtp_read($socket);
    $code = (int) substr($response, 0, 3);
    return in_array($code, (array) $expectedCodes, true);
}

function chatweb_send_smtp_mail($to, $subject, $body, $from, $fromName)
{
    $db = $GLOBALS['conn'] ?? null;
    $host = $db ? chatweb_backend_config($db, 'smtp_host', 'SMTP_HOST') : (getenv('SMTP_HOST') ?: '');
    $port = (int) ($db ? chatweb_backend_config($db, 'smtp_port', 'SMTP_PORT', '587') : (getenv('SMTP_PORT') ?: 587));
    $username = $db ? chatweb_backend_config($db, 'smtp_username', 'SMTP_USERNAME') : (getenv('SMTP_USERNAME') ?: '');
    $password = $db ? chatweb_backend_config($db, 'smtp_password', 'SMTP_PASSWORD') : (getenv('SMTP_PASSWORD') ?: '');
    $encryption = strtolower($db ? chatweb_backend_config($db, 'smtp_encryption', 'SMTP_ENCRYPTION', 'tls') : (getenv('SMTP_ENCRYPTION') ?: 'tls'));

    if ($host === '' || $username === '' || $password === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('SMTP email not sent: SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD, or SMTP_FROM is missing.');
        return false;
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15);
    if (!$socket) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }

    stream_set_timeout($socket, 15);
    $greeting = chatweb_smtp_read($socket);
    if ((int) substr($greeting, 0, 3) !== 220) {
        fclose($socket);
        return false;
    }

    $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    if (!chatweb_smtp_command($socket, "EHLO $serverName", 250)) {
        fclose($socket);
        return false;
    }

    if ($encryption === 'tls') {
        if (!chatweb_smtp_command($socket, 'STARTTLS', 220)) {
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }
        if (!chatweb_smtp_command($socket, "EHLO $serverName", 250)) {
            fclose($socket);
            return false;
        }
    }

    if (!chatweb_smtp_command($socket, 'AUTH LOGIN', 334)
        || !chatweb_smtp_command($socket, base64_encode($username), 334)
        || !chatweb_smtp_command($socket, base64_encode($password), 235)) {
        fclose($socket);
        error_log('SMTP authentication failed.');
        return false;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $safeFromName = str_replace(['"', "\r", "\n"], '', $fromName);
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: "' . $safeFromName . '" <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body);

    $ok = chatweb_smtp_command($socket, 'MAIL FROM:<' . $from . '>', 250)
        && chatweb_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251])
        && chatweb_smtp_command($socket, 'DATA', 354)
        && chatweb_smtp_command($socket, $message . "\r\n.", 250);

    chatweb_smtp_command($socket, 'QUIT', 221);
    fclose($socket);

    return $ok;
}

function chatweb_is_local_dev()
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';

    return str_starts_with($host, 'localhost')
        || str_starts_with($host, '127.0.0.1')
        || $remote === '127.0.0.1'
        || $remote === '::1';
}

function chatweb_create_otp_challenge($conn, $email, $phone, $country, $detectedCountry, $ip)
{
    $code = (string) random_int(100000, 999999);
    $channel = chatweb_send_sms_otp($phone, $code) ? 'sms' : 'email';
    $target = $channel === 'sms' ? $phone : $email;

    if ($channel === 'email' && !chatweb_send_email_otp($email, $code)) {
        return ['ok' => false, 'message' => 'Email address is valid, but SMS/email delivery is not configured on this server. Please configure SMS_API_URL or server mail settings.'];
    }

    $hash = password_hash($code, PASSWORD_DEFAULT);
    $ttlMinutes = chatweb_app_setting_int($conn, 'otp_ttl_minutes', CHATWEB_OTP_TTL_MINUTES, 1, 60);
    $resendSeconds = chatweb_app_setting_int($conn, 'otp_resend_seconds', CHATWEB_OTP_RESEND_SECONDS, 15, 300);
    $maxAttempts = chatweb_app_setting_int($conn, 'otp_max_attempts', CHATWEB_OTP_MAX_ATTEMPTS, 3, 10);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlMinutes * 60);
    $nextResendAt = date('Y-m-d H:i:s', time() + $resendSeconds);
    $stmt = mysqli_prepare($conn, "INSERT INTO otp_challenges (email, phone_number, country, detected_country, ip_address, channel, target, otp_hash, max_attempts, expires_at, next_resend_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "ssssssssiss", $email, $phone, $country, $detectedCountry, $ip, $channel, $target, $hash, $maxAttempts, $expiresAt, $nextResendAt);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    return ['ok' => true, 'id' => $id, 'channel' => $channel, 'target' => $target];
}

function chatweb_redirect_if_logged_in($conn, $target)
{
    chatweb_restore_login($conn);
    if (!empty($_SESSION['user_id'])) {
        header("Location: $target");
        exit();
    }
}

function chatweb_require_login($conn, $loginPath = '../index.php')
{
    chatweb_restore_login($conn);
    if (empty($_SESSION['user_id'])) {
        header("Location: $loginPath");
        exit();
    }

    if (!chatweb_user_access_allowed($conn, (int) $_SESSION['user_id'])) {
        chatweb_clear_remember_cookie($conn);
        session_unset();
        header("Location: $loginPath?account=restricted");
        exit();
    }
}
