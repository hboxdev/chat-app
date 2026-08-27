<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/admin_helpers.php";

chatweb_ensure_admin_schema($conn);
if (!empty($_SESSION['admin_user_id'])) {
    chatweb_admin_log($conn, 'ADMIN_LOGOUT');
}
chatweb_admin_clear_cookie($conn);
unset($_SESSION['admin_user_id'], $_SESSION['admin_email'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['admin_permissions']);

header("Location: login.php");
exit();
