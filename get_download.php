<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه فایل مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM download_center WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if ($item) {
    echo json_encode([
        'success' => true,
        'title' => $item['title'],
        'description' => $item['description'],
        'file_name' => $item['file_name'],
        'file_path' => $item['file_path'],
        'download_url' => $item['download_url'],
        'file_size' => $item['file_size'],
        'file_type' => $item['file_type'],
        'category_id' => $item['category_id'],
        'status' => $item['status'],
        'shamsi_date' => $item['shamsi_date']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'فایل یافت نشد']);
}
?>