<?php
require __DIR__ . '/_guard.php';
chatweb_admin_require_permission($conn, 'admin.dashboard.view');

function admin_count($conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);
    return (int) ($row['total'] ?? 0);
}

function admin_rows($conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function admin_month_counts($conn, $table, $where = '1=1')
{
    $data = array_fill(1, 12, 0);
    $year = (int) date('Y');
    $rows = admin_rows($conn, "
        SELECT MONTH(created_at) month_no, COUNT(*) total
        FROM $table
        WHERE YEAR(created_at)=$year AND $where
        GROUP BY MONTH(created_at)
    ");
    foreach ($rows as $row) {
        $data[(int) $row['month_no']] = (int) $row['total'];
    }
    return $data;
}

function admin_day_counts($conn)
{
    $days = (int) date('t');
    $data = array_fill(1, $days, 0);
    $rows = admin_rows($conn, "
        SELECT DAY(last_seen) day_no, COUNT(*) total
        FROM users
        WHERE last_seen IS NOT NULL AND YEAR(last_seen)=YEAR(CURDATE()) AND MONTH(last_seen)=MONTH(CURDATE())
        GROUP BY DAY(last_seen)
    ");
    foreach ($rows as $row) {
        $data[(int) $row['day_no']] = (int) $row['total'];
    }
    return $data;
}

function admin_platform_counts($conn)
{
    $web = admin_count($conn, "SELECT COUNT(*) total FROM users");
    return ['Android' => 0, 'Web' => $web, 'Ios' => 0];
}

$year = (int) date('Y');
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$stats = [
    'users' => admin_count($conn, "SELECT COUNT(*) total FROM users WHERE deleted_at IS NULL"),
    'groups' => admin_count($conn, "SELECT COUNT(*) total FROM conversations WHERE type='group'"),
    'audio' => admin_count($conn, "SELECT COUNT(*) total FROM call_sessions WHERE call_type='audio'"),
    'video' => admin_count($conn, "SELECT COUNT(*) total FROM call_sessions WHERE call_type='video'"),
    'active_country' => admin_count($conn, "SELECT COUNT(*) total FROM users WHERE status='online'"),
];
$lastMonth = [
    'users' => admin_count($conn, "SELECT COUNT(*) total FROM users WHERE deleted_at IS NULL AND YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH)"),
    'groups' => admin_count($conn, "SELECT COUNT(*) total FROM conversations WHERE type='group' AND YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH)"),
    'audio' => admin_count($conn, "SELECT COUNT(*) total FROM call_sessions WHERE call_type='audio' AND YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH)"),
    'video' => admin_count($conn, "SELECT COUNT(*) total FROM call_sessions WHERE call_type='video' AND YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH)"),
];

$monthlyUsers = admin_month_counts($conn, 'users', 'deleted_at IS NULL');
$monthlyGroups = admin_month_counts($conn, 'conversations', "type='group'");
$monthlyAudio = admin_month_counts($conn, 'call_sessions', "call_type='audio'");
$monthlyVideo = admin_month_counts($conn, 'call_sessions', "call_type='video'");
$dailyActive = admin_day_counts($conn);
$platformCounts = admin_platform_counts($conn);
$platformTotal = max(1, array_sum($platformCounts));
$countryRows = admin_rows($conn, "
    SELECT COALESCE(NULLIF(country,''), NULLIF(detected_country,''), 'Unknown') country, COUNT(*) total
    FROM users
    WHERE deleted_at IS NULL
    GROUP BY COALESCE(NULLIF(country,''), NULLIF(detected_country,''), 'Unknown')
    ORDER BY total DESC
    LIMIT 5
");
$recentUsers = admin_rows($conn, "
    SELECT id, full_name, username, email, profile_image, country, detected_country, created_at
    FROM users
    WHERE deleted_at IS NULL
    ORDER BY id DESC
    LIMIT 5
");
$recentGroups = admin_rows($conn, "
    SELECT c.id, c.title, c.image, c.created_at, COUNT(cm.user_id) members
    FROM conversations c
    LEFT JOIN conversation_members cm ON cm.conversation_id=c.id
    WHERE c.type='group'
    GROUP BY c.id
    ORDER BY c.id DESC
    LIMIT 5
");
$maxYearly = max(1, max($monthlyUsers), max($monthlyGroups));
$maxDaily = max(1, max($dailyActive));
$nav = chatweb_admin_nav($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Chat Web Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<style>
:root{--accent:#2563eb;--gold:#2563eb;--ink:#172033;--muted:#6b7280;--line:#e5e7eb;--page:#f7f7f8;--card:#fff}
*{box-sizing:border-box}body{margin:0;background:var(--page);font-family:Inter,Arial,sans-serif;color:var(--ink)}.layout{display:flex;min-height:100dvh}.sidebar{width:255px;background:#fff;border-right:1px solid #d8dde5;position:fixed;inset:0 auto 0 0;overflow:auto;padding:26px 18px}.brand{display:flex;align-items:center;gap:12px;font-size:25px;font-weight:800;color:#0a0d14;margin-bottom:32px}.brand-badge{width:45px;height:45px;border-radius:12px;background:linear-gradient(135deg,#dbeafe,#2563eb);display:grid;place-items:center;color:#fff;box-shadow:0 10px 22px rgba(37,99,235,.24)}.nav-title,.nav-section{display:block;font-weight:800;color:#1f2937;font-size:15px;margin:22px 0 8px;text-transform:uppercase}.sidebar nav a{display:flex;align-items:center;gap:14px;padding:11px 12px;border-radius:8px;color:#656b78;text-decoration:none;font-weight:600;font-size:15px}.sidebar nav a:hover,.sidebar nav a.active{color:#2563eb;background:#eff6ff}.sidebar nav a::before{font-family:"Font Awesome 6 Free";font-weight:900;content:"\f111";width:18px;color:#737373}.sidebar nav a[href*="index"]::before{content:"\f00a"}.sidebar nav a[href*="reports"]::before{content:"\f15c"}.sidebar nav a[href*="users"]::before{content:"\f007"}.sidebar nav a[href*="countrywise"]::before{content:"\f024"}.sidebar nav a[href*="groups"]::before{content:"\f0c0"}.sidebar nav a[href*="language"]::before{content:"\f1ab"}.sidebar nav a[href*="blocked"]::before{content:"\f05e"}.sidebar nav a[href*="avatar"]::before{content:"\f2bd"}.sidebar nav a[href*="calls"]::before{content:"\f095"}.sidebar nav a[href*="notifications"]::before{content:"\f0f3"}.sidebar nav a[href*="administrators"]::before{content:"\f505"}.sidebar nav a[href*="roles"]::before{content:"\f0e8"}.sidebar nav a[href*="logs"]::before{content:"\f1da"}.sidebar nav a[href*="settings"]::before{content:"\f013"}.sidebar nav a[href*="cms"]::before{content:"\f1ea"}.sidebar nav a[href*="logout"]::before{content:"\f2f5"}.main{margin-left:255px;min-width:0;flex:1}.topbar{height:82px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 31px;position:sticky;top:0;z-index:5}.searchbox{width:257px;height:40px;border:1px solid #cfd6df;border-radius:7px;display:flex;align-items:center;gap:10px;padding:0 13px;color:#a0a7b4}.searchbox input{border:0;outline:0;width:100%;font:inherit}.top-actions{display:flex;align-items:center;gap:14px}.circle{width:41px;height:41px;border:1px solid #0c111d;border-radius:50%;display:grid;place-items:center;background:#fff}.content{padding:32px 48px}.metric-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:24px;margin-bottom:24px}.metric{min-height:160px;border-radius:14px;padding:25px;color:#fff;position:relative;overflow:hidden;box-shadow:0 18px 35px rgba(25,35,55,.08)}.metric h3{font-size:18px;margin:0 0 18px}.metric strong{font-size:38px;display:block;margin-bottom:8px}.metric small{font-weight:700}.metric .trend{position:absolute;right:24px;top:24px;background:#dbeafe;color:#1d4ed8;border-radius:12px;padding:7px 13px;font-weight:800;font-size:13px}.metric.negative .trend{background:#e0f2fe;color:#0369a1}.cyan{background:linear-gradient(135deg,#2563eb,#1d4ed8)}.violet{background:linear-gradient(135deg,#1e40af,#2563eb)}.blue{background:linear-gradient(135deg,#2563eb,#60a5fa)}.pink{background:linear-gradient(135deg,#0f172a,#334155)}.panel{background:#fff;border-radius:10px;box-shadow:0 13px 30px rgba(15,23,42,.05);padding:24px;margin-bottom:24px}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}.panel h2{font-size:20px;margin:0}.select{height:38px;border:1px solid #cfd6df;border-radius:7px;background:#fff;padding:0 13px;color:#172033}.year-chart{height:320px;display:grid;grid-template-columns:42px 1fr;gap:10px}.y-axis{display:flex;flex-direction:column;justify-content:space-between;text-align:right;color:#737880;font-size:12px;padding:14px 0 34px}.bar-chart{position:relative;border-bottom:1px solid #dce1e7;background:repeating-linear-gradient(to bottom,#fff 0,#fff 49px,#e8eaee 50px);display:flex;align-items:end;gap:7%;padding:0 25px 34px}.month{flex:1;display:flex;align-items:end;justify-content:center;gap:4px;height:100%;position:relative}.month span{position:absolute;bottom:-27px;font-size:13px;color:#777}.bar{width:38%;min-width:8px;border-radius:2px 2px 0 0}.bar.users{background:#2563eb}.bar.groups{background:#0ea5e9}.legend{display:flex;justify-content:center;gap:20px;margin-top:28px;font-weight:700}.dot{width:12px;height:12px;border-radius:3px;display:inline-block;margin-right:6px}.two-col{display:grid;grid-template-columns:3fr 1fr;gap:24px}.lower-grid{display:grid;grid-template-columns:370px 1fr;gap:24px}.country-box{display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:center}.big-number{font-size:72px;line-height:1;font-weight:800;color:#364152}.map-card{min-height:280px;display:grid;place-items:center;color:#cbd5e1;background:radial-gradient(circle at 30% 45%,#dff4f9 0 22%,transparent 23%),radial-gradient(circle at 56% 42%,#dff4f9 0 27%,transparent 28%),radial-gradient(circle at 68% 60%,#dff4f9 0 18%,transparent 19%);border-radius:10px}.table-shell{border:1px solid #dde2e8;border-radius:9px;overflow:hidden}.table{width:100%;border-collapse:collapse}.table th{background:#f0f0f1;color:#16213a;font-size:13px;text-transform:uppercase;letter-spacing:.02em}.table th,.table td{padding:14px 18px;border-bottom:1px solid #e4e6eb;text-align:left}.table tr:last-child td{border-bottom:0}.avatar{width:34px;height:34px;border-radius:50%;object-fit:cover;background:#dbeafe;display:inline-grid;place-items:center;color:#1d4ed8;font-weight:800}.chip{display:inline-flex;padding:6px 13px;background:#dbeafe;color:#1d4ed8;border-radius:999px;font-weight:800;font-size:12px}.weekly-row{display:grid;grid-template-columns:36px 1fr;gap:12px;align-items:center;margin:18px 0;color:#777}.track{height:20px;background:#f0f0f2}.fill{height:100%;background:#2563eb}.activity{display:grid;place-items:center;min-height:280px}.gauge{width:210px;height:110px;border:20px solid #f3f4f6;border-top-color:#2563eb;border-left-color:#0ea5e9;border-right-color:#60a5fa;border-radius:210px 210px 0 0;border-bottom:0;position:relative}.gauge-text{position:absolute;left:0;right:0;bottom:-2px;text-align:center;font-size:20px;font-weight:800;color:#777}.daily{height:310px;position:relative;border-bottom:1px solid #dce1e7;background:repeating-linear-gradient(to bottom,#fff 0,#fff 37px,#e8eaee 38px);display:flex;align-items:end;gap:3px;padding:0 12px 24px}.daily-bar{flex:1;background:linear-gradient(to top,rgba(37,99,235,.16),rgba(37,99,235,.62));border-radius:8px 8px 0 0;min-height:1px}.country-list{display:grid;gap:12px}.country-item{display:flex;justify-content:space-between;border-bottom:1px solid #e5e7eb;padding-bottom:12px;font-weight:800;color:#666}.recent-link{color:#111827;text-decoration:none;font-weight:700}.empty{color:#777;text-align:center;padding:42px 12px}@media(max-width:1200px){.metric-grid{grid-template-columns:repeat(2,1fr)}.two-col,.lower-grid{grid-template-columns:1fr}.content{padding:24px}}@media(max-width:760px){.sidebar{position:static;width:100%;height:auto}.main{margin-left:0}.topbar{position:static}.metric-grid{grid-template-columns:1fr}.country-box{grid-template-columns:1fr}.content{padding:16px}.bar-chart{gap:3%;padding-inline:8px}}
</style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><span class="brand-badge"><i class="fa-solid fa-comments"></i></span><span>Chat Web</span></div>
        <nav><?php echo $nav; ?></nav>
    </aside>
    <main class="main">
        <header class="topbar">
            <form class="searchbox" action="users.php" method="GET">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input name="q" placeholder="Search...">
            </form>
            <div class="top-actions">
                <span class="circle"><i class="fa-regular fa-sun"></i></span>
                <span class="circle"><i class="fa-regular fa-bell"></i></span>
                <span class="circle"><?php echo strtoupper(substr($_SESSION['admin_name'] ?? 'A', 0, 1)); ?></span>
            </div>
        </header>

        <section class="content">
            <div class="metric-grid">
                <div class="metric cyan negative"><h3>Total Users</h3><strong><?php echo $stats['users']; ?></strong><small>vs last month: <?php echo $lastMonth['users']; ?></small><span class="trend">0%</span></div>
                <div class="metric violet negative"><h3>Total Groups</h3><strong><?php echo $stats['groups']; ?></strong><small>vs last month: <?php echo $lastMonth['groups']; ?></small><span class="trend">0%</span></div>
                <div class="metric blue"><h3>Total Audio calls</h3><strong><?php echo $stats['audio']; ?></strong><small>vs last month: <?php echo $lastMonth['audio']; ?></small><span class="trend">0%</span></div>
                <div class="metric pink"><h3>Total Video calls</h3><strong><?php echo $stats['video']; ?></strong><small>vs last month: <?php echo $lastMonth['video']; ?></small><span class="trend">0%</span></div>
            </div>

            <section class="panel">
                <div class="panel-head">
                    <h2>Yearly Data <select class="select"><option><?php echo $year; ?></option></select></h2>
                </div>
                <div class="year-chart">
                    <div class="y-axis"><span><?php echo $maxYearly; ?></span><span><?php echo (int) ceil($maxYearly*.75); ?></span><span><?php echo (int) ceil($maxYearly*.5); ?></span><span><?php echo (int) ceil($maxYearly*.25); ?></span><span>0</span></div>
                    <div class="bar-chart">
                        <?php foreach ($months as $i => $month) { $m = $i + 1; ?>
                            <div class="month">
                                <div class="bar users" style="height:<?php echo max(2, ($monthlyUsers[$m] / $maxYearly) * 100); ?>%"></div>
                                <div class="bar groups" style="height:<?php echo max(2, ($monthlyGroups[$m] / $maxYearly) * 100); ?>%"></div>
                                <span><?php echo $month; ?></span>
                            </div>
                        <?php } ?>
                    </div>
                </div>
                <div class="legend"><span><i class="dot" style="background:#2563eb"></i>New Users</span><span><i class="dot" style="background:#0ea5e9"></i>New Groups</span></div>
            </section>

            <div class="two-col">
                <section class="panel">
                    <h2>Active Users by Country <small style="font-weight:400">(Last 30 Minutes Users)</small></h2>
                    <div class="country-box">
                        <div>
                            <div class="big-number"><?php echo $stats['active_country']; ?></div>
                            <div class="table-shell">
                                <table class="table">
                                    <thead><tr><th>Country</th><th>Active User</th></tr></thead>
                                    <tbody>
                                    <?php if (!$countryRows) { ?>
                                        <tr><td colspan="2" class="empty">No Data</td></tr>
                                    <?php } else { foreach ($countryRows as $row) { ?>
                                        <tr><td><?php echo htmlspecialchars($row['country']); ?></td><td><?php echo (int) $row['total']; ?></td></tr>
                                    <?php }} ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="map-card"><i class="fa-solid fa-earth-americas" style="font-size:150px"></i></div>
                    </div>
                </section>
                <section class="panel">
                    <h2>Platform Activity</h2>
                    <div class="activity">
                        <div>
                            <div class="gauge"><div class="gauge-text">100%<br><span style="font-size:16px;font-weight:600">Completed</span></div></div>
                            <div class="legend" style="margin-top:34px">
                                <?php foreach ($platformCounts as $label => $count) { ?>
                                    <span><i class="dot" style="background:<?php echo $label === 'Web' ? '#2563eb' : ($label === 'Android' ? '#0ea5e9' : '#2563eb'); ?>"></i><?php echo $label; ?> <?php echo round(($count / $platformTotal) * 100); ?>%</span>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="lower-grid">
                <section class="panel">
                    <div class="panel-head"><h2>Weekly New Users</h2><i class="fa-regular fa-user"></i></div>
                    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $idx => $day) {
                        $count = admin_count($conn, "SELECT COUNT(*) total FROM users WHERE WEEKDAY(created_at)=" . (($idx + 6) % 7) . " AND created_at >= CURDATE() - INTERVAL 7 DAY");
                        $width = min(100, $count * 20);
                    ?>
                        <div class="weekly-row"><span><?php echo $day; ?></span><div class="track"><div class="fill" style="width:<?php echo $width; ?>%"></div></div></div>
                    <?php } ?>
                </section>

                <section class="panel">
                    <div class="panel-head"><h2>Recent User</h2><a class="recent-link" href="users.php">View All</a></div>
                    <div class="table-shell">
                        <table class="table">
                            <thead><tr><th>S.L</th><th>Profile</th><th>Country</th><th>Platform</th><th>Joined At</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentUsers as $user) { ?>
                                <tr>
                                    <td># <?php echo (int) $user['id']; ?></td>
                                    <td><span class="avatar"><?php echo strtoupper(substr($user['full_name'] ?: 'U', 0, 1)); ?></span> <strong><?php echo htmlspecialchars($user['full_name'] ?: 'Unknown User'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['country'] ?: $user['detected_country'] ?: '-'); ?></td>
                                    <td><span class="chip">Web</span></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($user['created_at']))); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="panel">
                <div class="panel-head">
                    <h2>Daily Active Users</h2>
                    <div><select class="select"><option><?php echo date('F'); ?></option></select> <select class="select"><option><?php echo $year; ?></option></select></div>
                </div>
                <div class="daily">
                    <?php foreach ($dailyActive as $day => $count) { ?>
                        <div class="daily-bar" title="<?php echo $day; ?> <?php echo date('M'); ?>: <?php echo $count; ?>" style="height:<?php echo max(1, ($count / $maxDaily) * 100); ?>%"></div>
                    <?php } ?>
                </div>
            </section>

            <div class="two-col">
                <section class="panel">
                    <h2>Countrywise Users</h2>
                    <div class="country-box">
                        <div class="map-card"><i class="fa-solid fa-earth-asia" style="font-size:150px;color:#2563eb"></i></div>
                        <div class="country-list">
                            <?php foreach ($countryRows as $row) { ?>
                                <div class="country-item"><span><?php echo htmlspecialchars($row['country']); ?></span><span><?php echo (int) $row['total']; ?> Users</span></div>
                            <?php } ?>
                        </div>
                    </div>
                </section>
                <section class="panel">
                    <div class="panel-head"><h2>Recent Groups</h2><a class="recent-link" href="users.php">View All</a></div>
                    <div class="table-shell">
                        <table class="table">
                            <thead><tr><th>Group Image</th><th>Name</th><th>Created At</th><th>Members</th></tr></thead>
                            <tbody>
                            <?php if (!$recentGroups) { ?>
                                <tr><td colspan="4" class="empty">No groups yet</td></tr>
                            <?php } else { foreach ($recentGroups as $group) { ?>
                                <tr><td><span class="avatar"><i class="fa-solid fa-users"></i></span></td><td><?php echo htmlspecialchars($group['title'] ?: 'Group'); ?></td><td><?php echo htmlspecialchars(date('d/m/Y, H:i:s', strtotime($group['created_at']))); ?></td><td><?php echo (int) $group['members']; ?></td></tr>
                            <?php }} ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="panel">
                <div class="panel-head"><h2>Yearly Data <select class="select"><option><?php echo $year; ?></option></select></h2></div>
                <div class="year-chart">
                    <div class="y-axis"><span><?php echo max(1, max($monthlyAudio), max($monthlyVideo)); ?></span><span>0.75</span><span>0.5</span><span>0.25</span><span>0</span></div>
                    <div class="bar-chart">
                        <?php $maxCalls = max(1, max($monthlyAudio), max($monthlyVideo)); foreach ($months as $i => $month) { $m = $i + 1; ?>
                            <div class="month">
                                <div class="bar groups" style="height:<?php echo max(2, ($monthlyVideo[$m] / $maxCalls) * 100); ?>%"></div>
                                <div class="bar users" style="background:#0ea5e9;height:<?php echo max(2, ($monthlyAudio[$m] / $maxCalls) * 100); ?>%"></div>
                                <span><?php echo $month; ?></span>
                            </div>
                        <?php } ?>
                    </div>
                </div>
                <div class="legend"><span><i class="dot" style="background:#2563eb"></i>Video Call</span><span><i class="dot" style="background:#0ea5e9"></i>Audio Call</span></div>
            </section>
        </section>
    </main>
</div>
</body>
</html>

