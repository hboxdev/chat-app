<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json");

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

$current_user = (int) $_SESSION['user_id'];

function json_response($payload)
{
    echo json_encode($payload);
    exit();
}

function ensure_column($conn, $table, $column, $definition)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $exists = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");

    if ($exists && mysqli_num_rows($exists) === 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensure_settings_schema($conn)
{
    ensure_column($conn, "users", "about", "varchar(255) NULL");
    ensure_column($conn, "users", "phone", "varchar(50) NULL");
    ensure_column($conn, "users", "date_of_birth", "date NULL");
    ensure_column($conn, "users", "country", "varchar(100) NULL");
    ensure_column($conn, "users", "timezone", "varchar(80) NULL");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS user_settings (
            user_id INT PRIMARY KEY,
            language VARCHAR(30) DEFAULT 'English',
            time_format VARCHAR(20) DEFAULT '12 hour',
            auto_download_images TINYINT(1) DEFAULT 1,
            auto_download_videos TINYINT(1) DEFAULT 0,
            auto_download_documents TINYINT(1) DEFAULT 0,
            save_sent_media TINYINT(1) DEFAULT 1,
            enter_to_send TINYINT(1) DEFAULT 1,
            ctrl_enter_send TINYINT(1) DEFAULT 0,
            autoplay_gifs TINYINT(1) DEFAULT 1,
            autoplay_videos TINYINT(1) DEFAULT 0,
            message_preview TINYINT(1) DEFAULT 1,
            keep_chat_history TINYINT(1) DEFAULT 1,
            archive_inactive TINYINT(1) DEFAULT 0,
            delete_after VARCHAR(20) DEFAULT 'Never',
            debug_mode TINYINT(1) DEFAULT 0,
            websocket_enabled TINYINT(1) DEFAULT 0,
            polling_interval INT DEFAULT 3000,
            api_endpoint VARCHAR(255) DEFAULT '../ajax/',
            clear_local_storage TINYINT(1) DEFAULT 0,
            reset_ui_settings TINYINT(1) DEFAULT 0,
            performance_mode TINYINT(1) DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS notification_preferences (
            user_id INT PRIMARY KEY,
            message_notifications TINYINT(1) DEFAULT 1,
            group_notifications TINYINT(1) DEFAULT 1,
            call_notifications TINYINT(1) DEFAULT 1,
            desktop_notifications TINYINT(1) DEFAULT 0,
            browser_push TINYINT(1) DEFAULT 0,
            notification_sound TINYINT(1) DEFAULT 1,
            vibrate_mobile TINYINT(1) DEFAULT 1,
            preview_message TINYINT(1) DEFAULT 1,
            mute_all_chats TINYINT(1) DEFAULT 0,
            mute_specific_users VARCHAR(255) DEFAULT '',
            notification_volume INT DEFAULT 70,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS privacy_settings (
            user_id INT PRIMARY KEY,
            last_seen_visibility VARCHAR(20) DEFAULT 'Everyone',
            profile_photo_visibility VARCHAR(20) DEFAULT 'Everyone',
            about_visibility VARCHAR(20) DEFAULT 'Everyone',
            read_receipts TINYINT(1) DEFAULT 1,
            typing_indicator TINYINT(1) DEFAULT 1,
            online_status_visibility VARCHAR(20) DEFAULT 'Everyone',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_privacy_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS theme_settings (
            user_id INT PRIMARY KEY,
            theme VARCHAR(20) DEFAULT 'Light',
            accent_color VARCHAR(20) DEFAULT 'Blue',
            wallpaper_type VARCHAR(30) DEFAULT 'Solid',
            wallpaper_value VARCHAR(255) DEFAULT '#F5F7FB',
            font_size VARCHAR(20) DEFAULT 'Medium',
            compact_mode TINYINT(1) DEFAULT 0,
            bubble_style VARCHAR(30) DEFAULT 'Rounded',
            enable_animations TINYINT(1) DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_theme_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS blocked_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            blocked_user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_block (user_id, blocked_user_id),
            INDEX idx_blocked_user (blocked_user_id),
            CONSTRAINT fk_blocked_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_blocked_users_blocked FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            browser VARCHAR(120) DEFAULT '',
            ip_address VARCHAR(60) DEFAULT '',
            device VARCHAR(120) DEFAULT '',
            last_active DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_sessions_user (user_id),
            CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS user_backups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            backup_name VARCHAR(160) DEFAULT '',
            include_media TINYINT(1) DEFAULT 1,
            status VARCHAR(30) DEFAULT 'ready',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_backups_user (user_id),
            CONSTRAINT fk_user_backups_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensure_default_rows($conn, $user_id)
{
    mysqli_query($conn, "INSERT IGNORE INTO user_settings (user_id) VALUES ($user_id)");
    mysqli_query($conn, "INSERT IGNORE INTO notification_preferences (user_id) VALUES ($user_id)");
    mysqli_query($conn, "INSERT IGNORE INTO privacy_settings (user_id) VALUES ($user_id)");
    mysqli_query($conn, "INSERT IGNORE INTO theme_settings (user_id) VALUES ($user_id)");
}

function bool_value($value)
{
    return in_array((string) $value, ["1", "true", "on", "yes"], true) ? 1 : 0;
}

function update_table($conn, $table, $user_id, $allowed, $source)
{
    $sets = [];
    $types = "";
    $values = [];

    foreach ($allowed as $field => $type) {
        if (!array_key_exists($field, $source)) {
            continue;
        }

        $sets[] = "`$field`=?";

        if ($type === "i") {
            $types .= "i";
            $values[] = (int) $source[$field];
        } elseif ($type === "b") {
            $types .= "i";
            $values[] = bool_value($source[$field]);
        } else {
            $types .= "s";
            $values[] = trim((string) $source[$field]);
        }
    }

    if (!$sets) {
        return true;
    }

    $types .= "i";
    $values[] = $user_id;
    $sql = "UPDATE `$table` SET " . implode(",", $sets) . " WHERE user_id=?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$values);
    return mysqli_stmt_execute($stmt);
}

ensure_settings_schema($conn);
ensure_default_rows($conn, $current_user);

$action = $_POST['action'] ?? $_GET['action'] ?? 'save';

if ($action === "test_notification") {
    json_response(["status" => "success", "message" => "Test notification delivered."]);
}

if ($action === "logout_all") {
    mysqli_query($conn, "DELETE FROM user_sessions WHERE user_id=$current_user");
    json_response(["status" => "success", "message" => "Other sessions cleared."]);
}

if ($action === "backup") {
    $name = "ChatWeb backup " . date("Y-m-d H:i");
    $stmt = mysqli_prepare($conn, "INSERT INTO user_backups (user_id, backup_name, include_media, status) VALUES (?, ?, ?, 'ready')");
    $include = bool_value($_POST['include_media'] ?? 1);
    mysqli_stmt_bind_param($stmt, "isi", $current_user, $name, $include);
    mysqli_stmt_execute($stmt);
    json_response(["status" => "success", "message" => "Backup entry created."]);
}

if ($action !== "save") {
    json_response(["status" => "error", "message" => "Invalid action"]);
}

$section = $_POST['section'] ?? '';

if ($section === "profile") {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $about = trim($_POST['about'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $timezone = trim($_POST['timezone'] ?? '');

    if ($full_name === '' || $username === '' || $email === '') {
        json_response(["status" => "error", "message" => "Name, username and email are required."]);
    }

    $check = mysqli_prepare($conn, "SELECT id FROM users WHERE (email=? OR username=?) AND id<>? LIMIT 1");
    mysqli_stmt_bind_param($check, "ssi", $email, $username, $current_user);
    mysqli_stmt_execute($check);
    $dupe = mysqli_stmt_get_result($check);

    if ($dupe && mysqli_num_rows($dupe) > 0) {
        json_response(["status" => "error", "message" => "Email or username already exists."]);
    }

    $profile_image = $_SESSION['profile_image'] ?? '';

    if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === "1") {
        $profile_image = "";
    }

    if (!empty($_FILES['profile_image']['name'])) {
        $upload_dir = __DIR__ . "/../uploads/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed = ["jpg", "jpeg", "png", "webp", "gif"];

        if (!in_array($ext, $allowed, true)) {
            json_response(["status" => "error", "message" => "Only JPG, PNG, WEBP and GIF images are allowed."]);
        }

        $file_name = "profile_" . $current_user . "_" . time() . "." . $ext;

        if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $file_name)) {
            json_response(["status" => "error", "message" => "Profile image upload failed."]);
        }

        $profile_image = $file_name;
    }

    $dob_value = $date_of_birth !== '' ? $date_of_birth : null;
    $stmt = mysqli_prepare($conn, "
        UPDATE users
        SET full_name=?, username=?, email=?, about=?, phone=?, date_of_birth=?, country=?, timezone=?, profile_image=?
        WHERE id=?
    ");
    mysqli_stmt_bind_param($stmt, "sssssssssi", $full_name, $username, $email, $about, $phone, $dob_value, $country, $timezone, $profile_image, $current_user);

    if (!mysqli_stmt_execute($stmt)) {
        json_response(["status" => "error", "message" => "Profile could not be saved."]);
    }

    $_SESSION['full_name'] = $full_name;
    $_SESSION['username'] = $username;
    $_SESSION['email'] = $email;
    $_SESSION['profile_image'] = $profile_image;

    json_response([
        "status" => "success",
        "message" => "Profile saved.",
        "profile_image" => $profile_image ? "../uploads/" . $profile_image : ""
    ]);
}

if ($section === "privacy") {
    $ok = update_table($conn, "privacy_settings", $current_user, [
        "last_seen_visibility" => "s",
        "profile_photo_visibility" => "s",
        "about_visibility" => "s",
        "read_receipts" => "b",
        "typing_indicator" => "b",
        "online_status_visibility" => "s"
    ], $_POST);
    json_response(["status" => $ok ? "success" : "error", "message" => $ok ? "Privacy saved." : "Privacy could not be saved."]);
}

if ($section === "notifications") {
    $ok = update_table($conn, "notification_preferences", $current_user, [
        "message_notifications" => "b",
        "group_notifications" => "b",
        "call_notifications" => "b",
        "desktop_notifications" => "b",
        "browser_push" => "b",
        "notification_sound" => "b",
        "vibrate_mobile" => "b",
        "preview_message" => "b",
        "mute_all_chats" => "b",
        "mute_specific_users" => "s",
        "notification_volume" => "i"
    ], $_POST);
    json_response(["status" => $ok ? "success" : "error", "message" => $ok ? "Notification settings saved." : "Notifications could not be saved."]);
}

if ($section === "appearance") {
    $ok = update_table($conn, "theme_settings", $current_user, [
        "theme" => "s",
        "accent_color" => "s",
        "wallpaper_type" => "s",
        "wallpaper_value" => "s",
        "font_size" => "s",
        "compact_mode" => "b",
        "bubble_style" => "s",
        "enable_animations" => "b"
    ], $_POST);
    json_response(["status" => $ok ? "success" : "error", "message" => $ok ? "Appearance saved." : "Appearance could not be saved."]);
}

if ($section === "chat" || $section === "advanced") {
    $ok = update_table($conn, "user_settings", $current_user, [
        "language" => "s",
        "time_format" => "s",
        "auto_download_images" => "b",
        "auto_download_videos" => "b",
        "auto_download_documents" => "b",
        "save_sent_media" => "b",
        "enter_to_send" => "b",
        "ctrl_enter_send" => "b",
        "autoplay_gifs" => "b",
        "autoplay_videos" => "b",
        "message_preview" => "b",
        "keep_chat_history" => "b",
        "archive_inactive" => "b",
        "delete_after" => "s",
        "debug_mode" => "b",
        "websocket_enabled" => "b",
        "polling_interval" => "i",
        "api_endpoint" => "s",
        "clear_local_storage" => "b",
        "reset_ui_settings" => "b",
        "performance_mode" => "b"
    ], $_POST);
    json_response(["status" => $ok ? "success" : "error", "message" => $ok ? "Settings saved." : "Settings could not be saved."]);
}

if ($section === "status") {
    $status = $_POST['status'] ?? 'online';
    if (!in_array($status, ['online', 'away', 'offline'], true)) {
        $status = 'online';
    }
    $stmt = mysqli_prepare($conn, "UPDATE users SET status=?, last_seen=NOW() WHERE id=?");
    mysqli_stmt_bind_param($stmt, "si", $status, $current_user);
    $ok = mysqli_stmt_execute($stmt);
    $_SESSION['status'] = $status;
    json_response(["status" => $ok ? "success" : "error", "message" => $ok ? "Status saved." : "Status could not be saved."]);
}

json_response(["status" => "error", "message" => "Unknown settings section."]);
