<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه قالب مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM letter_templates WHERE id = ?");
$stmt->execute([$id]);
$template = $stmt->fetch();

if ($template) {
    echo json_encode([
        'success' => true,
        'title' => $template['title'],
        'content' => $template['content'],
        'footer_text' => $template['footer_text'],
        'header_id' => $template['header_id'],
        'description' => $template['description'],
        'is_default' => $template['is_default']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'قالب یافت نشد']);
}
?>