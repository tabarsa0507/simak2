<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
require_once 'includes/sms.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'هزینه‌های ماموریت';

// ===== تابع تبدیل اعداد به فارسی =====
function persian_number($number) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english, $persian, (string)$number);
}

function persian_number_str($str) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english, $persian, $str);
}

// ===== دریافت نقش‌های کاربر =====
$userRoles = getUserRoles($user['id']);
if (!is_array($userRoles)) {
    $userRoles = [];
}
$currentRole = $_SESSION['current_role'] ?? ($userRoles[0] ?? null);
if (!$currentRole && !empty($userRoles)) {
    $currentRole = $userRoles[0];
    $_SESSION['current_role'] = $currentRole;
}
if (isset($_POST['switch_role']) && in_array($_POST['switch_role'], $userRoles)) {
    $_SESSION['current_role'] = $_POST['switch_role'];
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ===== اطلاعات پایه =====
$categories = $pdo->query("SELECT * FROM expense_categories ORDER BY name")->fetchAll();
$projects = $pdo->query("SELECT * FROM projects WHERE status = 1 ORDER BY name")->fetchAll();
$provinces = $pdo->query("SELECT * FROM provinces ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name, national_id, email, mobile, role FROM users WHERE status = 1 ORDER BY full_name")->fetchAll();

// ===== شهرها برای جاوااسکریپت =====
$cities = $pdo->query("SELECT id, province_id, name FROM cities ORDER BY name")->fetchAll();
$cityMap = [];
foreach ($cities as $c) {
    $cityMap[$c['province_id']][] = ['id' => $c['id'], 'name' => $c['name']];
}
$cityMapJson = json_encode($cityMap);

// ===== توابع کمکی =====
function getStatusStyle($status) {
    $styles = [
        'pending' => ['bg' => '#e3f2fd', 'color' => '#1565c0', 'label' => 'در انتظار تایید', 'icon' => 'fa-clock'],
        'approved' => ['bg' => '#e8f5e9', 'color' => '#2e7d32', 'label' => 'تایید شده', 'icon' => 'fa-check-circle'],
        'rejected' => ['bg' => '#ffebee', 'color' => '#c62828', 'label' => 'رد شده', 'icon' => 'fa-times-circle']
    ];
    return $styles[$status] ?? $styles['pending'];
}

function getPaymentTypeLabel($type) {
    $labels = ['cash' => 'نقدی', 'non_cash' => 'غیرنقدی', 'card' => 'کارت بانکی', 'check' => 'چک', 'transfer' => 'اینترنتی'];
    return $labels[$type] ?? $type;
}

function getDocumentTypeLabel($type) {
    $labels = ['invoice' => 'فاکتور', 'receipt' => 'رسید', 'bill' => 'قبض', 'other' => 'سایر'];
    return $labels[$type] ?? $type;
}

// ===== دریافت لیست هزینه‌ها با فیلتر =====
$where = [];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where[] = "(te.full_name LIKE :search)";
    $params[':search'] = '%' . $_GET['search'] . '%';
}
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $where[] = "te.expense_category_id = :category";
    $params[':category'] = $_GET['category'];
}
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $where[] = "te.status = :status";
    $params[':status'] = $_GET['status'];
}
if (isset($_GET['project']) && !empty($_GET['project'])) {
    $where[] = "te.project_id = :project";
    $params[':project'] = $_GET['project'];
}
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where[] = "te.travel_date >= :date_from";
    $params[':date_from'] = $_GET['date_from'];
}
if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where[] = "te.travel_date <= :date_to";
    $params[':date_to'] = $_GET['date_to'];
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "
    SELECT te.*,
           u.full_name as user_name,
           c.name as category_name,
           c.color as category_color,
           c.icon as category_icon,
           p.name as project_name,
           op.name as origin_province,
           oc.name as origin_city,
           dp.name as destination_province,
           dc.name as destination_city,
           (SELECT SUM(amount) FROM expense_items WHERE expense_id = te.id) as total_amount
    FROM travel_expenses te
    LEFT JOIN users u ON te.user_id = u.id
    LEFT JOIN expense_categories c ON te.expense_category_id = c.id
    LEFT JOIN projects p ON te.project_id = p.id
    LEFT JOIN provinces op ON te.origin_province_id = op.id
    LEFT JOIN cities oc ON te.origin_city_id = oc.id
    LEFT JOIN provinces dp ON te.destination_province_id = dp.id
    LEFT JOIN cities dc ON te.destination_city_id = dc.id
    $whereClause
    ORDER BY te.id DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// ===== آمار =====
$stats = [
    'total' => count($expenses),
    'pending' => count(array_filter($expenses, fn($a) => $a['status'] === 'pending')),
    'approved' => count(array_filter($expenses, fn($a) => $a['status'] === 'approved')),
    'rejected' => count(array_filter($expenses, fn($a) => $a['status'] === 'rejected')),
    'total_amount' => array_sum(array_column($expenses, 'total_amount'))
];

