<?php
session_start();
require_once 'config.php';

function generateNumericCaptcha() {
    $code = rand(100000, 999999);
    $_SESSION['captcha'] = $code;
    return $code;
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'captcha' => generateNumericCaptcha()]);
    exit();
}