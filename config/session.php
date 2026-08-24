<?php
/*
==========================================================
File Name : session.php

Purpose :
Manage Application Sessions

Used In :
Entire Project

Author :
Ahmed Ali
==========================================================
*/


/*
----------------------------------------------------------
Session Security
----------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.use_only_cookies', 1);

    ini_set('session.use_strict_mode', 1);

}


/*
----------------------------------------------------------
Start Session
----------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}


/*
----------------------------------------------------------
Session Lifetime
30 Minutes
----------------------------------------------------------
*/

$sessionLifetime = 1800;

if (isset($_SESSION['LAST_ACTIVITY']) &&

    (time() - $_SESSION['LAST_ACTIVITY']) > $sessionLifetime) {

    session_unset();

    session_destroy();

}

$_SESSION['LAST_ACTIVITY'] = time();


/*
----------------------------------------------------------
Regenerate Session ID
Every 10 Minutes
----------------------------------------------------------
*/

if (!isset($_SESSION['CREATED'])) {

    $_SESSION['CREATED'] = time();

}
else {

    if (time() - $_SESSION['CREATED'] > 600) {

        session_regenerate_id(true);

        $_SESSION['CREATED'] = time();

    }

}
