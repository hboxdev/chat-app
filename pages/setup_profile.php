<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/admin_helpers.php";

chatweb_ensure_admin_schema($conn);
chatweb_require_login($conn, '../index.php');

$userId = (int) $_SESSION['user_id'];
$errors = [];
$notice = '';

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function setup_clean_name($name)
{
    return trim(preg_replace('/\s+/', ' ', (string) $name));
}

function setup_next_step($user)
{
    if (empty($user['full_name']) || str_starts_with((string) $user['full_name'], 'User ')) {
        return 'name';
    }
    if (empty($user['username_normalized'])) {
        return 'username';
    }
    if (empty($user['onboarding_completed'])) {
        return 'photo';
    }
    return 'complete';
}

function setup_save_avatar($userId)
{
    if (empty($_FILES['profile_image']['name']) || !is_uploaded_file($_FILES['profile_image']['tmp_name'])) {
        return '';
    }

    if ((int) ($_FILES['profile_image']['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Profile photo must be 3 MB or smaller.');
    }

    $info = getimagesize($_FILES['profile_image']['tmp_name']);
    if (!$info) {
        throw new RuntimeException('Upload a valid JPEG, PNG, or WebP image.');
    }

    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!isset($allowed[$info[2]])) {
        throw new RuntimeException('Only JPEG, PNG, and WebP images are supported.');
    }

    if ($info[0] < 120 || $info[1] < 120) {
        throw new RuntimeException('Profile photo must be at least 120 x 120 pixels.');
    }

    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $fileName = 'profile_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $dir . '/' . $fileName)) {
        throw new RuntimeException('Could not save the profile photo.');
    }

    return $fileName;
}

mysqli_query($conn, "INSERT IGNORE INTO user_profiles (user_id) VALUES ($userId)");
$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, full_name, username, username_normalized, profile_image, onboarding_step, onboarding_completed, profile_completed, phone_verified, email_verified FROM users WHERE id=$userId LIMIT 1")) ?: [];

if (!empty($user['onboarding_completed'])) {
    header("Location: ../app/");
    exit();
}

