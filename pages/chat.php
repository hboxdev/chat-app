<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include __DIR__ . "/../config/config.php";

function ensure_chat_column($conn, $table, $column, $definition)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $exists = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");

    if ($exists && mysqli_num_rows($exists) === 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

$current_user = (int) $_SESSION['user_id'];
$presence_timeout_seconds = 60;

mysqli_query($conn, "UPDATE users SET status='online', last_seen=NOW() WHERE id=" . (int)$current_user);

$currentUserStmt = mysqli_prepare($conn, "
    SELECT id, full_name, username, profile_image, status
    FROM users
    WHERE id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($currentUserStmt, "i", $current_user);
mysqli_stmt_execute($currentUserStmt);
$currentUserProfile = mysqli_fetch_assoc(mysqli_stmt_get_result($currentUserStmt)) ?: [];
mysqli_stmt_close($currentUserStmt);

$selfConversationTitle = "__saved_messages_" . $current_user;
$selfConversationId = 0;
$selfStmt = mysqli_prepare($conn, "
    SELECT c.id
    FROM conversations c
    JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ?
    WHERE c.created_by = ?
    AND c.title = ?
    LIMIT 1
");
mysqli_stmt_bind_param($selfStmt, "iis", $current_user, $current_user, $selfConversationTitle);
mysqli_stmt_execute($selfStmt);
$selfResult = mysqli_stmt_get_result($selfStmt);
$selfRow = mysqli_fetch_assoc($selfResult);
mysqli_stmt_close($selfStmt);

if ($selfRow) {
    $selfConversationId = (int) $selfRow['id'];
} else {
    $insertConversation = mysqli_prepare($conn, "
        INSERT INTO conversations (type, title, created_by, created_at)
        VALUES ('private', ?, ?, NOW())
    ");
    mysqli_stmt_bind_param($insertConversation, "si", $selfConversationTitle, $current_user);
    mysqli_stmt_execute($insertConversation);
    $selfConversationId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($insertConversation);

    if ($selfConversationId > 0) {
        $insertMember = mysqli_prepare($conn, "
            INSERT INTO conversation_members (conversation_id, user_id, joined_at)
            VALUES (?, ?, NOW())
        ");
        mysqli_stmt_bind_param($insertMember, "ii", $selfConversationId, $current_user);
        mysqli_stmt_execute($insertMember);
        mysqli_stmt_close($insertMember);
    }
}

$currentUserName = $currentUserProfile['full_name'] ?? ($_SESSION['full_name'] ?? 'Saved Messages');
$currentUsername = $currentUserProfile['username'] ?? ($_SESSION['username'] ?? '');
$currentUserStatus = $currentUserProfile['status'] ?? ($_SESSION['status'] ?? 'online');
$currentUserImage = $currentUserProfile['profile_image'] ?? ($_SESSION['profile_image'] ?? '');
$currentUserImageUrl = '';

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
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
ensure_chat_column($conn, "theme_settings", "wallpaper_type", "VARCHAR(30) DEFAULT 'Solid'");
ensure_chat_column($conn, "theme_settings", "wallpaper_value", "VARCHAR(255) DEFAULT '#F5F7FB'");
mysqli_query($conn, "INSERT IGNORE INTO theme_settings (user_id) VALUES ($current_user)");

$themeStmt = mysqli_prepare($conn, "
    SELECT wallpaper_type, wallpaper_value
    FROM theme_settings
    WHERE user_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($themeStmt, "i", $current_user);
mysqli_stmt_execute($themeStmt);
$currentThemeSettings = mysqli_fetch_assoc(mysqli_stmt_get_result($themeStmt)) ?: [];
mysqli_stmt_close($themeStmt);

if (!empty($currentUserImage)) {
    $currentUserImageUrl = str_starts_with($currentUserImage, 'uploads/')
        ? '../' . $currentUserImage
        : '../uploads/' . $currentUserImage;
}

$stmt = mysqli_prepare($conn, "
    SELECT id, full_name, profile_image, status, last_seen, created_at
    FROM users
    WHERE id != ?
    AND is_active = 1
    ORDER BY full_name ASC
");
mysqli_stmt_bind_param($stmt, "i", $current_user);
mysqli_stmt_execute($stmt);
$users = mysqli_stmt_get_result($stmt);

$groupStmt = mysqli_prepare($conn, "
    SELECT
        c.id,
        c.title,
        c.image,
        COUNT(DISTINCT cm_all.id) AS member_count,
        MAX(m.created_at) AS last_message_at
    FROM conversations c
    JOIN conversation_members cm_self
        ON cm_self.conversation_id = c.id
        AND cm_self.user_id = ?
    LEFT JOIN conversation_members cm_all
        ON cm_all.conversation_id = c.id
    LEFT JOIN messages m
        ON m.conversation_id = c.id
        AND m.is_deleted = 0
    WHERE c.type = 'group'
    GROUP BY c.id, c.title, c.image
    ORDER BY COALESCE(MAX(m.created_at), c.created_at) DESC
");
mysqli_stmt_bind_param($groupStmt, "i", $current_user);
mysqli_stmt_execute($groupStmt);
$groups = mysqli_stmt_get_result($groupStmt);

function effectivePresenceStatus($status, $last_seen, $timeout_seconds = 60)
{
    if ($status === 'away') {
        return 'away';
    }

    if ($status === 'online' && !empty($last_seen)) {
        $timestamp = strtotime($last_seen);

        if ($timestamp && (time() - $timestamp) <= $timeout_seconds) {
            return 'online';
        }
    }

    return 'offline';
}

function presenceText($status, $last_seen, $timeout_seconds = 60, $fallback_seen = null)
{
    $status = effectivePresenceStatus($status, $last_seen, $timeout_seconds);

    if ($status === 'online') {
        return 'online';
    }

    if ($status === 'away') {
        return 'away';
    }

    $seen_time = !empty($last_seen) ? $last_seen : $fallback_seen;
    $timestamp = strtotime($seen_time);

    if (!$timestamp) {
        return 'last seen at ' . date('h:i A');
    }

    $date = date('Y-m-d', $timestamp);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $time = date('h:i A', $timestamp);

    if ($date === $today) {
        return 'last seen today at ' . $time;
    }

    if ($date === $yesterday) {
        return 'last seen yesterday at ' . $time;
    }

    return 'last seen ' . date('M d, Y', $timestamp) . ' at ' . $time;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chat | Chat Web</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    min-height:100vh;
    font-family:Arial, Helvetica, sans-serif;
    background:
        radial-gradient(circle at 18% 12%, rgba(37,99,235,.10), transparent 28%),
        radial-gradient(circle at 88% 8%, rgba(14,165,233,.10), transparent 26%),
        #eaf0f7;
    color:#111827;
}

.page-shell{
    min-height:100vh;
    display:flex;
}

.app-sidebar{
    width:270px;
    padding:24px 18px;
    background:linear-gradient(180deg,#ffffff,#f8fbff);
    border-right:1px solid rgba(203,213,225,.9);
    display:flex;
    flex-direction:column;
    gap:24px;
    box-shadow:10px 0 30px rgba(15,23,42,.04);
}

.brand{
    display:flex;
    align-items:center;
    gap:12px;
    padding:0 8px;
}

.brand-icon{
    width:44px;
    height:44px;
    border-radius:12px;
    background:linear-gradient(135deg,#2f6df6,#1d4ed8);
    color:#ffffff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:19px;
    box-shadow:0 12px 24px rgba(37,99,235,.26), inset 0 1px 0 rgba(255,255,255,.35);
}

.brand h1{
    margin:0;
    font-size:19px;
}

.brand span{
    display:block;
    margin-top:3px;
    color:#64748b;
    font-size:12px;
}

.app-nav{
    display:grid;
    gap:6px;
}

.app-nav a{
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    border-radius:10px;
    color:#334155;
    text-decoration:none;
    font-weight:700;
    font-size:14px;
}

.app-nav a:hover,
.app-nav a.active{
    background:linear-gradient(135deg,#eff6ff,#e8f1ff);
    color:#2563eb;
    box-shadow:inset 0 0 0 1px rgba(147,197,253,.35);
}

.app-nav a.logout{
    color:#dc2626;
    margin-top:10px;
}

.chat-page{
    flex:1;
    min-width:0;
    padding:28px 34px 24px;
}

.chat-topbar{
    width:min(1180px,100%);
    margin:0 auto 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
}

.chat-topbar h2{
    margin:0;
    font-size:30px;
    letter-spacing:.2px;
}

.chat-topbar p{
    margin:5px 0 0;
    color:#64748b;
    font-size:14px;
}

.topbar-actions{
    display:flex;
    align-items:center;
    gap:10px;
}

.power-btn{
    width:52px;
    height:52px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#ffffff;
    text-decoration:none;
    background:linear-gradient(135deg,#ef4444,#dc2626);
    box-shadow:0 16px 32px rgba(220,38,38,.28),inset 0 1px 0 rgba(255,255,255,.35),inset 0 -8px 18px rgba(127,29,29,.18);
}

.power-btn:hover{
    transform:translateY(-2px);
}

.chat-container{
    width:min(1180px,100%);
    height:calc(100vh - 124px);
    min-height:620px;
    margin:0 auto;
    background:rgba(255,255,255,.94);
    display:flex;
    border:1px solid rgba(203,213,225,.85);
    border-radius:14px;
    overflow:hidden;
    box-shadow:
        0 24px 60px rgba(15,23,42,.14),
        0 2px 0 rgba(255,255,255,.75) inset;
    backdrop-filter:blur(10px);
}

.users{
    width:350px;
    flex:0 0 350px;
    border-right:1px solid #e5e7eb;
    background:linear-gradient(180deg,#ffffff,#f7faff);
    display:flex;
    flex-direction:column;
}

.sidebar-head{
    padding:24px 22px 18px;
    border-bottom:1px solid #e5e7eb;
    background:#ffffff;
}

.sidebar-head h2{
    margin:0;
    font-size:28px;
    line-height:1.1;
}

.sidebar-head p{
    margin:6px 0 0;
    color:#64748b;
    font-size:13px;
}

.user-list{
    padding:14px 10px;
    overflow-y:auto;
}

.empty-users{
    padding:22px;
    color:#64748b;
    font-size:14px;
    text-align:center;
}

.user{
    width:100%;
    padding:13px;
    margin-bottom:8px;
    border:1px solid transparent;
    border-radius:12px;
    cursor:pointer;
    display:flex;
    align-items:center;
    gap:12px;
    transition:background .18s ease,border-color .18s ease,transform .18s ease;
}

.user:hover{
    background:#ffffff;
    border-color:#dbeafe;
    box-shadow:0 10px 24px rgba(15,23,42,.06);
}

.user.active{
    background:linear-gradient(135deg,#eff6ff,#eaf3ff);
    border-color:#93c5fd;
    box-shadow:0 14px 28px rgba(37,99,235,.12), inset 0 1px 0 rgba(255,255,255,.8);
}

.avatar{
    width:46px;
    height:46px;
    flex:0 0 46px;
    border-radius:50%;
    background:linear-gradient(135deg,#2f6df6,#1d4ed8);
    color:#ffffff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    text-transform:uppercase;
    overflow:hidden;
    box-shadow:0 10px 20px rgba(37,99,235,.20);
}

.avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.user-info{
    min-width:0;
    flex:1;
}

.user-name{
    display:block;
    font-size:15px;
    font-weight:700;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.user-status{
    display:flex;
    align-items:center;
    gap:6px;
    margin-top:4px;
    color:#64748b;
    font-size:12px;
    text-transform:none;
}

.status-dot{
    width:8px;
    height:8px;
    border-radius:50%;
    background:#94a3b8;
}

.status-dot.online{
    background:#16a34a;
}

.status-dot.away{
    background:#f59e0b;
}

.chat-area{
    min-width:0;
    flex:1;
    display:flex;
    flex-direction:column;
    background:#ffffff;
}

.chat-header{
    min-height:86px;
    padding:14px 22px;
    border-bottom:1px solid #e6edf5;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
    background:rgba(255,255,255,.98);
    box-shadow:0 10px 28px rgba(15,23,42,.05);
}

.chat-title{
    min-width:0;
    display:flex;
    align-items:center;
    gap:14px;
    cursor:pointer;
    flex:1;
}

.chat-title h3{
    margin:0;
    color:#111827;
    font-size:23px;
    line-height:1.1;
    font-weight:800;
    max-width:420px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.chat-header-avatar{
    width:52px;
    height:52px;
    flex:0 0 52px;
    border-radius:16px;
    background:linear-gradient(135deg,#2f6df6,#1d4ed8);
    color:#ffffff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
    box-shadow:0 12px 24px rgba(37,99,235,.16);
    overflow:hidden;
    border:1px solid rgba(226,232,240,.95);
}

.chat-header-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

#typing-status{
    min-height:17px;
    margin-top:3px;
    color:#2563eb;
    font-size:12px;
    line-height:1.25;
    font-weight:700;
}

#chat-presence{
    min-height:17px;
    margin-top:5px;
    color:#64748b;
    font-size:12px;
    line-height:1.25;
    font-weight:700;
}

#chat-presence.online{
    color:#12843b;
}

.chat-actions{
    display:flex;
    align-items:center;
    gap:9px;
    flex:0 0 auto;
}

.icon-btn{
    width:42px;
    height:42px;
    border:1px solid #dbe3ee;
    border-radius:12px;
    background:#ffffff;
    color:#1f2937;
    cursor:pointer;
    font-size:17px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    line-height:1;
    padding:0;
    box-shadow:0 10px 20px rgba(15,23,42,.06);
    transition:background .18s ease,border-color .18s ease,color .18s ease,transform .18s ease;
}

.icon-btn:hover{
    background:#eff6ff;
    border-color:#bfdbfe;
    color:#2563eb;
    transform:translateY(-1px);
}

.mobile-chat-back{
    display:none;
}

#messages{
    flex:1;
    overflow-y:auto;
    padding:28px 30px;
    background:
        linear-gradient(rgba(248,250,252,.92),rgba(248,250,252,.92)),
        radial-gradient(circle at 20px 20px, rgba(37,99,235,.07) 2px, transparent 3px);
    background-size:auto,28px 28px;
    display:flex;
    flex-direction:column;
    gap:12px;
}

.message-search{
    display:none;
    padding:12px 18px;
    border-bottom:1px solid #e5e7eb;
    background:#ffffff;
}

.message-search.open{
    display:block;
}

.message-search input{
    width:100%;
    height:42px;
    border:1px solid #dbe3ee;
    border-radius:12px;
    padding:0 14px;
    outline:none;
}

.message-search input:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.empty-state{
    min-height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    color:#64748b;
}

.empty-state-box{
    max-width:320px;
}

.empty-state-box strong{
    display:block;
    margin-bottom:8px;
    color:#111827;
    font-size:18px;
}

.message-row{
    display:flex;
}

.message-stack{
    display:flex;
    flex-direction:column;
}

.message-stack.sent{
    align-items:flex-end;
}

.message-stack.received{
    align-items:flex-start;
}

.message-row.sent{
    justify-content:flex-end;
    width:100%;
}

.message-row.received{
    justify-content:flex-start;
    width:100%;
}

.message-bubble{
    max-width:min(540px,100%);
    padding:11px 13px 9px;
    border-radius:16px;
    border:1px solid #e2e8f0;
    background:#ffffff;
    box-shadow:0 10px 24px rgba(15,23,42,.07);
    position:relative;
}

.message-with-reactions{
    position:relative;
    display:inline-block;
    max-width:min(540px,76%);
}

.message-with-reactions .message-bubble{
    max-width:100%;
}

.message-row.received .message-bubble{
    border-top-left-radius:5px;
}

.message-row.sent .message-bubble{
    border-top-right-radius:5px;
}

.message-row.sent .message-bubble{
    background:linear-gradient(135deg,#2f6df6 0%,#255fe8 48%,#1d4ed8 100%);
    color:#ffffff;
    border-color:#2458d8;
    box-shadow:0 14px 28px rgba(37,99,235,.20), inset 0 1px 0 rgba(255,255,255,.20);
}

.message-sender{
    display:block;
    margin-bottom:6px;
    font-size:11px;
    line-height:1.2;
    font-weight:800;
    color:#475569;
}

.reply-preview{
    margin-bottom:8px;
    padding:8px 10px;
    border-left:3px solid #2563eb;
    border-radius:10px;
    background:#f8fafc;
    color:#334155;
    font-size:12px;
    line-height:1.35;
    box-shadow:inset 0 0 0 1px rgba(226,232,240,.9);
}

.message-row.sent .reply-preview{
    background:rgba(255,255,255,.14);
    border-left-color:#dbeafe;
    color:#ffffff;
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.18);
}

.reply-preview strong{
    display:block;
    margin-bottom:2px;
    font-size:11px;
    line-height:1.25;
    font-weight:800;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.reply-preview span{
    display:block;
    opacity:.86;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.message-row.sent .message-sender{
    color:#dbeafe;
}

.message-text{
    margin:0;
    font-size:14px;
    line-height:1.45;
    word-wrap:break-word;
    white-space:pre-wrap;
}

.view-once-label{
    display:inline-flex;
    align-items:center;
    gap:5px;
    margin-bottom:6px;
    padding:3px 7px;
    border-radius:999px;
    background:#e0f2fe;
    color:#0369a1;
    font-size:11px;
    font-weight:700;
}

.message-row.sent .view-once-label{
    background:rgba(255,255,255,.18);
    color:#ffffff;
}

.view-once-placeholder{
    min-width:210px;
    max-width:280px;
    display:flex;
    align-items:center;
    gap:12px;
    text-align:left;
    margin-top:4px;
    padding:12px;
    border-radius:16px;
    background:#F8FAFC;
    color:#334155;
}

.message-row.sent .view-once-placeholder{
    background:rgba(255,255,255,.18);
    color:#FFFFFF;
}

.view-once-icon{
    width:42px;
    height:42px;
    flex:0 0 42px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
    background:#E0F2FE;
    color:#0369A1;
}

.view-once-icon i{
    display:block;
    line-height:1;
    font-size:17px;
}

.message-row.sent .view-once-icon{
    background:rgba(255,255,255,.20);
    color:#FFFFFF;
}

.view-once-placeholder strong{
    display:block;
    margin-bottom:2px;
    font-size:13px;
}

.view-once-placeholder span{
    display:block;
    font-size:12px;
    opacity:.78;
}

.message-meta{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:5px;
    margin-top:5px;
    font-size:10.5px;
    line-height:1;
    color:#64748b;
}

.message-row.sent .message-meta{
    color:rgba(219,234,254,.96);
}

.message-ticks{
    display:inline-flex;
    align-items:center;
    gap:2px;
    line-height:1;
}

.message-ticks.seen{
    color:#38bdf8;
}

.message-ticks i{
    font-size:12px;
}

.call-modal{
    position:fixed;
    inset:0;
    z-index:1000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    background:rgba(15,23,42,.62);
    backdrop-filter:blur(8px);
}

.call-modal.open{
    display:flex;
}

.call-box{
    width:min(720px,100%);
    overflow:hidden;
    border:1px solid rgba(226,232,240,.45);
    border-radius:14px;
    background:#0f172a;
    color:#ffffff;
    box-shadow:0 28px 80px rgba(2,6,23,.45);
}

.call-head{
    min-height:74px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:16px 18px;
    background:rgba(15,23,42,.96);
    border-bottom:1px solid rgba(148,163,184,.22);
}

.call-head strong{
    display:block;
    font-size:18px;
    line-height:1.25;
}

.call-head span{
    display:block;
    margin-top:4px;
    color:#cbd5e1;
    font-size:13px;
}

.call-small-btn{
    width:38px;
    height:38px;
    border:1px solid rgba(148,163,184,.3);
    border-radius:10px;
    background:rgba(255,255,255,.06);
    color:#ffffff;
    cursor:pointer;
}

.call-video-stage{
    position:relative;
    min-height:390px;
    background:#020617;
    display:flex;
    align-items:center;
    justify-content:center;
}

#remote-video{
    width:100%;
    height:100%;
    min-height:390px;
    object-fit:cover;
    background:#020617;
}

#local-video{
    position:absolute;
    right:16px;
    bottom:16px;
    width:158px;
    aspect-ratio:4/3;
    border:1px solid rgba(255,255,255,.35);
    border-radius:10px;
    object-fit:cover;
    background:#111827;
    box-shadow:0 16px 34px rgba(0,0,0,.35);
}

.call-audio-avatar{
    position:absolute;
    inset:0;
    display:none;
    align-items:center;
    justify-content:center;
    font-size:70px;
    color:#93c5fd;
    background:
        radial-gradient(circle at 50% 42%, rgba(37,99,235,.35), transparent 30%),
        #020617;
}

.call-modal.audio-call #remote-video,
.call-modal.audio-call #local-video{
    display:none;
}

.call-modal.audio-call .call-audio-avatar{
    display:flex;
}

.call-controls{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:12px;
    padding:16px;
    background:#0f172a;
}

.call-control-btn{
    width:50px;
    height:50px;
    border:0;
    border-radius:50%;
    background:#334155;
    color:#ffffff;
    cursor:pointer;
    font-size:17px;
}

.call-control-btn:hover{
    background:#475569;
}

.call-control-btn.accept{
    display:none;
    background:#16a34a;
}

.call-modal.incoming .call-control-btn.accept{
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.call-control-btn.danger{
    background:#dc2626;
}

.call-control-btn.is-off{
    background:#64748b;
    color:#cbd5e1;
}

.call-control-btn.is-on{
    background:#2563eb;
    color:#ffffff;
}

.seen-text{
    display:block;
    margin-top:4px;
    padding-right:6px;
    text-align:right;
    font-size:10.5px;
    color:#0ea5e9;
    font-weight:800;
}

.message-menu-btn{
    position:absolute;
    top:6px;
    right:6px;
    width:26px;
    height:26px;
    border:0;
    border-radius:6px;
    background:rgba(15,23,42,.08);
    color:inherit;
    cursor:pointer;
    opacity:0;
}

.message-bubble:hover .message-menu-btn,
.message-menu-btn:focus{
    opacity:1;
}

.message-menu{
    position:absolute;
    top:34px;
    right:6px;
    min-width:168px;
    padding:6px;
    border:1px solid #e5e7eb;
    border-radius:8px;
    background:#ffffff;
    box-shadow:0 16px 35px rgba(15,23,42,.18);
    display:none;
    z-index:15;
}

.message-menu.open{
    display:block;
}

.message-menu button{
    width:100%;
    border:0;
    border-radius:6px;
    background:#ffffff;
    padding:9px 10px;
    text-align:left;
    color:#111827;
    cursor:pointer;
    font-size:13px;
}

.message-menu a{
    width:100%;
    border-radius:6px;
    background:#ffffff;
    padding:9px 10px;
    text-align:left;
    color:#111827;
    cursor:pointer;
    font-size:13px;
    display:block;
    text-decoration:none;
}

.message-menu button:hover,
.message-menu a:hover{
    background:#f1f5f9;
}

.message-menu button.danger{
    color:#dc2626;
}

.reaction-bar{
    display:none;
    position:absolute;
    left:10px;
    top:-38px;
    gap:4px;
    padding:5px;
    border:1px solid #e5e7eb;
    border-radius:999px;
    background:#ffffff;
    box-shadow:0 16px 32px rgba(15,23,42,.16);
    z-index:20;
}

.reaction-bar.open{
    display:flex;
}

.reaction-bar button{
    width:30px;
    height:30px;
    border:0;
    border-radius:50%;
    background:#ffffff;
    cursor:pointer;
    font-size:17px;
}

.reaction-bar button:hover{
    background:#eff6ff;
}

.message-reactions{
    position:absolute;
    left:12px;
    bottom:-18px;
    display:flex;
    gap:5px;
    flex-wrap:wrap;
    z-index:8;
}

.message-stack.sent .message-reactions{
    left:auto;
    right:12px;
}

.message-reaction-chip{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:3px 7px;
    border-radius:999px;
    background:#ffffff;
    color:#334155;
    font-size:12px;
    box-shadow:0 5px 12px rgba(15,23,42,.10);
}

.message-stack.has-reaction{
    margin-bottom:16px;
}

.message-media{
    display:block;
    max-width:320px;
    width:100%;
    border-radius:8px;
    margin-top:6px;
}

.image-attachment{
    position:relative;
    max-width:320px;
    margin-top:6px;
}

.image-attachment .message-media{
    margin-top:0;
}

.image-download-button{
    position:absolute;
    right:8px;
    bottom:8px;
    width:34px;
    height:34px;
    border:1px solid rgba(255,255,255,.65);
    border-radius:50%;
    background:rgba(15,23,42,.72);
    color:#FFFFFF;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    box-shadow:0 10px 24px rgba(15,23,42,.24);
    opacity:1;
    transition:opacity var(--transition),transform var(--transition);
}

.image-download-button:hover{
    transform:translateY(-1px);
}

.message-video,
.message-audio{
    max-width:320px;
    width:100%;
    margin-top:6px;
}

.voice-player{
    width:min(320px,100%);
    display:grid;
    grid-template-columns:38px minmax(90px,1fr) 46px 42px;
    align-items:center;
    gap:10px;
    margin-top:6px;
    padding:9px 10px;
    border-radius:18px;
    background:#ffffff;
    color:#111827;
}

.message-row.sent .voice-player{
    background:rgba(255,255,255,.94);
}

.voice-play{
    width:38px;
    height:38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:0;
    border-radius:50%;
    background:#2563eb;
    color:#ffffff;
    cursor:pointer;
    font-size:14px;
}

.voice-waveform{
    width:100%;
    height:34px;
    display:flex;
    align-items:center;
    gap:3px;
    cursor:pointer;
    touch-action:none;
}

.voice-wave-bar{
    flex:1 1 0;
    min-width:2px;
    height:var(--bar-height,12px);
    border-radius:999px;
    background:#CBD5E1;
    transition:background .12s ease, transform .12s ease;
}

.voice-wave-bar.active{
    background:#2563eb;
}

.voice-waveform:hover .voice-wave-bar{
    transform:scaleY(1.06);
}

.voice-time{
    color:#475569;
    font-size:12px;
    font-weight:800;
    text-align:right;
}

.voice-speed{
    min-width:42px;
    height:30px;
    border:0;
    border-radius:999px;
    background:#EEF4FF;
    color:#2563eb;
    cursor:pointer;
    font-size:12px;
    font-weight:900;
}

.voice-player audio{
    display:none;
}

.file-attachment{
    display:flex;
    align-items:center;
    gap:10px;
    margin-top:6px;
    padding:10px;
    border-radius:8px;
    background:#f1f5f9;
    color:#111827;
    text-decoration:none;
}

.message-row.sent .file-attachment{
    background:rgba(255,255,255,.16);
    color:#ffffff;
}

.file-attachment i{
    font-size:20px;
}

.composer{
    padding:16px 18px;
    border-top:1px solid #e5e7eb;
    display:flex;
    gap:10px;
    align-items:center;
    background:rgba(255,255,255,.98);
    position:relative;
    flex-wrap:wrap;
}

.reply-composer{
    display:none;
    width:100%;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px;
    border:1px solid #dbe3ee;
    border-radius:12px;
    background:#eff6ff;
    color:#334155;
}

.reply-composer.show{
    display:flex;
}

.reply-composer strong{
    display:block;
    margin-bottom:3px;
    color:#2563eb;
}

.reply-composer span{
    font-size:13px;
}

.reply-composer button{
    width:30px;
    height:30px;
    border:0;
    border-radius:8px;
    background:#ffffff;
    cursor:pointer;
}

.contact-panel{
    width:320px;
    border-left:1px solid #e5e7eb;
    background:#ffffff;
    display:none;
    flex-direction:column;
}

.contact-panel.open{
    display:flex;
}

.contact-cover{
    padding:24px;
    text-align:center;
    background:linear-gradient(135deg,#eff6ff,#ffffff);
    border-bottom:1px solid #e5e7eb;
}

.contact-avatar{
    width:110px;
    height:110px;
    margin:0 auto 14px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#2f6df6,#1d4ed8);
    color:#ffffff;
    font-size:42px;
    font-weight:900;
    overflow:hidden;
    box-shadow:0 18px 36px rgba(37,99,235,.24);
}

.contact-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.contact-cover h3{
    margin:0;
    font-size:22px;
}

.contact-cover p{
    margin:6px 0 0;
    color:#64748b;
}

.contact-details{
    padding:18px;
}

.contact-row{
    padding:13px 0;
    border-bottom:1px solid #eef2f7;
}

.contact-row span{
    display:block;
    color:#64748b;
    font-size:12px;
    margin-bottom:4px;
}

.contact-row strong{
    font-size:14px;
}

.forward-modal{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.35);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:11000;
    padding:18px;
}

.forward-modal.open{
    display:flex;
}

.forward-box{
    width:min(420px,100%);
    max-height:80vh;
    overflow:hidden;
    border-radius:14px;
    background:#ffffff;
    box-shadow:0 24px 60px rgba(15,23,42,.25);
    border:1px solid #dbe3ee;
}

.forward-head{
    padding:16px;
    border-bottom:1px solid #eef2f7;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.forward-head h3{
    margin:0;
}

.forward-close{
    width:34px;
    height:34px;
    border:0;
    border-radius:8px;
    background:#f1f5f9;
    cursor:pointer;
}

.forward-list{
    max-height:420px;
    overflow:auto;
    padding:10px;
}

.forward-user{
    width:100%;
    border:1px solid transparent;
    border-radius:10px;
    background:#ffffff;
    padding:10px;
    display:flex;
    align-items:center;
    gap:10px;
    cursor:pointer;
    text-align:left;
}

.forward-user:hover{
    background:#eff6ff;
    border-color:#bfdbfe;
}

#message{
    flex:1;
    min-width:0;
    height:50px;
    border:1px solid #d1d5db;
    border-radius:12px;
    padding:0 14px;
    outline:none;
    font-size:15px;
}

.composer-btn{
    width:46px;
    height:46px;
    flex:0 0 46px;
    border:1px solid #d1d5db;
    border-radius:12px;
    background:linear-gradient(180deg,#ffffff,#f8fafc);
    color:#475569;
    cursor:pointer;
    font-size:18px;
    box-shadow:0 8px 18px rgba(15,23,42,.06);
}

.composer-btn:hover{
    background:#f8fafc;
    color:#2563eb;
}

.composer-btn.active{
    border-color:#2563eb;
    background:#eff6ff;
    color:#2563eb;
}

.emoji-panel{
    position:absolute;
    left:16px;
    bottom:72px;
    width:280px;
    padding:10px;
    border:1px solid #dbe3ee;
    border-radius:8px;
    background:#ffffff;
    box-shadow:0 18px 42px rgba(15,23,42,.16);
    display:none;
    grid-template-columns:repeat(8,1fr);
    gap:4px;
    z-index:10;
}

.emoji-panel.open{
    display:grid;
}

.emoji-item{
    width:30px;
    height:30px;
    border:0;
    border-radius:6px;
    background:#ffffff;
    cursor:pointer;
    font-size:18px;
}

.emoji-item:hover{
    background:#eff6ff;
}

.upload-status{
    position:absolute;
    left:16px;
    bottom:68px;
    color:#2563eb;
    font-size:13px;
    display:none;
}

.upload-status.show{
    display:block;
}

#message:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.14);
}

.send-btn {
    width: 112px;
    height: 50px;
    border: none;
    outline: none;
    cursor: pointer;
    border-radius: 12px;
    background:linear-gradient(135deg,#2f6df6,#1d4ed8);
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    box-shadow:0 14px 28px rgba(37,99,235,.28), inset 0 1px 0 rgba(255,255,255,.25);
    transition: all 0.25s ease;
}

.send-btn:hover {
    transform: translateY(-2px);
}

.send-btn:active {
    transform: scale(.98);
}

.send-btn:disabled {
    background: #cbd5e1;
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
    opacity: .7;
}

@media(max-width:820px){
    .page-shell{
        display:block;
    }

    .app-sidebar{
        width:100%;
        border-right:0;
        border-bottom:1px solid #dbe3ee;
        padding:16px;
    }

    .app-nav{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .chat-page{
        padding:0;
    }

    .chat-topbar{
        padding:14px;
        margin:0;
    }

    .chat-topbar h2,
    .chat-topbar p{
        display:none;
    }

    .chat-container{
        height:calc(100vh - 190px);
        min-height:620px;
        border-radius:0;
        border:0;
        flex-direction:column;
        box-shadow:none;
    }

    .users{
        width:100%;
        flex:0 0 auto;
        max-height:230px;
        border-right:0;
        border-bottom:1px solid #e5e7eb;
    }

    .user-list{
        display:flex;
        overflow-x:auto;
        overflow-y:hidden;
        padding:10px;
    }

    .user{
        min-width:220px;
    }

    .chat-header{
        min-height:74px;
        padding:12px 14px;
        gap:12px;
    }

    .chat-header-avatar{
        width:44px;
        height:44px;
        flex-basis:44px;
        border-radius:14px;
    }

    .chat-title{
        gap:10px;
    }

    .chat-title h3{
        max-width:180px;
        font-size:18px;
    }

    .chat-actions{
        gap:6px;
    }

    .icon-btn{
        width:38px;
        height:38px;
        border-radius:10px;
        font-size:15px;
    }

    #messages{
        padding:14px;
    }

    .message-with-reactions{
        max-width:88%;
    }

    .message-bubble{
        max-width:100%;
    }

    .composer{
        padding:10px;
    }

    .emoji-panel{
        left:10px;
        bottom:64px;
        width:260px;
    }

    .send-btn{
        width:82px;
    }
}

/* Premium chat redesign */
:root{
    --bg:#F5F7FB;
    --surface:#FFFFFF;
    --surface-soft:#F8FAFD;
    --border:#E8EDF5;
    --text:#111827;
    --muted:#64748B;
    --primary:#3870FF;
    --primary-dark:#2458E8;
    --success:#16A34A;
    --danger:#EF4444;
    --radius:16px;
    --radius-lg:20px;
    --shadow-sm:0 8px 22px rgba(15,23,42,.06);
    --shadow-md:0 22px 55px rgba(15,23,42,.10);
    --transition:.22s ease;
}

html{
    height:100%;
    overflow:hidden;
    scroll-behavior:smooth;
}

*{
    scrollbar-width:thin;
    scrollbar-color:#CBD5E1 transparent;
}

::-webkit-scrollbar{
    width:8px;
    height:8px;
}

::-webkit-scrollbar-thumb{
    background:#CBD5E1;
    border-radius:999px;
}

::-webkit-scrollbar-track{
    background:transparent;
}

body{
    height:100%;
    min-height:100vh;
    overflow:hidden;
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;
    background:
        radial-gradient(circle at 78% 0%, rgba(56,112,255,.10), transparent 28%),
        linear-gradient(180deg,#F7FAFF 0%,var(--bg) 48%,#EEF3FA 100%);
    color:var(--text);
}

.sr-only{
    position:absolute;
    width:1px;
    height:1px;
    padding:0;
    margin:-1px;
    overflow:hidden;
    clip:rect(0,0,0,0);
    white-space:nowrap;
    border:0;
}

.page-shell{
    height:100vh;
    min-height:0;
    overflow:hidden;
    display:grid;
    grid-template-columns:260px minmax(0,1fr);
}

.app-sidebar{
    width:260px;
    min-height:100vh;
    padding:28px 18px;
    background:rgba(255,255,255,.92);
    border-right:1px solid var(--border);
    box-shadow:none;
    backdrop-filter:blur(18px);
    position:sticky;
    top:0;
}

.brand{
    gap:12px;
    padding:0 10px 22px;
}

.brand-icon{
    width:44px;
    height:44px;
    border-radius:14px;
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    box-shadow:0 14px 30px rgba(56,112,255,.24);
}

.brand h1{
    font-size:19px;
    line-height:1.1;
    letter-spacing:-.01em;
}

.brand span{
    margin-top:5px;
    color:var(--muted);
    font-size:12px;
    line-height:1.2;
}

.app-nav{
    gap:7px;
}

.app-nav a{
    min-height:44px;
    padding:0 14px;
    border-radius:14px;
    color:#273449;
    font-size:14px;
    font-weight:800;
    transition:background var(--transition),color var(--transition),transform var(--transition),box-shadow var(--transition);
}

.app-nav a i{
    width:18px;
    text-align:center;
    font-size:15px;
}

.app-nav a:hover{
    background:#F1F6FF;
    color:var(--primary);
    transform:translateX(2px);
}

.app-nav a.active{
    background:rgba(56,112,255,.12);
    color:var(--primary);
    box-shadow:inset 0 0 0 1px rgba(56,112,255,.18);
}

.app-nav a.logout{
    color:#DC2626;
    margin-top:16px;
}

.app-nav a.logout:hover{
    background:#FFF1F2;
    color:#DC2626;
}

.chat-page{
    min-width:0;
    padding:30px;
    height:100vh;
    overflow:hidden;
}

.chat-topbar{
    width:min(1180px,100%);
    margin:0 auto 18px;
    min-height:54px;
}

.chat-topbar h2{
    font-size:30px;
    line-height:1.1;
    letter-spacing:-.035em;
    font-weight:900;
}

.chat-topbar p{
    margin-top:8px;
    color:var(--muted);
    font-size:14px;
}

.topbar-actions{
    gap:12px;
}

.power-btn{
    width:50px;
    height:50px;
    border-radius:15px;
    background:linear-gradient(135deg,#FF5A5F,#DC2626);
    box-shadow:0 18px 34px rgba(220,38,38,.24);
    transition:transform var(--transition),box-shadow var(--transition);
}

.power-btn:hover{
    transform:translateY(-2px);
    box-shadow:0 22px 42px rgba(220,38,38,.30);
}

.chat-container{
    width:min(1180px,100%);
    height:calc(100vh - 128px);
    min-height:0;
    margin:0 auto;
    display:grid;
    grid-template-columns:360px minmax(0,1fr) auto;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:20px;
    overflow:hidden;
    box-shadow:var(--shadow-md);
    backdrop-filter:none;
}

.users{
    width:360px;
    flex:0 0 360px;
    min-width:0;
    background:#FBFCFF;
    border-right:1px solid var(--border);
}

.sidebar-head{
    padding:22px 20px 16px;
    background:#FFFFFF;
    border-bottom:1px solid var(--border);
}

.sidebar-head h2{
    font-size:26px;
    line-height:1.1;
    letter-spacing:-.035em;
    font-weight:900;
}

.sidebar-head p{
    margin-top:7px;
    color:var(--muted);
    font-size:13px;
}

.chat-list-search{
    height:42px;
    margin-top:16px;
    padding:0 13px;
    display:flex;
    align-items:center;
    gap:10px;
    border:1px solid var(--border);
    border-radius:14px;
    background:#F7F9FD;
    color:#94A3B8;
    transition:border-color var(--transition),box-shadow var(--transition),background var(--transition);
}

.chat-list-search:focus-within{
    background:#FFFFFF;
    border-color:rgba(56,112,255,.45);
    box-shadow:0 0 0 4px rgba(56,112,255,.10);
}

.chat-list-search input{
    width:100%;
    min-width:0;
    border:0;
    outline:0;
    background:transparent;
    color:var(--text);
    font-size:14px;
}

.chat-list-search input::placeholder{
    color:#94A3B8;
}

.new-group-btn{
    width:100%;
    min-height:42px;
    margin-top:14px;
    border:1px solid rgba(56,112,255,.24);
    border-radius:14px;
    background:#F1F6FF;
    color:var(--primary);
    display:flex;
    align-items:center;
    justify-content:center;
    gap:9px;
    font-size:14px;
    font-weight:850;
    cursor:pointer;
    transition:background var(--transition),border-color var(--transition),transform var(--transition);
}

.new-group-btn:hover{
    background:#EAF2FF;
    border-color:rgba(56,112,255,.38);
    transform:translateY(-1px);
}

.user-list{
    padding:12px;
    overflow-y:auto;
}

.list-section-label{
    margin:14px 8px 8px;
    color:#94A3B8;
    font-size:11px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
}

.empty-users{
    margin:12px;
    padding:18px;
    border:1px dashed var(--border);
    border-radius:16px;
    background:#FFFFFF;
}

.user{
    position:relative;
    width:100%;
    min-height:74px;
    margin:0 0 8px;
    padding:12px;
    gap:12px;
    border:1px solid transparent;
    border-radius:16px;
    background:transparent;
    transition:background var(--transition),border-color var(--transition),box-shadow var(--transition),transform var(--transition);
}

.user:hover{
    background:#FFFFFF;
    border-color:var(--border);
    box-shadow:var(--shadow-sm);
    transform:translateY(-1px);
}

.user.active{
    background:#FFFFFF;
    border-color:rgba(56,112,255,.34);
    box-shadow:0 14px 34px rgba(56,112,255,.13);
}

.user.active:before{
    content:"";
    position:absolute;
    left:-1px;
    top:16px;
    bottom:16px;
    width:3px;
    border-radius:999px;
    background:var(--primary);
}

.avatar{
    width:50px;
    height:50px;
    flex:0 0 50px;
    border-radius:50%;
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    font-size:16px;
    font-weight:900;
    box-shadow:0 12px 24px rgba(56,112,255,.18);
}

.avatar img,
.chat-header-avatar img,
.contact-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.group-avatar{
    border-radius:16px;
    background:linear-gradient(135deg,#0EA5E9,#2563EB);
}

.group-create-form{
    display:grid;
    gap:14px;
}

.group-name-field{
    display:grid;
    gap:7px;
    color:#334155;
    font-size:12px;
    font-weight:850;
}

.group-name-field input{
    width:100%;
    height:44px;
    border:1px solid var(--border);
    border-radius:12px;
    padding:0 13px;
    color:var(--text);
    font-size:14px;
    outline:0;
}

.group-name-field input:focus{
    border-color:rgba(56,112,255,.55);
    box-shadow:0 0 0 3px rgba(56,112,255,.12);
}

.group-member-list{
    display:grid;
    gap:8px;
    max-height:320px;
    overflow:auto;
}

.group-member-row{
    min-height:58px;
    padding:9px 10px;
    border:1px solid var(--border);
    border-radius:12px;
    display:flex;
    align-items:center;
    gap:10px;
    cursor:pointer;
    background:#FFFFFF;
}

.group-member-row:hover{
    border-color:rgba(56,112,255,.28);
    background:#F8FBFF;
}

.group-member-row input{
    width:16px;
    height:16px;
    accent-color:var(--primary);
}

.group-member-avatar{
    width:38px;
    height:38px;
    flex:0 0 38px;
    border-radius:50%;
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    color:#FFFFFF;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    font-size:13px;
    font-weight:900;
}

.group-member-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.group-member-avatar .avatar{
    width:38px;
    height:38px;
    flex-basis:38px;
    box-shadow:none;
    font-size:13px;
}

.group-member-copy{
    min-width:0;
    display:grid;
    gap:2px;
}

.group-member-copy strong,
.group-member-copy small{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.group-member-copy strong{
    color:#172033;
    font-size:14px;
}

.group-member-copy small{
    color:var(--muted);
    font-size:12px;
}

.group-create-actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
}

.user-info{
    min-width:0;
    flex:1;
    padding-right:8px;
}

.user-name{
    font-size:15px;
    line-height:1.25;
    font-weight:850;
    letter-spacing:-.01em;
    color:#172033;
}

.user-status{
    max-width:100%;
    margin-top:6px;
    display:flex;
    align-items:center;
    gap:7px;
    color:var(--muted);
    font-size:12px;
    line-height:1.35;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.status-dot{
    width:8px;
    height:8px;
    flex:0 0 8px;
    border-radius:50%;
    background:#94A3B8;
}

.status-dot.online{
    background:var(--success);
    box-shadow:0 0 0 4px rgba(22,163,74,.12);
    animation:pulseDot 1.8s infinite;
}

.status-dot.away{
    background:#F59E0B;
    box-shadow:0 0 0 4px rgba(245,158,11,.12);
}

@keyframes pulseDot{
    0%,100%{box-shadow:0 0 0 3px rgba(22,163,74,.12);}
    50%{box-shadow:0 0 0 7px rgba(22,163,74,0);}
}

.chat-area{
    min-width:0;
    background:#FFFFFF;
    height:100%;
    overflow:hidden;
}

.chat-header{
    min-height:72px;
    padding:12px 20px;
    background:rgba(255,255,255,.96);
    border-bottom:1px solid var(--border);
    box-shadow:none;
}

.chat-title{
    min-width:0;
    gap:13px;
}

.chat-title h3{
    max-width:360px;
    font-size:19px;
    line-height:1.15;
    font-weight:900;
    letter-spacing:-.025em;
    color:var(--text);
}

.chat-header-avatar{
    width:44px;
    height:44px;
    flex:0 0 44px;
    border-radius:50%;
    border:1px solid var(--border);
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    box-shadow:0 12px 24px rgba(56,112,255,.16);
}

#chat-presence,
#typing-status{
    min-height:16px;
    margin-top:3px;
    font-size:12px;
    line-height:1.25;
    font-weight:700;
}

#chat-presence{
    color:var(--muted);
}

#chat-presence.online{
    color:var(--success);
}

#typing-status{
    color:var(--primary);
}

.chat-actions{
    gap:8px;
}

.icon-btn{
    width:42px;
    height:42px;
    border:1px solid var(--border);
    border-radius:14px;
    background:#FFFFFF;
    color:#344054;
    font-size:16px;
    box-shadow:none;
    transition:background var(--transition),border-color var(--transition),color var(--transition),transform var(--transition),box-shadow var(--transition);
}

.icon-btn:hover{
    background:#F3F7FF;
    border-color:rgba(56,112,255,.28);
    color:var(--primary);
    transform:translateY(-1px);
    box-shadow:0 10px 22px rgba(56,112,255,.10);
}

.message-search{
    padding:12px 18px;
    background:#FFFFFF;
    border-bottom:1px solid var(--border);
}

.message-search input{
    height:42px;
    border:1px solid var(--border);
    border-radius:14px;
    background:#F7F9FD;
}

#messages{
    min-height:0;
    padding:24px 28px;
    gap:10px;
    overflow-y:auto;
    overscroll-behavior:contain;
    background:
        linear-gradient(rgba(245,247,251,.94),rgba(245,247,251,.94)),
        radial-gradient(circle at 12px 12px, rgba(56,112,255,.045) 1.5px, transparent 2px);
    background-size:auto,26px 26px;
}

#messages::before{
    content:"";
    margin-top:auto;
}

#messages:has(.empty-state)::before{
    display:none;
}

#messages.wallpaper-solid{
    background:var(--chat-wallpaper-solid,#F5F7FB);
    background-size:auto;
}

#messages.wallpaper-gradient{
    background:var(--chat-wallpaper-gradient,linear-gradient(135deg,#F5F7FB,#EAF1FF));
    background-size:auto;
}

#messages.wallpaper-pattern{
    background:
        linear-gradient(rgba(245,247,251,.90),rgba(245,247,251,.90)),
        var(--chat-wallpaper-pattern,radial-gradient(circle at 12px 12px, rgba(56,112,255,.08) 1.5px, transparent 2px));
    background-size:auto,26px 26px;
}

#messages.wallpaper-image{
    background:
        linear-gradient(rgba(248,250,252,.68),rgba(248,250,252,.68)),
        var(--chat-wallpaper-image);
    background-size:auto,cover;
    background-position:center;
}

.empty-state{
    color:var(--muted);
}

.empty-state-box{
    padding:22px 26px;
    border:1px solid var(--border);
    border-radius:20px;
    background:rgba(255,255,255,.78);
    box-shadow:var(--shadow-sm);
}

.empty-state-box strong{
    font-size:17px;
    color:var(--text);
}

.message-stack{
    animation:messageIn .24s ease both;
}

@keyframes messageIn{
    from{opacity:0; transform:translateY(6px);}
    to{opacity:1; transform:translateY(0);}
}

.message-with-reactions{
    max-width:min(620px,70%);
}

.message-bubble{
    max-width:100%;
    min-width:128px;
    padding:12px 14px 9px;
    border-radius:20px;
    border:1px solid var(--border);
    background:#FFFFFF;
    box-shadow:0 8px 20px rgba(15,23,42,.06);
}

.message-row.sent .message-with-reactions{
    max-width:min(560px,64%);
}

.message-row.received .message-with-reactions{
    max-width:min(600px,68%);
}

.message-row.received .message-bubble{
    border-top-left-radius:8px;
}

.message-row.sent .message-bubble{
    border-top-right-radius:8px;
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    border-color:transparent;
    color:#FFFFFF;
    box-shadow:0 14px 30px rgba(56,112,255,.24);
}

.message-sender{
    margin-bottom:6px;
    font-size:11px;
    line-height:1.2;
    font-weight:900;
    letter-spacing:.01em;
    color:#475569;
}

.message-row.sent .message-sender{
    color:rgba(255,255,255,.82);
}

.message-text{
    font-size:14px;
    line-height:1.45;
    overflow-wrap:anywhere;
}

.reply-preview{
    margin-bottom:8px;
    padding:8px 10px;
    border-radius:14px;
    border-left:3px solid var(--primary);
    background:#F7F9FD;
    box-shadow:inset 0 0 0 1px rgba(232,237,245,.95);
}

.message-row.sent .reply-preview{
    background:rgba(255,255,255,.14);
    border-left-color:#DDE8FF;
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.18);
}

.reply-preview strong,
.reply-preview span{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.message-meta{
    margin-top:6px;
    gap:5px;
    min-height:13px;
    color:#94A3B8;
    font-size:10.5px;
    font-weight:700;
}

.message-row.sent .message-meta{
    color:rgba(255,255,255,.78);
}

.message-ticks.seen,
.seen-text{
    color:#60D5FF;
}

.seen-text{
    margin-top:4px;
    padding-right:8px;
    font-size:10.5px;
    font-weight:900;
}

.message-menu-btn{
    top:7px;
    right:7px;
    border-radius:10px;
    background:rgba(255,255,255,.16);
    color:inherit;
    transition:opacity var(--transition),background var(--transition);
}

.message-row.received .message-menu-btn{
    background:#F1F5F9;
}

.message-menu{
    border-color:var(--border);
    border-radius:14px;
    box-shadow:0 22px 45px rgba(15,23,42,.16);
}

.message-menu button,
.message-menu a{
    border-radius:10px;
    font-weight:700;
}

.reaction-bar{
    border-color:var(--border);
    box-shadow:0 18px 40px rgba(15,23,42,.16);
}

.message-reaction-chip{
    border:1px solid var(--border);
    box-shadow:0 8px 18px rgba(15,23,42,.10);
}

.message-media,
.message-video,
.message-audio{
    border-radius:14px;
}

.voice-player{
    box-shadow:0 12px 24px rgba(15,23,42,.10);
}

.voice-play:hover{
    transform:scale(1.03);
}

.voice-play:active{
    transform:scale(.96);
}

.file-attachment{
    border-radius:14px;
    background:#F7F9FD;
}

.composer{
    position:sticky;
    bottom:0;
    padding:12px 18px;
    gap:10px;
    border-top:1px solid var(--border);
    background:rgba(255,255,255,.96);
    backdrop-filter:blur(16px);
}

.reply-composer{
    border-color:rgba(56,112,255,.20);
    border-radius:16px;
    background:#F4F7FF;
}

.reply-composer button{
    border-radius:12px;
}

.voice-recorder{
    display:none;
    width:100%;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:10px 12px;
    border:1px solid rgba(239,68,68,.24);
    border-radius:16px;
    background:#FFF1F2;
    color:#334155;
}

.voice-recorder.show{
    display:flex;
}

.voice-recorder-status,
.voice-recorder-actions{
    display:flex;
    align-items:center;
    gap:10px;
}

.voice-recorder-status strong{
    color:#DC2626;
    font-size:13px;
}

.voice-recorder-status span:last-child{
    min-width:42px;
    color:#475569;
    font-size:13px;
    font-weight:800;
}

.voice-dot{
    width:10px;
    height:10px;
    border-radius:50%;
    background:#EF4444;
    box-shadow:0 0 0 0 rgba(239,68,68,.34);
    animation:voicePulse 1.2s infinite;
}

.voice-recorder-actions button{
    min-height:34px;
    border:0;
    border-radius:11px;
    padding:0 12px;
    cursor:pointer;
    font-weight:900;
}

#voice-cancel{
    background:#FFFFFF;
    color:#DC2626;
}

#voice-send{
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    color:#FFFFFF;
}

.composer-btn.recording{
    background:#FFF1F2;
    border-color:rgba(239,68,68,.36);
    color:#DC2626;
}

@keyframes voicePulse{
    70%{box-shadow:0 0 0 8px rgba(239,68,68,0);}
    100%{box-shadow:0 0 0 0 rgba(239,68,68,0);}
}

.composer-btn{
    width:44px;
    height:44px;
    flex:0 0 44px;
    border:1px solid var(--border);
    border-radius:15px;
    background:#FFFFFF;
    color:#526174;
    box-shadow:none;
    transition:background var(--transition),border-color var(--transition),color var(--transition),transform var(--transition);
}

.composer-btn:hover,
.composer-btn.active{
    background:#F3F7FF;
    border-color:rgba(56,112,255,.28);
    color:var(--primary);
    transform:translateY(-1px);
}

#message{
    height:46px;
    border:1px solid var(--border);
    border-radius:16px;
    background:#FFFFFF;
    padding:0 16px;
    font-size:14px;
    transition:border-color var(--transition),box-shadow var(--transition);
}

#message:focus{
    border-color:rgba(56,112,255,.50);
    box-shadow:0 0 0 4px rgba(56,112,255,.10);
}

.send-btn{
    width:48px;
    height:48px;
    flex:0 0 48px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    font-size:17px;
    box-shadow:0 16px 32px rgba(56,112,255,.28);
}

.send-btn:hover{
    transform:translateY(-2px) scale(1.02);
    box-shadow:0 20px 38px rgba(56,112,255,.34);
}

.send-btn:active{
    transform:scale(.96);
}

.emoji-panel{
    border-color:var(--border);
    border-radius:16px;
    box-shadow:0 24px 55px rgba(15,23,42,.18);
}

.attach-menu{
    position:absolute;
    left:62px;
    bottom:72px;
    z-index:40;
    width:min(330px,calc(100% - 34px));
    display:none;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px;
    padding:12px;
    border:1px solid var(--border);
    border-radius:18px;
    background:rgba(255,255,255,.98);
    box-shadow:0 24px 55px rgba(15,23,42,.18);
}

.attach-menu.open{
    display:grid;
}

.attach-menu button{
    min-height:76px;
    display:grid;
    place-items:center;
    gap:7px;
    border:1px solid var(--border);
    border-radius:15px;
    background:#FFFFFF;
    color:#334155;
    cursor:pointer;
    font-weight:900;
    font-size:12px;
    transition:background var(--transition),border-color var(--transition),color var(--transition),transform var(--transition);
}

.attach-menu button:hover{
    transform:translateY(-1px);
    border-color:rgba(56,112,255,.28);
    background:#F3F7FF;
    color:var(--primary);
}

.attach-menu i{
    width:34px;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:12px;
    background:#EEF4FF;
    color:var(--primary);
    font-size:15px;
}

.emoji-item{
    border-radius:10px;
}

.contact-panel{
    width:320px;
    border-left:1px solid var(--border);
    background:#FFFFFF;
}

.contact-cover{
    background:linear-gradient(180deg,#F6F9FF,#FFFFFF);
    border-bottom:1px solid var(--border);
}

.contact-avatar{
    border-radius:50%;
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    box-shadow:0 18px 38px rgba(56,112,255,.20);
}

.contact-cover h3{
    letter-spacing:-.02em;
}

.contact-cover p,
.contact-row span{
    color:var(--muted);
}

.contact-row{
    border-bottom:1px solid var(--border);
}

.forward-modal{
    background:rgba(15,23,42,.42);
    backdrop-filter:blur(8px);
}

.forward-box{
    border-color:var(--border);
    border-radius:20px;
    box-shadow:0 30px 70px rgba(15,23,42,.24);
}

.forward-user{
    border-radius:14px;
}

.forward-user:hover{
    background:#F3F7FF;
    border-color:rgba(56,112,255,.24);
}

.upload-status{
    color:var(--primary);
    font-weight:800;
}

@media(max-width:1180px){
    .chat-page{
        padding:22px;
    }

    .chat-container{
        grid-template-columns:330px minmax(0,1fr) auto;
    }

    .users{
        width:330px;
        flex-basis:330px;
    }
}

@media(max-width:960px){
    .page-shell{
        grid-template-columns:1fr;
    }

    .app-sidebar{
        position:static;
        width:100%;
        min-height:0;
        padding:14px;
        border-right:0;
        border-bottom:1px solid var(--border);
    }

    .brand{
        padding:0;
        margin-bottom:12px;
    }

    .app-nav{
        display:flex;
        overflow-x:auto;
        gap:8px;
        padding-bottom:2px;
    }

    .app-nav a{
        flex:0 0 auto;
        white-space:nowrap;
    }

    .app-nav a.logout{
        margin-top:0;
        margin-left:auto;
    }

    .chat-page{
        padding:18px;
    }

    .chat-container{
        height:calc(100vh - 190px);
        min-height:620px;
        grid-template-columns:300px minmax(0,1fr);
    }
}

@media(max-width:760px){
    .chat-topbar{
        display:none;
    }

    .chat-page{
        padding:0;
    }

    .chat-container{
        height:calc(100vh - 116px);
        min-height:0;
        grid-template-columns:1fr;
        border-radius:0;
        border-left:0;
        border-right:0;
    }

    .users{
        width:100%;
        flex:0 0 auto;
        max-height:236px;
        border-right:0;
        border-bottom:1px solid var(--border);
    }

    .sidebar-head{
        padding:16px;
    }

    .user-list{
        display:flex;
        gap:8px;
        padding:10px;
        overflow-x:auto;
        overflow-y:hidden;
    }

    .user{
        min-width:230px;
        margin-bottom:0;
    }

    .chat-header{
        min-height:70px;
        padding:12px;
    }

    .chat-header-avatar{
        width:42px;
        height:42px;
        flex-basis:42px;
    }

    .chat-title h3{
        max-width:150px;
        font-size:17px;
    }

    .chat-actions{
        gap:5px;
    }

    .icon-btn{
        width:36px;
        height:36px;
        border-radius:12px;
        font-size:14px;
    }

    #messages{
        padding:16px 12px;
    }

    .message-with-reactions{
        max-width:86%;
    }

    .composer{
        padding:10px;
        gap:7px;
    }

    .composer-btn,
    .send-btn{
        width:42px;
        height:42px;
        flex-basis:42px;
    }

    #message{
        height:44px;
        border-radius:14px;
    }

    .contact-panel.open{
        position:absolute;
        inset:0 0 0 auto;
        width:min(320px,86vw);
        z-index:50;
        box-shadow:-20px 0 50px rgba(15,23,42,.18);
    }
}

/* Header, more menu, and profile drawer polish */
.chat-header{
    min-height:74px;
    padding:13px 20px;
    display:flex;
    align-items:center;
}

.chat-title{
    display:flex;
    align-items:center;
    gap:12px;
}

.chat-title > div:last-child{
    min-width:0;
    display:flex;
    flex-direction:column;
    justify-content:center;
}

.chat-header-avatar{
    width:48px;
    height:48px;
    flex:0 0 48px;
    border-radius:50%;
    transition:transform .2s ease, box-shadow .2s ease;
}

.chat-header-avatar:hover{
    transform:translateY(-1px);
    box-shadow:0 16px 30px rgba(56,112,255,.22);
}

.chat-title h3{
    margin:0;
    font-size:18px;
    font-weight:600;
    line-height:1.2;
    letter-spacing:0;
}

#chat-presence{
    min-height:auto;
    margin-top:4px;
    display:flex;
    align-items:center;
    gap:6px;
    color:var(--muted);
    font-size:13px;
    font-weight:600;
    line-height:1.2;
    text-transform:capitalize;
}

#chat-presence:empty,
#typing-status:empty{
    display:none;
}

#typing-status{
    min-height:0;
    margin-top:2px;
    font-size:12px;
    line-height:1.2;
}

.chat-actions{
    position:relative;
    align-items:center;
}

.icon-btn{
    width:48px;
    height:48px;
    border-radius:50%;
    box-shadow:0 10px 24px rgba(15,23,42,.08);
}

.icon-btn:hover{
    transform:translateY(-2px);
    background:#eef5ff;
    color:var(--primary);
    box-shadow:0 16px 32px rgba(56,112,255,.16);
}

.more-menu-wrap{
    position:relative;
}

.chat-more-menu{
    position:absolute;
    right:0;
    top:58px;
    width:250px;
    padding:8px;
    border:1px solid rgba(232,237,245,.95);
    border-radius:16px;
    background:rgba(255,255,255,.96);
    box-shadow:0 24px 60px rgba(15,23,42,.18);
    backdrop-filter:blur(14px);
    display:none;
    z-index:80;
    transform-origin:top right;
}

.chat-more-menu.open{
    display:block;
    animation:menuIn .16s ease-out;
}

.chat-more-menu button{
    width:100%;
    min-height:40px;
    border:0;
    border-radius:12px;
    background:transparent;
    display:flex;
    align-items:center;
    gap:11px;
    padding:0 10px;
    color:#1f2937;
    cursor:pointer;
    font-size:13px;
    font-weight:700;
    text-align:left;
    transition:background .18s ease,color .18s ease,transform .18s ease;
}

.chat-more-menu button i{
    width:18px;
    text-align:center;
    color:#64748b;
}

.chat-more-menu button:hover{
    background:#f1f6ff;
    color:var(--primary);
    transform:translateX(2px);
}

.chat-more-menu button:hover i{
    color:var(--primary);
}

.chat-more-menu button.danger{
    color:#dc2626;
}

.chat-more-menu button.danger i{
    color:#ef4444;
}

@keyframes menuIn{
    from{opacity:0;transform:translateY(-6px) scale(.98);}
    to{opacity:1;transform:translateY(0) scale(1);}
}

.contact-panel{
    width:400px;
    max-width:400px;
    display:flex;
    flex-direction:column;
    position:absolute;
    top:0;
    right:0;
    bottom:0;
    transform:translateX(100%);
    opacity:0;
    pointer-events:none;
    transition:transform .26s ease, opacity .2s ease;
    box-shadow:-24px 0 60px rgba(15,23,42,.14);
    z-index:55;
}

.chat-container{
    position:relative;
}

.contact-panel.open{
    display:flex;
    transform:translateX(0);
    opacity:1;
    pointer-events:auto;
}

.contact-cover{
    position:relative;
    padding:52px 24px 24px;
    text-align:center;
    background:linear-gradient(180deg,rgba(246,249,255,.98),rgba(255,255,255,.96));
}

.contact-close{
    position:absolute;
    top:14px;
    right:14px;
    width:38px;
    height:38px;
    border:1px solid var(--border);
    border-radius:50%;
    background:#fff;
    color:#334155;
    cursor:pointer;
    box-shadow:0 10px 22px rgba(15,23,42,.08);
    transition:background .18s ease,color .18s ease,transform .18s ease,box-shadow .18s ease;
}

.contact-close:hover{
    background:#eef5ff;
    color:var(--primary);
    transform:rotate(90deg) scale(1.03);
    box-shadow:0 16px 30px rgba(56,112,255,.16);
}

.contact-avatar{
    width:112px;
    height:112px;
    margin:0 auto 16px;
    border:4px solid #fff;
}

.contact-cover h3{
    margin:0;
    font-size:22px;
    line-height:1.2;
    font-weight:700;
}

.contact-cover p{
    margin-top:8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    color:var(--muted);
    font-size:13px;
    font-weight:600;
    text-transform:capitalize;
}

.contact-cover p::before{
    content:"";
    width:8px;
    height:8px;
    border-radius:50%;
    background:#94a3b8;
    box-shadow:0 0 0 4px rgba(148,163,184,.12);
}

.contact-cover p.online::before{
    background:#16a34a;
    box-shadow:0 0 0 4px rgba(22,163,74,.12);
}

.contact-details{
    padding:16px;
    overflow:auto;
    display:grid;
    gap:10px;
}

.contact-row{
    padding:14px;
    border:1px solid var(--border);
    border-radius:16px;
    background:rgba(255,255,255,.88);
    box-shadow:0 10px 24px rgba(15,23,42,.05);
    transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.contact-row:hover{
    transform:translateY(-2px);
    border-color:rgba(56,112,255,.28);
    box-shadow:0 16px 34px rgba(15,23,42,.09);
}

.contact-action-row{
    cursor:pointer;
}

.tool-modal{
    position:fixed;
    inset:0;
    z-index:13000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:rgba(15,23,42,.42);
    backdrop-filter:blur(10px);
}

.tool-modal.open{
    display:flex;
    animation:toolFade .18s ease-out;
}

.tool-box{
    width:min(760px,100%);
    max-height:84vh;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    border:1px solid rgba(232,237,245,.95);
    border-radius:22px;
    background:rgba(255,255,255,.97);
    box-shadow:0 30px 80px rgba(15,23,42,.24);
}

.tool-head{
    padding:16px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-bottom:1px solid var(--border);
}

.tool-head h3{
    margin:0;
    color:#111827;
    font-size:18px;
    line-height:1.2;
}

.tool-close{
    width:38px;
    height:38px;
    border:1px solid var(--border);
    border-radius:50%;
    background:#fff;
    color:#334155;
    cursor:pointer;
}

.tool-tabs{
    display:flex;
    gap:8px;
    padding:12px 16px 0;
    overflow:auto;
}

.tool-tabs button{
    border:1px solid var(--border);
    border-radius:999px;
    background:#fff;
    color:#475569;
    padding:8px 12px;
    cursor:pointer;
    font-size:12px;
    font-weight:800;
}

.tool-tabs button.active{
    background:#eff6ff;
    border-color:#bfdbfe;
    color:var(--primary);
}

.tool-search{
    padding:12px 16px;
}

.tool-search input{
    width:100%;
    height:42px;
    border:1px solid var(--border);
    border-radius:14px;
    padding:0 13px;
    outline:none;
}

.tool-body{
    min-height:220px;
    overflow:auto;
    padding:0 16px 16px;
}

.tool-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
    gap:12px;
}

.tool-item{
    border:1px solid var(--border);
    border-radius:16px;
    background:#fff;
    overflow:hidden;
    box-shadow:0 10px 24px rgba(15,23,42,.06);
}

.tool-thumb{
    height:112px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#f8fafc;
    color:var(--primary);
    font-size:28px;
}

.tool-thumb img,
.tool-thumb video{
    width:100%;
    height:100%;
    object-fit:cover;
}

.tool-info{
    padding:10px;
}

.tool-info strong{
    display:block;
    color:#111827;
    font-size:13px;
    line-height:1.25;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.tool-info span{
    display:block;
    margin-top:4px;
    color:#64748b;
    font-size:11px;
}

.tool-actions{
    display:flex;
    gap:8px;
    margin-top:9px;
}

.tool-actions button,
.tool-actions a{
    flex:1;
    border:0;
    border-radius:10px;
    background:#eff6ff;
    color:var(--primary);
    padding:8px;
    text-align:center;
    text-decoration:none;
    cursor:pointer;
    font-size:12px;
    font-weight:800;
}

.tool-list{
    display:grid;
    gap:10px;
}

.tool-list-item{
    padding:13px;
    border:1px solid var(--border);
    border-radius:16px;
    background:#fff;
    cursor:pointer;
}

.tool-list-item mark{
    background:#fef08a;
    border-radius:4px;
    padding:0 2px;
}

.tool-empty{
    padding:32px 18px;
    text-align:center;
    color:#64748b;
    border:1px dashed var(--border);
    border-radius:18px;
    background:#fff;
}

.media-send-preview{
    display:grid;
    gap:14px;
    padding-top:4px;
}

.media-preview-stage{
    min-height:220px;
    display:grid;
    place-items:center;
    overflow:hidden;
    border:1px solid var(--border);
    border-radius:18px;
    background:#F8FAFC;
}

.media-preview-stage img,
.media-preview-stage video{
    max-width:100%;
    max-height:54vh;
    display:block;
    object-fit:contain;
}

.media-preview-stage audio{
    width:min(420px,100%);
}

.media-preview-file{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
    color:#334155;
    font-size:13px;
    font-weight:800;
}

.media-preview-file i{
    width:34px;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:12px;
    background:#EEF4FF;
    color:var(--primary);
}

.media-preview-file span{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.media-send-options{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}

.media-send-actions{
    display:flex;
    gap:10px;
}

.media-once-toggle,
.media-send-actions button{
    min-height:40px;
    border:0;
    border-radius:12px;
    padding:0 14px;
    cursor:pointer;
    font-weight:900;
}

.media-once-toggle{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:#EEF4FF;
    color:var(--primary);
}

.media-once-toggle.active{
    background:#ECFDF5;
    color:#16A34A;
}

.media-once-toggle i{
    width:18px;
    height:18px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:2px solid currentColor;
    border-radius:50%;
    font-size:10px;
}

.media-send-actions .secondary{
    background:#F1F5F9;
    color:#475569;
}

.media-send-actions .primary{
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    color:#fff;
}

.wallpaper-panel{
    display:grid;
    gap:16px;
    padding-top:4px;
}

.wallpaper-section{
    display:grid;
    gap:10px;
}

.wallpaper-section-title{
    margin:0;
    color:#475569;
    font-size:12px;
    font-weight:900;
    letter-spacing:.04em;
    text-transform:uppercase;
}

.wallpaper-options{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(118px,1fr));
    gap:10px;
}

.wallpaper-choice{
    border:1px solid var(--border);
    border-radius:14px;
    background:#fff;
    padding:8px;
    cursor:pointer;
    text-align:left;
    transition:transform var(--transition),border-color var(--transition),box-shadow var(--transition);
}

.wallpaper-choice:hover,
.wallpaper-choice.active{
    transform:translateY(-1px);
    border-color:rgba(56,112,255,.42);
    box-shadow:0 14px 30px rgba(56,112,255,.12);
}

.wallpaper-preview{
    height:70px;
    border-radius:10px;
    border:1px solid rgba(203,213,225,.7);
    background-size:cover;
    background-position:center;
}

.wallpaper-choice span{
    display:block;
    margin-top:7px;
    color:#334155;
    font-size:12px;
    font-weight:900;
}

.wallpaper-custom{
    display:grid;
    grid-template-columns:72px minmax(0,1fr) auto auto;
    gap:10px;
    align-items:center;
}

.wallpaper-custom input[type="color"]{
    width:72px;
    height:44px;
    padding:4px;
    border:1px solid var(--border);
    border-radius:13px;
    background:#fff;
    cursor:pointer;
}

.wallpaper-custom input[type="text"]{
    min-width:0;
    height:44px;
    border:1px solid var(--border);
    border-radius:13px;
    padding:0 12px;
    outline:0;
}

.wallpaper-custom input:focus{
    border-color:var(--primary);
    box-shadow:0 0 0 4px rgba(56,112,255,.12);
}

.wallpaper-file-name{
    min-height:20px;
    color:#64748b;
    font-size:12px;
}

.wallpaper-actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    flex-wrap:wrap;
}

.wallpaper-actions button,
.wallpaper-custom button{
    min-height:40px;
    border:0;
    border-radius:12px;
    padding:0 14px;
    cursor:pointer;
    font-weight:900;
}

.wallpaper-custom button,
.wallpaper-actions .secondary{
    background:#EEF4FF;
    color:var(--primary);
}

.wallpaper-actions .primary{
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    color:#fff;
}

.wallpaper-custom button[disabled],
.wallpaper-actions button[disabled]{
    opacity:.55;
    cursor:not-allowed;
}

@media(max-width:560px){
    .wallpaper-custom{
        grid-template-columns:1fr;
    }

    .wallpaper-custom input[type="color"]{
        width:100%;
    }
}

.message-highlight .message-bubble{
    outline:3px solid rgba(56,112,255,.28);
    box-shadow:0 0 0 8px rgba(56,112,255,.10), 0 14px 32px rgba(15,23,42,.12);
}

@keyframes toolFade{
    from{opacity:0;}
    to{opacity:1;}
}

.contact-row span{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:6px;
    color:#475569;
    font-size:13px;
    font-weight:700;
}

.contact-row span i{
    width:20px;
    color:var(--primary);
    text-align:center;
}

.contact-row strong{
    display:block;
    color:#111827;
    font-size:14px;
    font-weight:700;
}

.contact-row.danger span,
.contact-row.danger span i{
    color:#dc2626;
}

.user-status .status-dot:not(.online){
    display:none;
}

.avatar{
    position:relative;
    overflow:visible;
}

.avatar img{
    border-radius:inherit;
}

.avatar-status-dot{
    position:absolute;
    right:-2px;
    top:50%;
    transform:translateY(-50%);
    width:12px;
    height:12px;
    border:2px solid #ffffff;
    border-radius:50%;
    background:#16a34a;
    box-shadow:0 0 0 3px rgba(22,163,74,.14);
}

.user-status:empty{
    display:none;
}

.user-info{
    display:flex;
    min-height:50px;
    flex-direction:column;
    justify-content:center;
    align-self:center;
}

.user-name{
    display:block;
    line-height:1.25;
}

.user{
    display:grid;
    grid-template-columns:50px minmax(0,1fr);
    align-items:center;
    column-gap:14px;
}

.user .avatar{
    grid-column:1;
    align-self:center;
}

.user .user-info{
    grid-column:2;
}

@media(max-width:1100px){
    .contact-panel{
        width:320px;
        max-width:320px;
    }
}

@media(max-width:760px){
    .chat-header{
        min-height:68px;
        padding:10px 12px;
    }

    .chat-header-avatar{
        width:42px;
        height:42px;
        flex-basis:42px;
    }

    .chat-title h3{
        font-size:16px;
        max-width:135px;
    }

    #chat-presence{
        font-size:12px;
    }

    .icon-btn{
        width:38px;
        height:38px;
    }

    .chat-more-menu{
        right:-4px;
        top:48px;
        width:min(250px,88vw);
    }

    .contact-panel,
    .contact-panel.open{
        position:fixed;
        inset:0;
        width:100%;
        max-width:none;
        z-index:12000;
        border-left:0;
    }
}

.sidebar-toggle{
    width:100%;
    min-height:44px;
    border:1px solid var(--border);
    border-radius:14px;
    background:#fff;
    color:#273449;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    cursor:pointer;
    box-shadow:var(--shadow-sm);
    transition:transform var(--transition),box-shadow var(--transition),color var(--transition),background var(--transition);
    font-weight:800;
    font-size:14px;
    margin-top:10px;
}

.sidebar-toggle:hover{
    transform:translateY(-1px);
    color:var(--primary);
    background:#F1F6FF;
    box-shadow:0 14px 30px rgba(56,112,255,.14);
}

.page-shell,
.app-sidebar,
.brand,
.brand-copy,
.app-nav a{
    transition:grid-template-columns var(--transition),width var(--transition),padding var(--transition),gap var(--transition),transform var(--transition),opacity var(--transition);
}

.page-shell.sidebar-collapsed{
    grid-template-columns:86px minmax(0,1fr);
}

.page-shell.sidebar-collapsed .app-sidebar{
    width:86px;
    padding:24px 12px;
}

.page-shell.sidebar-collapsed .brand{
    flex-direction:column;
    justify-content:center;
    gap:10px;
    padding:0 0 22px;
}

.page-shell.sidebar-collapsed .brand-copy,
.page-shell.sidebar-collapsed .app-nav a span{
    width:0;
    opacity:0;
    overflow:hidden;
    white-space:nowrap;
    pointer-events:none;
}

.page-shell.sidebar-collapsed .sidebar-toggle span{
    display:none;
}

.page-shell.sidebar-collapsed .app-nav a{
    justify-content:center;
    padding:0;
    gap:0;
}

.page-shell.sidebar-collapsed .app-nav a i{
    width:auto;
}

.page-shell.sidebar-collapsed .sidebar-toggle{
    width:44px;
    padding:0;
    align-self:center;
}

.page-shell.sidebar-collapsed .sidebar-toggle i{
    transform:rotate(180deg);
}

.welcome-state{
    min-width:min(380px,92%);
}

.welcome-icon{
    width:52px;
    height:52px;
    margin:0 auto 14px;
    border-radius:18px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:22px;
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    box-shadow:0 18px 38px rgba(56,112,255,.22);
}

.welcome-state strong{
    font-size:20px;
}

.saved-workspace{
    max-width:560px;
    padding:26px;
    text-align:left;
    animation:savedFloat .32s ease both;
}

.saved-workspace .welcome-icon{
    margin:0 0 16px;
}

.saved-workspace strong{
    display:block;
    font-size:24px;
    margin-bottom:8px;
}

.saved-workspace p{
    margin:0 0 16px;
    color:var(--muted);
    line-height:1.6;
}

.saved-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
    margin:16px 0;
}

