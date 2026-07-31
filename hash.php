<?php
// پسورد مورد نظر خود را اینجا وارد کنید
$password = 'password';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "پسورد: " . $password . "<br>";
echo "هش تولید شده: " . $hash . "<br>";
echo "<hr>";
echo "برای تست:<br>";
echo "<a href='test_login.php?username=admin&password=password'>تست ورود</a>";
?>