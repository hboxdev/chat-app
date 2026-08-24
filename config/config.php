<?php
$localConfig = __DIR__ . "/config.local.php";

if (is_file($localConfig)) {
    require $localConfig;
}

$host = getenv('DB_HOST') ?: ($db_host ?? 'localhost');
$user = getenv('DB_USER') ?: ($db_user ?? 'root');
$password = getenv('DB_PASSWORD') ?: ($db_password ?? '');
$database = getenv('DB_NAME') ?: ($db_name ?? 'chatapp');
$port = (int) (getenv('DB_PORT') ?: ($db_port ?? 3306));

$conn = mysqli_connect($host, $user, $password, $database, $port);

if (!$conn) {
    error_log("Database Connection Failed: " . mysqli_connect_error());
    die("Database Connection Failed.");
}

mysqli_set_charset($conn, "utf8mb4");
?>