$step = $_GET['step'] ?? ($user['onboarding_step'] ?: setup_next_step($user));
$validSteps = ['name', 'username', 'photo'];
if (!in_array($step, $validSteps, true)) {
    $step = setup_next_step($user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'back') {
        $step = $_POST['to'] ?? 'name';
        header("Location: setup_profile.php?step=" . urlencode($step));
        exit();
    }

    if ($action === 'save_name') {
        $fullName = setup_clean_name($_POST['full_name'] ?? '');
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 80) {
            $errors[] = 'Full name must be between 2 and 80 characters.';
            $step = 'name';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE users SET full_name=?, onboarding_step='username' WHERE id=?");
            mysqli_stmt_bind_param($stmt, "si", $fullName, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "UPDATE user_profiles SET display_name=? WHERE user_id=?");
            mysqli_stmt_bind_param($stmt, "si", $fullName, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $_SESSION['full_name'] = $fullName;
            header("Location: setup_profile.php?step=username");
            exit();
        }
    }

    if ($action === 'save_username') {
        $rawUsername = $_POST['username'] ?? '';
        $result = chatweb_validate_username($conn, $rawUsername, $userId);
        if (!$result['available']) {
            $errors[] = $result['message'] === 'Username already taken.'
                ? 'Sorry, this username was just taken. Please choose another one.'
                : $result['message'];
            $step = 'username';
        } else {
            $normalized = $result['normalized'];
            $stmt = mysqli_prepare($conn, "UPDATE users SET username=?, username_normalized=?, onboarding_step='photo' WHERE id=?");
            mysqli_stmt_bind_param($stmt, "ssi", $normalized, $normalized, $userId);
            if (!mysqli_stmt_execute($stmt)) {
                $errors[] = 'Sorry, this username was just taken. Please choose another one.';
                $step = 'username';
            } else {
                $_SESSION['username'] = $normalized;
                header("Location: setup_profile.php?step=photo");
                exit();
            }
            mysqli_stmt_close($stmt);
        }
    }

    if ($action === 'finish_photo') {
        try {
            $avatar = setup_save_avatar($userId);
            if ($avatar !== '') {
                $stmt = mysqli_prepare($conn, "UPDATE users SET profile_image=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, "si", $avatar, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare($conn, "UPDATE user_profiles SET avatar=? WHERE user_id=?");
                mysqli_stmt_bind_param($stmt, "si", $avatar, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['profile_image'] = $avatar;
            }

            $fresh = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name, username_normalized, phone_verified, email_verified FROM users WHERE id=$userId LIMIT 1")) ?: [];
            if (empty($fresh['full_name']) || empty($fresh['username_normalized']) || (!((int) $fresh['phone_verified']) && !((int) $fresh['email_verified']))) {
                $errors[] = 'Complete the required setup steps before opening WebChat.';
                $step = setup_next_step($fresh);
            } else {
                mysqli_query($conn, "UPDATE users SET onboarding_completed=1, profile_completed=1, onboarding_step='complete' WHERE id=$userId");
                mysqli_query($conn, "UPDATE user_profiles SET setup_completed_at=NOW() WHERE user_id=$userId");
                header("Location: ../app/");
                exit();
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
            $step = 'photo';
        }
    }

    $user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, full_name, username, username_normalized, profile_image, onboarding_step, onboarding_completed, profile_completed, phone_verified, email_verified FROM users WHERE id=$userId LIMIT 1")) ?: [];
}

$stepIndex = ['name' => 1, 'username' => 2, 'photo' => 3][$step] ?? 1;
$avatarUrl = !empty($user['profile_image']) ? '../uploads/' . $user['profile_image'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Setup | Chat Web</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100dvh;font-family:Arial,Helvetica,sans-serif;background:#eef2f7;color:#111827;display:grid;place-items:center;padding:18px}.card{width:min(500px,100%);background:#fff;border:1px solid #dbe3ee;border-radius:8px;box-shadow:0 18px 44px rgba(15,23,42,.12);padding:clamp(22px,5vw,38px)}.progress{display:flex;gap:8px;margin-bottom:26px}.dot{flex:1;height:6px;border-radius:999px;background:#e5e7eb}.dot.done{background:#2563eb}.back{width:42px;height:42px;border:1px solid #dbe3ee;border-radius:50%;background:#fff;color:#111827;display:inline-grid;place-items:center;margin-bottom:18px;cursor:pointer}h1{margin:0 0 8px;font-size:30px}.muted{color:#64748b;line-height:1.55;margin:0 0 24px}.field{display:grid;gap:8px;margin-bottom:16px}.field label{font-weight:800;font-size:14px}.field input{width:100%;min-height:52px;border:1px solid #cbd5e1;border-radius:8px;padding:0 14px;font:inherit;font-size:17px}.username-wrap{position:relative}.username-wrap span{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b;font-weight:800}.username-wrap input{padding-left:34px;text-transform:lowercase}.btn{width:100%;min-height:52px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:900;cursor:pointer;font-size:15px}.btn-soft{background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;margin-top:10px}.alert{padding:12px 14px;border-radius:8px;background:#fee2e2;color:#991b1b;margin-bottom:16px}.hint{font-size:13px;color:#64748b}.availability{min-height:22px;font-size:14px;font-weight:800}.availability.ok{color:#15803d}.availability.bad{color:#b91c1c}.avatar-zone{display:grid;place-items:center;gap:14px;margin:20px 0}.avatar{width:150px;height:150px;border-radius:50%;border:2px dashed #bfdbfe;background:#eff6ff;color:#2563eb;display:grid;place-items:center;overflow:hidden;cursor:pointer}.avatar img{width:100%;height:100%;object-fit:cover}.avatar i{font-size:48px}.photo-actions{display:flex;gap:10px;width:100%}.photo-actions button{flex:1}@media(max-width:520px){.card{padding:20px}.photo-actions{display:grid}.avatar{width:132px;height:132px}}
</style>
</head>
<body>
<main class="card">
    <div class="progress">
        <span class="dot <?php echo $stepIndex >= 1 ? 'done' : ''; ?>"></span>
        <span class="dot <?php echo $stepIndex >= 2 ? 'done' : ''; ?>"></span>
        <span class="dot <?php echo $stepIndex >= 3 ? 'done' : ''; ?>"></span>
    </div>

    <?php foreach ($errors as $error) { ?><div class="alert"><?php echo h($error); ?></div><?php } ?>

    <?php if ($step === 'name') { ?>
        <h1>What's your name?</h1>
        <p class="muted">Enter your full name so people can recognize you.</p>
        <form method="POST">
            <input type="hidden" name="action" value="save_name">
            <div class="field">
                <label>Full Name</label>
                <input type="text" name="full_name" maxlength="80" value="<?php echo h($_POST['full_name'] ?? ($user['full_name'] ?? '')); ?>" required autofocus>
            </div>
            <button class="btn" type="submit">Continue</button>
        </form>
    <?php } ?>

    <?php if ($step === 'username') { ?>
        <form method="POST"><input type="hidden" name="action" value="back"><input type="hidden" name="to" value="name"><button class="back" type="submit"><i class="fa-solid fa-arrow-left"></i></button></form>
        <h1>Choose a username</h1>
        <p class="muted">Your username is unique and can be used to find you on WebChat.</p>
        <form method="POST" id="username-form">
            <input type="hidden" name="action" value="save_username">
            <div class="field">
                <label>Username</label>
                <div class="username-wrap"><span>@</span><input id="username-input" type="text" name="username" maxlength="24" value="<?php echo h($_POST['username'] ?? ($user['username_normalized'] ?? '')); ?>" autocomplete="off" required></div>
                <div id="username-status" class="availability"></div>
                <small class="hint">Use 3-24 letters, numbers, underscore, or period.</small>
            </div>
            <button class="btn" id="username-submit" type="submit">Continue</button>
        </form>
    <?php } ?>

    <?php if ($step === 'photo') { ?>
        <form method="POST"><input type="hidden" name="action" value="back"><input type="hidden" name="to" value="username"><button class="back" type="submit"><i class="fa-solid fa-arrow-left"></i></button></form>
        <h1>Add a profile photo</h1>
        <p class="muted">Choose a photo that works well as a circular avatar. You can skip this step.</p>
        <form method="POST" enctype="multipart/form-data" id="photo-form">
            <input type="hidden" name="action" value="finish_photo">
            <input type="file" id="profile-file" name="profile_image" accept="image/jpeg,image/png,image/webp" hidden>
            <div class="avatar-zone">
                <button class="avatar" type="button" id="avatar-button">
                    <?php if ($avatarUrl) { ?><img id="avatar-preview" src="<?php echo h($avatarUrl); ?>" alt="Profile preview"><?php } else { ?><i id="avatar-empty" class="fa-solid fa-camera"></i><img id="avatar-preview" src="" alt="" style="display:none"><?php } ?>
                </button>
                <div class="photo-actions">
                    <button class="btn btn-soft" type="button" id="change-photo">Change</button>
                    <button class="btn btn-soft" type="button" id="remove-photo">Remove</button>
                </div>
                <small class="hint">JPEG, PNG, or WebP. Max 3 MB.</small>
            </div>
            <button class="btn" type="submit">Finish</button>
            <button class="btn btn-soft" type="submit" name="skip_photo" value="1">Skip</button>
        </form>
    <?php } ?>
</main>

<script>
(function(){
    const input = document.getElementById("username-input");
    const status = document.getElementById("username-status");
    const submit = document.getElementById("username-submit");
    let timer = null;

    function setStatus(text, ok){
        if(!status) return;
        status.textContent = text;
        status.className = "availability " + (ok ? "ok" : "bad");
        if(submit) submit.disabled = ok === false;
    }

    if(input){
        input.addEventListener("input", function(){
            input.value = input.value.toLowerCase().replace(/[^a-z0-9_.]/g, "");
            clearTimeout(timer);
            if(input.value.length < 3){
                setStatus("Username is too short.", false);
                return;
            }
            status.textContent = "Checking...";
            status.className = "availability";
            timer = setTimeout(function(){
                fetch("../ajax/check_username.php?username=" + encodeURIComponent(input.value), {credentials:"same-origin"})
                    .then(r => r.json())
                    .then(data => setStatus(data.message, !!data.available))
                    .catch(() => setStatus("Could not check username right now.", false));
            }, 400);
        });
        if(input.value) input.dispatchEvent(new Event("input"));
    }

    const file = document.getElementById("profile-file");
    const preview = document.getElementById("avatar-preview");
    const empty = document.getElementById("avatar-empty");
    const avatarButton = document.getElementById("avatar-button");
    const change = document.getElementById("change-photo");
    const remove = document.getElementById("remove-photo");

    function openPicker(){ if(file) file.click(); }
    if(avatarButton) avatarButton.addEventListener("click", openPicker);
    if(change) change.addEventListener("click", openPicker);
    if(remove) remove.addEventListener("click", function(){
        if(file) file.value = "";
        if(preview){ preview.src = ""; preview.style.display = "none"; }
        if(empty) empty.style.display = "block";
    });
    if(file){
        file.addEventListener("change", function(){
            const selected = file.files && file.files[0];
            if(!selected) return;
            if(!["image/jpeg","image/png","image/webp"].includes(selected.type) || selected.size > 3 * 1024 * 1024){
                alert("Choose a JPEG, PNG, or WebP image under 3 MB.");
                file.value = "";
                return;
            }
            const reader = new FileReader();
            reader.onload = e => {
                if(preview){ preview.src = e.target.result; preview.style.display = "block"; }
                if(empty) empty.style.display = "none";
            };
            reader.readAsDataURL(selected);
        });
    }
})();
</script>
</body>
</html>
