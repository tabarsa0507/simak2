<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'سفارش اغذیه روزانه';

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

// ===== دریافت نقش‌ها =====
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

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ===== دریافت لیست کاربران و پروژه‌ها =====
$users = $pdo->query("SELECT id, full_name FROM users WHERE status = 1 ORDER BY full_name")->fetchAll();
$projects = $pdo->query("SELECT id, name FROM projects WHERE status = 1 ORDER BY name")->fetchAll();

// ===== دریافت لیست سفارشات =====
$where = [];
$params = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where[] = "(fo.meal_type LIKE :search)";
    $params[':search'] = '%' . $_GET['search'] . '%';
}
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where[] = "fo.order_date >= :date_from";
    $params[':date_from'] = $_GET['date_from'];
}
if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where[] = "fo.order_date <= :date_to";
    $params[':date_to'] = $_GET['date_to'];
}
$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "
    SELECT fo.*, u.full_name as creator_name,
           (SELECT COUNT(*) FROM food_order_items WHERE order_id = fo.id) as item_count
    FROM food_orders fo
    LEFT JOIN users u ON fo.created_by = u.id
    $whereClause
    ORDER BY fo.id DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// ===== آمار =====
$stats = [
    'total' => count($orders),
    'today' => count(array_filter($orders, fn($a) => $a['order_date'] == nowShamsi('Y/m/d'))),
];

