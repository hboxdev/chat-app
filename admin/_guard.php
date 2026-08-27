<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/admin_helpers.php";

chatweb_ensure_admin_schema($conn);
chatweb_admin_require($conn);

