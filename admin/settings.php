<?php
require __DIR__ . '/_guard.php';
chatweb_admin_require_permission($conn, 'settings.view');

$canEdit = chatweb_admin_has_permission($conn, 'settings.edit');
$saved = false;
$errors = [];

if (empty($_SESSION['admin_settings_csrf'])) {
    $_SESSION['admin_settings_csrf'] = bin2hex(random_bytes(24));
}

$settingFields = [
    'site_name' => ['label' => 'Platform name', 'type' => 'text', 'default' => 'Chat Web', 'max' => 80],
    'support_email' => ['label' => 'Support email', 'type' => 'email', 'default' => '', 'max' => 150],
    'default_country' => ['label' => 'Default country', 'type' => 'text', 'default' => 'Pakistan', 'max' => 80],
    'email_from_name' => ['label' => 'Email sender name', 'type' => 'text', 'default' => 'Chat Web', 'max' => 80],
    'sms_sender_name' => ['label' => 'SMS sender name', 'type' => 'text', 'default' => 'ChatWeb', 'max' => 30],
    'otp_ttl_minutes' => ['label' => 'OTP expiry minutes', 'type' => 'number', 'default' => '10', 'min' => 1, 'max' => 60],
    'otp_resend_seconds' => ['label' => 'OTP resend cooldown seconds', 'type' => 'number', 'default' => '30', 'min' => 15, 'max' => 300],
    'otp_max_attempts' => ['label' => 'OTP verification attempts', 'type' => 'number', 'default' => '5', 'min' => 3, 'max' => 10],
    'registration_enabled' => ['label' => 'Registration enabled', 'type' => 'toggle', 'default' => '1'],
    'maintenance_mode' => ['label' => 'Maintenance mode', 'type' => 'toggle', 'default' => '0'],
    'sms_api_url' => ['label' => 'SMS API URL', 'type' => 'url', 'default' => '', 'max' => 500],
    'smtp_host' => ['label' => 'SMTP host', 'type' => 'text', 'default' => '', 'max' => 150],
    'smtp_port' => ['label' => 'SMTP port', 'type' => 'number', 'default' => '587', 'min' => 1, 'max' => 65535],
    'smtp_encryption' => ['label' => 'SMTP encryption', 'type' => 'text', 'default' => 'tls', 'max' => 10],
    'smtp_username' => ['label' => 'SMTP username', 'type' => 'text', 'default' => '', 'max' => 150],
    'smtp_password' => ['label' => 'SMTP password', 'type' => 'secret', 'default' => '', 'max' => 255],
    'smtp_from' => ['label' => 'SMTP from email', 'type' => 'email', 'default' => '', 'max' => 150],
    'geoip_api_url' => ['label' => 'GeoIP API URL', 'type' => 'url', 'default' => '', 'max' => 500],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        $errors[] = 'You do not have permission to edit settings.';
    } elseif (!hash_equals($_SESSION['admin_settings_csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Security check failed. Please refresh and try again.';
    } else {
        foreach ($settingFields as $key => $field) {
            $value = $_POST[$key] ?? '';
            if ($field['type'] === 'toggle') {
                $value = $value === '1' ? '1' : '0';
            } elseif ($field['type'] === 'number') {
                $value = (string) min((int) $field['max'], max((int) $field['min'], (int) $value));
            } elseif ($field['type'] === 'secret' && trim((string) $value) === '') {
                $value = chatweb_app_setting($conn, $key, $field['default']);
            } else {
                $value = trim((string) $value);
                $value = substr($value, 0, (int) $field['max']);
                if ($field['type'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = $field['label'] . ' must be a valid email address.';
                }
                if ($field['type'] === 'url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[] = $field['label'] . ' must be a valid URL.';
                }
                if ($key === 'smtp_encryption' && !in_array(strtolower($value), ['tls','ssl','none',''], true)) {
                    $errors[] = 'SMTP encryption must be tls, ssl, or none.';
                }
            }
            $_POST[$key] = $value;
        }

        if (!$errors) {
            foreach ($settingFields as $key => $field) {
                chatweb_set_app_setting($conn, $key, $_POST[$key], (int) $_SESSION['admin_user_id']);
            }
            chatweb_admin_log($conn, 'SYSTEM_SETTING_CHANGED', 'settings', 'platform', ['keys' => array_keys($settingFields)]);
            $saved = true;
            $_SESSION['admin_settings_csrf'] = bin2hex(random_bytes(24));
        }
    }
}

$settings = [];
foreach ($settingFields as $key => $field) {
    $settings[$key] = chatweb_app_setting($conn, $key, $field['default']);
}

foreach ([
    'sms_api_url' => 'SMS_API_URL',
    'smtp_host' => 'SMTP_HOST',
    'smtp_port' => 'SMTP_PORT',
    'smtp_encryption' => 'SMTP_ENCRYPTION',
    'smtp_username' => 'SMTP_USERNAME',
    'smtp_from' => 'SMTP_FROM',
    'geoip_api_url' => 'GEOIP_API_URL',
] as $key => $envKey) {
    if ($settings[$key] === '') {
        $settings[$key] = chatweb_backend_config($conn, $key, $envKey, $settingFields[$key]['default']);
    }
}

$deliveryStatus = [
    'SMS API' => chatweb_backend_config($conn, 'sms_api_url', 'SMS_API_URL') !== '',
    'SMTP Host' => chatweb_backend_config($conn, 'smtp_host', 'SMTP_HOST') !== '',
    'SMTP Username' => chatweb_backend_config($conn, 'smtp_username', 'SMTP_USERNAME') !== '',
    'SMTP Password' => chatweb_backend_config($conn, 'smtp_password', 'SMTP_PASSWORD') !== '',
    'SMTP From' => chatweb_backend_config($conn, 'smtp_from', 'SMTP_FROM', getenv('MAIL_FROM') ?: '') !== '',
    'GeoIP' => chatweb_backend_config($conn, 'geoip_api_url', 'GEOIP_API_URL') !== '',
];

$nav = chatweb_admin_nav($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | Chat Web Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<style>
:root{--primary:#2563eb;--primary-dark:#1d4ed8;--navy:#101828;--ink:#172033;--muted:#667085;--line:#e5e7eb;--page:#f4f7fb;--soft:#eff6ff;--card:#fff}
*{box-sizing:border-box}body{margin:0;background:var(--page);font-family:Inter,Arial,sans-serif;color:var(--ink)}.layout{display:flex;min-height:100dvh}.sidebar{width:255px;background:#fff;border-right:1px solid #d8dde5;position:fixed;inset:0 auto 0 0;overflow:auto;padding:26px 18px}.brand{display:flex;align-items:center;gap:12px;font-size:25px;font-weight:800;color:#0a0d14;margin-bottom:32px}.brand-badge{width:45px;height:45px;border-radius:12px;background:linear-gradient(135deg,#dbeafe,#2563eb);display:grid;place-items:center;color:#fff;box-shadow:0 10px 22px rgba(37,99,235,.24)}.nav-section{display:block;font-weight:800;color:#1f2937;font-size:15px;margin:22px 0 8px;text-transform:uppercase}.sidebar nav a{display:flex;align-items:center;gap:14px;padding:11px 12px;border-radius:8px;color:#656b78;text-decoration:none;font-weight:600;font-size:15px}.sidebar nav a:hover,.sidebar nav a.active{color:var(--primary);background:var(--soft)}.sidebar nav a::before{font-family:"Font Awesome 6 Free";font-weight:900;content:"\f111";width:18px;color:#737373}.sidebar nav a[href*="index"]::before{content:"\f00a"}.sidebar nav a[href*="reports"]::before{content:"\f15c"}.sidebar nav a[href*="users"]::before{content:"\f007"}.sidebar nav a[href*="countrywise"]::before{content:"\f024"}.sidebar nav a[href*="groups"]::before{content:"\f0c0"}.sidebar nav a[href*="language"]::before{content:"\f1ab"}.sidebar nav a[href*="blocked"]::before{content:"\f05e"}.sidebar nav a[href*="avatar"]::before{content:"\f2bd"}.sidebar nav a[href*="calls"]::before{content:"\f095"}.sidebar nav a[href*="notifications"]::before{content:"\f0f3"}.sidebar nav a[href*="administrators"]::before{content:"\f505"}.sidebar nav a[href*="roles"]::before{content:"\f0e8"}.sidebar nav a[href*="logs"]::before{content:"\f1da"}.sidebar nav a[href*="settings"]::before{content:"\f013"}.sidebar nav a[href*="cms"]::before{content:"\f1ea"}.sidebar nav a[href*="logout"]::before{content:"\f2f5"}.main{margin-left:255px;min-width:0;flex:1}.topbar{height:82px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 31px;position:sticky;top:0;z-index:5}.searchbox{width:257px;height:40px;border:1px solid #cfd6df;border-radius:7px;display:flex;align-items:center;gap:10px;padding:0 13px;color:#a0a7b4}.searchbox input{border:0;outline:0;width:100%;font:inherit}.top-actions{display:flex;align-items:center;gap:14px}.circle{width:41px;height:41px;border:1px solid #0c111d;border-radius:50%;display:grid;place-items:center;background:#fff}.content{padding:30px 48px}.hero{background:linear-gradient(135deg,#101828,#1d4ed8);color:#fff;border-radius:14px;padding:26px 30px;margin-bottom:24px;display:flex;justify-content:space-between;gap:18px;align-items:center}.hero h1{margin:0 0 8px;font-size:30px}.hero p{margin:0;color:#dbeafe}.badge{display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:8px 12px;font-weight:800;font-size:13px}.badge.ok{background:#dcfce7;color:#166534}.badge.warn{background:#fef3c7;color:#92400e}.grid{display:grid;grid-template-columns:290px 1fr;gap:24px}.menu,.panel{background:#fff;border-radius:12px;box-shadow:0 13px 30px rgba(15,23,42,.05);border:1px solid #edf0f4}.menu{padding:12px;height:max-content;position:sticky;top:104px}.menu a{display:flex;gap:11px;align-items:center;color:#526071;text-decoration:none;font-weight:700;padding:13px;border-radius:9px}.menu a:hover,.menu a.active{background:var(--soft);color:var(--primary)}.panel{padding:24px;margin-bottom:22px}.panel h2{font-size:20px;margin:0 0 6px}.panel p{color:var(--muted);margin:0 0 20px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.field label{display:block;font-size:13px;font-weight:800;margin-bottom:8px}.field input{width:100%;height:45px;border:1px solid #ccd5e1;border-radius:8px;padding:0 12px;font:inherit;background:#fff}.field input:disabled{background:#f8fafc;color:#7b8493}.switch-row{display:flex;justify-content:space-between;align-items:center;border:1px solid #e5e7eb;border-radius:10px;padding:15px;gap:16px}.switch-row strong{display:block}.switch-row span{color:var(--muted);font-size:13px}.switch{position:relative;width:52px;height:30px;flex:0 0 auto}.switch input{display:none}.slider{position:absolute;inset:0;background:#cbd5e1;border-radius:999px;transition:.2s}.slider:before{content:"";position:absolute;width:24px;height:24px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 2px 5px rgba(0,0,0,.2)}.switch input:checked+.slider{background:var(--primary)}.switch input:checked+.slider:before{transform:translateX(22px)}.status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.status-card{border:1px solid #e5e7eb;border-radius:10px;padding:16px;background:#fff}.status-card small{display:block;color:var(--muted);margin-top:6px}.actions{display:flex;justify-content:flex-end;gap:12px;margin-top:22px}.btn{border:0;border-radius:9px;background:var(--primary);color:#fff;padding:13px 18px;font-weight:800;cursor:pointer}.btn:disabled{background:#94a3b8;cursor:not-allowed}.notice{border-radius:10px;padding:14px 16px;margin-bottom:18px;font-weight:700}.notice.success{background:#dcfce7;color:#166534}.notice.error{background:#fee2e2;color:#991b1b}.secret-note{border-left:4px solid var(--primary);background:#f8fbff;padding:14px 16px;border-radius:9px;color:#475467}.kv{display:grid;gap:10px}.kv div{display:flex;justify-content:space-between;gap:14px;padding:11px 0;border-bottom:1px solid #eef2f7}.kv div:last-child{border-bottom:0}.kv span{color:var(--muted)}@media(max-width:1100px){.grid{grid-template-columns:1fr}.menu{position:static}.status-grid,.form-grid{grid-template-columns:1fr}.content{padding:22px}}@media(max-width:760px){.sidebar{position:static;width:100%;height:auto}.main{margin-left:0}.topbar{position:static}.hero{display:block}.content{padding:16px}}
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
            <form class="searchbox" action="users.php" method="GET"><i class="fa-solid fa-magnifying-glass"></i><input name="q" placeholder="Search..."></form>
            <div class="top-actions"><span class="circle"><i class="fa-regular fa-sun"></i></span><span class="circle"><i class="fa-regular fa-bell"></i></span><span class="circle"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['admin_name'] ?? 'A', 0, 1))); ?></span></div>
        </header>
        <section class="content">
            <div class="hero">
                <div><h1>System Settings</h1><p>Manage platform identity, onboarding, OTP, delivery and security controls.</p></div>
                <span class="badge <?php echo $canEdit ? 'ok' : 'warn'; ?>"><i class="fa-solid fa-shield-halved"></i><?php echo $canEdit ? 'Editable' : 'View only'; ?></span>
            </div>

            <?php if ($saved) { ?><div class="notice success">Settings saved successfully.</div><?php } ?>
            <?php foreach ($errors as $error) { ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php } ?>

            <div class="grid">
                <aside class="menu">
                    <a class="active" href="#general"><i class="fa-solid fa-sliders"></i>General</a>
                    <a href="#onboarding"><i class="fa-solid fa-user-check"></i>Registration & OTP</a>
                    <a href="#delivery"><i class="fa-solid fa-paper-plane"></i>Delivery</a>
                    <a href="#security"><i class="fa-solid fa-lock"></i>Security</a>
                    <a href="#bootstrap"><i class="fa-solid fa-user-shield"></i>Admin Bootstrap</a>
                </aside>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_settings_csrf']); ?>">

                    <section class="panel" id="general">
                        <h2>General</h2>
                        <p>Basic platform settings shown across the admin and onboarding flow.</p>
                        <div class="form-grid">
                            <?php foreach (['site_name','support_email','default_country','email_from_name','sms_sender_name'] as $key) { $field = $settingFields[$key]; ?>
                                <div class="field">
                                    <label for="<?php echo $key; ?>"><?php echo htmlspecialchars($field['label']); ?></label>
                                    <input id="<?php echo $key; ?>" name="<?php echo $key; ?>" type="<?php echo $field['type']; ?>" value="<?php echo htmlspecialchars($settings[$key]); ?>" <?php echo $canEdit ? '' : 'disabled'; ?>>
                                </div>
                            <?php } ?>
                        </div>
                    </section>

                    <section class="panel" id="onboarding">
                        <h2>Registration & OTP</h2>
                        <p>These OTP values are used by the registration flow. Resend cooldown is capped to a sensible secure range.</p>
                        <div class="form-grid">
                            <?php foreach (['otp_ttl_minutes','otp_resend_seconds','otp_max_attempts'] as $key) { $field = $settingFields[$key]; ?>
                                <div class="field">
                                    <label for="<?php echo $key; ?>"><?php echo htmlspecialchars($field['label']); ?></label>
                                    <input id="<?php echo $key; ?>" name="<?php echo $key; ?>" type="number" min="<?php echo (int) $field['min']; ?>" max="<?php echo (int) $field['max']; ?>" value="<?php echo htmlspecialchars($settings[$key]); ?>" <?php echo $canEdit ? '' : 'disabled'; ?>>
                                </div>
                            <?php } ?>
                        </div>
                        <div style="height:18px"></div>
                        <div class="form-grid">
                            <div class="switch-row">
                                <div><strong>Registration enabled</strong><span>Allow new users to start onboarding.</span></div>
                                <label class="switch"><input type="checkbox" name="registration_enabled" value="1" <?php echo $settings['registration_enabled'] === '1' ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>><span class="slider"></span></label>
                            </div>
                            <div class="switch-row">
                                <div><strong>Maintenance mode</strong><span>Prepared switch for future access gating.</span></div>
                                <label class="switch"><input type="checkbox" name="maintenance_mode" value="1" <?php echo $settings['maintenance_mode'] === '1' ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>><span class="slider"></span></label>
                            </div>
                        </div>
                    </section>

                    <section class="panel" id="delivery">
                        <h2>Delivery Configuration</h2>
                        <p>Manage SMS, SMTP and GeoIP provider settings from the backend. Password is write-only: leave it blank to keep the current value.</p>
                        <div class="status-grid">
                            <?php foreach ($deliveryStatus as $label => $ready) { ?>
                                <div class="status-card">
                                    <span class="badge <?php echo $ready ? 'ok' : 'warn'; ?>"><?php echo $ready ? 'Ready' : 'Needs setup'; ?></span>
                                    <h3><?php echo htmlspecialchars($label); ?></h3>
                                    <small><?php echo $ready ? 'Backend value available' : 'Add value below'; ?></small>
                                </div>
                            <?php } ?>
                        </div>
                        <div style="height:20px"></div>
                        <div class="form-grid">
                            <?php foreach (['sms_api_url','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_from','geoip_api_url'] as $key) { $field = $settingFields[$key]; ?>
                                <div class="field">
                                    <label for="<?php echo $key; ?>"><?php echo htmlspecialchars($field['label']); ?></label>
                                    <input id="<?php echo $key; ?>" name="<?php echo $key; ?>" type="<?php echo $field['type'] === 'url' ? 'url' : $field['type']; ?>" value="<?php echo htmlspecialchars($settings[$key]); ?>" <?php echo $canEdit ? '' : 'disabled'; ?>>
                                </div>
                            <?php } ?>
                            <div class="field">
                                <label for="smtp_password">SMTP password</label>
                                <input id="smtp_password" name="smtp_password" type="password" value="" placeholder="<?php echo $deliveryStatus['SMTP Password'] ? 'Password saved - leave blank to keep' : 'Enter SMTP password'; ?>" <?php echo $canEdit ? '' : 'disabled'; ?>>
                            </div>
                        </div>
                        <p class="secret-note">These values are saved in backend settings and used by OTP delivery. Existing server environment values still work as fallback until you override them here.</p>
                    </section>

                    <section class="panel" id="security">
                        <h2>Security</h2>
                        <p>Current authentication and admin security status.</p>
                        <div class="kv">
                            <div><span>Admin sessions</span><strong>Separate admin cookie + DB-backed token</strong></div>
                            <div><span>User sessions</span><strong>Persistent until user logout or admin revoke</strong></div>
                            <div><span>OTP storage</span><strong>Password-hashed codes, no plaintext exposure</strong></div>
                            <div><span>Rate limiting</span><strong>DB table `auth_rate_limits`</strong></div>
                            <div><span>Audit logging</span><strong>Enabled for sensitive admin actions</strong></div>
                        </div>
                    </section>

                    <section class="panel" id="bootstrap">
                        <h2>Admin Bootstrap</h2>
                        <p>First Super Admin should be created once, then bootstrap env vars can be removed.</p>
                        <div class="kv">
                            <div><span>ADMIN_BOOTSTRAP_EMAIL</span><strong><?php echo getenv('ADMIN_BOOTSTRAP_EMAIL') ? 'Configured' : 'Not configured'; ?></strong></div>
                            <div><span>ADMIN_BOOTSTRAP_PASSWORD</span><strong><?php echo getenv('ADMIN_BOOTSTRAP_PASSWORD') ? 'Configured' : 'Not configured'; ?></strong></div>
                        </div>
                    </section>

                    <div class="actions"><button class="btn" type="submit" <?php echo $canEdit ? '' : 'disabled'; ?>>Save Settings</button></div>
                </form>
            </div>
        </section>
    </main>
</div>
</body>
</html>
