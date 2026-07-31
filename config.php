<?php
// تنظیمات دیتابیس
define('DB_HOST', 'localhost');
define('DB_NAME', 'company_kb');
define('DB_USER', 'tabarsa');
define('DB_PASS', 'tabarsa');

// اتصال به دیتابیس با PDO
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("خطا در اتصال به دیتابیس: " . $e->getMessage());
}

// شروع سشن
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// تنظیمات منطقه زمانی به تهران
date_default_timezone_set('Asia/Tehran');

// کلید رمزنگاری (برای رمز کردن پسوردها)
define('ENCRYPTION_KEY', 'your-secret-key-here-change-it');
?>