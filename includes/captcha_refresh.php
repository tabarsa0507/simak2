<?php
session_start();
$code = rand(1000, 9999);
$_SESSION['captcha'] = $code;
echo json_encode(['code' => $code]);
?>