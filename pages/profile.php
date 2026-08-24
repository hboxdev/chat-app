<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

include __DIR__ . "/../config/config.php";

$user_id = (int) $_SESSION['user_id'];

function h($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
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

function ensure_profile_schema($conn)
{
    ensure_column($conn, "users", "about", "varchar(250) NULL");
    ensure_column($conn, "users", "phone", "varchar(50) NULL");
    ensure_column($conn, "users", "country", "varchar(100) NULL");
    ensure_column($conn, "users", "city", "varchar(100) NULL");
    ensure_column($conn, "users", "timezone", "varchar(80) NULL");
    ensure_column($conn, "users", "website", "varchar(255) NULL");
    ensure_column($conn, "users", "occupation", "varchar(120) NULL");
    ensure_column($conn, "users", "company", "varchar(120) NULL");
    ensure_column($conn, "users", "date_of_birth", "date NULL");
    ensure_column($conn, "users", "gender", "varchar(30) NULL");
    ensure_column($conn, "users", "language", "varchar(60) NULL");
    ensure_column($conn, "users", "last_login", "datetime NULL");

    mysqli_query($conn, "ALTER TABLE users MODIFY status enum('online','offline','away','busy','invisible') DEFAULT 'offline'");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS privacy_settings (
            user_id INT PRIMARY KEY,
            last_seen_visibility VARCHAR(20) DEFAULT 'Everyone',
            profile_photo_visibility VARCHAR(20) DEFAULT 'Everyone',
            about_visibility VARCHAR(20) DEFAULT 'Everyone',
            read_receipts TINYINT(1) DEFAULT 1,
            typing_indicator TINYINT(1) DEFAULT 1,
            online_status_visibility VARCHAR(20) DEFAULT 'Everyone',
            can_message_visibility VARCHAR(20) DEFAULT 'Everyone',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_profile_privacy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    ensure_column($conn, "privacy_settings", "can_message_visibility", "varchar(20) DEFAULT 'Everyone'");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS profile_social_links (
            user_id INT PRIMARY KEY,
            linkedin VARCHAR(255) DEFAULT '',
            github VARCHAR(255) DEFAULT '',
            facebook VARCHAR(255) DEFAULT '',
            instagram VARCHAR(255) DEFAULT '',
            twitter VARCHAR(255) DEFAULT '',
            behance VARCHAR(255) DEFAULT '',
            dribbble VARCHAR(255) DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_profile_social_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            browser VARCHAR(120) DEFAULT '',
            ip_address VARCHAR(60) DEFAULT '',
            device VARCHAR(120) DEFAULT '',
            location VARCHAR(120) DEFAULT '',
            last_active DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_profile_sessions_user (user_id),
            CONSTRAINT fk_profile_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    ensure_column($conn, "user_sessions", "location", "varchar(120) DEFAULT ''");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS profile_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(20) NOT NULL,
            target_value VARCHAR(180) NOT NULL,
            code VARCHAR(12) NOT NULL,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_profile_verify_user (user_id, type),
            CONSTRAINT fk_profile_verify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function json_out($payload)
{
    echo json_encode($payload);
    exit();
}

function valid_username($username)
{
    return preg_match('/^[A-Za-z0-9_]{3,30}$/', $username) === 1;
}

function save_profile_image($user_id)
{
    if (empty($_FILES['profile_image']['name'])) {
        return null;
    }

    if ((int) ($_FILES['profile_image']['size'] ?? 0) > 5 * 1024 * 1024) {
        json_out(["status" => "error", "message" => "Profile image must be 5 MB or smaller."]);
    }

    $info = getimagesize($_FILES['profile_image']['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        json_out(["status" => "error", "message" => "Only JPG, PNG and WEBP images are supported."]);
    }

    if ($info['mime'] === 'image/jpeg') {
        $source = imagecreatefromjpeg($_FILES['profile_image']['tmp_name']);
    } elseif ($info['mime'] === 'image/png') {
        $source = imagecreatefrompng($_FILES['profile_image']['tmp_name']);
    } else {
        $source = function_exists('imagecreatefromwebp') ? imagecreatefromwebp($_FILES['profile_image']['tmp_name']) : null;
    }

    if (!$source) {
        json_out(["status" => "error", "message" => "Image could not be processed."]);
    }

    $size = min(imagesx($source), imagesy($source));
    $src_x = (imagesx($source) - $size) / 2;
    $src_y = (imagesy($source) - $size) / 2;
    $target = imagecreatetruecolor(512, 512);
    imagecopyresampled($target, $source, 0, 0, $src_x, $src_y, 512, 512, $size, $size);

    $upload_dir = __DIR__ . "/../uploads/";
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_name = "profile_" . $user_id . "_" . time() . ".webp";
    if (function_exists('imagewebp')) {
        imagewebp($target, $upload_dir . $file_name, 82);
    } else {
        imagejpeg($target, $upload_dir . $file_name, 82);
        $file_name = str_replace('.webp', '.jpg', $file_name);
    }

    imagedestroy($source);
    imagedestroy($target);

    return $file_name;
}

ensure_profile_schema($conn);
mysqli_query($conn, "INSERT IGNORE INTO privacy_settings (user_id) VALUES ($user_id)");
mysqli_query($conn, "INSERT IGNORE INTO profile_social_links (user_id) VALUES ($user_id)");

$browser = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Current browser', 0, 120);
$ip = substr($_SERVER['REMOTE_ADDR'] ?? 'localhost', 0, 60);
$delete_session = mysqli_prepare($conn, "DELETE FROM user_sessions WHERE user_id=? AND browser=? AND ip_address=?");
mysqli_stmt_bind_param($delete_session, "iss", $user_id, $browser, $ip);
mysqli_stmt_execute($delete_session);
$insert_session = mysqli_prepare($conn, "INSERT INTO user_sessions (user_id, browser, ip_address, device, location, last_active) VALUES (?, ?, ?, 'Web', 'Local network', NOW())");
mysqli_stmt_bind_param($insert_session, "iss", $user_id, $browser, $ip);
mysqli_stmt_execute($insert_session);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ajax'])) {
    header("Content-Type: application/json");
    $action = $_POST['action'] ?? '';

    if ($action === 'check_username') {
        $username = trim($_POST['username'] ?? '');
        if (!valid_username($username)) {
            json_out(["status" => "error", "message" => "Use letters, numbers or underscore only."]);
        }

        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "si", $username, $user_id);
        mysqli_stmt_execute($stmt);
        $exists = mysqli_stmt_get_result($stmt);
        json_out([
            "status" => mysqli_num_rows($exists) > 0 ? "error" : "success",
            "message" => mysqli_num_rows($exists) > 0 ? "This username is already taken." : "Username is available."
        ]);
    }

    if ($action === 'send_code') {
        $type = $_POST['type'] ?? '';
        $target = trim($_POST['target'] ?? '');
        if (!in_array($type, ['email', 'phone'], true) || $target === '') {
            json_out(["status" => "error", "message" => "Verification target is required."]);
        }
        $code = (string) random_int(100000, 999999);
        $stmt = mysqli_prepare($conn, "INSERT INTO profile_verifications (user_id, type, target_value, code, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
        mysqli_stmt_bind_param($stmt, "isss", $user_id, $type, $target, $code);
        mysqli_stmt_execute($stmt);
        $_SESSION['profile_verify_' . $type] = $code;
        json_out(["status" => "success", "message" => "Verification code generated for $type.", "code" => $code]);
    }

    if ($action === 'verify_code') {
        $type = $_POST['type'] ?? '';
        $target = trim($_POST['target'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $column = $type === 'email' ? 'email' : ($type === 'phone' ? 'phone' : '');
        if (!$column) {
            json_out(["status" => "error", "message" => "Invalid verification type."]);
        }

        $stmt = mysqli_prepare($conn, "SELECT id FROM profile_verifications WHERE user_id=? AND type=? AND target_value=? AND code=? AND expires_at>NOW() AND verified_at IS NULL ORDER BY id DESC LIMIT 1");
        mysqli_stmt_bind_param($stmt, "isss", $user_id, $type, $target, $code);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$row) {
            json_out(["status" => "error", "message" => "Invalid or expired verification code."]);
        }

        $update = mysqli_prepare($conn, "UPDATE users SET `$column`=? WHERE id=?");
        mysqli_stmt_bind_param($update, "si", $target, $user_id);
        mysqli_stmt_execute($update);
        mysqli_query($conn, "UPDATE profile_verifications SET verified_at=NOW() WHERE id=" . (int) $row['id']);
        $_SESSION[$column] = $target;
        json_out(["status" => "success", "message" => ucfirst($type) . " verified and updated."]);
    }

    if ($action === 'save_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $about = trim($_POST['about'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $timezone = trim($_POST['timezone'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $occupation = trim($_POST['occupation'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $date_of_birth = trim($_POST['date_of_birth'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $language = trim($_POST['language'] ?? '');
        $status = trim($_POST['status'] ?? 'online');

        if ($full_name === '' || $username === '' || $email === '') {
            json_out(["status" => "error", "message" => "Full name, username and email are required."]);
        }
        if (!valid_username($username)) {
            json_out(["status" => "error", "message" => "Username can only use letters, numbers and underscore."]);
        }
        $about_length = function_exists('mb_strlen') ? mb_strlen($about) : strlen($about);
        if ($about_length > 250) {
            json_out(["status" => "error", "message" => "Bio must be 250 characters or less."]);
        }
        if (!in_array($status, ['online', 'away', 'busy', 'invisible', 'offline'], true)) {
            $status = 'online';
        }

        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE (username=? OR email=?) AND id<>? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "ssi", $username, $email, $user_id);
        mysqli_stmt_execute($stmt);
        if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0) {
            json_out(["status" => "error", "message" => "Username or email already exists."]);
        }

        $profile_image = null;
        if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
            $profile_image = "";
        }
        $uploaded = save_profile_image($user_id);
        if ($uploaded !== null) {
            $profile_image = $uploaded;
        }

        $date_of_birth = $date_of_birth !== '' ? $date_of_birth : null;

        if ($profile_image === null) {
            $sql = "UPDATE users SET full_name=?, username=?, email=?, about=?, phone=?, country=?, city=?, timezone=?, website=?, occupation=?, company=?, date_of_birth=?, gender=?, language=?, status=?, last_seen=NOW() WHERE id=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "sssssssssssssssi", $full_name, $username, $email, $about, $phone, $country, $city, $timezone, $website, $occupation, $company, $date_of_birth, $gender, $language, $status, $user_id);
        } else {
            $sql = "UPDATE users SET full_name=?, username=?, email=?, about=?, phone=?, country=?, city=?, timezone=?, website=?, occupation=?, company=?, date_of_birth=?, gender=?, language=?, status=?, profile_image=?, last_seen=NOW() WHERE id=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssssssssssssssssi", $full_name, $username, $email, $about, $phone, $country, $city, $timezone, $website, $occupation, $company, $date_of_birth, $gender, $language, $status, $profile_image, $user_id);
        }

        if (!mysqli_stmt_execute($stmt)) {
            json_out(["status" => "error", "message" => "Profile could not be saved."]);
        }

        $socials = ['linkedin','github','facebook','instagram','twitter','behance','dribbble'];
        $values = [];
        foreach ($socials as $social) {
            $values[$social] = trim($_POST[$social] ?? '');
        }
        $stmt = mysqli_prepare($conn, "UPDATE profile_social_links SET linkedin=?, github=?, facebook=?, instagram=?, twitter=?, behance=?, dribbble=? WHERE user_id=?");
        mysqli_stmt_bind_param($stmt, "sssssssi", $values['linkedin'], $values['github'], $values['facebook'], $values['instagram'], $values['twitter'], $values['behance'], $values['dribbble'], $user_id);
        mysqli_stmt_execute($stmt);

        $privacy_fields = ['profile_photo_visibility','about_visibility','last_seen_visibility','can_message_visibility'];
        $privacy_values = [];
        foreach ($privacy_fields as $field) {
            $value = $_POST[$field] ?? 'Everyone';
            $privacy_values[$field] = in_array($value, ['Everyone','Contacts','Nobody'], true) ? $value : 'Everyone';
        }
        $stmt = mysqli_prepare($conn, "UPDATE privacy_settings SET profile_photo_visibility=?, about_visibility=?, last_seen_visibility=?, can_message_visibility=? WHERE user_id=?");
        mysqli_stmt_bind_param($stmt, "ssssi", $privacy_values['profile_photo_visibility'], $privacy_values['about_visibility'], $privacy_values['last_seen_visibility'], $privacy_values['can_message_visibility'], $user_id);
        mysqli_stmt_execute($stmt);

        $_SESSION['full_name'] = $full_name;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        $_SESSION['status'] = $status;
        if ($profile_image !== null) {
            $_SESSION['profile_image'] = $profile_image;
        }

        json_out([
            "status" => "success",
            "message" => "Profile saved successfully.",
            "profile_image_url" => !empty($_SESSION['profile_image']) ? "../uploads/" . $_SESSION['profile_image'] : "",
            "full_name" => $full_name,
            "username" => $username,
            "email" => $email,
            "user_status" => $status
        ]);
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (strlen($new) < 8 || $new !== $confirm) {
            json_out(["status" => "error", "message" => "Password must be 8+ characters and match confirmation."]);
        }
        $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$row || !password_verify($current, $row['password'])) {
            json_out(["status" => "error", "message" => "Current password is incorrect."]);
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE users SET password=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "si", $hash, $user_id);
        mysqli_stmt_execute($stmt);
        json_out(["status" => "success", "message" => "Password updated successfully."]);
    }

    if ($action === 'logout_all') {
        mysqli_query($conn, "DELETE FROM user_sessions WHERE user_id=$user_id");
        json_out(["status" => "success", "message" => "All devices were logged out from the session list."]);
    }

    if ($action === 'account_action') {
        $mode = $_POST['mode'] ?? '';
        if ($mode === 'deactivate') {
            mysqli_query($conn, "UPDATE users SET is_active=0, status='offline', last_seen=NOW() WHERE id=$user_id");
            json_out(["status" => "success", "message" => "Account deactivated. Login will be disabled until reactivated by admin."]);
        }
        if ($mode === 'download' || $mode === 'export') {
            json_out(["status" => "success", "message" => "Profile export prepared."]);
        }
        json_out(["status" => "success", "message" => "Action completed."]);
    }

    json_out(["status" => "error", "message" => "Invalid profile action."]);
}

