<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه نامه مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM letters WHERE id = ?");
$stmt->execute([$id]);
$letter = $stmt->fetch();

if (!$letter) {
    echo json_encode(['success' => false, 'error' => 'نامه یافت نشد']);
    exit();
}

// دریافت اطلاعات ارجاع
$stmt = $pdo->prepare("SELECT * FROM letter_referrals WHERE letter_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
$stmt->execute([$id]);
$referral = $stmt->fetch();

echo json_encode([
    'success' => true,
    'subject' => $letter['subject'],
    'content' => $letter['content'],
    'summary' => $letter['summary'],
    'type' => $letter['type'],
    'priority' => $letter['priority'],
    'status' => $letter['status'],
    'sender_name' => $letter['sender_name'],
    'sender_organization' => $letter['sender_organization'],
    'sender_phone' => $letter['sender_phone'],
    'receiver_name' => $letter['receiver_name'],
    'receiver_organization' => $letter['receiver_organization'],
    'receiver_phone' => $letter['receiver_phone'],
    'header_id' => $letter['header_id'],
    'template_id' => $letter['template_id'],
    'shamsi_date' => $letter['shamsi_date'],
    'shamsi_letter_date' => $letter['shamsi_letter_date'],
    'has_attachment' => $letter['has_attachment'] ?? 0,
    'attachment_count' => $letter['attachment_count'] ?? 0,
    'attachment_description' => $letter['attachment_description'] ?? '',
    'signature_id' => $letter['signature_id'] ?? null,
    'referral' => $referral
]);
?>