// ===== پردازش فرم =====
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        // ===== ADD CATEGORY =====
        if ($action === 'add_category' && !empty($_POST['cat_name'])) {
            $cat_name = trim($_POST['cat_name']);
            $cat_color = trim($_POST['cat_color'] ?? '#0969da');
            $cat_icon = trim($_POST['cat_icon'] ?? 'fa-tag');
            
            $stmt = $pdo->prepare("INSERT INTO expense_categories (name, color, icon) VALUES (?, ?, ?)");
            $stmt->execute([$cat_name, $cat_color, $cat_icon]);
            
            $_SESSION['success'] = 'دسته‌بندی "' . $cat_name . '" اضافه شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // ===== DELETE CATEGORY =====
        if ($action === 'delete_category' && !empty($_POST['cat_id'])) {
            $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$_POST['cat_id']]);
            $_SESSION['success'] = 'دسته‌بندی حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // ===== EDIT CATEGORY =====
        if ($action === 'edit_category' && !empty($_POST['cat_id']) && !empty($_POST['cat_name'])) {
            $catId = (int)$_POST['cat_id'];
            $cat_name = trim($_POST['cat_name']);
            $cat_color = trim($_POST['cat_color'] ?? '#0969da');
            $cat_icon = trim($_POST['cat_icon'] ?? 'fa-tag');
            
            $stmt = $pdo->prepare("UPDATE expense_categories SET name = ?, color = ?, icon = ? WHERE id = ?");
            $stmt->execute([$cat_name, $cat_color, $cat_icon, $catId]);
            $_SESSION['success'] = 'دسته‌بندی ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // ===== ADD PROJECT =====
        if ($action === 'add_project' && !empty($_POST['project_name'])) {
            $pname = trim($_POST['project_name']);
            $pdesc = trim($_POST['project_description'] ?? '');
            
            $stmt = $pdo->prepare("INSERT INTO projects (name, description, status) VALUES (?, ?, 1)");
            $stmt->execute([$pname, $pdesc]);
            
            $_SESSION['success'] = 'پروژه "' . $pname . '" اضافه شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // ===== DELETE PROJECT =====
        if ($action === 'delete_project' && !empty($_POST['project_id'])) {
            $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$_POST['project_id']]);
            $_SESSION['success'] = 'پروژه حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // ===== EDIT PROJECT =====
        if ($action === 'edit_project' && !empty($_POST['project_id']) && !empty($_POST['project_name'])) {
            $pid = (int)$_POST['project_id'];
            $pname = trim($_POST['project_name']);
            $pdesc = trim($_POST['project_description'] ?? '');
            
            $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$pname, $pdesc, $pid]);
            $_SESSION['success'] = 'پروژه ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // ===== ADD EXPENSE =====
        if ($action === 'add') {
            $full_name = trim($_POST['full_name'] ?? '');
            $start_date = trim($_POST['start_date'] ?? '');
            $end_date = trim($_POST['end_date'] ?? '');
            $origin_province = !empty($_POST['origin_province']) ? (int)$_POST['origin_province'] : null;
            $origin_city = !empty($_POST['origin_city']) ? (int)$_POST['origin_city'] : null;
            $destination_province = !empty($_POST['destination_province']) ? (int)$_POST['destination_province'] : null;
            $destination_city = !empty($_POST['destination_city']) ? (int)$_POST['destination_city'] : null;
            $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
            
            $companions = trim($_POST['companions'] ?? '');
            $status = $_POST['status'] ?? 'pending';
            
            $item_amounts = $_POST['item_amount'] ?? [];
            $item_categories = $_POST['item_category'] ?? [];
            $item_payment_types = $_POST['item_payment_type'] ?? [];
            $item_document_types = $_POST['item_document_type'] ?? [];
            $item_descriptions = $_POST['item_description'] ?? [];
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO travel_expenses 
                (user_id, full_name, travel_date, end_date, origin_province_id, origin_city_id, 
                 destination_province_id, destination_city_id, project_id, companions, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user['id'], $full_name, $start_date, $end_date, $origin_province, $origin_city,
                           $destination_province, $destination_city, $project_id, $companions, $status, $user['id']]);
            $expense_id = $pdo->lastInsertId();
            
            for ($i = 0; $i < count($item_amounts); $i++) {
                if (!empty($item_amounts[$i])) {
                    $stmt = $pdo->prepare("INSERT INTO expense_items 
                        (expense_id, amount, category_id, payment_type, document_type, description) 
                        VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $expense_id,
                        str_replace(',', '', $item_amounts[$i]),
                        !empty($item_categories[$i]) ? (int)$item_categories[$i] : null,
                        $item_payment_types[$i] ?? 'cash',
                        $item_document_types[$i] ?? 'invoice',
                        $item_descriptions[$i] ?? ''
                    ]);
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = 'هزینه ماموریت با موفقیت ثبت شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // ===== EDIT EXPENSE =====
        if ($action === 'edit' && !empty($_POST['expense_id'])) {
            $expense_id = (int)$_POST['expense_id'];
            $full_name = trim($_POST['full_name'] ?? '');
            $start_date = trim($_POST['start_date'] ?? '');
            $end_date = trim($_POST['end_date'] ?? '');
            $origin_province = !empty($_POST['origin_province']) ? (int)$_POST['origin_province'] : null;
            $origin_city = !empty($_POST['origin_city']) ? (int)$_POST['origin_city'] : null;
            $destination_province = !empty($_POST['destination_province']) ? (int)$_POST['destination_province'] : null;
            $destination_city = !empty($_POST['destination_city']) ? (int)$_POST['destination_city'] : null;
            $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
            $companions = trim($_POST['companions'] ?? '');
            $status = $_POST['status'] ?? 'pending';
            
            $item_amounts = $_POST['item_amount'] ?? [];
            $item_categories = $_POST['item_category'] ?? [];
            $item_payment_types = $_POST['item_payment_type'] ?? [];
            $item_document_types = $_POST['item_document_type'] ?? [];
            $item_descriptions = $_POST['item_description'] ?? [];
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE travel_expenses SET 
                full_name = ?, travel_date = ?, end_date = ?, origin_province_id = ?, origin_city_id = ?,
                destination_province_id = ?, destination_city_id = ?, project_id = ?, companions = ?, status = ?
                WHERE id = ?");
            $stmt->execute([$full_name, $start_date, $end_date, $origin_province, $origin_city,
                           $destination_province, $destination_city, $project_id, $companions, $status, $expense_id]);
            
            $pdo->prepare("DELETE FROM expense_items WHERE expense_id = ?")->execute([$expense_id]);
            
            for ($i = 0; $i < count($item_amounts); $i++) {
                if (!empty($item_amounts[$i])) {
                    $stmt = $pdo->prepare("INSERT INTO expense_items 
                        (expense_id, amount, category_id, payment_type, document_type, description) 
                        VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $expense_id,
                        str_replace(',', '', $item_amounts[$i]),
                        !empty($item_categories[$i]) ? (int)$item_categories[$i] : null,
                        $item_payment_types[$i] ?? 'cash',
                        $item_document_types[$i] ?? 'invoice',
                        $item_descriptions[$i] ?? ''
                    ]);
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = 'هزینه ماموریت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // ===== DELETE EXPENSE =====
        if ($action === 'delete' && !empty($_POST['expense_id'])) {
            $pdo->prepare("DELETE FROM travel_expenses WHERE id = ?")->execute([$_POST['expense_id']]);
            $_SESSION['success'] = 'هزینه ماموریت حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // ===== CHANGE STATUS =====
        if ($action === 'change_status' && !empty($_POST['expense_id'])) {
            $new_status = $_POST['new_status'] ?? 'pending';
            $stmt = $pdo->prepare("UPDATE travel_expenses SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $user['id'], $_POST['expense_id']]);
            $_SESSION['success'] = 'وضعیت هزینه تغییر کرد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        $_SESSION['error'] = 'عملیات نامعتبر';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'خطا: ' . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'خطا: ' . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// ===== نمایش پیام‌ها =====
if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['success']) . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>هزینه‌های ماموریت</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/jalalidatepicker.min.css">
    <script src="assets/js/jalalidatepicker.min.js"></script>
    <script src="assets/js/persian-date.js"></script>
    <script src="assets/js/global.js"></script>
    <style>
        /* ===== استایل‌ها (همون استایل‌های قبلی با رنگ‌های متناوب) ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; margin: 0; padding: 0; }
        body { font-family: 'Vazirmatn', sans-serif; background: #f6f8fa; color: #24292f; display: flex; flex-direction: column; min-height: 100vh; }
        a { text-decoration: none; }

        .sidebar {
            width: 240px; background: #fff; border-left: 1px solid #e1e4e8;
            padding: 24px 16px; display: flex; flex-direction: column;
            position: fixed; top: 0; right: 0; height: 100vh;
            z-index: 1000; transition: all 0.3s ease; overflow-y: auto;
        }
        .sidebar-brand { padding: 0 8px 24px; border-bottom: 1px solid #e1e4e8; margin-bottom: 20px; }
        .sidebar-brand .brand { font-size: 18px; font-weight: 800; color: #24292f; display: flex; align-items: center; gap: 10px; }
        .sidebar-brand .brand i { color: #0969da; font-size: 22px; }
        .sidebar-brand .sub { font-size: 12px; color: #57606a; font-weight: 400; margin-top: 2px; }
        .sidebar-menu { list-style: none; flex: 1; }
        .sidebar-menu li a {
            display: flex; align-items: center; gap: 12px; padding: 8px 12px;
            color: #57606a; border-radius: 6px; font-size: 14px; font-weight: 500;
            transition: all 0.15s ease; margin-bottom: 2px;
        }
        .sidebar-menu li a i { width: 18px; font-size: 15px; color: #8b949e; }
        .sidebar-menu li a:hover { background: #f0f2f4; color: #24292f; }
        .sidebar-menu li a.active { background: #f0f2f4; color: #0969da; }
        .sidebar-menu li a.active i { color: #0969da; }
        .sidebar-menu .divider { height: 1px; background: #e1e4e8; margin: 12px 8px; }
        .sidebar-menu .label { font-size: 11px; font-weight: 600; color: #8b949e; padding: 12px 12px 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .sidebar-footer { border-top: 1px solid #e1e4e8; padding-top: 16px; }
        .sidebar-footer a { display: flex; align-items: center; gap: 12px; padding: 8px 12px; color: #57606a; border-radius: 6px; font-size: 14px; transition: all 0.15s ease; }
        .sidebar-footer a:hover { background: #f0f2f4; color: #24292f; }

        .main-content { flex: 1 0 auto; margin-right: 240px; padding: 28px 40px 20px 40px; display: flex; flex-direction: column; }
        .top-bar-wrapper {
            background: #ffffff; border-radius: 12px; border: 1px solid #e1e4e8;
            padding: 12px 24px; margin-bottom: 28px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .top-bar h1 { font-size: 20px; font-weight: 700; color: #24292f; }
        .top-bar h1 span { font-weight: 400; font-size: 13px; color: #57606a; margin-right: 8px; }
        .top-right { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .top-right .date {
            font-size: 13px; color: #57606a; background: #f6f8fa; padding: 6px 14px;
            border-radius: 6px; border: 1px solid #e1e4e8; white-space: nowrap;
            font-family: 'Vazirmatn', sans-serif; direction: ltr;
        }
        .top-right .date i { margin-left: 6px; color: #8b949e; }
        .user-profile {
            display: flex; align-items: center; gap: 12px; background: #fff;
            padding: 4px 12px 4px 16px; border-radius: 6px; border: 1px solid #e1e4e8;
        }
        .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: #0969da; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; }
        .user-name { font-size: 13px; font-weight: 600; color: #24292f; }
        .user-role { font-size: 11px; color: #57606a; }
        .role-switcher { display: flex; align-items: center; gap: 4px; }
        .role-switcher select { padding: 2px 8px; border: 1px solid #e1e4e8; border-radius: 4px; font-size: 11px; font-family: 'Vazirmatn', sans-serif; background: #fff; color: #24292f; outline: none; }
        .role-switcher select:focus { border-color: #0969da; }
        .role-switcher .btn-switch { padding: 2px 10px; background: #0969da; color: #fff; border: none; border-radius: 4px; font-size: 11px; font-family: 'Vazirmatn', sans-serif; cursor: pointer; transition: background 0.15s; }
        .role-switcher .btn-switch:hover { background: #0550b3; }

        .stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 28px; }
        .stat-card {
            padding: 16px 16px 14px; border-radius: 10px; transition: all 0.3s ease;
            cursor: default; border: 1px solid transparent;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .stat-card .stat-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .stat-card .stat-icon {
            width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center;
            justify-content: center; font-size: 16px; background: #ffffff; border: 1px solid;
        }
        .stat-card .stat-number { font-size: 20px; font-weight: 800; color: #1a1a2e; letter-spacing: -0.3px; line-height: 1.2; }
        .stat-card .stat-label { font-size: 13px; font-weight: 600; color: #1a1a2e; margin-top: 2px; opacity: 0.8; }
        .stat-card.blue-light { background: #eff6ff; border-color: #b8d4f5; }
        .stat-card.blue-light .stat-icon { border-color: #b8d4f5; color: #0969da; }
        .stat-card.green-light { background: #ecfdf3; border-color: #a8e6c1; }
        .stat-card.green-light .stat-icon { border-color: #a8e6c1; color: #2da44e; }
        .stat-card.purple-light { background: #f5f0ff; border-color: #d4c4f0; }
        .stat-card.purple-light .stat-icon { border-color: #d4c4f0; color: #8250df; }
        .stat-card.orange-light { background: #fff3e0; border-color: #ffcc80; }
        .stat-card.orange-light .stat-icon { border-color: #ffcc80; color: #ff9800; }
        .stat-card.red-light { background: #fef2f2; border-color: #f5c8c8; }
        .stat-card.red-light .stat-icon { border-color: #f5c8c8; color: #cf222e; }

        .filters-bar {
            background: #ffffff; border-radius: 12px; border: 1px solid #e1e4e8;
            padding: 14px 20px; margin-bottom: 24px; display: flex; flex-wrap: wrap;
            gap: 12px; align-items: center; box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .filters-bar .filter-group { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .filters-bar input, .filters-bar select {
            padding: 8px 14px; border-radius: 6px; border: 1px solid #e1e4e8;
            font-size: 14px; font-family: 'Vazirmatn', sans-serif; background: #f8f9fa;
            outline: none; transition: 0.2s; color: #24292f;
        }
        .filters-bar input:focus, .filters-bar select:focus {
            border-color: #0969da; box-shadow: 0 0 0 3px rgba(9,105,218,0.1);
        }
        .filters-bar input[name="search"] { min-width: 250px; font-size: 15px; }
        .filters-bar .date-wrapper {
            position: relative;
            display: inline-block;
        }
        .filters-bar .date-wrapper input {
            padding-left: 35px;
            cursor: pointer;
            min-width: 140px;
            text-align: center;
        }
        .filters-bar .date-wrapper .calendar-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #8b949e;
            font-size: 16px;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        .filters-bar .date-wrapper .calendar-icon:hover { color: #0969da; }
        .filters-bar .btn-filter {
            padding: 8px 20px; background: #0969da; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif;
        }
        .filters-bar .btn-filter:hover { background: #0550b3; }
        .filters-bar .btn-reset {
            padding: 8px 20px; background: #f0f2f4; color: #57606a; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif;
        }
        .filters-bar .btn-reset:hover { background: #e1e4e8; }

        .actions-bar {
            display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;
            background: #ffffff; padding: 12px 20px; border-radius: 12px;
            border: 1px solid #e1e4e8;
        }
        .btn-action {
            padding: 10px 18px; border: none; border-radius: 10px; cursor: pointer;
            transition: all 0.3s ease; font-family: 'Vazirmatn', sans-serif;
            color: #fff; position: relative; overflow: hidden; min-width: 140px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .btn-action::before {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: all 0.5s ease;
        }
        .btn-action:hover::before { left: 100%; }
        .btn-action:hover { transform: translateY(-2px) scale(1.02); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .btn-action:active { transform: translateY(0px) scale(0.97); }
        .btn-add-expense { background: linear-gradient(135deg, #0f9d58, #0b7a44); }
        .btn-add-expense:hover { background: linear-gradient(135deg, #0c8b4a, #09693a); box-shadow: 0 8px 25px rgba(15,157,88,0.4); }
        .btn-category { background: linear-gradient(135deg, #7c3aed, #5b21b6); }
        .btn-category:hover { background: linear-gradient(135deg, #6d28d9, #4c1d95); box-shadow: 0 8px 25px rgba(124,58,237,0.4); }
        .btn-project { background: linear-gradient(135deg, #ea580c, #c2410c); }
        .btn-project:hover { background: linear-gradient(135deg, #d97706, #b45309); box-shadow: 0 8px 25px rgba(234,88,12,0.4); }

        .table-wrapper { background: #fff; border-radius: 12px; border: 1px solid #e1e4e8; overflow: hidden; flex: 1; }
        .table-responsive { overflow-x: auto; }
        .table-modern { width: 100%; border-collapse: collapse; font-size: 14px; }
        .table-modern th {
            text-align: right; padding: 12px 16px; font-weight: 600;
            color: #57606a; background: #f8f9fa; border-bottom: 1px solid #e1e4e8;
            font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .table-modern td { padding: 12px 16px; border-bottom: 1px solid #f0f2f4; vertical-align: middle; color: #24292f; }
        .table-modern tbody tr {
            transition: all 0.25s ease;
            border-right: 4px solid transparent;
        }
        .table-modern tbody tr:hover {
            transform: scale(1.01);
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            z-index: 2;
            position: relative;
        }

        .row-color-0 { background: #f0f9ff; border-right-color: #3b82f6; }
        .row-color-0:hover { background: #dbeafe; }
        .row-color-1 { background: #f0fdf4; border-right-color: #22c55e; }
        .row-color-1:hover { background: #dcfce7; }
        .row-color-2 { background: #f5f3ff; border-right-color: #8b5cf6; }
        .row-color-2:hover { background: #ede9fe; }
        .row-color-3 { background: #fff7ed; border-right-color: #f97316; }
        .row-color-3:hover { background: #ffedd5; }
        .row-color-4 { background: #fdf2f8; border-right-color: #ec4899; }
        .row-color-4:hover { background: #fce7f3; }
        .row-color-5 { background: #f0fdfa; border-right-color: #14b8a6; }
        .row-color-5:hover { background: #ccfbf1; }
        .row-color-6 { background: #fefce8; border-right-color: #eab308; }
        .row-color-6:hover { background: #fef9c3; }
        .row-color-7 { background: #fef2f2; border-right-color: #ef4444; }
        .row-color-7:hover { background: #fee2e2; }

        .badge-status { padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; white-space: nowrap; }
        .badge-category { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; display: inline-block; white-space: nowrap; }

        .table-modern .actions { display: flex; gap: 4px; flex-wrap: wrap; }
        .table-modern .actions .btn-icon {
            width: 32px; height: 32px; border-radius: 6px; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.2s; font-size: 13px;
            background: #f0f2f4; color: #57606a;
        }
        .table-modern .actions .btn-icon:hover { background: #e1e4e8; transform: scale(1.05); }
        .table-modern .actions .btn-icon.edit { color: #0969da; }
        .table-modern .actions .btn-icon.edit:hover { background: #ddf4ff; }
        .table-modern .actions .btn-icon.status { color: #ff9800; }
        .table-modern .actions .btn-icon.status:hover { background: #fff3e0; }
        .table-modern .actions .btn-icon.approve { color: #2da44e; }
        .table-modern .actions .btn-icon.approve:hover { background: #ddf4e6; }
        .table-modern .actions .btn-icon.reject { color: #c62828; }
        .table-modern .actions .btn-icon.reject:hover { background: #ffcdd2; }
        .table-modern .actions .btn-icon.delete { color: #991b1b; }
        .table-modern .actions .btn-icon.delete:hover { background: #fecaca; }
        .table-modern .actions .btn-icon.view { color: #8250df; }
        .table-modern .actions .btn-icon.view:hover { background: #f5f0ff; }
        .table-modern .actions .btn-icon.print { color: #0969da; }
        .table-modern .actions .btn-icon.print:hover { background: #dbeafe; }

        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            z-index: 2000; justify-content: center; align-items: center;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff; border-radius: 16px; padding: 30px 35px;
            max-width: 950px; width: 95%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: modalIn 0.3s ease;
        }
        @keyframes modalIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        .modal-box h2 { font-size: 20px; font-weight: 700; color: #1a1a2e; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .modal-box .form-group { margin-bottom: 16px; }
        .modal-box .form-group label { display: block; font-size: 13px; font-weight: 600; color: #57606a; margin-bottom: 4px; }
        .modal-box .form-group input, .modal-box .form-group select, .modal-box .form-group textarea {
            width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #e1e4e8;
            font-size: 14px; font-family: 'Vazirmatn', sans-serif; outline: none;
            transition: 0.2s; background: #f8f9fa;
        }
        .modal-box .form-group input:focus, .modal-box .form-group select:focus, .modal-box .form-group textarea:focus {
            border-color: #0969da; box-shadow: 0 0 0 3px rgba(9,105,218,0.1);
        }
        .modal-box .form-group textarea { min-height: 80px; resize: vertical; }
        .modal-box .form-row { display: flex; gap: 12px; flex-wrap: wrap; }
        .modal-box .form-row .form-group { flex: 1; min-width: 120px; }
        
        .items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .items-table th {
            background: #f8f9fa; padding: 8px 10px; border: 1px solid #e1e4e8;
            text-align: center; font-weight: 600; font-size: 12px;
        }
        .items-table td { padding: 6px 8px; border: 1px solid #e1e4e8; vertical-align: middle; }
        .items-table input, .items-table select {
            width: 100%; padding: 6px 8px; border: 1px solid #e1e4e8;
            border-radius: 4px; font-size: 13px; font-family: 'Vazirmatn', sans-serif;
            background: #fff;
        }
        .items-table input:focus, .items-table select:focus { border-color: #0969da; outline: none; }
        .items-table .btn-remove-row {
            background: #fecaca; color: #991b1b; border: none;
            border-radius: 4px; padding: 4px 8px; cursor: pointer; font-size: 14px;
        }
        .items-table .btn-remove-row:hover { background: #f5a0a0; }
        .btn-add-row {
            background: #d1fae5; color: #065f46; border: none;
            border-radius: 6px; padding: 6px 16px; cursor: pointer;
            font-size: 13px; font-weight: 600; font-family: 'Vazirmatn', sans-serif;
        }
        .btn-add-row:hover { background: #a8e6c1; }

        .modal-box .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; border-top: 1px solid #f0f2f4; padding-top: 16px; }
        .modal-box .modal-actions .btn { padding: 8px 24px; border-radius: 6px; border: none; font-size: 14px; font-family: 'Vazirmatn', sans-serif; cursor: pointer; transition: 0.2s; }
        .modal-box .modal-actions .btn-primary { background: #0969da; color: #fff; }
        .modal-box .modal-actions .btn-primary:hover { background: #0550b3; }
        .modal-box .modal-actions .btn-secondary { background: #f0f2f4; color: #57606a; }
        .modal-box .modal-actions .btn-secondary:hover { background: #e1e4e8; }
        .modal-box .modal-actions .btn-danger { background: #cf222e; color: #fff; }
        .modal-box .modal-actions .btn-danger:hover { background: #a0111f; }
        .modal-box .modal-actions .btn-success { background: #2da44e; color: #fff; }
        .modal-box .modal-actions .btn-success:hover { background: #22863a; }
        .sub-section { border-top: 2px dashed #e1e4e8; padding-top: 16px; margin-top: 8px; }
        .sub-section-title { font-size: 15px; font-weight: 700; color: #1a1a2e; margin-bottom: 12px; }
        .sub-section-title i { margin-left: 8px; color: #0969da; }

        /* ===== فیلد همراه ساده ===== */
        .companion-input {
            width: 100%;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #e1e4e8;
            font-size: 14px;
            font-family: 'Vazirmatn', sans-serif;
            background: #f8f9fa;
            outline: none;
            transition: 0.2s;
        }
        .companion-input:focus {
            border-color: #0969da;
            box-shadow: 0 0 0 3px rgba(9,105,218,0.1);
        }

        .date-wrapper {
            position: relative; display: flex; align-items: center; gap: 0;
        }
        .date-wrapper input {
            flex: 1; padding-left: 35px !important; cursor: pointer;
        }
        .date-wrapper .calendar-icon {
            position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
            color: #8b949e; font-size: 16px; cursor: pointer; background: none;
            border: none; padding: 0; z-index: 10;
        }
        .date-wrapper .calendar-icon:hover { color: #0969da; }

        .footer {
            flex-shrink: 0; background: #ffffff; border-top: 1px solid #e1e4e8;
            padding: 12px 20px; text-align: center; width: 100%; margin-top: auto;
        }
        .footer-content {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; flex-wrap: wrap; font-size: 14px; color: #57606a;
            direction: rtl;
        }
        .footer-content a { color: #0969da; font-weight: 500; transition: 0.2s; }
        .footer-content a:hover { color: #0550b3; text-decoration: underline; }
        .footer-divider { color: #d0d7de; }
        .footer-version { font-size: 12px; color: #8b949e; background: #f6f8fa; padding: 2px 12px; border-radius: 12px; }

        .mobile-toggle { display: none; position: fixed; top: 16px; right: 16px; z-index: 1001; background: #fff; border: 1px solid #e1e4e8; border-radius: 6px; padding: 10px 12px; font-size: 18px; color: #24292f; cursor: pointer; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 999; }
        .sidebar-overlay.active { display: block; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 500; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a8e6c1; }
        .alert-danger { background: #fecaca; color: #991b1b; border: 1px solid #f5c8c8; }

        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 991px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .sidebar { right: -260px; width: 260px; }
            .sidebar.open { right: 0; }
            .main-content { margin-right: 0; padding: 20px 16px 20px 16px; }
            .mobile-toggle { display: flex !important; }
            .top-bar { flex-direction: column; align-items: flex-start; gap: 12px; }
            .top-right { width: 100%; flex-wrap: wrap; }
            .user-profile { flex: 1; flex-wrap: wrap; justify-content: space-between; padding: 8px 12px; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .filters-bar .filter-group { flex-wrap: wrap; }
            .filters-bar input[name="search"] { min-width: 100%; }
            .modal-box { padding: 20px; }
            .modal-box .form-row { flex-direction: column; }
            .modal-box .form-row .form-group { min-width: 100%; }
            .actions-bar { flex-direction: column; }
            .btn-action { min-width: 100%; justify-content: center; }
            .items-table { font-size: 12px; }
            .items-table th, .items-table td { padding: 4px 6px; }
            .items-table input, .items-table select { font-size: 12px; padding: 4px 6px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .stat-card .stat-number { font-size: 18px; }
            .top-bar h1 { font-size: 18px; }
            .table-modern { font-size: 13px; }
            .table-modern th, .table-modern td { padding: 8px 10px; }
            .table-modern .actions .btn-icon { width: 28px; height: 28px; font-size: 11px; }
            .footer-content { font-size: 12px; flex-direction: column; gap: 6px; }
            .footer-divider { display: none; }
        }
    </style>
</head>
<body>

<!-- ===== سایدبار ===== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand"><i class="fas fa-heartbeat"></i> سامانه مدیریت پیشگامان</div>
        <div class="sub">پنل مدیریت</div>
    </div>
   <ul class="sidebar-menu">
        <li class="label">منو</li>
        <li><a href="dashboard.php"><i class="fas fa-chart-pie"></i> داشبورد</a></li>
        <li><a href="secretariat.php"><i class="fas fa-inbox"></i> دبیرخانه</a></li>
        <li><a href="reminder.php"><i class="fas fa-bell"></i> یادآور</a></li>
        <li><a href="travel_expenses.php" class="active"><i class="fas fa-route"></i>  ماموریت</a></li>
        <li><a href="assets.php"><i class="fas fa-boxes"></i> بانک اموال</a></li>
        <li><a href="knowledge.php"><i class="fas fa-database"></i> بانک دانش</a></li>
        <li><a href="links.php"><i class="fas fa-link"></i> بانک لینک</a></li>
        <li><a href="downloads.php"><i class="fas fa-download"></i> مرکز دانلود</a></li>
        <li><a href="users.php"><i class="fas fa-users"></i> مدیریت کاربران</a></li>
        <li class="divider"></li>
        <li class="label">سیستم</li>
        <li><a href="settings.php"><i class="fas fa-cog"></i> تنظیمات</a></li>
    </ul>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> خروج</a>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ===== محتوای اصلی ===== -->
<main class="main-content">

    <!-- ===== هدر ===== -->
    <div class="top-bar-wrapper">
        <div class="top-bar">
            <h1>
                <i class="fas fa-route" style="color:#0969da; margin-left:10px;"></i>
                هزینه‌های ماموریت
                <span>مدیریت هزینه‌های سفر و ماموریت</span>
            </h1>
            <div class="top-right">
                <div class="date"><i class="fas fa-calendar"></i> <?= persian_number_str(nowShamsi1('Y/m/d H:i')) ?></div>
                <div class="user-profile">
                    <div class="user-avatar"><?= mb_substr($user['full_name'] ?? $user['national_id'] ?? 'کاربر', 0, 1, 'UTF-8') ?></div>
                    <div>
                        <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? $user['national_id'] ?? 'کاربر') ?></div>
                        <div class="user-role"><?= htmlspecialchars($currentRole ?? 'بدون نقش') ?></div>
                    </div>
                    <?php if (count($userRoles) > 1): ?>
                    <form method="POST" class="role-switcher">
                        <select name="switch_role">
                            <?php foreach ($userRoles as $role): ?>
                                <option value="<?= htmlspecialchars($role) ?>" <?= $role === $currentRole ? 'selected' : '' ?>><?= htmlspecialchars($role) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-switch"><i class="fas fa-sync-alt"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== آمار ===== -->
    <div class="stats-grid">
        <div class="stat-card blue-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-list"></i></span><span class="stat-number"><?= persian_number($stats['total']) ?></span></div><div class="stat-label">کل هزینه‌ها</div></div>
        <div class="stat-card orange-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-clock"></i></span><span class="stat-number"><?= persian_number($stats['pending']) ?></span></div><div class="stat-label">در انتظار تایید</div></div>
        <div class="stat-card green-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-check"></i></span><span class="stat-number"><?= persian_number($stats['approved']) ?></span></div><div class="stat-label">تایید شده</div></div>
        <div class="stat-card red-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-times"></i></span><span class="stat-number"><?= persian_number($stats['rejected']) ?></span></div><div class="stat-label">رد شده</div></div>
        <div class="stat-card purple-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-rial"></i></span><span class="stat-number"><?= persian_number(number_format($stats['total_amount'])) ?></span></div><div class="stat-label">مجموع هزینه (ریال)</div></div>
    </div>

    <!-- ===== فیلترها ===== -->
    <form class="filters-bar" method="GET">
        <div class="filter-group">
            <input type="text" name="search" placeholder="جستجوی نام..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        </div>
        <div class="filter-group">
            <select name="category">
                <option value="">همه دسته‌ها</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= (isset($_GET['category']) && $_GET['category'] == $cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <select name="status">
                <option value="">همه وضعیت‌ها</option>
                <option value="pending" <?= (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : '' ?>>در انتظار</option>
                <option value="approved" <?= (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : '' ?>>تایید شده</option>
                <option value="rejected" <?= (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'selected' : '' ?>>رد شده</option>
            </select>
        </div>
        <div class="filter-group">
            <select name="project">
                <option value="">همه پروژه‌ها</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= (isset($_GET['project']) && $_GET['project'] == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
  <div class="filter-group">
    <div class="date-wrapper">
        <input type="text" id="dateFrom" class="persian-date" placeholder="از تاریخ" value="<?= htmlspecialchars(persian_number($_GET['date_from'] ?? '')) ?>">
        <button type="button" class="calendar-icon" onclick="openDatePicker(this)">
            <i class="fas fa-calendar-alt"></i>
        </button>
    </div>
    <span style="color:#8b93a5;">تا</span>
    <div class="date-wrapper">
        <input type="text" id="dateTo" class="persian-date" placeholder="تا تاریخ" value="<?= htmlspecialchars(persian_number($_GET['date_to'] ?? '')) ?>">
        <button type="button" class="calendar-icon" onclick="openDatePicker(this)">
            <i class="fas fa-calendar-alt"></i>
        </button>
    </div>
</div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> فیلتر</button>
        <a href="travel_expenses.php" class="btn-reset"><i class="fas fa-undo"></i> پاک کردن</a>
    </form>

    <!-- ===== دکمه‌های عملیات ===== -->
    <div class="actions-bar">
        <button class="btn-action btn-add-expense" id="btnAddExpense" onclick="openAddModal()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">ثبت هزینه جدید</div>
                    <div style="font-size:10px;opacity:0.8;">ثبت هزینه ماموریت</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-category" onclick="openCategoryModal()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-tags"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">دسته‌بندی</div>
                    <div style="font-size:10px;opacity:0.8;">مدیریت دسته‌ها</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-project" onclick="openProjectModal()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-project-diagram"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">پروژه‌ها</div>
                    <div style="font-size:10px;opacity:0.8;">مدیریت پروژه‌ها</div>
                </div>
            </div>
        </button>
    </div>

    <!-- ===== جدول هزینه‌ها ===== -->
    <div class="table-wrapper">
        <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="min-width:180px; max-width:250px; white-space:nowrap;">نام متقاضی</th>
                        <th style="width:100px;">تاریخ</th>
                        <th>مبدا → مقصد</th>
                        <th style="width:100px;">مبلغ (ریال)</th>
                        <th style="width:100px;">وضعیت</th>
                        <th style="width:200px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:#8b93a5;">هیچ هزینه‌ای ثبت نشده است.</td></tr>
                    <?php else: $rowIndex = 0; foreach ($expenses as $item): 
                        $status = getStatusStyle($item['status']);
                        $total = $item['total_amount'] ?? 0;
                        $rowColor = 'row-color-' . ($rowIndex % 8);
                        $rowIndex++;
                    ?>
                        <tr class="<?= $rowColor ?>">
                            <td style="font-weight:700;"><?= persian_number($rowIndex) ?></td>
                            <td style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($item['full_name'] ?? $item['user_name']) ?></td>
                            <td style="font-size:13px;color:#57606a;">
                                <?= persian_number_str($item['travel_date']) ?>
                                <?php if ($item['end_date']): ?><br><small>تا <?= persian_number_str($item['end_date']) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['origin_city'] || $item['destination_city']): ?>
                                    <span style="color:#0969da;"><i class="fas fa-map-marker-alt"></i></span>
                                    <?= htmlspecialchars($item['origin_city'] ?? '-') ?> 
                                    <span style="color:#0969da; font-weight:bold; font-size:14px; margin:0 4px;">←</span> 
                                    <?= htmlspecialchars($item['destination_city'] ?? '-') ?>
                                <?php else: ?>
                                    <span style="color:#57606a;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;color:#1a2332;"><?= persian_number(number_format($total)) ?></td>
                            <td>
                                <span class="badge-status" style="background:<?= $status['bg'] ?>;color:<?= $status['color'] ?>;">
                                    <i class="fas <?= $status['icon'] ?>" style="margin-left:4px;font-size:10px;"></i>
                                    <?= $status['label'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($item['status'] === 'pending' && in_array($user['role'], ['admin', 'manager', 'finance'])): ?>
                                        <button class="btn-icon approve" onclick="changeStatus(<?= $item['id'] ?>, 'approved')" title="تایید"><i class="fas fa-check"></i></button>
                                        <button class="btn-icon reject" onclick="changeStatus(<?= $item['id'] ?>, 'rejected')" title="رد"><i class="fas fa-times"></i></button>
                                    <?php endif; ?>
                                    <a href="print_expense.php?id=<?= $item['id'] ?>" target="_blank" class="btn-icon print" title="پرینت"><i class="fas fa-print"></i></a>
                                    <a href="view_expense.php?id=<?= $item['id'] ?>" target="_blank" class="btn-icon view" title="مشاهده"><i class="fas fa-eye"></i></a>
                                    <?php if ($item['status'] === 'pending' && ($item['user_id'] == $user['id'] || in_array($user['role'], ['admin', 'manager']))): ?>
                                        <button class="btn-icon edit" onclick="editExpense(<?= $item['id'] ?>)" title="ویرایش"><i class="fas fa-edit"></i></button>
                                    <?php endif; ?>
                                    <?php if (in_array($user['role'], ['admin', 'manager']) || $item['user_id'] == $user['id']): ?>
                                        <button class="btn-icon status" onclick="quickChangeStatus(<?= $item['id'] ?>)" title="تغییر وضعیت"><i class="fas fa-exchange-alt"></i></button>
                                    <?php endif; ?>
                                    <button class="btn-icon delete" onclick="deleteExpense(<?= $item['id'] ?>)" title="حذف"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- ===== فوتر ===== -->
<div class="footer">
    <div class="footer-content">
        <span>طراحی و توسعه: <a href="#" target="_blank">شرکت دانش بنیان پیشگامان دنیای فناوری</a></span>
        <span class="footer-divider">|</span>
        <span class="footer-version">نسخه 2.0 - هزینه‌های ماموریت</span>
    </div>
</div>

<!-- ============================================= -->
<!-- ===== مودال‌ها ===== -->
<!-- ============================================= -->

<!-- ===== مودال ثبت/ویرایش هزینه ===== -->
<div class="modal-overlay" id="expenseModal">
    <div class="modal-box">
        <h2 id="expenseModalTitle"><i class="fas fa-plus-circle" style="color:#0f9d58;"></i> ثبت هزینه جدید</h2>
        <form method="POST" enctype="multipart/form-data" id="expenseForm">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" id="expense_action" value="add">
            <input type="hidden" name="expense_id" id="expense_id" value="0">

            <!-- ===== اطلاعات اصلی ===== -->
            <div class="form-row">
                <div class="form-group">
                    <label>نام و نام خانوادگی <span style="color:red;">*</span></label>
                    <select name="full_name" id="full_name" required style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #e1e4e8; font-size:14px; font-family:'Vazirmatn', sans-serif; background:#f8f9fa;">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= htmlspecialchars($u['full_name']) ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>تاریخ شروع <span style="color:red;">*</span></label>
                    <div class="date-wrapper">
                        <input type="text" name="start_date" id="start_date" class="persian-date" data-jdp value="<?= jdate('Y/m/d') ?>" autocomplete="off">
                        <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();"><i class="fas fa-calendar-alt"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label>تاریخ اتمام</label>
                    <div class="date-wrapper">
                        <input type="text" name="end_date" id="end_date" class="persian-date" data-jdp value="<?= jdate('Y/m/d') ?>" autocomplete="off">
                        <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();"><i class="fas fa-calendar-alt"></i></button>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>مبدا (استان)</label>
                    <select name="origin_province" id="origin_province" onchange="loadCities('origin')">
                        <option value="">انتخاب استان</option>
                        <?php foreach ($provinces as $prov): ?>
                            <option value="<?= $prov['id'] ?>"><?= htmlspecialchars($prov['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>شهر مبدا</label>
                    <select name="origin_city" id="origin_city">
                        <option value="">ابتدا استان را انتخاب کنید</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>مقصد (استان)</label>
                    <select name="destination_province" id="destination_province" onchange="loadCities('destination')">
                        <option value="">انتخاب استان</option>
                        <?php foreach ($provinces as $prov): ?>
                            <option value="<?= $prov['id'] ?>"><?= htmlspecialchars($prov['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>شهر مقصد</label>
                    <select name="destination_city" id="destination_city">
                        <option value="">ابتدا استان را انتخاب کنید</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>نام پروژه</label>
                    <select name="project_id" id="project_id">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>افراد همراه</label>
                    <input type="text" name="companions" id="companions_input" class="companion-input" placeholder="نام همراهان را با کاما جدا کنید..." value="">
                    <small style="color:#57606a; font-size:12px; display:block; margin-top:4px;">نام همراهان را با کاما (،) یا ویرگول انگلیسی (,) جدا کنید.</small>
                </div>
            </div>

            <!-- ===== جدول هزینه‌ها ===== -->
            <div class="sub-section">
                <div class="sub-section-title"><i class="fas fa-table"></i> لیست هزینه‌ها</div>
                <div style="overflow-x:auto;">
                    <table class="items-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="width:15%;">هزینه (ریال)</th>
                                <th style="width:15%;">دسته‌بندی</th>
                                <th style="width:15%;">نوع پرداخت</th>
                                <th style="width:15%;">نوع سند</th>
                                <th style="width:30%;">توضیحات</th>
                                <th style="width:10%;">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <!-- ردیف‌ها توسط JS اضافه می‌شوند -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" style="text-align:center;padding:8px;">
                                    <button type="button" class="btn-add-row" onclick="addRow()"><i class="fas fa-plus-circle"></i> افزودن ردیف جدید</button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('expenseModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره هزینه</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال تغییر وضعیت ===== -->
<div class="modal-overlay" id="quickStatusModal">
    <div class="modal-box">
        <h2><i class="fas fa-exchange-alt" style="color:#ff9800;"></i> تغییر وضعیت</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="expense_id" id="quick_status_expense_id" value="0">
            <div class="form-group">
                <label>وضعیت جدید</label>
                <select name="new_status" id="quick_status_select">
                    <option value="pending">در انتظار تایید</option>
                    <option value="approved">تایید شده</option>
                    <option value="rejected">رد شده</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('quickStatusModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">تغییر وضعیت</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال مدیریت دسته‌بندی ===== -->
<div class="modal-overlay" id="categoryModal">
    <div class="modal-box">
        <h2><i class="fas fa-tags" style="color:#8250df;"></i> مدیریت دسته‌بندی هزینه‌ها</h2>
        <div style="max-height:200px;overflow-y:auto;margin-bottom:16px;border:1px solid #e1e4e8;border-radius:8px;">
            <table class="category-table" style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead><tr style="background:#f8f9fa;border-bottom:2px solid #e1e4e8;"><th style="padding:8px 10px;text-align:right;">نام</th><th style="padding:8px 10px;text-align:center;width:50px;">رنگ</th><th style="padding:8px 10px;text-align:center;width:120px;">عملیات</th></tr></thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr style="border-bottom:2px solid #e1e4e8;">
                        <td style="padding:8px 10px;"><?= htmlspecialchars($cat['name']) ?></td>
                        <td style="padding:8px 10px;text-align:center;"><span style="display:inline-block;width:24px;height:24px;border-radius:50%;background:<?= htmlspecialchars($cat['color'] ?? '#6c757d') ?>;border:1px solid #ddd;"></span></td>
                        <td style="padding:8px 10px;text-align:center;">
                            <button class="btn btn-warning btn-sm" style="border:none;border-radius:6px;padding:4px 10px;background:#fef3c7;color:#92400e;cursor:pointer;margin-left:4px;" onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name']) ?>', '<?= htmlspecialchars($cat['color'] ?? '#0969da') ?>', '<?= htmlspecialchars($cat['icon'] ?? 'fa-tag') ?>')"><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟')">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="border:none;border-radius:6px;padding:4px 10px;background:#fecaca;color:#991b1b;cursor:pointer;"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="add_category">
            <input type="hidden" name="category_action" id="category_action" value="add_category">
            <input type="hidden" name="cat_id" id="cat_id" value="0">
            <div class="form-row">
                <div class="form-group"><label>نام دسته‌بندی</label><input type="text" name="cat_name" id="cat_name" required placeholder="مثلاً: سوخت"></div>
                <div class="form-group" style="flex:0.5;"><label>رنگ</label><input type="color" name="cat_color" id="cat_color" value="#0969da" style="padding:2px;height:42px;cursor:pointer;"></div>
                <div class="form-group" style="flex:0.5;"><label>آیکون</label><input type="text" name="cat_icon" id="cat_icon" value="fa-tag" placeholder="fa-tag"></div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('categoryModal')">بستن</button>
                <button type="submit" class="btn btn-success" id="categorySubmitBtn">افزودن</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال مدیریت پروژه ===== -->
<div class="modal-overlay" id="projectModal">
    <div class="modal-box">
        <h2><i class="fas fa-project-diagram" style="color:#ea580c;"></i> مدیریت پروژه‌ها</h2>
        <div style="max-height:200px;overflow-y:auto;margin-bottom:16px;border:1px solid #e1e4e8;border-radius:8px;">
            <table class="project-table" style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead><tr style="background:#f8f9fa;border-bottom:2px solid #e1e4e8;"><th style="padding:8px 10px;text-align:right;">نام</th><th style="padding:8px 10px;text-align:right;">توضیحات</th><th style="padding:8px 10px;text-align:center;width:120px;">عملیات</th></tr></thead>
                <tbody>
                    <?php foreach ($projects as $p): ?>
                    <tr style="border-bottom:2px solid #e1e4e8;">
                        <td style="padding:8px 10px;"><?= htmlspecialchars($p['name']) ?></td>
                        <td style="padding:8px 10px;color:#57606a;font-size:13px;"><?= htmlspecialchars($p['description'] ?? '-') ?></td>
                        <td style="padding:8px 10px;text-align:center;">
                            <button class="btn btn-warning btn-sm" style="border:none;border-radius:6px;padding:4px 10px;background:#fef3c7;color:#92400e;cursor:pointer;margin-left:4px;" onclick="editProject(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name']) ?>', '<?= htmlspecialchars($p['description'] ?? '') ?>')"><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟')">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="delete_project">
                                <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="border:none;border-radius:6px;padding:4px 10px;background:#fecaca;color:#991b1b;cursor:pointer;"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="add_project">
            <input type="hidden" name="project_action" id="project_action" value="add_project">
            <input type="hidden" name="project_id" id="project_id_edit" value="0">
            <div class="form-row">
                <div class="form-group"><label>نام پروژه <span style="color:red;">*</span></label><input type="text" name="project_name" id="project_name" required placeholder="مثلاً: پروژه توسعه نرم‌افزار"></div>
                <div class="form-group"><label>توضیحات</label><input type="text" name="project_description" id="project_description" placeholder="توضیحات پروژه"></div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('projectModal')">بستن</button>
                <button type="submit" class="btn btn-success" id="projectSubmitBtn">افزودن پروژه</button>
            </div>
        </form>
    </div>
</div>

<button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>

<script>
// ===== شهرها =====
var citiesData = <?= $cityMapJson ?>;

function loadCities(type) {
    var provinceSelect = document.getElementById(type + '_province');
    var citySelect = document.getElementById(type + '_city');
    var provinceId = provinceSelect.value;
    
    citySelect.innerHTML = '<option value="">ابتدا استان را انتخاب کنید</option>';
    if (provinceId && citiesData[provinceId]) {
        citiesData[provinceId].forEach(function(city) {
            var opt = document.createElement('option');
            opt.value = city.id;
            opt.textContent = city.name;
            citySelect.appendChild(opt);
        });
    }
}

// ===== فرمت عدد =====
function formatNumberInput(input) {
    var value = input.value.replace(/,/g, '');
    if (value === '') return;
    var num = parseInt(value);
    if (!isNaN(num)) {
        input.value = num.toLocaleString('en');
    }
}

// ===== مدیریت ردیف‌های هزینه =====
var rowCount = 0;
var categories = <?= json_encode($categories) ?>;

function addRow(data) {
    rowCount++;
    var tbody = document.getElementById('itemsBody');
    var tr = document.createElement('tr');
    tr.id = 'row_' + rowCount;
    
    var catOptions = '<option value="">انتخاب</option>';
    categories.forEach(function(c) {
        catOptions += '<option value="' + c.id + '">' + c.name + '</option>';
    });
    
    tr.innerHTML = `
        <td><input type="text" name="item_amount[]" class="item-amount" placeholder="مبلغ" value="${data ? data.amount : ''}" oninput="formatNumberInput(this); this.value = this.value.replace(/[^0-9,]/g, '');" style="width:100%;padding:6px 8px;border:1px solid #e1e4e8;border-radius:4px;font-size:13px;font-family:'Vazirmatn',sans-serif;background:#fff;text-align:center;direction:ltr;"></td>
        <td><select name="item_category[]" style="width:100%;padding:6px 8px;border:1px solid #e1e4e8;border-radius:4px;font-size:13px;font-family:'Vazirmatn',sans-serif;background:#fff;">${catOptions}</select></td>
        <td>
            <select name="item_payment_type[]" style="width:100%;padding:6px 8px;border:1px solid #e1e4e8;border-radius:4px;font-size:13px;font-family:'Vazirmatn',sans-serif;background:#fff;">
                <option value="cash" ${data && data.payment_type === 'cash' ? 'selected' : ''}>نقدی</option>
                <option value="non_cash" ${data && data.payment_type === 'non_cash' ? 'selected' : ''}>غیرنقدی</option>
                <option value="card" ${data && data.payment_type === 'card' ? 'selected' : ''}>کارت بانکی</option>
                <option value="check" ${data && data.payment_type === 'check' ? 'selected' : ''}>چک</option>
                <option value="transfer" ${data && data.payment_type === 'transfer' ? 'selected' : ''}>اینترنتی</option>
            </select>
        </td>
        <td>
            <select name="item_document_type[]" style="width:100%;padding:6px 8px;border:1px solid #e1e4e8;border-radius:4px;font-size:13px;font-family:'Vazirmatn',sans-serif;background:#fff;">
                <option value="invoice" ${data && data.document_type === 'invoice' ? 'selected' : ''}>فاکتور</option>
                <option value="receipt" ${data && data.document_type === 'receipt' ? 'selected' : ''}>رسید</option>
                <option value="bill" ${data && data.document_type === 'bill' ? 'selected' : ''}>قبض</option>
                <option value="other" ${data && data.document_type === 'other' ? 'selected' : ''}>سایر</option>
            </select>
        </td>
        <td><input type="text" name="item_description[]" placeholder="توضیحات" value="${data ? data.description : ''}" style="width:100%;padding:6px 8px;border:1px solid #e1e4e8;border-radius:4px;font-size:13px;font-family:'Vazirmatn',sans-serif;background:#fff;"></td>
        <td style="text-align:center;"><button type="button" class="btn-remove-row" onclick="removeRow('row_${rowCount}')" style="background:#fecaca;color:#991b1b;border:none;border-radius:4px;padding:4px 8px;cursor:pointer;font-size:14px;"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
}

function removeRow(rowId) {
    var row = document.getElementById(rowId);
    if (row) {
        var tbody = document.getElementById('itemsBody');
        if (tbody.children.length > 1) {
            row.remove();
        } else {
            alert('حداقل یک ردیف باید وجود داشته باشد.');
        }
    }
}

function clearItems() {
    var tbody = document.getElementById('itemsBody');
    tbody.innerHTML = '';
    rowCount = 0;
    addRow();
}

// ===== مودال‌ها =====
function openAddModal() {
    document.getElementById('expenseModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color:#0f9d58;"></i> ثبت هزینه جدید';
    document.getElementById('expense_action').value = 'add';
    document.getElementById('expense_id').value = 0;
    document.getElementById('full_name').value = '';
    document.getElementById('start_date').value = '<?= jdate('Y/m/d') ?>';
    document.getElementById('end_date').value = '<?= jdate('Y/m/d') ?>';
    document.getElementById('origin_province').value = '';
    document.getElementById('origin_city').innerHTML = '<option value="">ابتدا استان را انتخاب کنید</option>';
    document.getElementById('destination_province').value = '';
    document.getElementById('destination_city').innerHTML = '<option value="">ابتدا استان را انتخاب کنید</option>';
    document.getElementById('project_id').value = '';
    document.getElementById('companions_input').value = '';
    
    clearItems();
    document.getElementById('expenseModal').classList.add('active');
}

function editExpense(id) {
    fetch('get_expense.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('expenseModalTitle').innerHTML = '<i class="fas fa-edit" style="color:#0969da;"></i> ویرایش هزینه';
                document.getElementById('expense_action').value = 'edit';
                document.getElementById('expense_id').value = id;
                document.getElementById('full_name').value = data.full_name || '';
                document.getElementById('start_date').value = data.travel_date || '';
                document.getElementById('end_date').value = data.end_date || '';
                document.getElementById('origin_province').value = data.origin_province_id || '';
                loadCities('origin');
                setTimeout(function() {
                    document.getElementById('origin_city').value = data.origin_city_id || '';
                }, 300);
                document.getElementById('destination_province').value = data.destination_province_id || '';
                loadCities('destination');
                setTimeout(function() {
                    document.getElementById('destination_city').value = data.destination_city_id || '';
                }, 300);
                document.getElementById('project_id').value = data.project_id || '';
                document.getElementById('companions_input').value = data.companions || '';
                
                var tbody = document.getElementById('itemsBody');
                tbody.innerHTML = '';
                rowCount = 0;
                if (data.items && data.items.length > 0) {
                    data.items.forEach(function(item) {
                        addRow({
                            amount: item.amount,
                            category_id: item.category_id,
                            payment_type: item.payment_type,
                            document_type: item.document_type,
                            description: item.description
                        });
                    });
                } else {
                    addRow();
                }
                
                document.getElementById('expenseModal').classList.add('active');
            }
        })
        .catch(error => console.error('خطا:', error));
}

function deleteExpense(id) {
    if (confirm('آیا از حذف این هزینه مطمئن هستید؟')) {
        var form = document.createElement('form');
        form.method = 'POST';
        var csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = '<?= $csrfToken ?>';
        var action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'delete';
        var expenseId = document.createElement('input');
        expenseId.type = 'hidden';
        expenseId.name = 'expense_id';
        expenseId.value = id;
        form.appendChild(csrf);
        form.appendChild(action);
        form.appendChild(expenseId);
        document.body.appendChild(form);
        form.submit();
    }
}

function changeStatus(id, status) {
    if (confirm('آیا از تغییر وضعیت این هزینه به "' + (status === 'approved' ? 'تایید شده' : 'رد شده') + '" مطمئن هستید؟')) {
        var form = document.createElement('form');
        form.method = 'POST';
        var csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = '<?= $csrfToken ?>';
        var action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'change_status';
        var expenseId = document.createElement('input');
        expenseId.type = 'hidden';
        expenseId.name = 'expense_id';
        expenseId.value = id;
        var newStatus = document.createElement('input');
        newStatus.type = 'hidden';
        newStatus.name = 'new_status';
        newStatus.value = status;
        form.appendChild(csrf);
        form.appendChild(action);
        form.appendChild(expenseId);
        form.appendChild(newStatus);
        document.body.appendChild(form);
        form.submit();
    }
}

function quickChangeStatus(id) {
    document.getElementById('quick_status_expense_id').value = id;
    document.getElementById('quick_status_select').value = 'pending';
    document.getElementById('quickStatusModal').classList.add('active');
}

function openCategoryModal() { document.getElementById('categoryModal').classList.add('active'); }
function openProjectModal() { document.getElementById('projectModal').classList.add('active'); }

function editCategory(id, name, color, icon) {
    document.getElementById('category_action').value = 'edit_category';
    document.getElementById('cat_id').value = id;
    document.getElementById('cat_name').value = name;
    document.getElementById('cat_color').value = color || '#0969da';
    document.getElementById('cat_icon').value = icon || 'fa-tag';
    document.getElementById('categorySubmitBtn').textContent = 'ویرایش دسته‌بندی';
    document.getElementById('categoryModal').classList.add('active');
}

function editProject(id, name, description) {
    document.getElementById('project_action').value = 'edit_project';
    document.getElementById('project_id_edit').value = id;
    document.getElementById('project_name').value = name;
    document.getElementById('project_description').value = description || '';
    document.getElementById('projectSubmitBtn').textContent = 'ویرایش پروژه';
    document.getElementById('projectModal').classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    if (id === 'categoryModal') {
        document.getElementById('categorySubmitBtn').textContent = 'افزودن';
        document.getElementById('category_action').value = 'add_category';
    }
    if (id === 'projectModal') {
        document.getElementById('projectSubmitBtn').textContent = 'افزودن پروژه';
        document.getElementById('project_action').value = 'add_project';
    }
}

// ===== راه‌اندازی اولیه =====
document.addEventListener('DOMContentLoaded', function() {
    // ===== راه‌اندازی تاریخ شمسی - فقط یک بار =====
    if (typeof jalaliDatepicker !== 'undefined') {
        jalaliDatepicker.startWatch({
            minDate: 'attr',
            maxDate: 'attr',
            persianDigit: true,
            autoHide: true,
            zIndex: 3000   // بیشتر از z-index مودال (2000)
        });
    }
    
    var btn = document.getElementById('btnAddExpense');
    if (btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            openAddModal();
        });
    }
    
    var mobileToggle = document.getElementById('mobileToggle');
    var sidebarOverlay = document.getElementById('sidebarOverlay');
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
            sidebarOverlay.classList.toggle('active');
        });
    }
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            document.getElementById('sidebar').classList.remove('open');
            sidebarOverlay.classList.remove('active');
        });
    }
    
// بعد از انتخاب تاریخ توسط کاربر، مقدار رو به فارسی تبدیل کن
document.querySelectorAll('.persian-date').forEach(function(input) {
    input.addEventListener('change', function() {
        var value = this.value;
        // تبدیل اعداد انگلیسی به فارسی
        var persianDigits = '۰۱۲۳۴۵۶۷۸۹';
        var englishDigits = '0123456789';
        var persianValue = value.replace(/[0-9]/g, function(w) {
            return persianDigits[englishDigits.indexOf(w)];
        });
        this.value = persianValue;
    });
});
    
    clearItems();
});
</script>

</body>
</html>