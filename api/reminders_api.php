<?php
/**
 * API مدیریت یادآورها
 * برای فراخوانی از جاوااسکریپت
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/ReminderService.php';

// احراز هویت
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'نیاز به ورود']);
    exit;
}

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'data' => null, 'error' => null];

try {
    $reminderService = new ReminderService($pdo);
    $user = getCurrentUser();
    
    switch($action) {
        case 'list':
            // دریافت لیست یادآورها با فیلتر
            $filters = [
                'search' => $_GET['search'] ?? '',
                'category' => $_GET['category'] ?? null,
                'status' => $_GET['status'] ?? null,
                'priority' => $_GET['priority'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null
            ];
            $response['data'] = getRemindersList($pdo, $filters, $user['id']);
            $response['success'] = true;
            break;
            
        case 'get':
            // دریافت یک یادآور
            $id = $_GET['id'] ?? 0;
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ?");
                $stmt->execute([$id]);
                $response['data'] = $stmt->fetch(PDO::FETCH_ASSOC);
                $response['success'] = true;
            }
            break;
            
        case 'create':
            // ایجاد یادآور جدید
            $data = json_decode(file_get_contents('php://input'), true);
            if ($data) {
                $data['created_by'] = $user['id'];
                $result = $reminderService->createReminder($data);
                $response = $result;
            }
            break;
            
        case 'update':
            // ویرایش یادآور
            $id = $_GET['id'] ?? 0;
            $data = json_decode(file_get_contents('php://input'), true);
            if ($id && $data) {
                $result = $reminderService->updateReminder($id, $data);
                $response = $result;
            }
            break;
            
        case 'delete':
            // حذف یادآور
            $id = $_GET['id'] ?? 0;
            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM reminders WHERE id = ?");
                $stmt->execute([$id]);
                $response['success'] = true;
            }
            break;
            
        case 'change_status':
            // تغییر وضعیت
            $id = $_GET['id'] ?? 0;
            $status = $_GET['status'] ?? 'active';
            if ($id) {
                $stmt = $pdo->prepare("UPDATE reminders SET status = ? WHERE id = ?");
                $stmt->execute([$status, $id]);
                $response['success'] = true;
            }
            break;
            
        case 'send_now':
            // ارسال فوری
            $id = $_GET['id'] ?? 0;
            if ($id) {
                $result = $reminderService->sendReminder($id);
                $response = $result;
            }
            break;
            
        default:
            $response['error'] = 'اکشن نامعتبر';
    }
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);

/**
 * دریافت لیست یادآورها با فیلتر
 */
function getRemindersList($pdo, $filters, $userId) {
    $sql = "
        SELECT r.*, 
               c.name as category_name,
               c.color as category_color,
               u.full_name as assigned_to_name,
               g.name as group_name
        FROM reminders r
        LEFT JOIN reminder_categories c ON r.category_id = c.id
        LEFT JOIN users u ON r.assigned_to = u.id
        LEFT JOIN user_groups g ON r.assigned_group = g.id
        WHERE (r.assigned_to = ? OR r.user_id = ? OR r.assigned_group IN (SELECT id FROM user_groups WHERE FIND_IN_SET(?, members)))
    ";
    
    $params = [$userId, $userId, $userId];
    
    if (!empty($filters['search'])) {
        $sql .= " AND (r.title LIKE ? OR r.description LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
    
    if (!empty($filters['category'])) {
        $sql .= " AND r.category_id = ?";
        $params[] = $filters['category'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND r.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['priority'])) {
        $sql .= " AND r.priority = ?";
        $params[] = $filters['priority'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND r.reminder_date >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND r.reminder_date <= ?";
        $params[] = $filters['date_to'];
    }
    
    $sql .= " ORDER BY r.reminder_date ASC, r.reminder_time ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}