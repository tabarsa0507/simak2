<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه کاربر ارسال نشده']);
    exit();
}

$stmt = $pdo->prepare("SELECT id, full_name, mobile, email, telegram_id FROM users WHERE id = ?");
$stmt->execute([$_GET['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo json_encode(['success' => true, 'full_name' => $user['full_name'], 'mobile' => $user['mobile'], 'email' => $user['email'], 'telegram_id' => $user['telegram_id']]);
} else {
    echo json_encode(['success' => false, 'error' => 'کاربر یافت نشد']);
}