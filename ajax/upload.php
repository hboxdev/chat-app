<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include __DIR__ . "/../config/config.php";
include __DIR__ . "/../config/notifications.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit();
}

$sender_id = (int) $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;
$is_view_once = isset($_POST['is_view_once']) ? (int) $_POST['is_view_once'] : 0;
$is_view_once = $is_view_once === 1 ? 1 : 0;

if ($conversation_id <= 0 || !isset($_FILES['file'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing upload data"
    ]);
    exit();
}

$memberStmt = mysqli_prepare($conn, "
    SELECT id
    FROM conversation_members
    WHERE conversation_id = ? AND user_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($memberStmt, "ii", $conversation_id, $sender_id);
mysqli_stmt_execute($memberStmt);
mysqli_stmt_store_result($memberStmt);

if (mysqli_stmt_num_rows($memberStmt) === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Conversation access denied"
    ]);
    exit();
}

mysqli_stmt_close($memberStmt);

$blockStmt = mysqli_prepare($conn, "
    SELECT uc.id
    FROM conversation_members cm
    JOIN user_contacts uc
        ON (
            (uc.user_id = ? AND uc.contact_id = cm.user_id)
            OR (uc.user_id = cm.user_id AND uc.contact_id = ?)
        )
        AND uc.is_blocked = 1
    WHERE cm.conversation_id = ?
    AND cm.user_id != ?
    LIMIT 1
");
mysqli_stmt_bind_param($blockStmt, "iiii", $sender_id, $sender_id, $conversation_id, $sender_id);
mysqli_stmt_execute($blockStmt);
mysqli_stmt_store_result($blockStmt);

if (mysqli_stmt_num_rows($blockStmt) > 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Uploads are blocked in this conversation"
    ]);
    mysqli_stmt_close($blockStmt);
    exit();
}

mysqli_stmt_close($blockStmt);

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        "status" => "error",
        "message" => "File upload failed"
    ]);
    exit();
}

$maxSize = 25 * 1024 * 1024;

if ($file['size'] > $maxSize) {
    echo json_encode([
        "status" => "error",
        "message" => "File is too large"
    ]);
    exit();
}

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$mimeType = strtolower((string) ($file['type'] ?? ''));
$allowed = [
    'jpg', 'jpeg', 'png', 'gif', 'webp',
    'mp4', 'webm', 'mov',
    'mp3', 'wav', 'ogg', 'm4a',
    'pdf', 'doc', 'docx', 'txt', 'zip', 'rar',
    'xls', 'xlsx', 'ppt', 'pptx'
];

if (!in_array($extension, $allowed, true)) {
    echo json_encode([
        "status" => "error",
        "message" => "File type is not allowed"
    ]);
    exit();
}

$uploadDir = __DIR__ . "/../uploads";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$safeName = time() . "_" . bin2hex(random_bytes(6)) . "." . $extension;
$targetPath = $uploadDir . "/" . $safeName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode([
        "status" => "error",
        "message" => "File could not be saved"
    ]);
    exit();
}

$messageType = 'file';

if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
    $messageType = 'image';
} elseif (str_starts_with($mimeType, 'audio/') || in_array($extension, ['mp3', 'wav', 'ogg', 'm4a'], true)) {
    $messageType = 'audio';
} elseif (in_array($extension, ['mp4', 'webm', 'mov'], true)) {
    $messageType = 'video';
}
$attachment = "uploads/" . $safeName;

$stmt = mysqli_prepare($conn, "
    INSERT INTO messages (conversation_id, sender_id, message_type, message, attachment, is_view_once, created_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");

$message = $messageType === 'audio' && str_starts_with($file['name'], 'voice_note_')
    ? ''
    : $file['name'];
mysqli_stmt_bind_param($stmt, "iisssi", $conversation_id, $sender_id, $messageType, $message, $attachment, $is_view_once);

if (mysqli_stmt_execute($stmt)) {
    $message_id = mysqli_insert_id($conn);
    createMessageNotifications($conn, $conversation_id, $sender_id, $message_id);

    echo json_encode([
        "status" => "success",
        "message" => "File uploaded",
        "attachment" => $attachment
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => mysqli_error($conn)
    ]);
}

mysqli_stmt_close($stmt);
