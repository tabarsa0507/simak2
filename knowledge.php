<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'بانک دانش';

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
$categories = $pdo->query("SELECT * FROM knowledge_categories ORDER BY sort_order, name")->fetchAll();

// ===== پردازش فیلترها =====
$where = [];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where[] = "(k.title LIKE :search OR k.content LIKE :search)";
    $params[':search'] = '%' . $_GET['search'] . '%';
}
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $where[] = "k.category_id = :category";
    $params[':category'] = $_GET['category'];
}
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $where[] = "k.status = :status";
    $params[':status'] = $_GET['status'];
}
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where[] = "k.shamsi_date >= :date_from";
    $params[':date_from'] = $_GET['date_from'];
}
if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where[] = "k.shamsi_date <= :date_to";
    $params[':date_to'] = $_GET['date_to'];
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ===== دریافت کل مقالات =====
$query = "
    SELECT k.*, 
           c.name as category_name, 
           c.color as category_color,
           u.full_name as author_name,
           u2.full_name as approver_name
    FROM knowledge_base k
    LEFT JOIN knowledge_categories c ON k.category_id = c.id
    LEFT JOIN users u ON k.created_by = u.id
    LEFT JOIN users u2 ON k.approved_by = u2.id
    $whereClause
    ORDER BY k.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$articles = $stmt->fetchAll();

// ===== آمار =====
$stats = [
    'total' => count($articles),
    'pending' => count(array_filter($articles, fn($a) => $a['status'] === 'pending')),
    'approved' => count(array_filter($articles, fn($a) => $a['status'] === 'approved')),
    'rejected' => count(array_filter($articles, fn($a) => $a['status'] === 'rejected')),
    'draft' => count(array_filter($articles, fn($a) => $a['status'] === 'draft'))
];

function getStatusStyle($status) {
    $styles = [
        'draft' => ['bg' => '#e2e8f0', 'color' => '#475569', 'label' => 'پیش‌نویس'],
        'pending' => ['bg' => '#fef3c7', 'color' => '#d97706', 'label' => 'در انتظار تایید'],
        'approved' => ['bg' => '#d1fae5', 'color' => '#065f46', 'label' => 'تایید شده'],
        'rejected' => ['bg' => '#fecaca', 'color' => '#991b1b', 'label' => 'رد شده'],
        'needs_revision' => ['bg' => '#fed7aa', 'color' => '#9a3412', 'label' => 'نیاز به اصلاح']
    ];
    return $styles[$status] ?? $styles['draft'];
}

function getProjectColor($color) {
    $colors = [
        '#0969da' => 'primary',
        '#2da44e' => 'success',
        '#d4a72c' => 'warning',
        '#8250df' => 'purple',
        '#cf222e' => 'danger',
        '#ff9800' => 'orange',
        '#e91e63' => 'pink',
        '#00bcd4' => 'cyan',
        '#795548' => 'brown',
        '#607d8b' => 'bluegray'
    ];
    return $colors[$color] ?? 'secondary';
}

