<?php
/*
==========================================================
File Name : functions.php

Purpose :
Reusable Helper Functions

Used In :
Entire Project

Author :
Ahmed Ali

==========================================================
*/


/*
==========================================================
Function : clean()

Purpose :
Remove extra spaces and convert special characters
to prevent XSS attacks.

Example :

$name = clean($_POST['name']);

==========================================================
*/

function clean($data)
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}


/*
==========================================================
Function : redirect()

Purpose :
Redirect user to another page.

Example :

redirect("dashboard.php");

==========================================================
*/

function redirect($page)
{
    header("Location: $page");
    exit();
}


/*
==========================================================
Function : isPost()

Purpose :
Check if form submitted using POST.

==========================================================
*/

function isPost()
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}


/*
==========================================================
Function : isGet()

Purpose :
Check if request is GET.

==========================================================
*/

function isGet()
{
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}


/*
==========================================================
Function : generateUsername()

Purpose :
Create unique username.

==========================================================
*/

function generateUsername($name)
{

    $name = strtolower($name);

    $name = preg_replace('/[^a-z0-9]/', '', $name);

    return $name . rand(1000,9999);

}


/*
==========================================================
Function : uploadImage()

Purpose :
Upload profile image.

(Currently placeholder)

==========================================================
*/

function uploadImage($file)
{

    return "default.png";

}


/*
==========================================================
Function : currentDateTime()

Purpose :
Return current date & time.

==========================================================
*/

function currentDateTime()
{

    return date("Y-m-d H:i:s");

}


/*
==========================================================
Function : hashPassword()

Purpose :
Encrypt password.

==========================================================
*/

function hashPassword($password)
{

    return password_hash($password, PASSWORD_DEFAULT);

}


/*
==========================================================
Function : verifyPassword()

Purpose :
Verify encrypted password.

==========================================================
*/

function verifyPassword($password,$hash)
{

    return password_verify($password,$hash);

}


/*
==========================================================
Function : response()

Purpose :
Standard response array.

==========================================================
*/

function response($status,$message,$data=[])

{

    return [

        "status"=>$status,

        "message"=>$message,

        "data"=>$data

    ];

}

?>