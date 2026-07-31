<?php
require_once 'config.php';
require_once 'functions.php';

if (isLoggedIn()) {
    logActivity($_SESSION['user_id'], 'auth', 'logout', 'کاربر از سیستم خارج شد');
}

$_SESSION = array();
session_destroy();
header('Location: index.php');
exit();
?>