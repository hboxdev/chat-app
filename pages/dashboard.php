<?php
require_once __DIR__ . "/../config/session.php";
include __DIR__ . "/../config/config.php";

chatweb_ensure_auth_schema($conn);
chatweb_require_login($conn, '../index.php');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$user_id = (int) $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$username = $_SESSION['username'] ?? '';
$status = $_SESSION['status'] ?? 'offline';
$profile_image = $_SESSION['profile_image'] ?? '';
$initial = strtoupper(substr(trim($full_name ?: $username ?: 'U'), 0, 1));
$profile_image_url = '';

if (!empty($profile_image)) {
    $profile_image_url = str_starts_with($profile_image, 'uploads/')
        ? '../' . $profile_image
        : '../uploads/' . $profile_image;
}

$stats = [
    'conversations' => 0,
    'messages' => 0,
    'unread' => 0,
    'contacts' => 0
];

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) total FROM conversation_members WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$stats['conversations'] = (int) ($row['total'] ?? 0);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "
    SELECT COUNT(*) total
    FROM messages m
    JOIN conversation_members cm ON cm.conversation_id = m.conversation_id
    WHERE cm.user_id = ?
    AND m.is_deleted = 0
");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$stats['messages'] = (int) ($row['total'] ?? 0);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "
    SELECT COUNT(*) total
    FROM messages m
    JOIN conversation_members cm ON cm.conversation_id = m.conversation_id
    WHERE cm.user_id = ?
    AND m.sender_id != ?
    AND m.is_read = 0
    AND m.is_deleted = 0
");
mysqli_stmt_bind_param($stmt, "ii", $user_id, $user_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$stats['unread'] = (int) ($row['total'] ?? 0);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) total FROM user_contacts WHERE user_id = ? AND is_blocked = 0");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$stats['contacts'] = (int) ($row['total'] ?? 0);
mysqli_stmt_close($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Chat Web</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    min-height:100vh;
    font-family:Arial, Helvetica, sans-serif;
    background:#eef2f7;
    color:#111827;
}

.layout{
    min-height:100vh;
    display:flex;
}

.sidebar{
    width:270px;
    padding:22px 18px;
    background:#ffffff;
    border-right:1px solid #dbe3ee;
    display:flex;
    flex-direction:column;
    gap:24px;
}

.brand{
    display:flex;
    align-items:center;
    gap:12px;
    padding:0 8px;
}

.brand-icon{
    width:42px;
    height:42px;
    border-radius:8px;
    background:#2563eb;
    color:#ffffff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:19px;
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

.nav{
    display:grid;
    gap:6px;
}

.nav a{
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    border-radius:8px;
    color:#334155;
    text-decoration:none;
    font-weight:700;
    font-size:14px;
}

.nav a:hover,
.nav a.active{
    background:#eff6ff;
    color:#2563eb;
}

.nav a.logout{
    color:#dc2626;
    margin-top:10px;
}

.sidebar-toggle{
    width:100%;
    min-height:42px;
    border:1px solid #dbe3ee;
    border-radius:12px;
    background:#fff;
    color:#273449;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    cursor:pointer;
    box-shadow:0 12px 28px rgba(15,23,42,.06);
    font-weight:800;
    font-size:14px;
    transition:.2s;
}

.sidebar-toggle:hover{
    color:#2563eb;
    background:#eff6ff;
    transform:translateY(-1px);
}

.layout,
.sidebar,
.brand,
.nav a{
    transition:.22s ease;
}

.layout.sidebar-collapsed .sidebar{
    width:86px;
    padding:22px 12px;
    align-items:center;
}

.layout.sidebar-collapsed .brand{
    justify-content:center;
    padding:0;
}

.layout.sidebar-collapsed .brand-copy,
.layout.sidebar-collapsed .nav a span,
.layout.sidebar-collapsed .sidebar-toggle span{
    display:none;
}

.layout.sidebar-collapsed .nav a{
    justify-content:center;
    padding:12px 0;
}

.layout.sidebar-collapsed .sidebar-toggle{
    width:44px;
    padding:0;
}

.layout.sidebar-collapsed .sidebar-toggle i{
    transform:rotate(180deg);
}

.layout.sidebar-collapsed .sidebar{
    width:84px;
    flex:0 0 84px;
    gap:28px;
    padding:22px 11px;
}

.layout.sidebar-collapsed .brand{
    width:100%;
    margin-bottom:22px;
}

.layout.sidebar-collapsed .brand-icon{
    width:44px;
    height:44px;
    border-radius:14px;
}

.layout.sidebar-collapsed .nav{
    width:100%;
    gap:14px;
}

.layout.sidebar-collapsed .nav a{
    width:54px;
    min-height:44px;
    padding:0;
    border-radius:14px;
}

.layout.sidebar-collapsed .nav a.active{
    background:rgba(56,112,255,.14);
    box-shadow:inset 0 0 0 1px rgba(56,112,255,.22);
}

.layout.sidebar-collapsed .nav a.logout{
    margin-top:12px;
}

.layout.sidebar-collapsed .sidebar-toggle{
    width:44px;
    min-height:44px;
    margin-top:10px;
    border-radius:14px;
}

.main{
    flex:1;
    min-width:0;
    padding:28px;
}

.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:18px;
    margin-bottom:24px;
}

