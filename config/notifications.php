<?php
function createMessageNotifications($conn, $conversation_id, $sender_id, $message_id)
{
    $memberStmt = mysqli_prepare($conn, "
        SELECT user_id
        FROM conversation_members
        WHERE conversation_id = ?
        AND user_id != ?
    ");

    if (!$memberStmt) {
        return;
    }

    mysqli_stmt_bind_param($memberStmt, "ii", $conversation_id, $sender_id);
    mysqli_stmt_execute($memberStmt);
    $members = mysqli_stmt_get_result($memberStmt);

    $notifyStmt = mysqli_prepare($conn, "
        INSERT INTO notifications (user_id, message_id, is_read, created_at)
        VALUES (?, ?, 0, NOW())
    ");

    if (!$notifyStmt) {
        mysqli_stmt_close($memberStmt);
        return;
    }

    while ($member = mysqli_fetch_assoc($members)) {
        $receiver_id = (int) $member['user_id'];
        mysqli_stmt_bind_param($notifyStmt, "ii", $receiver_id, $message_id);
        mysqli_stmt_execute($notifyStmt);
    }

    mysqli_stmt_close($notifyStmt);
    mysqli_stmt_close($memberStmt);
}
