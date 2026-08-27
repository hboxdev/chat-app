<?php
require_once __DIR__ . "/config/session.php";
include "config/config.php";

chatweb_ensure_auth_schema($conn);
chatweb_redirect_if_logged_in($conn, "app/");

$error = "";

if(isset($_POST['login']))
{
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

        if(mysqli_num_rows($result) == 1)
        {
            $user = mysqli_fetch_assoc($result);

            if(password_verify($password, $user['password']))
            {
                if (!chatweb_user_access_allowed($conn, (int) $user['id'])) {
                    $error = "This account is restricted. Please contact support.";
                } else {
                chatweb_load_user_session($conn, $user);
                chatweb_issue_remember_cookie($conn, (int) $user['id']);
                header("Location: app/");
                exit();
                }
            }
            else
            {
                $error = "Invalid email or password.";
            }
        }
        else
        {
            $error = "Invalid email or password.";
        }

        mysqli_stmt_close($stmt);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Chat Web - Login</title>

<link rel="stylesheet" href="assets/css/style.css">

<!-- Font Awesome -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

<style>

.password-box{
    position:relative;
}

.password-box input{
    width:100%;
    padding-right:45px;
}

.password-box i{
    position:absolute;
    right:15px;
    top:50%;
    transform:translateY(-50%);
    cursor:pointer;
    color:#777;
}

</style>

</head>

<body>

<div class="container">

    <div class="left-panel">

        <div class="logo">💬</div>

        <h1>Chat Web</h1>

        <p>
            Welcome Back! <br>
            Login to continue chatting with your friends and team.
        </p>

    </div>

    <div class="right-panel">

        <div class="login-box">

            <h2>Sign In</h2>

            <form method="POST">

                <div class="input-group">

                    <label>Email Address</label>

                    <input
                        type="email"
                        name="email"
                        placeholder="Enter Email"
                        required>

                </div>

                <div class="input-group">

                    <label>Password</label>

                    <div class="password-box">

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter Password"
                            required>

                        <i class="fa-solid fa-eye" id="togglePassword"></i>

                    </div>

                </div>

                <div class="options">

                    <label>
                        <input type="checkbox">
                        Remember Me
                    </label>

                    <a href="#">Forgot Password?</a>

                </div>

                <button type="submit" name="login" class="login-btn">
                    Login
                </button>

            </form>

            <div class="register">

                Don't have an account?

                <a href="pages/register.php">
                    Register Now
                </a>

            </div>

        </div>

    </div>

</div>

<?php if(!empty($error)){ ?>

<div style="background:#ffdddd;color:red;padding:10px;margin-bottom:15px;border-radius:5px;">
    <?php echo $error; ?>
</div>

<?php } ?>

<script>

const togglePassword=document.getElementById("togglePassword");
const password=document.getElementById("password");

togglePassword.addEventListener("click",function(){

    if(password.type==="password")
    {
        password.type="text";
        this.classList.remove("fa-eye");
        this.classList.add("fa-eye-slash");
    }
    else
    {
        password.type="password";
        this.classList.remove("fa-eye-slash");
        this.classList.add("fa-eye");
    }

});

</script>

</body>
</html>