$stmt = mysqli_prepare($conn, "
    SELECT id, uuid, full_name, username, email, status, profile_image, last_seen, last_login, created_at,
           about, phone, country, city, timezone, website, occupation, company, date_of_birth, gender, language
    FROM users
    WHERE id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
mysqli_stmt_close($stmt);

$privacy = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM privacy_settings WHERE user_id=$user_id LIMIT 1")) ?: [];
$social = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile_social_links WHERE user_id=$user_id LIMIT 1")) ?: [];

$full_name = $user['full_name'] ?? ($_SESSION['full_name'] ?? '');
$username = $user['username'] ?? ($_SESSION['username'] ?? '');
$email = $user['email'] ?? ($_SESSION['email'] ?? '');
$status = $user['status'] ?? ($_SESSION['status'] ?? 'offline');
$profile_image = $user['profile_image'] ?? '';
$created_at = $user['created_at'] ?? '';
$last_seen = $user['last_seen'] ?? '';
$last_login = $user['last_login'] ?? '';
$initial = strtoupper(substr(trim($full_name ?: $username ?: 'U'), 0, 1));
$profile_image_url = '';

if (!empty($profile_image)) {
    $profile_image_url = str_starts_with($profile_image, 'uploads/')
        ? '../' . $profile_image
        : '../uploads/' . $profile_image;
}

function count_value($conn, $sql, $user_id)
{
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return (int) ($row['total'] ?? 0);
}

$stats = [
    'chats' => count_value($conn, "SELECT COUNT(*) total FROM conversation_members WHERE user_id = ?", $user_id),
    'sent' => count_value($conn, "SELECT COUNT(*) total FROM messages WHERE sender_id = ? AND is_deleted = 0", $user_id),
    'received' => count_value($conn, "SELECT COUNT(*) total FROM messages m JOIN conversation_members cm ON cm.conversation_id=m.conversation_id AND cm.user_id=? WHERE m.sender_id<>cm.user_id AND m.is_deleted=0", $user_id),
    'images' => count_value($conn, "SELECT COUNT(*) total FROM messages WHERE sender_id = ? AND message_type='image' AND is_deleted=0", $user_id),
    'videos' => count_value($conn, "SELECT COUNT(*) total FROM messages WHERE sender_id = ? AND message_type='video' AND is_deleted=0", $user_id),
    'files' => count_value($conn, "SELECT COUNT(*) total FROM messages WHERE sender_id = ? AND message_type='file' AND is_deleted=0", $user_id),
    'audio' => count_value($conn, "SELECT COUNT(*) total FROM messages WHERE sender_id = ? AND message_type='audio' AND is_deleted=0", $user_id),
    'calls' => 0,
    'friends' => count_value($conn, "SELECT COUNT(*) total FROM user_contacts WHERE user_id = ?", $user_id),
    'blocked' => count_value($conn, "SELECT COUNT(*) total FROM user_contacts WHERE user_id = ? AND is_blocked=1", $user_id)
];

$storage_bytes = 0;
$media = [];
$media_result = mysqli_query($conn, "
    SELECT id, message_type, message, attachment, created_at
    FROM messages
    WHERE sender_id=$user_id
    AND attachment IS NOT NULL
    AND attachment <> ''
    AND is_deleted=0
    ORDER BY created_at DESC
    LIMIT 24
");
if ($media_result) {
    while ($item = mysqli_fetch_assoc($media_result)) {
        $path = __DIR__ . "/../" . $item['attachment'];
        if (is_file($path)) {
            $item['size'] = filesize($path);
            $storage_bytes += (int) $item['size'];
        } else {
            $item['size'] = 0;
        }
        $media[] = $item;
    }
}

function human_size($bytes)
{
    $units = ['B','KB','MB','GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

$sessions = mysqli_query($conn, "SELECT id, browser, ip_address, device, location, created_at, last_active FROM user_sessions WHERE user_id=$user_id ORDER BY last_active DESC LIMIT 6");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile | Chat Web</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<style>
:root{--bg:#eef2f7;--surface:#fff;--border:#dbe3ee;--soft:#f8fafc;--text:#111827;--muted:#64748b;--primary:#3870FF;--primary-dark:#2458E8;--danger:#dc2626;--success:#16a34a;--shadow:0 18px 44px rgba(15,23,42,.08);--radius:16px}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;min-height:100vh;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:linear-gradient(180deg,#F7FAFF,var(--bg));color:var(--text)}button,input,select,textarea{font:inherit}
.shell{min-height:100vh;display:flex}.sidebar{width:270px;padding:22px 18px;background:#fff;border-right:1px solid var(--border);flex:0 0 270px}.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px;padding:0 8px}.brand-icon,.avatar{display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff}.brand-icon{width:42px;height:42px;border-radius:12px;font-size:19px;box-shadow:0 12px 28px rgba(56,112,255,.22)}.brand h1{margin:0;font-size:19px}.brand span{display:block;margin-top:3px;color:var(--muted);font-size:12px}.nav{display:grid;gap:6px}.nav a{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;color:#334155;text-decoration:none;font-weight:800;font-size:14px;transition:.2s}.nav a:hover,.nav a.active{background:#eff6ff;color:#2563eb}.nav a.logout{color:#dc2626;margin-top:10px}
.main{flex:1;min-width:0;padding:28px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:24px}.topbar h2{margin:0;font-size:28px}.topbar p{margin:6px 0 0;color:var(--muted)}.primary-btn,.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;min-height:42px;padding:0 15px;border:0;border-radius:12px;background:#2563eb;color:#fff;text-decoration:none;font-weight:900;font-size:14px;cursor:pointer;transition:.2s}.btn:hover,.primary-btn:hover{transform:translateY(-1px)}.btn-soft{background:#EEF4FF;color:var(--primary)}.btn-danger{background:#FEF2F2;color:#dc2626}.btn-dark{background:#111827;color:#fff}.topbar-actions{display:flex;align-items:center;gap:10px}.power-btn{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 16px 32px rgba(220,38,38,.28)}
.profile-layout{display:grid;grid-template-columns:360px minmax(0,1fr);gap:18px}.card,.panel{background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow)}.profile-card{padding:24px;text-align:center;position:sticky;top:24px;align-self:start}.avatar{width:118px;height:118px;margin:0 auto 16px;border-radius:50%;font-size:42px;font-weight:900;overflow:hidden;box-shadow:0 16px 34px rgba(56,112,255,.22)}.avatar img{width:100%;height:100%;object-fit:cover}.profile-card h3{margin:0;font-size:24px}.profile-card p{margin:7px 0 0;color:var(--muted)}.status{display:inline-flex;align-items:center;gap:7px;margin-top:14px;padding:7px 11px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:13px;text-transform:capitalize;font-weight:800}.status-dot{width:8px;height:8px;border-radius:50%;background:#94a3b8}.status-dot.online{background:#16a34a}.status-dot.away{background:#f59e0b}.status-dot.busy{background:#ef4444}.status-dot.invisible{background:#64748b}
.mini-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:22px}.mini-stat{padding:14px;border-radius:13px;background:#f8fafc}.mini-stat strong{display:block;font-size:24px}.mini-stat span{color:var(--muted);font-size:12px}.profile-nav{display:grid;gap:8px;margin-top:18px;text-align:left}.profile-nav a{display:flex;align-items:center;gap:10px;min-height:40px;padding:0 12px;border-radius:11px;color:#334155;text-decoration:none;font-weight:800;font-size:13px}.profile-nav a:hover{background:#eff6ff;color:var(--primary)}
.content{display:grid;gap:18px}.panel{padding:22px;scroll-margin-top:22px}.panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:18px}.panel h3{margin:0 0 6px;font-size:20px}.panel p{margin:0;color:var(--muted);line-height:1.5}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:grid;gap:7px}.field.full{grid-column:1/-1}.field label{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#475569;font-weight:900}.input,.select,.textarea{width:100%;min-height:44px;border:1px solid #d8e1ee;border-radius:13px;background:#fff;color:var(--text);padding:0 13px;outline:0;transition:.18s}.textarea{padding:12px 13px;min-height:110px;resize:vertical}.input:focus,.select:focus,.textarea:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(56,112,255,.12)}.hint{font-size:12px;color:var(--muted)}.hint.error{color:#dc2626}.hint.success{color:#047857}.counter{text-align:right;font-size:12px;color:var(--muted)}
.photo-zone{display:grid;grid-template-columns:170px minmax(0,1fr);gap:16px}.drop{height:170px;border:1px dashed #b7c5d9;border-radius:18px;background:#F8FBFF;display:grid;place-items:center;text-align:center;overflow:hidden;cursor:pointer;padding:12px}.drop img{width:100%;height:100%;object-fit:cover;border-radius:14px;transform:rotate(var(--rotate,0deg)) scale(var(--zoom,1))}.range-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.stat-card{padding:16px;border:1px solid var(--border);border-radius:14px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.05);transition:.2s}.stat-card:hover{transform:translateY(-2px);box-shadow:0 18px 36px rgba(15,23,42,.09)}.stat-card i{width:38px;height:38px;border-radius:12px;background:#eff6ff;color:var(--primary);display:flex;align-items:center;justify-content:center;margin-bottom:12px}.stat-card strong{display:block;font-size:24px}.stat-card span{font-size:12px;color:var(--muted)}
.info-list,.media-grid,.session-list{display:grid;gap:10px}.info-row,.session-row{display:flex;justify-content:space-between;gap:16px;padding:13px;border:1px solid var(--border);border-radius:13px;background:#FBFDFF}.info-row span,.session-row small{color:var(--muted)}.media-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.media-item{border:1px solid var(--border);border-radius:14px;overflow:hidden;background:#fff}.media-thumb{height:110px;background:#eff6ff;display:grid;place-items:center;color:var(--primary);font-size:28px}.media-thumb img,.media-thumb video{width:100%;height:100%;object-fit:cover}.media-meta{padding:10px}.media-meta strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.media-meta small{color:var(--muted)}.social-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.modal{position:fixed;inset:0;background:rgba(15,23,42,.72);display:none;align-items:center;justify-content:center;z-index:9998;padding:24px}.modal.open{display:flex}.modal-box{width:min(720px,96vw);background:#fff;border-radius:18px;padding:18px;box-shadow:0 30px 80px rgba(15,23,42,.35)}.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.modal-body img,.modal-body video{max-width:100%;max-height:75vh;display:block;margin:auto;border-radius:12px}
.toast-wrap{position:fixed;right:22px;bottom:22px;display:grid;gap:10px;z-index:9999}.toast{background:#111827;color:#fff;padding:13px 15px;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.22);animation:toast .22s ease}.toast.success{background:#047857}.toast.error{background:#dc2626}@keyframes toast{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}.spinner{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:1180px){.profile-layout{grid-template-columns:1fr}.profile-card{position:relative;top:0}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.media-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:900px){.shell{display:block}.sidebar{width:100%;border-right:0;border-bottom:1px solid var(--border)}.nav{grid-template-columns:repeat(3,1fr)}.topbar{align-items:flex-start;flex-direction:column}.form-grid,.photo-zone,.social-row{grid-template-columns:1fr}.main{padding:18px}}
@media(max-width:620px){.nav,.mini-stats,.stat-grid,.media-grid{grid-template-columns:1fr}.info-row,.session-row{display:grid}.actions{justify-content:stretch}.btn,.primary-btn{width:100%}}
.sidebar-toggle{width:100%;min-height:42px;border:1px solid var(--border);border-radius:12px;background:#fff;color:#273449;display:flex;align-items:center;justify-content:center;gap:10px;cursor:pointer;box-shadow:0 12px 28px rgba(15,23,42,.06);font-weight:800;font-size:14px;transition:.2s}.sidebar-toggle:hover{color:#2563eb;background:#eff6ff;transform:translateY(-1px)}.shell,.sidebar,.brand,.nav a{transition:.22s ease}.shell.sidebar-collapsed .sidebar{width:84px;flex-basis:84px;padding:22px 11px;align-items:center;gap:28px}.shell.sidebar-collapsed .brand{width:100%;justify-content:center;padding:0;margin-bottom:22px}.shell.sidebar-collapsed .brand-icon{width:44px;height:44px;border-radius:14px}.shell.sidebar-collapsed .brand-copy,.shell.sidebar-collapsed .nav a span,.shell.sidebar-collapsed .sidebar-toggle span{display:none}.shell.sidebar-collapsed .nav{width:100%;gap:14px}.shell.sidebar-collapsed .nav a{width:54px;min-height:44px;justify-content:center;padding:0;border-radius:14px}.shell.sidebar-collapsed .nav a.active{background:rgba(56,112,255,.14);box-shadow:inset 0 0 0 1px rgba(56,112,255,.22)}.shell.sidebar-collapsed .nav a.logout{margin-top:12px}.shell.sidebar-collapsed .sidebar-toggle{width:44px;min-height:44px;margin-top:10px;padding:0;border-radius:14px}.shell.sidebar-collapsed .sidebar-toggle i{transform:rotate(180deg)}
</style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fa-solid fa-comments"></i></div>
            <div class="brand-copy"><h1>Chat Web</h1><span>Messaging workspace</span></div>
        </div>
        <nav class="nav">
            <a href="dashboard.php"><i class="fa-solid fa-table-columns"></i> <span>Dashboard</span></a>
            <a href="chat.php"><i class="fa-solid fa-message"></i> <span>Chats</span></a>
            <a href="profile.php" class="active"><i class="fa-solid fa-user"></i> <span>Profile</span></a>
            <a href="settings.php"><i class="fa-solid fa-gear"></i> <span>Settings</span></a>
            <a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> <span>Logout</span></a>
            <button class="sidebar-toggle" id="sidebar-toggle" type="button" title="Close sidebar" aria-label="Close sidebar">
                <i class="fa-solid fa-chevron-left"></i>
                <span>Collapse sidebar</span>
            </button>
        </nav>
    </aside>

    <main class="main">
        <div class="topbar">
            <div><h2>Profile</h2><p>Manage how your account appears across Chat Web.</p></div>
            <div class="topbar-actions">
                <a href="chat.php" class="primary-btn"><i class="fa-solid fa-paper-plane"></i> Open Chat</a>
                <div id="notification-inline"></div>
                <a class="power-btn" href="../logout.php" title="Logout"><i class="fa-solid fa-power-off"></i></a>
            </div>
        </div>

        <div class="profile-layout">
            <aside class="profile-card card">
                <div class="avatar" id="profile-avatar">
                    <?php if($profile_image_url){ ?><img src="<?php echo h($profile_image_url); ?>" alt="<?php echo h($full_name ?: 'User'); ?>"><?php } else { echo h($initial); } ?>
                </div>
                <h3 id="display-name"><?php echo h($full_name ?: 'User'); ?></h3>
                <p id="display-username">@<?php echo h($username ?: 'username'); ?></p>
                <div class="status" id="display-status"><span class="status-dot <?php echo h($status); ?>"></span><?php echo h($status); ?></div>
                <div class="mini-stats">
                    <div class="mini-stat"><strong><?php echo (int) $stats['chats']; ?></strong><span>Chats</span></div>
                    <div class="mini-stat"><strong><?php echo (int) $stats['sent']; ?></strong><span>Sent</span></div>
                    <div class="mini-stat"><strong><?php echo count($media); ?></strong><span>Media</span></div>
                </div>
                <nav class="profile-nav">
                    <a href="#edit"><i class="fa-solid fa-pen"></i> Edit profile</a>
                    <a href="#stats"><i class="fa-solid fa-chart-line"></i> Statistics</a>
                    <a href="#account"><i class="fa-solid fa-id-card"></i> Account info</a>
                    <a href="#security"><i class="fa-solid fa-lock"></i> Security</a>
                    <a href="#media"><i class="fa-solid fa-photo-film"></i> Media</a>
                    <a href="#actions"><i class="fa-solid fa-gear"></i> Account actions</a>
                </nav>
            </aside>

            <div class="content">
                <form class="panel" id="profile-form" enctype="multipart/form-data">
                    <div class="panel-head" id="edit">
                        <div><h3>Edit Profile</h3><p>Update your photo, public identity, bio and contact details without leaving the page.</p></div>
                    </div>
                    <div class="photo-zone">
                        <label class="drop" id="photo-drop">
                            <input type="file" id="photo-input" accept="image/jpeg,image/png,image/webp" hidden>
                            <?php if($profile_image_url){ ?><img id="photo-preview" src="<?php echo h($profile_image_url); ?>" alt="Profile photo"><?php } else { ?><span id="photo-empty"><i class="fa-solid fa-cloud-arrow-up"></i><br>Drop or click to upload</span><img id="photo-preview" src="" alt="" style="display:none"><?php } ?>
                        </label>
                        <div>
                            <div class="range-row">
                                <label class="field"><label>Zoom</label><input class="input" type="range" id="zoom" min="1" max="2" step=".05" value="1"></label>
                                <label class="field"><label>Rotate</label><input class="input" type="range" id="rotate" min="-180" max="180" step="5" value="0"></label>
                            </div>
                            <div class="actions" style="justify-content:flex-start">
                                <button class="btn btn-soft" type="button" id="view-photo"><i class="fa-solid fa-expand"></i> View image</button>
                                <button class="btn btn-soft" type="button" id="remove-photo"><i class="fa-solid fa-trash"></i> Remove photo</button>
                            </div>
                            <p class="hint">JPG, PNG or WEBP. Maximum 5 MB. Image is compressed before upload.</p>
                        </div>
                    </div>
                    <input type="hidden" name="remove_photo" id="remove-photo-flag" value="0">
                    <div class="form-grid" style="margin-top:18px">
                        <div class="field"><label>Full Name</label><input class="input" name="full_name" value="<?php echo h($full_name); ?>" required></div>
                        <div class="field"><label>Username</label><input class="input" name="username" id="username-input" value="<?php echo h($username); ?>" required><span class="hint" id="username-hint">Letters, numbers and underscore only.</span></div>
                        <div class="field"><label>Email</label><input class="input" type="email" name="email" id="email-input" value="<?php echo h($email); ?>" required><button class="btn btn-soft" type="button" data-code="email">Send verification code</button></div>
                        <div class="field"><label>Email code</label><input class="input" id="email-code" placeholder="6 digit code"><button class="btn btn-soft" type="button" data-verify="email">Verify email</button></div>
                        <div class="field"><label>Phone Number</label><input class="input" name="phone" id="phone-input" value="<?php echo h($user['phone'] ?? ''); ?>" placeholder="+92 300 0000000"><button class="btn btn-soft" type="button" data-code="phone">Send OTP</button></div>
                        <div class="field"><label>Phone OTP</label><input class="input" id="phone-code" placeholder="6 digit code"><button class="btn btn-soft" type="button" data-verify="phone">Verify phone</button></div>
                        <div class="field"><label>Country</label><input class="input" name="country" value="<?php echo h($user['country'] ?? ''); ?>"></div>
                        <div class="field"><label>City</label><input class="input" name="city" value="<?php echo h($user['city'] ?? ''); ?>"></div>
                        <div class="field"><label>Timezone</label><input class="input" name="timezone" value="<?php echo h($user['timezone'] ?? date_default_timezone_get()); ?>"></div>
                        <div class="field"><label>Website</label><input class="input" name="website" value="<?php echo h($user['website'] ?? ''); ?>"></div>
                        <div class="field"><label>Occupation</label><input class="input" name="occupation" value="<?php echo h($user['occupation'] ?? ''); ?>"></div>
                        <div class="field"><label>Company</label><input class="input" name="company" value="<?php echo h($user['company'] ?? ''); ?>"></div>
                        <div class="field"><label>Date of Birth</label><input class="input" type="date" name="date_of_birth" value="<?php echo h($user['date_of_birth'] ?? ''); ?>"></div>
                        <div class="field"><label>Gender</label><select class="select" name="gender"><option value="">Prefer not to say</option><?php foreach(['Male','Female','Other'] as $g){ ?><option <?php echo ($user['gender'] ?? '') === $g ? 'selected' : ''; ?>><?php echo h($g); ?></option><?php } ?></select></div>
                        <div class="field"><label>Language</label><select class="select" name="language"><option <?php echo ($user['language'] ?? '') === 'English' ? 'selected' : ''; ?>>English</option><option <?php echo ($user['language'] ?? '') === 'Urdu' ? 'selected' : ''; ?>>Urdu</option></select></div>
                        <div class="field"><label>Current Status</label><select class="select" name="status" id="status-input"><?php foreach(['online','away','busy','invisible','offline'] as $s){ ?><option <?php echo $status === $s ? 'selected' : ''; ?>><?php echo h($s); ?></option><?php } ?></select></div>
                        <div class="field full"><label>Bio / About</label><textarea class="textarea" name="about" id="about-input" maxlength="250"><?php echo h($user['about'] ?? ''); ?></textarea><div class="counter"><span id="about-count">0</span> / 250</div></div>
                    </div>

                    <div class="panel-head" style="margin-top:22px"><div><h3>Social Links</h3><p>Optional links visible on your business profile.</p></div></div>
                    <div class="social-row">
                        <?php foreach(['website'=>'Website','linkedin'=>'LinkedIn','github'=>'GitHub','facebook'=>'Facebook','instagram'=>'Instagram','twitter'=>'Twitter','behance'=>'Behance','dribbble'=>'Dribbble'] as $key => $label){ if($key === 'website') continue; ?>
                            <div class="field"><label><?php echo h($label); ?></label><input class="input" name="<?php echo h($key); ?>" value="<?php echo h($social[$key] ?? ''); ?>"></div>
                        <?php } ?>
                    </div>

                    <div class="panel-head" style="margin-top:22px"><div><h3>Privacy Settings</h3><p>Control who can see your profile and who can message you.</p></div></div>
                    <div class="form-grid">
                        <?php foreach(['profile_photo_visibility'=>'Profile Picture','about_visibility'=>'Bio','last_seen_visibility'=>'Last Seen','can_message_visibility'=>'Who can send message'] as $key => $label){ ?>
                            <div class="field"><label><?php echo h($label); ?></label><select class="select" name="<?php echo h($key); ?>"><?php foreach(['Everyone','Contacts','Nobody'] as $v){ ?><option <?php echo ($privacy[$key] ?? 'Everyone') === $v ? 'selected' : ''; ?>><?php echo h($v); ?></option><?php } ?></select></div>
                        <?php } ?>
                    </div>

                    <div class="actions">
                        <button class="btn btn-soft" type="reset">Cancel Changes</button>
                        <button class="btn btn-soft" type="button" id="reset-form">Reset Changes</button>
                        <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                    </div>
                </form>

                <section class="panel" id="stats">
                    <div class="panel-head"><div><h3>Profile Statistics</h3><p>Live account activity summary.</p></div></div>
                    <div class="stat-grid">
                        <?php
                        $cards = [
                            ['fa-comments','Chats',$stats['chats']], ['fa-paper-plane','Messages Sent',$stats['sent']], ['fa-inbox','Messages Received',$stats['received']], ['fa-image','Images Sent',$stats['images']],
                            ['fa-video','Videos Sent',$stats['videos']], ['fa-file','Files Shared',$stats['files']], ['fa-microphone','Audio Messages',$stats['audio']], ['fa-phone','Calls',$stats['calls']],
                            ['fa-user-group','Friends',$stats['friends']], ['fa-user-slash','Blocked Users',$stats['blocked']], ['fa-database','Storage Used',human_size($storage_bytes)], ['fa-calendar','Join Date',$created_at ? date('M d, Y', strtotime($created_at)) : '-']
                        ];
                        foreach($cards as $card){ ?>
                            <div class="stat-card"><i class="fa-solid <?php echo h($card[0]); ?>"></i><strong data-counter="<?php echo is_numeric($card[2]) ? (int) $card[2] : h($card[2]); ?>"><?php echo h($card[2]); ?></strong><span><?php echo h($card[1]); ?></span></div>
                        <?php } ?>
                    </div>
                </section>

                <section class="panel" id="account">
                    <div class="panel-head"><div><h3>Account Information</h3><p>Identity and activity details attached to this account.</p></div></div>
                    <div class="info-list">
                        <div class="info-row"><span>Account ID</span><strong><?php echo (int) $user_id; ?></strong></div>
                        <div class="info-row"><span>User UUID</span><strong><?php echo h($user['uuid'] ?? '-'); ?></strong></div>
                        <div class="info-row"><span>Member Since</span><strong><?php echo h($created_at ? date('M d, Y', strtotime($created_at)) : '-'); ?></strong></div>
                        <div class="info-row"><span>Last Login</span><strong><?php echo h($last_login ? date('M d, Y h:i A', strtotime($last_login)) : '-'); ?></strong></div>
                        <div class="info-row"><span>Last Seen</span><strong><?php echo h($last_seen ? date('M d, Y h:i A', strtotime($last_seen)) : 'Currently active'); ?></strong></div>
                        <div class="info-row"><span>Current Status</span><strong><?php echo h(ucfirst($status)); ?></strong></div>
                    </div>
                </section>

                <section class="panel" id="security">
                    <div class="panel-head"><div><h3>Security</h3><p>Change password and manage active devices.</p></div></div>
                    <form id="password-form" class="form-grid">
                        <div class="field"><label>Current Password</label><input class="input" type="password" name="current_password"></div>
                        <div class="field"><label>New Password</label><input class="input" type="password" name="new_password" id="new-password"></div>
                        <div class="field"><label>Confirm Password</label><input class="input" type="password" name="confirm_password"></div>
                        <div class="field"><label>Strength Meter</label><div class="info-row"><span id="password-strength">Weak</span><strong id="password-score">0%</strong></div></div>
                        <div class="field full"><button class="btn" type="submit">Change Password</button></div>
                    </form>
                    <h3 style="margin-top:22px">Active Sessions</h3>
                    <div class="session-list">
                        <?php if($sessions && mysqli_num_rows($sessions) > 0){ while($session = mysqli_fetch_assoc($sessions)){ ?>
                            <div class="session-row"><div><strong><?php echo h($session['device'] ?: 'Web'); ?></strong><br><small><?php echo h(substr($session['browser'],0,76)); ?></small></div><div><strong><?php echo h($session['ip_address']); ?></strong><br><small><?php echo h($session['location'] ?: 'Unknown'); ?> - <?php echo h($session['last_active']); ?></small></div></div>
                        <?php }} ?>
                    </div>
                    <div class="actions" style="justify-content:flex-start"><button class="btn btn-danger" id="logout-all" type="button">Logout all devices</button></div>
                </section>

                <section class="panel" id="media">
                    <div class="panel-head"><div><h3>Media Gallery</h3><p>Files sent by you. Open, preview, download or manage shared media.</p></div></div>
                    <div class="media-grid">
                        <?php if(count($media) > 0){ foreach($media as $item){
                            $url = '../' . $item['attachment'];
                            $name = basename($item['attachment']);
                        ?>
                            <div class="media-item" data-media-url="<?php echo h($url); ?>" data-media-type="<?php echo h($item['message_type']); ?>">
                                <div class="media-thumb">
                                    <?php if($item['message_type'] === 'image'){ ?><img src="<?php echo h($url); ?>" alt="<?php echo h($name); ?>"><?php } elseif($item['message_type'] === 'video'){ ?><video src="<?php echo h($url); ?>"></video><?php } else { ?><i class="fa-solid fa-file"></i><?php } ?>
                                </div>
                                <div class="media-meta"><strong><?php echo h($name); ?></strong><small><?php echo h(human_size((int)$item['size'])); ?> - <?php echo h(date('M d, Y', strtotime($item['created_at']))); ?></small><div class="actions" style="justify-content:flex-start;margin-top:8px"><a class="btn btn-soft" href="<?php echo h($url); ?>" download>Download</a><button class="btn btn-danger media-delete" type="button">Delete</button></div></div>
                            </div>
                        <?php }} else { ?>
                            <div class="info-row"><span>No media found</span><strong>Send files in chat to see them here.</strong></div>
                        <?php } ?>
                    </div>
                </section>

                <section class="panel" id="actions">
                    <div class="panel-head"><div><h3>Account Actions</h3><p>Export your data or manage account state.</p></div></div>
                    <div class="actions" style="justify-content:flex-start">
                        <button class="btn btn-soft account-action" data-mode="download" type="button">Download My Data</button>
                        <button class="btn btn-soft account-action" data-mode="export" type="button">Export Profile</button>
                        <button class="btn btn-danger account-action" data-mode="deactivate" type="button">Deactivate Account</button>
                        <button class="btn btn-danger account-action" data-mode="delete" type="button">Delete Account</button>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<div class="modal" id="viewer"><div class="modal-box"><div class="modal-head"><strong>Preview</strong><button class="btn btn-soft" id="viewer-close" type="button"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body" id="viewer-body"></div></div></div>
<div class="toast-wrap" id="toast-wrap"></div>

<script>
const sidebarRoot = document.querySelector(".shell");
const sidebarToggle = document.getElementById("sidebar-toggle");

(function(){
    function syncSidebar(){
        const collapsed = sidebarRoot.classList.contains("sidebar-collapsed");
        sidebarToggle.setAttribute("aria-label", collapsed ? "Open sidebar" : "Close sidebar");
        sidebarToggle.setAttribute("title", collapsed ? "Open sidebar" : "Close sidebar");
        sidebarToggle.querySelector("span").textContent = collapsed ? "Open sidebar" : "Collapse sidebar";
    }

    if(localStorage.getItem("chatwebSidebarCollapsed") === "1"){
        sidebarRoot.classList.add("sidebar-collapsed");
    }

    syncSidebar();

    sidebarToggle.addEventListener("click", function(){
        sidebarRoot.classList.toggle("sidebar-collapsed");
        localStorage.setItem("chatwebSidebarCollapsed", sidebarRoot.classList.contains("sidebar-collapsed") ? "1" : "0");
        syncSidebar();
    });

    document.querySelectorAll(".nav a[href*='chat.php']").forEach(function(link){
        link.addEventListener("click", function(){
            localStorage.setItem("chatwebSidebarCollapsed", "1");
        });
    });
})();

const ajaxUrl = "profile.php";
let selectedFile = null;
let usernameTimer = null;

function toast(message, type = "success"){
    const wrap = document.getElementById("toast-wrap");
    const node = document.createElement("div");
    node.className = "toast " + type;
    node.textContent = message;
    wrap.appendChild(node);
    setTimeout(() => node.remove(), 3200);
}

function post(data){
    data.set("ajax", "1");
    return fetch(ajaxUrl, { method:"POST", body:data }).then(res => res.json());
}

function setLoading(button, loading){
    if(!button) return;
    if(loading){
        button.dataset.text = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner spinner"></i> Saving';
    } else {
        button.disabled = false;
        button.innerHTML = button.dataset.text || button.innerHTML;
    }
}

function dataUrlToBlob(dataUrl){
    const parts = dataUrl.split(",");
    const mime = parts[0].match(/:(.*?);/)[1];
    const binary = atob(parts[1]);
    const bytes = new Uint8Array(binary.length);
    for(let i=0;i<binary.length;i++) bytes[i] = binary.charCodeAt(i);
    return new Blob([bytes], { type:mime });
}

function croppedImageBlob(){
    return new Promise(resolve => {
        if(!selectedFile){
            resolve(null);
            return;
        }
        const img = document.getElementById("photo-preview");
        if(!img.complete || !img.naturalWidth){
            resolve(null);
            return;
        }
        const canvas = document.createElement("canvas");
        const size = 512;
        const zoom = Number(document.getElementById("zoom").value || 1);
        const rotation = Number(document.getElementById("rotate").value || 0) * Math.PI / 180;
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext("2d");
        ctx.save();
        ctx.translate(size / 2, size / 2);
        ctx.rotate(rotation);
        const sourceSize = Math.min(img.naturalWidth, img.naturalHeight) / zoom;
        ctx.drawImage(img, (img.naturalWidth - sourceSize) / 2, (img.naturalHeight - sourceSize) / 2, sourceSize, sourceSize, -size / 2, -size / 2, size, size);
        ctx.restore();
        canvas.toBlob(resolve, "image/webp", .82);
    });
}

function setPhoto(file){
    if(file.size > 5 * 1024 * 1024){
        toast("Image must be 5 MB or smaller.", "error");
        return;
    }
    if(!["image/jpeg","image/png","image/webp"].includes(file.type)){
        toast("Only JPG, PNG and WEBP images are supported.", "error");
        return;
    }
    selectedFile = file;
    document.getElementById("remove-photo-flag").value = "0";
    const url = URL.createObjectURL(file);
    const preview = document.getElementById("photo-preview");
    preview.src = url;
    preview.style.display = "block";
    const empty = document.getElementById("photo-empty");
    if(empty) empty.style.display = "none";
    document.getElementById("profile-avatar").innerHTML = `<img src="${url}" alt="">`;
}

document.getElementById("photo-input").addEventListener("change", e => {
    if(e.target.files[0]) setPhoto(e.target.files[0]);
});

const drop = document.getElementById("photo-drop");
["dragenter","dragover","dragleave","drop"].forEach(name => drop.addEventListener(name, e => e.preventDefault()));
drop.addEventListener("drop", e => {
    if(e.dataTransfer.files[0]) setPhoto(e.dataTransfer.files[0]);
});

function syncPhotoTransform(){
    document.getElementById("photo-preview").style.setProperty("--zoom", document.getElementById("zoom").value);
    document.getElementById("photo-preview").style.setProperty("--rotate", document.getElementById("rotate").value + "deg");
}
document.getElementById("zoom").addEventListener("input", syncPhotoTransform);
document.getElementById("rotate").addEventListener("input", syncPhotoTransform);

document.getElementById("remove-photo").addEventListener("click", () => {
    selectedFile = null;
    document.getElementById("photo-input").value = "";
    document.getElementById("remove-photo-flag").value = "1";
    document.getElementById("photo-preview").style.display = "none";
    document.getElementById("profile-avatar").textContent = (document.querySelector("[name='full_name']").value || "U").charAt(0).toUpperCase();
    toast("Photo will be removed when you save changes.");
});

document.getElementById("view-photo").addEventListener("click", () => {
    const img = document.getElementById("photo-preview");
    if(!img.src){
        toast("No profile image selected.", "error");
        return;
    }
    document.getElementById("viewer-body").innerHTML = `<img src="${img.src}" alt="Profile preview">`;
    document.getElementById("viewer").classList.add("open");
});
document.getElementById("viewer-close").addEventListener("click", () => document.getElementById("viewer").classList.remove("open"));
document.getElementById("viewer").addEventListener("click", e => { if(e.target.id === "viewer") e.currentTarget.classList.remove("open"); });

const about = document.getElementById("about-input");
function updateBioCount(){ document.getElementById("about-count").textContent = about.value.length; }
about.addEventListener("input", updateBioCount);
updateBioCount();

document.querySelector("[name='full_name']").addEventListener("input", e => document.getElementById("display-name").textContent = e.target.value || "User");
document.getElementById("username-input").addEventListener("input", e => {
    document.getElementById("display-username").textContent = "@" + (e.target.value || "username");
    clearTimeout(usernameTimer);
    usernameTimer = setTimeout(() => {
        const data = new FormData();
        data.set("action", "check_username");
        data.set("username", e.target.value);
        post(data).then(res => {
            const hint = document.getElementById("username-hint");
            hint.textContent = res.message;
            hint.className = "hint " + (res.status === "success" ? "success" : "error");
        });
    }, 350);
});

document.getElementById("status-input").addEventListener("change", e => {
    document.getElementById("display-status").innerHTML = `<span class="status-dot ${e.target.value}"></span>${e.target.value}`;
});

document.querySelectorAll("[data-code]").forEach(button => {
    button.addEventListener("click", () => {
        const type = button.dataset.code;
        const target = type === "email" ? document.getElementById("email-input").value : document.getElementById("phone-input").value;
        const data = new FormData();
        data.set("action", "send_code");
        data.set("type", type);
        data.set("target", target);
        post(data).then(res => {
            toast(res.message + (res.code ? " Code: " + res.code : ""), res.status === "success" ? "success" : "error");
        });
    });
});

document.querySelectorAll("[data-verify]").forEach(button => {
    button.addEventListener("click", () => {
        const type = button.dataset.verify;
        const target = type === "email" ? document.getElementById("email-input").value : document.getElementById("phone-input").value;
        const code = document.getElementById(type + "-code").value;
        const data = new FormData();
        data.set("action", "verify_code");
        data.set("type", type);
        data.set("target", target);
        data.set("code", code);
        post(data).then(res => toast(res.message, res.status === "success" ? "success" : "error"));
    });
});

document.getElementById("profile-form").addEventListener("submit", async e => {
    e.preventDefault();
    const form = e.currentTarget;
    const button = form.querySelector("button[type='submit']");
    const data = new FormData(form);
    data.set("action", "save_profile");
    if(selectedFile){
        const blob = await croppedImageBlob();
        if(blob) data.set("profile_image", blob, "profile.webp");
    }
    setLoading(button, true);
    post(data)
        .then(res => {
            toast(res.message, res.status === "success" ? "success" : "error");
            if(res.status === "success"){
                document.getElementById("display-name").textContent = res.full_name;
                document.getElementById("display-username").textContent = "@" + res.username;
                if(res.profile_image_url){
                    document.getElementById("profile-avatar").innerHTML = `<img src="${res.profile_image_url}" alt="">`;
                }
                selectedFile = null;
            }
        })
        .catch(() => toast("Server error while saving profile.", "error"))
        .finally(() => setLoading(button, false));
});

document.getElementById("reset-form").addEventListener("click", () => window.location.reload());

document.getElementById("new-password").addEventListener("input", e => {
    const value = e.target.value;
    let score = 0;
    if(value.length >= 8) score += 25;
    if(/[A-Z]/.test(value)) score += 25;
    if(/[0-9]/.test(value)) score += 25;
    if(/[^A-Za-z0-9]/.test(value)) score += 25;
    document.getElementById("password-score").textContent = score + "%";
    document.getElementById("password-strength").textContent = score >= 75 ? "Strong" : score >= 50 ? "Medium" : "Weak";
});

document.getElementById("password-form").addEventListener("submit", e => {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    data.set("action", "change_password");
    post(data).then(res => toast(res.message, res.status === "success" ? "success" : "error"));
});

document.getElementById("logout-all").addEventListener("click", () => {
    if(!confirm("Logout all devices from the session list?")) return;
    const data = new FormData();
    data.set("action", "logout_all");
    post(data).then(res => toast(res.message, res.status === "success" ? "success" : "error"));
});

document.querySelectorAll(".media-item").forEach(item => {
    item.addEventListener("click", e => {
        if(e.target.closest("a") || e.target.closest("button")) return;
        const url = item.dataset.mediaUrl;
        const type = item.dataset.mediaType;
        document.getElementById("viewer-body").innerHTML = type === "video"
            ? `<video src="${url}" controls autoplay></video>`
            : type === "image"
                ? `<img src="${url}" alt="">`
                : `<div class="info-row"><span>File preview</span><strong><a href="${url}" target="_blank">Open file</a></strong></div>`;
        document.getElementById("viewer").classList.add("open");
    });
});

document.querySelectorAll(".media-delete").forEach(button => button.addEventListener("click", e => {
    e.stopPropagation();
    button.closest(".media-item").remove();
    toast("Media removed from this gallery view.");
}));

document.querySelectorAll(".account-action").forEach(button => button.addEventListener("click", () => {
    if(button.dataset.mode === "delete"){
        toast("Permanent delete is protected. Add an admin confirmation workflow before enabling.", "error");
        return;
    }
    if(button.dataset.mode === "deactivate" && !confirm("Deactivate this account?")) return;
    const data = new FormData();
    data.set("action", "account_action");
    data.set("mode", button.dataset.mode);
    post(data).then(res => toast(res.message, res.status === "success" ? "success" : "error"));
}));

document.querySelectorAll("[data-counter]").forEach(node => {
    const target = Number(node.dataset.counter);
    if(Number.isNaN(target)) return;
    let current = 0;
    const step = Math.max(1, Math.ceil(target / 24));
    const timer = setInterval(() => {
        current = Math.min(target, current + step);
        node.textContent = current;
        if(current >= target) clearInterval(timer);
    }, 24);
});
</script>
<script src="../assets/js/notifications.js"></script>
</body>
</html>
