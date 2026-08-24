<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Starred | Chat Web</title>
</head>
<body>
    <h2>Starred</h2>
    <p>Starred messages page is ready.</p>
    <a href="dashboard.php">Back to Dashboard</a>
</body>
</html>