// ===== پردازش عملیات مقاله =====
if (isset($_POST['article_action'])) {
    $action = $_POST['article_action'];
    $article_id = $_POST['article_id'] ?? 0;
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $status = $_POST['status'] ?? 'pending';
    
    // ===== دریافت تاریخ شمسی از فرم =====
    $shamsi_date = isset($_POST['created_date']) ? trim($_POST['created_date']) : jdate('Y/m/d');
    
    try {
        if ($action === 'add' && !empty($title) && !empty($content)) {
            $stmt = $pdo->prepare("INSERT INTO knowledge_base (title, content, category_id, status, created_by, created_at, shamsi_date) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->execute([$title, $content, $category_id, $status, $user['id'], $shamsi_date]);
            $_SESSION['success'] = 'مقاله با موفقیت اضافه شد. تاریخ ثبت: ' . $shamsi_date;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'edit' && !empty($title) && !empty($content) && $article_id > 0) {
            $stmt = $pdo->prepare("UPDATE knowledge_base SET title = ?, content = ?, category_id = ?, status = ? WHERE id = ?");
            $stmt->execute([$title, $content, $category_id, $status, $article_id]);
            $_SESSION['success'] = 'مقاله با موفقیت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'change_status' && $article_id > 0) {
            $new_status = $_POST['new_status'] ?? 'pending';
            $stmt = $pdo->prepare("UPDATE knowledge_base SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $article_id]);
            $_SESSION['success'] = 'وضعیت مقاله با موفقیت تغییر کرد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'delete' && $article_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM knowledge_base WHERE id = ?");
            $stmt->execute([$article_id]);
            $_SESSION['success'] = 'مقاله با موفقیت حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در انجام عملیات: ' . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بانک دانش</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- ===== کتابخانه‌های تاریخ شمسی ===== -->
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

        .stats-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin-bottom: 28px; }
        .stat-card { padding: 16px 16px 14px; border-radius: 10px; transition: all 0.3s ease; cursor: default; border: 1px solid transparent; }
        .stat-card .stat-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .stat-card .stat-icon { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; background: #ffffff; border: 1px solid; }
        .stat-card .stat-number { font-size: 20px; font-weight: 800; color: #1a1a2e; letter-spacing: -0.3px; line-height: 1.2; }
        .stat-card .stat-label { font-size: 13px; font-weight: 700; color: #1a1a2e; margin-top: 2px; }

        .stat-card.green-light { background: #ecfdf3; border-color: #a8e6c1; }
        .stat-card.green-light .stat-icon { border-color: #a8e6c1; color: #2da44e; }
        .stat-card.green-light:hover { box-shadow: 0 8px 30px rgba(45,164,78,0.20); transform: translateY(-3px); border-color: #2da44e; }
        .stat-card.purple-light { background: #f5f0ff; border-color: #d4c4f0; }
        .stat-card.purple-light .stat-icon { border-color: #d4c4f0; color: #8250df; }
        .stat-card.purple-light:hover { box-shadow: 0 8px 30px rgba(130,80,223,0.20); transform: translateY(-3px); border-color: #8250df; }
        .stat-card.blue-light { background: #eff6ff; border-color: #b8d4f5; }
        .stat-card.blue-light .stat-icon { border-color: #b8d4f5; color: #0969da; }
        .stat-card.blue-light:hover { box-shadow: 0 8px 30px rgba(9,105,218,0.20); transform: translateY(-3px); border-color: #0969da; }
        .stat-card.red-light { background: #fef2f2; border-color: #f5c8c8; }
        .stat-card.red-light .stat-icon { border-color: #f5c8c8; color: #cf222e; }
        .stat-card.red-light:hover { box-shadow: 0 8px 30px rgba(207,34,46,0.20); transform: translateY(-3px); border-color: #cf222e; }
        .stat-card.yellow-light { background: #fffbeb; border-color: #f5e6b8; }
        .stat-card.yellow-light .stat-icon { border-color: #f5e6b8; color: #d4a72c; }
        .stat-card.yellow-light:hover { box-shadow: 0 8px 30px rgba(212,167,44,0.20); transform: translateY(-3px); border-color: #d4a72c; }
        .stat-card.orange-light { background: #fff3e0; border-color: #ffcc80; }
        .stat-card.orange-light .stat-icon { border-color: #ffcc80; color: #ff9800; }
        .stat-card.orange-light:hover { box-shadow: 0 8px 30px rgba(255,152,0,0.20); transform: translateY(-3px); border-color: #ff9800; }

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
        .filters-bar .date-wrapper .calendar-icon:hover {
            color: #0969da;
        }

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
            padding: 8px 20px; background: #2da44e; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif; display: inline-flex; align-items: center; gap: 6px;
            margin-right: auto;
        }
        .filters-bar .btn-add:hover { background: #22863a; }
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
        .table-modern .project-color-primary { border-right-color: #0969da !important; }
        .table-modern .project-color-success { border-right-color: #2da44e !important; }
        .table-modern .project-color-warning { border-right-color: #d4a72c !important; }
        .table-modern .project-color-purple { border-right-color: #8250df !important; }
        .table-modern .project-color-danger { border-right-color: #cf222e !important; }
        .table-modern .project-color-orange { border-right-color: #ff9800 !important; }
        .table-modern .project-color-pink { border-right-color: #e91e63 !important; }
        .table-modern .project-color-cyan { border-right-color: #00bcd4 !important; }
        .table-modern .project-color-brown { border-right-color: #795548 !important; }
        .table-modern .project-color-bluegray { border-right-color: #607d8b !important; }
        .table-modern .project-color-secondary { border-right-color: #e2e8f0 !important; }

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
            max-width: 550px; width: 95%; max-height: 90vh; overflow-y: auto;
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
        .modal-box .form-group textarea { min-height: 120px; resize: vertical; }
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
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
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
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
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
        <li><a href="assets.php"><i class="fas fa-boxes"></i> بانک اموال</a></li>
        <li><a href="knowledge.php" class="active"><i class="fas fa-database"></i> بانک دانش</a></li>
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

    <div class="top-bar-wrapper">
        <div class="top-bar">
            <h1>
                <i class="fas fa-database" style="color: #2da44e; margin-left: 10px;"></i>
                بانک دانش
                <span>مدیریت مقالات دانشی</span>
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

    <div class="stats-grid">
        <div class="stat-card green-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-database"></i></span><span class="stat-number"><?= $stats['total'] ?></span></div>
            <div class="stat-label">کل مقالات</div>
        </div>
        <div class="stat-card purple-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-check-circle"></i></span><span class="stat-number"><?= $stats['approved'] ?></span></div>
            <div class="stat-label">تایید شده</div>
        </div>
        <div class="stat-card yellow-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-clock"></i></span><span class="stat-number"><?= $stats['pending'] ?></span></div>
            <div class="stat-label">در انتظار تایید</div>
        </div>
        <div class="stat-card orange-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-pen"></i></span><span class="stat-number"><?= $stats['draft'] ?></span></div>
            <div class="stat-label">پیش‌نویس</div>
        </div>
        <div class="stat-card red-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-times-circle"></i></span><span class="stat-number"><?= $stats['rejected'] ?></span></div>
            <div class="stat-label">رد شده</div>
        </div>
        <div class="stat-card blue-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-tags"></i></span><span class="stat-number"><?= count($categories) ?></span></div>
            <div class="stat-label">دسته‌بندی‌ها</div>
        </div>
    </div>

    <!-- ===== فیلترها ===== -->
    <form class="filters-bar" method="GET" action="">
        <div class="filter-group">
            <input type="text" name="search" placeholder="جستجوی عنوان یا محتوا..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
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
                <option value="draft" <?= (isset($_GET['status']) && $_GET['status'] == 'draft') ? 'selected' : '' ?>>پیش‌نویس</option>
                <option value="pending" <?= (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : '' ?>>در انتظار تایید</option>
                <option value="approved" <?= (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : '' ?>>تایید شده</option>
                <option value="rejected" <?= (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'selected' : '' ?>>رد شده</option>
                <option value="needs_revision" <?= (isset($_GET['status']) && $_GET['status'] == 'needs_revision') ? 'selected' : '' ?>>نیاز به اصلاح</option>
            </select>
        </div>
        <div class="filter-group">
            <div class="date-wrapper">
                <input type="text" name="date_from" class="persian-date" placeholder="از تاریخ " value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();">
                    <i class="fas fa-calendar-alt"></i>
                </button>
            </div>
            <span style="color:#8b93a5;">تا</span>
            <div class="date-wrapper">
                <input type="text" name="date_to" class="persian-date" placeholder="تا تاریخ" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();">
                    <i class="fas fa-calendar-alt"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> فیلتر</button>
        <a href="knowledge.php" class="btn-reset"><i class="fas fa-undo"></i> پاک کردن</a>
        <button type="button" class="btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> مقاله جدید</button>
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
                        <th style="width:130px;">دسته‌بندی</th>
                        <th style="width:110px;">وضعیت</th>
                        <th style="width:120px;">نویسنده</th>
                        <th style="width:110px;">تاریخ ثبت</th>
                        <th style="width:190px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($articles)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:#8b93a5;">هیچ مقاله‌ای یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($articles as $article): ?>
                            <?php 
                                $status = getStatusStyle($article['status']);
                                $projectColor = getProjectColor($article['category_color'] ?? '#e2e8f0');
                            ?>
                            <tr class="project-color-<?= $projectColor ?>">
                                <td><?= $i++ ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($article['title']) ?></strong>
                                    <?php if (!empty($article['summary'])): ?>
                                        <div style="font-size:12px;color:#8b93a5;margin-top:2px;"><?= htmlspecialchars(mb_substr($article['summary'], 0, 60)) ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($article['category_name']): ?>
                                        <span class="category-badge" style="background:<?= htmlspecialchars($article['category_color'] ?? '#e2e8f0') ?>20;color:<?= htmlspecialchars($article['category_color'] ?? '#475569') ?>;border:1px solid <?= htmlspecialchars($article['category_color'] ?? '#e2e8f0') ?>40;">
                                            <i class="fas fa-folder" style="margin-left:4px;font-size:10px;"></i>
                                            <?= htmlspecialchars($article['category_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#8b93a5;font-size:12px;">دسته‌بندی نشده</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge" style="background:<?= $status['bg'] ?>;color:<?= $status['color'] ?>;">
                                        <?= $status['label'] ?>
                                    </span>
                                </td>
                                <td style="font-size:13px;"><?= htmlspecialchars($article['author_name'] ?? 'نامشخص') ?></td>
                               <td style="font-size:13px;color:#57606a;">
    <?= !empty($article['shamsi_date']) ? $article['shamsi_date'] : nowShamsi() ?>
</td>
                                <td>
                                    <div class="actions">
                                        <button class="btn-icon view" onclick="viewArticle(<?= $article['id'] ?>)" title="مشاهده">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon edit" onclick="editArticle(<?= $article['id'] ?>)" title="ویرایش">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon quick-status" onclick="quickChangeStatus(<?= $article['id'] ?>)" title="تغییر وضعیت">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                        <button class="btn-icon delete" onclick="deleteArticle(<?= $article['id'] ?>)" title="حذف">
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

<!-- ===== مودال افزودن مقاله با تاریخ شمسی و autocomplete off ===== -->
<div class="modal-overlay" id="articleModal">
    <div class="modal-box">
        <h2 id="articleModalTitle">مقاله جدید</h2>
        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="article_action" id="article_action" value="add">
            <input type="hidden" name="article_id" id="article_id" value="0">
            
            <!-- ===== تاریخ ثبت با مقدار پیش‌فرض شمسی ===== -->
            <div class="form-group">
                <label>تاریخ ثبت <span style="color:#8b949e;font-weight:400;">(شمسی)</span></label>
                <input type="text" name="created_date" id="created_date" class="persian-date" placeholder="تاریخ ثبت" value="<?= jdate('Y/m/d') ?>" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label>عنوان مقاله</label>
                <input type="text" name="title" id="article_title" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>دسته‌بندی</label>
                <select name="category_id" id="article_category">
                    <option value="">بدون دسته‌بندی</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>خلاصه</label>
                <input type="text" name="summary" id="article_summary" autocomplete="off">
            </div>
            <div class="form-group">
                <label>محتوای مقاله</label>
                <textarea name="content" id="article_content" required></textarea>
            </div>
            <div class="form-group">
                <label>وضعیت</label>
                <select name="status" id="article_status">
                    <option value="draft">پیش‌نویس</option>
                    <option value="pending" selected>در انتظار تایید</option>
                    <option value="approved">تایید شده</option>
                    <option value="rejected">رد شده</option>
                    <option value="needs_revision">نیاز به اصلاح</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('articleModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره مقاله</button>
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
                            <span style="display:inline-block;width:24px;height:24px;border-radius:50%;background:<?= htmlspecialchars($cat['color'] ?? '#6c757d') ?>;border:1px solid #ddd;"></span>
                        </td>
                        <td style="padding:8px 10px;text-align:center;">
                            <button class="btn-icon edit" onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name']) ?>', '<?= htmlspecialchars($cat['color'] ?? '#6c757d') ?>')"><i class="fas fa-pen"></i></button>
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
                    <input type="color" name="cat_color" id="cat_color" value="#6c757d" style="padding:2px;height:42px;cursor:pointer;">
                </div>
            </div>
            <div class="form-group">
                <label>دسته‌بندی والد</label>
                <select name="cat_parent" id="cat_parent">
                    <option value="">بدون والد</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('categoryModal')">بستن</button>
                <button type="submit" class="btn btn-success" id="categorySubmitBtn">افزودن دسته‌بندی</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال تغییر وضعیت ===== -->
<div class="modal-overlay" id="quickStatusModal">
    <div class="modal-box">
        <h2>تغییر وضعیت مقاله</h2>
        <form method="POST" action="">
            <input type="hidden" name="article_action" value="change_status">
            <input type="hidden" name="article_id" id="quick_status_article_id" value="0">
            <div class="form-group">
                <label>وضعیت جدید</label>
                <select name="new_status" id="quick_status_select">
                    <option value="draft">پیش‌نویس</option>
                    <option value="pending">در انتظار تایید</option>
                    <option value="approved">تایید شده</option>
                    <option value="rejected">رد شده</option>
                    <option value="needs_revision">نیاز به اصلاح</option>
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
    document.getElementById('mobileToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    });
    document.getElementById('sidebarOverlay').addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    });

    function openAddModal() {
        document.getElementById('articleModalTitle').textContent = 'مقاله جدید';
        document.getElementById('article_action').value = 'add';
        document.getElementById('article_id').value = 0;
        
        document.getElementById('article_title').value = '';
        document.getElementById('article_summary').value = '';
        document.getElementById('article_content').value = '';
        document.getElementById('article_status').value = 'pending';
        document.getElementById('article_category').value = '';
        
        var shamsiToday = '<?= jdate('Y/m/d') ?>';
        document.getElementById('created_date').value = shamsiToday;
        
        document.getElementById('articleModal').classList.add('active');
    }

    function editArticle(id) {
        fetch('get_article.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('articleModalTitle').textContent = 'ویرایش مقاله';
                    document.getElementById('article_action').value = 'edit';
                    document.getElementById('article_id').value = id;
                    document.getElementById('article_title').value = data.title || '';
                    document.getElementById('article_summary').value = data.summary || '';
                    document.getElementById('article_content').value = data.content || '';
                    document.getElementById('article_category').value = data.category_id || '';
                    document.getElementById('article_status').value = data.status || 'pending';
                    document.getElementById('articleModal').classList.add('active');
                } else {
                    alert('خطا در دریافت اطلاعات مقاله: ' + (data.error || 'نامشخص'));
                }
            })
            .catch(function(error) {
                alert('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
                console.error('Error:', error);
            });
    }

    function viewArticle(id) {
        window.open('view_article.php?id=' + id, '_blank');
    }

    function deleteArticle(id) {
        if (confirm('آیا از حذف این مقاله مطمئن هستید؟ این عملیات غیرقابل بازگشت است!')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            var input1 = document.createElement('input');
            input1.type = 'hidden';
            input1.name = 'article_action';
            input1.value = 'delete';
            var input2 = document.createElement('input');
            input2.type = 'hidden';
            input2.name = 'article_id';
            input2.value = id;
            form.appendChild(input1);
            form.appendChild(input2);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function quickChangeStatus(id) {
        document.getElementById('quick_status_article_id').value = id;
        document.getElementById('quick_status_select').value = 'pending';
        document.getElementById('quickStatusModal').classList.add('active');
    }

    function openCategoryModal() {
        document.getElementById('category_action').value = 'add';
        document.getElementById('cat_id').value = 0;
        document.getElementById('cat_name').value = '';
        document.getElementById('cat_color').value = '#6c757d';
        document.getElementById('cat_parent').value = '';
        document.getElementById('categorySubmitBtn').textContent = 'افزودن دسته‌بندی';
        document.getElementById('categoryModal').classList.add('active');
    }

    function editCategory(id, name, color) {
        document.getElementById('category_action').value = 'edit';
        document.getElementById('cat_id').value = id;
        document.getElementById('cat_name').value = name;
        document.getElementById('cat_color').value = color;
        document.getElementById('cat_parent').value = '';
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