.topbar h2{
    margin:0;
    font-size:28px;
}

.topbar p{
    margin:6px 0 0;
    color:#64748b;
}

.profile-pill{
    display:flex;
    align-items:center;
    gap:12px;
    padding:10px 12px;
    border:1px solid #dbe3ee;
    border-radius:8px;
    background:#ffffff;
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
    box-shadow:
        0 16px 32px rgba(220,38,38,.28),
        inset 0 1px 0 rgba(255,255,255,.35),
        inset 0 -8px 18px rgba(127,29,29,.18);
}

.power-btn:hover{
    transform:translateY(-2px);
}

.avatar{
    width:46px;
    height:46px;
    border-radius:50%;
    background:#2563eb;
    color:#ffffff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    overflow:hidden;
}

.avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.profile-pill strong{
    display:block;
    max-width:180px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.status{
    display:flex;
    align-items:center;
    gap:6px;
    margin-top:3px;
    color:#64748b;
    font-size:12px;
    text-transform:capitalize;
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

.hero{
    display:grid;
    grid-template-columns:minmax(0,1.6fr) minmax(280px,.8fr);
    gap:18px;
    margin-bottom:18px;
}

.welcome-card,
.panel,
.stat-card,
.action-card{
    background:#ffffff;
    border:1px solid #dbe3ee;
    border-radius:8px;
    box-shadow:0 14px 34px rgba(15,23,42,.08);
}

.welcome-card{
    padding:28px;
    display:flex;
    justify-content:space-between;
    gap:22px;
    overflow:hidden;
}

.welcome-card h3{
    margin:0;
    font-size:30px;
    line-height:1.15;
}

.welcome-card p{
    max-width:560px;
    margin:12px 0 22px;
    color:#64748b;
    line-height:1.6;
}

.primary-btn,
.secondary-btn{
    display:inline-flex;
    align-items:center;
    gap:9px;
    min-height:44px;
    padding:0 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:800;
    font-size:14px;
}

.primary-btn{
    background:#2563eb;
    color:#ffffff;
}

.secondary-btn{
    background:#eff6ff;
    color:#2563eb;
    margin-left:8px;
}

.hero-mark{
    width:150px;
    height:150px;
    align-self:center;
    border-radius:8px;
    background:#eff6ff;
    color:#2563eb;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:58px;
}

.panel{
    padding:22px;
}

.panel h3{
    margin:0 0 14px;
    font-size:18px;
}

.info-row{
    display:flex;
    justify-content:space-between;
    gap:16px;
    padding:12px 0;
    border-bottom:1px solid #eef2f7;
}

.info-row:last-child{
    border-bottom:0;
}

.info-row span{
    color:#64748b;
    font-size:13px;
}

.info-row strong{
    text-align:right;
    font-size:14px;
}

.stats-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:18px;
    margin-bottom:18px;
}

.stat-card{
    padding:20px;
}

.stat-icon{
    width:42px;
    height:42px;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#eff6ff;
    color:#2563eb;
    margin-bottom:14px;
}

.stat-card span{
    display:block;
    color:#64748b;
    font-size:13px;
    margin-bottom:8px;
}

.stat-card strong{
    font-size:28px;
}

.actions-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:18px;
}

.action-card{
    padding:20px;
    text-decoration:none;
    color:#111827;
    transition:transform .18s ease,border-color .18s ease;
}

