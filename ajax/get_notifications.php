<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$countStmt = mysqli_prepare($conn, "
    SELECT COUNT(*) total
    FROM notifications
    WHERE user_id = ?
    AND is_read = 0
");
mysqli_stmt_bind_param($countStmt, "i", $user_id);
mysqli_stmt_execute($countStmt);
$countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));
$unread = (int) ($countRow['total'] ?? 0);
mysqli_stmt_close($countStmt);

$groupStmt = mysqli_prepare($conn, "
    SELECT
        u.id AS sender_id,
        u.full_name AS sender_name,
        u.profile_image,
        COUNT(*) AS unread_count,
        latest.message_type,
        latest.message,
        latest.attachment,
        latest.created_at AS latest_created_at,
        latest.conversation_id,
        u.status AS sender_status,
        MAX(n.id) AS latest_notification_id
    FROM notifications n
    JOIN messages m ON m.id = n.message_id
    JOIN users u ON u.id = m.sender_id
    JOIN messages latest ON latest.id = (
        SELECT m2.id
        FROM notifications n2
        JOIN messages m2 ON m2.id = n2.message_id
        WHERE n2.user_id = n.user_id
        AND n2.is_read = 0
        AND m2.sender_id = m.sender_id
        AND m2.is_deleted = 0
        ORDER BY n2.id DESC
        LIMIT 1
    )
    WHERE n.user_id = ?
    AND n.is_read = 0
    AND m.is_deleted = 0
    GROUP BY u.id, u.full_name, u.profile_image, u.status, latest.message_type, latest.message, latest.attachment, latest.created_at, latest.conversation_id
    ORDER BY latest_notification_id DESC
");
mysqli_stmt_bind_param($groupStmt, "i", $user_id);
mysqli_stmt_execute($groupStmt);
$groupQuery = mysqli_stmt_get_result($groupStmt);

$unread_groups = [];

while ($row = mysqli_fetch_assoc($groupQuery)) {
    $row['sender_id'] = (int) $row['sender_id'];
    $row['unread_count'] = (int) $row['unread_count'];
    $row['latest_notification_id'] = (int) $row['latest_notification_id'];
    $unread_groups[] = $row;
}

mysqli_stmt_close($groupStmt);

$stmt = mysqli_prepare($conn, "
    SELECT
        n.id,
        n.is_read,
        n.created_at,
        m.id AS message_id,
        m.conversation_id,
        m.message_type,
        m.message,
        m.attachment,
        u.full_name AS sender_name,
        u.profile_image
    FROM notifications n
    JOIN messages m ON m.id = n.message_id
    JOIN users u ON u.id = m.sender_id
    WHERE n.user_id = ?
    AND n.is_read = 0
    AND m.is_deleted = 0
    ORDER BY n.id DESC
    LIMIT 10
");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$query = mysqli_stmt_get_result($stmt);

$notifications = [];

while ($row = mysqli_fetch_assoc($query)) {
    $row['id'] = (int) $row['id'];
    $row['is_read'] = (int) $row['is_read'];
    $notifications[] = $row;
}
mysqli_stmt_close($stmt);

$recentStmt = mysqli_prepare($conn, "
    SELECT
        n.id,
        n.is_read,
        n.created_at,
        m.id AS message_id,
        m.conversation_id,
        m.message_type,
        m.message,
        m.attachment,
        u.full_name AS sender_name,
        u.profile_image,
        u.status AS sender_status
    FROM notifications n
    JOIN messages m ON m.id = n.message_id
    JOIN users u ON u.id = m.sender_id
    WHERE n.user_id = ?
    AND n.is_read = 1
    AND m.is_deleted = 0
    AND n.id IN (
        SELECT MAX(n2.id)
        FROM notifications n2
        JOIN messages m2 ON m2.id = n2.message_id
        WHERE n2.user_id = ?
        AND n2.is_read = 1
        AND m2.is_deleted = 0
        GROUP BY m2.conversation_id
    )
    ORDER BY n.id DESC
    LIMIT 10
");
mysqli_stmt_bind_param($recentStmt, "ii", $user_id, $user_id);
mysqli_stmt_execute($recentStmt);
$recentQuery = mysqli_stmt_get_result($recentStmt);

$recent_notifications = [];

while ($row = mysqli_fetch_assoc($recentQuery)) {
    $row['id'] = (int) $row['id'];
    $row['is_read'] = (int) $row['is_read'];
    $recent_notifications[] = $row;
}
mysqli_stmt_close($recentStmt);

echo json_encode([
    "status" => "success",
    "unread" => $unread,
    "unread_groups" => $unread_groups,
    "notifications" => $notifications,
    "recent_notifications" => $recent_notifications
]);
