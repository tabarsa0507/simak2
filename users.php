<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
require_once 'includes/UserService.php';
requireLogin();

$user = getCurrentUser();
if (!in_array($user['role'], ['admin', 'manager'])) {
    die('شما دسترسی لازم برای مدیریت کاربران را ندارید.');
}

$page_title = 'مدیریت کاربران';
$csrfToken = generateCsrfToken();

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


// ===== تولید توکن CSRF =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$userService = new UserService($pdo);

// دریافت نقش‌ها و گروه‌ها برای فرم‌ها
$roles = $pdo->query("SELECT * FROM roles WHERE status = 1 ORDER BY id")->fetchAll();
$groups = $pdo->query("SELECT * FROM user_groups ORDER BY name")->fetchAll();

// ===== پردازش فرم‌ها =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('درخواست نامعتبر (CSRF)');
    }
    
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'add_user':
                $data = [
                    'national_id' => trim($_POST['national_id']),
                    'password' => $_POST['password'],
                    'full_name' => trim($_POST['full_name']),
                    'email' => trim($_POST['email'] ?? ''),
                    'mobile' => trim($_POST['mobile']),
                    'birth_year' => (int)trim($_POST['birth_year']),
                    'birth_city' => trim($_POST['birth_city']),
                    'role_id' => (int)($_POST['role_id'] ?? 0),
                    'status' => (int)($_POST['status'] ?? 1)
                ];
                $userService->addUser($data);
                $_SESSION['success'] = 'کاربر با موفقیت اضافه شد.';
                break;
                
            case 'edit_user':
                $id = (int)$_POST['id'];
                $data = [
                    'national_id' => trim($_POST['national_id']),
                    'full_name' => trim($_POST['full_name']),
                    'email' => trim($_POST['email'] ?? ''),
                    'mobile' => trim($_POST['mobile']),
                    'birth_year' => (int)trim($_POST['birth_year']),
                    'birth_city' => trim($_POST['birth_city']),
                    'role_id' => (int)($_POST['role_id'] ?? 0),
                    'status' => (int)($_POST['status'] ?? 1),
                    'new_password' => trim($_POST['new_password'] ?? '')
                ];
                if (!empty($_FILES['profile_picture']['name'])) {
                    $uploadResult = uploadProfilePicture($_FILES['profile_picture']);
                    if ($uploadResult['success']) {
                        $data['profile_picture'] = $uploadResult['path'];
                    } else {
                        throw new Exception($uploadResult['error']);
                    }
                }
                $userService->editUser($id, $data);
                $_SESSION['success'] = 'کاربر با موفقیت ویرایش شد.';
                break;
                
            case 'delete_user':
                $id = (int)$_POST['id'];
                $userService->deleteUser($id, $_SESSION['user_id']);
                $_SESSION['success'] = 'کاربر با موفقیت حذف شد.';
                break;
                
            case 'change_status':
                $id = (int)$_POST['id'];
                $status = (int)$_POST['status'];
                $userService->changeStatus($id, $status);
                $_SESSION['success'] = 'وضعیت کاربر تغییر کرد.';
                break;
                
            case 'change_role':
                $id = (int)$_POST['id'];
                $roleId = (int)$_POST['role_id'];
                $userService->changeRole($id, $roleId);
                $_SESSION['success'] = 'نقش کاربر تغییر کرد.';
                break;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ===== دریافت داده‌ها برای نمایش =====