.action-card:hover{
    transform:translateY(-2px);
    border-color:#93c5fd;
}

.action-card i{
    width:42px;
    height:42px;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#f1f5f9;
    color:#2563eb;
    margin-bottom:16px;
}

.action-card strong{
    display:block;
    margin-bottom:6px;
}

.action-card span{
    color:#64748b;
    font-size:13px;
    line-height:1.5;
}

@media(max-width:980px){
    .layout{
        display:block;
    }

    .sidebar{
        width:100%;
        border-right:0;
        border-bottom:1px solid #dbe3ee;
    }

    .nav{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }

    .hero,
    .stats-grid,
    .actions-grid{
        grid-template-columns:1fr;
    }

    .topbar{
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:640px){
    .main{
        padding:18px;
    }

    .welcome-card{
        padding:22px;
    }

    .hero-mark{
        display:none;
    }

    .secondary-btn{
        margin-left:0;
        margin-top:8px;
    }

    .nav{
        grid-template-columns:1fr;
    }
}

/* Responsive safety pass */
html,
body{
    width:100%;
    max-width:100%;
    overflow-x:hidden;
    -webkit-text-size-adjust:100%;
}

img,
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

.layout{
    width:100%;
    min-width:0;
}

.main{
    width:100%;
    max-width:1500px;
    margin:0 auto;
    padding-left:clamp(16px,2.5vw,34px);
    padding-right:clamp(16px,2.5vw,34px);
}

.topbar,
.profile-pill,
.welcome-actions{
    min-width:0;
    flex-wrap:wrap;
}

.topbar h2{
    font-size:clamp(24px,3vw,32px);
}

.welcome-card h3{
    font-size:clamp(24px,3.8vw,42px);
    line-height:1.12;
}

.stat-grid,
.quick-grid,
.activity-grid{
    grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));
}

.nav a,
.profile-pill,
.card,
.activity-card{
    min-width:0;
}

.nav a span,
.profile-pill strong,
.profile-pill span,
.card,
.activity-card{
    overflow-wrap:anywhere;
}

@media(max-width:900px){
    .layout,
    .layout.sidebar-collapsed{
        display:block;
    }

    .sidebar,
    .layout.sidebar-collapsed .sidebar{
        width:100%;
        min-height:auto;
        flex-basis:auto;
        border-right:0;
        border-bottom:1px solid #dbe3ee;
        position:relative;
    }

    .layout.sidebar-collapsed .brand-copy,
    .layout.sidebar-collapsed .nav a span,
    .layout.sidebar-collapsed .sidebar-toggle span{
        display:block;
    }

    .layout.sidebar-collapsed .nav a{
        width:auto;
        justify-content:flex-start;
        padding:12px 14px;
    }
}

@media(max-width:520px){
    .main{
        padding:16px 12px calc(18px + env(safe-area-inset-bottom));
    }

    .topbar-actions,
    .profile-pill{
        width:100%;
    }

    .power-btn{
        width:46px;
        height:46px;
    }
}
</style>
</head>

