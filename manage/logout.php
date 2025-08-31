<?php
// Start the session to access session variables
session_start();

// Unset all of the session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to the login page or homepage
header("Location: manage_taxi.php"); // Or login.php, or another appropriate page
exit; // Terminate script execution after redirection