$page = $_GET['page'] ?? 1;
$filters = [
    'search' => $_GET['search'] ?? '',
    'role_id' => $_GET['role_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

$result = $userService->getUsers($filters, $page, 10);
$users = $result['users'];
$totalUsers = $result['total'];
$totalPages = $result['totalPages'];
$stats = $userService->getStats();

// آخرین فعالیت‌ها (۴ مورد)
$lastActivities = $pdo->query("
    SELECT * FROM activity_logs 
    WHERE module = 'users' 
    ORDER BY created_at DESC 
    LIMIT 4
")->fetchAll();

// ===== توابع کمکی =====
function getTimeAgo($timestamp) {
    date_default_timezone_set('Asia/Tehran');
    $now = time();
    $diff = $now - strtotime($timestamp);
    
    if ($diff < 60) return 'لحظاتی پیش';
    if ($diff < 3600) return floor($diff / 60) . ' دقیقه پیش';
    if ($diff < 86400) return floor($diff / 3600) . ' ساعت پیش';
    if ($diff < 604800) return floor($diff / 86400) . ' روز پیش';
    return jdate('Y/m/d', strtotime($timestamp));
}

// نمایش پیام‌ها
if (isset($_SESSION['success'])) { 
    $successMessage = $_SESSION['success']; 
    unset($_SESSION['success']); 
} else {
    $successMessage = '';
}
if (isset($_SESSION['error'])) { 
    $errorMessage = $_SESSION['error']; 
    unset($_SESSION['error']); 
} else {
    $errorMessage = '';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت کاربران</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/jalalidatepicker.min.css">
    <script src="assets/js/jalalidatepicker.min.js"></script>
    <script src="assets/js/persian-date.js"></script>
    <script src="assets/js/global.js"></script>
    <style>
        /* ===== تمام استایل‌های دبیرخانه ===== */
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

        .stats-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 14px; margin-bottom: 28px; }
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
        .stat-card.info-light { background: #e8f4fd; border-color: #b8d4f5; }
        .stat-card.info-light .stat-icon { border-color: #b8d4f5; color: #0969da; }
        .stat-card.info-light:hover { box-shadow: 0 8px 30px rgba(9,105,218,0.20); transform: translateY(-3px); border-color: #0969da; }
        .stat-card.success-light { background: #ecfdf3; border-color: #a8e6c1; }
        .stat-card.success-light .stat-icon { border-color: #a8e6c1; color: #0f9d58; }
        .stat-card.success-light:hover { box-shadow: 0 8px 30px rgba(15,157,88,0.20); transform: translateY(-3px); border-color: #0f9d58; }
        .stat-card.danger-light { background: #fef2f2; border-color: #f5c8c8; }
        .stat-card.danger-light .stat-icon { border-color: #f5c8c8; color: #dc2626; }
        .stat-card.danger-light:hover { box-shadow: 0 8px 30px rgba(220,38,38,0.20); transform: translateY(-3px); border-color: #dc2626; }

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
            padding: 8px 20px; background: #0969da; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif; display: inline-flex; align-items: center; gap: 6px;
            margin-right: auto;
        }
        .filters-bar .btn-add:hover { background: #0550b3; }
        .filters-bar .btn-settings {
            padding: 8px 20px; background: #8250df; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif; display: inline-flex; align-items: center; gap: 6px;
        }
        .filters-bar .btn-settings:hover { background: #6a3fc7; }

        .actions-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
        }

        /* ===== استایل دکمه‌های عملیات ===== */
        .btn-action {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Vazirmatn', sans-serif;
            color: #fff;
            position: relative;
            overflow: hidden;
            min-width: 140px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .btn-action::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: all 0.5s ease;
        }
        .btn-action:hover::before { left: 100%; }
        .btn-action:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .btn-action:active { transform: translateY(0px) scale(0.97); }
        
        .btn-add-user { background: linear-gradient(135deg, #0f9d58, #0b7a44); }
        .btn-add-user:hover { background: linear-gradient(135deg, #0c8b4a, #09693a); box-shadow: 0 8px 25px rgba(15,157,88,0.4); }
        .btn-roles { background: linear-gradient(135deg, #7c3aed, #5b21b6); }
        .btn-roles:hover { background: linear-gradient(135deg, #6d28d9, #4c1d95); box-shadow: 0 8px 25px rgba(124,58,237,0.4); }
        .btn-groups { background: linear-gradient(135deg, #ea580c, #c2410c); }
        .btn-groups:hover { background: linear-gradient(135deg, #d97706, #b45309); box-shadow: 0 8px 25px rgba(234,88,12,0.4); }
        .btn-bulk-email { background: linear-gradient(135deg, #d97706, #b45309); }
        .btn-bulk-email:hover { background: linear-gradient(135deg, #ca8a04, #a16207); box-shadow: 0 8px 25px rgba(217,119,6,0.4); }

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
        .table-modern td:first-child { border-right: 4px solid #e2e8f0; }
        
        .badge-status { padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; white-space: nowrap; }
        .badge-role { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; white-space: nowrap; }
        
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
        .table-modern .actions .btn-icon.role { color: #8250df; }
        .table-modern .actions .btn-icon.role:hover { background: #f5f0ff; }
        .table-modern .actions .btn-icon.delete { color: #991b1b; }
        .table-modern .actions .btn-icon.delete:hover { background: #fecaca; }

        /* ===== فعالیت‌ها ===== */
        .activities-panel {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            padding: 16px 20px;
            margin-bottom: 24px;
        }
        .activities-panel .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }
        .activities-panel .panel-header h3 {
            font-size: 15px;
            font-weight: 700;
            color: #24292f;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .activities-panel .panel-header .badge-count {
            background: #0969da;
            color: #fff;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .activities-timeline {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .activity-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s ease;
            border-right: 4px solid #0969da;
        }
        .activity-card:hover {
            background: #f0f2f4;
            transform: translateX(-3px);
        }
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .activity-content {
            flex: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .activity-title {
            font-size: 13px;
            font-weight: 500;
            color: #24292f;
        }
        .activity-time {
            font-size: 12px;
            color: #57606a;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .activity-empty {
            text-align: center;
            padding: 30px 0;
            color: #57606a;
        }
        .activity-empty i {
            font-size: 32px;
            color: #d0d7de;
            display: block;
            margin-bottom: 8px;
        }

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
            font-size: 15px; font-weight: 700; color: #1a1a2e; margin-bottom: 12px;
        }
        .modal-box .sub-section-title i { margin-left: 8px; color: #0969da; }

        .date-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0;
        }
        .date-wrapper input {
            flex: 1;
            padding-left: 35px !important;
            cursor: pointer;
        }
        .date-wrapper .calendar-icon {
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
            z-index: 10;
        }
        .date-wrapper .calendar-icon:hover {
            color: #0969da;
        }

        .pagination {
            display: flex;
            gap: 4px;
            justify-content: center;
            padding: 14px 0;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 6px 14px;
            border-radius: 6px;
            border: 1px solid #e1e4e8;
            background: #fff;
            color: #24292f;
            font-size: 14px;
            transition: 0.2s;
            text-decoration: none;
        }
        .pagination a:hover { background: #f0f2f4; border-color: #0969da; }
        .pagination .active { background: #0969da; color: #fff; border-color: #0969da; }

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

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 500;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a8e6c1; }
        .alert-danger { background: #fecaca; color: #991b1b; border: 1px solid #f5c8c8; }

        @media (max-width: 1400px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
        @media (max-width: 991px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .sidebar { right: -260px; width: 260px; }
            .sidebar.open { right: 0; }
            .main-content { margin-right: 0; padding: 20px 16px 20px 16px; }
            .mobile-toggle { display: flex !important; }
            .top-bar { flex-direction: column; align-items: flex-start; gap: 12px; }
            .top-right { width: 100%; flex-wrap: wrap; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .filters-bar .filter-group { flex-wrap: wrap; }
            .filters-bar input[name="search"] { min-width: 100%; }
            .modal-box { padding: 20px; }
            .modal-box .form-row { flex-direction: column; }
            .modal-box .form-row .form-group { min-width: 100%; }
            .actions-bar { flex-direction: column; }
            .btn-action { min-width: 100%; justify-content: center; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
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
        <div class="brand"><i class="fas fa-users-cog"></i> سامانه مدیریت پیشگامان</div>
        <div class="sub">پنل مدیریت</div>
    </div>
   <ul class="sidebar-menu">
        <li class="label">منو</li>
        <li><a href="dashboard.php"><i class="fas fa-chart-pie"></i> داشبورد</a></li>
        <li><a href="secretariat.php"><i class="fas fa-inbox"></i> دبیرخانه</a></li>
        <li><a href="reminder.php"><i class="fas fa-bell"></i> یادآور</a></li>
        <li><a href="travel_expenses.php"><i class="fas fa-route"></i> مأموریت</a></li>
        <li><a href="assets.php"><i class="fas fa-boxes"></i> بانک اموال</a></li>
        <li><a href="knowledge.php"><i class="fas fa-database"></i> بانک دانش</a></li>
        <li><a href="links.php"><i class="fas fa-link"></i> بانک لینک</a></li>
        <li><a href="downloads.php"><i class="fas fa-download"></i> مرکز دانلود</a></li>
        <li><a href="users.php" class="active"><i class="fas fa-users"></i> مدیریت کاربران</a></li>
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
                <i class="fas fa-users" style="color: #0969da; margin-left: 10px;"></i>
                مدیریت کاربران
                <span>مدیریت و کنترل کاربران</span>
            </h1>
            <div class="top-right">
                <div class="date"><i class="fas fa-calendar"></i> <?= persian_number_str(nowShamsi1('Y/m/d H:i')) ?></div>
                <div class="user-profile">
                    <div class="user-avatar"><?= mb_substr($user['full_name'] ?? $user['username'] ?? 'کاربر', 0, 1, 'UTF-8') ?></div>
                    <div>
                        <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'کاربر') ?></div>
                        <div class="user-role"><?= htmlspecialchars($user['role'] ?? 'بدون نقش') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== پیام‌ها ===== -->
    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <!-- ===== آمار ===== -->
    <div class="stats-grid">
        <div class="stat-card blue-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-users"></i></span><span class="stat-number"><?= $stats['total'] ?></span></div>
            <div class="stat-label">کل کاربران</div>
        </div>
        <div class="stat-card green-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-user-check"></i></span><span class="stat-number"><?= $stats['active'] ?></span></div>
            <div class="stat-label">فعال</div>
        </div>
        <div class="stat-card red-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-user-slash"></i></span><span class="stat-number"><?= $stats['inactive'] ?></span></div>
            <div class="stat-label">غیرفعال</div>
        </div>
        <div class="stat-card purple-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-user-shield"></i></span><span class="stat-number"><?= $stats['admins'] ?></span></div>
            <div class="stat-label">ادمین</div>
        </div>
        <div class="stat-card orange-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-user-tie"></i></span><span class="stat-number"><?= $stats['managers'] ?></span></div>
            <div class="stat-label">مدیران</div>
        </div>
        <div class="stat-card info-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-user-graduate"></i></span><span class="stat-number"><?= $stats['employees'] ?></span></div>
            <div class="stat-label">کارمندان</div>
        </div>
        <div class="stat-card success-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-sign-in-alt"></i></span><span class="stat-number"><?= $stats['today_logins'] ?></span></div>
            <div class="stat-label">ورود امروز</div>
        </div>
        <div class="stat-card danger-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-lock"></i></span><span class="stat-number"><?= $stats['locked'] ?></span></div>
            <div class="stat-label">قفل‌شده</div>
        </div>
    </div>

    <!-- ===== فیلترها ===== -->
    <form class="filters-bar" method="GET">
        <div class="filter-group">
            <input type="text" name="search" placeholder="جستجوی نام، کد ملی، موبایل..." value="<?= htmlspecialchars($filters['search']) ?>">
        </div>
        <div class="filter-group">
            <select name="role_id">
                <option value="">همه نقش‌ها</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= $role['id'] ?>" <?= ($filters['role_id'] == $role['id']) ? 'selected' : '' ?>><?= htmlspecialchars($role['role_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <select name="status">
                <option value="">همه وضعیت‌ها</option>
                <option value="1" <?= ($filters['status'] === '1') ? 'selected' : '' ?>>فعال</option>
                <option value="0" <?= ($filters['status'] === '0') ? 'selected' : '' ?>>غیرفعال</option>
            </select>
        </div>
        <div class="filter-group">
            <div class="date-wrapper">
                <input type="text" name="date_from" class="persian-date" placeholder="از تاریخ" value="<?= htmlspecialchars($filters['date_from']) ?>" autocomplete="off">
                <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();">
                    <i class="fas fa-calendar-alt"></i>
                </button>
            </div>
            <span style="color:#8b93a5;">تا</span>
            <div class="date-wrapper">
                <input type="text" name="date_to" class="persian-date" placeholder="تا تاریخ" value="<?= htmlspecialchars($filters['date_to']) ?>" autocomplete="off">
                <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();">
                    <i class="fas fa-calendar-alt"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> فیلتر</button>
        <a href="users.php" class="btn-reset"><i class="fas fa-undo"></i> پاک کردن</a>
    </form>

    <!-- ===== دکمه‌های عملیات ===== -->
    <div class="actions-bar">
        <button class="btn-action btn-add-user" id="btnAddUser">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">افزودن کاربر</div>
                    <div style="font-size:10px;opacity:0.8;">ثبت کاربر جدید</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-roles" onclick="openRoleModal()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-user-tag"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">نقش‌ها</div>
                    <div style="font-size:10px;opacity:0.8;">مدیریت نقش‌ها</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-groups" onclick="openGroupModal()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">گروه‌ها</div>
                    <div style="font-size:10px;opacity:0.8;">مدیریت گروه‌ها</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-bulk-email" onclick="openBulkEmail()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-envelope-open-text"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">ارسال گروهی</div>
                    <div style="font-size:10px;opacity:0.8;">ایمیل/پیامک</div>
                </div>
            </div>
        </button>
    </div>

    <!-- ===== آخرین فعالیت‌ها ===== -->
    <div class="activities-panel">
        <div class="panel-header">
            <h3><i class="fas fa-history" style="color:#0969da;"></i> آخرین فعالیت‌ها</h3>
            <span class="badge-count"><?= count($lastActivities) ?></span>
        </div>
        <?php if (empty($lastActivities)): ?>
            <div class="activity-empty">
                <i class="fas fa-inbox"></i>
                <p>هیچ فعالیتی ثبت نشده است.</p>
            </div>
        <?php else: ?>
            <div class="activities-timeline">
                <?php foreach ($lastActivities as $activity): 
                    $timeAgo = getTimeAgo($activity['created_at']);
                ?>
                    <div class="activity-card" style="border-right:4px solid #0969da;">
                        <div class="activity-icon" style="background:#dbeafe;color:#0969da;">
                            <i class="fas fa-user-edit"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title"><?= htmlspecialchars($activity['description'] ?? $activity['action']) ?></div>
                            <div class="activity-time"><i class="far fa-clock"></i> <?= $timeAgo ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ===== جدول ===== -->
    <div class="table-wrapper">
        <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:120px;">کد ملی</th>
                        <th>نام کامل</th>
                        <th style="width:120px;">موبایل</th>
                        <th style="width:100px;">نقش</th>
                        <th style="width:100px;">وضعیت</th>
                        <th style="width:110px;">تاریخ ثبت</th>
                        <th style="width:130px;">آخرین ورود</th>
                        <th style="width:180px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:40px;color:#8b93a5;">هیچ کاربری یافت نشد.</td></tr>
                    <?php else: $i = ($page - 1) * 10 + 1; foreach ($users as $item): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td style="font-weight:600;color:#24292f;"><?= htmlspecialchars($item['national_id']) ?></td>
                            <td><?= htmlspecialchars($item['full_name']) ?></td>
                            <td dir="ltr"><?= htmlspecialchars($item['mobile']) ?></td>
                            <td>
                                <span class="badge-role" style="background:#4facfe20;color:#4facfe;">
                                    <?= htmlspecialchars($item['role_name'] ?? 'بدون نقش') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-status <?= $item['status'] == 1 ? 'status-active' : 'status-inactive' ?>"
                                      style="background:<?= $item['status'] == 1 ? '#d4edda' : '#f8d7da' ?>;color:<?= $item['status'] == 1 ? '#155724' : '#721c24' ?>;">
                                    <?= $item['status'] == 1 ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </td>
                            <td style="font-size:13px;color:#57606a;"><?= jdate('Y/m/d', strtotime($item['created_at'])) ?></td>
                            <td style="font-size:13px;color:#57606a;"><?= $item['last_login'] ? jdate('Y/m/d H:i', strtotime($item['last_login'])) : '-' ?></td>
                            <td>
                                <div class="actions">
                                    <button class="btn-icon edit" onclick="editUser(<?= $item['id'] ?>)" title="ویرایش"><i class="fas fa-edit"></i></button>
                                    <button class="btn-icon status" onclick="quickChangeStatus(<?= $item['id'] ?>)" title="تغییر وضعیت"><i class="fas fa-exchange-alt"></i></button>
                                    <button class="btn-icon role" onclick="quickChangeRole(<?= $item['id'] ?>)" title="تغییر نقش"><i class="fas fa-user-tag"></i></button>
                                    <?php if ($item['id'] != $user['id']): ?>
                                        <button class="btn-icon delete" onclick="deleteUser(<?= $item['id'] ?>)" title="حذف"><i class="fas fa-trash-alt"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <!-- ===== صفحه‌بندی ===== -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p == $page): ?>
                        <span class="active"><?= $p ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $p ?>&<?= http_build_query(array_diff_key($filters, ['page' => 0])) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

</main>

<!-- ===== فوتر ===== -->
<div class="footer">
    <div class="footer-content">
        <span>طراحی و توسعه: <a href="#" target="_blank">شرکت دانش بنیان پیشگامان دنیای فناوری</a></span>
        <span class="footer-divider">|</span>
        <span class="footer-version">نسخه 2.0</span>
    </div>
</div>

<!-- ============================================= -->
<!-- ===== مودال‌ها ===== -->
<!-- ============================================= -->

<!-- ===== مودال افزودن کاربر ===== -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal-box">
        <h2><i class="fas fa-user-plus" style="color:#0f9d58;"></i> افزودن کاربر جدید</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="add_user">
            <div class="form-row">
                <div class="form-group">
                    <label>کد ملی <span style="color:red;">*</span></label>
                    <input type="text" name="national_id" id="national_id" required placeholder="۱۰ رقم" maxlength="10" oninput="this.value = this.value.replace(/\D/g, '').slice(0, 10)">
                </div>
                <div class="form-group">
                    <label>رمز عبور <span style="color:red;">*</span></label>
                    <input type="password" name="password" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>نام کامل <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" required placeholder="مثلاً: علی محمدی">
                </div>
                <div class="form-group">
                    <label>شماره همراه <span style="color:red;">*</span></label>
                    <input type="text" name="mobile" required placeholder="۰۹۱۲۳۴۵۶۷۸۹" maxlength="11" oninput="this.value = this.value.replace(/\D/g, '').slice(0, 11)">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>ایمیل</label>
                    <input type="email" name="email" placeholder="email@example.com">
                </div>
                <div class="form-group">
                    <label>سال تولد <span style="color:red;">*</span></label>
                    <input type="text" name="birth_year" required placeholder="مثلاً ۱۳۷۰" maxlength="4" oninput="this.value = this.value.replace(/\D/g, '').slice(0, 4)">
                </div>
            </div>
            <div class="form-group">
                <label>شهر تولد <span style="color:red;">*</span></label>
                <input type="text" name="birth_city" required placeholder="مثلاً: تهران">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>نقش <span style="color:red;">*</span></label>
                    <select name="role_id" required>
                        <option value="">انتخاب نقش...</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>وضعیت</label>
                    <select name="status">
                        <option value="1">فعال</option>
                        <option value="0">غیرفعال</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>عکس پروفایل (اختیاری)</label>
                <input type="file" name="profile_picture" accept="image/*">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">لغو</button>
                <button type="submit" class="btn btn-primary">افزودن کاربر</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال ویرایش کاربر ===== -->
<div class="modal-overlay" id="editUserModal">
    <div class="modal-box">
        <h2><i class="fas fa-user-edit" style="color:#d4a72c;"></i> ویرایش کاربر</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-row">
                <div class="form-group">
                    <label>کد ملی <span style="color:red;">*</span></label>
                    <input type="text" name="national_id" id="edit_national_id" required maxlength="10" oninput="this.value = this.value.replace(/\D/g, '').slice(0, 10)">
                </div>
                <div class="form-group">
                    <label>نام کامل <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="edit_full_name" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>شماره همراه <span style="color:red;">*</span></label>
                    <input type="text" name="mobile" id="edit_mobile" required maxlength="11" oninput="this.value = this.value.replace(/\D/g, '').slice(0, 11)">
                </div>
                <div class="form-group">
                    <label>ایمیل</label>
                    <input type="email" name="email" id="edit_email" placeholder="email@example.com">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>سال تولد <span style="color:red;">*</span></label>
                    <input type="text" name="birth_year" id="edit_birth_year" required maxlength="4" oninput="this.value = this.value.replace(/\D/g, '').slice(0, 4)">
                </div>
                <div class="form-group">
                    <label>شهر تولد <span style="color:red;">*</span></label>
                    <input type="text" name="birth_city" id="edit_birth_city" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>نقش <span style="color:red;">*</span></label>
                    <select name="role_id" id="edit_role_id" required>
                        <option value="">انتخاب نقش...</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>وضعیت</label>
                    <select name="status" id="edit_status">
                        <option value="1">فعال</option>
                        <option value="0">غیرفعال</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>رمز عبور جدید (در صورت تغییر)</label>
                <input type="password" name="new_password" placeholder="برای عدم تغییر خالی بگذارید">
            </div>
            <div class="form-group">
                <label>عکس پروفایل (اختیاری)</label>
                <input type="file" name="profile_picture" accept="image/*">
                <div id="edit_profile_preview" style="margin-top:8px;"></div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">لغو</button>
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
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
            <input type="hidden" name="id" id="quick_status_id" value="0">
            <div class="form-group">
                <label>وضعیت جدید</label>
                <select name="status" id="quick_status_select">
                    <option value="1">فعال</option>
                    <option value="0">غیرفعال</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('quickStatusModal')">لغو</button>
                <button type="submit" class="btn btn-primary">تغییر وضعیت</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال تغییر نقش ===== -->
<div class="modal-overlay" id="quickRoleModal">
    <div class="modal-box">
        <h2><i class="fas fa-user-tag" style="color:#8250df;"></i> تغییر نقش</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="change_role">
            <input type="hidden" name="id" id="quick_role_id" value="0">
            <div class="form-group">
                <label>نقش جدید</label>
                <select name="role_id" id="quick_role_select">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('quickRoleModal')">لغو</button>
                <button type="submit" class="btn btn-primary">تغییر نقش</button>
            </div>
        </form>
    </div>
</div>

<button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>

<script>
// ===== راه‌اندازی تاریخ شمسی =====
function initDatepickers() {
    document.querySelectorAll('.persian-date').forEach(function(input) {
        if (typeof jalaliDatepicker !== 'undefined') {
            try {
                jalaliDatepicker.startWatch({
                    container: input,
                    minDate: 'attr',
                    maxDate: 'attr'
                });
            } catch(e) {
                console.warn('خطا در راه‌اندازی تاریخ شمسی:', e);
            }
        }
    });
}

// ================================================
// ===== رویداد بارگذاری صفحه =====
// ================================================
document.addEventListener('DOMContentLoaded', function() {
    // دکمه افزودن کاربر
    document.getElementById('btnAddUser')?.addEventListener('click', function() {
        document.getElementById('addUserModal').classList.add('active');
        initDatepickers();
    });

    // منوی موبایل
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

    // بستن مودال با کلیک روی پس‌زمینه
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
    
    initDatepickers();
});

// ================================================
// ===== توابع سراسری =====
// ================================================

function openAddUserModal() {
    document.getElementById('addUserModal').classList.add('active');
    initDatepickers();
}

function editUser(id) {
    fetch('get_user.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_id').value = data.id;
                document.getElementById('edit_national_id').value = data.national_id;
                document.getElementById('edit_full_name').value = data.full_name;
                document.getElementById('edit_email').value = data.email || '';
                document.getElementById('edit_mobile').value = data.mobile;
                document.getElementById('edit_birth_year').value = data.birth_year;
                document.getElementById('edit_birth_city').value = data.birth_city;
                document.getElementById('edit_role_id').value = data.role_id || 0;
                document.getElementById('edit_status').value = data.status;
                if (data.profile_picture) {
                    document.getElementById('edit_profile_preview').innerHTML = '<img src="' + data.profile_picture + '" style="max-width:100px;border-radius:8px;border:1px solid #e1e4e8;">';
                } else {
                    document.getElementById('edit_profile_preview').innerHTML = '';
                }
                document.getElementById('editUserModal').classList.add('active');
            } else {
                alert('خطا در دریافت اطلاعات کاربر');
            }
        })
        .catch(error => console.error('خطا:', error));
}

function quickChangeStatus(id) {
    document.getElementById('quick_status_id').value = id;
    document.getElementById('quick_status_select').value = '1';
    document.getElementById('quickStatusModal').classList.add('active');
}

function quickChangeRole(id) {
    document.getElementById('quick_role_id').value = id;
    document.getElementById('quickRoleModal').classList.add('active');
}

function deleteUser(id) {
    if (confirm('آیا از حذف این کاربر مطمئن هستید؟')) {
        var form = document.createElement('form');
        form.method = 'POST';
        var csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = '<?= $csrfToken ?>';
        var action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'delete_user';
        var userId = document.createElement('input');
        userId.type = 'hidden';
        userId.name = 'id';
        userId.value = id;
        form.appendChild(csrf);
        form.appendChild(action);
        form.appendChild(userId);
        document.body.appendChild(form);
        form.submit();
    }
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function openDatePicker(element) {
    var wrapper = element.parentElement;
    var input = wrapper.querySelector('input[type="text"]');
    if (input) {
        input.focus();
        input.click();
    }
}

function openRoleModal() {
    window.location.href = 'roles.php';
}

function openGroupModal() {
    window.location.href = 'groups.php';
}

function openBulkEmail() {
    window.location.href = 'bulk_email.php';
}
</script>
</body>
</html>