// ===== پردازش فرم =====
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        if ($action === 'add' || $action === 'edit') {
            $order_date = trim($_POST['order_date'] ?? '');
            $meal_type = trim($_POST['meal_type'] ?? '');
            $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
            
            if (empty($order_date) || empty($meal_type)) {
                $_SESSION['error'] = 'تاریخ و وعده غذایی الزامی است';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            }
            
            $pdo->beginTransaction();
            
            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO food_orders (order_date, meal_type, created_by) VALUES (?, ?, ?)");
                $stmt->execute([$order_date, $meal_type, $user['id']]);
                $order_id = $pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare("UPDATE food_orders SET order_date = ?, meal_type = ? WHERE id = ?");
                $stmt->execute([$order_date, $meal_type, $order_id]);
                // حذف آیتم‌های قبلی
                $pdo->prepare("DELETE FROM food_order_items WHERE order_id = ?")->execute([$order_id]);
            }
            
            // ذخیره آیتم‌های اضافی
            $colleague_names = $_POST['colleague_name'] ?? [];
            $food_names = $_POST['food_name'] ?? [];
            $project_ids = $_POST['project_id'] ?? [];
            
            for ($i = 0; $i < count($colleague_names); $i++) {
                if (!empty($colleague_names[$i]) && !empty($food_names[$i])) {
                    $stmt = $pdo->prepare("INSERT INTO food_order_items (order_id, colleague_name, food_name, project_id) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $order_id,
                        trim($colleague_names[$i]),
                        trim($food_names[$i]),
                        !empty($project_ids[$i]) ? (int)$project_ids[$i] : null
                    ]);
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = 'سفارش با موفقیت ثبت شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        if ($action === 'delete' && !empty($_POST['order_id'])) {
            $pdo->prepare("DELETE FROM food_orders WHERE id = ?")->execute([$_POST['order_id']]);
            $_SESSION['success'] = 'سفارش حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'خطا: ' . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// ===== نمایش پیام‌ها =====
if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" id="successMessage">' . htmlspecialchars($_SESSION['success']) . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger" id="errorMessage">' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سفارش اغذیه روزانه</title>
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

        .sidebar { width: 240px; background: #fff; border-left: 1px solid #e1e4e8; padding: 24px 16px; display: flex; flex-direction: column; position: fixed; top: 0; right: 0; height: 100vh; z-index: 1000; transition: all 0.3s ease; overflow-y: auto; }
        .sidebar-brand { padding: 0 8px 24px; border-bottom: 1px solid #e1e4e8; margin-bottom: 20px; }
        .sidebar-brand .brand { font-size: 18px; font-weight: 800; color: #24292f; display: flex; align-items: center; gap: 10px; }
        .sidebar-brand .brand i { color: #0969da; font-size: 22px; }
        .sidebar-brand .sub { font-size: 12px; color: #57606a; font-weight: 400; margin-top: 2px; }
        .sidebar-menu { list-style: none; flex: 1; }
        .sidebar-menu li a { display: flex; align-items: center; gap: 12px; padding: 8px 12px; color: #57606a; border-radius: 6px; font-size: 14px; font-weight: 500; transition: all 0.15s ease; margin-bottom: 2px; }
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
        .top-bar-wrapper { background: #ffffff; border-radius: 12px; border: 1px solid #e1e4e8; padding: 12px 24px; margin-bottom: 28px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .top-bar h1 { font-size: 20px; font-weight: 700; color: #24292f; }
        .top-bar h1 span { font-weight: 400; font-size: 13px; color: #57606a; margin-right: 8px; }
        .top-right { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .top-right .date { font-size: 13px; color: #57606a; background: #f6f8fa; padding: 6px 14px; border-radius: 6px; border: 1px solid #e1e4e8; white-space: nowrap; font-family: 'Vazirmatn', sans-serif; direction: ltr; }
        .top-right .date i { margin-left: 6px; color: #8b949e; }
        .user-profile { display: flex; align-items: center; gap: 12px; background: #fff; padding: 4px 12px 4px 16px; border-radius: 6px; border: 1px solid #e1e4e8; }
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
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .stat-card .stat-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .stat-card .stat-icon { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; background: #ffffff; border: 1px solid; }
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

        .filters-bar { background: #ffffff; border-radius: 12px; border: 1px solid #e1e4e8; padding: 14px 20px; margin-bottom: 24px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        .filters-bar .filter-group { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .filters-bar input, .filters-bar select { padding: 8px 14px; border-radius: 6px; border: 1px solid #e1e4e8; font-size: 14px; font-family: 'Vazirmatn', sans-serif; background: #f8f9fa; outline: none; transition: 0.2s; color: #24292f; }
        .filters-bar input:focus, .filters-bar select:focus { border-color: #0969da; box-shadow: 0 0 0 3px rgba(9,105,218,0.1); }
        .filters-bar input[name="search"] { min-width: 250px; font-size: 15px; }
        .filters-bar .date-wrapper { position: relative; display: inline-block; }
        .filters-bar .date-wrapper input { padding-left: 35px; cursor: pointer; min-width: 140px; text-align: center; }
        .filters-bar .date-wrapper .calendar-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #8b949e; font-size: 16px; cursor: pointer; background: none; border: none; padding: 0; }
        .filters-bar .date-wrapper .calendar-icon:hover { color: #0969da; }
        .filters-bar .btn-filter { padding: 8px 20px; background: #0969da; color: #fff; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s; font-family: 'Vazirmatn', sans-serif; }
        .filters-bar .btn-filter:hover { background: #0550b3; }
        .filters-bar .btn-reset { padding: 8px 20px; background: #f0f2f4; color: #57606a; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s; font-family: 'Vazirmatn', sans-serif; }
        .filters-bar .btn-reset:hover { background: #e1e4e8; }

        .actions-bar { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; background: #ffffff; padding: 12px 20px; border-radius: 12px; border: 1px solid #e1e4e8; }
        .btn-action { padding: 10px 18px; border: none; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; font-family: 'Vazirmatn', sans-serif; color: #fff; position: relative; overflow: hidden; min-width: 140px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .btn-action::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent); transition: all 0.5s ease; }
        .btn-action:hover::before { left: 100%; }
        .btn-action:hover { transform: translateY(-2px) scale(1.02); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .btn-action:active { transform: translateY(0px) scale(0.97); }
        .btn-add-order { background: linear-gradient(135deg, #0f9d58, #0b7a44); }
        .btn-add-order:hover { background: linear-gradient(135deg, #0c8b4a, #09693a); box-shadow: 0 8px 25px rgba(15,157,88,0.4); }

        .table-wrapper { background: #fff; border-radius: 12px; border: 1px solid #e1e4e8; overflow: hidden; flex: 1; }
        .table-responsive { overflow-x: auto; }
        .table-modern { width: 100%; border-collapse: collapse; font-size: 14px; }
        .table-modern th { text-align: right; padding: 12px 16px; font-weight: 600; color: #57606a; background: #f8f9fa; border-bottom: 1px solid #e1e4e8; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .table-modern td { padding: 12px 16px; border-bottom: 1px solid #f0f2f4; vertical-align: middle; color: #24292f; }
        .table-modern tbody tr { transition: all 0.25s ease; border-right: 4px solid transparent; }
        .table-modern tbody tr:hover { transform: scale(1.01); box-shadow: 0 4px 16px rgba(0,0,0,0.06); z-index: 2; position: relative; }

        .row-color-0 { background: #f0f9ff; border-right-color: #3b82f6; }
        .row-color-1 { background: #f0fdf4; border-right-color: #22c55e; }
        .row-color-2 { background: #f5f3ff; border-right-color: #8b5cf6; }
        .row-color-3 { background: #fff7ed; border-right-color: #f97316; }
        .row-color-4 { background: #fdf2f8; border-right-color: #ec4899; }
        .row-color-5 { background: #f0fdfa; border-right-color: #14b8a6; }
        .row-color-6 { background: #fefce8; border-right-color: #eab308; }
        .row-color-7 { background: #fef2f2; border-right-color: #ef4444; }

        .badge-status { padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; white-space: nowrap; }

        .table-modern .actions { display: flex; gap: 4px; flex-wrap: wrap; }
        .table-modern .actions .btn-icon { width: 32px; height: 32px; border-radius: 6px; border: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; font-size: 13px; background: #f0f2f4; color: #57606a; }
        .table-modern .actions .btn-icon:hover { background: #e1e4e8; transform: scale(1.05); }
        .table-modern .actions .btn-icon.edit { color: #0969da; }
        .table-modern .actions .btn-icon.edit:hover { background: #ddf4ff; }
        .table-modern .actions .btn-icon.delete { color: #991b1b; }
        .table-modern .actions .btn-icon.delete:hover { background: #fecaca; }
        .table-modern .actions .btn-icon.view { color: #8250df; }
        .table-modern .actions .btn-icon.view:hover { background: #f5f0ff; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(4px); }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #fff; border-radius: 16px; padding: 30px 35px; max-width: 850px; width: 95%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: modalIn 0.3s ease; }
        @keyframes modalIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        .modal-box h2 { font-size: 20px; font-weight: 700; color: #1a1a2e; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .modal-box .form-group { margin-bottom: 16px; }
        .modal-box .form-group label { display: block; font-size: 13px; font-weight: 600; color: #57606a; margin-bottom: 4px; }
        .modal-box .form-group input, .modal-box .form-group select { width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #e1e4e8; font-size: 14px; font-family: 'Vazirmatn', sans-serif; outline: none; transition: 0.2s; background: #f8f9fa; }
        .modal-box .form-group input:focus, .modal-box .form-group select:focus { border-color: #0969da; box-shadow: 0 0 0 3px rgba(9,105,218,0.1); }
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
        .sub-section { border-top: 2px dashed #e1e4e8; padding-top: 16px; margin-top: 8px; }
        .sub-section-title { font-size: 15px; font-weight: 700; color: #1a1a2e; margin-bottom: 12px; }
        .sub-section-title i { margin-left: 8px; color: #0969da; }

        .items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .items-table th { background: #f8f9fa; padding: 8px 10px; border: 1px solid #e1e4e8; text-align: center; font-weight: 600; font-size: 12px; }
        .items-table td { padding: 6px 8px; border: 1px solid #e1e4e8; vertical-align: middle; }
        .items-table input, .items-table select { width: 100%; padding: 6px 8px; border: 1px solid #e1e4e8; border-radius: 4px; font-size: 13px; font-family: 'Vazirmatn', sans-serif; background: #fff; }
        .items-table input:focus, .items-table select:focus { border-color: #0969da; outline: none; }
        .items-table .btn-remove-row { background: #fecaca; color: #991b1b; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer; font-size: 14px; }
        .items-table .btn-remove-row:hover { background: #f5a0a0; }
        .btn-add-row { background: #d1fae5; color: #065f46; border: none; border-radius: 6px; padding: 6px 16px; cursor: pointer; font-size: 13px; font-weight: 600; font-family: 'Vazirmatn', sans-serif; }
        .btn-add-row:hover { background: #a8e6c1; }

        .date-wrapper { position: relative; display: flex; align-items: center; gap: 0; }
        .date-wrapper input { flex: 1; padding-left: 35px !important; cursor: pointer; }
        .date-wrapper .calendar-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #8b949e; font-size: 16px; cursor: pointer; background: none; border: none; padding: 0; z-index: 10; }
        .date-wrapper .calendar-icon:hover { color: #0969da; }

        .footer { flex-shrink: 0; background: #ffffff; border-top: 1px solid #e1e4e8; padding: 12px 20px; text-align: center; width: 100%; margin-top: auto; }
        .footer-content { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; font-size: 14px; color: #57606a; direction: rtl; }
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
    <div class="sidebar-brand"><div class="brand"><i class="fas fa-heartbeat"></i> سامانه مدیریت پیشگامان</div><div class="sub">پنل مدیریت</div></div>
    <ul class="sidebar-menu">
        <li class="label">منو</li>
        <li><a href="dashboard.php"><i class="fas fa-chart-pie"></i> داشبورد</a></li>
        <li><a href="knowledge.php"><i class="fas fa-database"></i> بانک دانش</a></li>
        <li><a href="links.php"><i class="fas fa-link"></i> بانک لینک</a></li>
        <li><a href="downloads.php"><i class="fas fa-download"></i> مرکز دانلود</a></li>
        <li><a href="assets.php"><i class="fas fa-boxes"></i> بانک اموال</a></li>
        <li><a href="users.php"><i class="fas fa-users"></i> مدیریت کاربران</a></li>
        <li><a href="secretariat.php"><i class="fas fa-inbox"></i> دبیرخانه</a></li>
        <li><a href="reminder.php"><i class="fas fa-bell"></i> یادآور</a></li>
        <li><a href="travel_expenses.php"><i class="fas fa-route"></i> هزینه‌های ماموریت</a></li>
        <li><a href="daily_food_orders.php" class="active"><i class="fas fa-utensils"></i> سفارش اغذیه</a></li>
        <li class="divider"></li>
        <li class="label">سیستم</li>
        <li><a href="settings.php"><i class="fas fa-cog"></i> تنظیمات</a></li>
    </ul>
    <div class="sidebar-footer"><a href="logout.php"><i class="fas fa-sign-out-alt"></i> خروج</a></div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ===== محتوای اصلی ===== -->
<main class="main-content">

    <div class="top-bar-wrapper">
        <div class="top-bar">
            <h1><i class="fas fa-utensils" style="color:#0f9d58; margin-left:10px;"></i> سفارش اغذیه روزانه <span>مدیریت سفارشات غذایی</span></h1>
            <div class="top-right">
                <div class="date"><i class="fas fa-calendar"></i> <?= persian_number_str(nowShamsi('Y/m/d H:i')) ?></div>
                <div class="user-profile">
                    <div class="user-avatar"><?= mb_substr($user['full_name'] ?? $user['national_id'] ?? 'کاربر', 0, 1, 'UTF-8') ?></div>
                    <div><div class="user-name"><?= htmlspecialchars($user['full_name'] ?? $user['national_id'] ?? 'کاربر') ?></div><div class="user-role"><?= htmlspecialchars($currentRole ?? 'بدون نقش') ?></div></div>
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
        <div class="stat-card blue-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-list"></i></span><span class="stat-number"><?= persian_number($stats['total']) ?></span></div><div class="stat-label">کل سفارشات</div></div>
        <div class="stat-card green-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-calendar-day"></i></span><span class="stat-number"><?= persian_number($stats['today']) ?></span></div><div class="stat-label">سفارش امروز</div></div>
        <div class="stat-card purple-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-utensils"></i></span><span class="stat-number"><?= persian_number(count($users)) ?></span></div><div class="stat-label">تعداد کاربران</div></div>
        <div class="stat-card orange-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-project-diagram"></i></span><span class="stat-number"><?= persian_number(count($projects)) ?></span></div><div class="stat-label">پروژه‌ها</div></div>
    </div>

    <!-- ===== فیلترها ===== -->
    <form class="filters-bar" method="GET">
        <div class="filter-group"><input type="text" name="search" placeholder="جستجوی وعده غذایی..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"></div>
        <div class="filter-group">
            <div class="date-wrapper">
                <input type="text" name="date_from" class="persian-date" data-jdp placeholder="از تاریخ" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>" autocomplete="off">
                <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();"><i class="fas fa-calendar-alt"></i></button>
            </div>
            <span style="color:#8b93a5;">تا</span>
            <div class="date-wrapper">
                <input type="text" name="date_to" class="persian-date" data-jdp placeholder="تا تاریخ" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>" autocomplete="off">
                <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();"><i class="fas fa-calendar-alt"></i></button>
            </div>
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> فیلتر</button>
        <a href="daily_food_orders.php" class="btn-reset"><i class="fas fa-undo"></i> پاک کردن</a>
    </form>

    <!-- ===== دکمه ثبت سفارش ===== -->
    <div class="actions-bar">
        <button class="btn-action btn-add-order" id="btnAddOrder">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-plus-circle"></i></div>
                <div style="text-align:right;line-height:1.3;"><div style="font-weight:700;font-size:14px;">ثبت سفارش جدید</div><div style="font-size:10px;opacity:0.8;">ثبت سفارش اغذیه</div></div>
            </div>
        </button>
    </div>

    <!-- ===== جدول ===== -->
    <div class="table-wrapper">
        <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:130px;">تاریخ</th>
                        <th style="width:150px;">وعده غذایی</th>
                        <th style="width:100px;">تعداد آیتم</th>
                        <th style="width:130px;">ثبت‌کننده</th>
                        <th style="width:160px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:40px;color:#8b93a5;">هیچ سفارشی ثبت نشده است.</td></tr>
                    <?php else: $i=1; foreach ($orders as $item): ?>
                        <tr class="<?= 'row-color-' . (($i-1)%8) ?>">
                            <td><?= persian_number($i++) ?></td>
                            <td><?= persian_number_str($item['order_date']) ?></td>
                            <td><?= htmlspecialchars($item['meal_type']) ?></td>
                            <td><?= persian_number($item['item_count']) ?></td>
                            <td><?= htmlspecialchars($item['creator_name'] ?? '-') ?></td>
                            <td>
                                <div class="actions">
                                    <a href="view_food_order.php?id=<?= $item['id'] ?>" target="_blank" class="btn-icon view" title="مشاهده"><i class="fas fa-eye"></i></a>
                                    <button class="btn-icon edit" onclick="editOrder(<?= $item['id'] ?>)" title="ویرایش"><i class="fas fa-edit"></i></button>
                                    <button class="btn-icon delete" onclick="deleteOrder(<?= $item['id'] ?>)" title="حذف"><i class="fas fa-trash-alt"></i></button>
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
        <span class="footer-version">نسخه 1.0 - سفارش اغذیه</span>
    </div>
</div>

<!-- ============================================= -->
<!-- ===== مودال ثبت/ویرایش سفارش ===== -->
<!-- ============================================= -->
<div class="modal-overlay" id="orderModal">
    <div class="modal-box">
        <h2 id="orderModalTitle"><i class="fas fa-plus-circle" style="color:#0f9d58;"></i> ثبت سفارش جدید</h2>
        <form method="POST" id="orderForm">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" id="order_action" value="add">
            <input type="hidden" name="order_id" id="order_id" value="0">

            <!-- ===== اطلاعات اصلی ===== -->
            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ سفارش <span style="color:red;">*</span></label>
                    <div class="date-wrapper">
                        <input type="text" name="order_date" id="order_date" class="persian-date" data-jdp value="<?= jdate('Y/m/d') ?>" autocomplete="off">
                        <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();"><i class="fas fa-calendar-alt"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label>وعده غذایی <span style="color:red;">*</span></label>
                    <select name="meal_type" id="meal_type" required>
                        <option value="">انتخاب کنید</option>
                        <option value="صبحانه">صبحانه</option>
                        <option value="ناهار">ناهار</option>
                        <option value="شام">شام</option>
                        <option value="نوشیدنی">نوشیدنی</option>
                    </select>
                </div>
            </div>

            <!-- ===== آیتم‌های اضافی ===== -->
            <div class="sub-section">
                <div class="sub-section-title"><i class="fas fa-list"></i> لیست همکاران</div>
                <div style="overflow-x:auto;">
                    <table class="items-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="width:5%;">#</th>
                                <th style="width:25%;">نام همکار</th>
                                <th style="width:30%;">نام غذا</th>
                                <th style="width:25%;">پروژه</th>
                                <th style="width:15%;">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <!-- توسط JS اضافه می‌شود -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" style="text-align:center;padding:8px;">
                                    <button type="button" class="btn-add-row" onclick="addItemRow()"><i class="fas fa-plus-circle"></i> افزودن همکار</button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('orderModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره سفارش</button>
            </div>
        </form>
    </div>
</div>

<button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>

<script>
var rowCount = 0;

// ===== فرمت عدد =====
function formatNumberInput(input) {
    var value = input.value.replace(/,/g, '');
    if (value === '') return;
    var num = parseInt(value);
    if (!isNaN(num)) {
        input.value = num.toLocaleString('en');
    }
}

// ===== مدیریت آیتم‌ها =====
function addItemRow(data) {
    rowCount++;
    var tbody = document.getElementById('itemsBody');
    var tr = document.createElement('tr');
    tr.id = 'item_row_' + rowCount;
    tr.innerHTML = `
        <td style="text-align:center;font-weight:700;">${rowCount}</td>
        <td>
            <select name="colleague_name[]" style="width:100%;padding:6px 8px;border:1px solid #e1e4e8;border-radius:4px;font-size:13px;font-family:'Vazirmatn',sans-serif;background:#fff;">
                <option value="">انتخاب کنید</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= htmlspecialchars($u['full_name']) ?>" ${data && data.colleague_name === '<?= htmlspecialchars($u['full_name']) ?>' ? 'selected' : ''}> <?= htmlspecialchars($u['full_name']) ?> </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" name="food_name[]" placeholder="نام غذا (مثلاً جوجه کباب)" value="${data ? data.food_name : ''}" style="width:100%;padding:6px 8px;border:1px solid #e1e4e8;border-radius:4px;font-size:13px;font-family:'Vazirmatn',sans-serif;background:#fff;"></td>
        <td>
            <select name="project_id[]" style="width:100%;padding:6px 8px;border:1px solid #e1e4e8;border-radius:4px;font-size:13px;font-family:'Vazirmatn',sans-serif;background:#fff;">
                <option value="">بدون پروژه</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= $p['id'] ?>" ${data && data.project_id == '<?= $p['id'] ?>' ? 'selected' : ''}> <?= htmlspecialchars($p['name']) ?> </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td style="text-align:center;"><button type="button" class="btn-remove-row" onclick="removeItemRow('item_row_' + rowCount)"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
}

function removeItemRow(rowId) {
    var row = document.getElementById(rowId);
    if (row) {
        var tbody = document.getElementById('itemsBody');
        if (tbody.children.length > 0) {
            row.remove();
            // بازنویسی شماره ردیف‌ها
            var rows = tbody.querySelectorAll('tr');
            rows.forEach(function(r, idx) {
                r.querySelector('td:first-child').textContent = idx + 1;
            });
        }
    }
}

function clearItems() {
    var tbody = document.getElementById('itemsBody');
    tbody.innerHTML = '';
    rowCount = 0;
    // اضافه کردن یک ردیف پیش‌فرض
    addItemRow();
}

// ===== مودال‌ها =====
function openAddModal() {
    document.getElementById('orderModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color:#0f9d58;"></i> ثبت سفارش جدید';
    document.getElementById('order_action').value = 'add';
    document.getElementById('order_id').value = 0;
    document.getElementById('order_date').value = '<?= jdate('Y/m/d') ?>';
    document.getElementById('meal_type').value = '';
    clearItems();
    document.getElementById('orderModal').classList.add('active');
}

function editOrder(id) {
    fetch('get_food_order.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('orderModalTitle').innerHTML = '<i class="fas fa-edit" style="color:#0969da;"></i> ویرایش سفارش';
                document.getElementById('order_action').value = 'edit';
                document.getElementById('order_id').value = id;
                document.getElementById('order_date').value = data.order_date || '';
                document.getElementById('meal_type').value = data.meal_type || '';
                
                clearItems();
                var tbody = document.getElementById('itemsBody');
                tbody.innerHTML = '';
                rowCount = 0;
                if (data.items && data.items.length > 0) {
                    data.items.forEach(function(item) {
                        addItemRow({
                            colleague_name: item.colleague_name,
                            food_name: item.food_name,
                            project_id: item.project_id
                        });
                    });
                } else {
                    addItemRow();
                }
                document.getElementById('orderModal').classList.add('active');
            }
        })
        .catch(error => console.error('خطا:', error));
}

function deleteOrder(id) {
    if (confirm('آیا از حذف این سفارش مطمئن هستید؟')) {
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
        var orderId = document.createElement('input');
        orderId.type = 'hidden';
        orderId.name = 'order_id';
        orderId.value = id;
        form.appendChild(csrf);
        form.appendChild(action);
        form.appendChild(orderId);
        document.body.appendChild(form);
        form.submit();
    }
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// ===== رویدادها =====
document.addEventListener('DOMContentLoaded', function() {
    // ===== تاریخ شمسی =====
    if (typeof jalaliDatepicker !== 'undefined') {
        jalaliDatepicker.startWatch({
            minDate: 'attr',
            maxDate: 'attr',
            persianDigit: true,
            autoHide: true,
            zIndex: 3000
        });
    }
    
    // ===== دکمه ثبت سفارش =====
    document.getElementById('btnAddOrder')?.addEventListener('click', openAddModal);
    
    // ===== منوی موبایل =====
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
    
    // ===== بستن مودال با کلیک روی پس‌زمینه =====
    document.querySelectorAll('.modal-overlay').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('active');
        });
    });
    
    // ===== افزودن یک ردیف خالی =====
    clearItems();
    
    // ===== محو کردن پیام موفقیت بعد از ۳ ثانیه =====
    var successMsg = document.getElementById('successMessage');
    if (successMsg) {
        setTimeout(function() {
            successMsg.style.transition = 'opacity 0.5s';
            successMsg.style.opacity = '0';
            setTimeout(function() {
                successMsg.style.display = 'none';
            }, 500);
        }, 3000);
    }
    
    // ===== محو کردن پیام خطا بعد از ۵ ثانیه =====
    var errorMsg = document.getElementById('errorMessage');
    if (errorMsg) {
        setTimeout(function() {
            errorMsg.style.transition = 'opacity 0.5s';
            errorMsg.style.opacity = '0';
            setTimeout(function() {
                errorMsg.style.display = 'none';
            }, 500);
        }, 5000);
    }
});
</script>

</body>
</html>