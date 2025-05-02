<?php
// admin_logout.php - Admin logout script
session_start();

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page
header("location: admin_login.php");
exit;
?>