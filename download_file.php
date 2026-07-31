<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه فایل مشخص نشده است');
}

$id = (int)$_GET['id'];

// دریافت اطلاعات فایل
$stmt = $pdo->prepare("SELECT * FROM download_center WHERE id = ? AND status = 1");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die('فایل یافت نشد یا غیرفعال است');
}

// بررسی وجود فایل
if (empty($item['file_path']) || !file_exists($item['file_path'])) {
    die('فایل روی سرور وجود ندارد');
}

// افزایش تعداد دانلود (استفاده از download_count)
$stmt = $pdo->prepare("UPDATE download_center SET download_count = download_count + 1 WHERE id = ?");
$stmt->execute([$id]);

// دانلود فایل
$file_path = $item['file_path'];
$file_name = basename($file_path);

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file_name . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

readfile($file_path);
exit();
?>