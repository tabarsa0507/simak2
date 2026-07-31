<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه امضا مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM signatures WHERE id = ?");
$stmt->execute([$id]);
$sig = $stmt->fetch();

if ($sig) {
    echo json_encode([
        'success' => true,
        'name' => $sig['name'],
        'position' => $sig['position'],
        'image_path' => $sig['image_path'],
        'is_default' => $sig['is_default']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'امضا یافت نشد']);
}
?>