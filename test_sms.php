<?php
require_once 'config.php';
require_once 'includes/sms.php';

$sms = new SMS();
$result = $sms->send('09112770507', 'پیام تست از سامانه');

echo '<pre>';
print_r($result);
echo '</pre>';