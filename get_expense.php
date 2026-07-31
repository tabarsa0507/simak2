<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'شناسه نامعتبر']);
    exit;
}

function getPaymentTypeLabel($type) {
    $labels = ['cash'=>'نقدی', 'non_cash'=>'غیرنقدی', 'card'=>'کارت بانکی', 'check'=>'چک', 'transfer'=>'اینترنتی'];
    return $labels[$type] ?? $type;
}

function getDocumentTypeLabel($type) {
    $labels = ['invoice'=>'فاکتور', 'receipt'=>'رسید', 'bill'=>'قبض', 'other'=>'سایر'];
    return $labels[$type] ?? $type;
}

try {
    $stmt = $pdo->prepare("
        SELECT te.*,
               u.full_name as user_name,
               c.name as category_name,
               c.color as category_color,
               p.name as project_name,
               ap.full_name as approved_by_name,
               op.name as origin_province,
               oc.name as origin_city,
               dp.name as destination_province,
               dc.name as destination_city,
               (SELECT SUM(amount) FROM expense_items WHERE expense_id = te.id) as total_amount
        FROM travel_expenses te
        LEFT JOIN users u ON te.user_id = u.id
        LEFT JOIN expense_categories c ON te.expense_category_id = c.id
        LEFT JOIN projects p ON te.project_id = p.id
        LEFT JOIN users ap ON te.approved_by = ap.id
        LEFT JOIN provinces op ON te.origin_province_id = op.id
        LEFT JOIN cities oc ON te.origin_city_id = oc.id
        LEFT JOIN provinces dp ON te.destination_province_id = dp.id
        LEFT JOIN cities dc ON te.destination_city_id = dc.id
        WHERE te.id = ?
    ");
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        echo json_encode(['success' => false, 'error' => 'هزینه‌ای یافت نشد']);
        exit;
    }

    $items = $pdo->prepare("
        SELECT ei.*, ec.name as category_name
        FROM expense_items ei
        LEFT JOIN expense_categories ec ON ei.category_id = ec.id
        WHERE ei.expense_id = ?
        ORDER BY ei.id
    ");
    $items->execute([$id]);
    $expense['items'] = $items->fetchAll();

    $statusColors = ['pending' => '#fef3c7', 'approved' => '#d1fae5', 'rejected' => '#fecaca'];
    $statusTextColors = ['pending' => '#92400e', 'approved' => '#065f46', 'rejected' => '#991b1b'];
    $statusLabels = ['pending' => 'در انتظار تایید', 'approved' => 'تایید شده', 'rejected' => 'رد شده'];
    $expense['status_color'] = $statusColors[$expense['status']] ?? '#f3f4f6';
    $expense['status_text_color'] = $statusTextColors[$expense['status']] ?? '#6b7280';
    $expense['status_label'] = $statusLabels[$expense['status']] ?? $expense['status'];
    $expense['payment_type_label'] = getPaymentTypeLabel($expense['payment_type']);
    $expense['document_type_label'] = getDocumentTypeLabel($expense['document_type']);

    echo json_encode(['success' => true] + $expense);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}