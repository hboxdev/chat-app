<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json");

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit();
}

$current_user = (int) $_SESSION['user_id'];
$presence_timeout_seconds = 60;

function effectivePresenceStatus($status, $last_seen, $timeout_seconds = 60)
{
    if ($status === 'away') {
        return 'away';
    }

    if ($status === 'online' && !empty($last_seen)) {
        $timestamp = strtotime($last_seen);

        if ($timestamp && (time() - $timestamp) <= $timeout_seconds) {
            return 'online';
        }
    }

    return 'offline';
}

function presenceText($status, $last_seen, $timeout_seconds = 60, $fallback_seen = null)
{
    $status = effectivePresenceStatus($status, $last_seen, $timeout_seconds);

    if ($status === 'online') {
        return 'online';
    }

    if ($status === 'away') {
        return 'away';
    }

    $seen_time = !empty($last_seen) ? $last_seen : $fallback_seen;
    $timestamp = strtotime($seen_time);

    if (!$timestamp) {
        return 'last seen at ' . date('h:i A');
    }

    $date = date('Y-m-d', $timestamp);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $time = date('h:i A', $timestamp);

    if ($date === $today) {
        return 'last seen today at ' . $time;
    }

    if ($date === $yesterday) {
        return 'last seen yesterday at ' . $time;
    }

    return 'last seen ' . date('M d, Y', $timestamp) . ' at ' . $time;
}

mysqli_query(
    $conn,
    "UPDATE users
     SET status='online', last_seen=NOW()
     WHERE id=$current_user"
);

mysqli_query(
    $conn,
    "UPDATE users
     SET status='offline'
     WHERE id != $current_user
     AND status='online'
     AND (last_seen IS NULL OR last_seen < (NOW() - INTERVAL $presence_timeout_seconds SECOND))"
);

$stmt = mysqli_prepare($conn, "
    SELECT id, status, last_seen, created_at
    FROM users
    WHERE id != ?
    AND is_active = 1
");
mysqli_stmt_bind_param($stmt, "i", $current_user);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$users = [];

while ($user = mysqli_fetch_assoc($result)) {
    $effective_status = effectivePresenceStatus($user['status'] ?: 'offline', $user['last_seen'] ?? null, $presence_timeout_seconds);

    $users[] = [
        "id" => (int) $user['id'],
        "status" => $effective_status,
        "presence" => presenceText($effective_status, $user['last_seen'] ?? null, $presence_timeout_seconds, $user['created_at'] ?? null),
        "last_seen" => $user['last_seen'] ?? null
    ];
}

echo json_encode([
    "status" => "success",
    "users" => $users
]);
?>
