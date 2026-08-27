-- First phase authentication and onboarding support.
-- Reversible down migration notes are included at the bottom.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS phone_number VARCHAR(32) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS detected_country VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS phone_verified TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS verification_method VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS role VARCHAR(30) NOT NULL DEFAULT 'USER',
    ADD COLUMN IF NOT EXISTS profile_completed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS username_normalized VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS onboarding_step VARCHAR(30) NOT NULL DEFAULT 'name',
    ADD COLUMN IF NOT EXISTS onboarding_completed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS account_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
    ADD COLUMN IF NOT EXISTS blocked_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS blocked_by INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS block_reason TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS suspended_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS suspended_until DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS suspended_by INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS suspension_reason TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deactivated_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deactivated_by INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deactivation_reason TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME DEFAULT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS unique_username_normalized ON users (username_normalized);

CREATE TABLE IF NOT EXISTS persistent_logins (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS otp_challenges (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action VARCHAR(40) NOT NULL,
    rate_key VARCHAR(190) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    window_start DATETIME NOT NULL,
    blocked_until DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_action_key (action, rate_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reserved_usernames (
    username VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
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
    ('geoip_api_url', '');

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(40) NOT NULL,
    label VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    label VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_permission_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO roles (name, label) VALUES
    ('SUPER_ADMIN', 'Super Admin'),
    ('ADMIN', 'Admin'),
    ('SUPPORT', 'Support'),
    ('MODERATOR', 'Moderator'),
    ('USER', 'User');

INSERT IGNORE INTO permissions (name, label) VALUES
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
    ('system.manage', 'Manage system');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'SUPER_ADMIN';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'admin.dashboard.view', 'users.view', 'users.edit', 'users.block', 'users.suspend',
    'sessions.view', 'sessions.revoke', 'reports.view', 'reports.resolve',
    'audit.view', 'settings.view'
)
WHERE r.name = 'ADMIN';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'admin.dashboard.view', 'users.view', 'sessions.view', 'reports.view'
)
WHERE r.name = 'SUPPORT';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'admin.dashboard.view', 'users.view', 'users.suspend', 'chat.view',
    'chat.search', 'chat.moderate', 'reports.view', 'audit.view'
)
WHERE r.name = 'MODERATOR';

CREATE TABLE IF NOT EXISTS user_profiles (
    user_id INT(11) NOT NULL,
    display_name VARCHAR(150) DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    setup_completed_at DATETIME DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_sessions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_status_history (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS moderation_actions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Down migration, if needed:
-- DROP TABLE IF EXISTS auth_rate_limits;
-- DROP TABLE IF EXISTS reserved_usernames;
-- DROP TABLE IF EXISTS audit_logs;
-- DROP TABLE IF EXISTS moderation_actions;
-- DROP TABLE IF EXISTS user_status_history;
-- DROP TABLE IF EXISTS admin_sessions;
-- DROP TABLE IF EXISTS admin_users;
-- DROP TABLE IF EXISTS user_profiles;
-- DROP TABLE IF EXISTS role_permissions;
-- DROP TABLE IF EXISTS permissions;
-- DROP TABLE IF EXISTS roles;
-- DROP TABLE IF EXISTS otp_challenges;
-- DROP TABLE IF EXISTS persistent_logins;
-- ALTER TABLE users DROP COLUMN phone_number, DROP COLUMN detected_country, DROP COLUMN ip_address,
--     DROP COLUMN phone_verified, DROP COLUMN email_verified, DROP COLUMN verification_method,
--     DROP COLUMN role, DROP COLUMN profile_completed, DROP COLUMN username_normalized,
--     DROP COLUMN onboarding_step, DROP COLUMN onboarding_completed, DROP COLUMN account_status,
--     DROP COLUMN blocked_at, DROP COLUMN blocked_by, DROP COLUMN block_reason,
--     DROP COLUMN suspended_at, DROP COLUMN suspended_until, DROP COLUMN suspended_by, DROP COLUMN suspension_reason,
--     DROP COLUMN deactivated_at, DROP COLUMN deactivated_by, DROP COLUMN deactivation_reason, DROP COLUMN deleted_at,
--     DROP COLUMN updated_at, DROP COLUMN last_login_at;