.saved-chip{
    display:flex;
    align-items:center;
    gap:9px;
    min-height:38px;
    padding:0 12px;
    border:1px solid var(--border);
    border-radius:12px;
    background:#FBFDFF;
    color:#273449;
    font-size:13px;
    font-weight:800;
    cursor:pointer;
    transition:.2s;
}

.saved-chip i{
    color:var(--primary);
}

.saved-chip:hover{
    border-color:rgba(56,112,255,.35);
    background:#EEF4FF;
    color:var(--primary);
    transform:translateY(-1px);
}

.saved-private-note{
    margin-top:14px;
    padding:12px 14px;
    border-radius:14px;
    background:#EEF4FF;
    color:#2458E8;
    font-size:13px;
    font-weight:800;
}

.saved-toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    padding:10px 14px;
    border-bottom:1px solid var(--border);
    background:rgba(255,255,255,.78);
}

.saved-filter{
    border:1px solid var(--border);
    background:#fff;
    color:#475569;
    min-height:32px;
    padding:0 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    cursor:pointer;
    transition:.2s;
}

.saved-filter:hover,
.saved-filter.active{
    color:var(--primary);
    border-color:rgba(56,112,255,.35);
    background:#EEF4FF;
}

.chat-area.personal-workspace .chat-header{
    background:linear-gradient(180deg,#fff,#FBFDFF);
}

.chat-area.personal-workspace #chat-presence{
    color:var(--muted);
    font-weight:700;
}

