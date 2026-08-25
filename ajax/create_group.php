<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Login required"
    ]);
    exit();
}

$current_user = (int) $_SESSION['user_id'];
$title = trim($_POST['title'] ?? '');
$members = $_POST['members'] ?? [];

if (!is_array($members)) {
    $members = [$members];
}

$memberIds = [];

foreach ($members as $memberId) {
    $memberId = (int) $memberId;

    if ($memberId > 0 && $memberId !== $current_user) {
        $memberIds[$memberId] = $memberId;
    }
}

$memberIds = array_values($memberIds);

if ($title === '') {
    echo json_encode([
        "status" => "error",
        "message" => "Group name is required"
    ]);
    exit();
}

if (strlen($title) > 120) {
    $title = substr($title, 0, 120);
}

if (count($memberIds) < 2) {
    echo json_encode([
        "status" => "error",
        "message" => "Select at least 2 members"
    ]);
    exit();
}

$activeIds = [];
$activeStmt = mysqli_prepare($conn, "
    SELECT id
    FROM users
    WHERE id = ?
    AND is_active = 1
    LIMIT 1
");

foreach ($memberIds as $memberId) {
    mysqli_stmt_bind_param($activeStmt, "i", $memberId);
    mysqli_stmt_execute($activeStmt);
    $activeResult = mysqli_stmt_get_result($activeStmt);

    if ($row = mysqli_fetch_assoc($activeResult)) {
        $activeIds[(int) $row['id']] = (int) $row['id'];
    }
}

mysqli_stmt_close($activeStmt);
$memberIds = array_values($activeIds);

if (count($memberIds) < 2) {
    echo json_encode([
        "status" => "error",
        "message" => "Selected members are not available"
    ]);
    exit();
}

mysqli_begin_transaction($conn);

try {
    $conversationStmt = mysqli_prepare($conn, "
        INSERT INTO conversations (type, title, created_by, created_at)
        VALUES ('group', ?, ?, NOW())
    ");
    mysqli_stmt_bind_param($conversationStmt, "si", $title, $current_user);
    mysqli_stmt_execute($conversationStmt);
    $conversation_id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($conversationStmt);

    $memberStmt = mysqli_prepare($conn, "
        INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
        VALUES (?, ?, ?, NOW())
    ");

    $role = 'admin';
    mysqli_stmt_bind_param($memberStmt, "iis", $conversation_id, $current_user, $role);
    mysqli_stmt_execute($memberStmt);

    $role = 'member';

    foreach ($memberIds as $memberId) {
        mysqli_stmt_bind_param($memberStmt, "iis", $conversation_id, $memberId, $role);
        mysqli_stmt_execute($memberStmt);
    }

    mysqli_stmt_close($memberStmt);
    mysqli_commit($conn);

    echo json_encode([
        "status" => "success",
        "message" => "Group created",
        "conversation_id" => $conversation_id,
        "title" => $title
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);

    echo json_encode([
        "status" => "error",
        "message" => "Group could not be created"
    ]);
}
