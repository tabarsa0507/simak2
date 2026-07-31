<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'بانک اموال';

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

$userRoles = getUserRoles($user['id']);
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

// ===== دریافت دسته‌بندی‌ها =====
$categories = $pdo->query("SELECT * FROM asset_categories ORDER BY name")->fetchAll();

// ===== دریافت لیست کاربران برای واگذاری =====
$users = $pdo->query("SELECT id, full_name, national_id as username FROM users WHERE status = 1 ORDER BY full_name")->fetchAll();
// ===== دریافت لیست پروژه‌ها و واحدها =====
$projects = $pdo->query("SELECT * FROM projects WHERE status = 1 ORDER BY name")->fetchAll();
$units = $pdo->query("SELECT * FROM units WHERE status = 1 ORDER BY name")->fetchAll();

// ===== پردازش فیلترها =====
$where = [];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where[] = "(a.title LIKE :search OR a.code LIKE :search OR a.description LIKE :search)";
    $params[':search'] = '%' . $_GET['search'] . '%';
}
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $where[] = "a.category_id = :category";
    $params[':category'] = $_GET['category'];
}
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $where[] = "a.status = :status";
    $params[':status'] = $_GET['status'];
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ===== دریافت کل اموال =====
$query = "
    SELECT a.*, 
           c.name as category_name, 
           c.color as category_color,
           u.full_name as creator_name,
           (SELECT full_name FROM users WHERE id = aa.assigned_to) as assigned_to_name,
           aa.shamsi_assigned_date,
           aa.shamsi_return_date,
           aa.project,
           aa.unit
    FROM assets a
    LEFT JOIN asset_categories c ON a.category_id = c.id
    LEFT JOIN users u ON a.created_by = u.id
    LEFT JOIN asset_assignments aa ON a.id = aa.asset_id AND aa.status = 'assigned'
    $whereClause
    GROUP BY a.id
    ORDER BY a.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$assets = $stmt->fetchAll();

// ===== آمار =====
$stats = [
    'total' => count($assets),
    'available' => count(array_filter($assets, fn($a) => $a['status'] == 'available')),
    'assigned' => count(array_filter($assets, fn($a) => $a['status'] == 'assigned')),
    'damaged' => count(array_filter($assets, fn($a) => $a['status'] == 'damaged')),
    'depreciated' => count(array_filter($assets, fn($a) => $a['status'] == 'depreciated'))
];

// ===== تابع وضعیت =====
function getAssetStatus($status) {
    $statuses = [
        'available' => ['label' => 'موجود', 'color' => '#d1fae5', 'text' => '#065f46'],
        'assigned' => ['label' => 'واگذار شده', 'color' => '#dbeafe', 'text' => '#1e40af'],
        'damaged' => ['label' => 'خراب', 'color' => '#fecaca', 'text' => '#991b1b'],
        'depreciated' => ['label' => 'مستهلک', 'color' => '#fef3c7', 'text' => '#92400e']
    ];
    return $statuses[$status] ?? $statuses['available'];
}