.chat-area.personal-workspace .call-action{
    display:none;
}

@keyframes savedFloat{
    from{opacity:0;transform:translateY(10px)}
    to{opacity:1;transform:none}
}

@media(max-width:640px){
    .saved-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:900px){
    .page-shell.sidebar-collapsed{
        grid-template-columns:72px minmax(0,1fr);
    }

    .page-shell.sidebar-collapsed .app-sidebar{
        width:72px;
        padding:16px 10px;
    }
}

@media(max-width:760px){
    html,
    body{
        width:100%;
        min-height:100%;
        overflow-x:hidden;
    }

    body{
        background:#EEF3FA;
    }

    .page-shell,
    .page-shell.sidebar-collapsed{
        display:block;
        min-height:100dvh;
        width:100%;
    }

    .app-sidebar,
    .page-shell.sidebar-collapsed .app-sidebar{
        position:fixed;
        left:0;
        right:0;
        bottom:0;
        top:auto;
        z-index:14000;
        width:100%;
        height:64px;
        min-height:64px;
        padding:8px 10px;
        border-right:0;
        border-top:1px solid var(--border);
        border-bottom:0;
        background:rgba(255,255,255,.98);
        box-shadow:0 -12px 30px rgba(15,23,42,.10);
    }

    .brand,
    .sidebar-toggle{
        display:none;
    }

    .app-nav{
        height:100%;
        display:flex;
        align-items:center;
        justify-content:space-around;
        gap:6px;
        overflow-x:auto;
        padding:0;
    }

    .app-nav a,
    .page-shell.sidebar-collapsed .app-nav a{
        width:44px;
        min-width:44px;
        height:44px;
        min-height:44px;
        padding:0;
        border-radius:14px;
        justify-content:center;
        flex:0 0 auto;
    }

    .app-nav a span,
    .page-shell.sidebar-collapsed .app-nav a span{
        display:none;
    }

    .chat-page{
        width:100%;
        min-height:100dvh;
        padding:0 0 64px;
    }

    .chat-topbar{
        display:none;
    }

    .chat-container{
        width:100%;
        height:calc(100dvh - 64px);
        min-height:0;
        display:grid;
        grid-template-columns:1fr;
        grid-template-rows:auto minmax(0,1fr);
        border:0;
        border-radius:0;
        box-shadow:none;
        overflow:hidden;
    }

    .users{
        width:100%;
        max-height:174px;
        min-height:0;
        flex:0 0 auto;
        border-right:0;
        border-bottom:1px solid var(--border);
    }

    .sidebar-head{
        padding:14px 14px 10px;
    }

    .sidebar-head h2{
        font-size:23px;
    }

    .sidebar-head p{
        font-size:12px;
    }

    .chat-list-search input{
        height:40px;
    }

    .user-list{
        display:flex;
        gap:8px;
        overflow-x:auto;
        overflow-y:hidden;
        padding:10px 12px;
        scroll-snap-type:x proximity;
    }

    .user{
        width:190px;
        min-width:190px;
        margin:0;
        padding:10px;
        gap:10px;
        scroll-snap-align:start;
    }

    .avatar{
        width:44px;
        height:44px;
        flex-basis:44px;
    }

    .user-name{
        font-size:14px;
    }

    .user-status{
        font-size:11px;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .chat-area{
        min-height:0;
        overflow:hidden;
    }

    .chat-header{
        min-height:62px;
        padding:9px 10px;
        gap:8px;
    }

    .mobile-chat-back{
        display:none;
    }

    body.mobile-chat-open .mobile-chat-back{
        display:inline-flex;
        flex:0 0 38px;
        width:38px;
        height:38px;
        align-items:center;
        justify-content:center;
        padding:0;
        line-height:1;
    }

    body.mobile-chat-open .app-sidebar{
        display:none;
    }

    body.mobile-chat-open .chat-page{
        padding-bottom:0;
    }

    body.mobile-chat-open .chat-container{
        height:100dvh;
        grid-template-rows:minmax(0,1fr);
    }

    body.mobile-chat-open .users{
        display:none;
    }

    body.mobile-chat-open .chat-area{
        height:100dvh;
    }

    body:not(.mobile-chat-open) .chat-container{
        height:calc(100dvh - 64px);
        grid-template-rows:minmax(0,1fr);
    }

    body:not(.mobile-chat-open) .users{
        display:flex;
        width:100%;
        height:100%;
        max-height:none;
        border-bottom:0;
    }

    body:not(.mobile-chat-open) .user-list{
        display:block;
        overflow-x:hidden;
        overflow-y:auto;
        padding:12px;
        scroll-snap-type:none;
    }

    body:not(.mobile-chat-open) .user{
        width:100%;
        min-width:0;
        margin:0 0 8px;
        scroll-snap-align:none;
    }

    body:not(.mobile-chat-open) .chat-area{
        display:none;
    }

    .chat-title{
        gap:9px;
    }

    .chat-header-avatar{
        width:40px;
        height:40px;
        flex-basis:40px;
        border-radius:13px;
    }

    .chat-title h3{
        max-width:calc(100vw - 178px);
        font-size:16px;
    }

    #chat-presence,
    #typing-status{
        max-width:calc(100vw - 178px);
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
        font-size:11px;
    }

    .chat-actions{
        gap:6px;
    }

    .icon-btn{
        width:38px;
        height:38px;
        border-radius:13px;
    }

    .chat-actions .call-action{
        display:inline-flex;
        align-items:center;
        justify-content:center;
    }

    .saved-toolbar{
        flex-wrap:nowrap;
        overflow-x:auto;
        padding:8px 10px;
        gap:7px;
    }

    .saved-filter{
        flex:0 0 auto;
        min-height:30px;
        padding:0 10px;
        font-size:12px;
    }

    #messages{
        padding:12px 10px;
        gap:9px;
    }

    .empty-state-box,
    .saved-workspace{
        width:min(100%,420px);
        max-width:100%;
        padding:18px;
    }

    .saved-grid{
        grid-template-columns:1fr;
    }

    .message-with-reactions,
    .message-row.sent .message-with-reactions,
    .message-row.received .message-with-reactions{
        max-width:88%;
    }

    .message-bubble{
        border-radius:16px;
        padding:11px 12px;
    }

    .message-media,
    .message-video{
        max-width:100%;
    }

    .voice-player{
        width:min(300px,100%);
        grid-template-columns:36px minmax(70px,1fr) 40px 38px;
        gap:7px;
        padding:8px;
    }

    .voice-play{
        width:36px;
        height:36px;
    }

    .voice-speed{
        min-width:38px;
    }

    .composer{
        display:grid;
        grid-template-columns:40px 40px 40px 40px minmax(0,1fr) 46px;
        align-items:center;
        gap:8px;
        padding:10px;
    }

    .reply-composer,
    .voice-recorder{
        grid-column:1 / -1;
    }

    .composer-btn{
        width:40px;
        height:40px;
        min-width:40px;
        flex-basis:40px;
        border-radius:13px;
        font-size:16px;
    }

    #message{
        min-width:0;
        height:44px;
        padding:0 12px;
    }

    .send-btn{
        width:46px;
        height:46px;
        flex-basis:46px;
    }

    .emoji-panel,
    .attach-menu{
        left:10px;
        right:10px;
        bottom:64px;
        width:auto;
        max-width:none;
    }

    .attach-menu{
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:8px;
        padding:10px;
    }

    .attach-menu button{
        min-height:68px;
        font-size:11px;
    }

    .tool-box{
        width:100%;
        max-height:88dvh;
        border-radius:18px;
    }

    .tool-modal{
        padding:10px;
    }

    .contact-panel,
    .contact-panel.open{
        width:100%;
        max-width:none;
    }
}

