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
    <title>Contacts | Chat Web</title>
    <style>
        *{box-sizing:border-box}html,body{width:100%;max-width:100%;overflow-x:hidden}body{margin:0;min-height:100dvh;font-family:Arial,Helvetica,sans-serif;background:#eef2f7;color:#111827;display:grid;place-items:center;padding:clamp(16px,4vw,40px)}main{width:min(520px,100%);background:#fff;border:1px solid #dbe3ee;border-radius:16px;padding:clamp(20px,4vw,32px);box-shadow:0 18px 44px rgba(15,23,42,.08)}h2{margin:0 0 8px;font-size:clamp(24px,4vw,32px)}p{color:#64748b;line-height:1.5}a{display:inline-flex;min-height:44px;align-items:center;justify-content:center;padding:0 16px;border-radius:12px;background:#2563eb;color:#fff;text-decoration:none;font-weight:700}
    </style>
</head>
<body>
<main>
    <h2>Contacts</h2>
    <p>Contacts page is ready.</p>
    <a href="dashboard.php">Back to Dashboard</a>
</main>
</body>
</html>
