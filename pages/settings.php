<?php
require_once __DIR__ . "/../config/session.php";
include __DIR__ . "/../config/config.php";

chatweb_ensure_auth_schema($conn);
chatweb_require_login($conn, '../index.php');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$current_user = (int) $_SESSION['user_id'];

function ensure_column_settings_page($conn, $table, $column, $definition)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $exists = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");

    if ($exists && mysqli_num_rows($exists) === 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensure_settings_schema_page($conn)
{
    ensure_column_settings_page($conn, "users", "about", "varchar(255) NULL");
    ensure_column_settings_page($conn, "users", "phone", "varchar(50) NULL");
    ensure_column_settings_page($conn, "users", "date_of_birth", "date NULL");
    ensure_column_settings_page($conn, "users", "country", "varchar(100) NULL");
    ensure_column_settings_page($conn, "users", "timezone", "varchar(80) NULL");

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

function row_for_user($conn, $table, $user_id)
{
    mysqli_query($conn, "INSERT IGNORE INTO `$table` (user_id) VALUES ($user_id)");
    $res = mysqli_query($conn, "SELECT * FROM `$table` WHERE user_id=$user_id LIMIT 1");
    return $res ? (mysqli_fetch_assoc($res) ?: []) : [];
}

function h($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function selected_option($current, $value)
{
    return (string) $current === (string) $value ? 'selected' : '';
}

function checked_attr($value)
{
    return (int) $value === 1 ? 'checked' : '';
}

function directory_size($path)
{
    if (!is_dir($path)) {
        return 0;
    }

    $size = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }

    return $size;
}

function human_size($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

ensure_settings_schema_page($conn);

$session_browser = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Current browser', 0, 120);
$session_ip = substr($_SERVER['REMOTE_ADDR'] ?? 'localhost', 0, 60);
$stmt_clean_session = mysqli_prepare($conn, "DELETE FROM user_sessions WHERE user_id=? AND browser=? AND ip_address=?");
mysqli_stmt_bind_param($stmt_clean_session, "iss", $current_user, $session_browser, $session_ip);
mysqli_stmt_execute($stmt_clean_session);
$stmt_session = mysqli_prepare($conn, "INSERT INTO user_sessions (user_id, browser, ip_address, device, last_active) VALUES (?, ?, ?, 'Web', NOW())");
mysqli_stmt_bind_param($stmt_session, "iss", $current_user, $session_browser, $session_ip);
mysqli_stmt_execute($stmt_session);

$stmt = mysqli_prepare($conn, "SELECT id, full_name, username, email, status, profile_image, about, phone, date_of_birth, country, timezone FROM users WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $current_user);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];

$settings = row_for_user($conn, "user_settings", $current_user);
$notifications = row_for_user($conn, "notification_preferences", $current_user);
$privacy = row_for_user($conn, "privacy_settings", $current_user);
$theme = row_for_user($conn, "theme_settings", $current_user);

$full_name = $user['full_name'] ?? ($_SESSION['full_name'] ?? 'User');
$email = $user['email'] ?? ($_SESSION['email'] ?? '');
$username = $user['username'] ?? ($_SESSION['username'] ?? '');
$status = $user['status'] ?? ($_SESSION['status'] ?? 'online');
$profile_image = $user['profile_image'] ?? ($_SESSION['profile_image'] ?? '');
$initial = strtoupper(substr(trim($full_name ?: $username ?: 'U'), 0, 1));
$profile_image_url = '';

if (!empty($profile_image)) {
    $profile_image_url = str_starts_with($profile_image, 'uploads/')
        ? '../' . $profile_image
        : '../uploads/' . $profile_image;
}

$uploads_size = directory_size(__DIR__ . '/../uploads');
$db_size = 0;
$db_name = mysqli_real_escape_string($conn, mysqli_fetch_row(mysqli_query($conn, "SELECT DATABASE()"))[0] ?? '');
$db_res = mysqli_query($conn, "SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema='$db_name'");
if ($db_res) {
    $db_size = (int) (mysqli_fetch_assoc($db_res)['size'] ?? 0);
}
$total_storage = max($uploads_size + $db_size, 1);
$quota = 5 * 1024 * 1024 * 1024;
$storage_percent = min(100, round(($total_storage / $quota) * 100));

$sessions = mysqli_query($conn, "SELECT browser, ip_address, device, last_active FROM user_sessions WHERE user_id=$current_user ORDER BY last_active DESC LIMIT 4");
$backups = mysqli_query($conn, "SELECT backup_name, status, created_at FROM user_backups WHERE user_id=$current_user ORDER BY created_at DESC LIMIT 4");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | Chat Web</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<style>
:root{--bg:#F5F7FB;--surface:#fff;--muted:#64748B;--text:#111827;--border:#E8EDF5;--primary:#3870FF;--primary-dark:#2458E8;--danger:#EF4444;--success:#16A34A;--shadow:0 20px 55px rgba(15,23,42,.09);--soft:0 10px 28px rgba(15,23,42,.06);--radius:16px}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;min-height:100vh;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:linear-gradient(180deg,#F7FAFF,var(--bg));color:var(--text)}
.shell{min-height:100vh;display:grid;grid-template-columns:270px minmax(0,1fr)}.sidebar{padding:22px 18px;background:rgba(255,255,255,.94);border-right:1px solid var(--border);position:sticky;top:0;height:100vh}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px;padding:0 8px}.brand-icon,.avatar{display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff}.brand-icon{width:42px;height:42px;border-radius:12px;font-size:19px;box-shadow:0 14px 30px rgba(56,112,255,.24)}.brand h1{margin:0;font-size:19px}.brand span{display:block;margin-top:3px;color:var(--muted);font-size:12px}
.nav{display:grid;gap:7px}.nav a{display:flex;align-items:center;gap:12px;min-height:44px;padding:0 14px;border-radius:14px;color:#273449;text-decoration:none;font-weight:800;font-size:14px;transition:.22s}.nav a:hover,.nav a.active{background:rgba(56,112,255,.12);color:var(--primary)}.nav a.logout{color:#dc2626;margin-top:12px}.main{min-width:0;padding:28px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:24px}.topbar h2{margin:0;font-size:28px}.topbar p{margin:6px 0 0;color:var(--muted)}.avatar{width:46px;height:46px;border-radius:50%;font-weight:900;overflow:hidden}.avatar img{width:100%;height:100%;object-fit:cover}
.profile-pill{display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid var(--border);border-radius:14px;background:#fff;box-shadow:var(--soft)}.profile-pill strong{display:block}.profile-pill span{color:var(--muted);font-size:12px}.topbar-actions{display:flex;align-items:center;gap:10px}.power-btn{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 16px 32px rgba(220,38,38,.24)}
.settings-layout{display:grid;grid-template-columns:240px minmax(0,1fr);gap:20px}.settings-nav{position:sticky;top:24px;align-self:start;background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:14px;box-shadow:var(--soft)}.settings-nav span{display:block;margin:4px 8px 12px;color:var(--muted);font-size:12px;font-weight:900;letter-spacing:.08em}.settings-nav a{display:flex;align-items:center;gap:10px;min-height:40px;padding:0 12px;border-radius:12px;color:#334155;text-decoration:none;font-weight:800;font-size:13px}.settings-nav a:hover,.settings-nav a.active{background:#EEF4FF;color:var(--primary)}
.settings-content{display:grid;gap:18px}.panel{background:rgba(255,255,255,.96);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:22px;scroll-margin-top:24px}.panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}.panel h3{margin:0 0 6px;font-size:21px}.panel p{margin:0;color:var(--muted);line-height:1.5}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:grid;gap:7px}.field.full{grid-column:1/-1}.field label,.mini-label{font-size:12px;font-weight:900;color:#475569;text-transform:uppercase;letter-spacing:.04em}.input,.select,.textarea{width:100%;border:1px solid #d9e2ef;border-radius:13px;background:#fff;color:var(--text);padding:0 13px;min-height:44px;font:inherit;outline:0;transition:.18s}.textarea{min-height:96px;padding:12px 13px;resize:vertical}.input:focus,.select:focus,.textarea:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(56,112,255,.12)}
.setting-row{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:15px 0;border-bottom:1px solid #EEF2F7}.setting-row:last-child{border-bottom:0}.setting-title{display:flex;align-items:center;gap:12px}.setting-title i{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#EFF6FF;color:var(--primary)}.setting-title strong{display:block;margin-bottom:4px}.setting-title span{color:var(--muted);font-size:13px}
.toggle{position:relative;width:50px;height:28px;flex:0 0 50px}.toggle input{display:none}.slider{position:absolute;inset:0;border-radius:999px;background:#cbd5e1;cursor:pointer;transition:.2s}.slider:before{content:"";position:absolute;width:22px;height:22px;left:3px;top:3px;border-radius:50%;background:#fff;transition:.2s;box-shadow:0 2px 5px rgba(15,23,42,.2)}.toggle input:checked+.slider{background:var(--primary)}.toggle input:checked+.slider:before{transform:translateX(22px)}
.btn{border:0;border-radius:13px;min-height:42px;padding:0 16px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:9px;justify-content:center;transition:.2s}.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;box-shadow:0 14px 30px rgba(56,112,255,.22)}.btn-soft{background:#EEF4FF;color:var(--primary)}.btn-danger{background:#FEF2F2;color:#DC2626}.btn:hover{transform:translateY(-1px)}.btn[disabled]{opacity:.55;cursor:not-allowed;transform:none}.actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}
.photo-tools{display:grid;grid-template-columns:180px minmax(0,1fr);gap:18px;align-items:start}.photo-drop{height:180px;border:1px dashed #B8C7DD;border-radius:18px;background:#F8FBFF;display:grid;place-items:center;text-align:center;padding:14px;cursor:pointer;overflow:hidden}.photo-drop img{width:100%;height:100%;object-fit:cover;border-radius:14px}.crop-tools{display:grid;gap:12px}.preview-card{border:1px solid var(--border);border-radius:16px;background:#fff;padding:16px}.preview-row{display:flex;align-items:center;gap:12px}.preview-avatar{width:72px;height:72px;border-radius:50%;overflow:hidden;background:#EEF4FF;color:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:26px}.preview-avatar img{width:100%;height:100%;object-fit:cover}
.swatches{display:flex;flex-wrap:wrap;gap:10px}.swatch{width:34px;height:34px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 1px #CBD5E1;cursor:pointer}.swatch input{display:none}.swatch:has(input:checked){box-shadow:0 0 0 3px rgba(56,112,255,.28)}.wallpapers{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.wallpaper{height:72px;border-radius:14px;border:1px solid var(--border);cursor:pointer}
.storage-bar{height:14px;background:#E8EDF5;border-radius:999px;overflow:hidden;margin:16px 0}.storage-bar span{display:block;height:100%;width:var(--used);background:linear-gradient(90deg,var(--primary),#7C3AED)}.metric-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.metric{border:1px solid var(--border);border-radius:14px;background:#FBFDFF;padding:14px}.metric span{display:block;color:var(--muted);font-size:12px}.metric strong{font-size:18px}.list{display:grid;gap:10px}.list-item{border:1px solid var(--border);border-radius:14px;background:#fff;padding:13px;display:flex;align-items:center;justify-content:space-between;gap:12px}.list-item small{color:var(--muted)}
.toast-wrap{position:fixed;right:22px;bottom:22px;z-index:9999;display:grid;gap:10px}.toast{background:#111827;color:#fff;border-radius:14px;padding:13px 15px;box-shadow:0 18px 40px rgba(15,23,42,.22);animation:slideIn .22s ease}.toast.success{background:#047857}.toast.error{background:#DC2626}@keyframes slideIn{from{transform:translateY(10px);opacity:0}to{transform:none;opacity:1}}
.skeleton{position:relative;overflow:hidden;background:#EEF2F7}.skeleton:after{content:"";position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,transparent,rgba(255,255,255,.65),transparent);animation:shimmer 1.3s infinite}@keyframes shimmer{to{transform:translateX(100%)}}
body.dark-mode{--bg:#0F172A;--surface:#111827;--text:#F8FAFC;--muted:#94A3B8;--border:#243044;background:#0F172A;color:var(--text)}body.dark-mode .sidebar,body.dark-mode .panel,body.dark-mode .settings-nav,body.dark-mode .profile-pill,body.dark-mode .preview-card,body.dark-mode .list-item,body.dark-mode .metric,body.dark-mode .input,body.dark-mode .select,body.dark-mode .textarea{background:#111827;color:var(--text);border-color:#243044}
@media(max-width:1100px){.settings-layout{grid-template-columns:1fr}.settings-nav{position:relative;top:0;display:flex;overflow:auto}.settings-nav span{display:none}.settings-nav a{white-space:nowrap}.form-grid,.photo-tools{grid-template-columns:1fr}.wallpapers,.metric-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.shell{grid-template-columns:1fr}.sidebar{position:relative;width:100%;height:auto;border-right:0;border-bottom:1px solid var(--border)}.nav{grid-template-columns:repeat(2,minmax(0,1fr))}.main{padding:18px}.topbar{align-items:flex-start;flex-direction:column}.setting-row{align-items:flex-start;flex-direction:column}.select{width:100%}.panel{padding:18px}.wallpapers,.metric-grid{grid-template-columns:1fr}}
.sidebar-toggle{width:100%;min-height:42px;border:1px solid var(--border);border-radius:12px;background:#fff;color:#273449;display:flex;align-items:center;justify-content:center;gap:10px;cursor:pointer;box-shadow:0 12px 28px rgba(15,23,42,.06);font-weight:800;font-size:14px;transition:.2s}.sidebar-toggle:hover{color:var(--primary);background:#eff6ff;transform:translateY(-1px)}.shell,.sidebar,.brand,.nav a{transition:.22s ease}.shell.sidebar-collapsed{grid-template-columns:84px minmax(0,1fr)}.shell.sidebar-collapsed .sidebar{width:84px;padding:22px 11px;align-items:center}.shell.sidebar-collapsed .brand{width:100%;justify-content:center;padding:0;margin-bottom:22px}.shell.sidebar-collapsed .brand-icon{width:44px;height:44px;border-radius:14px}.shell.sidebar-collapsed .brand-copy,.shell.sidebar-collapsed .nav a span,.shell.sidebar-collapsed .sidebar-toggle span{display:none}.shell.sidebar-collapsed .nav{width:100%;gap:14px}.shell.sidebar-collapsed .nav a{width:54px;min-height:44px;justify-content:center;padding:0;border-radius:14px}.shell.sidebar-collapsed .nav a.active{background:rgba(56,112,255,.14);box-shadow:inset 0 0 0 1px rgba(56,112,255,.22)}.shell.sidebar-collapsed .nav a.logout{margin-top:12px}.shell.sidebar-collapsed .sidebar-toggle{width:44px;min-height:44px;margin-top:10px;padding:0;border-radius:14px}.shell.sidebar-collapsed .sidebar-toggle i{transform:rotate(180deg)}body.dark-mode .sidebar-toggle{background:#111827;color:var(--text);border-color:#243044}
html,body{width:100%;max-width:100%;overflow-x:hidden;-webkit-text-size-adjust:100%}img,video,canvas,svg{max-width:100%}button,input,select,textarea{font:inherit;max-width:100%}.shell{width:100%;min-width:0}.main{width:100%;max-width:1500px;margin:0 auto;padding-left:clamp(16px,2.5vw,34px);padding-right:clamp(16px,2.5vw,34px)}.topbar,.topbar-actions,.profile-pill,.panel-head,.actions,.setting-row,.setting-title,.list-item,.preview-row{min-width:0;flex-wrap:wrap}.topbar h2{font-size:clamp(24px,3vw,32px)}.panel,.settings-nav,.metric,.list-item,.preview-card{min-width:0}.panel h3{font-size:clamp(18px,2vw,22px)}.panel p,.setting-title span,.list-item strong,.list-item small,.metric span,.metric strong{overflow-wrap:anywhere}.settings-layout{grid-template-columns:minmax(210px,240px) minmax(0,1fr)}.wallpapers{grid-template-columns:repeat(auto-fit,minmax(min(100%,120px),1fr))}.metric-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,170px),1fr))}.toast-wrap{right:max(12px,env(safe-area-inset-right));bottom:max(12px,env(safe-area-inset-bottom));max-width:calc(100vw - 24px)}@media(max-width:1100px){.settings-nav{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.settings-nav a{flex:0 0 auto}}@media(max-width:900px){.shell,.shell.sidebar-collapsed{display:block}.sidebar,.shell.sidebar-collapsed .sidebar{position:relative;width:100%;height:auto;min-height:auto;border-right:0;border-bottom:1px solid var(--border)}.shell.sidebar-collapsed .brand-copy,.shell.sidebar-collapsed .nav a span,.shell.sidebar-collapsed .sidebar-toggle span{display:block}.shell.sidebar-collapsed .nav a{width:auto;justify-content:flex-start;padding:0 14px}.nav{grid-template-columns:repeat(auto-fit,minmax(135px,1fr))}.topbar{align-items:flex-start;flex-direction:column}}@media(max-width:620px){.main{padding:16px 12px calc(18px + env(safe-area-inset-bottom))}.panel{padding:16px}.form-grid,.photo-tools,.wallpapers,.metric-grid{grid-template-columns:1fr}.setting-row,.list-item{align-items:flex-start;flex-direction:column}.actions .btn{width:100%}.topbar-actions,.profile-pill{width:100%}.settings-nav{padding:10px;border-radius:12px}}
</style>
</head>
<body class="<?php echo ($theme['theme'] ?? 'Light') === 'Dark' ? 'dark-mode' : ''; ?>">
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fa-solid fa-comments"></i></div>
            <div class="brand-copy"><h1>Chat Web</h1><span>Messaging workspace</span></div>
        </div>
        <nav class="nav">
            <a href="dashboard.php"><i class="fa-solid fa-table-columns"></i> <span>Dashboard</span></a>
            <a href="chat.php"><i class="fa-solid fa-message"></i> <span>Chats</span></a>
            <a href="profile.php"><i class="fa-solid fa-user"></i> <span>Profile</span></a>
            <a href="settings.php" class="active"><i class="fa-solid fa-gear"></i> <span>Settings</span></a>
            <a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> <span>Logout</span></a>
            <button class="sidebar-toggle" id="sidebar-toggle" type="button" title="Close sidebar" aria-label="Close sidebar">
                <i class="fa-solid fa-chevron-left"></i>
                <span>Collapse sidebar</span>
            </button>
        </nav>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h2>Settings</h2>
                <p>Control privacy, notifications, appearance, storage and account behavior.</p>
            </div>
            <div class="topbar-actions">
                <div class="profile-pill">
                    <div class="avatar" id="top-avatar">
                        <?php if($profile_image_url){ ?>
                            <img src="<?php echo h($profile_image_url); ?>" alt="<?php echo h($full_name); ?>">
                        <?php } else { ?>
                            <?php echo h($initial); ?>
                        <?php } ?>
                    </div>
                    <div><strong id="top-name"><?php echo h($full_name); ?></strong><span id="top-email"><?php echo h($email); ?></span></div>
                </div>
                <div id="notification-inline"></div>
                <a class="power-btn" href="../logout.php" title="Logout"><i class="fa-solid fa-power-off"></i></a>
            </div>
        </div>

        <div class="settings-layout">
            <nav class="settings-nav" id="settings-nav">
                <span>SETTINGS</span>
                <a class="active" href="#profile"><i class="fa-solid fa-user"></i> Profile</a>
                <a href="#privacy"><i class="fa-solid fa-shield-halved"></i> Privacy</a>
                <a href="#notifications"><i class="fa-solid fa-bell"></i> Notifications</a>
                <a href="#appearance"><i class="fa-solid fa-palette"></i> Appearance</a>
                <a href="#chat"><i class="fa-solid fa-message"></i> Chat</a>
                <a href="#storage"><i class="fa-solid fa-database"></i> Storage</a>
                <a href="#security"><i class="fa-solid fa-lock"></i> Security</a>
                <a href="#backup"><i class="fa-solid fa-cloud-arrow-up"></i> Backup</a>
                <a href="#support"><i class="fa-solid fa-circle-question"></i> Help</a>
                <a href="#advanced"><i class="fa-solid fa-code"></i> Advanced</a>
            </nav>

            <div class="settings-content">
                <section class="panel" id="profile">
                    <div class="panel-head">
                        <div><h3>Profile</h3><p>Manage your public profile and account information.</p></div>
                    </div>
                    <form class="settings-form" data-section="profile" enctype="multipart/form-data">
                        <div class="photo-tools">
                            <label class="photo-drop" id="photo-drop">
                                <input type="file" id="profile-image-input" name="profile_image" accept="image/*" hidden>
                                <?php if($profile_image_url){ ?>
                                    <img id="photo-preview" src="<?php echo h($profile_image_url); ?>" alt="Profile photo">
                                <?php } else { ?>
                                    <span id="photo-empty"><i class="fa-solid fa-cloud-arrow-up"></i><br>Drop photo or click to upload</span>
                                    <img id="photo-preview" src="" alt="" style="display:none">
                                <?php } ?>
                            </label>
                            <div class="crop-tools">
                                <div class="preview-card">
                                    <div class="preview-row">
                                        <div class="preview-avatar" id="live-avatar">
                                            <?php if($profile_image_url){ ?><img src="<?php echo h($profile_image_url); ?>" alt="Preview"><?php } else { echo h($initial); } ?>
                                        </div>
                                        <div>
                                            <strong id="live-name"><?php echo h($full_name); ?></strong>
                                            <p id="live-about"><?php echo h($user['about'] ?: 'Building modern messaging experiences.'); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <label class="field">
                                    <span class="mini-label">Crop zoom</span>
                                    <input class="input" type="range" id="crop-zoom" min="1" max="2" step=".05" value="1">
                                </label>
                                <div>
                                    <button class="btn btn-soft" type="button" id="remove-photo"><i class="fa-solid fa-trash"></i> Remove photo</button>
                                </div>
                                <input type="hidden" name="remove_photo" id="remove-photo-flag" value="0">
                            </div>
                        </div>
                        <div class="form-grid" style="margin-top:16px">
                            <div class="field"><label>Full name</label><input class="input" name="full_name" value="<?php echo h($full_name); ?>" required></div>
                            <div class="field"><label>Username</label><input class="input" name="username" value="<?php echo h($username); ?>" required></div>
                            <div class="field"><label>Email</label><input class="input" type="email" name="email" value="<?php echo h($email); ?>" required></div>
                            <div class="field"><label>Phone number</label><input class="input" name="phone" value="<?php echo h($user['phone'] ?? ''); ?>"></div>
                            <div class="field"><label>Date of birth</label><input class="input" type="date" name="date_of_birth" value="<?php echo h($user['date_of_birth'] ?? ''); ?>"></div>
                            <div class="field"><label>Country</label><input class="input" name="country" value="<?php echo h($user['country'] ?? ''); ?>"></div>
                            <div class="field"><label>Timezone</label><input class="input" name="timezone" value="<?php echo h($user['timezone'] ?? date_default_timezone_get()); ?>"></div>
                            <div class="field"><label>Status</label><select class="select instant-save" data-section="status" name="status"><option <?php echo selected_option($status, 'online'); ?>>online</option><option <?php echo selected_option($status, 'away'); ?>>away</option><option <?php echo selected_option($status, 'offline'); ?>>offline</option></select></div>
                            <div class="field full"><label>About / bio</label><textarea class="textarea" name="about"><?php echo h($user['about'] ?? ''); ?></textarea></div>
                        </div>
                        <div class="actions"><button class="btn btn-soft" type="reset">Cancel</button><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save changes</button></div>
                    </form>
                </section>

                <section class="panel" id="privacy">
                    <div class="panel-head"><div><h3>Privacy</h3><p>Choose who can see your activity and profile details.</p></div></div>
                    <form class="settings-form" data-section="privacy">
                        <?php
                        $privacy_rows = [
                            ['last_seen_visibility', 'Last Seen', 'Control who sees your last active time', 'fa-eye'],
                            ['profile_photo_visibility', 'Profile Photo', 'Control who sees your photo', 'fa-image'],
                            ['about_visibility', 'About', 'Control who sees your bio', 'fa-address-card'],
                            ['online_status_visibility', 'Online Status', 'Control who sees your online state', 'fa-circle']
                        ];
                        foreach ($privacy_rows as $row) { ?>
                            <div class="setting-row"><div class="setting-title"><i class="fa-solid <?php echo h($row[3]); ?>"></i><div><strong><?php echo h($row[1]); ?></strong><span><?php echo h($row[2]); ?></span></div></div><select class="select" name="<?php echo h($row[0]); ?>"><option <?php echo selected_option($privacy[$row[0]] ?? '', 'Everyone'); ?>>Everyone</option><option <?php echo selected_option($privacy[$row[0]] ?? '', 'Contacts'); ?>>Contacts</option><option <?php echo selected_option($privacy[$row[0]] ?? '', 'Nobody'); ?>>Nobody</option></select></div>
                        <?php } ?>
                        <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-check-double"></i><div><strong>Read Receipts</strong><span>Show seen status and blue ticks</span></div></div><label class="toggle"><input name="read_receipts" type="checkbox" <?php echo checked_attr($privacy['read_receipts'] ?? 1); ?>><span class="slider"></span></label></div>
                        <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-keyboard"></i><div><strong>Typing Indicator</strong><span>Let others know when you are typing</span></div></div><label class="toggle"><input name="typing_indicator" type="checkbox" <?php echo checked_attr($privacy['typing_indicator'] ?? 1); ?>><span class="slider"></span></label></div>
                        <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-user-slash"></i><div><strong>Blocked Users</strong><span>Manage restricted contacts</span></div></div><button class="btn btn-soft action-only" data-action="manage-blocked-users" type="button">Manage list</button></div>
                        <div class="actions"><button class="btn btn-primary" type="submit">Save privacy</button></div>
                    </form>
                </section>

                <section class="panel" id="notifications">
                    <div class="panel-head"><div><h3>Notifications</h3><p>Control alerts, sounds, previews and mute behavior.</p></div><button class="btn btn-soft" id="test-notification" type="button"><i class="fa-solid fa-volume-high"></i> Test notification</button></div>
                    <form class="settings-form" data-section="notifications">
                        <?php
                        $notification_rows = [
                            ['message_notifications', 'Message notifications', 'Show alerts for new messages', 'fa-bell'],
                            ['group_notifications', 'Group notifications', 'Alert for group messages', 'fa-users'],
                            ['call_notifications', 'Call notifications', 'Ring for calls', 'fa-phone'],
                            ['desktop_notifications', 'Desktop notifications', 'Use browser desktop alerts', 'fa-desktop'],
                            ['browser_push', 'Browser push notifications', 'Allow push alerts where supported', 'fa-paper-plane'],
                            ['notification_sound', 'Notification sound', 'Play sound for new messages only', 'fa-music'],
                            ['vibrate_mobile', 'Vibrate on mobile', 'Use vibration on supported devices', 'fa-mobile-screen'],
                            ['preview_message', 'Preview message', 'Show message text in notifications', 'fa-align-left'],
                            ['mute_all_chats', 'Mute all chats', 'Silence every conversation', 'fa-bell-slash']
                        ];
                        foreach ($notification_rows as $row) { ?>
                            <div class="setting-row"><div class="setting-title"><i class="fa-solid <?php echo h($row[3]); ?>"></i><div><strong><?php echo h($row[1]); ?></strong><span><?php echo h($row[2]); ?></span></div></div><label class="toggle"><input name="<?php echo h($row[0]); ?>" type="checkbox" <?php echo checked_attr($notifications[$row[0]] ?? 0); ?>><span class="slider"></span></label></div>
                        <?php } ?>
                        <div class="form-grid">
                            <div class="field"><label>Mute specific users</label><input class="input" name="mute_specific_users" placeholder="Usernames separated by comma" value="<?php echo h($notifications['mute_specific_users'] ?? ''); ?>"></div>
                            <div class="field"><label>Notification volume</label><input class="input" name="notification_volume" type="range" min="0" max="100" value="<?php echo h($notifications['notification_volume'] ?? 70); ?>"></div>
                        </div>
                        <div class="actions"><button class="btn btn-primary" type="submit">Save notifications</button></div>
                    </form>
                </section>

                <section class="panel" id="appearance">
                    <div class="panel-head"><div><h3>Appearance</h3><p>Customize theme, accent color, wallpaper and density.</p></div></div>
                    <form class="settings-form" data-section="appearance">
                        <div class="form-grid">
                            <div class="field"><label>Theme</label><select class="select" name="theme" id="theme-select"><option <?php echo selected_option($theme['theme'] ?? '', 'Light'); ?>>Light</option><option <?php echo selected_option($theme['theme'] ?? '', 'Dark'); ?>>Dark</option><option <?php echo selected_option($theme['theme'] ?? '', 'System default'); ?>>System default</option></select></div>
                            <div class="field"><label>Font size</label><select class="select" name="font_size"><option <?php echo selected_option($theme['font_size'] ?? '', 'Small'); ?>>Small</option><option <?php echo selected_option($theme['font_size'] ?? '', 'Medium'); ?>>Medium</option><option <?php echo selected_option($theme['font_size'] ?? '', 'Large'); ?>>Large</option></select></div>
                            <div class="field"><label>Chat wallpaper</label><select class="select" name="wallpaper_type"><option <?php echo selected_option($theme['wallpaper_type'] ?? '', 'Solid'); ?>>Solid</option><option <?php echo selected_option($theme['wallpaper_type'] ?? '', 'Gradient'); ?>>Gradient</option><option <?php echo selected_option($theme['wallpaper_type'] ?? '', 'Custom image'); ?>>Custom image</option></select></div>
                            <div class="field"><label>Message bubble style</label><select class="select" name="bubble_style"><option <?php echo selected_option($theme['bubble_style'] ?? '', 'Rounded'); ?>>Rounded</option><option <?php echo selected_option($theme['bubble_style'] ?? '', 'Soft'); ?>>Soft</option><option <?php echo selected_option($theme['bubble_style'] ?? '', 'Compact'); ?>>Compact</option></select></div>
                            <div class="field full"><label>Accent color</label><div class="swatches">
                                <?php foreach (['Blue'=>'#3870FF','Green'=>'#16A34A','Purple'=>'#7C3AED','Red'=>'#EF4444','Orange'=>'#F97316'] as $label => $color) { ?>
                                    <label class="swatch" style="background:<?php echo h($color); ?>" title="<?php echo h($label); ?>"><input type="radio" name="accent_color" value="<?php echo h($label); ?>" <?php echo checked_attr(($theme['accent_color'] ?? 'Blue') === $label ? 1 : 0); ?>></label>
                                <?php } ?>
                            </div></div>
                            <div class="field full"><label>Wallpaper value</label><input class="input" name="wallpaper_value" value="<?php echo h($theme['wallpaper_value'] ?? '#F5F7FB'); ?>" placeholder="#F5F7FB or image path"></div>
                        </div>
                        <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-compress"></i><div><strong>Compact mode</strong><span>Reduce spacing in dense views</span></div></div><label class="toggle"><input name="compact_mode" type="checkbox" <?php echo checked_attr($theme['compact_mode'] ?? 0); ?>><span class="slider"></span></label></div>
                        <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-wand-magic-sparkles"></i><div><strong>Enable animations</strong><span>Use smooth motion and transitions</span></div></div><label class="toggle"><input name="enable_animations" type="checkbox" <?php echo checked_attr($theme['enable_animations'] ?? 1); ?>><span class="slider"></span></label></div>
                        <div class="actions"><button class="btn btn-primary" type="submit">Save appearance</button></div>
                    </form>
                </section>

                <section class="panel" id="chat">
                    <div class="panel-head"><div><h3>Chat Settings</h3><p>Control media handling, sending behavior and chat retention.</p></div></div>
                    <form class="settings-form" data-section="chat">
                        <div class="form-grid">
                            <div class="field"><label>Language</label><select class="select" name="language"><option <?php echo selected_option($settings['language'] ?? '', 'English'); ?>>English</option><option <?php echo selected_option($settings['language'] ?? '', 'Urdu'); ?>>Urdu</option></select></div>
                            <div class="field"><label>Time format</label><select class="select" name="time_format"><option <?php echo selected_option($settings['time_format'] ?? '', '12 hour'); ?>>12 hour</option><option <?php echo selected_option($settings['time_format'] ?? '', '24 hour'); ?>>24 hour</option></select></div>
                            <div class="field"><label>Delete messages after</label><select class="select" name="delete_after"><option <?php echo selected_option($settings['delete_after'] ?? '', 'Never'); ?>>Never</option><option <?php echo selected_option($settings['delete_after'] ?? '', '24h'); ?>>24h</option><option <?php echo selected_option($settings['delete_after'] ?? '', '7d'); ?>>7d</option><option <?php echo selected_option($settings['delete_after'] ?? '', '30d'); ?>>30d</option></select></div>
                        </div>
                        <?php
                        $chat_rows = [
                            ['auto_download_images', 'Auto-download images', 'Download images automatically', 'fa-image'],
                            ['auto_download_videos', 'Auto-download videos', 'Download videos automatically', 'fa-video'],
                            ['auto_download_documents', 'Auto-download documents', 'Download documents automatically', 'fa-file-lines'],
                            ['save_sent_media', 'Save sent media to gallery', 'Keep a copy of sent files', 'fa-floppy-disk'],
                            ['enter_to_send', 'Enter to send', 'Send messages with Enter', 'fa-turn-down'],
                            ['ctrl_enter_send', 'Send by Ctrl+Enter', 'Use Ctrl+Enter for sending', 'fa-keyboard'],
                            ['autoplay_gifs', 'Auto-play GIFs', 'Animate GIFs in chat', 'fa-film'],
                            ['autoplay_videos', 'Auto-play videos', 'Play videos automatically', 'fa-circle-play'],
                            ['message_preview', 'Message preview', 'Show message previews', 'fa-align-left'],
                            ['keep_chat_history', 'Keep chat history', 'Persist conversations', 'fa-clock-rotate-left'],
                            ['archive_inactive', 'Archive inactive chats', 'Move quiet chats into archive', 'fa-box-archive']
                        ];
                        foreach ($chat_rows as $row) { ?>
                            <div class="setting-row"><div class="setting-title"><i class="fa-solid <?php echo h($row[3]); ?>"></i><div><strong><?php echo h($row[1]); ?></strong><span><?php echo h($row[2]); ?></span></div></div><label class="toggle"><input name="<?php echo h($row[0]); ?>" type="checkbox" <?php echo checked_attr($settings[$row[0]] ?? 0); ?>><span class="slider"></span></label></div>
                        <?php } ?>
                        <div class="actions"><button class="btn btn-primary" type="submit">Save chat settings</button></div>
                    </form>
                </section>

                <section class="panel" id="storage">
                    <div class="panel-head"><div><h3>Storage & Data</h3><p>Review local file usage, cache and database footprint.</p></div></div>
                    <strong><?php echo h(human_size($total_storage)); ?> used</strong><span style="color:var(--muted)"> of 5 GB total</span>
                    <div class="storage-bar" style="--used:<?php echo (int) $storage_percent; ?>%"><span></span></div>
                    <div class="metric-grid">
                        <div class="metric"><span>Uploads / media</span><strong><?php echo h(human_size($uploads_size)); ?></strong></div>
                        <div class="metric"><span>Database size</span><strong><?php echo h(human_size($db_size)); ?></strong></div>
                        <div class="metric"><span>Cache</span><strong>Ready</strong></div>
                    </div>
                    <div class="actions" style="justify-content:flex-start"><button class="btn btn-soft action-only" data-action="clear-cache" type="button">Clear cache</button><button class="btn btn-soft action-only" data-action="manage-storage" type="button">Manage storage</button><button class="btn btn-soft action-only" data-action="download-data" type="button">Download my data</button><button class="btn btn-primary action-only" data-action="export-all" type="button">Export all chats</button></div>
                </section>

                <section class="panel" id="security">
                    <div class="panel-head"><div><h3>Security</h3><p>Manage password, sessions, device activity and account protection.</p></div></div>
                    <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-key"></i><div><strong>Change password</strong><span>Update your account password</span></div></div><button class="btn btn-soft action-only" data-action="change-password" type="button">Open</button></div>
                    <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-shield"></i><div><strong>Two-factor authentication</strong><span>Add a second verification step</span></div></div><label class="toggle"><input type="checkbox"><span class="slider"></span></label></div>
                    <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-triangle-exclamation"></i><div><strong>Security alerts</strong><span>Notify me about risky activity</span></div></div><label class="toggle"><input type="checkbox" checked><span class="slider"></span></label></div>
                    <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-lock"></i><div><strong>Auto-lock after inactivity</strong><span>Lock the app on shared devices</span></div></div><select class="select"><option>Never</option><option>5 minutes</option><option>15 minutes</option><option>1 hour</option></select></div>
                    <h4>Active devices</h4>
                    <div class="list">
                        <?php if($sessions && mysqli_num_rows($sessions) > 0){ while($session = mysqli_fetch_assoc($sessions)){ ?>
                            <div class="list-item"><div><strong><?php echo h($session['device'] ?: 'Web'); ?></strong><br><small><?php echo h(substr($session['browser'], 0, 70)); ?> - <?php echo h($session['ip_address']); ?></small></div><small><?php echo h($session['last_active']); ?></small></div>
                        <?php }} else { ?>
                            <div class="list-item"><div><strong>No active sessions found</strong><br><small>Your current browser will appear here.</small></div></div>
                        <?php } ?>
                    </div>
                    <div class="actions" style="justify-content:flex-start"><button class="btn btn-danger" id="logout-all" type="button">Logout from all devices</button><button class="btn btn-soft action-only" data-action="backup-codes" type="button">Backup codes</button></div>
                </section>

                <section class="panel" id="backup">
                    <div class="panel-head"><div><h3>Backup & Sync</h3><p>Prepare exports and backups for this account.</p></div></div>
                    <form class="settings-form" data-section="backup">
                        <div class="form-grid">
                            <div class="field"><label>Auto backup schedule</label><select class="select" name="schedule"><option>Manual</option><option>Daily</option><option>Weekly</option><option>Monthly</option></select></div>
                            <div class="field"><label>Encryption password</label><input class="input" type="password" name="encryption_password" placeholder="Optional"></div>
                        </div>
                        <div class="setting-row"><div class="setting-title"><i class="fa-solid fa-photo-film"></i><div><strong>Include media</strong><span>Export images, videos, audio and documents</span></div></div><label class="toggle"><input name="include_media" type="checkbox" checked><span class="slider"></span></label></div>
                        <div class="actions" style="justify-content:flex-start"><button class="btn btn-primary" id="create-backup" type="button">Backup chats</button><button class="btn btn-soft action-only" data-action="restore-backup" type="button">Restore backup</button><button class="btn btn-soft action-only" data-action="cloud-sync" type="button">Cloud sync</button><button class="btn btn-soft action-only" data-action="export-backup" type="button">Export backup file</button></div>
                    </form>
                    <div class="list">
                        <?php if($backups && mysqli_num_rows($backups) > 0){ while($backup = mysqli_fetch_assoc($backups)){ ?>
                            <div class="list-item"><div><strong><?php echo h($backup['backup_name']); ?></strong><br><small><?php echo h($backup['created_at']); ?></small></div><small><?php echo h($backup['status']); ?></small></div>
                        <?php }} ?>
                    </div>
                </section>

                <section class="panel" id="support">
                    <div class="panel-head"><div><h3>Help & Support</h3><p>Find help, shortcuts, support and app information.</p></div></div>
                    <div class="list">
                        <?php foreach (['Help center','Keyboard shortcuts','Report a bug','Contact support','FAQ','Release notes','Check for updates','App version 1.0.0'] as $item) { ?>
                            <button class="list-item action-only" data-action="<?php echo h(strtolower(str_replace(' ', '-', $item))); ?>" type="button"><strong><?php echo h($item); ?></strong><i class="fa-solid fa-chevron-right"></i></button>
                        <?php } ?>
                    </div>
                </section>

                <section class="panel" id="advanced">
                    <div class="panel-head"><div><h3>Advanced Settings</h3><p>Developer-style controls for debugging and performance.</p></div></div>
                    <form class="settings-form" data-section="advanced">
                        <div class="form-grid">
                            <div class="field"><label>Polling interval</label><input class="input" type="number" min="1000" step="500" name="polling_interval" value="<?php echo h($settings['polling_interval'] ?? 3000); ?>"></div>
                            <div class="field"><label>API endpoint</label><input class="input" name="api_endpoint" value="<?php echo h($settings['api_endpoint'] ?? '../ajax/'); ?>"></div>
                        </div>
                        <?php foreach ([['debug_mode','Debug mode','Show diagnostic logs','fa-bug'],['websocket_enabled','Enable WebSocket','Use socket transport when available','fa-plug'],['clear_local_storage','Clear local storage','Remove local UI state on next reload','fa-broom'],['reset_ui_settings','Reset UI settings','Restore interface defaults','fa-rotate-left'],['performance_mode','Performance mode','Reduce visual work on slower devices','fa-gauge-high']] as $row) { ?>
                            <div class="setting-row"><div class="setting-title"><i class="fa-solid <?php echo h($row[3]); ?>"></i><div><strong><?php echo h($row[1]); ?></strong><span><?php echo h($row[2]); ?></span></div></div><label class="toggle"><input name="<?php echo h($row[0]); ?>" type="checkbox" <?php echo checked_attr($settings[$row[0]] ?? 0); ?>><span class="slider"></span></label></div>
                        <?php } ?>
                        <div class="actions"><button class="btn btn-primary" type="submit">Save advanced</button></div>
                    </form>
                </section>
            </div>
        </div>
    </main>
</div>

<div class="toast-wrap" id="toast-wrap"></div>
<script>
const sidebarRoot = document.querySelector(".shell");
const sidebarToggle = document.getElementById("sidebar-toggle");

(function(){
    function syncSidebar(){
        if(window.matchMedia("(max-width: 900px)").matches){
            sidebarRoot.classList.remove("sidebar-collapsed");
        }

        const collapsed = sidebarRoot.classList.contains("sidebar-collapsed");
        sidebarToggle.setAttribute("aria-label", collapsed ? "Open sidebar" : "Close sidebar");
        sidebarToggle.setAttribute("title", collapsed ? "Open sidebar" : "Close sidebar");
        sidebarToggle.querySelector("span").textContent = collapsed ? "Open sidebar" : "Collapse sidebar";
    }

    if(localStorage.getItem("chatwebSidebarCollapsed") === "1" && !window.matchMedia("(max-width: 900px)").matches){
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

    window.addEventListener("resize", syncSidebar);
})();

const endpoint = "../ajax/settings.php";
let selectedProfileFile = null;

function toast(message, type = "success"){
    const wrap = document.getElementById("toast-wrap");
    const item = document.createElement("div");
    item.className = "toast " + type;
    item.textContent = message;
    wrap.appendChild(item);
    setTimeout(() => item.remove(), 3200);
}

function normalizeFormData(form){
    const data = new FormData(form);
    form.querySelectorAll("input[type='checkbox']").forEach(input => {
        data.set(input.name, input.checked ? "1" : "0");
    });
    data.set("action", "save");
    data.set("section", form.dataset.section);
    return data;
}

async function saveForm(form){
    const button = form.querySelector("button[type='submit']");
    const oldText = button ? button.innerHTML : "";
    const data = normalizeFormData(form);

    if(form.dataset.section === "profile" && selectedProfileFile){
        const blob = await croppedProfileBlob();
        if(blob){
            data.set("profile_image", blob, selectedProfileFile.name.replace(/\.[^.]+$/, "") + ".png");
        }
    }

    if(button){
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving';
    }

    fetch(endpoint, { method:"POST", body:data })
        .then(res => res.json())
        .then(data => {
            if(data.status !== "success"){
                toast(data.message || "Settings could not be saved.", "error");
                return;
            }

            toast(data.message || "Settings saved.");

            if(form.dataset.section === "profile"){
                document.getElementById("top-name").textContent = form.full_name.value;
                document.getElementById("top-email").textContent = form.email.value;
                if(data.profile_image){
                    document.getElementById("top-avatar").innerHTML = `<img src="${data.profile_image}" alt="">`;
                }
            }

            if(form.dataset.section === "appearance"){
                applyThemePreview();
            }
        })
        .catch(() => toast("Server error while saving settings.", "error"))
        .finally(() => {
            if(button){
                button.disabled = false;
                button.innerHTML = oldText;
            }
        });
}

function croppedProfileBlob(){
    return new Promise(resolve => {
        const img = document.getElementById("photo-preview");
        if(!selectedProfileFile || !img.complete || !img.naturalWidth){
            resolve(null);
            return;
        }

        const size = 512;
        const canvas = document.createElement("canvas");
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext("2d");
        const zoom = Number(document.getElementById("crop-zoom").value || 1);
        const sourceSize = Math.min(img.naturalWidth, img.naturalHeight) / zoom;
        const sx = (img.naturalWidth - sourceSize) / 2;
        const sy = (img.naturalHeight - sourceSize) / 2;
        ctx.drawImage(img, sx, sy, sourceSize, sourceSize, 0, 0, size, size);
        canvas.toBlob(resolve, "image/png", .92);
    });
}

function applyThemePreview(){
    const theme = document.getElementById("theme-select").value;
    document.body.classList.toggle("dark-mode", theme === "Dark" || (theme === "System default" && window.matchMedia("(prefers-color-scheme: dark)").matches));
}

document.querySelectorAll(".settings-form").forEach(form => {
    form.addEventListener("submit", event => {
        event.preventDefault();

        if(form.dataset.section === "backup"){
            return;
        }

        saveForm(form);
    });
});

document.querySelectorAll(".instant-save").forEach(input => {
    input.addEventListener("change", () => {
        const data = new FormData();
        data.set("action", "save");
        data.set("section", input.dataset.section);
        data.set(input.name, input.value);
        fetch(endpoint, { method:"POST", body:data })
            .then(res => res.json())
            .then(data => toast(data.message || "Saved.", data.status === "success" ? "success" : "error"))
            .catch(() => toast("Server error while saving.", "error"));
    });
});

const photoDrop = document.getElementById("photo-drop");
const photoInput = document.getElementById("profile-image-input");
const photoPreview = document.getElementById("photo-preview");

["dragenter","dragover"].forEach(name => photoDrop.addEventListener(name, event => {
    event.preventDefault();
    photoDrop.classList.add("skeleton");
}));

["dragleave","drop"].forEach(name => photoDrop.addEventListener(name, event => {
    event.preventDefault();
    photoDrop.classList.remove("skeleton");
}));

photoDrop.addEventListener("drop", event => {
    const file = event.dataTransfer.files[0];
    if(file){
        setPhotoFile(file);
    }
});

photoInput.addEventListener("change", () => {
    if(photoInput.files[0]){
        setPhotoFile(photoInput.files[0]);
    }
});

function setPhotoFile(file){
    selectedProfileFile = file;
    document.getElementById("remove-photo-flag").value = "0";
    const url = URL.createObjectURL(file);
    photoPreview.src = url;
    photoPreview.style.display = "block";
    const empty = document.getElementById("photo-empty");
    if(empty){
        empty.style.display = "none";
    }
    document.getElementById("live-avatar").innerHTML = `<img src="${url}" alt="">`;
}

document.getElementById("remove-photo").addEventListener("click", () => {
    selectedProfileFile = null;
    photoInput.value = "";
    document.getElementById("remove-photo-flag").value = "1";
    photoPreview.removeAttribute("src");
    photoPreview.style.display = "none";
    document.getElementById("live-avatar").textContent = (document.querySelector("[name='full_name']").value || "U").trim().charAt(0).toUpperCase();
    toast("Photo will be removed when you save profile.");
});

document.querySelector("[name='full_name']").addEventListener("input", event => {
    document.getElementById("live-name").textContent = event.target.value || "User";
});

document.querySelector("[name='about']").addEventListener("input", event => {
    document.getElementById("live-about").textContent = event.target.value || "Building modern messaging experiences.";
});

document.getElementById("theme-select").addEventListener("change", applyThemePreview);

document.getElementById("test-notification").addEventListener("click", () => {
    fetch(endpoint, { method:"POST", body:new URLSearchParams({ action:"test_notification" }) })
        .then(res => res.json())
        .then(data => {
            toast(data.message || "Test notification delivered.");
            if("Notification" in window && Notification.permission === "granted"){
                new Notification("Chat Web", { body:"This is how message notifications will look." });
            } else if("Notification" in window && Notification.permission !== "denied"){
                Notification.requestPermission();
            }
        });
});

document.getElementById("logout-all").addEventListener("click", () => {
    if(!confirm("Logout from all devices?")){
        return;
    }

    fetch(endpoint, { method:"POST", body:new URLSearchParams({ action:"logout_all" }) })
        .then(res => res.json())
        .then(data => toast(data.message || "Sessions cleared.", data.status === "success" ? "success" : "error"));
});

document.getElementById("create-backup").addEventListener("click", () => {
    const includeMedia = document.querySelector("#backup [name='include_media']").checked ? "1" : "0";
    fetch(endpoint, { method:"POST", body:new URLSearchParams({ action:"backup", include_media:includeMedia }) })
        .then(res => res.json())
        .then(data => toast(data.message || "Backup action complete.", data.status === "success" ? "success" : "error"));
});

document.querySelectorAll(".action-only").forEach(button => {
    button.addEventListener("click", () => {
        toast(button.dataset.action.replaceAll("-", " ") + " is ready for the next backend workflow.");
    });
});

const sections = [...document.querySelectorAll(".panel")];
const navLinks = [...document.querySelectorAll(".settings-nav a")];
window.addEventListener("scroll", () => {
    let current = null;
    sections.forEach(section => {
        if(section.getBoundingClientRect().top < 160){
            current = section;
        }
    });

    if(!current){
        return;
    }
    navLinks.forEach(link => link.classList.toggle("active", link.getAttribute("href") === "#" + current.id));
});

applyThemePreview();
</script>
<script src="../assets/js/notifications.js"></script>
</body>
</html>