@media(max-width:420px){
    .users{
        max-height:164px;
    }

    .sidebar-head{
        padding:12px 12px 8px;
    }

    .sidebar-head h2{
        font-size:21px;
    }

    .sidebar-head p{
        display:none;
    }

    .user{
        width:168px;
        min-width:168px;
    }

    body:not(.mobile-chat-open) .user{
        width:100%;
        min-width:0;
    }

    .chat-title h3,
    #chat-presence,
    #typing-status{
        max-width:calc(100vw - 148px);
    }

    .chat-header{
        padding:8px;
    }

    .chat-actions .icon-btn:not(#search-toggle):not(#contact-toggle):not(.call-action){
        display:none;
    }

    #search-toggle{
        display:none;
    }

    .composer{
        grid-template-columns:38px 38px 38px 38px minmax(0,1fr) 44px;
        gap:6px;
        padding:8px;
    }

    .composer-btn{
        width:38px;
        height:38px;
        min-width:38px;
    }

    #message{
        height:42px;
        font-size:13px;
    }

    .send-btn{
        width:44px;
        height:44px;
    }

    .message-with-reactions,
    .message-row.sent .message-with-reactions,
    .message-row.received .message-with-reactions{
        max-width:92%;
    }

    .voice-player{
        grid-template-columns:34px minmax(54px,1fr) 38px 36px;
        gap:6px;
    }
}

/* Final responsive safety pass for phones, tablets, and wide screens */
html,
body{
    width:100%;
    max-width:100%;
    overflow-x:hidden;
    -webkit-text-size-adjust:100%;
}

img,
video,
canvas,
svg{
    max-width:100%;
}

button,
input,
select,
textarea{
    font:inherit;
    max-width:100%;
}

.page-shell,
.chat-page,
.chat-container,
.chat-area,
.chat-sidebar,
.message-bubble,
.message-with-reactions{
    min-width:0;
}

.chat-page{
    width:100%;
    max-width:100%;
}

.chat-topbar,
.chat-container{
    width:min(100%,1360px);
    max-width:calc(100vw - clamp(20px,4vw,64px));
}

.chat-topbar{
    flex-wrap:wrap;
}

.topbar-actions,
.chat-actions,
.message-menu,
.tool-box,
.call-box,
.forward-box{
    max-width:100%;
}

.message-bubble,
.reply-preview,
.message-text,
.file-attachment,
.voice-player{
    overflow-wrap:anywhere;
}

@supports (height: 100dvh){
    .page-shell{
        height:100dvh;
    }

    .chat-container{
        height:calc(100dvh - 128px);
    }
}

.image-view-button{
    max-width:100%;
    display:block;
    padding:0;
    border:0;
    border-radius:14px;
    background:transparent;
    cursor:zoom-in;
    overflow:hidden;
}

.image-lightbox{
    position:fixed;
    inset:0;
    z-index:30000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    background:rgba(15,23,42,.78);
    backdrop-filter:blur(8px);
}

.image-lightbox.open{
    display:flex;
}

.image-lightbox-stage{
    position:relative;
    width:min(980px,100%);
    height:min(82dvh,760px);
    display:flex;
    align-items:center;
    justify-content:center;
}

.image-lightbox img{
    max-width:100%;
    max-height:100%;
    border-radius:12px;
    object-fit:contain;
    background:#FFFFFF;
    box-shadow:0 30px 90px rgba(2,6,23,.45);
}

.image-lightbox-actions{
    position:absolute;
    top:12px;
    right:12px;
    display:flex;
    gap:8px;
}

.image-lightbox-actions a,
.image-lightbox-actions button,
.call-sound-btn{
    width:44px;
    height:44px;
    border:1px solid rgba(255,255,255,.28);
    border-radius:14px;
    background:rgba(15,23,42,.70);
    color:#FFFFFF;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    text-decoration:none;
}

.call-sound-btn{
    position:absolute;
    left:50%;
    bottom:16px;
    transform:translateX(-50%);
    width:auto;
    min-width:138px;
    gap:8px;
    padding:0 14px;
    font-weight:850;
    font-size:13px;
    z-index:3;
}

.call-sound-btn[hidden]{
    display:none;
}

@media(min-width:1440px){
    .chat-container,
    .chat-topbar{
        width:min(1500px,calc(100vw - 80px));
    }

    .chat-container{
        grid-template-columns:380px minmax(0,1fr) auto;
    }
}

@media(max-width:760px){
    html,
    body{
        position:fixed;
        inset:0;
        width:100%;
        height:100%;
        overflow:hidden;
    }

    .page-shell{
        height:100dvh;
        overflow:hidden;
    }

    .chat-page{
        height:100dvh;
        min-height:0;
        overflow:hidden;
        padding-bottom:calc(64px + env(safe-area-inset-bottom));
    }

    .app-sidebar,
    .page-shell.sidebar-collapsed .app-sidebar{
        height:calc(64px + env(safe-area-inset-bottom));
        min-height:calc(64px + env(safe-area-inset-bottom));
        padding-bottom:calc(8px + env(safe-area-inset-bottom));
        padding-left:max(10px,env(safe-area-inset-left));
        padding-right:max(10px,env(safe-area-inset-right));
    }

    .chat-container{
        height:calc(100dvh - 64px - env(safe-area-inset-bottom));
        max-height:calc(100dvh - 64px - env(safe-area-inset-bottom));
    }

    body.mobile-chat-open .chat-container,
    body.mobile-chat-open .chat-area{
        height:100dvh;
        max-height:100dvh;
    }

    .chat-area{
        display:flex;
        flex-direction:column;
    }

    #messages{
        min-height:0;
        flex:1 1 auto;
        overflow-y:auto;
        -webkit-overflow-scrolling:touch;
        padding-bottom:14px;
    }

    .composer{
        flex:0 0 auto;
        padding-bottom:calc(10px + env(safe-area-inset-bottom));
        padding-left:max(10px,env(safe-area-inset-left));
        padding-right:max(10px,env(safe-area-inset-right));
    }

    .chat-header{
        flex:0 0 auto;
    }

    .message-media,
    .message-video{
        max-width:min(100%,72vw);
        max-height:46dvh;
        object-fit:contain;
    }

    .call-box{
        width:100%;
        max-height:92dvh;
        border-radius:16px;
    }

    .call-controls{
        gap:8px;
        padding:12px;
        flex-wrap:wrap;
    }

    .call-control-btn{
        width:46px;
        height:46px;
    }

    .call-video-stage{
        height:min(58dvh,420px);
    }

    .image-lightbox{
        padding:10px;
    }

    .image-lightbox-stage{
        height:88dvh;
    }

    .chat-topbar,
    .chat-container{
        max-width:100%;
    }

    .message-menu{
        max-width:calc(100vw - 28px);
    }

    .tool-box,
    .forward-box,
    .call-box{
        width:calc(100vw - 20px);
    }
}

@media(max-width:360px){
    .composer{
        grid-template-columns:36px 36px 36px minmax(0,1fr) 42px;
    }

    #media-btn{
        display:none;
    }

    .composer-btn{
        width:36px;
        height:36px;
        min-width:36px;
    }

    .send-btn{
        width:42px;
        height:42px;
        flex-basis:42px;
    }
}

</style>

</head>


<body>

<div class="page-shell">
<aside class="app-sidebar">
    <div class="brand">
        <div class="brand-icon"><i class="fa-solid fa-comments"></i></div>
        <div class="brand-copy">
            <h1>Chat Web</h1>
            <span>Messaging workspace</span>
        </div>
    </div>
    <nav class="app-nav">
        <a href="dashboard.php"><i class="fa-solid fa-table-columns"></i> <span>Dashboard</span></a>
        <a href="chat.php" class="active"><i class="fa-solid fa-message"></i> <span>Chats</span></a>
        <a href="profile.php?v=2"><i class="fa-solid fa-user"></i> <span>Profile</span></a>
        <a href="settings.php?v=2"><i class="fa-solid fa-gear"></i> <span>Settings</span></a>
        <a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> <span>Logout</span></a>
        <button class="sidebar-toggle" id="sidebar-toggle" type="button" title="Close sidebar" aria-label="Close sidebar">
            <i class="fa-solid fa-chevron-left"></i>
            <span>Collapse sidebar</span>
        </button>
    </nav>
</aside>

<main class="chat-page">
<div class="chat-topbar">
    <div>
        <h2>Chats</h2>
        <p>Continue conversations, send media, and track seen status.</p>
    </div>
    <div class="topbar-actions">
        <div id="notification-inline"></div>
        <a class="power-btn" href="../logout.php" title="Logout">
            <i class="fa-solid fa-power-off"></i>
        </a>
    </div>
</div>

<div class="chat-container">

<div class="users">

<div class="sidebar-head">
    <h2>Messages</h2>
    <p>Select a person to start chatting</p>
    <button class="new-group-btn" type="button" id="new-group-btn">
        <i class="fa-solid fa-user-group"></i>
        <span>New Group</span>
    </button>
    <div class="chat-list-search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="user-search-input" placeholder="Search users..." aria-label="Search users">
    </div>
</div>

<div class="user-list">

<?php if(mysqli_num_rows($groups) > 0){ ?>
<div class="list-section-label">Groups</div>
<?php while($group=mysqli_fetch_assoc($groups)){
    $groupTitle = $group['title'] ?: 'Group Chat';
    $groupInitial = strtoupper(substr(trim($groupTitle), 0, 1));
    $groupImage = $group['image'] ?? '';
    $groupImageUrl = '';

    if (!empty($groupImage)) {
        $groupImageUrl = str_starts_with($groupImage, 'uploads/')
            ? '../' . $groupImage
            : '../uploads/' . $groupImage;
    }
?>

<div
class="user group-chat"
data-is-group="1"
data-conversation-id="<?php echo (int) $group['id']; ?>"
data-user-id="0"
data-user-name="<?php echo htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8'); ?>"
data-user-status="group"
data-user-presence="<?php echo (int) $group['member_count']; ?> members"
data-user-image="<?php echo htmlspecialchars($groupImageUrl, ENT_QUOTES, 'UTF-8'); ?>"
>

<div class="avatar group-avatar">
    <?php if($groupImageUrl){ ?>
        <img src="<?php echo htmlspecialchars($groupImageUrl); ?>" alt="<?php echo htmlspecialchars($groupTitle); ?>">
    <?php } else { ?>
        <?php echo htmlspecialchars($groupInitial); ?>
    <?php } ?>
</div>

<div class="user-info">
    <span class="user-name">
        <?php echo htmlspecialchars($groupTitle); ?>
    </span>
    <span class="user-status">
        <?php echo (int) $group['member_count']; ?> members
    </span>
</div>

</div>

<?php } ?>
<div class="list-section-label">People</div>
<?php } ?>

<?php if(mysqli_num_rows($users) > 0){ ?>
<?php while($user=mysqli_fetch_assoc($users)){
    $name = $user['full_name'] ?? 'User';
    $initial = strtoupper(substr(trim($name), 0, 1));
    $status = effectivePresenceStatus($user['status'] ?: 'offline', $user['last_seen'] ?? null, $presence_timeout_seconds);
    $presence = presenceText($status, $user['last_seen'] ?? null, $presence_timeout_seconds, $user['created_at'] ?? null);
    $profileImage = $user['profile_image'] ?? '';
    $profileImageUrl = '';

    if (!empty($profileImage)) {
        $profileImageUrl = str_starts_with($profileImage, 'uploads/')
            ? '../' . $profileImage
            : '../uploads/' . $profileImage;
    }
?>

<div
class="user"
data-user-id="<?php echo (int) $user['id']; ?>"
data-user-name="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
data-user-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"
data-user-presence="<?php echo htmlspecialchars($presence, ENT_QUOTES, 'UTF-8'); ?>"
data-user-image="<?php echo htmlspecialchars($profileImageUrl, ENT_QUOTES, 'UTF-8'); ?>"
>

<div class="avatar">
    <?php if($profileImageUrl){ ?>
        <img src="<?php echo htmlspecialchars($profileImageUrl); ?>" alt="<?php echo htmlspecialchars($name); ?>">
    <?php } else { ?>
        <?php echo htmlspecialchars($initial); ?>
    <?php } ?>
    <?php if ($status === 'online') { ?>
        <span class="avatar-status-dot"></span>
    <?php } ?>
</div>

<div class="user-info">
    <span class="user-name">
        <?php echo htmlspecialchars($name); ?>
    </span>
    <span class="user-status">
        <?php echo htmlspecialchars($status === 'online' ? '' : $presence); ?>
    </span>
</div>


</div>

<?php } ?>
<?php } else { ?>
    <div class="empty-users">No active users found.</div>
<?php } ?>

<div class="empty-users user-search-empty" id="user-search-empty" style="display:none;">No users found.</div>

</div>

</div>



<div class="chat-area personal-workspace" id="chat-area">

