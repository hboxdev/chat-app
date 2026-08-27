<?php

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";

chatweb_ensure_auth_schema($conn);
chatweb_require_login($conn, '../index.php');

?>
