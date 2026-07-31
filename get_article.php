<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه مقاله مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM knowledge_base WHERE id = ?");
$stmt->execute([$id]);
$article = $stmt->fetch();

if ($article) {
    echo json_encode([
        'success' => true,
        'title' => $article['title'],
        'summary' => $article['summary'] ?? '',
        'content' => $article['content'],
        'category_id' => $article['category_id'],
        'status' => $article['status']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'مقاله یافت نشد']);
}
?>