<div class="chat-header">
    <button class="icon-btn mobile-chat-back" type="button" id="mobile-chat-back" title="Back to chats" aria-label="Back to chats">
        <i class="fa-solid fa-arrow-left"></i>
    </button>
    <div class="chat-title">
        <div class="chat-header-avatar" id="chat-header-avatar">
            <?php if($currentUserImageUrl){ ?>
                <img src="<?php echo htmlspecialchars($currentUserImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?>">
            <?php } else { ?>
                <?php echo htmlspecialchars(strtoupper(substr(trim($currentUserName ?: 'S'), 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
            <?php } ?>
        </div>
        <div>
            <h3 id="chat-user"><?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?></h3>
            <div id="chat-presence">@<?php echo htmlspecialchars($currentUsername ?: 'saved', ENT_QUOTES, 'UTF-8'); ?> &bull; Saved Messages</div>
            <div id="typing-status"></div>
        </div>
    </div>
    <div class="chat-actions">
        <button class="icon-btn call-action" type="button" title="Phone Call" aria-label="Phone Call" data-call-type="audio">
            <i class="fa-solid fa-phone"></i>
        </button>
        <button class="icon-btn call-action" type="button" title="Video Call" aria-label="Video Call" data-call-type="video">
            <i class="fa-solid fa-video"></i>
        </button>
        <button class="icon-btn" type="button" id="search-toggle" title="Search" aria-label="Search">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>
        <div class="more-menu-wrap">
        <button class="icon-btn" type="button" id="contact-toggle" title="More Options" aria-label="More Options" aria-expanded="false">
            <i class="fa-solid fa-ellipsis-vertical"></i>
        </button>
        <div class="chat-more-menu" id="chat-more-menu">
            <button type="button" data-more-action="profile"><i class="fa-solid fa-user"></i><span>Profile</span></button>
            <button type="button" data-more-action="search"><i class="fa-solid fa-magnifying-glass"></i><span>Search</span></button>
            <button type="button" data-more-action="mute"><i class="fa-solid fa-bell-slash"></i><span>Mute Notifications</span></button>
            <button type="button" data-more-action="starred"><i class="fa-solid fa-star"></i><span>Star Messages</span></button>
            <button type="button" data-more-action="media"><i class="fa-solid fa-photo-film"></i><span>Media</span></button>
            <button type="button" data-more-action="pinned"><i class="fa-solid fa-thumbtack"></i><span>Pinned Messages</span></button>
            <button type="button" data-more-action="wallpaper"><i class="fa-solid fa-image"></i><span>Wallpaper</span></button>
            <button type="button" data-more-action="clear"><i class="fa-solid fa-broom"></i><span>Clear Chat</span></button>
            <button type="button" data-more-action="export"><i class="fa-solid fa-file-export"></i><span>Export Chat</span></button>
            <button type="button" data-more-action="block" class="danger"><i class="fa-solid fa-ban"></i><span>Block User</span></button>
            <button type="button" data-more-action="report" class="danger"><i class="fa-solid fa-flag"></i><span>Report User</span></button>
            <button type="button" data-more-action="delete" class="danger"><i class="fa-solid fa-trash"></i><span>Delete Conversation</span></button>
        </div>
        </div>
    </div>
</div>

<div class="message-search" id="message-search">
    <input type="text" id="message-search-input" placeholder="Search Saved Messages...">
</div>

<div class="saved-toolbar" id="saved-toolbar">
    <button class="saved-filter active" type="button" data-saved-filter="all">All</button>
    <button class="saved-filter" type="button" data-saved-filter="notes">Notes</button>
    <button class="saved-filter" type="button" data-saved-filter="image">Images</button>
    <button class="saved-filter" type="button" data-saved-filter="video">Videos</button>
    <button class="saved-filter" type="button" data-saved-filter="file">Documents</button>
    <button class="saved-filter" type="button" data-saved-filter="audio">Audio</button>
    <button class="saved-filter" type="button" data-saved-filter="links">Links</button>
    <button class="saved-filter" type="button" data-saved-filter="favorites">Favorites</button>
    <button class="saved-filter" type="button" data-saved-filter="pinned">Pinned</button>
    <button class="saved-filter" type="button" data-saved-filter="recent">Recent</button>
</div>

<div id="messages">
    <div class="empty-state">
        <div class="empty-state-box welcome-state saved-workspace">
            <div class="welcome-icon"><i class="fa-solid fa-bookmark"></i></div>
            <strong>Saved Messages</strong>
            <p>Keep your notes, files, images and important information here.</p>
            <div class="saved-grid">
                <button class="saved-chip" type="button" data-saved-action="notes"><i class="fa-solid fa-note-sticky"></i> Notes</button>
                <button class="saved-chip" type="button" data-saved-action="image"><i class="fa-solid fa-image"></i> Images</button>
                <button class="saved-chip" type="button" data-saved-action="video"><i class="fa-solid fa-video"></i> Videos</button>
                <button class="saved-chip" type="button" data-saved-action="file"><i class="fa-solid fa-file-lines"></i> Documents</button>
                <button class="saved-chip" type="button" data-saved-action="audio"><i class="fa-solid fa-music"></i> Audio</button>
                <button class="saved-chip" type="button" data-saved-action="favorites"><i class="fa-solid fa-star"></i> Favorites</button>
                <button class="saved-chip" type="button" data-saved-action="pinned"><i class="fa-solid fa-thumbtack"></i> Pinned reminders</button>
                <button class="saved-chip" type="button" data-saved-action="links"><i class="fa-solid fa-link"></i> Personal links</button>
            </div>
            <div class="saved-private-note">This area is completely private.</div>
        </div>
    </div>
</div>


<div class="composer">
    <div class="emoji-panel" id="emoji-panel"></div>
    <div class="attach-menu" id="attach-menu">
        <button type="button" data-attach-option="gallery"><i class="fa-solid fa-images"></i><span>Gallery</span></button>
        <button type="button" data-attach-option="document"><i class="fa-solid fa-file-lines"></i><span>Document</span></button>
        <button type="button" data-attach-option="audio"><i class="fa-solid fa-music"></i><span>Audio</span></button>
        <button type="button" data-attach-option="video"><i class="fa-solid fa-video"></i><span>Video</span></button>
        <button type="button" data-attach-option="contact"><i class="fa-solid fa-address-book"></i><span>Contact</span></button>
    </div>
    <div class="upload-status" id="upload-status">Uploading...</div>
    <div class="reply-composer" id="reply-composer">
        <div>
            <strong>Replying to</strong>
            <span id="reply-text"></span>
        </div>
        <button type="button" id="cancel-reply" title="Cancel reply">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <div class="voice-recorder" id="voice-recorder">
        <div class="voice-recorder-status">
            <span class="voice-dot"></span>
            <strong>Recording</strong>
            <span id="voice-timer">00:00</span>
        </div>
        <div class="voice-recorder-actions">
            <button type="button" id="voice-cancel">Cancel</button>
            <button type="button" id="voice-send">Send</button>
        </div>
    </div>

    <button class="composer-btn" type="button" id="emoji-btn" title="Emoji">
        <i class="fa-regular fa-face-smile"></i>
    </button>

    <button class="composer-btn" type="button" id="attach-btn" title="Attach file">
        <i class="fa-solid fa-paperclip"></i>
    </button>

    <button class="composer-btn" type="button" id="media-btn" title="Open gallery" aria-label="Open gallery">
        <i class="fa-solid fa-camera"></i>
    </button>

    <button class="composer-btn" type="button" id="mic-btn" title="Voice note" aria-label="Voice note">
        <i class="fa-solid fa-microphone"></i>
    </button>

    <input
    type="file"
    id="file-input"
    accept="image/*,.gif,video/mp4,video/webm,video/quicktime,audio/*,.pdf,.doc,.docx,.txt,.zip,.rar,.xls,.xlsx,.ppt,.pptx"
    hidden
    >

    <input
    type="file"
    id="media-input"
    accept="image/*,video/*,audio/*"
    hidden
    >

    <input
    type="text"
    id="message"
    placeholder="Type a message..."
    autocomplete="off"
    >

    <button class="send-btn" onclick="sendMessage()">
        <i class="fa-solid fa-paper-plane"></i>
        <span class="sr-only">Send</span>
    </button>
</div>


</div>

<aside class="contact-panel" id="contact-panel">
    <div class="contact-cover">
        <button class="contact-close" type="button" id="contact-close" title="Close profile" aria-label="Close profile">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="contact-avatar" id="contact-avatar">?</div>
        <h3 id="contact-name">Contact</h3>
        <p id="contact-presence">Select a user</p>
    </div>
    <div class="contact-details">
        <div class="contact-row contact-action-row" data-contact-action="media">
            <span><i class="fa-solid fa-circle"></i> Status</span>
            <strong id="contact-status">-</strong>
        </div>
        <div class="contact-row">
            <span><i class="fa-solid fa-photo-film"></i> Shared Media</span>
            <strong>Images, Videos, Files</strong>
        </div>
        <div class="contact-row contact-action-row" data-contact-action="search">
            <span><i class="fa-solid fa-magnifying-glass"></i> Search Conversation</span>
            <strong>Find messages</strong>
        </div>
        <div class="contact-row contact-action-row" data-contact-action="starred">
            <span><i class="fa-solid fa-star"></i> Starred Messages</span>
            <strong>Saved items</strong>
        </div>
        <div class="contact-row contact-action-row" data-contact-action="block">
            <span><i class="fa-solid fa-ban"></i> Block User</span>
            <strong>Restrict contact</strong>
        </div>
        <div class="contact-row contact-action-row" data-contact-action="clear">
            <span><i class="fa-solid fa-broom"></i> Clear Chat</span>
            <strong>Remove messages</strong>
        </div>
        <div class="contact-row contact-action-row danger" data-contact-action="delete">
            <span><i class="fa-solid fa-trash"></i> Delete Conversation</span>
            <strong>Permanent action</strong>
        </div>
        <div class="contact-row contact-action-row" data-contact-action="export">
            <span><i class="fa-solid fa-file-export"></i> Export Chat</span>
            <strong>Download data</strong>
        </div>
    </div>
</aside>


</div>
</div>
</main>

<div class="forward-modal" id="forward-modal">
    <div class="forward-box">
        <div class="forward-head">
            <h3>Forward message</h3>
            <button class="forward-close" type="button" id="forward-close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="forward-list" id="forward-list"></div>
    </div>
</div>

<div class="call-modal" id="call-modal" aria-hidden="true">
    <div class="call-box">
        <div class="call-head">
            <div>
                <strong id="call-title">Calling...</strong>
                <span id="call-status">Connecting</span>
            </div>
            <button type="button" class="call-small-btn" id="call-close" title="Close" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="call-video-stage" id="call-video-stage">
            <video id="remote-video" autoplay playsinline></video>
            <audio id="remote-audio" autoplay playsinline></audio>
            <video id="local-video" autoplay muted playsinline></video>
            <div class="call-audio-avatar" id="call-audio-avatar">
                <i class="fa-solid fa-phone-volume"></i>
            </div>
            <button class="call-sound-btn" type="button" id="call-sound-btn" hidden>
                <i class="fa-solid fa-volume-high"></i>
                <span>Enable sound</span>
            </button>
        </div>
        <div class="call-controls">
            <button type="button" class="call-control-btn accept" id="call-accept" title="Accept" aria-label="Accept">
                <i class="fa-solid fa-phone"></i>
            </button>
            <button type="button" class="call-control-btn" id="call-mic" title="Mute microphone" aria-label="Mute microphone">
                <i class="fa-solid fa-microphone"></i>
            </button>
            <button type="button" class="call-control-btn" id="call-speaker" title="Loud speaker" aria-label="Loud speaker">
                <i class="fa-solid fa-volume-high"></i>
            </button>
            <button type="button" class="call-control-btn" id="call-camera" title="Toggle camera" aria-label="Toggle camera">
                <i class="fa-solid fa-video"></i>
            </button>
            <button type="button" class="call-control-btn danger" id="call-end" title="End call" aria-label="End call">
                <i class="fa-solid fa-phone-slash"></i>
            </button>
        </div>
    </div>
</div>

<div class="image-lightbox" id="image-lightbox" aria-hidden="true">
    <div class="image-lightbox-stage">
        <img id="image-lightbox-img" src="" alt="">
        <div class="image-lightbox-actions">
            <a id="image-lightbox-download" href="#" download title="Download image" aria-label="Download image">
                <i class="fa-solid fa-download"></i>
            </a>
            <button type="button" id="image-lightbox-close" title="Close image" aria-label="Close image">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>
</div>

<div class="tool-modal" id="tool-modal">
    <div class="tool-box">
        <div class="tool-head">
            <h3 id="tool-title">Chat tools</h3>
            <button class="tool-close" type="button" id="tool-close" title="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="tool-tabs" id="tool-tabs"></div>
        <div class="tool-search" id="tool-search-wrap">
            <input type="text" id="tool-search" placeholder="Search...">
        </div>
        <div class="tool-body" id="tool-body"></div>
    </div>
</div>



<script>

let conversation_id = 0;
let typingTimer = null;
let isViewOnce = false;
let replyToMessageId = 0;
let replyToText = "";
let forwardMessageText = "";
let currentMessages = [];
let forceScrollToBottom = false;
let lastMessagesSignature = "";
let voiceRecorder = null;
let voiceStream = null;
let voiceChunks = [];
let voiceTimer = null;
let voiceStartedAt = 0;
let voiceCancelled = false;
let pendingMediaFile = null;
let pendingMediaUrl = "";
let selectedContact = {
    id: 0,
    name: "",
    presence: "",
    status: "",
    image: "",
    blocked: false,
    isGroup: false
};
const currentUserId = <?php echo json_encode((int) $current_user); ?>;
const selfConversationId = <?php echo json_encode((int) $selfConversationId); ?>;
const selfProfile = {
    id: currentUserId,
    name: <?php echo json_encode($currentUserName); ?>,
    username: <?php echo json_encode($currentUsername); ?>,
    status: <?php echo json_encode($currentUserStatus); ?>,
    image: <?php echo json_encode($currentUserImageUrl); ?>
};
let chatWallpaper = {
    type: <?php echo json_encode($currentThemeSettings['wallpaper_type'] ?? 'Pattern'); ?>,
    value: <?php echo json_encode($currentThemeSettings['wallpaper_value'] ?? 'dots-blue'); ?>
};
let isPersonalWorkspace = true;
let savedFilter = "all";
const emojis = [
    "😀","😁","😂","🤣","😊","😍","😘","😎",
    "🙂","😇","🥰","😋","😜","🤔","😐","😢",
    "😭","😡","👍","👎","👏","🙏","💪","🔥",
    "❤️","💙","💚","💯","✅","🎉","✨","⭐"
];

const callConfig = {
    iceServers: [
        { urls: "stun:stun.l.google.com:19302" },
        { urls: "stun:stun1.l.google.com:19302" },
        { urls: "stun:openrelay.metered.ca:80" },
        {
            urls: [
                "turn:openrelay.metered.ca:80",
                "turn:openrelay.metered.ca:443",
                "turn:openrelay.metered.ca:443?transport=tcp"
            ],
            username: "openrelayproject",
            credential: "openrelayproject"
        }
    ],
    iceCandidatePoolSize: 4
};
let activeCall = {
    id: 0,
    type: "audio",
    role: "",
    peer: null,
    localStream: null,
    remoteStream: null,
    status: "idle",
    incomingOffer: null,
    answered: false,
    polling: false,
    pendingIce: [],
    queuedRemoteIce: [],
    upgradeOffer: null,
    upgradePrompted: false,
    upgradeRequested: false
};
let callAudioUnlocked = false;
let callSpeakerOn = false;

const pageShell = document.querySelector(".page-shell");
const sidebarToggle = document.getElementById("sidebar-toggle");

function callModal(){
    return document.getElementById("call-modal");
}

function mediaPermissionMessage(error, action){
    if(!window.isSecureContext && location.hostname !== "localhost" && location.hostname !== "127.0.0.1"){
        return action + " needs HTTPS on mobile. Open this site with HTTPS or test on localhost.";
    }

    if(error && error.name === "NotAllowedError"){
        return "Please allow microphone/camera permission, then try again.";
    }

    if(error && error.name === "NotFoundError"){
        return "No microphone/camera was found on this device.";
    }

    if(error && error.name === "NotReadableError"){
        return "Microphone/camera is busy in another app.";
    }

    return action + " could not start on this device.";
}

function isMediaAccessError(error){
    return error && ["NotAllowedError", "NotFoundError", "NotReadableError", "OverconstrainedError", "SecurityError"].includes(error.name);
}

function setCallStatus(title, status){
    document.getElementById("call-title").textContent = title || "Call";
    document.getElementById("call-status").textContent = status || "";
}

function openCallModal(mode, callType, title, status){
    const modal = callModal();
    modal.classList.add("open");
    modal.classList.toggle("incoming", mode === "incoming");
    modal.classList.toggle("audio-call", callType !== "video");
    modal.setAttribute("aria-hidden", "false");
    document.getElementById("call-sound-btn").hidden = true;
    document.getElementById("call-camera").style.display = "";
    document.getElementById("call-camera").setAttribute("title", callType === "video" ? "Toggle camera" : "Convert to video call");
    document.getElementById("call-camera").setAttribute("aria-label", callType === "video" ? "Toggle camera" : "Convert to video call");
    setCallStatus(title, status);
}

function closeCallModal(){
    const modal = callModal();
    modal.classList.remove("open", "incoming", "audio-call");
    modal.setAttribute("aria-hidden", "true");
    document.getElementById("remote-video").srcObject = null;
    document.getElementById("remote-audio").srcObject = null;
    document.getElementById("local-video").srcObject = null;
    document.getElementById("call-sound-btn").hidden = true;
    callAudioUnlocked = false;
    callSpeakerOn = false;
    document.getElementById("call-speaker").classList.remove("is-on");
}

function callSignal(action, payload = {}){
    const formData = new FormData();
    formData.append("action", action);

    Object.keys(payload).forEach(function(key){
        formData.append(key, payload[key]);
    });

    return fetch("../ajax/call_signal.php", {
        method: "POST",
        body: formData
    }).then(res => res.json());
}

function resetActiveCall(closeModal = true){
    if(activeCall.peer){
        activeCall.peer.onicecandidate = null;
        activeCall.peer.ontrack = null;
        activeCall.peer.close();
    }

    if(activeCall.localStream){
        activeCall.localStream.getTracks().forEach(track => track.stop());
    }

    activeCall = {
        id: 0,
        type: "audio",
        role: "",
        peer: null,
        localStream: null,
        remoteStream: null,
        status: "idle",
        incomingOffer: null,
        answered: false,
        polling: false,
        pendingIce: [],
        queuedRemoteIce: [],
        upgradeOffer: null,
        upgradePrompted: false,
        upgradeRequested: false
    };

    document.getElementById("call-mic").classList.remove("is-off");
    document.getElementById("call-camera").classList.remove("is-off");
    document.getElementById("call-speaker").classList.remove("is-on");

    if(closeModal){
        closeCallModal();
    }
}

function ensureCallReady(){
    if(isPersonalWorkspace || conversation_id == 0){
        alert("Please select a user first.");
        return false;
    }

    if(selectedContact.isGroup){
        alert("Group calls are not available yet.");
        return false;
    }

    if(selectedContact.blocked){
        alert("Unblock this user before calling.");
        return false;
    }

    if(!window.isSecureContext && location.hostname !== "localhost" && location.hostname !== "127.0.0.1"){
        alert("Audio/video calls need HTTPS on mobile. Open this site with HTTPS or test on localhost.");
        return false;
    }

    if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.RTCPeerConnection){
        alert("Audio/video calls are not supported in this browser.");
        return false;
    }

    return true;
}

function createPeer(){
    const peer = new RTCPeerConnection(callConfig);

    peer.onicecandidate = function(event){
        if(!event.candidate){
            return;
        }

        if(!activeCall.id){
            activeCall.pendingIce.push(event.candidate);
            return;
        }

        if(activeCall.id){
            callSignal("ice", {
                call_id: activeCall.id,
                candidate: JSON.stringify(event.candidate)
            }).catch(() => {});
        }
    };

    peer.ontrack = function(event){
        if(!activeCall.remoteStream){
            activeCall.remoteStream = new MediaStream();
            attachRemoteStream(activeCall.remoteStream);
        }

        if(!activeCall.remoteStream.getTracks().includes(event.track)){
            activeCall.remoteStream.addTrack(event.track);
        }

        attachRemoteStream(activeCall.remoteStream);
        playRemoteAudio();
        setCallStatus(document.getElementById("call-title").textContent, "Connected");
    };

    peer.onconnectionstatechange = function(){
        if(["failed", "disconnected", "closed"].includes(peer.connectionState) && activeCall.status === "active"){
            setCallStatus(document.getElementById("call-title").textContent, "Call disconnected");
        }
    };

    return peer;
}

function attachRemoteStream(stream){
    const remoteVideo = document.getElementById("remote-video");
    const remoteAudio = document.getElementById("remote-audio");
    remoteAudio.muted = false;
    remoteAudio.volume = 1;
    remoteAudio.setAttribute("playsinline", "");
    remoteVideo.muted = false;
    remoteVideo.volume = 1;
    remoteVideo.setAttribute("playsinline", "");

    if(remoteVideo.srcObject !== stream){
        remoteVideo.srcObject = stream;
    }

    if(remoteAudio.srcObject !== stream){
        remoteAudio.srcObject = stream;
    }

    if(callAudioUnlocked || callModal().classList.contains("open")){
        playRemoteAudio();
    }
}

function playRemoteAudio(){
    const remoteAudio = document.getElementById("remote-audio");
    const remoteVideo = document.getElementById("remote-video");
    const soundButton = document.getElementById("call-sound-btn");

    if(remoteAudio && remoteAudio.play){
        remoteAudio.muted = false;
        remoteAudio.volume = 1;
        remoteAudio.setAttribute("playsinline", "");
        const audioPromise = remoteAudio.play();
        const videoPromise = remoteVideo && remoteVideo.srcObject ? remoteVideo.play().catch(() => {}) : Promise.resolve();

        return Promise.all([audioPromise, videoPromise]).then(() => {
            callAudioUnlocked = true;
            if(soundButton){
                soundButton.hidden = true;
            }
        }).catch(() => {
            if(soundButton){
                soundButton.hidden = false;
            }
            setCallStatus(document.getElementById("call-title").textContent, "Tap the call window to hear audio");
        });
    }

    return Promise.resolve();
}

async function applySpeakerMode(){
    const remoteAudio = document.getElementById("remote-audio");
    const remoteVideo = document.getElementById("remote-video");
    const targetSink = callSpeakerOn ? "default" : "";

    [remoteAudio, remoteVideo].forEach(function(media){
        if(!media){
            return;
        }

        media.muted = false;
        media.volume = 1;

        if(typeof media.setSinkId === "function"){
            media.setSinkId(targetSink).catch(() => {});
        }
    });

    document.getElementById("call-speaker").classList.toggle("is-on", callSpeakerOn);
    document.getElementById("call-speaker").setAttribute("title", callSpeakerOn ? "Speaker on" : "Loud speaker");
    document.getElementById("call-speaker").setAttribute("aria-label", callSpeakerOn ? "Speaker on" : "Loud speaker");
    await playRemoteAudio();
}

function toggleCallSpeaker(){
    callSpeakerOn = !callSpeakerOn;
    applySpeakerMode();
}

function unlockCallAudio(){
    const remoteAudio = document.getElementById("remote-audio");
    const remoteVideo = document.getElementById("remote-video");
    const soundButton = document.getElementById("call-sound-btn");
    callAudioUnlocked = true;

    if(remoteAudio){
        remoteAudio.muted = false;
        remoteAudio.volume = 1;

        if(remoteAudio.srcObject){
            remoteAudio.play().then(() => {
                if(soundButton){
                    soundButton.hidden = true;
                }
            }).catch(() => {
                if(soundButton){
                    soundButton.hidden = false;
                }
            });
        }
    }

    if(remoteVideo && activeCall.type === "video"){
        remoteVideo.muted = false;
        remoteVideo.volume = 1;
        remoteVideo.play().catch(() => {});
    }

    applySpeakerMode();
}

function flushPendingIce(){
    if(!activeCall.id || !activeCall.pendingIce.length){
        return;
    }

    const candidates = activeCall.pendingIce.splice(0);

    candidates.forEach(function(candidate){
        callSignal("ice", {
            call_id: activeCall.id,
            candidate: JSON.stringify(candidate)
        }).catch(() => {});
    });
}

async function prepareLocalMedia(callType){
    const stream = await navigator.mediaDevices.getUserMedia({
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true
        },
        video: callType === "video" ? {
            facingMode: "user"
        } : false
    });
    activeCall.localStream = stream;
    document.getElementById("local-video").srcObject = stream;

    const audioTrack = stream.getAudioTracks()[0];

    if(!audioTrack || audioTrack.readyState !== "live"){
        throw new Error("Microphone did not start");
    }

    return stream;
}

function attachLocalTracksToPeer(stream){
    if(!activeCall.peer || !stream){
        return;
    }

    stream.getTracks().forEach(function(track){
        const existingSender = activeCall.peer.getSenders().find(function(sender){
            return sender.track === track;
        });

        if(existingSender){
            return;
        }

        activeCall.peer.addTrack(track, stream);
    });
}

function ensureMicTrackSending(){
    if(!activeCall.peer || !activeCall.localStream){
        return;
    }

    const audioTrack = activeCall.localStream.getAudioTracks()[0];

    if(!audioTrack){
        return;
    }

    audioTrack.enabled = true;

    const audioSender = activeCall.peer.getSenders().find(function(sender){
        return sender.track && sender.track.kind === "audio";
    });

    if(audioSender){
        if(audioSender.track !== audioTrack && audioSender.replaceTrack){
            audioSender.replaceTrack(audioTrack).catch(() => {});
        }
        return;
    }

    activeCall.peer.addTrack(audioTrack, activeCall.localStream);
}

async function addLocalVideoTrack(){
    if(activeCall.localStream && activeCall.localStream.getVideoTracks().length){
        return activeCall.localStream.getVideoTracks()[0];
    }

    const videoStream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: true
    });
    const videoTrack = videoStream.getVideoTracks()[0];

    if(!activeCall.localStream){
        activeCall.localStream = new MediaStream();
    }

    activeCall.localStream.addTrack(videoTrack);
    document.getElementById("local-video").srcObject = activeCall.localStream;

    if(activeCall.peer && videoTrack){
        attachLocalTracksToPeer(activeCall.localStream);
    }

    return videoTrack;
}

function switchCallToVideo(statusText = "Video call connected"){
    activeCall.type = "video";
    activeCall.status = "active";
    activeCall.upgradeRequested = false;
    activeCall.upgradePrompted = false;
    activeCall.upgradeOffer = null;
    callModal().classList.remove("audio-call", "incoming");
    document.getElementById("call-camera").classList.remove("is-off");
    document.getElementById("call-camera").setAttribute("title", "Toggle camera");
    document.getElementById("call-camera").setAttribute("aria-label", "Toggle camera");
    setCallStatus(document.getElementById("call-title").textContent, statusText);
}

async function requestVideoUpgrade(){
    if(activeCall.type === "video"){
        toggleCallCamera();
        return;
    }

    if(!activeCall.id || !activeCall.peer || activeCall.status !== "active"){
        alert("Video can be enabled after the audio call connects.");
        return;
    }

    if(activeCall.upgradeRequested){
        setCallStatus(document.getElementById("call-title").textContent, "Video request already sent");
        return;
    }

    try {
        await addLocalVideoTrack();
        const offer = await activeCall.peer.createOffer();
        await activeCall.peer.setLocalDescription(offer);

        const data = await callSignal("upgrade_offer", {
            call_id: activeCall.id,
            offer: JSON.stringify(offer)
        });

        if(data.status !== "success"){
            throw new Error(data.message || "Video request could not be sent");
        }

        activeCall.upgradeRequested = true;
        callModal().classList.remove("audio-call");
        setCallStatus(document.getElementById("call-title").textContent, "Video request sent...");
    } catch (error) {
        alert(isMediaAccessError(error) ? mediaPermissionMessage(error, "Camera") : (error.message || "Video request could not be sent"));
    }
}

async function startCall(callType){
    if(!ensureCallReady()){
        return;
    }

    unlockCallAudio();
    resetActiveCall(false);
    activeCall.type = callType;
    activeCall.role = "caller";
    activeCall.status = "starting";
    openCallModal("outgoing", callType, selectedContact.name || "Contact", callType === "video" ? "Video calling..." : "Audio calling...");

    try {
        const stream = await prepareLocalMedia(callType);
        activeCall.peer = createPeer();
        attachLocalTracksToPeer(stream);
        ensureMicTrackSending();

        const offer = await activeCall.peer.createOffer();
        await activeCall.peer.setLocalDescription(offer);

        const data = await callSignal("start", {
            conversation_id: conversation_id,
            call_type: callType,
            offer: JSON.stringify(offer)
        });

        if(data.status !== "success"){
            throw new Error(data.message || "Call could not be started");
        }

        activeCall.id = Number(data.call_id);
        activeCall.status = "ringing";
        flushPendingIce();
        setCallStatus(selectedContact.name || "Contact", "Ringing...");
    } catch (error) {
        alert(isMediaAccessError(error) ? mediaPermissionMessage(error, "Call") : (error.message || "Call could not be started"));
        resetActiveCall();
    }
}

async function acceptIncomingCall(){
    if(activeCall.status === "upgrade-pending"){
        acceptVideoUpgrade();
        return;
    }

    if(!activeCall.id || !activeCall.incomingOffer){
        return;
    }

    unlockCallAudio();

    try {
        activeCall.role = "receiver";
        activeCall.status = "answering";
        activeCall.peer = createPeer();
        const stream = await prepareLocalMedia(activeCall.type);
        attachLocalTracksToPeer(stream);
        ensureMicTrackSending();

        await activeCall.peer.setRemoteDescription(new RTCSessionDescription(activeCall.incomingOffer));
        await flushQueuedRemoteIce();
        const answer = await activeCall.peer.createAnswer();
        await activeCall.peer.setLocalDescription(answer);

        const data = await callSignal("answer", {
            call_id: activeCall.id,
            answer: JSON.stringify(answer)
        });

        if(data.status !== "success"){
            throw new Error(data.message || "Call could not be answered");
        }

        activeCall.status = "active";
        activeCall.answered = true;
        ensureMicTrackSending();
        callModal().classList.remove("incoming");
        document.getElementById("call-camera").setAttribute("title", activeCall.type === "video" ? "Toggle camera" : "Convert to video call");
        document.getElementById("call-camera").setAttribute("aria-label", activeCall.type === "video" ? "Toggle camera" : "Convert to video call");
        setCallStatus(document.getElementById("call-title").textContent, "Connected");
    } catch (error) {
        alert(isMediaAccessError(error) ? mediaPermissionMessage(error, "Call") : (error.message || "Call could not be answered"));
        endCall();
    }
}

async function acceptVideoUpgrade(){
    if(!activeCall.id || !activeCall.peer || !activeCall.upgradeOffer){
        return;
    }

    try {
        await addLocalVideoTrack();
        await activeCall.peer.setRemoteDescription(new RTCSessionDescription(activeCall.upgradeOffer));
        await flushQueuedRemoteIce();
        const answer = await activeCall.peer.createAnswer();
        await activeCall.peer.setLocalDescription(answer);

        const data = await callSignal("upgrade_answer", {
            call_id: activeCall.id,
            answer: JSON.stringify(answer)
        });

        if(data.status !== "success"){
            throw new Error(data.message || "Video upgrade could not be accepted");
        }

        switchCallToVideo("Video call connected");
    } catch (error) {
        alert(isMediaAccessError(error) ? mediaPermissionMessage(error, "Camera") : (error.message || "Video upgrade could not be accepted"));
        activeCall.status = "active";
        callModal().classList.remove("incoming");
        setCallStatus(document.getElementById("call-title").textContent, "Audio call connected");
    }
}

async function applyRemoteAnswer(call){
    if(activeCall.answered || !call.answer || !activeCall.peer){
        return;
    }

    await activeCall.peer.setRemoteDescription(new RTCSessionDescription(JSON.parse(call.answer)));
    await flushQueuedRemoteIce();
    activeCall.answered = true;
    activeCall.status = "active";
    ensureMicTrackSending();
    setCallStatus(document.getElementById("call-title").textContent, "Connected");
}

async function processVideoUpgrade(call){
    if(!activeCall.peer || activeCall.type === "video"){
        return;
    }

    if(
        call.upgrade_status === "pending"
        && Number(call.upgrade_requested_by) !== currentUserId
        && call.upgrade_offer
        && !activeCall.upgradePrompted
    ){
        activeCall.upgradeOffer = JSON.parse(call.upgrade_offer);
        activeCall.upgradePrompted = true;
        activeCall.status = "upgrade-pending";
        callModal().classList.add("incoming");
        setCallStatus(document.getElementById("call-title").textContent, "Wants to switch to video");
        return;
    }

    if(
        call.upgrade_status === "accepted"
        && Number(call.upgrade_requested_by) === currentUserId
        && call.upgrade_answer
        && activeCall.upgradeRequested
    ){
        await activeCall.peer.setRemoteDescription(new RTCSessionDescription(JSON.parse(call.upgrade_answer)));
        await flushQueuedRemoteIce();
        switchCallToVideo("Video call connected");
    }
}

async function addRemoteIce(candidates){
    if(!candidates || !candidates.length){
        return;
    }

    if(!activeCall.peer || !activeCall.peer.remoteDescription){
        activeCall.queuedRemoteIce.push(...candidates.filter(Boolean));
        return;
    }

    for(const candidate of candidates){
        if(candidate){
            try {
                await activeCall.peer.addIceCandidate(new RTCIceCandidate(candidate));
            } catch (error) {}
        }
    }
}

async function flushQueuedRemoteIce(){
    if(!activeCall.peer || !activeCall.peer.remoteDescription || !activeCall.queuedRemoteIce.length){
        return;
    }

    const candidates = activeCall.queuedRemoteIce.splice(0);
    await addRemoteIce(candidates);
}

function showIncomingCall(call){
    if(activeCall.id){
        return;
    }

    resetActiveCall(false);
    activeCall.id = Number(call.id);
    activeCall.type = call.call_type === "video" ? "video" : "audio";
    activeCall.role = "receiver";
    activeCall.status = "incoming";
    activeCall.incomingOffer = JSON.parse(call.offer);
    openCallModal("incoming", activeCall.type, call.caller_name || "Incoming call", activeCall.type === "video" ? "Incoming video call" : "Incoming audio call");
}

async function pollCallSignal(){
    if(activeCall.id){
        if(activeCall.polling){
            return;
        }

        activeCall.polling = true;

        try {
            const data = await callSignal("poll", { call_id: activeCall.id });

            if(data.status === "success" && data.call){
                if(["ended", "declined"].includes(data.call.call_status)){
                    setCallStatus(document.getElementById("call-title").textContent, data.call.call_status === "declined" ? "Call declined" : "Call ended");
                    setTimeout(() => resetActiveCall(), 700);
                    return;
                }

                if(activeCall.role === "caller"){
                    await applyRemoteAnswer(data.call);
                }

                await processVideoUpgrade(data.call);
                await addRemoteIce(data.ice || []);
            }
        } catch (error) {
        } finally {
            activeCall.polling = false;
        }

        return;
    }

    try {
        const data = await callSignal("poll");

        if(data.status === "success" && data.incoming){
            showIncomingCall(data.incoming);
        }
    } catch (error) {}
}

function endCall(){
    const callId = activeCall.id;

    if(callId){
        callSignal("end", { call_id: callId }).catch(() => {});
    }

    resetActiveCall();
}

function toggleCallMic(){
    if(!activeCall.localStream){
        return;
    }

    const audioTrack = activeCall.localStream.getAudioTracks()[0];

    if(audioTrack){
        audioTrack.enabled = !audioTrack.enabled;
        document.getElementById("call-mic").classList.toggle("is-off", !audioTrack.enabled);
        document.getElementById("call-mic").setAttribute("title", audioTrack.enabled ? "Mute microphone" : "Unmute microphone");
        document.getElementById("call-mic").setAttribute("aria-label", audioTrack.enabled ? "Mute microphone" : "Unmute microphone");

        if(audioTrack.enabled){
            ensureMicTrackSending();
        }
    }
}

function toggleCallCamera(){
    if(!activeCall.localStream){
        return;
    }

    if(activeCall.type !== "video"){
        requestVideoUpgrade();
        return;
    }

    const videoTrack = activeCall.localStream.getVideoTracks()[0];

    if(videoTrack){
        videoTrack.enabled = !videoTrack.enabled;
        document.getElementById("call-camera").classList.toggle("is-off", !videoTrack.enabled);
    }
}

function syncSidebarToggleLabel(){
    const isCollapsed = pageShell.classList.contains("sidebar-collapsed");
    sidebarToggle.setAttribute("aria-label", isCollapsed ? "Open sidebar" : "Close sidebar");
    sidebarToggle.setAttribute("title", isCollapsed ? "Open sidebar" : "Close sidebar");
    const text = sidebarToggle.querySelector("span");

    if(text){
        text.textContent = isCollapsed ? "Open sidebar" : "Collapse sidebar";
    }
}

if(localStorage.getItem("chatwebSidebarCollapsed") !== "0"){
    pageShell.classList.add("sidebar-collapsed");
}

syncSidebarToggleLabel();

sidebarToggle.addEventListener("click", function(){
    const isCollapsed = pageShell.classList.toggle("sidebar-collapsed");
    localStorage.setItem("chatwebSidebarCollapsed", isCollapsed ? "1" : "0");
    syncSidebarToggleLabel();
});

document.querySelectorAll(".app-nav a[href*='chat.php']").forEach(function(link){
    link.addEventListener("click", function(){
        localStorage.setItem("chatwebSidebarCollapsed", "1");
        pageShell.classList.add("sidebar-collapsed");
        syncSidebarToggleLabel();
    });
});

