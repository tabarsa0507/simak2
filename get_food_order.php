<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'شناسه نامعتبر']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM food_orders WHERE id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'سفارش یافت نشد']);
        exit;
    }

    $items = $pdo->prepare("SELECT * FROM food_order_items WHERE order_id = ?");
    $items->execute([$id]);
    $order['items'] = $items->fetchAll();

    echo json_encode(['success' => true] + $order);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}