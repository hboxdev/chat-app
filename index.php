<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include "config/config.php";

$error = "";

if(isset($_POST['login']))
{
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = trim($_POST['password']);

    $sql = "SELECT * FROM users WHERE email='$email' LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if(mysqli_num_rows($result) == 1)
    {
        $user = mysqli_fetch_assoc($result);

        if(password_verify($password, $user['password']))
        {
            mysqli_query($conn, "UPDATE users SET status='online', last_seen=NOW() WHERE id=" . (int)$user['id']);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['uuid'] = $user['uuid'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['profile_image'] = $user['profile_image'];
            $_SESSION['status'] = 'online';
            $_SESSION['is_active'] = $user['is_active'];

            header("Location: pages/dashboard.php");
            exit();
        }
        else
        {
            $error = "Invalid password.";
        }
    }
    else
    {
        $error = "Email not found.";
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