function escapeHtml(value){
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function voiceWaveBars(seedValue){
    const heights = [10, 18, 13, 24, 16, 28, 12, 20, 30, 15, 23, 11, 26, 17, 21, 14, 29, 12, 19, 25, 13, 22, 16, 27, 11, 20, 14, 24];
    const seed = Number(seedValue) || String(seedValue || "").length;

    return heights.map(function(height, index){
        const adjusted = heights[(index + seed) % heights.length];
        return `<span class="voice-wave-bar" style="--bar-height:${adjusted}px"></span>`;
    }).join("");
}

function buildAttachmentHtml(msg){
    const attachment = msg.attachment ? "../" + msg.attachment : "";
    const fileName = escapeHtml(msg.message || "Attachment");
    const safeAttachment = escapeHtml(attachment);

    if(!attachment){
        return "";
    }

    if(Number(msg.is_view_once) === 1){
        const type = String(msg.message_type || "media").toLowerCase();
        const label = type === "audio" ? "Voice note" : (type === "video" ? "Video" : (type === "image" ? "Photo" : "Media"));
        const icon = type === "audio" ? "fa-microphone" : (type === "video" ? "fa-video" : (type === "image" ? "fa-image" : "fa-photo-film"));

        return `
            <div class="view-once-placeholder">
                <span class="view-once-icon"><i class="fa-solid ${icon}"></i></span>
                <div>
                    <strong>${label}</strong>
                    <span>View once media</span>
                </div>
            </div>
        `;
    }

    if(isImageAttachment(msg)){
        return `
            <div class="image-attachment">
                <button class="image-view-button" type="button" data-open-image="${safeAttachment}" data-image-name="${fileName}" title="Open image" aria-label="Open image">
                    <img class="message-media" src="${safeAttachment}" alt="${fileName}" loading="lazy">
                </button>
                <a class="image-download-button" href="${safeAttachment}" download="${fileName}" title="Download image" aria-label="Download image">
                    <i class="fa-solid fa-download"></i>
                </a>
            </div>
        `;
    }

    if(msg.message_type === "video"){
        return `<video class="message-video" src="${safeAttachment}" controls></video>`;
    }

    if(msg.message_type === "audio"){
        const waveBars = voiceWaveBars(msg.id);

        return `
            <div class="voice-player" data-voice-player="${escapeHtml(msg.id)}">
                <button class="voice-play" type="button" data-audio-toggle="${escapeHtml(msg.id)}" title="Play voice note" aria-label="Play voice note">
                    <i class="fa-solid fa-play"></i>
                </button>
                <div class="voice-waveform" data-audio-wave="${escapeHtml(msg.id)}" role="slider" aria-label="Voice note progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" tabindex="0">
                    ${waveBars}
                </div>
                <span class="voice-time" data-audio-time="${escapeHtml(msg.id)}">00:00</span>
                <button class="voice-speed" type="button" data-audio-speed="${escapeHtml(msg.id)}" title="Playback speed" aria-label="Playback speed">1x</button>
                <audio class="message-audio" src="${safeAttachment}" preload="metadata" data-audio-id="${escapeHtml(msg.id)}"></audio>
            </div>
        `;
    }

    return `
        <a class="file-attachment" href="${safeAttachment}" target="_blank" rel="noopener">
            <i class="fa-solid fa-file-arrow-down"></i>
            <span>${fileName}</span>
        </a>
    `;
}

function isImageAttachment(msg){
    const type = String(msg.message_type || "").toLowerCase();
    const attachment = String(msg.attachment || msg.message || "").toLowerCase().split("?")[0];

    return type === "image" || /\.(jpe?g|png|gif|webp|bmp|avif|svg)$/i.test(attachment);
}

function contactAvatarHtml(name, image){
    if(image){
        return `<img src="${escapeHtml(image)}" alt="${escapeHtml(name || "User")}">`;
    }

    return escapeHtml(String(name || "?").trim().charAt(0).toUpperCase() || "?");
}

function openImageLightbox(src, name){
    const lightbox = document.getElementById("image-lightbox");
    const image = document.getElementById("image-lightbox-img");
    const download = document.getElementById("image-lightbox-download");

    image.src = src;
    image.alt = name || "Image";
    download.href = src;
    download.setAttribute("download", name || "image");
    lightbox.classList.add("open");
    lightbox.setAttribute("aria-hidden", "false");
}

function closeImageLightbox(){
    const lightbox = document.getElementById("image-lightbox");
    const image = document.getElementById("image-lightbox-img");

    lightbox.classList.remove("open");
    lightbox.setAttribute("aria-hidden", "true");
    image.src = "";
}

function headerPresenceText(status, presence){
    return status === "online" ? "Online" : (presence || "");
}

function savedAcceptFor(action){
    const types = {
        image: "image/*,.gif",
        video: "video/mp4,video/webm,video/quicktime",
        audio: "audio/*,.mp3,.wav,.ogg",
        file: ".pdf,.doc,.docx,.txt,.zip,.rar,.xls,.xlsx,.ppt,.pptx"
    };

    return types[action] || "image/*,.gif,video/mp4,video/webm,video/quicktime,audio/*,.pdf,.doc,.docx,.txt,.zip,.rar,.xls,.xlsx,.ppt,.pptx";
}

function activateSavedFilter(filter){
    savedFilter = filter || "all";
    document.querySelectorAll(".saved-filter").forEach(function(item){
        item.classList.toggle("active", item.dataset.savedFilter === savedFilter);
    });
    lastMessagesSignature = "";
    loadMessages();
}

function handleSavedAction(action){
    if(!isPersonalWorkspace){
        return;
    }

    const messageBox = document.getElementById("message");
    const fileInput = document.getElementById("file-input");

    if(action === "notes"){
        activateSavedFilter("notes");
        messageBox.placeholder = "Write a note, checklist, idea, reminder or code snippet...";
        messageBox.focus();
        return;
    }

    if(action === "links"){
        activateSavedFilter("links");
        messageBox.placeholder = "Paste a personal link or bookmark...";
        messageBox.focus();
        return;
    }

    if(action === "favorites" || action === "pinned"){
        activateSavedFilter(action);
        return;
    }

    if(["image", "video", "audio", "file"].includes(action)){
        activateSavedFilter(action);
        fileInput.accept = savedAcceptFor(action);
        fileInput.click();
    }
}

function savedWorkspaceEmptyHtml(){
    return `
        <div class="empty-state">
            <div class="empty-state-box welcome-state saved-workspace">
                <div class="welcome-icon"><i class="fa-solid fa-bookmark"></i></div>
                <strong>Saved Messages</strong>
                <p>Keep your notes, files, images and important information here.</p>
                <div class="saved-grid">
                    <button class="saved-chip" type="button" data-saved-action="notes"><i class="fa-solid fa-note-sticky"></i> Notes</button>
                    <button class="saved-chip" type="button" data-saved-action="image"><i class="fa-solid fa-image"></i> Images</button>
                    <button class="saved-chip" type="button" data-saved-action="video"><i class="fa-solid fa-video"></i> Videos</button>
                    <button class="saved-chip" type="button" data-saved-action="file"><i class="fa-solid fa-file-lines"></i> Documents</button>
                    <button class="saved-chip" type="button" data-saved-action="audio"><i class="fa-solid fa-music"></i> Audio</button>
                    <button class="saved-chip" type="button" data-saved-action="favorites"><i class="fa-solid fa-star"></i> Favorites</button>
                    <button class="saved-chip" type="button" data-saved-action="pinned"><i class="fa-solid fa-thumbtack"></i> Pinned reminders</button>
                    <button class="saved-chip" type="button" data-saved-action="links"><i class="fa-solid fa-link"></i> Personal links</button>
                </div>
                <div class="saved-private-note">This area is completely private.</div>
            </div>
        </div>
    `;
}

function renderPersonalWorkspace(){
    document.body.classList.remove("mobile-chat-open");
    document.querySelectorAll(".user.active").forEach(function(item){
        item.classList.remove("active");
    });
    conversation_id = selfConversationId;
    currentMessages = [];
    lastMessagesSignature = "";
    isPersonalWorkspace = true;
    selectedContact = {
        id: selfProfile.id,
        name: selfProfile.name || "Saved Messages",
        presence: "Saved Messages",
        status: selfProfile.status || "",
        image: selfProfile.image || "",
        blocked: false,
        isGroup: false
    };
    document.getElementById("chat-area").classList.add("personal-workspace");
    document.getElementById("saved-toolbar").style.display = "";
    document.getElementById("message-search-input").placeholder = "Search Saved Messages...";
    document.getElementById("message").placeholder = "Save a note, file, link or reminder...";
    document.getElementById("chat-user").textContent = selfProfile.name || "Saved Messages";
    document.getElementById("chat-presence").innerHTML = `@${escapeHtml(selfProfile.username || "saved")} &bull; Saved Messages`;
    document.getElementById("chat-presence").classList.remove("online");
    document.getElementById("typing-status").textContent = "";
    document.getElementById("chat-header-avatar").innerHTML = contactAvatarHtml(selfProfile.name, selfProfile.image);
    document.getElementById("messages").innerHTML = savedWorkspaceEmptyHtml();
    updateContactUI();
    forceScrollToBottom = true;
    loadMessages();
}

function updateContactUI(){
    document.getElementById("contact-name").textContent = selectedContact.name || "Contact";
    const contactPresence = document.getElementById("contact-presence");
    contactPresence.textContent = selectedContact.presence || "";
    contactPresence.classList.toggle("online", selectedContact.status === "online");
    document.getElementById("contact-status").textContent = selectedContact.status || "-";
    document.getElementById("contact-avatar").innerHTML =
        contactAvatarHtml(selectedContact.name, selectedContact.image);
    document.getElementById("chat-header-avatar").innerHTML =
        contactAvatarHtml(selectedContact.name, selectedContact.image);

    document.querySelectorAll(".call-action").forEach(function(button){
        button.style.display = selectedContact.isGroup ? "none" : "";
    });

    const blockLabel = selectedContact.blocked ? "Unblock User" : "Block User";
    const contactBlock = document.querySelector("[data-contact-action='block'] span");
    const menuBlock = document.querySelector("[data-more-action='block'] span");
    const contactBlockRow = document.querySelector("[data-contact-action='block']");
    const menuBlockButton = document.querySelector("[data-more-action='block']");

    if(contactBlock){
        contactBlock.innerHTML = `<i class="fa-solid fa-ban"></i> ${blockLabel}`;
    }

    if(menuBlock){
        menuBlock.textContent = blockLabel;
    }

    if(contactBlockRow){
        contactBlockRow.style.display = selectedContact.isGroup ? "none" : "";
    }

    if(menuBlockButton){
        menuBlockButton.style.display = selectedContact.isGroup ? "none" : "";
    }
}

function applyPresenceToUser(userId, status, presence){
    const userElement = Array.from(document.querySelectorAll(".user")).find(function(item){
        return Number(item.dataset.userId) === Number(userId);
    });

    if(userElement){
        userElement.dataset.userStatus = status || "offline";
        userElement.dataset.userPresence = presence || "offline";

        const avatarElement = userElement.querySelector(".avatar");

        if(avatarElement){
            const existingDot = avatarElement.querySelector(".avatar-status-dot");

            if(status === "online" && !existingDot){
                avatarElement.insertAdjacentHTML("beforeend", `<span class="avatar-status-dot"></span>`);
            }

            if(status !== "online" && existingDot){
                existingDot.remove();
            }
        }

        const statusElement = userElement.querySelector(".user-status");

        if(statusElement){
            statusElement.textContent = status === "online" ? "" : (presence || "offline");
        }
    }

    if(Number(selectedContact.id) === Number(userId)){
        selectedContact.status = status || "offline";
        selectedContact.presence = presence || "offline";
        updateContactUI();

        const presenceElement = document.getElementById("chat-presence");
        presenceElement.textContent = headerPresenceText(selectedContact.status, selectedContact.presence);
        presenceElement.classList.toggle("online", selectedContact.status === "online");
    }
}

function refreshPresence(){
    fetch("../ajax/presence.php", {
        method: "POST"
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== "success"){
            return;
        }

        (data.users || []).forEach(function(user){
            applyPresenceToUser(user.id, user.status, user.presence);
        });
    })
    .catch(() => {});
}

function insertAtCursor(input, text){
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const before = input.value.substring(0, start);
    const after = input.value.substring(end);

    input.value = before + text + after;
    input.focus();
    input.selectionStart = input.selectionEnd = start + text.length;
}

function formatMessageTime(value){
    if(!value){
        return "";
    }

    const normalized = String(value).replace(" ", "T");
    const date = new Date(normalized);

    if(Number.isNaN(date.getTime())){
        return String(value).slice(11, 16);
    }

    return date.toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit"
    });
}

function buildMessageMeta(msg, isMine){
    const time = formatMessageTime(msg.created_at);

    if(!isMine){
        return `<div class="message-meta"><span>${escapeHtml(time)}</span></div>`;
    }

    const isSeen = Number(msg.is_read) === 1;
    const tickTitle = isSeen
        ? `Seen${msg.seen_at ? " at " + formatMessageTime(msg.seen_at) : ""}`
        : "Delivered";
    const tickClass = isSeen ? "seen" : "delivered";

    return `
        <div class="message-meta">
            <span>${escapeHtml(time)}</span>
            <span class="message-ticks ${tickClass}" title="${escapeHtml(tickTitle)}">
                <i class="fa-solid fa-check"></i>
                <i class="fa-solid fa-check"></i>
            </span>
        </div>
    `;
}

function buildReactionsHtml(reactions){
    if(!reactions || reactions.length === 0){
        return "";
    }

    return `
        <div class="message-reactions">
            ${reactions.map(function(item){
                return `<span class="message-reaction-chip">${escapeHtml(item.reaction)} ${Number(item.total)}</span>`;
            }).join("")}
        </div>
    `;
}

function copyText(value){
    const text = value || "";

    if(navigator.clipboard){
        navigator.clipboard.writeText(text).then(function(){
            alert("Message copied");
        }).catch(function(){
            alert("Copy failed");
        });
        return;
    }

    const textarea = document.createElement("textarea");
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand("copy");
    document.body.removeChild(textarea);
    alert("Message copied");
}

function openForwardModal(messageText){
    forwardMessageText = messageText || "";
    let html = "";

    document.querySelectorAll(".user").forEach(function(user){
        html += `
            <button class="forward-user" type="button" data-forward-user-id="${escapeHtml(user.dataset.userId)}">
                <div class="avatar">${user.querySelector(".avatar").innerHTML}</div>
                <div>
                    <strong>${escapeHtml(user.dataset.userName || "User")}</strong>
                    <div class="user-status">${escapeHtml(user.dataset.userPresence || "")}</div>
                </div>
            </button>
        `;
    });

    document.getElementById("forward-list").innerHTML = html || `<div class="empty-users">No users found.</div>`;
    document.getElementById("forward-modal").classList.add("open");
}

function closeForwardModal(){
    document.getElementById("forward-modal").classList.remove("open");
}

function forwardToUser(userId){
    if(!forwardMessageText || !userId){
        return;
    }

    let startData = new FormData();
    startData.append("user_id", userId);

    fetch("../ajax/start_chat.php", {
        method: "POST",
        body: startData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== "success"){
            alert(data.message || "Chat could not be started");
            return null;
        }

        let sendData = new FormData();
        sendData.append("conversation_id", data.conversation_id);
        sendData.append("message", forwardMessageText);

        return fetch("../ajax/send_message.php", {
            method: "POST",
            body: sendData
        });
    })
    .then(res => res ? res.json() : null)
    .then(data => {
        if(!data){
            return;
        }

        if(data.status === "success"){
            closeForwardModal();
            alert("Message forwarded");
        } else {
            alert(data.message || "Message could not be forwarded");
        }
    })
    .catch(() => alert("Server error while forwarding"));
}

function setReply(messageId, text){
    replyToMessageId = Number(messageId) || 0;
    replyToText = text || "";
    document.getElementById("reply-text").textContent = replyToText || "Message";
    document.getElementById("reply-composer").classList.toggle("show", replyToMessageId > 0);
    document.getElementById("message").focus();
}

function clearReply(){
    replyToMessageId = 0;
    replyToText = "";
    document.getElementById("reply-composer").classList.remove("show");
}

function reactToMessage(messageId, reaction){
    let formData = new FormData();
    formData.append("message_id", messageId);
    formData.append("reaction", reaction);

    fetch("../ajax/react_message.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === "success"){
            loadMessages();
        } else {
            alert(data.message || "Reaction could not be saved");
        }
    })
    .catch(() => alert("Server error while reacting"));
}

function startChat(user_id, name, presence, status, image){

    if(voiceRecorder && voiceRecorder.state === "recording"){
        stopVoiceRecording(false);
    }

    document.body.classList.add("mobile-chat-open");
    isPersonalWorkspace = false;
    document.getElementById("chat-area").classList.remove("personal-workspace");
    document.getElementById("saved-toolbar").style.display = "none";
    document.getElementById("message-search-input").placeholder = "Search messages in this chat...";
    document.getElementById("message").placeholder = "Type a message...";

    document.getElementById("chat-user").textContent =
    name;
    selectedContact = {
        id: Number(user_id) || 0,
        name: name || "Contact",
        presence: presence || "",
        status: status || "",
        image: image || "",
        blocked: false,
        isGroup: false
    };
    updateContactUI();

    const presenceElement = document.getElementById("chat-presence");
    presenceElement.textContent = headerPresenceText(status, presence);
    presenceElement.classList.toggle("online", status === "online");

    let formData = new FormData();

    formData.append("user_id", user_id);

    fetch("../ajax/start_chat.php", {
        method: "POST",
        body: formData
    })

    .then(res => res.json())

    .then(data => {

        if(data.status == "success"){

            conversation_id = data.conversation_id;

            forceScrollToBottom = true;
            lastMessagesSignature = "";
            loadMessages();
            getTypingStatus();

        } else {
            document.getElementById("messages").textContent = data.message || "Chat could not be started";
        }

    })
    .catch(() => {
        document.getElementById("messages").textContent = "Server error while starting chat";
    });

}



function sendMessage(){

    let msg = document.getElementById("message").value;

    if(selectedContact.blocked){
        alert("Unblock this user before sending messages.");
        return;
    }

    if(msg.trim() == ""){
        return;
    }

    if(conversation_id == 0){
        alert("Please select a user first.");
        return;
    }

    let formData = new FormData();

    formData.append("conversation_id", conversation_id);
    formData.append("message", msg);
    formData.append("is_view_once", isViewOnce ? 1 : 0);
    formData.append("reply_to", replyToMessageId);

    fetch("../ajax/send_message.php",{

        method:"POST",
        body:formData

    })

    .then(res=>res.json())

    .then(data=>{

        if(data.status=="success"){

            document.getElementById("message").value="";
            setTypingStatus(false);
            setViewOnce(false);
            clearReply();

            forceScrollToBottom = true;
            loadMessages();

        } else {
            alert(data.message || "Message could not be sent");
        }

    })
    .catch(() => alert("Server error while sending message"));

}

function openGroupConversation(groupElement){
    if(voiceRecorder && voiceRecorder.state === "recording"){
        stopVoiceRecording(false);
    }

    document.body.classList.add("mobile-chat-open");
    isPersonalWorkspace = false;
    document.getElementById("chat-area").classList.remove("personal-workspace");
    document.getElementById("saved-toolbar").style.display = "none";
    document.getElementById("message-search-input").placeholder = "Search messages in this group...";
    document.getElementById("message").placeholder = "Message this group...";

    conversation_id = Number(groupElement.dataset.conversationId || 0);
    selectedContact = {
        id: 0,
        name: groupElement.dataset.userName || "Group Chat",
        presence: groupElement.dataset.userPresence || "Group",
        status: "group",
        image: groupElement.dataset.userImage || "",
        blocked: false,
        isGroup: true
    };

    document.getElementById("chat-user").textContent = selectedContact.name;
    const presenceElement = document.getElementById("chat-presence");
    presenceElement.textContent = selectedContact.presence;
    presenceElement.classList.remove("online");
    document.getElementById("typing-status").textContent = "";

    updateContactUI();
    forceScrollToBottom = true;
    lastMessagesSignature = "";
    loadMessages();
    getTypingStatus();
}

function ensureCanSendVoice(){
    if(conversation_id == 0){
        alert("Please select a user first.");
        return false;
    }

    if(selectedContact.blocked){
        alert("Unblock this user before sending voice notes.");
        return false;
    }

    if(!window.isSecureContext && location.hostname !== "localhost" && location.hostname !== "127.0.0.1" && supportsLiveVoiceRecorder()){
        alert("Voice notes need HTTPS on mobile. Open this site with HTTPS or test on localhost.");
        return false;
    }

    return true;
}

function supportsLiveVoiceRecorder(){
    return Boolean(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
}

function voiceMimeType(){
    const types = [
        "audio/mp4;codecs=mp4a.40.2",
        "audio/mp4",
        "audio/webm;codecs=opus",
        "audio/webm",
        "audio/ogg;codecs=opus",
        "audio/ogg"
    ];

    return types.find(type => MediaRecorder.isTypeSupported(type)) || "";
}

function voiceExtension(mimeType){
    if(mimeType.includes("ogg")){
        return "ogg";
    }

    if(mimeType.includes("mp4")){
        return "m4a";
    }

    return "webm";
}

function formatVoiceTime(seconds){
    const mins = String(Math.floor(seconds / 60)).padStart(2, "0");
    const secs = String(seconds % 60).padStart(2, "0");
    return `${mins}:${secs}`;
}

function formatAudioTime(value){
    const seconds = Math.max(0, Math.floor(Number(value) || 0));
    return formatVoiceTime(seconds);
}

function selectorValue(value){
    const text = String(value);
    return window.CSS && CSS.escape ? CSS.escape(text) : text.replace(/"/g, '\\"');
}

function audioControlsFor(id){
    const safeId = selectorValue(id);

    return {
        audio: document.querySelector(`[data-audio-id="${safeId}"]`),
        button: document.querySelector(`[data-audio-toggle="${safeId}"]`),
        progress: document.querySelector(`[data-audio-wave="${safeId}"]`),
        time: document.querySelector(`[data-audio-time="${safeId}"]`),
        speed: document.querySelector(`[data-audio-speed="${safeId}"]`)
    };
}

function canBrowserPlayAudio(src, mimeType = ""){
    const audio = document.createElement("audio");
    const type = String(mimeType || "").toLowerCase();
    const path = String(src || "").toLowerCase().split("?")[0];

    if(type && audio.canPlayType(type)){
        return audio.canPlayType(type) !== "";
    }

    if(path.endsWith(".webm")){
        return audio.canPlayType('audio/webm; codecs="opus"') !== "" || audio.canPlayType("audio/webm") !== "";
    }

    if(path.endsWith(".ogg") || path.endsWith(".oga")){
        return audio.canPlayType('audio/ogg; codecs="opus"') !== "" || audio.canPlayType("audio/ogg") !== "";
    }

    if(path.endsWith(".m4a") || path.endsWith(".mp4") || path.endsWith(".aac")){
        return audio.canPlayType("audio/mp4") !== "" || audio.canPlayType("audio/aac") !== "";
    }

    if(path.endsWith(".mp3")){
        return audio.canPlayType("audio/mpeg") !== "";
    }

    if(path.endsWith(".wav")){
        return audio.canPlayType("audio/wav") !== "";
    }

    return true;
}

function openUnsupportedVoice(audio){
    const src = audio ? audio.currentSrc || audio.src : "";

    if(src){
        window.open(src, "_blank", "noopener");
    }

    alert("This phone cannot play this old voice-note format. New voice notes will be saved in mobile-friendly audio.");
}

function setVoiceWaveProgress(wave, percent){
    if(!wave){
        return;
    }

    const value = Math.max(0, Math.min(100, Number(percent) || 0));
    const bars = Array.from(wave.querySelectorAll(".voice-wave-bar"));
    const activeCount = Math.round((value / 100) * bars.length);

    bars.forEach(function(bar, index){
        bar.classList.toggle("active", index < activeCount);
    });

    wave.setAttribute("aria-valuenow", String(Math.round(value)));
}

function setAudioButton(button, isPlaying){
    if(!button){
        return;
    }

    button.innerHTML = isPlaying ? `<i class="fa-solid fa-pause"></i>` : `<i class="fa-solid fa-play"></i>`;
    button.setAttribute("title", isPlaying ? "Pause voice note" : "Play voice note");
    button.setAttribute("aria-label", isPlaying ? "Pause voice note" : "Play voice note");
}

function initializeVoicePlayers(){
    document.querySelectorAll(".message-audio[data-audio-id]:not([data-audio-ready])").forEach(function(audio){
        audio.dataset.audioReady = "1";
        audio.playbackRate = 1;

        if(!canBrowserPlayAudio(audio.src, audio.type || "")){
            const controls = audioControlsFor(audio.dataset.audioId);

            if(controls.time){
                controls.time.textContent = "Open";
            }

            if(controls.button){
                controls.button.setAttribute("title", "Open voice note");
                controls.button.setAttribute("aria-label", "Open voice note");
            }
        }

        audio.addEventListener("loadedmetadata", function(){
            const controls = audioControlsFor(audio.dataset.audioId);

            if(controls.time){
                controls.time.textContent = formatAudioTime(audio.duration);
            }
        });

        audio.addEventListener("timeupdate", function(){
            const controls = audioControlsFor(audio.dataset.audioId);
            const duration = Number(audio.duration) || 0;

            if(controls.progress && duration > 0){
                setVoiceWaveProgress(controls.progress, (audio.currentTime / duration) * 100);
            }

            if(controls.time){
                controls.time.textContent = formatAudioTime(audio.currentTime || duration);
            }
        });

        audio.addEventListener("play", function(){
            document.querySelectorAll(".message-audio").forEach(function(otherAudio){
                if(otherAudio !== audio){
                    otherAudio.pause();
                }
            });

            setAudioButton(audioControlsFor(audio.dataset.audioId).button, true);
        });

        audio.addEventListener("pause", function(){
            setAudioButton(audioControlsFor(audio.dataset.audioId).button, false);
        });

        audio.addEventListener("ended", function(){
            const controls = audioControlsFor(audio.dataset.audioId);
            audio.currentTime = 0;

            if(controls.progress){
                setVoiceWaveProgress(controls.progress, 0);
            }

            if(controls.time){
                controls.time.textContent = formatAudioTime(audio.duration);
            }

            setAudioButton(controls.button, false);
        });

        audio.addEventListener("error", function(){
            const controls = audioControlsFor(audio.dataset.audioId);

            if(controls.time){
                controls.time.textContent = "Open";
            }

            setAudioButton(controls.button, false);
        });
    });
}

function cycleAudioSpeed(id){
    const controls = audioControlsFor(id);

    if(!controls.audio || !controls.speed){
        return;
    }

    const speeds = [1, 2, 3];
    const current = Number(controls.audio.playbackRate) || 1;
    const next = speeds[(speeds.indexOf(current) + 1) % speeds.length];

    controls.audio.playbackRate = next;
    controls.speed.textContent = `${next}x`;
    controls.speed.setAttribute("title", `Playback speed ${next}x`);
}

function seekVoiceWave(wave, clientX){
    const id = wave.dataset.audioWave;
    const controls = audioControlsFor(id);

    if(!controls.audio || !Number(controls.audio.duration)){
        return;
    }

    const rect = wave.getBoundingClientRect();
    const percent = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
    controls.audio.currentTime = percent * controls.audio.duration;
    setVoiceWaveProgress(wave, percent * 100);
}

function setVoiceRecordingUI(isRecording){
    document.getElementById("voice-recorder").classList.toggle("show", isRecording);
    document.getElementById("mic-btn").classList.toggle("recording", isRecording);
}

function updateVoiceTimer(){
    const elapsed = Math.floor((Date.now() - voiceStartedAt) / 1000);
    document.getElementById("voice-timer").textContent = formatVoiceTime(elapsed);
}

function cleanupVoiceStream(){
    if(voiceStream){
        voiceStream.getTracks().forEach(track => track.stop());
    }

    voiceStream = null;
}

function stopVoiceRecording(send = true){
    if(!voiceRecorder || voiceRecorder.state === "inactive"){
        return;
    }

    voiceCancelled = !send;
    voiceRecorder.stop();
}

function startVoiceRecording(){
    if(!ensureCanSendVoice()){
        return;
    }

    if(!supportsLiveVoiceRecorder()){
        openFilePicker(document.getElementById("media-input"), "audio/*", "microphone");
        return;
    }

    navigator.mediaDevices.getUserMedia({
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true
        }
    })
    .then(stream => {
        const mimeType = voiceMimeType();
        voiceStream = stream;
        voiceChunks = [];
        voiceCancelled = false;
        voiceRecorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);

        voiceRecorder.addEventListener("dataavailable", event => {
            if(event.data && event.data.size > 0){
                voiceChunks.push(event.data);
            }
        });

        voiceRecorder.addEventListener("stop", () => {
            clearInterval(voiceTimer);
            voiceTimer = null;
            setVoiceRecordingUI(false);
            cleanupVoiceStream();

            if(voiceCancelled){
                voiceChunks = [];
                return;
            }

            const type = voiceRecorder.mimeType || mimeType || "audio/webm";
            const blob = new Blob(voiceChunks, { type });
            voiceChunks = [];

            if(blob.size < 800){
                alert("Voice note is too short.");
                return;
            }

            const file = new File([blob], `voice_note_${Date.now()}.${voiceExtension(type)}`, { type });
            uploadFile(file, "Sending voice note...");
        });

        voiceStartedAt = Date.now();
        document.getElementById("voice-timer").textContent = "00:00";
        voiceTimer = setInterval(updateVoiceTimer, 500);
        setVoiceRecordingUI(true);
        voiceRecorder.start();
    })
    .catch(error => alert(mediaPermissionMessage(error, "Voice note")));
}

function toggleVoiceRecording(){
    if(voiceRecorder && voiceRecorder.state === "recording"){
        stopVoiceRecording(true);
        return;
    }

    startVoiceRecording();
}




