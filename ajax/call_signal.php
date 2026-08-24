<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Login required"]);
    exit();
}

$current_user = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? 'poll';

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS call_sessions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT NOT NULL,
        caller_id INT NOT NULL,
        receiver_id INT NOT NULL,
        call_type VARCHAR(10) NOT NULL DEFAULT 'audio',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        offer MEDIUMTEXT NULL,
        answer MEDIUMTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        ended_at DATETIME NULL,
        INDEX idx_call_lookup (conversation_id, status),
        INDEX idx_call_receiver (receiver_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS call_ice_candidates (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        call_id BIGINT NOT NULL,
        sender_id INT NOT NULL,
        recipient_id INT NOT NULL,
        candidate MEDIUMTEXT NOT NULL,
        delivered TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ice_delivery (call_id, recipient_id, delivered)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function ensure_call_column($conn, $column, $definition)
{
    $column = mysqli_real_escape_string($conn, $column);
    $exists = mysqli_query($conn, "SHOW COLUMNS FROM call_sessions LIKE '$column'");

    if ($exists && mysqli_num_rows($exists) === 0) {
        mysqli_query($conn, "ALTER TABLE call_sessions ADD COLUMN `$column` $definition");
    }
}

ensure_call_column($conn, "upgrade_offer", "MEDIUMTEXT NULL");
ensure_call_column($conn, "upgrade_answer", "MEDIUMTEXT NULL");
ensure_call_column($conn, "upgrade_requested_by", "INT NULL");
ensure_call_column($conn, "upgrade_status", "VARCHAR(20) NULL");

function json_error($message)
{
    echo json_encode(["status" => "error", "message" => $message]);
    exit();
}

function fetch_call($conn, $call_id, $current_user)
{
    $stmt = mysqli_prepare($conn, "
        SELECT cs.*, cu.full_name AS caller_name, ru.full_name AS receiver_name
        FROM call_sessions cs
        JOIN users cu ON cu.id = cs.caller_id
        JOIN users ru ON ru.id = cs.receiver_id
        WHERE cs.id = ?
        AND (cs.caller_id = ? OR cs.receiver_id = ?)
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "iii", $call_id, $current_user, $current_user);
    mysqli_stmt_execute($stmt);
    $call = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $call ?: null;
}

function member_exists($conn, $conversation_id, $user_id)
{
    $stmt = mysqli_prepare($conn, "
        SELECT id
        FROM conversation_members
        WHERE conversation_id = ? AND user_id = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "ii", $conversation_id, $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);

    return $exists;
}

function deliver_ice($conn, $call_id, $current_user)
{
    $stmt = mysqli_prepare($conn, "
        SELECT id, candidate
        FROM call_ice_candidates
        WHERE call_id = ?
        AND recipient_id = ?
        AND delivered = 0
        ORDER BY id ASC
    ");
    mysqli_stmt_bind_param($stmt, "ii", $call_id, $current_user);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $ids = [];
    $items = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $ids[] = (int) $row['id'];
        $items[] = json_decode($row['candidate'], true);
    }

    mysqli_stmt_close($stmt);

    if ($ids) {
        $idList = implode(',', array_map('intval', $ids));
        mysqli_query($conn, "UPDATE call_ice_candidates SET delivered = 1 WHERE id IN ($idList)");
    }

    return $items;
}

if ($action === 'start') {
    $conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;
    $call_type = ($_POST['call_type'] ?? 'audio') === 'video' ? 'video' : 'audio';
    $offer = $_POST['offer'] ?? '';

    if ($conversation_id <= 0 || $offer === '') {
        json_error("Missing call data");
    }

    if (!member_exists($conn, $conversation_id, $current_user)) {
        json_error("Conversation access denied");
    }

    $receiverStmt = mysqli_prepare($conn, "
        SELECT user_id
        FROM conversation_members
        WHERE conversation_id = ?
        AND user_id != ?
        ORDER BY user_id ASC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($receiverStmt, "ii", $conversation_id, $current_user);
    mysqli_stmt_execute($receiverStmt);
    $receiver = mysqli_fetch_assoc(mysqli_stmt_get_result($receiverStmt));
    mysqli_stmt_close($receiverStmt);

    if (!$receiver) {
        json_error("Calls need another user in this chat");
    }

    $receiver_id = (int) $receiver['user_id'];

    mysqli_query($conn, "
        UPDATE call_sessions
        SET status = 'ended', ended_at = NOW()
        WHERE conversation_id = " . (int) $conversation_id . "
        AND status IN ('pending','active')
    ");

    $stmt = mysqli_prepare($conn, "
        INSERT INTO call_sessions (conversation_id, caller_id, receiver_id, call_type, status, offer)
        VALUES (?, ?, ?, ?, 'pending', ?)
    ");
    mysqli_stmt_bind_param($stmt, "iiiss", $conversation_id, $current_user, $receiver_id, $call_type, $offer);
    mysqli_stmt_execute($stmt);
    $call_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    echo json_encode(["status" => "success", "call_id" => (int) $call_id]);
    exit();
}

if ($action === 'answer') {
    $call_id = isset($_POST['call_id']) ? (int) $_POST['call_id'] : 0;
    $answer = $_POST['answer'] ?? '';
    $call = fetch_call($conn, $call_id, $current_user);

    if (!$call || (int) $call['receiver_id'] !== $current_user || $answer === '') {
        json_error("Call cannot be answered");
    }

    $stmt = mysqli_prepare($conn, "
        UPDATE call_sessions
        SET answer = ?, status = 'active'
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "si", $answer, $call_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(["status" => "success"]);
    exit();
}

if ($action === 'upgrade_offer') {
    $call_id = isset($_POST['call_id']) ? (int) $_POST['call_id'] : 0;
    $offer = $_POST['offer'] ?? '';
    $call = fetch_call($conn, $call_id, $current_user);

    if (!$call || $call['status'] !== 'active' || $offer === '') {
        json_error("Video upgrade cannot be requested");
    }

    $stmt = mysqli_prepare($conn, "
        UPDATE call_sessions
        SET upgrade_offer = ?,
            upgrade_answer = NULL,
            upgrade_requested_by = ?,
            upgrade_status = 'pending'
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "sii", $offer, $current_user, $call_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(["status" => "success"]);
    exit();
}

if ($action === 'upgrade_answer') {
    $call_id = isset($_POST['call_id']) ? (int) $_POST['call_id'] : 0;
    $answer = $_POST['answer'] ?? '';
    $call = fetch_call($conn, $call_id, $current_user);

    if (!$call || $call['status'] !== 'active' || $answer === '' || (int) $call['upgrade_requested_by'] === $current_user) {
        json_error("Video upgrade cannot be answered");
    }

    $stmt = mysqli_prepare($conn, "
        UPDATE call_sessions
        SET upgrade_answer = ?,
            upgrade_status = 'accepted',
            call_type = 'video'
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "si", $answer, $call_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(["status" => "success"]);
    exit();
}

if ($action === 'ice') {
    $call_id = isset($_POST['call_id']) ? (int) $_POST['call_id'] : 0;
    $candidate = $_POST['candidate'] ?? '';
    $call = fetch_call($conn, $call_id, $current_user);

    if (!$call || $candidate === '') {
        json_error("ICE candidate rejected");
    }

    $recipient_id = ((int) $call['caller_id'] === $current_user)
        ? (int) $call['receiver_id']
        : (int) $call['caller_id'];

    $stmt = mysqli_prepare($conn, "
        INSERT INTO call_ice_candidates (call_id, sender_id, recipient_id, candidate)
        VALUES (?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param($stmt, "iiis", $call_id, $current_user, $recipient_id, $candidate);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(["status" => "success"]);
    exit();
}

if ($action === 'end') {
    $call_id = isset($_POST['call_id']) ? (int) $_POST['call_id'] : 0;
    $call = fetch_call($conn, $call_id, $current_user);

    if (!$call) {
        json_error("Call not found");
    }

    $newStatus = ($call['status'] === 'pending' && (int) $call['receiver_id'] === $current_user) ? 'declined' : 'ended';
    $stmt = mysqli_prepare($conn, "
        UPDATE call_sessions
        SET status = ?, ended_at = NOW()
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "si", $newStatus, $call_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(["status" => "success"]);
    exit();
}

$call_id = isset($_POST['call_id']) ? (int) $_POST['call_id'] : 0;

if ($call_id > 0) {
    $call = fetch_call($conn, $call_id, $current_user);

    if (!$call) {
        json_error("Call not found");
    }

    echo json_encode([
        "status" => "success",
        "call" => [
            "id" => (int) $call['id'],
            "conversation_id" => (int) $call['conversation_id'],
            "caller_id" => (int) $call['caller_id'],
            "receiver_id" => (int) $call['receiver_id'],
            "caller_name" => $call['caller_name'],
            "receiver_name" => $call['receiver_name'],
            "call_type" => $call['call_type'],
            "call_status" => $call['status'],
            "offer" => $call['offer'],
            "answer" => $call['answer'],
            "upgrade_offer" => $call['upgrade_offer'] ?? null,
            "upgrade_answer" => $call['upgrade_answer'] ?? null,
            "upgrade_requested_by" => isset($call['upgrade_requested_by']) ? (int) $call['upgrade_requested_by'] : 0,
            "upgrade_status" => $call['upgrade_status'] ?? null
        ],
        "ice" => deliver_ice($conn, $call_id, $current_user)
    ]);
    exit();
}

$incomingStmt = mysqli_prepare($conn, "
    SELECT cs.*, u.full_name AS caller_name
    FROM call_sessions cs
    JOIN users u ON u.id = cs.caller_id
    WHERE cs.receiver_id = ?
    AND cs.status = 'pending'
    AND cs.created_at >= (NOW() - INTERVAL 60 SECOND)
    ORDER BY cs.id DESC
    LIMIT 1
");
mysqli_stmt_bind_param($incomingStmt, "i", $current_user);
mysqli_stmt_execute($incomingStmt);
$incoming = mysqli_fetch_assoc(mysqli_stmt_get_result($incomingStmt));
mysqli_stmt_close($incomingStmt);

echo json_encode([
    "status" => "success",
    "incoming" => $incoming ? [
        "id" => (int) $incoming['id'],
        "conversation_id" => (int) $incoming['conversation_id'],
        "caller_id" => (int) $incoming['caller_id'],
        "caller_name" => $incoming['caller_name'],
        "call_type" => $incoming['call_type'],
        "offer" => $incoming['offer']
    ] : null
]);
