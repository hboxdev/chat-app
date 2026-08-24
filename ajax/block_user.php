<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$contact_id = isset($_POST['contact_id']) ? (int) $_POST['contact_id'] : 0;
$action = $_POST['action'] ?? 'toggle';

if ($contact_id <= 0 || $contact_id === $user_id) {
    echo json_encode(["status" => "error", "message" => "Invalid user"]);
    exit();
}

$checkStmt = mysqli_prepare($conn, "SELECT is_blocked FROM user_contacts WHERE user_id = ? AND contact_id = ? LIMIT 1");
mysqli_stmt_bind_param($checkStmt, "ii", $user_id, $contact_id);
mysqli_stmt_execute($checkStmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
mysqli_stmt_close($checkStmt);

$current = (int) ($row['is_blocked'] ?? 0);
$blocked = $action === 'block' ? 1 : ($action === 'unblock' ? 0 : ($current ? 0 : 1));

$existingStmt = mysqli_prepare($conn, "SELECT id FROM user_contacts WHERE user_id = ? AND contact_id = ? LIMIT 1");
mysqli_stmt_bind_param($existingStmt, "ii", $user_id, $contact_id);
mysqli_stmt_execute($existingStmt);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($existingStmt));
mysqli_stmt_close($existingStmt);

if ($existing) {
    $existing_id = (int) $existing['id'];
    $stmt = mysqli_prepare($conn, "UPDATE user_contacts SET is_blocked = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $blocked, $existing_id);
} else {
    $stmt = mysqli_prepare($conn, "INSERT INTO user_contacts (user_id, contact_id, is_blocked, created_at) VALUES (?, ?, ?, NOW())");
    mysqli_stmt_bind_param($stmt, "iii", $user_id, $contact_id, $blocked);
}

$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode([
    "status" => $ok ? "success" : "error",
    "blocked" => (bool) $blocked,
    "message" => $ok ? ($blocked ? "User blocked" : "User unblocked") : mysqli_error($conn)
]);
?>