function loadMessages(){

    if(conversation_id == 0){
        return;
    }

    const messagesElement = document.getElementById("messages");
    const bottomOffset = messagesElement.scrollHeight - messagesElement.scrollTop - messagesElement.clientHeight;
    const shouldStickToBottom = forceScrollToBottom || bottomOffset < 80;
    const previousScrollHeight = messagesElement.scrollHeight;

    let formData = new FormData();

    formData.append("conversation_id", conversation_id);

    fetch("../ajax/load_messages.php",{

        method:"POST",
        body:formData

    })

    .then(res=>res.json())

    .then(data=>{

        if(data.status=="success"){

            currentMessages = data.messages || [];
            let messagesToRender = currentMessages;
            const searchTerm = document.getElementById("message-search-input").value.trim().toLowerCase();
            const messagesSignature = JSON.stringify(currentMessages.map(function(msg){
                return [
                    msg.id,
                    msg.message,
                    msg.message_type,
                    msg.attachment,
                    msg.is_read,
                    msg.is_deleted,
                    msg.reply_to,
                    msg.reply_message,
                    msg.reactions,
                    msg.is_starred,
                    msg.is_pinned
                ];
            })) + "|" + searchTerm + "|" + savedFilter + "|" + (isPersonalWorkspace ? "self" : "chat");

            if(messagesSignature === lastMessagesSignature && !forceScrollToBottom){
                return;
            }

            let html = "";
            let lastSeenSentMessageId = 0;

            if(searchTerm !== ""){
                messagesToRender = currentMessages.filter(function(msg){
                    return String(msg.message || "").toLowerCase().includes(searchTerm)
                        || String(msg.full_name || "").toLowerCase().includes(searchTerm);
                });
            }

            if(isPersonalWorkspace && savedFilter !== "all"){
                messagesToRender = messagesToRender.filter(function(msg, index){
                    const type = String(msg.message_type || "text");
                    const text = String(msg.message || "");

                    if(savedFilter === "notes"){
                        return type === "text";
                    }

                    if(["image", "video", "audio", "file"].includes(savedFilter)){
                        return type === savedFilter;
                    }

                    if(savedFilter === "links"){
                        return /(https?:\/\/|www\.)/i.test(text);
                    }

                    if(savedFilter === "recent"){
                        return index >= Math.max(0, messagesToRender.length - 20);
                    }

                    if(savedFilter === "favorites"){
                        return Number(msg.is_starred || 0) === 1;
                    }

                    if(savedFilter === "pinned"){
                        return Number(msg.is_pinned || 0) === 1;
                    }

                    return true;
                });
            }

            currentMessages.forEach(function(msg){
                if(Number(msg.sender_id) === currentUserId && Number(msg.is_read) === 1){
                    lastSeenSentMessageId = Number(msg.id);
                }
            });

            if(isPersonalWorkspace){
                messagesToRender = messagesToRender.slice().sort(function(a, b){
                    return Number(b.is_pinned || 0) - Number(a.is_pinned || 0);
                });
            }

            messagesToRender.forEach(function(msg){
                const isMine = Number(msg.sender_id) === currentUserId;
                const rowClass = isMine ? "sent" : "received";
                const sender = isMine ? "You" : msg.full_name;
                const attachmentHtml = buildAttachmentHtml(msg);
                const viewOnceHtml = Number(msg.is_view_once) === 1
                    ? `<span class="view-once-label">View once</span>`
                    : "";
                const textHtml = msg.message_type === "text"
                    ? `<p class="message-text">${escapeHtml(msg.message)}</p>`
                    : (msg.message && msg.message_type !== "audio" && Number(msg.is_view_once) !== 1 ? `<p class="message-text">${escapeHtml(msg.message)}</p>` : "");
                const replyHtml = Number(msg.reply_to) > 0
                    ? `
                        <div class="reply-preview">
                            <strong>${escapeHtml(msg.reply_sender || "Reply")}</strong>
                            <span>${escapeHtml(msg.reply_message || "Original message")}</span>
                        </div>
                    `
                    : "";
                const deleteForEveryone = isMine
                    ? `<button class="danger" type="button" data-delete-mode="everyone" data-message-id="${msg.id}">Delete for everyone</button>`
                    : "";
                const pinLabel = Number(msg.is_pinned || 0) === 1 ? "Unpin" : "Pin";
                const starLabel = Number(msg.is_starred || 0) === 1 ? "Unstar" : "Star";
                const downloadHtml = msg.attachment && Number(msg.is_view_once || 0) !== 1
                    ? `<button type="button" data-download-url="../${escapeHtml(msg.attachment)}" data-download-name="${escapeHtml(msg.message || "attachment")}">Download</button>`
                    : "";
                const metaHtml = buildMessageMeta(msg, isMine);
                const reactionsHtml = buildReactionsHtml(msg.reactions);
                const hasReactionClass = msg.reactions && msg.reactions.length > 0 ? "has-reaction" : "";
                const seenOutsideHtml = isMine && Number(msg.id) === lastSeenSentMessageId
                    ? `<span class="seen-text">Seen</span>`
                    : "";

                html += `
                <div class="message-stack ${rowClass} ${hasReactionClass}">
                    <div class="message-row ${rowClass}">
                        <div class="message-with-reactions">
                        <div class="message-bubble">
                            <div class="reaction-bar" id="reaction-bar-${msg.id}">
                                <button type="button" data-react="👍" data-message-id="${msg.id}">👍</button>
                                <button type="button" data-react="❤️" data-message-id="${msg.id}">❤️</button>
                                <button type="button" data-react="😂" data-message-id="${msg.id}">😂</button>
                                <button type="button" data-react="😮" data-message-id="${msg.id}">😮</button>
                                <button type="button" data-react="😢" data-message-id="${msg.id}">😢</button>
                                <button type="button" data-react="🙏" data-message-id="${msg.id}">🙏</button>
                            </div>
                            <button class="message-menu-btn" type="button" data-menu-message="${msg.id}" title="Message options">
                                <i class="fa-solid fa-ellipsis-vertical"></i>
                            </button>
                            <div class="message-menu" id="message-menu-${msg.id}">
                                <button type="button" data-reply-message="${msg.id}">Reply</button>
                                <button type="button" data-reaction-menu="${msg.id}">React</button>
                                <button type="button" data-star-message="${msg.id}">${starLabel}</button>
                                <button type="button" data-pin-message="${msg.id}">${pinLabel}</button>
                                <button type="button" data-info-message="${msg.id}">Info</button>
                                <button type="button" data-copy-message="${msg.id}">Copy</button>
                                <button type="button" data-forward-message="${msg.id}">Forward</button>
                                ${downloadHtml}
                                <button type="button" data-delete-mode="me" data-message-id="${msg.id}">Delete for me</button>
                                ${deleteForEveryone}
                            </div>
                            <span class="message-sender">${escapeHtml(sender)}</span>
                            ${replyHtml}
                            ${viewOnceHtml}
                            ${textHtml}
                            ${attachmentHtml}
                            ${metaHtml}
                        </div>
                        ${reactionsHtml}
                        </div>
                    </div>
                    ${seenOutsideHtml}
                </div>
                `;

            });

            messagesElement.innerHTML = html || (isPersonalWorkspace ? savedWorkspaceEmptyHtml() : `
                <div class="empty-state">
                    <div class="empty-state-box">
                        <strong>No messages yet</strong>
                        <span>Send the first message.</span>
                    </div>
                </div>
            `);
            initializeVoicePlayers();

            lastMessagesSignature = messagesSignature;

            if(shouldStickToBottom){
                messagesElement.scrollTop = messagesElement.scrollHeight;
            } else {
                messagesElement.scrollTop += messagesElement.scrollHeight - previousScrollHeight;
            }

            forceScrollToBottom = false;

            // ✅ Messages Seen
            let seenData = new FormData();

            seenData.append("conversation_id", conversation_id);

            fetch("../ajax/seen.php",{

                method:"POST",
                body:seenData

            });

        }

    })
    .catch(() => {
        document.getElementById("messages").textContent = "Messages could not be loaded";
    });

}

function uploadFile(file, statusText = "Uploading..."){

    if(conversation_id == 0){
        alert("Please select a user first.");
        return;
    }

    if(selectedContact.blocked){
        alert("Unblock this user before sending files.");
        return;
    }

    if(!file){
        return;
    }

    const uploadStatus = document.getElementById("upload-status");
    const formData = new FormData();

    formData.append("conversation_id", conversation_id);
    formData.append("file", file);
    formData.append("is_view_once", isViewOnce ? 1 : 0);

    uploadStatus.classList.add("show");
    uploadStatus.textContent = statusText;

    fetch("../ajax/upload.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        uploadStatus.classList.remove("show");

        if(data.status === "success"){
            setViewOnce(false);
            forceScrollToBottom = true;
            loadMessages();
        } else {
            alert(data.message || "File could not be uploaded");
        }
    })
    .catch(() => {
        uploadStatus.classList.remove("show");
        alert("Server error while uploading file");
    });

}

function clearPendingMedia(){
    if(pendingMediaUrl){
        URL.revokeObjectURL(pendingMediaUrl);
    }

    pendingMediaFile = null;
    pendingMediaUrl = "";
}

function mediaPreviewHtml(file, url){
    const type = String(file.type || "");
    const name = escapeHtml(file.name || "Media");

    if(type.startsWith("image/")){
        return `<img src="${escapeHtml(url)}" alt="${name}">`;
    }

    if(type.startsWith("video/")){
        return `<video src="${escapeHtml(url)}" controls></video>`;
    }

    if(type.startsWith("audio/")){
        return `<audio src="${escapeHtml(url)}" controls></audio>`;
    }

    return `<div class="tool-empty">Preview is not available for this file.</div>`;
}

function openMediaPreview(file){
    if(!file){
        return;
    }

    if(conversation_id == 0){
        alert("Please select a user first.");
        return;
    }

    if(selectedContact.blocked){
        alert("Unblock this user before sending media.");
        return;
    }

    clearPendingMedia();
    pendingMediaFile = file;
    pendingMediaUrl = URL.createObjectURL(file);

    openToolModal("Send Media", "", `
        <div class="media-send-preview">
            <div class="media-preview-stage">
                ${mediaPreviewHtml(file, pendingMediaUrl)}
            </div>
            <div class="media-preview-file">
                <i class="fa-solid fa-photo-film"></i>
                <span>${escapeHtml(file.name || "Selected media")}</span>
            </div>
            <div class="media-send-options">
                <button type="button" class="media-once-toggle ${isViewOnce ? "active" : ""}" id="media-once-toggle" aria-pressed="${isViewOnce ? "true" : "false"}">
                    <i class="fa-solid fa-1"></i>
                    <span>View once</span>
                </button>
                <div class="media-send-actions">
                    <button type="button" class="secondary" id="media-cancel">Cancel</button>
                    <button type="button" class="primary" id="media-send">Send</button>
                </div>
            </div>
        </div>
    `, false);
}

function closeAttachMenu(){
    document.getElementById("attach-menu").classList.remove("open");
}

function toggleAttachMenu(){
    document.getElementById("attach-menu").classList.toggle("open");
}

function openFilePicker(input, accept, capture = ""){
    input.accept = accept;

    if(capture){
        input.setAttribute("capture", capture);
    } else {
        input.removeAttribute("capture");
    }

    input.click();
}

function openContactSharePicker(){
    const users = Array.from(document.querySelectorAll(".user:not(.group-chat)"));

    if(!users.length){
        openToolModal("Share Contact", "", `<div class="tool-empty">No contacts available.</div>`, false);
        return;
    }

    const body = `<div class="tool-list">` + users.map(function(user){
        return `
            <button class="tool-list-item" type="button" data-share-contact="${escapeHtml(user.dataset.userId)}" data-contact-name="${escapeHtml(user.dataset.userName || "User")}">
                <strong>${escapeHtml(user.dataset.userName || "User")}</strong>
                <small>${escapeHtml(user.dataset.userPresence || "")}</small>
            </button>
        `;
    }).join("") + `</div>`;

    openToolModal("Share Contact", "", body, false);
}

function openCreateGroupModal(){
    const users = Array.from(document.querySelectorAll(".user:not(.group-chat)"));

    if(!users.length){
        openToolModal("New Group", "", `<div class="tool-empty">No users available for a group.</div>`, false);
        return;
    }

    const memberRows = users.map(function(user){
        const name = user.dataset.userName || "User";
        const presence = user.dataset.userPresence || "";
        const image = user.dataset.userImage || "";

        return `
            <label class="group-member-row" data-tool-name="${escapeHtml(name.toLowerCase())}">
                <input type="checkbox" name="group_members" value="${escapeHtml(user.dataset.userId || "")}">
                <span class="group-member-avatar">${contactAvatarHtml(name, image)}</span>
                <span class="group-member-copy">
                    <strong>${escapeHtml(name)}</strong>
                    <small>${escapeHtml(presence)}</small>
                </span>
            </label>
        `;
    }).join("");

    openToolModal("New Group", "", `
        <form class="group-create-form" id="group-create-form">
            <label class="group-name-field">
                <span>Group name</span>
                <input type="text" id="group-name-input" maxlength="120" autocomplete="off" required>
            </label>
            <div class="group-member-list">
                ${memberRows}
            </div>
            <div class="group-create-actions">
                <button type="button" class="secondary" id="group-cancel">Cancel</button>
                <button type="submit" class="primary" id="group-submit">Create Group</button>
            </div>
        </form>
    `);

    document.getElementById("tool-search").placeholder = "Search users...";
    document.getElementById("group-name-input").focus();
}

function submitCreateGroup(event){
    event.preventDefault();

    const nameInput = document.getElementById("group-name-input");
    const submitButton = document.getElementById("group-submit");
    const groupName = nameInput ? nameInput.value.trim() : "";
    const members = Array.from(document.querySelectorAll("input[name='group_members']:checked"))
        .map(function(input){
            return input.value;
        })
        .filter(Boolean);

    if(groupName === ""){
        alert("Please enter a group name.");
        return;
    }

    if(members.length < 2){
        alert("Please select at least 2 members.");
        return;
    }

    const formData = new FormData();
    formData.append("title", groupName);
    members.forEach(function(memberId){
        formData.append("members[]", memberId);
    });

    if(submitButton){
        submitButton.disabled = true;
        submitButton.textContent = "Creating...";
    }

    fetch("../ajax/create_group.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== "success"){
            alert(data.message || "Group could not be created");
            return;
        }

        window.location.href = "chat.php?conversation_id=" + encodeURIComponent(data.conversation_id) + "&user=" + encodeURIComponent(data.title || groupName) + "&group=1";
    })
    .catch(() => alert("Server error while creating group"))
    .finally(() => {
        if(submitButton){
            submitButton.disabled = false;
            submitButton.textContent = "Create Group";
        }
    });
}

function handleAttachmentOption(option){
    closeAttachMenu();

    const mediaInput = document.getElementById("media-input");
    const fileInput = document.getElementById("file-input");

    if(option === "gallery"){
        openFilePicker(mediaInput, "image/*,video/*");
        return;
    }

    if(option === "document"){
        openFilePicker(fileInput, ".pdf,.doc,.docx,.txt,.zip,.rar,.xls,.xlsx,.ppt,.pptx");
        return;
    }

    if(option === "audio"){
        openFilePicker(mediaInput, "audio/*,.mp3,.wav,.ogg,.m4a");
        return;
    }

    if(option === "video"){
        openFilePicker(mediaInput, "video/*,video/mp4,video/webm,video/quicktime");
        return;
    }

    if(option === "contact"){
        openContactSharePicker();
    }
}

function setViewOnce(value){
    isViewOnce = Boolean(value);
    const mediaOnce = document.getElementById("media-once-toggle");

    if(mediaOnce){
        mediaOnce.classList.toggle("active", isViewOnce);
        mediaOnce.setAttribute("aria-pressed", isViewOnce ? "true" : "false");
    }
}

function deleteMessage(messageId, mode){

    const label = mode === "everyone" ? "Delete this message for everyone?" : "Delete this message for you?";

    if(!confirm(label)){
        return;
    }

    const formData = new FormData();
    formData.append("message_id", messageId);
    formData.append("mode", mode);

    fetch("../ajax/delete_message.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === "success"){
            loadMessages();
        } else {
            alert(data.message || "Message could not be deleted");
        }
    })
    .catch(() => alert("Server error while deleting message"));

}

function setTypingStatus(isTyping){

    if(conversation_id == 0){
        return;
    }

    let formData = new FormData();
    formData.append("conversation_id", conversation_id);
    formData.append("is_typing", isTyping ? 1 : 0);

    fetch("../ajax/typing.php", {
        method: "POST",
        body: formData
    });

}

function getTypingStatus(){

    if(conversation_id == 0){
        return;
    }

    let formData = new FormData();
    formData.append("conversation_id", conversation_id);

    fetch("../ajax/get_typing.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById("typing-status").textContent =
            data.typing ? "typing..." : "";
    })
    .catch(() => {
        document.getElementById("typing-status").textContent = "";
    });

}

document.getElementById("message").addEventListener("input", function(){

    setTypingStatus(true);

    clearTimeout(typingTimer);
    typingTimer = setTimeout(function(){
        setTypingStatus(false);
    }, 1200);

});

document.getElementById("message").addEventListener("keydown", function(event){

    if(event.key === "Enter"){
        sendMessage();
    }

});

const emojiPanel = document.getElementById("emoji-panel");
const messageInput = document.getElementById("message");

emojiPanel.innerHTML = emojis.map(function(emoji){
    return `<button class="emoji-item" type="button">${emoji}</button>`;
}).join("");

document.getElementById("emoji-btn").addEventListener("click", function(){
    emojiPanel.classList.toggle("open");
});

emojiPanel.addEventListener("click", function(event){
    if(event.target.classList.contains("emoji-item")){
        insertAtCursor(messageInput, event.target.textContent);
        setTypingStatus(true);
    }
});

document.addEventListener("click", function(event){
    if(!emojiPanel.contains(event.target) && !document.getElementById("emoji-btn").contains(event.target)){
        emojiPanel.classList.remove("open");
    }

    if(!event.target.closest("#attach-menu") && !event.target.closest("#attach-btn")){
        closeAttachMenu();
    }
});

document.getElementById("attach-btn").addEventListener("click", function(){
    toggleAttachMenu();
});

document.getElementById("media-btn").addEventListener("click", function(){
    closeAttachMenu();
    openFilePicker(document.getElementById("media-input"), "image/*,video/*");
});

document.getElementById("attach-menu").addEventListener("click", function(event){
    const option = event.target.closest("[data-attach-option]");

    if(!option){
        return;
    }

    handleAttachmentOption(option.dataset.attachOption);
});

document.getElementById("mic-btn").addEventListener("click", toggleVoiceRecording);

document.getElementById("voice-cancel").addEventListener("click", function(){
    stopVoiceRecording(false);
});

document.getElementById("voice-send").addEventListener("click", function(){
    stopVoiceRecording(true);
});

document.getElementById("search-toggle").addEventListener("click", function(){
    document.getElementById("message-search").classList.toggle("open");
    document.getElementById("message-search-input").focus();
});

document.getElementById("mobile-chat-back").addEventListener("click", function(){
    closeMoreMenu();
    closeContactPanel();
    renderPersonalWorkspace();
});

document.getElementById("message-search-input").addEventListener("input", function(){
    if(conversation_id !== 0){
        loadMessages();
    }
});

const userSearchInput = document.getElementById("user-search-input");
const userSearchEmpty = document.getElementById("user-search-empty");

function filterUsers(){
    const query = userSearchInput.value.trim().toLowerCase();
    let visibleCount = 0;

    document.querySelectorAll(".user").forEach(function(userElement){
        const searchable = [
            userElement.dataset.userName || "",
            userElement.dataset.userStatus || "",
            userElement.dataset.userPresence || "",
            userElement.dataset.userId || "",
            userElement.querySelector(".user-name")?.textContent || "",
            userElement.querySelector(".user-status")?.textContent || ""
        ].join(" ").toLowerCase();
        const isVisible = query === "" || searchable.includes(query);

        userElement.style.display = isVisible ? "" : "none";

        if(isVisible){
            visibleCount++;
        }
    });

    if(userSearchEmpty){
        userSearchEmpty.style.display = visibleCount === 0 ? "" : "none";
    }
}

userSearchInput.addEventListener("input", filterUsers);

document.querySelector(".user-list").addEventListener("click", function(event){
    const userElement = event.target.closest(".user");

    if(!userElement || !this.contains(userElement)){
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    document.querySelectorAll(".user").forEach(function(item){
        item.classList.remove("active");
    });

    userElement.classList.add("active");

    if(userElement.dataset.isGroup === "1"){
        openGroupConversation(userElement);
        return;
    }

    startChat(
        userElement.dataset.userId,
        userElement.dataset.userName,
        userElement.dataset.userPresence,
        userElement.dataset.userStatus,
        userElement.dataset.userImage
    );
});

document.getElementById("new-group-btn").addEventListener("click", openCreateGroupModal);

function openContactPanel(){
    document.getElementById("contact-panel").classList.add("open");
}

function closeContactPanel(){
    document.getElementById("contact-panel").classList.remove("open");
}

function closeMoreMenu(){
    document.getElementById("chat-more-menu").classList.remove("open");
    document.getElementById("contact-toggle").setAttribute("aria-expanded", "false");
}

function ensureConversationSelected(){
    if(conversation_id === 0){
        alert("Please select a chat first.");
        return false;
    }

    return true;
}

function openMessageSearch(){
    if(!ensureConversationSelected()){
        return;
    }

    document.getElementById("message-search").classList.add("open");
    document.getElementById("message-search-input").focus();
}

function clearCurrentConversation(label){
    if(!ensureConversationSelected()){
        return;
    }

    if(!confirm(label)){
        return;
    }

    const formData = new FormData();
    formData.append("conversation_id", conversation_id);

    fetch("../ajax/clear_conversation.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== "success"){
            alert(data.message || "Chat could not be cleared");
            return;
        }

        currentMessages = [];
        lastMessagesSignature = "";
        document.getElementById("messages").innerHTML = isPersonalWorkspace ? savedWorkspaceEmptyHtml() : `
            <div class="empty-state">
                <div class="empty-state-box">
                    <strong>No messages yet</strong>
                    <span>This chat is clear for you.</span>
                </div>
            </div>
        `;
        closeContactPanel();
    })
    .catch(() => alert("Server error while clearing chat"));
}

