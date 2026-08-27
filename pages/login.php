<?php
require_once __DIR__ . "/../config/session.php";
include __DIR__ . "/../config/config.php";

chatweb_ensure_auth_schema($conn);
chatweb_redirect_if_logged_in($conn, "../app/");

$error = "";

if (isset($_POST['login'])) {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid email address.";
    } elseif (!chatweb_rate_limit($conn, 'login', $email . '|' . chatweb_client_ip(), 8, 900, 900)) {
        $error = "Too many login attempts. Please try again later.";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) == 1) {

            $user = mysqli_fetch_assoc($result);

            if (password_verify($password, $user['password'])) {

                if (!chatweb_user_access_allowed($conn, (int) $user['id'])) {
                    $error = "This account is restricted. Please contact support.";
                } else {
                chatweb_load_user_session($conn, $user);
                chatweb_issue_remember_cookie($conn, (int) $user['id']);
                header("Location: ../app/");
                exit();
                }

            } else {

                $error = "Invalid email or password.";

            }

        } else {

            $error = "Invalid email or password.";

        }
        mysqli_stmt_close($stmt);
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
    </style>
</head>

<body class="bg-light">

<div class="container auth-wrap">
    <div class="row justify-content-center">

        <div class="col-12 auth-card">

            <div class="card shadow">
                <div class="card-body">

                    <h3 class="text-center mb-4">Sign In</h3>

                    <?php if(!empty($error)){ ?>
                        <div class="alert alert-danger">
                            <?php echo $error; ?>
                        </div>
                    <?php } ?>

                    <form method="POST">

                        <div class="mb-3">
                            <label>Email Address</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label>Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>

                        <button type="submit" name="login" class="btn btn-primary w-100">
                            Login
                        </button>

                        <?php if(!empty($error)){ ?>
                            <div style="background:#ffe5e5;color:#d8000c;padding:10px;border-radius:5px;margin-bottom:15px;">
                                <?php echo $error; ?>
                            </div>
                            <?php } ?>

                    </form>

                </div>
            </div>

        </div>

    </div>
</div>

</body>
</html>
