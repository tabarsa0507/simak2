<?php
/**
 * دریافت اطلاعات یک یادآور برای ویرایش
 */

header('Content-Type: application/json');

require_once 'config.php';
require_once 'functions.php';
requireLogin();

$id = $_GET['id'] ?? 0;

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ?");
    $stmt->execute([$id]);
    $reminder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reminder) {
        echo json_encode(['success' => true] + $reminder);
    } else {
        echo json_encode(['success' => false, 'error' => 'یادآور یافت نشد']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'شناسه نامعتبر']);
}