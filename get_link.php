<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه لینک مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM link_bank WHERE id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();

if ($link) {
    echo json_encode([
        'success' => true,
        'title' => $link['title'],
        'url' => $link['url'],
        'description' => $link['description'],
        'category_id' => $link['category_id'],
        'status' => $link['status'],
        'username' => $link['username'],
        'password' => $link['password'],
        'shamsi_date' => $link['shamsi_date']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'لینک یافت نشد']);
}
?>