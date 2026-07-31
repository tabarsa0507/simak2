<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['profile_picture'])) {
    $targetDir = 'uploads/profiles/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    
    $fileName = time() . '_' . basename($_FILES['profile_picture']['name']);
    $targetPath = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($fileType, $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'فرمت فایل مجاز نیست']);
        exit();
    }
    if ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) { // 2MB
        echo json_encode(['success' => false, 'error' => 'حجم فایل بیش از ۲ مگابایت است']);
        exit();
    }
    
    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
        echo json_encode(['success' => true, 'path' => $targetPath]);
    } else {
        echo json_encode(['success' => false, 'error' => 'خطا در آپلود فایل']);
    }
}
?>