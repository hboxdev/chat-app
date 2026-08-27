<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";

chatweb_ensure_auth_schema($conn);
chatweb_cleanup_rate_limits($conn);
$registerEmbedded = defined('CHATWEB_INDEX_REGISTER') && CHATWEB_INDEX_REGISTER;
$setupProfilePath = $registerEmbedded ? 'pages/setup_profile.php' : 'setup_profile.php';
$appPath = $registerEmbedded ? 'app/' : '../app/';
$loginPath = $registerEmbedded ? 'pages/login.php' : 'login.php';

chatweb_restore_login($conn);
if (!empty($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    $result = mysqli_query($conn, "SELECT profile_completed, onboarding_completed FROM users WHERE id=$userId LIMIT 1");
    $user = $result ? mysqli_fetch_assoc($result) : [];
    header("Location: " . ((empty($user['profile_completed']) || empty($user['onboarding_completed'])) ? $setupProfilePath : $appPath));
    exit();
}

$errors = [];
$notice = "";
$step = !empty($_SESSION['registration_challenge_id']) ? 'verify' : 'details';
$clientIp = chatweb_client_ip();
$detectedCountry = $_SESSION['detected_country'] ?? chatweb_detect_country($clientIp);
$_SESSION['detected_country'] = $detectedCountry;

$countryDialCodes = [
    'Afghanistan' => '+93', 'Albania' => '+355', 'Algeria' => '+213', 'Argentina' => '+54',
    'Australia' => '+61', 'Austria' => '+43', 'Azerbaijan' => '+994', 'Bahrain' => '+973',
    'Bangladesh' => '+880', 'Belgium' => '+32', 'Brazil' => '+55', 'Canada' => '+1',
    'China' => '+86', 'Denmark' => '+45', 'Egypt' => '+20', 'France' => '+33',
    'Germany' => '+49', 'Ghana' => '+233', 'Greece' => '+30', 'Hong Kong' => '+852',
    'India' => '+91', 'Indonesia' => '+62', 'Iran' => '+98', 'Iraq' => '+964',
    'Ireland' => '+353', 'Italy' => '+39', 'Japan' => '+81', 'Jordan' => '+962',
    'Kenya' => '+254', 'Kuwait' => '+965', 'Malaysia' => '+60', 'Mexico' => '+52',
    'Morocco' => '+212', 'Nepal' => '+977', 'Netherlands' => '+31', 'New Zealand' => '+64',
    'Nigeria' => '+234', 'Norway' => '+47', 'Oman' => '+968', 'Pakistan' => '+92',
    'Philippines' => '+63', 'Qatar' => '+974', 'Russia' => '+7', 'Saudi Arabia' => '+966',
    'Singapore' => '+65', 'South Africa' => '+27', 'South Korea' => '+82', 'Spain' => '+34',
    'Sri Lanka' => '+94', 'Sweden' => '+46', 'Switzerland' => '+41', 'Thailand' => '+66',
    'Turkey' => '+90', 'United Arab Emirates' => '+971', 'United Kingdom' => '+44',
    'United States' => '+1', 'Vietnam' => '+84', 'Other' => ''
];
$countries = array_keys($countryDialCodes);

function registration_rate_error($label, $retryAfter)
{
    return $label . " Please try again in " . chatweb_format_retry_after($retryAfter) . ".";
}

function registration_resend_wait_seconds($conn, $challengeId, $nextResendAt)
{
    $wait = strtotime($nextResendAt) - time();
    if ($wait <= 0) {
        return 0;
    }

    $resendSeconds = chatweb_app_setting_int($conn, 'otp_resend_seconds', CHATWEB_OTP_RESEND_SECONDS, 15, 300);
    if ($wait > $resendSeconds) {
        $normalized = date('Y-m-d H:i:s', time() + $resendSeconds);
        $stmt = mysqli_prepare($conn, "UPDATE otp_challenges SET next_resend_at=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "si", $normalized, $challengeId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $resendSeconds;
    }

    return $wait;
}

function registration_mask_target($target)
{
    $target = (string) $target;
    if (strpos($target, '@') !== false) {
        [$name, $domain] = explode('@', $target, 2);
        return substr($name, 0, 2) . str_repeat('*', max(2, strlen($name) - 2)) . '@' . $domain;
    }

    return substr($target, 0, 4) . str_repeat('*', max(3, strlen($target) - 7)) . substr($target, -3);
}

function registration_otp_notice($otp)
{
    $target = registration_mask_target($otp['target'] ?? '');
    if (($otp['channel'] ?? '') === 'sms') {
        return "We've sent a verification code to your phone " . $target . ".";
    }

    return "Unable to send the verification code to your phone. We've sent a verification code to your email " . $target . " instead.";
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function registration_find_user_by_phone($conn, $phone)
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE phone_number=? OR phone=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $phone, $phone);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $user ?: null;
}

function registration_email_used_by_other_phone($conn, $email, $phone)
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email=? AND COALESCE(phone_number, phone, '')<>? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $email, $phone);
    mysqli_stmt_execute($stmt);
    $exists = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'start';

    if ($action === 'reset') {
        $challengeId = (int) ($_SESSION['registration_challenge_id'] ?? 0);
        if ($challengeId > 0) {
            mysqli_query($conn, "UPDATE otp_challenges SET consumed_at=NOW() WHERE id=" . $challengeId);
        }
        unset($_SESSION['registration_challenge_id']);
        $step = 'details';
        $notice = "Enter your phone and email again to request a fresh code.";
    }

    if ($action === 'start') {
        $country = trim($_POST['country'] ?? '');
        $phoneInput = trim($_POST['phone_number'] ?? '');
        if ($phoneInput !== '' && $country !== '' && ($countryDialCodes[$country] ?? '') !== '' && $phoneInput[0] !== '+') {
            $phoneInput = $countryDialCodes[$country] . $phoneInput;
        }
        if ($phoneInput !== '' && $country !== '' && ($countryDialCodes[$country] ?? '') !== '') {
            $dialCode = $countryDialCodes[$country];
            if (strpos($phoneInput, $dialCode . '0') === 0) {
                $phoneInput = $dialCode . substr($phoneInput, strlen($dialCode) + 1);
            }
        }
        $phone = chatweb_normalize_phone($phoneInput);
        $email = strtolower(trim($_POST['email'] ?? ''));

        if ($country === '') {
            $errors[] = "Select your country first.";
        }
        if (!chatweb_valid_phone($phone)) {
            $errors[] = "Enter the phone number in international format, for example +923001234567.";
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Enter a valid email address or leave it empty.";
        }
        if (!$errors && registration_email_used_by_other_phone($conn, $email, $phone)) {
            $errors[] = "That email is already linked with another account.";
        }

        if (!$errors) {
            $activeChallenge = chatweb_active_otp_challenge($conn, $phone);
            $activeWait = $activeChallenge ? registration_resend_wait_seconds($conn, (int) $activeChallenge['id'], $activeChallenge['next_resend_at']) : 0;
            if ($activeChallenge && $activeWait > 0) {
                $_SESSION['registration_challenge_id'] = (int) $activeChallenge['id'];
                $step = 'verify';
                $errors[] = registration_rate_error("A verification code was already sent.", $activeWait);
            } else {
                if ($activeChallenge) {
                    mysqli_query($conn, "UPDATE otp_challenges SET consumed_at=NOW() WHERE id=" . (int) $activeChallenge['id']);
                }

                $ipKey = $clientIp ?: 'unknown';
                $phoneLimit = chatweb_rate_limit_hit($conn, 'otp_request_phone', $phone, 3, 3600, 3600);
                $ipLimit = chatweb_rate_limit_hit($conn, 'otp_request_ip', $ipKey, 20, 3600, 900);

                if (!$phoneLimit['allowed']) {
                    $errors[] = registration_rate_error("Too many verification requests for this phone number.", $phoneLimit['retry_after']);
                } elseif (!$ipLimit['allowed']) {
                    $errors[] = registration_rate_error("Too many verification requests from this network.", $ipLimit['retry_after']);
                } else {
                    $otp = chatweb_create_otp_challenge($conn, $email, $phone, $country, $detectedCountry, $clientIp);
                    if (!$otp['ok']) {
                        chatweb_rate_limit_decrement($conn, 'otp_request_phone', $phone);
                        chatweb_rate_limit_decrement($conn, 'otp_request_ip', $ipKey);
                        $errors[] = $otp['message'];
                    } else {
                        $_SESSION['registration_challenge_id'] = (int) $otp['id'];
                        $step = 'verify';
                        $notice = registration_otp_notice($otp);
                    }
                }
            }
        }
    }

    if ($action === 'verify') {
        $challengeId = (int) ($_SESSION['registration_challenge_id'] ?? 0);
        $code = preg_replace('/\D/', '', (string) ($_POST['otp'] ?? ''));

        if ($challengeId <= 0 || !preg_match('/^\d{6}$/', $code)) {
            $errors[] = "Enter the 6 digit verification code.";
        } else {
            $verifyLimit = chatweb_rate_limit_hit($conn, 'otp_verify', $challengeId . '|' . ($clientIp ?: 'unknown'), 8, 900, 900);
        }

        if ($errors) {
            $step = 'verify';
        } elseif (!$verifyLimit['allowed']) {
            $errors[] = registration_rate_error("Too many verification attempts.", $verifyLimit['retry_after']);
        } else {
            $stmt = mysqli_prepare($conn, "SELECT * FROM otp_challenges WHERE id=? AND consumed_at IS NULL LIMIT 1");
            mysqli_stmt_bind_param($stmt, "i", $challengeId);
            mysqli_stmt_execute($stmt);
            $challenge = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$challenge) {
                $errors[] = "Verification session not found. Please start again.";
                $step = 'details';
            } elseif (strtotime($challenge['expires_at']) < time()) {
                $errors[] = "Verification code has expired. Please request a new code.";
            } elseif ((int) $challenge['attempts'] >= (int) $challenge['max_attempts']) {
                $errors[] = "Too many incorrect codes. Please request a new code.";
            } elseif (!password_verify($code, $challenge['otp_hash'])) {
                mysqli_query($conn, "UPDATE otp_challenges SET attempts=attempts+1 WHERE id=" . (int) $challengeId);
                $errors[] = "Invalid verification code.";
            } else {
                $email = $challenge['email'];
                $phone = $challenge['phone_number'];
                $country = $challenge['country'];
                $existingUser = registration_find_user_by_phone($conn, $phone);
                if ($existingUser && !chatweb_user_access_allowed($conn, (int) $existingUser['id'])) {
                    $errors[] = "This account is restricted. Please contact support.";
                    $step = 'verify';
                }
                if (!$errors) {
                $phoneVerified = $challenge['channel'] === 'sms' ? 1 : 0;
                $emailVerified = $challenge['channel'] === 'email' ? 1 : 0;
                $method = $challenge['channel'];

                if ($existingUser) {
                    $newUserId = (int) $existingUser['id'];
                    $stmt = mysqli_prepare($conn, "UPDATE users SET country=?, detected_country=?, ip_address=?, phone_verified=GREATEST(phone_verified, ?), email_verified=GREATEST(email_verified, ?), verification_method=?, last_login=NOW(), last_login_at=NOW(), status='online', last_seen=NOW() WHERE id=?");
                    mysqli_stmt_bind_param($stmt, "sssiisi", $country, $challenge['detected_country'], $challenge['ip_address'], $phoneVerified, $emailVerified, $method, $newUserId);
                    $saved = mysqli_stmt_execute($stmt);
                    $saveError = mysqli_error($conn);
                    mysqli_stmt_close($stmt);
                } else {
                    $username = 'user' . preg_replace('/\D/', '', $phone) . random_int(10, 99);
                    $fullName = 'User ' . substr($phone, -4);
                    $uuid = uniqid("user_", true);
                    $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                    $emailForDb = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;

                    $stmt = mysqli_prepare($conn, "INSERT INTO users (uuid, full_name, username, email, password, profile_image, status, last_seen, is_active, phone, phone_number, country, detected_country, ip_address, phone_verified, email_verified, verification_method, last_login, last_login_at) VALUES (?, ?, ?, ?, ?, '', 'online', NOW(), 1, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    mysqli_stmt_bind_param($stmt, "ssssssssssiis", $uuid, $fullName, $username, $emailForDb, $passwordHash, $phone, $phone, $country, $challenge['detected_country'], $challenge['ip_address'], $phoneVerified, $emailVerified, $method);
                    $saved = mysqli_stmt_execute($stmt);
                    $newUserId = mysqli_insert_id($conn);
                    $saveError = mysqli_error($conn);
                    mysqli_stmt_close($stmt);
                }

                if (!$saved) {
                    $errors[] = $saveError ?: "Could not complete login.";
                } else {
                    mysqli_query($conn, "UPDATE otp_challenges SET consumed_at=NOW() WHERE id=" . (int) $challengeId);
                    unset($_SESSION['registration_challenge_id']);

                    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id=? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, "i", $newUserId);
                    mysqli_stmt_execute($stmt);
                    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);

                    chatweb_load_user_session($conn, $user);
                    chatweb_issue_remember_cookie($conn, $newUserId);
                    if (empty($user['profile_completed'])) {
                        header("Location: " . $setupProfilePath);
                    } else {
                        header("Location: " . $appPath);
                    }
                    exit();
                }
                }
            }
        }
        $step = 'verify';
    }

    if ($action === 'resend') {
        $challengeId = (int) ($_SESSION['registration_challenge_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT * FROM otp_challenges WHERE id=? AND consumed_at IS NULL LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $challengeId);
        mysqli_stmt_execute($stmt);
        $challenge = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$challenge) {
            $errors[] = "Verification session not found. Please start again.";
            $step = 'details';
        } elseif (($resendWait = registration_resend_wait_seconds($conn, (int) $challengeId, $challenge['next_resend_at'])) > 0) {
            $errors[] = registration_rate_error("Please wait before requesting another code.", $resendWait);
            $step = 'verify';
        } else {
            $ipKey = $clientIp ?: 'unknown';
            $phoneLimit = chatweb_rate_limit_hit($conn, 'otp_resend_phone', $challenge['phone_number'], 5, 3600, 3600);
            $ipLimit = chatweb_rate_limit_hit($conn, 'otp_resend_ip', $ipKey, 20, 3600, 900);

            if (!$phoneLimit['allowed']) {
                $errors[] = registration_rate_error("Too many resend requests for this phone number.", $phoneLimit['retry_after']);
            } elseif (!$ipLimit['allowed']) {
                $errors[] = registration_rate_error("Too many resend requests from this network.", $ipLimit['retry_after']);
            } else {
                mysqli_query($conn, "UPDATE otp_challenges SET consumed_at=NOW() WHERE id=" . (int) $challengeId);
                $otp = chatweb_create_otp_challenge($conn, $challenge['email'], $challenge['phone_number'], $challenge['country'], $challenge['detected_country'], $challenge['ip_address']);
                if (!$otp['ok']) {
                    chatweb_rate_limit_decrement($conn, 'otp_resend_phone', $challenge['phone_number']);
                    chatweb_rate_limit_decrement($conn, 'otp_resend_ip', $ipKey);
                    $errors[] = $otp['message'];
                } else {
                    $_SESSION['registration_challenge_id'] = (int) $otp['id'];
                    $notice = registration_otp_notice($otp);
                }
            }
            $step = 'verify';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register | Chat Web</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<style>
*{box-sizing:border-box}html,body{width:100%;max-width:100%;overflow-x:hidden}body{margin:0;min-height:100dvh;font-family:Arial,Helvetica,sans-serif;background:#eef2f7;color:#111827}.auth-shell{min-height:100dvh;display:grid;grid-template-columns:minmax(280px,.9fr) minmax(320px,1.1fr)}.brand-panel{background:#0f172a;color:#fff;padding:clamp(28px,6vw,70px);display:flex;flex-direction:column;justify-content:space-between}.brand-mark{display:flex;align-items:center;gap:12px;font-weight:800;font-size:22px}.brand-mark i{width:44px;height:44px;border-radius:8px;background:#2563eb;display:grid;place-items:center}.brand-panel h1{font-size:clamp(34px,5vw,58px);line-height:1.05;margin:70px 0 18px}.brand-panel p{max-width:520px;color:#cbd5e1;line-height:1.7}.auth-panel{display:grid;place-items:center;padding:clamp(18px,4vw,56px)}.auth-card{width:min(520px,100%);background:#fff;border:1px solid #dbe3ee;border-radius:8px;box-shadow:0 18px 44px rgba(15,23,42,.12);padding:clamp(22px,4vw,34px)}.auth-card h2{margin:0 0 8px;font-size:28px}.muted{margin:0 0 24px;color:#64748b;line-height:1.5}.field{display:grid;gap:8px;margin-bottom:16px}.field label{font-weight:800;font-size:14px}.field input,.field select{width:100%;min-height:46px;border:1px solid #cbd5e1;border-radius:8px;padding:0 13px;font:inherit;background:#fff}.field small{color:#64748b}.btn{width:100%;min-height:48px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:800;cursor:pointer}.btn-soft{background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;margin-top:10px}.alert{padding:12px 14px;border-radius:8px;margin-bottom:16px;line-height:1.45}.alert-error{background:#fee2e2;color:#991b1b}.alert-info{background:#dbeafe;color:#1e3a8a}.register-link{text-align:center;margin-top:18px;color:#64748b}.register-link a{color:#2563eb;font-weight:800;text-decoration:none}.otp-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.field .otp-box{width:100%;height:58px;min-height:58px;padding:0;text-align:center;font-size:26px;font-weight:900;border-radius:10px}.field .otp-box:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.14);outline:none}.details-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.details-grid .field{margin-bottom:0}@media(max-width:820px){.auth-shell{grid-template-columns:1fr}.brand-panel{min-height:260px}.brand-panel h1{margin:36px 0 14px}.details-grid{grid-template-columns:1fr}}@media(max-width:520px){.auth-panel{padding:14px}.auth-card{padding:20px}.otp-grid{gap:7px}.field .otp-box{height:50px;min-height:50px;font-size:22px}}
</style>
</head>
<body>
<div class="auth-shell">
    <section class="brand-panel">
        <div class="brand-mark"><i class="fa-solid fa-comments"></i><span>Chat Web</span></div>
        <div>
            <h1>Create your secure chat account.</h1>
            <p>Verify your phone first. If SMS delivery is unavailable, Chat Web automatically sends the verification code to your email.</p>
        </div>
        <p>Detected country: <?php echo h($detectedCountry ?: 'Not available'); ?></p>
    </section>

    <main class="auth-panel">
        <div class="auth-card">
            <?php if ($step === 'details') { ?>
                <h2>Register</h2>
                <p class="muted">Select country, enter your mobile number, then verify OTP.</p>
            <?php } else { ?>
                <h2>Verify code</h2>
                <p class="muted">Enter the 6 digit code to complete registration.</p>
            <?php } ?>

            <?php foreach ($errors as $error) { ?>
                <div class="alert alert-error"><?php echo h($error); ?></div>
            <?php } ?>

            <?php if ($notice) { ?>
                <div class="alert alert-info"><?php echo h($notice); ?></div>
            <?php } ?>

            <?php if ($step === 'details') { ?>
                <form method="POST">
                    <input type="hidden" name="action" value="start">
                    <div class="field">
                        <label>Country</label>
                        <select name="country" id="country-select" required>
                            <option value="">Select country</option>
                            <?php
                            $selectedCountry = $_POST['country'] ?? $detectedCountry;
                            foreach ($countries as $country) {
                                $selected = $selectedCountry === $country ? 'selected' : '';
                                $dialCode = $countryDialCodes[$country] ?? '';
                                $label = $dialCode ? "$country ($dialCode)" : $country;
                                echo '<option value="' . h($country) . '" data-dial-code="' . h($dialCode) . '" ' . $selected . '>' . h($label) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Mobile phone number</label>
                        <input type="tel" id="phone-number" name="phone_number" value="<?php echo h($_POST['phone_number'] ?? ''); ?>" placeholder="+923001234567" required>
                        <small id="phone-help">Select country to apply its dialing code automatically.</small>
                    </div>
                    <div class="field">
                        <label>Email address <span style="color:#64748b;font-weight:400">(optional fallback)</span></label>
                        <input type="email" name="email" value="<?php echo h($_POST['email'] ?? ''); ?>" placeholder="Only needed if SMS is unavailable">
                    </div>
                    <br>
                    <button class="btn" type="submit">Send verification code</button>
                </form>
            <?php } else { ?>
                <form method="POST">
                    <input type="hidden" name="action" value="verify">
                    <div class="field">
                        <label>Verification code</label>
                        <input type="hidden" id="otp-value" name="otp" required>
                        <div class="otp-grid" aria-label="Enter 6 digit verification code">
                            <input class="otp-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code" aria-label="Digit 1">
                            <input class="otp-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 2">
                            <input class="otp-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 3">
                            <input class="otp-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 4">
                            <input class="otp-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 5">
                            <input class="otp-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 6">
                        </div>
                    </div>
                    <button class="btn" type="submit">Verify and continue</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="action" value="resend">
                    <button class="btn btn-soft" type="submit">Resend code</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="action" value="reset">
                    <button class="btn btn-soft" type="submit">Change phone or email</button>
                </form>
            <?php } ?>

            <div class="register-link">
                Already have an account? <a href="<?php echo h($loginPath); ?>">Login here</a>
            </div>
        </div>
    </main>
</div>
<script>
(function(){
    const boxes = Array.from(document.querySelectorAll(".otp-box"));
    const hidden = document.getElementById("otp-value");
    if(!boxes.length || !hidden) return;

    function sync(){
        hidden.value = boxes.map(box => box.value).join("");
    }

    function fill(value){
        const digits = String(value).replace(/\D/g, "").slice(0, boxes.length).split("");
        boxes.forEach((box, index) => {
            box.value = digits[index] || "";
        });
        sync();
        const next = Math.min(digits.length, boxes.length - 1);
        boxes[next].focus();
    }

    boxes.forEach((box, index) => {
        box.addEventListener("input", function(){
            const digits = box.value.replace(/\D/g, "");
            if(digits.length > 1){
                fill(digits);
                return;
            }
            box.value = digits;
            sync();
            if(digits && boxes[index + 1]){
                boxes[index + 1].focus();
            }
        });

        box.addEventListener("keydown", function(event){
            if(event.key === "Backspace" && !box.value && boxes[index - 1]){
                boxes[index - 1].focus();
            }
            if(event.key === "ArrowLeft" && boxes[index - 1]){
                boxes[index - 1].focus();
            }
            if(event.key === "ArrowRight" && boxes[index + 1]){
                boxes[index + 1].focus();
            }
        });

        box.addEventListener("paste", function(event){
            event.preventDefault();
            fill((event.clipboardData || window.clipboardData).getData("text"));
        });
    });

    boxes[0].focus();
})();

(function(){
    const countrySelect = document.getElementById("country-select");
    const phoneInput = document.getElementById("phone-number");
    const phoneHelp = document.getElementById("phone-help");

    if(!countrySelect || !phoneInput) return;

    function selectedDialCode(){
        const option = countrySelect.options[countrySelect.selectedIndex];
        return option ? option.dataset.dialCode || "" : "";
    }

    function applyDialCode(force){
        const dialCode = selectedDialCode();
        if(!dialCode) return;

        const current = phoneInput.value.trim();
        let localNumber = current.replace(/[^\d]/g, "");
        const numericCode = dialCode.replace(/[^\d]/g, "");
        if(localNumber.indexOf(numericCode) === 0){
            localNumber = localNumber.slice(numericCode.length);
        }
        if(localNumber.indexOf("0") === 0){
            localNumber = localNumber.slice(1);
        }
        const alreadyHasCode = current.indexOf(dialCode) === 0;

        if(force || current === "" || current === "+" || current.match(/^\+\d{1,4}$/) || !alreadyHasCode){
            phoneInput.value = localNumber ? dialCode + localNumber : dialCode;
        }

        phoneInput.placeholder = dialCode + "3001234567".slice(Math.min(dialCode.length - 1, 3));
        phoneHelp.textContent = "Country code " + dialCode + " applied. Enter the remaining mobile number.";
    }

    countrySelect.addEventListener("change", function(){
        applyDialCode(true);
        phoneInput.focus();
        const length = phoneInput.value.length;
        phoneInput.setSelectionRange(length, length);
    });

    phoneInput.addEventListener("focus", function(){
        if(phoneInput.value.trim() === ""){
            applyDialCode(false);
        }
    });

    applyDialCode(false);
})();
</script>
</body>
</html>