function exportCurrentConversation(){
    if(!ensureConversationSelected()){
        return;
    }

    const lines = currentMessages.map(function(msg){
        const sender = Number(msg.sender_id) === currentUserId ? "You" : (msg.full_name || "User");
        const time = formatMessageTime(msg.created_at);
        return `[${time}] ${sender}: ${msg.message || msg.message_type || "Attachment"}`;
    });

    const blob = new Blob([lines.join("\n") || "No messages"], { type: "text/plain" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = (selectedContact.name || "chat").replace(/[^a-z0-9]+/gi, "_").toLowerCase() + "_chat.txt";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function triggerFileDownload(url, name){
    if(!url){
        return;
    }

    const link = document.createElement("a");
    link.href = url;
    link.download = name || "attachment";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function openToolModal(title, tabsHtml, bodyHtml, showSearch = true){
    document.getElementById("tool-title").textContent = title;
    document.getElementById("tool-tabs").innerHTML = tabsHtml || "";
    document.getElementById("tool-body").innerHTML = bodyHtml || "";
    document.getElementById("tool-search-wrap").style.display = showSearch ? "block" : "none";
    document.getElementById("tool-search").value = "";
    document.getElementById("tool-modal").classList.add("open");
}

function closeToolModal(){
    document.getElementById("tool-modal").classList.remove("open");
    clearPendingMedia();
}

const wallpaperPresets = {
    solid: [
        ["#F5F7FB", "Cloud"],
        ["#FFFFFF", "White"],
        ["#ECFDF5", "Mint"],
        ["#FFF7ED", "Peach"],
        ["#F8FAFC", "Slate"]
    ],
    gradient: [
        ["linear-gradient(135deg,#F5F7FB,#EAF1FF)", "Blue mist"],
        ["linear-gradient(135deg,#FFF7ED,#ECFDF5)", "Warm mint"],
        ["linear-gradient(135deg,#FDF2F8,#EFF6FF)", "Rose sky"],
        ["linear-gradient(135deg,#F8FAFC,#E0F2FE)", "Soft cyan"]
    ],
    pattern: [
        ["dots-blue", "Blue dots", "radial-gradient(circle at 12px 12px, rgba(56,112,255,.08) 1.5px, transparent 2px)"],
        ["grid-soft", "Soft grid", "linear-gradient(rgba(56,112,255,.07) 1px, transparent 1px), linear-gradient(90deg, rgba(56,112,255,.07) 1px, transparent 1px)"],
        ["diagonal", "Diagonal", "repeating-linear-gradient(135deg, rgba(56,112,255,.07) 0 2px, transparent 2px 18px)"],
        ["bubbles", "Bubbles", "radial-gradient(circle at 16px 16px, rgba(20,184,166,.10) 2px, transparent 3px)"]
    ]
};

function normalizeWallpaperType(type){
    const normalized = String(type || "Pattern").toLowerCase();

    if(normalized.includes("gradient")){
        return "Gradient";
    }

    if(normalized.includes("image")){
        return "Custom image";
    }

    if(normalized.includes("pattern")){
        return "Pattern";
    }

    return "Solid";
}

function findPatternCss(value){
    const found = wallpaperPresets.pattern.find(item => item[0] === value);
    return found ? found[2] : wallpaperPresets.pattern[0][2];
}

function safeCssUrl(value){
    const url = String(value || "").trim();

    if(!url || /[<>"'()\\]/.test(url)){
        return "";
    }

    if(/^https?:\/\//i.test(url) || /^\.{0,2}\//.test(url)){
        return `url("${url}")`;
    }

    if(/^(uploads|assets)\//i.test(url)){
        return `url("../${url}")`;
    }

    return "";
}

function applyChatWallpaper(setting = chatWallpaper){
    const messages = document.getElementById("messages");
    const type = normalizeWallpaperType(setting.type);
    const value = String(setting.value || "").trim();

    messages.classList.remove("wallpaper-solid", "wallpaper-gradient", "wallpaper-pattern", "wallpaper-image");

    if(type === "Gradient"){
        messages.style.setProperty("--chat-wallpaper-gradient", value || wallpaperPresets.gradient[0][0]);
        messages.classList.add("wallpaper-gradient");
        return;
    }

    if(type === "Custom image"){
        const image = safeCssUrl(value);

        if(image){
            messages.style.setProperty("--chat-wallpaper-image", image);
            messages.classList.add("wallpaper-image");
            return;
        }

        messages.style.setProperty("--chat-wallpaper-solid", "#F5F7FB");
        messages.classList.add("wallpaper-solid");
        return;
    }

    if(type === "Pattern"){
        messages.style.setProperty("--chat-wallpaper-pattern", findPatternCss(value));
        messages.classList.add("wallpaper-pattern");
        return;
    }

    messages.style.setProperty("--chat-wallpaper-solid", value || "#F5F7FB");
    messages.classList.add("wallpaper-solid");
}

function wallpaperButton(type, value, label, preview){
    const isActive = normalizeWallpaperType(chatWallpaper.type) === type && String(chatWallpaper.value || "") === String(value || "");

    return `
        <button type="button" class="wallpaper-choice ${isActive ? "active" : ""}" data-wallpaper-type="${escapeHtml(type)}" data-wallpaper-value="${escapeHtml(value)}">
            <div class="wallpaper-preview" style="background:${escapeHtml(preview)}"></div>
            <span>${escapeHtml(label)}</span>
        </button>
    `;
}

function renderWallpaperPicker(){
    const solid = wallpaperPresets.solid.map(item => wallpaperButton("Solid", item[0], item[1], item[0])).join("");
    const gradients = wallpaperPresets.gradient.map(item => wallpaperButton("Gradient", item[0], item[1], item[0])).join("");
    const patterns = wallpaperPresets.pattern.map(item => wallpaperButton("Pattern", item[0], item[1], `linear-gradient(rgba(245,247,251,.86),rgba(245,247,251,.86)),${item[2]}`)).join("");
    const currentType = normalizeWallpaperType(chatWallpaper.type);
    const currentValue = String(chatWallpaper.value || "");
    const colorValue = /^#[0-9a-f]{6}$/i.test(currentValue) ? currentValue : "#F5F7FB";
    const imageValue = currentType === "Custom image" ? currentValue : "";

    openToolModal("Chat Wallpaper", "", `
        <div class="wallpaper-panel">
            <div class="wallpaper-section">
                <p class="wallpaper-section-title">Solid colors</p>
                <div class="wallpaper-options">${solid}</div>
            </div>
            <div class="wallpaper-section">
                <p class="wallpaper-section-title">Gradients</p>
                <div class="wallpaper-options">${gradients}</div>
            </div>
            <div class="wallpaper-section">
                <p class="wallpaper-section-title">Patterns</p>
                <div class="wallpaper-options">${patterns}</div>
            </div>
            <div class="wallpaper-section">
                <p class="wallpaper-section-title">Custom</p>
                <div class="wallpaper-custom">
                    <input type="color" id="wallpaper-color" value="${escapeHtml(colorValue)}" title="Pick solid color">
                    <input type="text" id="wallpaper-image-url" value="${escapeHtml(imageValue)}" placeholder="../uploads/image.jpg or https://...">
                    <button type="button" id="wallpaper-file-pick">Use image</button>
                    <button type="button" id="wallpaper-image-apply">Use URL</button>
                </div>
                <input type="file" id="wallpaper-file-input" accept="image/*" hidden>
                <div class="wallpaper-file-name" id="wallpaper-file-name"></div>
            </div>
            <div class="wallpaper-actions">
                <button type="button" class="secondary" id="wallpaper-reset">Reset</button>
                <button type="button" class="primary" id="wallpaper-save">Save Wallpaper</button>
            </div>
        </div>
    `, false);
}

function selectWallpaper(type, value){
    chatWallpaper = {
        type: normalizeWallpaperType(type),
        value: String(value || "")
    };
    applyChatWallpaper(chatWallpaper);

    document.querySelectorAll(".wallpaper-choice").forEach(button => {
        const active = normalizeWallpaperType(button.dataset.wallpaperType) === chatWallpaper.type
            && String(button.dataset.wallpaperValue || "") === chatWallpaper.value;
        button.classList.toggle("active", active);
    });
}

function saveWallpaper(){
    const formData = new FormData();
    formData.append("action", "save");
    formData.append("section", "appearance");
    formData.append("wallpaper_type", chatWallpaper.type);
    formData.append("wallpaper_value", chatWallpaper.value);

    fetch("../ajax/settings.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== "success"){
            alert(data.message || "Wallpaper could not be saved.");
            return;
        }

        closeToolModal();
    })
    .catch(() => alert("Server error while saving wallpaper."));
}

function uploadWallpaperImage(file){
    if(!file){
        return;
    }

    if(!file.type.startsWith("image/")){
        alert("Please select an image file.");
        return;
    }

    const maxSize = 8 * 1024 * 1024;

    if(file.size > maxSize){
        alert("Wallpaper image must be 8 MB or smaller.");
        return;
    }

    const formData = new FormData();
    formData.append("wallpaper_image", file);

    const chooseButton = document.getElementById("wallpaper-file-pick");
    const saveButton = document.getElementById("wallpaper-save");
    const fileName = document.getElementById("wallpaper-file-name");

    if(chooseButton){
        chooseButton.disabled = true;
        chooseButton.textContent = "Uploading...";
    }

    if(saveButton){
        saveButton.disabled = true;
    }

    if(fileName){
        fileName.textContent = file.name;
    }

    fetch("../ajax/wallpaper_upload.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== "success"){
            alert(data.message || "Wallpaper image could not be uploaded.");
            return;
        }

        chatWallpaper = {
            type: data.wallpaper_type || "Custom image",
            value: data.wallpaper_value || ""
        };

        applyChatWallpaper(chatWallpaper);

        const imageInput = document.getElementById("wallpaper-image-url");

        if(imageInput){
            imageInput.value = chatWallpaper.value;
        }

        if(fileName){
            fileName.textContent = "Image selected and saved.";
        }
    })
    .catch(() => alert("Server error while uploading wallpaper."))
    .finally(() => {
        if(chooseButton){
            chooseButton.disabled = false;
            chooseButton.textContent = "Use image";
        }

        if(saveButton){
            saveButton.disabled = false;
        }
    });
}

function fileSize(bytes){
    bytes = Number(bytes || 0);
    if(bytes < 1024) return bytes + " B";
    if(bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB";
    return (bytes / (1024 * 1024)).toFixed(1) + " MB";
}

function renderMediaItems(items){
    if(!items.length){
        return `<div class="tool-empty">No shared files found.</div>`;
    }

    return `<div class="tool-grid">` + items.map(function(item){
        const url = "../" + item.attachment;
        const name = item.message || item.attachment.split("/").pop();
        let thumb = `<i class="fa-solid fa-file"></i>`;

        if(item.message_type === "image"){
            thumb = `<img src="${escapeHtml(url)}" alt="${escapeHtml(name)}">`;
        } else if(item.message_type === "video"){
            thumb = `<video src="${escapeHtml(url)}"></video>`;
        } else if(item.message_type === "audio"){
            thumb = `<i class="fa-solid fa-music"></i>`;
        }

        return `
            <div class="tool-item" data-tool-name="${escapeHtml(String(name).toLowerCase())}">
                <div class="tool-thumb">${thumb}</div>
                <div class="tool-info">
                    <strong>${escapeHtml(name)}</strong>
                    <span>${escapeHtml(item.sender_name || "User")} · ${escapeHtml(formatMessageTime(item.created_at))}</span>
                    <span>${fileSize(item.file_size)}</span>
                    <div class="tool-actions">
                        <a href="${escapeHtml(url)}" target="_blank" rel="noopener">Open</a>
                        <a href="${escapeHtml(url)}" download>Download</a>
                    </div>
                </div>
            </div>
        `;
    }).join("") + `</div>`;
}

function loadSharedMedia(type = "all"){
    if(!ensureConversationSelected()){
        return;
    }

    const tabs = [
        ["all", "All"],
        ["image", "Images"],
        ["video", "Videos"],
        ["file", "Documents"],
        ["audio", "Audio"]
    ].map(function(tab){
        return `<button type="button" class="${tab[0] === type ? "active" : ""}" data-media-tab="${tab[0]}">${tab[1]}</button>`;
    }).join("");

    openToolModal("Shared Media", tabs, `<div class="tool-empty">Loading shared media...</div>`);

    const formData = new FormData();
    formData.append("conversation_id", conversation_id);
    formData.append("type", type);

    fetch("../ajax/shared_media.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById("tool-body").innerHTML =
            data.status === "success" ? renderMediaItems(data.items || []) : `<div class="tool-empty">${escapeHtml(data.message || "Media could not be loaded")}</div>`;
    })
    .catch(() => {
        document.getElementById("tool-body").innerHTML = `<div class="tool-empty">Server error while loading media.</div>`;
    });
}

function openConversationSearch(){
    if(!ensureConversationSelected()){
        return;
    }

    const body = `<div class="tool-list" id="conversation-search-results"></div>`;
    openToolModal("Search Conversation", "", body, true);
    document.getElementById("tool-search").placeholder = "Search messages or date...";
    renderConversationSearch("");
    document.getElementById("tool-search").focus();
}

function renderConversationSearch(term){
    const container = document.getElementById("conversation-search-results");
    const q = String(term || "").toLowerCase();
    const matches = currentMessages.filter(function(msg){
        return !q || String(msg.message || "").toLowerCase().includes(q) || String(msg.created_at || "").toLowerCase().includes(q);
    });

    container.innerHTML = matches.length ? matches.map(function(msg){
        const text = escapeHtml(msg.message || msg.message_type || "Attachment");
        const highlighted = q ? text.replace(new RegExp("(" + q.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + ")", "ig"), "<mark>$1</mark>") : text;

        return `
            <div class="tool-list-item" data-jump-message="${msg.id}">
                <strong>${escapeHtml(msg.full_name || "User")}</strong>
                <div>${highlighted}</div>
                <small>${escapeHtml(formatMessageTime(msg.created_at))}</small>
            </div>
        `;
    }).join("") : `<div class="tool-empty">No matching messages.</div>`;
}

function jumpToMessage(messageId){
    closeToolModal();
    const menuButton = document.querySelector(`[data-menu-message="${CSS.escape(String(messageId))}"]`);
    const bubble = menuButton ? menuButton.closest(".message-stack") : null;

    if(bubble){
        bubble.scrollIntoView({ behavior: "smooth", block: "center" });
        bubble.classList.add("message-highlight");
        setTimeout(() => bubble.classList.remove("message-highlight"), 1600);
    }
}

function loadStarredMessages(){
    if(!ensureConversationSelected()){
        return;
    }

    openToolModal("Starred Messages", "", `<div class="tool-empty">Loading starred messages...</div>`);

    const formData = new FormData();
    formData.append("conversation_id", conversation_id);

    fetch("../ajax/get_starred_messages.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        const items = data.items || [];
        document.getElementById("tool-body").innerHTML = items.length ? `<div class="tool-list">` + items.map(function(item){
            return `
                <div class="tool-list-item" data-jump-message="${item.id}">
                    <strong>${escapeHtml(item.sender_name || "User")}</strong>
                    <div>${escapeHtml(item.message || item.message_type || "Attachment")}</div>
                    <small>${escapeHtml(formatMessageTime(item.created_at))}</small>
                    <div class="tool-actions">
                        <button type="button" data-unstar-message="${item.id}">Unstar</button>
                    </div>
                </div>
            `;
        }).join("") + `</div>` : `<div class="tool-empty">No starred messages yet.</div>`;
    });
}

function toggleStarMessage(messageId, action = "toggle"){
    const formData = new FormData();
    formData.append("message_id", messageId);
    formData.append("action", action);

    fetch("../ajax/star_message.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== "success"){
            alert(data.message || "Star action failed");
            return;
        }

        if(document.getElementById("tool-modal").classList.contains("open")){
            loadStarredMessages();
        }
    })
    .catch(() => alert("Server error while starring message"));
}

function togglePinMessage(messageId, action = "toggle"){
    const formData = new FormData();
    formData.append("message_id", messageId);
    formData.append("action", action);

    fetch("../ajax/pin_message.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== "success"){
            alert(data.message || "Pin action failed");
            return;
        }

        lastMessagesSignature = "";
        loadMessages();
    })
    .catch(() => alert("Server error while pinning message"));
}

function showMessageInfo(messageId){
    const msg = currentMessages.find(item => Number(item.id) === Number(messageId));

    if(!msg){
        alert("Message not found");
        return;
    }

    openToolModal("Message Information", "", `
        <div class="tool-list">
            <div class="tool-list-item"><strong>Message ID</strong><div>${escapeHtml(msg.id)}</div></div>
            <div class="tool-list-item"><strong>Sent</strong><div>${escapeHtml(formatMessageTime(msg.created_at))}</div></div>
            <div class="tool-list-item"><strong>Seen</strong><div>${escapeHtml(msg.seen_at ? formatMessageTime(msg.seen_at) : "Not seen yet")}</div></div>
            <div class="tool-list-item"><strong>Type</strong><div>${escapeHtml(msg.message_type || "text")}</div></div>
            <div class="tool-list-item"><strong>Edited</strong><div>${Number(msg.is_edited || 0) === 1 ? "Yes" : "No"}</div></div>
        </div>
    `, false);
}

function toggleBlockUser(){
    if(!selectedContact.id){
        alert("Please select a user first.");
        return;
    }

    if(isPersonalWorkspace){
        alert("Saved Messages is your private workspace and cannot be blocked.");
        return;
    }

    const label = selectedContact.blocked ? "Unblock " : "Block ";

    if(!confirm(label + selectedContact.name + "?")){
        return;
    }

    const formData = new FormData();
    formData.append("contact_id", selectedContact.id);
    formData.append("action", "toggle");

    fetch("../ajax/block_user.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === "success"){
            selectedContact.blocked = Boolean(data.blocked);
            updateContactUI();
        }

        alert(data.message || "Block setting updated");
    })
    .catch(() => alert("Server error while updating block setting"));
}

function deleteConversationPermanently(){
    if(!ensureConversationSelected()){
        return;
    }

    if(isPersonalWorkspace){
        clearCurrentConversation("Clear Saved Messages?");
        return;
    }

    if(!confirm("Delete this conversation permanently?")){
        return;
    }

    const formData = new FormData();
    formData.append("conversation_id", conversation_id);

    fetch("../ajax/delete_conversation.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== "success"){
            alert(data.message || "Conversation could not be deleted");
            return;
        }

        closeContactPanel();
        document.querySelectorAll(".user.active").forEach(item => item.remove());
        renderPersonalWorkspace();
    })
    .catch(() => alert("Server error while deleting conversation"));
}

function handleChatToolAction(action){
    if(action === "profile"){
        openContactPanel();
        return;
    }

    if(action === "search"){
        openConversationSearch();
        return;
    }

    if(action === "clear"){
        clearCurrentConversation("Clear this chat for you?");
        return;
    }

    if(action === "delete"){
        deleteConversationPermanently();
        return;
    }

    if(action === "starred"){
        loadStarredMessages();
        return;
    }

    if(action === "media"){
        loadSharedMedia("all");
        return;
    }

    if(action === "wallpaper"){
        renderWallpaperPicker();
        return;
    }

    if(action === "pinned" && isPersonalWorkspace){
        activateSavedFilter("pinned");
        return;
    }

    if(action === "export"){
        exportCurrentConversation();
        return;
    }

    const labels = {
        mute: "Mute notifications option saved for this session.",
        pinned: "Open Saved Messages to use pinned notes.",
        block: "",
        report: "Report user action needs moderation support."
    };

    if(action === "block"){
        toggleBlockUser();
        return;
    }

    alert(labels[action] || "This option will be available soon.");
}

document.querySelectorAll(".call-action").forEach(function(button){
    button.addEventListener("click", function(){
        startCall(this.dataset.callType || "audio");
    });
});

document.getElementById("call-accept").addEventListener("click", acceptIncomingCall);
document.getElementById("call-end").addEventListener("click", endCall);
document.getElementById("call-close").addEventListener("click", endCall);
document.getElementById("call-mic").addEventListener("click", toggleCallMic);
document.getElementById("call-speaker").addEventListener("click", toggleCallSpeaker);
document.getElementById("call-camera").addEventListener("click", toggleCallCamera);
document.getElementById("call-modal").addEventListener("click", playRemoteAudio);
document.getElementById("call-sound-btn").addEventListener("click", function(event){
    event.stopPropagation();
    unlockCallAudio();
});

document.getElementById("image-lightbox-close").addEventListener("click", closeImageLightbox);
document.getElementById("image-lightbox").addEventListener("click", function(event){
    if(event.target.id === "image-lightbox"){
        closeImageLightbox();
    }
});

document.getElementById("contact-toggle").addEventListener("click", function(event){
    event.stopPropagation();
    const menu = document.getElementById("chat-more-menu");
    const isOpen = menu.classList.toggle("open");
    this.setAttribute("aria-expanded", isOpen ? "true" : "false");
});

document.getElementById("chat-more-menu").addEventListener("click", function(event){
    const menuButton = event.target.closest("button");
    const action = event.target.closest("[data-more-action]");

    if(!menuButton){
        event.stopPropagation();
        return;
    }

    event.stopPropagation();
    closeMoreMenu();

    if(!action){
        return;
    }

    handleChatToolAction(action.dataset.moreAction);
});

document.querySelector(".chat-title").addEventListener("click", openContactPanel);

document.getElementById("contact-close").addEventListener("click", closeContactPanel);

document.getElementById("contact-panel").addEventListener("click", function(event){
    const row = event.target.closest("[data-contact-action]");

    if(!row){
        return;
    }

    handleChatToolAction(row.dataset.contactAction);
});

document.getElementById("tool-close").addEventListener("click", closeToolModal);

document.getElementById("tool-modal").addEventListener("click", function(event){
    if(event.target.id === "tool-modal"){
        closeToolModal();
        return;
    }

    const mediaTab = event.target.closest("[data-media-tab]");
    const jump = event.target.closest("[data-jump-message]");
    const unstar = event.target.closest("[data-unstar-message]");
    const wallpaperChoice = event.target.closest("[data-wallpaper-type]");
    const shareContact = event.target.closest("[data-share-contact]");

    if(event.target.id === "group-cancel"){
        closeToolModal();
        return;
    }

    if(event.target.id === "media-once-toggle" || event.target.closest("#media-once-toggle")){
        setViewOnce(!isViewOnce);
        return;
    }

    if(shareContact){
        const name = shareContact.dataset.contactName || "Contact";
        closeToolModal();
        document.getElementById("message").value = `Contact: ${name}`;
        sendMessage();
        return;
    }

    if(event.target.id === "media-cancel"){
        closeToolModal();
        return;
    }

    if(event.target.id === "media-send"){
        if(!pendingMediaFile){
            alert("Please select media first.");
            return;
        }

        const fileToSend = pendingMediaFile;
        closeToolModal();
        uploadFile(fileToSend);
        return;
    }

    if(mediaTab){
        loadSharedMedia(mediaTab.dataset.mediaTab);
        return;
    }

    if(wallpaperChoice){
        selectWallpaper(wallpaperChoice.dataset.wallpaperType, wallpaperChoice.dataset.wallpaperValue);
        return;
    }

    if(event.target.id === "wallpaper-file-pick"){
        const fileInput = document.getElementById("wallpaper-file-input");

        if(fileInput){
            fileInput.click();
        }

        return;
    }

    if(event.target.id === "wallpaper-image-apply"){
        const input = document.getElementById("wallpaper-image-url");
        const value = input ? input.value.trim() : "";

        if(!safeCssUrl(value)){
            alert("Please enter a valid image URL or path.");
            return;
        }

        selectWallpaper("Custom image", value);
        return;
    }

    if(event.target.id === "wallpaper-reset"){
        selectWallpaper("Pattern", "dots-blue");
        return;
    }

    if(event.target.id === "wallpaper-save"){
        saveWallpaper();
        return;
    }

    if(unstar){
        event.stopPropagation();
        toggleStarMessage(unstar.dataset.unstarMessage, "remove");
        return;
    }

    if(jump){
        jumpToMessage(jump.dataset.jumpMessage);
    }
});

document.getElementById("tool-modal").addEventListener("input", function(event){
    if(event.target.id === "wallpaper-color"){
        selectWallpaper("Solid", event.target.value);
    }
});

document.getElementById("tool-modal").addEventListener("submit", function(event){
    if(event.target.id === "group-create-form"){
        submitCreateGroup(event);
    }
});

document.getElementById("tool-modal").addEventListener("change", function(event){
    if(event.target.id === "wallpaper-file-input"){
        uploadWallpaperImage(event.target.files[0]);
        event.target.value = "";
    }
});

document.getElementById("tool-search").addEventListener("input", function(){
    if(document.getElementById("tool-title").textContent === "Search Conversation"){
        renderConversationSearch(this.value);
        return;
    }

    const q = this.value.trim().toLowerCase();
    document.querySelectorAll("#tool-body [data-tool-name]").forEach(function(item){
        item.style.display = item.dataset.toolName.includes(q) ? "" : "none";
    });
});

document.addEventListener("keydown", function(event){
    if(event.key === "Escape"){
        closeContactPanel();
        closeMoreMenu();
        closeToolModal();
        closeImageLightbox();
    }
});

document.getElementById("cancel-reply").addEventListener("click", clearReply);

document.getElementById("saved-toolbar").addEventListener("click", function(event){
    const button = event.target.closest("[data-saved-filter]");

    if(!button){
        return;
    }

    savedFilter = button.dataset.savedFilter;
    activateSavedFilter(savedFilter);
});

document.getElementById("messages").addEventListener("click", function(event){
    const action = event.target.closest("[data-saved-action]");

    if(action){
        handleSavedAction(action.dataset.savedAction);
    }
});

document.getElementById("file-input").addEventListener("change", function(){
    openMediaPreview(this.files[0]);
    this.value = "";
});

document.getElementById("media-input").addEventListener("change", function(){
    openMediaPreview(this.files[0]);
    this.value = "";
});

document.getElementById("messages").addEventListener("click", function(event){
    const imageButton = event.target.closest("[data-open-image]");
    const audioToggle = event.target.closest("[data-audio-toggle]");
    const audioSpeed = event.target.closest("[data-audio-speed]");
    const menuButton = event.target.closest("[data-menu-message]");
    const deleteButton = event.target.closest("[data-delete-mode]");
    const replyButton = event.target.closest("[data-reply-message]");
    const reactionMenuButton = event.target.closest("[data-reaction-menu]");
    const reactionButton = event.target.closest("[data-react]");
    const starButton = event.target.closest("[data-star-message]");
    const pinButton = event.target.closest("[data-pin-message]");
    const infoButton = event.target.closest("[data-info-message]");
    const downloadButton = event.target.closest("[data-download-url]");

    if(imageButton){
        openImageLightbox(imageButton.dataset.openImage, imageButton.dataset.imageName || "Image");
        return;
    }

    if(downloadButton){
        triggerFileDownload(downloadButton.dataset.downloadUrl, downloadButton.dataset.downloadName || "attachment");
        document.querySelectorAll(".message-menu").forEach(function(item){
            item.classList.remove("open");
        });
        return;
    }

    if(audioToggle){
        const controls = audioControlsFor(audioToggle.dataset.audioToggle);

        if(!controls.audio){
            return;
        }

        if(!canBrowserPlayAudio(controls.audio.src, controls.audio.type || "")){
            openUnsupportedVoice(controls.audio);
            return;
        }

        if(controls.audio.paused){
            controls.audio.play().catch(() => openUnsupportedVoice(controls.audio));
        } else {
            controls.audio.pause();
        }

        return;
    }

    if(audioSpeed){
        cycleAudioSpeed(audioSpeed.dataset.audioSpeed);
        return;
    }

    if(menuButton){
        const menu = document.getElementById("message-menu-" + menuButton.dataset.menuMessage);

        document.querySelectorAll(".message-menu").forEach(function(item){
            if(item !== menu){
                item.classList.remove("open");
            }
        });

        menu.classList.toggle("open");
        return;
    }

    if(replyButton){
        const replyMessage = currentMessages.find(function(item){
            return Number(item.id) === Number(replyButton.dataset.replyMessage);
        });
        setReply(replyButton.dataset.replyMessage, replyMessage ? (replyMessage.message || replyMessage.message_type) : "Message");
        document.querySelectorAll(".message-menu").forEach(function(item){
            item.classList.remove("open");
        });
        return;
    }

    if(reactionMenuButton){
        const bar = document.getElementById("reaction-bar-" + reactionMenuButton.dataset.reactionMenu);
        document.querySelectorAll(".reaction-bar").forEach(function(item){
            if(item !== bar){
                item.classList.remove("open");
            }
        });
        bar.classList.toggle("open");
        document.querySelectorAll(".message-menu").forEach(function(item){
            item.classList.remove("open");
        });
        return;
    }

    if(reactionButton){
        reactToMessage(reactionButton.dataset.messageId, reactionButton.dataset.react);
        return;
    }

    if(starButton){
        toggleStarMessage(starButton.dataset.starMessage);
        document.querySelectorAll(".message-menu").forEach(function(item){
            item.classList.remove("open");
        });
        return;
    }

    if(pinButton){
        togglePinMessage(pinButton.dataset.pinMessage);
        document.querySelectorAll(".message-menu").forEach(function(item){
            item.classList.remove("open");
        });
        return;
    }

    if(infoButton){
        showMessageInfo(infoButton.dataset.infoMessage);
        document.querySelectorAll(".message-menu").forEach(function(item){
            item.classList.remove("open");
        });
        return;
    }

    if(deleteButton){
        deleteMessage(deleteButton.dataset.messageId, deleteButton.dataset.deleteMode);
    }
});

document.getElementById("messages").addEventListener("pointerdown", function(event){
    const wave = event.target.closest("[data-audio-wave]");

    if(!wave){
        return;
    }

    event.preventDefault();
    wave.setPointerCapture(event.pointerId);
    seekVoiceWave(wave, event.clientX);
});

document.getElementById("messages").addEventListener("pointermove", function(event){
    const wave = event.target.closest("[data-audio-wave]");

    if(!wave || !wave.hasPointerCapture(event.pointerId)){
        return;
    }

    seekVoiceWave(wave, event.clientX);
});

document.getElementById("messages").addEventListener("keydown", function(event){
    const wave = event.target.closest("[data-audio-wave]");

    if(!wave || !["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)){
        return;
    }

    const controls = audioControlsFor(wave.dataset.audioWave);

    if(!controls.audio || !Number(controls.audio.duration)){
        return;
    }

    event.preventDefault();

    if(event.key === "Home"){
        controls.audio.currentTime = 0;
    } else if(event.key === "End"){
        controls.audio.currentTime = controls.audio.duration;
    } else {
        const delta = event.key === "ArrowRight" ? 5 : -5;
        controls.audio.currentTime = Math.max(0, Math.min(controls.audio.duration, controls.audio.currentTime + delta));
    }

    setVoiceWaveProgress(wave, (controls.audio.currentTime / controls.audio.duration) * 100);
});

document.getElementById("messages").addEventListener("contextmenu", function(event){
    const menuButton = event.target.closest("[data-menu-message]");
    const bubble = event.target.closest(".message-bubble");

    if(!bubble){
        return;
    }

    event.preventDefault();

    const button = menuButton || bubble.querySelector("[data-menu-message]");

    if(button){
        button.click();
    }
});

document.addEventListener("click", function(event){
    if(!event.target.closest(".message-bubble")){
        document.querySelectorAll(".message-menu").forEach(function(item){
            item.classList.remove("open");
        });
        document.querySelectorAll(".reaction-bar").forEach(function(item){
            item.classList.remove("open");
        });
    }

    if(!event.target.closest("#chat-more-menu") && !event.target.closest("#contact-toggle")){
        closeMoreMenu();
    }

    if(
        document.getElementById("contact-panel").classList.contains("open")
        && !event.target.closest("#contact-panel")
        && !event.target.closest(".chat-title")
        && !event.target.closest("#chat-more-menu")
        && !event.target.closest("#contact-toggle")
    ){
        closeContactPanel();
    }
});

const initialParams = new URLSearchParams(window.location.search);
const initialConversationId = Number(initialParams.get("conversation_id") || 0);
const initialIsGroup = initialParams.get("group") === "1";

applyChatWallpaper(chatWallpaper);

if(initialConversationId > 0){
    document.body.classList.add("mobile-chat-open");
    isPersonalWorkspace = false;
    document.getElementById("chat-area").classList.remove("personal-workspace");
    document.getElementById("saved-toolbar").style.display = "none";
    document.getElementById("message-search-input").placeholder = initialIsGroup ? "Search messages in this group..." : "Search messages in this chat...";
    document.getElementById("message").placeholder = initialIsGroup ? "Message this group..." : "Type a message...";
    conversation_id = initialConversationId;
    lastMessagesSignature = "";
    document.getElementById("chat-user").textContent = initialParams.get("user") || "Chat";
    selectedContact = {
        id: 0,
        name: initialParams.get("user") || "Chat",
        presence: initialIsGroup ? "Group" : "",
        status: initialIsGroup ? "group" : "",
        image: "",
        blocked: false,
        isGroup: initialIsGroup
    };
    updateContactUI();
    document.getElementById("chat-presence").textContent = initialIsGroup ? "Group" : "";
    forceScrollToBottom = true;
    loadMessages();
    getTypingStatus();
} else {
    renderPersonalWorkspace();
}


refreshPresence();
setInterval(refreshPresence, 15000);

window.addEventListener("pagehide", function(){
    if(voiceRecorder && voiceRecorder.state === "recording"){
        stopVoiceRecording(false);
    }

    if(activeCall.id){
        callSignal("end", { call_id: activeCall.id }).catch(() => {});
        resetActiveCall(false);
    }

    if(navigator.sendBeacon){
        navigator.sendBeacon("../ajax/offline.php");
    }
});

setInterval(pollCallSignal, 2000);

setInterval(function(){

    loadMessages();
    getTypingStatus();

},3000);

</script>
<script src="../assets/js/notifications.js"></script>


</body>

</html>
