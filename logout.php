<?php
require_once 'config/auth.php';
startSecureSession();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
?>
