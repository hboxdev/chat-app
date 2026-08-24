<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include __DIR__ . "/../config/config.php";

$error = "";

if (isset($_POST['login'])) {

    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = trim($_POST['password']);

    $sql = "SELECT * FROM users WHERE email='$email' LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        die(mysqli_error($conn));
    }

    if (mysqli_num_rows($result) == 1) {

        $user = mysqli_fetch_assoc($result);

        // Password Verify
        if (password_verify($password, $user['password'])) {

            mysqli_query($conn, "UPDATE users SET status='online', last_seen=NOW() WHERE id=" . (int)$user['id']);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['uuid'] = $user['uuid'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['profile_image'] = $user['profile_image'];
            $_SESSION['status'] = 'online';


//             echo "<pre>";
// print_r($user);

// echo "<br><br>";

// print_r($_SESSION);
// exit();

           header("Location: dashboard.php");
exit();

        } else {

            $error = "Invalid Password.";

        }

    } else {

        $error = "Email not found.";

    }

}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container mt-5">
    <div class="row justify-content-center">

        <div class="col-md-4">

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
