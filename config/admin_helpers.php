<?php

require_once __DIR__ . '/auth_helpers.php';

const CHATWEB_ADMIN_COOKIE = 'chatweb_admin_remember';

function chatweb_ensure_admin_schema($conn)
{
    chatweb_ensure_auth_schema($conn);

    $userColumns = [
        'role' => "VARCHAR(30) NOT NULL DEFAULT 'USER'",
        'profile_completed' => "TINYINT(1) NOT NULL DEFAULT 0",
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
    ];

    foreach ($userColumns as $column => $definition) {
        $safe = mysqli_real_escape_string($conn, $column);
        $exists = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '$safe'");
        if ($exists && mysqli_num_rows($exists) === 0) {
            mysqli_query($conn, "ALTER TABLE users ADD COLUMN `$column` $definition");
        }
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(40) NOT NULL,
        label VARCHAR(80) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_role_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS permissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(80) NOT NULL,
        label VARCHAR(120) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_permission_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS role_permissions (
        role_id INT UNSIGNED NOT NULL,
        permission_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (role_id, permission_id),
        CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "INSERT IGNORE INTO roles (name, label) VALUES
        ('SUPER_ADMIN', 'Super Admin'),
        ('ADMIN', 'Admin'),
        ('SUPPORT', 'Support'),
        ('MODERATOR', 'Moderator'),
        ('USER', 'User')");

    mysqli_query($conn, "INSERT IGNORE INTO permissions (name, label) VALUES
        ('admin.dashboard.view', 'View admin dashboard'),
        ('users.view', 'View users'),
        ('users.edit', 'Edit users'),
        ('users.block', 'Block users'),
        ('users.suspend', 'Suspend users'),
        ('users.deactivate', 'Deactivate users'),
        ('chat.view', 'View user chats'),
        ('chat.search', 'Search user chats'),
        ('chat.export', 'Export user chats'),
        ('chat.moderate', 'Moderate chats'),
        ('chat.delete', 'Delete messages'),
        ('sessions.view', 'View user sessions'),
        ('sessions.revoke', 'Revoke user sessions'),
        ('reports.view', 'View reports'),
        ('reports.resolve', 'Resolve reports'),
        ('admins.view', 'View administrators'),
        ('admins.create', 'Create administrators'),
        ('admins.edit', 'Edit administrators'),
        ('admins.disable', 'Disable administrators'),
        ('roles.view', 'View roles'),
        ('roles.edit', 'Edit roles'),
        ('settings.view', 'View admin settings'),
        ('settings.edit', 'Edit settings'),
        ('audit.view', 'View audit logs'),
        ('audit.export', 'Export audit logs'),
        ('system.view', 'View system'),
        ('system.manage', 'Manage system')");
    chatweb_seed_role_permissions($conn);

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_profiles (
        user_id INT(11) NOT NULL,
        display_name VARCHAR(150) DEFAULT NULL,
        avatar VARCHAR(255) DEFAULT NULL,
        setup_completed_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id),
        CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS admin_users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        role_id INT UNSIGNED DEFAULT NULL,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL,
        password VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_admin_email (email),
        KEY idx_admin_role (role_id),
        CONSTRAINT fk_admin_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS admin_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        admin_user_id INT UNSIGNED NOT NULL,
        selector CHAR(24) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        ip_address VARCHAR(64) DEFAULT NULL,
        user_agent_hash CHAR(64) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_admin_selector (selector),
        KEY idx_admin_sessions_user (admin_user_id),
        CONSTRAINT fk_admin_sessions_user FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        actor_type ENUM('user','admin','system') NOT NULL DEFAULT 'system',
        actor_id INT UNSIGNED DEFAULT NULL,
        action VARCHAR(120) NOT NULL,
        target_type VARCHAR(80) DEFAULT NULL,
        target_id VARCHAR(80) DEFAULT NULL,
        ip_address VARCHAR(64) DEFAULT NULL,
        metadata TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_audit_actor (actor_type, actor_id),
        KEY idx_audit_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_status_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        previous_status VARCHAR(30) DEFAULT NULL,
        new_status VARCHAR(30) NOT NULL,
        reason TEXT DEFAULT NULL,
        actor_admin_id INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_status_history_user (user_id),
        CONSTRAINT fk_status_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS moderation_actions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        admin_user_id INT UNSIGNED DEFAULT NULL,
        target_user_id INT(11) DEFAULT NULL,
        conversation_id INT(11) DEFAULT NULL,
        message_id BIGINT(20) DEFAULT NULL,
        action VARCHAR(80) NOT NULL,
        reason TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_moderation_target_user (target_user_id),
        KEY idx_moderation_conversation (conversation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    chatweb_bootstrap_admin($conn);
}

function chatweb_seed_role_permissions($conn)
{
    $super = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM roles WHERE name='SUPER_ADMIN' LIMIT 1"));
    $admin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM roles WHERE name='ADMIN' LIMIT 1"));
    $support = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM roles WHERE name='SUPPORT' LIMIT 1"));
    $moderator = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM roles WHERE name='MODERATOR' LIMIT 1"));

    if ($super) {
        mysqli_query($conn, "INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT " . (int) $super['id'] . ", id FROM permissions");
    }

    $sets = [
        (int) ($admin['id'] ?? 0) => ['admin.dashboard.view','users.view','users.edit','users.block','users.suspend','sessions.view','sessions.revoke','reports.view','audit.view','settings.view'],
        (int) ($support['id'] ?? 0) => ['admin.dashboard.view','users.view','sessions.view','reports.view'],
        (int) ($moderator['id'] ?? 0) => ['admin.dashboard.view','users.view','users.suspend','chat.view','chat.search','chat.moderate','reports.view','reports.resolve','audit.view'],
    ];

    foreach ($sets as $roleId => $permissions) {
        if ($roleId <= 0) {
            continue;
        }
        foreach ($permissions as $permission) {
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name=?");
            mysqli_stmt_bind_param($stmt, "is", $roleId, $permission);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

function chatweb_bootstrap_admin($conn)
{
    $email = getenv('ADMIN_BOOTSTRAP_EMAIL') ?: '';
    $password = getenv('ADMIN_BOOTSTRAP_PASSWORD') ?: '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        return;
    }

    $count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total FROM admin_users"))['total'] ?? 0;
    if ((int) $count > 0) {
        return;
    }

    $role = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM roles WHERE name='SUPER_ADMIN' LIMIT 1"));
    $roleId = (int) ($role['id'] ?? 0);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $name = 'Super Admin';
    $stmt = mysqli_prepare($conn, "INSERT INTO admin_users (role_id, full_name, email, password) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "isss", $roleId, $name, $email, $hash);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function chatweb_admin_log($conn, $action, $targetType = null, $targetId = null, $metadata = null)
{
    $actorId = (int) ($_SESSION['admin_user_id'] ?? 0);
    $ip = chatweb_client_ip();
    $meta = $metadata === null ? null : json_encode($metadata);
    $stmt = mysqli_prepare($conn, "INSERT INTO audit_logs (actor_type, actor_id, action, target_type, target_id, ip_address, metadata) VALUES ('admin', ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "isssss", $actorId, $action, $targetType, $targetId, $ip, $meta);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function chatweb_admin_permissions($conn, $adminId = null)
{
    $adminId = (int) ($adminId ?: ($_SESSION['admin_user_id'] ?? 0));
    if ($adminId <= 0) {
        return [];
    }

    $stmt = mysqli_prepare($conn, "
        SELECT r.name role_name, p.name permission
        FROM admin_users au
        LEFT JOIN roles r ON r.id=au.role_id
        LEFT JOIN role_permissions rp ON rp.role_id=r.id
        LEFT JOIN permissions p ON p.id=rp.permission_id
        WHERE au.id=? AND au.is_active=1
    ");
    mysqli_stmt_bind_param($stmt, "i", $adminId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $permissions = [];
    $role = '';
    while ($row = mysqli_fetch_assoc($result)) {
        $role = $row['role_name'] ?: $role;
        if (!empty($row['permission'])) {
            $permissions[$row['permission']] = true;
        }
    }
    mysqli_stmt_close($stmt);

    if ($role === 'SUPER_ADMIN') {
        $all = mysqli_query($conn, "SELECT name FROM permissions");
        while ($row = mysqli_fetch_assoc($all)) {
            $permissions[$row['name']] = true;
        }
    }

    return $permissions;
}

function chatweb_admin_has_permission($conn, $permission)
{
    $permissions = $_SESSION['admin_permissions'] ?? null;
    if (!is_array($permissions)) {
        $permissions = chatweb_admin_permissions($conn);
        $_SESSION['admin_permissions'] = $permissions;
    }
    return !empty($permissions[$permission]) || (($_SESSION['admin_role'] ?? '') === 'SUPER_ADMIN');
}

function chatweb_admin_require_permission($conn, $permission)
{
    chatweb_admin_require($conn);
    if (!chatweb_admin_has_permission($conn, $permission)) {
        http_response_code(403);
        echo "403 Forbidden";
        exit();
    }
}

function chatweb_admin_nav($conn)
{
    $sections = [
        'Dashboard' => [
            ['Dashboard', 'index.php', 'admin.dashboard.view'],
        ],
        'List' => [
            ['Report List', 'reports.php', 'reports.view'],
            ['User List', 'users.php', 'users.view'],
            ['Countrywise User List', 'countrywise_users.php', 'users.view'],
            ['Group List', 'groups.php', 'users.view'],
            ['Language', 'language.php', 'settings.view'],
            ['Block List', 'blocked_users.php', 'users.view'],
            ['Avatar', 'avatar.php', 'settings.view'],
        ],
        'Calls' => [
            ['Calls', 'calls.php', 'system.view'],
        ],
        'Notification' => [
            ['Notification', 'notifications.php', 'settings.view'],
        ],
        'Management' => [
            ['Administrators', 'administrators.php', 'admins.view'],
            ['Roles & Permissions', 'roles.php', 'roles.view'],
            ['Audit Logs', 'logs.php', 'audit.view'],
        ],
        'Setting' => [
            ['Settings', 'settings.php', 'settings.view'],
            ['CMS Pages', 'cms_pages.php', 'settings.view'],
        ],
    ];
    $html = '';
    $current = basename($_SERVER['PHP_SELF'] ?? '');
    foreach ($sections as $section => $items) {
        $sectionHtml = '';
        foreach ($items as [$label, $href, $permission]) {
            if (chatweb_admin_has_permission($conn, $permission)) {
                $active = basename($href) === $current ? ' class="active"' : '';
                $sectionHtml .= '<a href="' . htmlspecialchars($href) . '"' . $active . '>' . htmlspecialchars($label) . '</a>';
            }
        }
        if ($sectionHtml !== '') {
            $html .= '<span class="nav-section">' . htmlspecialchars($section) . '</span>' . $sectionHtml;
        }
    }
    $html .= '<a href="logout.php">Logout</a>';
    return $html;
}

function chatweb_admin_load_session($conn, $admin)
{
    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = (int) $admin['id'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_name'] = $admin['full_name'];
    $_SESSION['admin_role'] = $admin['role_name'] ?? 'ADMIN';
    unset($_SESSION['admin_permissions']);
    mysqli_query($conn, "UPDATE admin_users SET last_login_at=NOW() WHERE id=" . (int) $admin['id']);
}

function chatweb_admin_issue_cookie($conn, $adminId)
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $uaHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = chatweb_client_ip();
    $expires = date('Y-m-d H:i:s', time() + CHATWEB_REMEMBER_DAYS * 86400);
    $stmt = mysqli_prepare($conn, "INSERT INTO admin_sessions (admin_user_id, selector, token_hash, ip_address, user_agent_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "isssss", $adminId, $selector, $hash, $ip, $uaHash, $expires);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    setcookie(CHATWEB_ADMIN_COOKIE, $selector . ':' . $validator, [
        'expires' => time() + CHATWEB_REMEMBER_DAYS * 86400,
        'path' => '/admin',
        'secure' => chatweb_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function chatweb_admin_restore($conn)
{
    if (!empty($_SESSION['admin_user_id']) || empty($_COOKIE[CHATWEB_ADMIN_COOKIE])) {
        return;
    }

    $parts = explode(':', $_COOKIE[CHATWEB_ADMIN_COOKIE], 2);
    if (count($parts) !== 2) {
        return;
    }

    [$selector, $validator] = $parts;
    $stmt = mysqli_prepare($conn, "
        SELECT au.*, r.name role_name
        FROM admin_sessions s
        JOIN admin_users au ON au.id=s.admin_user_id
        LEFT JOIN roles r ON r.id=au.role_id
        WHERE s.selector=? AND s.revoked_at IS NULL AND s.expires_at>NOW() AND au.is_active=1
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "s", $selector);
    mysqli_stmt_execute($stmt);
    $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$admin || !hash_equals($admin['token_hash'], hash('sha256', $validator))) {
        return;
    }

    chatweb_admin_load_session($conn, $admin);
}

function chatweb_admin_require($conn)
{
    chatweb_admin_restore($conn);
    if (empty($_SESSION['admin_user_id'])) {
        header("Location: login.php");
        exit();
    }
}

function chatweb_revoke_user_sessions($conn, $userId)
{
    $userId = (int) $userId;
    mysqli_query($conn, "UPDATE persistent_logins SET revoked_at=NOW() WHERE user_id=$userId AND revoked_at IS NULL");
    mysqli_query($conn, "UPDATE users SET status='offline', last_seen=NOW() WHERE id=$userId");
}

function chatweb_set_user_account_status($conn, $userId, $status, $reason = '', $until = null)
{
    $userId = (int) $userId;
    $adminId = (int) ($_SESSION['admin_user_id'] ?? 0);
    $current = mysqli_fetch_assoc(mysqli_query($conn, "SELECT account_status FROM users WHERE id=$userId LIMIT 1"));
    $previous = $current['account_status'] ?? 'ACTIVE';
    $allowed = ['ACTIVE','BLOCKED','SUSPENDED','DEACTIVATED'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }

    if ($status === 'BLOCKED') {
        $stmt = mysqli_prepare($conn, "UPDATE users SET account_status='BLOCKED', is_active=0, blocked_at=NOW(), blocked_by=?, block_reason=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "isi", $adminId, $reason, $userId);
    } elseif ($status === 'SUSPENDED') {
        $stmt = mysqli_prepare($conn, "UPDATE users SET account_status='SUSPENDED', is_active=0, suspended_at=NOW(), suspended_until=?, suspended_by=?, suspension_reason=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "sisi", $until, $adminId, $reason, $userId);
    } elseif ($status === 'DEACTIVATED') {
        $stmt = mysqli_prepare($conn, "UPDATE users SET account_status='DEACTIVATED', is_active=0, deactivated_at=NOW(), deactivated_by=?, deactivation_reason=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "isi", $adminId, $reason, $userId);
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE users SET account_status='ACTIVE', is_active=1, blocked_at=NULL, blocked_by=NULL, block_reason=NULL, suspended_at=NULL, suspended_until=NULL, suspended_by=NULL, suspension_reason=NULL WHERE id=?");
        mysqli_stmt_bind_param($stmt, "i", $userId);
    }
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if ($ok) {
        if ($status !== 'ACTIVE') {
            chatweb_revoke_user_sessions($conn, $userId);
        }
        $stmt = mysqli_prepare($conn, "INSERT INTO user_status_history (user_id, previous_status, new_status, reason, actor_admin_id) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "isssi", $userId, $previous, $status, $reason, $adminId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        chatweb_admin_log($conn, 'USER_' . $status, 'user', (string) $userId, ['reason' => $reason, 'until' => $until]);
    }

    return $ok;
}

function chatweb_admin_clear_cookie($conn)
{
    if (!empty($_COOKIE[CHATWEB_ADMIN_COOKIE])) {
        $parts = explode(':', $_COOKIE[CHATWEB_ADMIN_COOKIE], 2);
        $selector = $parts[0] ?? '';
        if ($selector !== '') {
            $stmt = mysqli_prepare($conn, "UPDATE admin_sessions SET revoked_at=NOW() WHERE selector=?");
            mysqli_stmt_bind_param($stmt, "s", $selector);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    setcookie(CHATWEB_ADMIN_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/admin',
        'secure' => chatweb_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