<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon">
                <i class="fa-solid fa-comments"></i>
            </div>
            <div class="brand-copy">
                <h1>Chat Web</h1>
                <span>Messaging workspace</span>
            </div>
        </div>

        <nav class="nav">
            <a href="dashboard.php" class="active"><i class="fa-solid fa-table-columns"></i> <span>Dashboard</span></a>
            <a href="chat.php"><i class="fa-solid fa-message"></i> <span>Chats</span></a>
            <a href="profile.php?v=2"><i class="fa-solid fa-user"></i> <span>Profile</span></a>
            <a href="settings.php?v=2"><i class="fa-solid fa-gear"></i> <span>Settings</span></a>
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
                <h2>Dashboard</h2>
                <p>Welcome back, <?php echo htmlspecialchars($full_name ?: 'User'); ?>.</p>
            </div>

            <div class="topbar-actions">
                <div class="profile-pill">
                    <div class="avatar">
                        <?php if($profile_image_url){ ?>
                            <img src="<?php echo htmlspecialchars($profile_image_url); ?>" alt="<?php echo htmlspecialchars($full_name ?: 'User'); ?>">
                        <?php } else { ?>
                            <?php echo htmlspecialchars($initial); ?>
                        <?php } ?>
                    </div>
                    <div>
                        <strong><?php echo htmlspecialchars($full_name ?: 'User'); ?></strong>
                        <span class="status">
                            <span class="status-dot <?php echo htmlspecialchars($status); ?>"></span>
                            <?php echo htmlspecialchars($status); ?>
                        </span>
                    </div>
                </div>
                <div id="notification-inline"></div>
                <a class="power-btn" href="../logout.php" title="Logout">
                    <i class="fa-solid fa-power-off"></i>
                </a>
            </div>
        </div>

        <section class="hero">
            <div class="welcome-card">
                <div>
                    <h3>Your conversations, neatly in one place.</h3>
                    <p>Open chat to send messages, images, GIFs, documents, view-once media, and keep track of seen status.</p>
                    <a href="chat.php" class="primary-btn"><i class="fa-solid fa-paper-plane"></i> Open Chat</a>
                    <a href="profile.php?v=2" class="secondary-btn"><i class="fa-solid fa-user-pen"></i> View Profile</a>
                </div>
                <div class="hero-mark">
                    <i class="fa-solid fa-comments"></i>
                </div>
            </div>

            <div class="panel">
                <h3>Account Details</h3>
                <div class="info-row">
                    <span>Full name</span>
                    <strong><?php echo htmlspecialchars($full_name ?: '-'); ?></strong>
                </div>
                <div class="info-row">
                    <span>Username</span>
                    <strong><?php echo htmlspecialchars($username ?: '-'); ?></strong>
                </div>
                <div class="info-row">
                    <span>Email</span>
                    <strong><?php echo htmlspecialchars($email ?: '-'); ?></strong>
                </div>
                <div class="info-row">
                    <span>Status</span>
                    <strong><?php echo htmlspecialchars($status); ?></strong>
                </div>
            </div>
        </section>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-comments"></i></div>
                <span>Conversations</span>
                <strong><?php echo $stats['conversations']; ?></strong>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-envelope"></i></div>
                <span>Total Messages</span>
                <strong><?php echo $stats['messages']; ?></strong>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-bell"></i></div>
                <span>Unread</span>
                <strong><?php echo $stats['unread']; ?></strong>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-address-book"></i></div>
                <span>Contacts</span>
                <strong><?php echo $stats['contacts']; ?></strong>
            </div>
        </section>

        <section class="actions-grid">
            <a href="chat.php" class="action-card">
                <i class="fa-solid fa-message"></i>
                <strong>Continue chatting</strong>
                <span>Jump straight into your active conversations.</span>
            </a>
            <a href="settings.php?v=2" class="action-card">
                <i class="fa-solid fa-sliders"></i>
                <strong>Manage settings</strong>
                <span>Update preferences and account controls.</span>
            </a>
            <a href="../logout.php" class="action-card">
                <i class="fa-solid fa-lock"></i>
                <strong>Secure logout</strong>
                <span>End your current session from this device.</span>
            </a>
        </section>
    </main>
</div>
<script>
(function(){
    const root = document.querySelector(".layout");
    const toggle = document.getElementById("sidebar-toggle");

    function sync(){
        if(window.matchMedia("(max-width: 900px)").matches){
            root.classList.remove("sidebar-collapsed");
        }

        const collapsed = root.classList.contains("sidebar-collapsed");
        toggle.setAttribute("aria-label", collapsed ? "Open sidebar" : "Close sidebar");
        toggle.setAttribute("title", collapsed ? "Open sidebar" : "Close sidebar");
        toggle.querySelector("span").textContent = collapsed ? "Open sidebar" : "Collapse sidebar";
    }

    if(localStorage.getItem("chatwebSidebarCollapsed") === "1" && !window.matchMedia("(max-width: 900px)").matches){
        root.classList.add("sidebar-collapsed");
    }

    sync();

    toggle.addEventListener("click", function(){
        root.classList.toggle("sidebar-collapsed");
        localStorage.setItem("chatwebSidebarCollapsed", root.classList.contains("sidebar-collapsed") ? "1" : "0");
        sync();
    });

    document.querySelectorAll(".nav a[href*='chat.php']").forEach(function(link){
        link.addEventListener("click", function(){
            localStorage.setItem("chatwebSidebarCollapsed", "1");
        });
    });

    window.addEventListener("resize", sync);
})();
</script>
<script src="../assets/js/notifications.js"></script>
</body>
</html>
