<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json");

include __DIR__ . "/../config/config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

$current_user = (int) $_SESSION['user_id'];

function wallpaper_response($payload)
{
    echo json_encode($payload);
    exit();
}

function ensure_wallpaper_column($conn, $table, $column, $definition)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $exists = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");

    if ($exists && mysqli_num_rows($exists) === 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

if (!isset($_FILES['wallpaper_image'])) {
    wallpaper_response(["status" => "error", "message" => "Please select an image."]);
}

$file = $_FILES['wallpaper_image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    wallpaper_response(["status" => "error", "message" => "Image upload failed."]);
}

$max_size = 8 * 1024 * 1024;

if ($file['size'] > $max_size) {
    wallpaper_response(["status" => "error", "message" => "Wallpaper image must be 8 MB or smaller."]);
}

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ["jpg", "jpeg", "png", "webp", "gif"];

if (!in_array($extension, $allowed, true)) {
    wallpaper_response(["status" => "error", "message" => "Only JPG, PNG, WEBP and GIF images are allowed."]);
}

$image_info = @getimagesize($file['tmp_name']);

if ($image_info === false) {
    wallpaper_response(["status" => "error", "message" => "Selected file is not a valid image."]);
}

$upload_dir = __DIR__ . "/../uploads/wallpapers";

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$safe_name = "wallpaper_" . $current_user . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $extension;
$target_path = $upload_dir . "/" . $safe_name;

if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    wallpaper_response(["status" => "error", "message" => "Wallpaper could not be saved."]);
}

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS theme_settings (
        user_id INT PRIMARY KEY,
        theme VARCHAR(20) DEFAULT 'Light',
        accent_color VARCHAR(20) DEFAULT 'Blue',
        wallpaper_type VARCHAR(30) DEFAULT 'Solid',
        wallpaper_value VARCHAR(255) DEFAULT '#F5F7FB',
        font_size VARCHAR(20) DEFAULT 'Medium',
        compact_mode TINYINT(1) DEFAULT 0,
        bubble_style VARCHAR(30) DEFAULT 'Rounded',
        enable_animations TINYINT(1) DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
ensure_wallpaper_column($conn, "theme_settings", "wallpaper_type", "VARCHAR(30) DEFAULT 'Solid'");
ensure_wallpaper_column($conn, "theme_settings", "wallpaper_value", "VARCHAR(255) DEFAULT '#F5F7FB'");
mysqli_query($conn, "INSERT IGNORE INTO theme_settings (user_id) VALUES ($current_user)");

$wallpaper_value = "uploads/wallpapers/" . $safe_name;
$stmt = mysqli_prepare($conn, "
    UPDATE theme_settings
    SET wallpaper_type = 'Custom image', wallpaper_value = ?
    WHERE user_id = ?
");
mysqli_stmt_bind_param($stmt, "si", $wallpaper_value, $current_user);

if (!mysqli_stmt_execute($stmt)) {
    wallpaper_response(["status" => "error", "message" => "Wallpaper setting could not be updated."]);
}

wallpaper_response([
    "status" => "success",
    "message" => "Wallpaper image saved.",
    "wallpaper_type" => "Custom image",
    "wallpaper_value" => $wallpaper_value,
    "url" => "../" . $wallpaper_value
]);