// ===== پردازش عملیات =====
if (isset($_POST['asset_action'])) {
    $action = $_POST['asset_action'];
    $asset_id = $_POST['asset_id'] ?? 0;
    $title = trim($_POST['title'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $status = $_POST['status'] ?? 'available';
    $purchase_price = !empty($_POST['purchase_price']) ? (float)$_POST['purchase_price'] : null;
    $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
    
    $shamsi_date = isset($_POST['created_date']) ? trim($_POST['created_date']) : nowShamsi();
    
    // ===== پردازش واگذاری =====
    $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
    $project = trim($_POST['project'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $shamsi_assigned_date = !empty($_POST['shamsi_assigned_date']) ? trim($_POST['shamsi_assigned_date']) : nowShamsi();
    $shamsi_return_date = !empty($_POST['shamsi_return_date']) ? trim($_POST['shamsi_return_date']) : null;
    
    try {
        if ($action === 'add' && !empty($title)) {
            $stmt = $pdo->prepare("INSERT INTO assets (title, code, description, category_id, status, purchase_price, purchase_date, created_by, created_at, shamsi_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->execute([$title, $code, $description, $category_id, $status, $purchase_price, $purchase_date, $user['id'], $shamsi_date]);
            $asset_id = $pdo->lastInsertId();
            
            // اگر واگذاری هم انجام شده
            if ($status === 'assigned' && $assigned_to) {
                $stmt = $pdo->prepare("INSERT INTO asset_assignments (asset_id, assigned_to, project, unit, shamsi_assigned_date, shamsi_return_date, status, assigned_by) VALUES (?, ?, ?, ?, ?, ?, 'assigned', ?)");
                $stmt->execute([$asset_id, $assigned_to, $project, $unit, $shamsi_assigned_date, $shamsi_return_date, $user['id']]);
            }
            
            $_SESSION['success'] = 'اموال با موفقیت اضافه شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'edit' && !empty($title) && $asset_id > 0) {
            $stmt = $pdo->prepare("UPDATE assets SET title = ?, code = ?, description = ?, category_id = ?, status = ?, purchase_price = ?, purchase_date = ?, shamsi_date = ? WHERE id = ?");
            $stmt->execute([$title, $code, $description, $category_id, $status, $purchase_price, $purchase_date, $shamsi_date, $asset_id]);
            
            // اگر وضعیت واگذار شده تغییر کرده
            if ($status === 'assigned' && $assigned_to) {
                $check = $pdo->prepare("SELECT id FROM asset_assignments WHERE asset_id = ? AND status = 'assigned'");
                $check->execute([$asset_id]);
                if ($check->fetch()) {
                    $stmt = $pdo->prepare("UPDATE asset_assignments SET assigned_to = ?, project = ?, unit = ?, shamsi_assigned_date = ?, shamsi_return_date = ? WHERE asset_id = ? AND status = 'assigned'");
                    $stmt->execute([$assigned_to, $project, $unit, $shamsi_assigned_date, $shamsi_return_date, $asset_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO asset_assignments (asset_id, assigned_to, project, unit, shamsi_assigned_date, shamsi_return_date, status, assigned_by) VALUES (?, ?, ?, ?, ?, ?, 'assigned', ?)");
                    $stmt->execute([$asset_id, $assigned_to, $project, $unit, $shamsi_assigned_date, $shamsi_return_date, $user['id']]);
                }
            } elseif ($status !== 'assigned') {
                $stmt = $pdo->prepare("UPDATE asset_assignments SET status = 'returned' WHERE asset_id = ? AND status = 'assigned'");
                $stmt->execute([$asset_id]);
            }
            
            $_SESSION['success'] = 'اموال با موفقیت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'change_status' && $asset_id > 0) {
            $new_status = $_POST['new_status'] ?? 'available';
            $stmt = $pdo->prepare("UPDATE assets SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $asset_id]);
            $_SESSION['success'] = 'وضعیت اموال با موفقیت تغییر کرد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'delete' && $asset_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM assets WHERE id = ?");
            $stmt->execute([$asset_id]);
            $_SESSION['success'] = 'اموال با موفقیت حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در انجام عملیات: ' . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// ===== پردازش دسته‌بندی =====
if (isset($_POST['category_action'])) {
    $action = $_POST['category_action'];
    $cat_name = trim($_POST['cat_name'] ?? '');
    $cat_id = $_POST['cat_id'] ?? 0;
    $cat_color = $_POST['cat_color'] ?? '#0969da';
    $cat_description = trim($_POST['cat_description'] ?? '');
    
    try {
        if ($action === 'add' && !empty($cat_name)) {
            $stmt = $pdo->prepare("INSERT INTO asset_categories (name, color, description) VALUES (?, ?, ?)");
            $stmt->execute([$cat_name, $cat_color, $cat_description]);
            $_SESSION['success'] = 'دسته‌بندی با موفقیت اضافه شد.';
        } elseif ($action === 'edit' && !empty($cat_name) && $cat_id > 0) {
            $stmt = $pdo->prepare("UPDATE asset_categories SET name = ?, color = ?, description = ? WHERE id = ?");
            $stmt->execute([$cat_name, $cat_color, $cat_description, $cat_id]);
            $_SESSION['success'] = 'دسته‌بندی با موفقیت ویرایش شد.';
        } elseif ($action === 'delete' && $cat_id > 0) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE category_id = ?");
            $check->execute([$cat_id]);
            if ($check->fetchColumn() > 0) {
                $_SESSION['error'] = 'این دسته‌بندی دارای اموال است و نمی‌توان آن را حذف کرد.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM asset_categories WHERE id = ?");
                $stmt->execute([$cat_id]);
                $_SESSION['success'] = 'دسته‌بندی با موفقیت حذف شد.';
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در انجام عملیات: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ===== پردازش پروژه‌ها =====
if (isset($_POST['project_action'])) {
    $action = $_POST['project_action'];
    $project_id = $_POST['project_id'] ?? 0;
    $project_name = trim($_POST['project_name'] ?? '');
    $project_description = trim($_POST['project_description'] ?? '');
    
    try {
        if ($action === 'add' && !empty($project_name)) {
            $stmt = $pdo->prepare("INSERT INTO projects (name, description) VALUES (?, ?)");
            $stmt->execute([$project_name, $project_description]);
            $_SESSION['success'] = 'پروژه با موفقیت اضافه شد.';
        } elseif ($action === 'edit' && !empty($project_name) && $project_id > 0) {
            $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$project_name, $project_description, $project_id]);
            $_SESSION['success'] = 'پروژه با موفقیت ویرایش شد.';
        } elseif ($action === 'delete' && $project_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$project_id]);
            $_SESSION['success'] = 'پروژه با موفقیت حذف شد.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در انجام عملیات: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ===== پردازش واحدها =====
if (isset($_POST['unit_action'])) {
    $action = $_POST['unit_action'];
    $unit_id = $_POST['unit_id'] ?? 0;
    $unit_name = trim($_POST['unit_name'] ?? '');
    $unit_description = trim($_POST['unit_description'] ?? '');
    
    try {
        if ($action === 'add' && !empty($unit_name)) {
            $stmt = $pdo->prepare("INSERT INTO units (name, description) VALUES (?, ?)");
            $stmt->execute([$unit_name, $unit_description]);
            $_SESSION['success'] = 'واحد با موفقیت اضافه شد.';
        } elseif ($action === 'edit' && !empty($unit_name) && $unit_id > 0) {
            $stmt = $pdo->prepare("UPDATE units SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$unit_name, $unit_description, $unit_id]);
            $_SESSION['success'] = 'واحد با موفقیت ویرایش شد.';
        } elseif ($action === 'delete' && $unit_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM units WHERE id = ?");
            $stmt->execute([$unit_id]);
            $_SESSION['success'] = 'واحد با موفقیت حذف شد.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در انجام عملیات: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بانک اموال</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/jalalidatepicker.min.css">
    <script src="assets/js/jalalidatepicker.min.js"></script>
    <script src="assets/js/persian-date.js"></script>
    <script src="assets/js/global.js"></script>
    
    <style>
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
        .stat-card { padding: 16px 16px 14px; border-radius: 10px; transition: all 0.3s ease; cursor: default; border: 1px solid transparent; }
        .stat-card .stat-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .stat-card .stat-icon { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; background: #ffffff; border: 1px solid; }
        .stat-card .stat-number { font-size: 20px; font-weight: 800; color: #1a1a2e; letter-spacing: -0.3px; line-height: 1.2; }
        .stat-card .stat-label { font-size: 13px; font-weight: 700; color: #1a1a2e; margin-top: 2px; }

        .stat-card.blue-light { background: #eff6ff; border-color: #b8d4f5; }
        .stat-card.blue-light .stat-icon { border-color: #b8d4f5; color: #0969da; }
        .stat-card.blue-light:hover { box-shadow: 0 8px 30px rgba(9,105,218,0.20); transform: translateY(-3px); border-color: #0969da; }

        .stat-card.green-light { background: #ecfdf3; border-color: #a8e6c1; }
        .stat-card.green-light .stat-icon { border-color: #a8e6c1; color: #2da44e; }
        .stat-card.green-light:hover { box-shadow: 0 8px 30px rgba(45,164,78,0.20); transform: translateY(-3px); border-color: #2da44e; }

        .stat-card.orange-light { background: #fff3e0; border-color: #ffcc80; }
        .stat-card.orange-light .stat-icon { border-color: #ffcc80; color: #ff9800; }
        .stat-card.orange-light:hover { box-shadow: 0 8px 30px rgba(255,152,0,0.20); transform: translateY(-3px); border-color: #ff9800; }

        .stat-card.red-light { background: #fef2f2; border-color: #f5c8c8; }
        .stat-card.red-light .stat-icon { border-color: #f5c8c8; color: #cf222e; }
        .stat-card.red-light:hover { box-shadow: 0 8px 30px rgba(207,34,46,0.20); transform: translateY(-3px); border-color: #cf222e; }

        .stat-card.purple-light { background: #f5f0ff; border-color: #d4c4f0; }
        .stat-card.purple-light .stat-icon { border-color: #d4c4f0; color: #8250df; }
        .stat-card.purple-light:hover { box-shadow: 0 8px 30px rgba(130,80,223,0.20); transform: translateY(-3px); border-color: #8250df; }

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
        .filters-bar .btn-add {
            padding: 8px 20px; background: #cf222e; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif; display: inline-flex; align-items: center; gap: 6px;
            margin-right: auto;
        }
        .filters-bar .btn-add:hover { background: #a0111f; }
        .filters-bar .btn-category {
            padding: 8px 20px; background: #8250df; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif; display: inline-flex; align-items: center; gap: 6px;
        }
        .filters-bar .btn-category:hover { background: #6a3fc7; }

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
        .table-modern tbody tr:nth-child(odd) { background: #fafbfc; }
        .table-modern tbody tr:nth-child(even) { background: #ffffff; }
        .table-modern tbody tr:hover { background: #f0f2f4 !important; }
        
        .table-modern td:first-child {
            border-right: 4px solid #e2e8f0;
        }
        .table-modern .status-badge { padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; white-space: nowrap; }
        .table-modern .category-badge { padding: 2px 12px; border-radius: 12px; font-size: 11px; font-weight: 500; display: inline-block; white-space: nowrap; }
        
        .table-modern .actions { display: flex; gap: 4px; flex-wrap: wrap; }
        .table-modern .actions .btn-icon {
            width: 32px; height: 32px; border-radius: 6px; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.2s; font-size: 13px;
            background: #f0f2f4; color: #57606a;
        }
        .table-modern .actions .btn-icon:hover { background: #e1e4e8; transform: scale(1.05); }
        .table-modern .actions .btn-icon.view { color: #4f7cff; }
        .table-modern .actions .btn-icon.view:hover { background: #e8edff; }
        .table-modern .actions .btn-icon.edit { color: #0969da; }
        .table-modern .actions .btn-icon.edit:hover { background: #ddf4ff; }
        .table-modern .actions .btn-icon.quick-status { color: #ff9800; }
        .table-modern .actions .btn-icon.quick-status:hover { background: #fff3e0; }
        .table-modern .actions .btn-icon.delete { color: #991b1b; }
        .table-modern .actions .btn-icon.delete:hover { background: #fecaca; }

        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            z-index: 2000; justify-content: center; align-items: center;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff; border-radius: 16px; padding: 30px 35px;
            max-width: 750px; width: 95%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: modalIn 0.3s ease;
        }
        @keyframes modalIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        .modal-box h2 { font-size: 20px; font-weight: 700; color: #1a1a2e; margin-bottom: 20px; }
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
        
        .modal-box .sub-section {
            border-top: 2px dashed #e1e4e8;
            padding-top: 16px;
            margin-top: 8px;
        }
        .modal-box .sub-section-title {
            font-size: 15px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 12px;
        }
        .modal-box .sub-section-title i {
            margin-left: 8px;
            color: #0969da;
        }

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
            .filters-bar .btn-add, .filters-bar .btn-category { margin-right: 0; }
            .filters-bar input[name="search"] { min-width: 100%; }
            .modal-box { padding: 20px; }
            .modal-box .form-row { flex-direction: column; }
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
        <li><a href="travel_expenses.php"><i class="fas fa-route"></i> مأموریت</a></li>
        <li><a href="assets.php" class="active"><i class="fas fa-boxes"></i> بانک اموال</a></li>
        <li><a href="knowledge.php"><i class="fas fa-database"></i> بانک دانش</a></li>
        <li><a href="links.php"><i class="fas fa-link"></i> بانک لینک</a></li>
        <li><a href="downloads.php"><i class="fas fa-download"></i> مرکز دانلود</a></li>
        <li><a href="users.php"><i class="fas fa-users"></i> مدیریت کاربران</a></li>
	<li><a href="daily_food_orders.php"><i class="fas fa-users"></i> سفارش غذا</a></li>

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

    <div class="top-bar-wrapper">
        <div class="top-bar">
            <h1>
                <i class="fas fa-boxes" style="color: #cf222e; margin-left: 10px;"></i>
                بانک اموال
                <span>مدیریت اموال و واگذاری</span>
            </h1>
               <div class="top-right">
                <div class="date"><i class="fas fa-calendar"></i> <?= persian_number_str(nowShamsi1('Y/m/d H:i')) ?></div>
                <div class="user-profile">
                    <div class="user-avatar"><?= mb_substr($user['full_name'] ?? $user['username'] ?? 'کاربر', 0, 1, 'UTF-8') ?></div>
                    <div>
                        <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'کاربر') ?></div>
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

    <!-- ===== کارت‌های آمار ===== -->
    <div class="stats-grid">
        <div class="stat-card blue-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-boxes"></i></span><span class="stat-number"><?= $stats['total'] ?></span></div>
            <div class="stat-label">کل اموال</div>
        </div>
        <div class="stat-card green-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-check-circle"></i></span><span class="stat-number"><?= $stats['available'] ?></span></div>
            <div class="stat-label">موجود</div>
        </div>
        <div class="stat-card orange-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-user-check"></i></span><span class="stat-number"><?= $stats['assigned'] ?></span></div>
            <div class="stat-label">واگذار شده</div>
        </div>
        <div class="stat-card red-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-times-circle"></i></span><span class="stat-number"><?= $stats['damaged'] ?></span></div>
            <div class="stat-label">خراب</div>
        </div>
        <div class="stat-card purple-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-tags"></i></span><span class="stat-number"><?= count($categories) ?></span></div>
            <div class="stat-label">دسته‌بندی‌ها</div>
        </div>
    </div>

    <!-- ===== فیلترها ===== -->
    <form class="filters-bar" method="GET" action="">
        <div class="filter-group">
            <input type="text" name="search" placeholder="جستجوی عنوان یا کد..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        </div>
        <div class="filter-group">
            <select name="category">
                <option value="">همه دسته‌ها</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= (isset($_GET['category']) && $_GET['category'] == $cat['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <select name="status">
                <option value="">همه وضعیت‌ها</option>
                <option value="available" <?= (isset($_GET['status']) && $_GET['status'] == 'available') ? 'selected' : '' ?>>موجود</option>
                <option value="assigned" <?= (isset($_GET['status']) && $_GET['status'] == 'assigned') ? 'selected' : '' ?>>واگذار شده</option>
                <option value="damaged" <?= (isset($_GET['status']) && $_GET['status'] == 'damaged') ? 'selected' : '' ?>>خراب</option>
                <option value="depreciated" <?= (isset($_GET['status']) && $_GET['status'] == 'depreciated') ? 'selected' : '' ?>>مستهلک</option>
            </select>
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> فیلتر</button>
        <a href="assets.php" class="btn-reset"><i class="fas fa-undo"></i> پاک کردن</a>
        <button type="button" class="btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> اموال جدید</button>
        <button type="button" class="btn-category" onclick="openCategoryModal()"><i class="fas fa-folder-plus"></i> مدیریت دسته‌بندی</button>
    </form>

    <!-- ===== جدول ===== -->
    <div class="table-wrapper">
        <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>عنوان</th>
                        <th style="width:100px;">کد</th>
                        <th style="width:130px;">دسته‌بندی</th>
                        <th style="width:150px;">وضعیت</th>
                        <th style="width:150px;">واگذار به</th>
                        <th style="width:110px;">تاریخ ثبت</th>
                        <th style="width:180px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assets)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:40px;color:#8b93a5;">هیچ اموالی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($assets as $item): 
                            $status = getAssetStatus($item['status']);
                        ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                                    <?php if (!empty($item['description'])): ?>
                                        <div style="font-size:12px;color:#8b93a5;margin-top:2px;"><?= htmlspecialchars(mb_substr($item['description'], 0, 60)) ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:13px;color:#57606a;">
                                    <?= htmlspecialchars($item['code'] ?? '-') ?>
                                </td>
                                <td>
                                    <?php if ($item['category_name']): ?>
                                        <span class="category-badge" style="background:<?= htmlspecialchars($item['category_color'] ?? '#e2e8f0') ?>20;color:<?= htmlspecialchars($item['category_color'] ?? '#475569') ?>;border:1px solid <?= htmlspecialchars($item['category_color'] ?? '#e2e8f0') ?>40;">
                                            <i class="fas fa-folder" style="margin-left:4px;font-size:10px;"></i>
                                            <?= htmlspecialchars($item['category_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#8b93a5;font-size:12px;">دسته‌بندی نشده</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge" style="background:<?= $status['color'] ?>;color:<?= $status['text'] ?>;">
                                        <?= $status['label'] ?>
                                    </span>
                                </td>
                                <td style="font-size:13px;">
                                    <?php if ($item['status'] == 'assigned' && !empty($item['assigned_to_name'])): ?>
                                        <div><?= htmlspecialchars($item['assigned_to_name']) ?></div>
                                        <?php if (!empty($item['project']) || !empty($item['unit'])): ?>
                                            <div style="font-size:11px;color:#8b93a5;">
                                                <?= htmlspecialchars($item['project'] ?? '') ?>
                                                <?php if (!empty($item['project']) && !empty($item['unit'])): ?>
                                                    -
                                                <?php endif; ?>
                                                <?= htmlspecialchars($item['unit'] ?? '') ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($item['status'] == 'assigned'): ?>
                                        <span style="color:#8b93a5;font-size:12px;">واگذار شده</span>
                                    <?php else: ?>
                                        <span style="color:#8b93a5;font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:13px;color:#57606a;">
                                    <?= !empty($item['shamsi_date']) ? $item['shamsi_date'] : nowShamsi() ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button class="btn-icon view" onclick="viewAsset(<?= $item['id'] ?>)" title="مشاهده">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon edit" onclick="editAsset(<?= $item['id'] ?>)" title="ویرایش">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon quick-status" onclick="quickChangeStatus(<?= $item['id'] ?>)" title="تغییر وضعیت">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                        <button class="btn-icon delete" onclick="deleteAsset(<?= $item['id'] ?>)" title="حذف">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
        <span class="footer-version">نسخه 1.0</span>
    </div>
</div>

<!-- ===== مودال افزودن/ویرایش اموال ===== -->
<div class="modal-overlay" id="assetModal">
    <div class="modal-box">
        <h2 id="assetModalTitle">اموال جدید</h2>
        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="asset_action" id="asset_action" value="add">
            <input type="hidden" name="asset_id" id="asset_id" value="0">
            
            <!-- ===== تاریخ ثبت ===== -->
            <div class="form-group">
                <label>تاریخ ثبت <span style="color:#8b949e;font-weight:400;">(شمسی)</span></label>
                <input type="text" name="created_date" id="created_date" class="persian-date" placeholder="تاریخ ثبت" value="<?= nowShamsi() ?>" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label>عنوان اموال</label>
                <input type="text" name="title" id="asset_title" required autocomplete="off">
            </div>
            
            <div class="form-group">
                <label>کد شناسایی (اختیاری)</label>
                <input type="text" name="code" id="asset_code" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label>توضیحات</label>
                <textarea name="description" id="asset_description" rows="2"></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>دسته‌بندی</label>
                    <select name="category_id" id="asset_category">
                        <option value="">بدون دسته‌بندی</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>وضعیت</label>
                    <select name="status" id="asset_status" onchange="toggleAssignmentFields()">
                        <option value="available">موجود</option>
                        <option value="assigned">واگذار شده</option>
                        <option value="damaged">خراب</option>
                        <option value="depreciated">مستهلک</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>قیمت خرید (اختیاری)</label>
                    <input type="number" name="purchase_price" id="asset_price" step="0.01" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>تاریخ خرید (اختیاری)</label>
                    <input type="text" name="purchase_date" id="asset_purchase_date" class="persian-date" placeholder="تاریخ خرید" autocomplete="off">
                </div>
            </div>
            
            <!-- ===== بخش واگذاری ===== -->
            <div id="assignment_section" class="sub-section" style="display:none;">
                <div class="sub-section-title"><i class="fas fa-user-check"></i> اطلاعات واگذاری</div>
                
                <div class="form-group">
                    <label>واگذار به</label>
                    <select name="assigned_to" id="asset_assigned_to">
                        <option value="">انتخاب کنید...</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name'] ?? $u['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>پروژه</label>
                        <select name="project" id="asset_project">
                            <option value="">انتخاب پروژه...</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-primary" onclick="openProjectModal()" style="margin-top:4px;padding:4px 12px;font-size:12px;">
                            <i class="fas fa-plus"></i> پروژه جدید
                        </button>
                    </div>
                    <div class="form-group">
                        <label>واحد</label>
                        <select name="unit" id="asset_unit">
                            <option value="">انتخاب واحد...</option>
                            <?php foreach ($units as $u): ?>
                                <option value="<?= htmlspecialchars($u['name']) ?>"><?= htmlspecialchars($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-primary" onclick="openUnitModal()" style="margin-top:4px;padding:4px 12px;font-size:12px;">
                            <i class="fas fa-plus"></i> واحد جدید
                        </button>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>تاریخ واگذاری (شمسی)</label>
                        <input type="text" name="shamsi_assigned_date" id="asset_assigned_date" class="persian-date" placeholder="تاریخ واگذاری" value="<?= nowShamsi() ?>" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>تاریخ بازگشت (شمسی)</label>
                        <input type="text" name="shamsi_return_date" id="asset_return_date" class="persian-date" placeholder="تاریخ بازگشت" autocomplete="off">
                    </div>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('assetModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره اموال</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال مدیریت دسته‌بندی ===== -->
<div class="modal-overlay" id="categoryModal">
    <div class="modal-box">
        <h2>مدیریت دسته‌بندی‌ها</h2>
        <div style="max-height:300px;overflow-y:auto;margin-bottom:16px;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr style="background:#f8f9fa;border-bottom:1px solid #e1e4e8;">
                        <th style="padding:8px 10px;text-align:right;">نام</th>
                        <th style="padding:8px 10px;text-align:center;width:80px;">رنگ</th>
                        <th style="padding:8px 10px;text-align:center;width:100px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr style="border-bottom:1px solid #f0f2f4;">
                        <td style="padding:8px 10px;"><?= htmlspecialchars($cat['name']) ?></td>
                        <td style="padding:8px 10px;text-align:center;">
                            <span style="display:inline-block;width:24px;height:24px;border-radius:50%;background:<?= htmlspecialchars($cat['color'] ?? '#0969da') ?>;border:1px solid #ddd;"></span>
                        </td>
                        <td style="padding:8px 10px;text-align:center;">
                            <button class="btn-icon edit" onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name']) ?>', '<?= htmlspecialchars($cat['color'] ?? '#0969da') ?>', '<?= htmlspecialchars($cat['description'] ?? '') ?>')"><i class="fas fa-pen"></i></button>
                            <button class="btn-icon delete" onclick="deleteCategory(<?= $cat['id'] ?>)"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="category_action" id="category_action" value="add">
            <input type="hidden" name="cat_id" id="cat_id" value="0">
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label>نام دسته‌بندی</label>
                    <input type="text" name="cat_name" id="cat_name" required>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>رنگ</label>
                    <input type="color" name="cat_color" id="cat_color" value="#0969da" style="padding:2px;height:42px;cursor:pointer;">
                </div>
            </div>
            <div class="form-group">
                <label>توضیحات دسته‌بندی</label>
                <input type="text" name="cat_description" id="cat_description" autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('categoryModal')">بستن</button>
                <button type="submit" class="btn btn-success" id="categorySubmitBtn">افزودن دسته‌بندی</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال مدیریت پروژه‌ها ===== -->
<div class="modal-overlay" id="projectModal">
    <div class="modal-box">
        <h2>مدیریت پروژه‌ها</h2>
        <div style="max-height:300px;overflow-y:auto;margin-bottom:16px;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr style="background:#f8f9fa;border-bottom:1px solid #e1e4e8;">
                        <th style="padding:8px 10px;text-align:right;">نام پروژه</th>
                        <th style="padding:8px 10px;text-align:center;width:100px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $p): ?>
                    <tr style="border-bottom:1px solid #f0f2f4;">
                        <td style="padding:8px 10px;"><?= htmlspecialchars($p['name']) ?></td>
                        <td style="padding:8px 10px;text-align:center;">
                            <button class="btn-icon edit" onclick="editProject(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name']) ?>', '<?= htmlspecialchars($p['description'] ?? '') ?>')"><i class="fas fa-pen"></i></button>
                            <button class="btn-icon delete" onclick="deleteProject(<?= $p['id'] ?>)"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="project_action" id="project_action" value="add">
            <input type="hidden" name="project_id" id="project_id" value="0">
            <div class="form-group">
                <label>نام پروژه</label>
                <input type="text" name="project_name" id="project_name" required>
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <input type="text" name="project_description" id="project_description">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('projectModal')">بستن</button>
                <button type="submit" class="btn btn-success" id="projectSubmitBtn">افزودن پروژه</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال مدیریت واحدها ===== -->
<div class="modal-overlay" id="unitModal">
    <div class="modal-box">
        <h2>مدیریت واحدها</h2>
        <div style="max-height:300px;overflow-y:auto;margin-bottom:16px;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr style="background:#f8f9fa;border-bottom:1px solid #e1e4e8;">
                        <th style="padding:8px 10px;text-align:right;">نام واحد</th>
                        <th style="padding:8px 10px;text-align:center;width:100px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($units as $u): ?>
                    <tr style="border-bottom:1px solid #f0f2f4;">
                        <td style="padding:8px 10px;"><?= htmlspecialchars($u['name']) ?></td>
                        <td style="padding:8px 10px;text-align:center;">
                            <button class="btn-icon edit" onclick="editUnit(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name']) ?>', '<?= htmlspecialchars($u['description'] ?? '') ?>')"><i class="fas fa-pen"></i></button>
                            <button class="btn-icon delete" onclick="deleteUnit(<?= $u['id'] ?>)"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="unit_action" id="unit_action" value="add">
            <input type="hidden" name="unit_id" id="unit_id" value="0">
            <div class="form-group">
                <label>نام واحد</label>
                <input type="text" name="unit_name" id="unit_name" required>
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <input type="text" name="unit_description" id="unit_description">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('unitModal')">بستن</button>
                <button type="submit" class="btn btn-success" id="unitSubmitBtn">افزودن واحد</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال تغییر وضعیت ===== -->
<div class="modal-overlay" id="quickStatusModal">
    <div class="modal-box">
        <h2>تغییر وضعیت اموال</h2>
        <form method="POST" action="">
            <input type="hidden" name="asset_action" value="change_status">
            <input type="hidden" name="asset_id" id="quick_status_asset_id" value="0">
            <div class="form-group">
                <label>وضعیت جدید</label>
                <select name="new_status" id="quick_status_select">
                    <option value="available">موجود</option>
                    <option value="assigned">واگذار شده</option>
                    <option value="damaged">خراب</option>
                    <option value="depreciated">مستهلک</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('quickStatusModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">تغییر وضعیت</button>
            </div>
        </form>
    </div>
</div>

<button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>

<script>
    // ===== مقداردهی مجدد تاریخ‌ها در مودال =====
    function initModalDatepickers() {
        var modal = document.getElementById('assetModal');
        if (!modal) return;
        
        modal.querySelectorAll('.persian-date').forEach(function(input) {
            if (typeof jalaliDatepicker !== 'undefined') {
                try {
                    jalaliDatepicker.startWatch({
                        container: input,
                        minDate: 'attr',
                        maxDate: 'attr'
                    });
                } catch(e) {
                    console.warn('خطا در مقداردهی تاریخ:', e);
                }
            }
        });
    }

    // ===== راه‌اندازی انتخابگر تاریخ شمسی =====
document.addEventListener('DOMContentLoaded', function() {
    if (typeof jalaliDatepicker !== 'undefined') {
        jalaliDatepicker.startWatch({
            minDate: 'attr',
            maxDate: 'attr',
            persianDigit: true,
            autoHide: true,
            zIndex: 3000
        });
    }
});
       // ===== منوی موبایل =====
    document.getElementById('mobileToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    });
    document.getElementById('sidebarOverlay').addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    });

    // ===== راه‌اندازی تاریخ شمسی (سراسری) =====
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof jalaliDatepicker !== 'undefined') {
            jalaliDatepicker.startWatch({
                minDate: 'attr',
                maxDate: 'attr',
                persianDigit: true,
                autoHide: true,
                zIndex: 3000
            });
        }
    });

    // ===== نمایش/مخفی کردن بخش واگذاری =====
    function toggleAssignmentFields() {
        var status = document.getElementById('asset_status').value;
        var section = document.getElementById('assignment_section');
        if (status === 'assigned') {
            section.style.display = 'block';
        } else {
            section.style.display = 'none';
        }
    }

    // ===== باز کردن مودال افزودن اموال =====
    function openAddModal() {
        document.getElementById('assetModalTitle').textContent = 'اموال جدید';
        document.getElementById('asset_action').value = 'add';
        document.getElementById('asset_id').value = 0;
        document.getElementById('asset_title').value = '';
        document.getElementById('asset_code').value = '';
        document.getElementById('asset_description').value = '';
        document.getElementById('asset_category').value = '';
        document.getElementById('asset_status').value = 'available';
        document.getElementById('asset_price').value = '';
        document.getElementById('asset_purchase_date').value = '';
        document.getElementById('asset_assigned_to').value = '';
        document.getElementById('asset_project').value = '';
        document.getElementById('asset_unit').value = '';
        document.getElementById('asset_assigned_date').value = '<?= nowShamsi() ?>';
        document.getElementById('asset_return_date').value = '';
        document.getElementById('created_date').value = '<?= nowShamsi() ?>';
        document.getElementById('assignment_section').style.display = 'none';
        document.getElementById('assetModal').classList.add('active');
    }

    // ===== ویرایش اموال =====
    function editAsset(id) {
        fetch('get_asset.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('assetModalTitle').textContent = 'ویرایش اموال';
                    document.getElementById('asset_action').value = 'edit';
                    document.getElementById('asset_id').value = id;
                    document.getElementById('asset_title').value = data.title || '';
                    document.getElementById('asset_code').value = data.code || '';
                    document.getElementById('asset_description').value = data.description || '';
                    document.getElementById('asset_category').value = data.category_id || '';
                    document.getElementById('asset_status').value = data.status || 'available';
                    document.getElementById('asset_price').value = data.purchase_price || '';
                    document.getElementById('asset_purchase_date').value = data.purchase_date || '';
                    document.getElementById('created_date').value = data.shamsi_date || '<?= nowShamsi() ?>';
                    
                    if (data.status === 'assigned' && data.assignment) {
                        document.getElementById('assignment_section').style.display = 'block';
                        document.getElementById('asset_assigned_to').value = data.assignment.assigned_to || '';
                        document.getElementById('asset_project').value = data.assignment.project || '';
                        document.getElementById('asset_unit').value = data.assignment.unit || '';
                        document.getElementById('asset_assigned_date').value = data.assignment.shamsi_assigned_date || '<?= nowShamsi() ?>';
                        document.getElementById('asset_return_date').value = data.assignment.shamsi_return_date || '';
                    } else {
                        document.getElementById('assignment_section').style.display = 'none';
                    }
                    
                    document.getElementById('assetModal').classList.add('active');
                } else {
                    alert('خطا در دریافت اطلاعات: ' + (data.error || 'نامشخص'));
                }
            })
            .catch(function() {
                alert('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
            });
    }

    // ===== مشاهده اموال =====
    function viewAsset(id) {
        window.open('view_asset.php?id=' + id, '_blank');
    }

    // ===== حذف اموال =====
    function deleteAsset(id) {
        if (confirm('آیا از حذف این اموال مطمئن هستید؟ این عملیات غیرقابل بازگشت است!')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            var input1 = document.createElement('input');
            input1.type = 'hidden';
            input1.name = 'asset_action';
            input1.value = 'delete';
            var input2 = document.createElement('input');
            input2.type = 'hidden';
            input2.name = 'asset_id';
            input2.value = id;
            form.appendChild(input1);
            form.appendChild(input2);
            document.body.appendChild(form);
            form.submit();
        }
    }

    // ===== تغییر وضعیت سریع =====
    function quickChangeStatus(id) {
        document.getElementById('quick_status_asset_id').value = id;
        document.getElementById('quick_status_select').value = 'available';
        document.getElementById('quickStatusModal').classList.add('active');
    }

    // ===== مدیریت دسته‌بندی =====
    function openCategoryModal() {
        document.getElementById('category_action').value = 'add';
        document.getElementById('cat_id').value = 0;
        document.getElementById('cat_name').value = '';
        document.getElementById('cat_color').value = '#0969da';
        document.getElementById('cat_description').value = '';
        document.getElementById('categorySubmitBtn').textContent = 'افزودن دسته‌بندی';
        document.getElementById('categoryModal').classList.add('active');
    }

    function editCategory(id, name, color, description) {
        document.getElementById('category_action').value = 'edit';
        document.getElementById('cat_id').value = id;
        document.getElementById('cat_name').value = name;
        document.getElementById('cat_color').value = color;
        document.getElementById('cat_description').value = description || '';
        document.getElementById('categorySubmitBtn').textContent = 'ویرایش دسته‌بندی';
        document.getElementById('categoryModal').classList.add('active');
    }

    function deleteCategory(id) {
        if (confirm('آیا از حذف این دسته‌بندی مطمئن هستید؟')) {
            document.getElementById('category_action').value = 'delete';
            document.getElementById('cat_id').value = id;
            document.getElementById('categoryModal').querySelector('form').submit();
        }
    }

    // ===== مدیریت پروژه‌ها =====
    function openProjectModal() {
        document.getElementById('project_action').value = 'add';
        document.getElementById('project_id').value = 0;
        document.getElementById('project_name').value = '';
        document.getElementById('project_description').value = '';
        document.getElementById('projectSubmitBtn').textContent = 'افزودن پروژه';
        document.getElementById('projectModal').classList.add('active');
    }

    function editProject(id, name, description) {
        document.getElementById('project_action').value = 'edit';
        document.getElementById('project_id').value = id;
        document.getElementById('project_name').value = name;
        document.getElementById('project_description').value = description || '';
        document.getElementById('projectSubmitBtn').textContent = 'ویرایش پروژه';
        document.getElementById('projectModal').classList.add('active');
    }

    function deleteProject(id) {
        if (confirm('آیا از حذف این پروژه مطمئن هستید؟')) {
            document.getElementById('project_action').value = 'delete';
            document.getElementById('project_id').value = id;
            document.getElementById('projectModal').querySelector('form').submit();
        }
    }

    // ===== مدیریت واحدها =====
    function openUnitModal() {
        document.getElementById('unit_action').value = 'add';
        document.getElementById('unit_id').value = 0;
        document.getElementById('unit_name').value = '';
        document.getElementById('unit_description').value = '';
        document.getElementById('unitSubmitBtn').textContent = 'افزودن واحد';
        document.getElementById('unitModal').classList.add('active');
    }

    function editUnit(id, name, description) {
        document.getElementById('unit_action').value = 'edit';
        document.getElementById('unit_id').value = id;
        document.getElementById('unit_name').value = name;
        document.getElementById('unit_description').value = description || '';
        document.getElementById('unitSubmitBtn').textContent = 'ویرایش واحد';
        document.getElementById('unitModal').classList.add('active');
    }

    function deleteUnit(id) {
        if (confirm('آیا از حذف این واحد مطمئن هستید؟')) {
            document.getElementById('unit_action').value = 'delete';
            document.getElementById('unit_id').value = id;
            document.getElementById('unitModal').querySelector('form').submit();
        }
    }

    // ===== بستن مودال =====
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
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
</script>

</body>
</html>