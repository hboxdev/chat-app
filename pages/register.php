<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config/config.php";

$error = "";

if(isset($_POST['register']))
{
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if($password != $confirm_password)
    {
        $error = "Passwords do not match.";
    }
    else
    {
        $check = mysqli_query($conn, "SELECT id FROM users WHERE email='$email'");

        if(mysqli_num_rows($check) > 0)
        {
            $error = "Email already exists.";
        }
        else
        {
            $uuid = uniqid("user_", true);

            $hashPassword = password_hash($password, PASSWORD_DEFAULT);

            $profile_image = "";

            if(isset($_FILES['profile_picture']) && $_FILES['profile_picture']['name'] != "")
            {
                $profile_image = time()."_".basename($_FILES['profile_picture']['name']);

                if(!is_dir("../uploads")){
                    mkdir("../uploads",0777,true);
                }

                move_uploaded_file(
                    $_FILES['profile_picture']['tmp_name'],
                    "../uploads/".$profile_image
                );
            }

            $status = "online";
            $is_active = 1;

            $insert = mysqli_query($conn,"
    INSERT INTO users
    (
        full_name,
        uuid,
        username,
        email,
        password,
        profile_image,
        status,
        last_seen,
        is_active
    )
    VALUES
    (
        '$full_name',
        '$uuid',
        '$username',
        '$email',
        '$hashPassword',
        '$profile_image',
        '$status',
        NOW(),
        '$is_active'
    )
");

            if($insert)
            {
                header("Location: ../index.php");
                exit();
            }
            else
            {
                $error = mysqli_error($conn);
            }
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

<link rel="stylesheet" href="../assets/css/style.css">

</head>

<body>

<div class="container">

    <div class="left-panel">

        <div class="logo">💬</div>

        <h1>Chat Web</h1>

        <p>

            Create your account and start chatting
            with your friends anywhere anytime.

        </p>

    </div>

    <div class="right-panel">

        <div class="login-box">

            <h2>Create Account</h2>

            <form action="" method="POST" enctype="multipart/form-data">

                <div class="input-group">

                    <label>Full Name</label>

                    <input
                    type="text"
                    name="full_name"
                    placeholder="Enter Full Name"
                    required>

                </div>

                <div class="input-group">

                    <label>Username</label>

                    <input
                    type="text"
                    name="username"
                    placeholder="Choose Username"
                    required>

                </div>

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

                    <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter Password"
                    required>

                </div>

                <div class="input-group">

                    <label>Confirm Password</label>

                    <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Confirm Password"
                    required>

                </div>

                <div class="input-group">

                    <label>Profile Picture</label>

                    <input
                    type="file"
                    name="profile_picture"
                    accept="image/*">

                </div>

                <button type="submit" name="register" class="login-btn">
    Create Account
</button>

<?php if($error!=""){ ?>
<div style="background:#ffdede;padding:10px;color:red;margin-bottom:15px;border-radius:5px;">
    <?php echo $error; ?>
</div>
<?php } ?>

            </form>

            <div class="register">

                Already have an account?

                <a href="../index.php">

                    Login Here

                </a>

            </div>

        </div>

    </div>

</div>

<script src="../assets/js/app.js"></script>

</body>
</html>
