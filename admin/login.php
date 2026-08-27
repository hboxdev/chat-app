<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/admin_helpers.php";

chatweb_ensure_admin_schema($conn);
chatweb_admin_restore($conn);
if (!empty($_SESSION['admin_user_id'])) {
    header("Location: index.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid admin email.';
    } elseif (!chatweb_rate_limit($conn, 'admin_login', $email . '|' . chatweb_client_ip(), 6, 900, 900)) {
        $error = 'Too many admin login attempts. Try again later.';
    } else {
        $stmt = mysqli_prepare($conn, "
            SELECT au.*, r.name role_name
            FROM admin_users au
            LEFT JOIN roles r ON r.id=au.role_id
            WHERE au.email=? AND au.is_active=1
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($admin && password_verify($password, $admin['password'])) {
            chatweb_admin_load_session($conn, $admin);
            chatweb_admin_issue_cookie($conn, (int) $admin['id']);
            chatweb_admin_log($conn, 'ADMIN_LOGIN');
            header("Location: index.php");
            exit();
        }

        $ip = chatweb_client_ip();
        $meta = json_encode(['email' => $email]);
        $stmt = mysqli_prepare($conn, "INSERT INTO audit_logs (actor_type, action, ip_address, metadata) VALUES ('system', 'FAILED_ADMIN_LOGIN', ?, ?)");
        mysqli_stmt_bind_param($stmt, "ss", $ip, $meta);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $error = 'Invalid admin credentials.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login | Chat Web</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;place-items:center;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#111827}.card{width:min(420px,calc(100% - 28px));background:#fff;border:1px solid #dbe3ee;border-radius:8px;box-shadow:0 18px 44px rgba(15,23,42,.12);padding:32px}h1{margin:0 0 8px}.muted{color:#64748b;margin:0 0 24px}.field{display:grid;gap:8px;margin-bottom:16px}.field label{font-weight:800}.field input{min-height:46px;border:1px solid #cbd5e1;border-radius:8px;padding:0 12px;font:inherit}.btn{width:100%;min-height:48px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:800}.alert{padding:12px;border-radius:8px;background:#fee2e2;color:#991b1b;margin-bottom:16px}.hint{font-size:13px;color:#64748b;line-height:1.5;margin-top:16px}
</style>
</head>
<body>
<main class="card">
    <h1>Admin Login</h1>
    <p class="muted">Separate management access for WebChat administrators.</p>
    <?php if ($error) { ?><div class="alert"><?php echo htmlspecialchars($error); ?></div><?php } ?>
    <form method="POST">
        <div class="field"><label>Email</label><input type="email" name="email" required></div>
        <div class="field"><label>Password</label><input type="password" name="password" required></div>
        <button class="btn" type="submit">Login</button>
    </form>
    <p class="hint">First admin is bootstrapped only when `ADMIN_BOOTSTRAP_EMAIL` and `ADMIN_BOOTSTRAP_PASSWORD` are configured.</p>
</main>
</body>
</html>
