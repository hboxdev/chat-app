<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";

chatweb_ensure_auth_schema($conn);
chatweb_redirect_if_logged_in($conn, "../app/");

$error = "";
$notice = "";
$step = !empty($_SESSION['login_challenge_id']) ? 'code' : 'email';
$emailValue = $_SESSION['login_email'] ?? '';

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function login_mask_email($email)
{
    [$name, $domain] = explode('@', (string) $email, 2);
    return substr($name, 0, 2) . str_repeat('*', max(2, strlen($name) - 2)) . '@' . $domain;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'send_code';

    if ($action === 'change_email') {
        $challengeId = (int) ($_SESSION['login_challenge_id'] ?? 0);
        if ($challengeId > 0) {
            mysqli_query($conn, "UPDATE otp_challenges SET consumed_at=NOW() WHERE id=$challengeId");
        }
        unset($_SESSION['login_challenge_id'], $_SESSION['login_email']);
        $step = 'email';
        $emailValue = '';
    }

    if ($action === 'send_code') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $emailValue = $email;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Enter a valid email address.";
            $step = 'email';
        } elseif (!chatweb_rate_limit($conn, 'login_email_code', $email . '|' . chatweb_client_ip(), 5, 900, 900)) {
            $error = "Too many code requests. Please try again later.";
            $step = 'email';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1");
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$user) {
                $error = "No active account found for this email.";
                $step = 'email';
            } else {
                $code = (string) random_int(100000, 999999);
                if (!chatweb_send_email_otp($email, $code)) {
                    $error = "Email code could not be sent. Please configure SMTP or server mail settings.";
                    $step = 'email';
                } else {
                    $challengeId = (int) ($_SESSION['login_challenge_id'] ?? 0);
                    if ($challengeId > 0) {
                        mysqli_query($conn, "UPDATE otp_challenges SET consumed_at=NOW() WHERE id=$challengeId");
                    }

                    $hash = password_hash($code, PASSWORD_DEFAULT);
                    $expiresAt = date('Y-m-d H:i:s', time() + CHATWEB_OTP_TTL_MINUTES * 60);
                    $nextResendAt = date('Y-m-d H:i:s', time() + CHATWEB_OTP_RESEND_SECONDS);
                    $phone = '';
                    $country = '';
                    $detectedCountry = '';
                    $ip = chatweb_client_ip();
                    $channel = 'email';
                    $maxAttempts = CHATWEB_OTP_MAX_ATTEMPTS;
                    $purpose = 'login';
                    $stmt = mysqli_prepare($conn, "INSERT INTO otp_challenges (purpose, email, phone_number, country, detected_country, ip_address, channel, target, otp_hash, max_attempts, expires_at, next_resend_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($stmt, "sssssssssiss", $purpose, $email, $phone, $country, $detectedCountry, $ip, $channel, $email, $hash, $maxAttempts, $expiresAt, $nextResendAt);
                    mysqli_stmt_execute($stmt);
                    $_SESSION['login_challenge_id'] = (int) mysqli_insert_id($conn);
                    $_SESSION['login_email'] = $email;
                    mysqli_stmt_close($stmt);

                    $notice = "We sent a 6 digit code to " . login_mask_email($email) . ".";
                    $step = 'code';
                }
            }
        }
    }

    if ($action === 'verify_code') {
        $challengeId = (int) ($_SESSION['login_challenge_id'] ?? 0);
        $email = strtolower(trim($_SESSION['login_email'] ?? ''));
        $code = preg_replace('/\D/', '', (string) ($_POST['otp'] ?? ''));
        $emailValue = $email;

        if ($challengeId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Login session expired. Please request a new code.";
            $step = 'email';
            unset($_SESSION['login_challenge_id'], $_SESSION['login_email']);
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $error = "Enter the 6 digit code.";
            $step = 'code';
        } elseif (!chatweb_rate_limit($conn, 'login_code_verify', $challengeId . '|' . chatweb_client_ip(), 8, 900, 900)) {
            $error = "Too many verification attempts. Please try again later.";
            $step = 'code';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT * FROM otp_challenges WHERE id=? AND purpose='login' AND email=? AND consumed_at IS NULL LIMIT 1");
            mysqli_stmt_bind_param($stmt, "is", $challengeId, $email);
            mysqli_stmt_execute($stmt);
            $challenge = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$challenge) {
                $error = "Login code not found. Please request a new code.";
                $step = 'email';
            } elseif (strtotime($challenge['expires_at']) < time()) {
                $error = "Login code has expired. Please request a new code.";
                $step = 'email';
            } elseif ((int) $challenge['attempts'] >= (int) $challenge['max_attempts']) {
                $error = "Too many incorrect codes. Please request a new code.";
                $step = 'email';
            } elseif (!password_verify($code, $challenge['otp_hash'])) {
                mysqli_query($conn, "UPDATE otp_challenges SET attempts=attempts+1 WHERE id=$challengeId");
                $error = "Invalid code.";
                $step = 'code';
            } else {
                $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email=? LIMIT 1");
                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);
                $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if (!$user || !chatweb_user_access_allowed($conn, (int) $user['id'])) {
                    $error = "This account is restricted. Please contact support.";
                    $step = 'email';
                } else {
                    $userId = (int) $user['id'];
                    mysqli_query($conn, "UPDATE otp_challenges SET consumed_at=NOW() WHERE id=$challengeId");
                    mysqli_query($conn, "UPDATE users SET email_verified=1, verification_method='email', status='online', last_seen=NOW(), last_login=NOW(), last_login_at=NOW() WHERE id=$userId");
                    unset($_SESSION['login_challenge_id'], $_SESSION['login_email']);

                    chatweb_load_user_session($conn, $user);
                    chatweb_issue_remember_cookie($conn, $userId);
                    header("Location: " . (chatweb_profile_setup_complete($conn, $userId) ? "../app/" : "setup_profile.php"));
                    exit();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        html,body{width:100%;max-width:100%;overflow-x:hidden}
        .auth-wrap{min-height:100dvh;display:grid;place-items:center;padding:clamp(16px,4vw,40px)}
        .auth-card{width:min(420px,100%)}
        input,button{min-height:44px}
        .otp-input{font-size:26px;font-weight:800;letter-spacing:0;text-align:center}
    </style>
</head>

<body class="bg-light">
<div class="container auth-wrap">
    <div class="row justify-content-center">
        <div class="col-12 auth-card">
            <div class="card shadow">
                <div class="card-body">
                    <h3 class="text-center mb-4"><?php echo $step === 'code' ? 'Enter Code' : 'Sign In'; ?></h3>

                    <?php if(!empty($error)){ ?>
                        <div class="alert alert-danger"><?php echo h($error); ?></div>
                    <?php } ?>

                    <?php if(!empty($notice)){ ?>
                        <div class="alert alert-info"><?php echo h($notice); ?></div>
                    <?php } ?>

                    <?php if ($step === 'email') { ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="send_code">
                            <div class="mb-3">
                                <label>Email Address</label>
                                <input type="email" name="email" class="form-control" value="<?php echo h($emailValue); ?>" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Send login code</button>
                        </form>
                    <?php } else { ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="verify_code">
                            <div class="mb-3">
                                <label>6 digit code</label>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" name="otp" class="form-control otp-input" required autofocus>
                                <small class="text-muted">Code sent to <?php echo h(login_mask_email($emailValue)); ?></small>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Verify and login</button>
                        </form>
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="send_code">
                            <input type="hidden" name="email" value="<?php echo h($emailValue); ?>">
                            <button type="submit" class="btn btn-outline-primary w-100">Resend code</button>
                        </form>
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="change_email">
                            <button type="submit" class="btn btn-light border w-100">Change email</button>
                        </form>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
