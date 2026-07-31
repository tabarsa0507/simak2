<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه اموال مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];

// دریافت اطلاعات اموال
$stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) {
    echo json_encode(['success' => false, 'error' => 'اموال یافت نشد']);
    exit();
}

// دریافت اطلاعات واگذاری
$stmt = $pdo->prepare("SELECT * FROM asset_assignments WHERE asset_id = ? AND status = 'assigned' ORDER BY id DESC LIMIT 1");
$stmt->execute([$id]);
$assignment = $stmt->fetch();

echo json_encode([
    'success' => true,
    'title' => $asset['title'],
    'code' => $asset['code'],
    'description' => $asset['description'],
    'category_id' => $asset['category_id'],
    'status' => $asset['status'],
    'purchase_price' => $asset['purchase_price'],
    'purchase_date' => $asset['purchase_date'],
    'shamsi_date' => $asset['shamsi_date'],
    'assignment' => $assignment
]);
?>