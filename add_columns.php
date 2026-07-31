تمام این کدهایی که میبینید در پوشه روت هستش به این آدرس:

Y:\htdocs\company_kb
======================================
<?php
// ===== فایل: add_columns.php =====
require_once 'config.php';

try {
    // ===== بررسی و اضافه کردن ستون mobile =====
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN mobile VARCHAR(20) NULL AFTER email");
        echo "✅ ستون mobile اضافه شد.<br>";
    } catch(PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "ℹ️ ستون mobile قبلاً وجود دارد.<br>";
        } else {
            throw $e;
        }
    }
    
    // ===== بررسی و اضافه کردن ستون telegram_id =====
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN telegram_id VARCHAR(50) NULL AFTER mobile");
        echo "✅ ستون telegram_id اضافه شد.<br>";
    } catch(PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "ℹ️ ستون telegram_id قبلاً وجود دارد.<br>";
        } else {
            throw $e;
        }
    }
    
    // ===== ایجاد جدول reminder_groups (اگر وجود نداشت) =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reminder_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `description` text,
            `members` text,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول reminder_groups ایجاد/بروزرسانی شد.<br>";
    
    // ===== ایجاد جدول user_groups (اگر وجود نداشت) =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `description` text,
            `members` text,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول user_groups ایجاد شد.<br>";
    
    echo "<br><strong>✅ همه تغییرات با موفقیت اعمال شدند!</strong>";
    echo "<br><a href='reminder.php' style='padding:10px 20px;background:#0969da;color:#fff;text-decoration:none;border-radius:6px;'>👉 رفتن به صفحه یادآور</a>";
    
} catch(PDOException $e) {
    echo "❌ خطا: " . $e->getMessage();
}
?>




================assets.php=============
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

=====================config.php==============
<?php
// تنظیمات دیتابیس
define('DB_HOST', 'localhost');
define('DB_NAME', 'company_kb');
define('DB_USER', 'tabarsa');
define('DB_PASS', 'tabarsa');

// اتصال به دیتابیس با PDO
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("خطا در اتصال به دیتابیس: " . $e->getMessage());
}

// شروع سشن
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// تنظیمات منطقه زمانی به تهران
date_default_timezone_set('Asia/Tehran');

// کلید رمزنگاری (برای رمز کردن پسوردها)
define('ENCRYPTION_KEY', 'your-secret-key-here-change-it');
?>

==============cron_reminder.php===================
<?php
/**
 * کرون جاب با استفاده از تابع nowShamsi()
 * مسیر: cron_reminder.php
 */

// نمایش خطاها
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ایجاد پوشه لاگ
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0777, true);
}

function writeLog($msg) {
    $logFile = __DIR__ . '/logs/cron.log';
    $date = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$date] $msg\n", FILE_APPEND);
}

writeLog("========== شروع اجرای کرون ==========");

try {
    require_once 'config.php';
    writeLog("config.php بارگذاری شد.");
    
    require_once 'functions.php';
    writeLog("functions.php بارگذاری شد.");
    
    require_once 'includes/jdf.php';
    writeLog("jdf.php بارگذاری شد.");
    
    require_once 'includes/ReminderService.php';
    writeLog("ReminderService.php بارگذاری شد.");
    
} catch (Exception $e) {
    writeLog("خطا در بارگذاری فایل‌ها: " . $e->getMessage());
    die("خطا: " . $e->getMessage());
}

if (!isset($pdo) || !$pdo) {
    writeLog("خطا: اتصال به دیتابیس برقرار نیست.");
    die("خطا: اتصال به دیتابیس برقرار نیست.");
}
writeLog("اتصال به دیتابیس برقرار است.");

try {
    $reminderService = new ReminderService($pdo);
    writeLog("ReminderService ساخته شد.");
} catch (Exception $e) {
    writeLog("خطا در ساخت ReminderService: " . $e->getMessage());
    die("خطا: " . $e->getMessage());
}

// ===== بخش مهم: محاسبه زمان با تابع nowShamsi() =====
try {
    // زمان فعلی
    $now = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $currentTime = $now->format('H:i:00');
    $today = $now->format('Y-m-d');
    
    // تاریخ شمسی با تابع nowShamsi() که در reminder.php استفاده شده
    $shamsiToday = nowShamsi('Y/m/d');
    
    writeLog("زمان فعلی: $currentTime");
    writeLog("تاریخ میلادی: $today");
    writeLog("تاریخ شمسی (nowShamsi): $shamsiToday");
    
    // همچنین تاریخ شمسی با jdate() را هم برای مقایسه لاگ می‌کنیم
    $jdateToday = jdate('Y/m/d');
    writeLog("تاریخ شمسی (jdate): $jdateToday");
    
} catch (Exception $e) {
    writeLog("خطا در محاسبه زمان: " . $e->getMessage());
    die("خطا: " . $e->getMessage());
}

// ===== جستجوی یادآورها با هر دو تاریخ =====
try {
    // کوئری با هر دو حالت (برای اطمینان)
    $query = "
        SELECT id, title, reminder_time, shamsi_reminder_date, reminder_date
        FROM reminders
        WHERE status = 'active'
          AND sent_at IS NULL
          AND canceled_at IS NULL
          AND (
              reminder_date = :today
              OR shamsi_reminder_date = :shamsi_today
              OR shamsi_reminder_date = :jdate_today
          )
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':today' => $today,
        ':shamsi_today' => $shamsiToday,
        ':jdate_today' => $jdateToday
    ]);
    
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    writeLog("تعداد یادآورهای یافت شده (بر اساس تاریخ): " . count($reminders));
    
    // اگر یادآوری پیدا شد، جزئیات را لاگ کن
    foreach ($reminders as $r) {
        writeLog("  - یادآور ID: {$r['id']}, عنوان: {$r['title']}, تاریخ شمسی: {$r['shamsi_reminder_date']}, زمان: {$r['reminder_time']}");
    }
    
    // حالا فیلتر بر اساس زمان
    $readyReminders = [];
    foreach ($reminders as $reminder) {
        if ($reminder['reminder_time'] <= $currentTime) {
            $readyReminders[] = $reminder;
        }
    }
    
    writeLog("تعداد یادآورهای آماده ارسال (بر اساس زمان): " . count($readyReminders));
    
    // ارسال هر یادآور
    foreach ($readyReminders as $reminder) {
        writeLog("شروع ارسال یادآور ID: {$reminder['id']} - عنوان: {$reminder['title']}");
        try {
            $result = $reminderService->sendReminder($reminder['id']);
            if ($result['success']) {
                writeLog("✓ یادآور {$reminder['id']} با موفقیت ارسال شد.");
                $reminderService->updateNextReminderDate($reminder['id']);
            } else {
                writeLog("✗ خطا در ارسال یادآور {$reminder['id']}: " . ($result['error'] ?? 'خطای ناشناخته'));
            }
        } catch (Exception $e) {
            writeLog("✗ خطا در ارسال یادآور {$reminder['id']}: " . $e->getMessage());
        }
    }
    
} catch (Exception $e) {
    writeLog("خطا در اجرای کوئری: " . $e->getMessage());
    die("خطا: " . $e->getMessage());
}

// منقضی کردن یادآورهای تکراری
try {
    $updateExpired = $pdo->prepare("
        UPDATE reminders 
        SET status = 'expired' 
        WHERE status = 'active' 
          AND repeat_type != 'none' 
          AND repeat_until IS NOT NULL 
          AND repeat_until < CURDATE()
    ");
    $updateExpired->execute();
    writeLog("تعداد یادآورهای منقضی شده: " . $updateExpired->rowCount());
} catch (Exception $e) {
    writeLog("خطا در به‌روزرسانی یادآورهای منقضی: " . $e->getMessage());
}

writeLog("========== پایان اجرای کرون ==========");
echo "کرون با موفقیت اجرا شد. لاگ را بررسی کنید.";

==============cron_runner.php================
<?php
// این فایل را در خط فرمان ویندوز اجرا کنید: php cron_runner.php
while (true) {
    include 'cron_reminder.php';
    sleep(60); // هر ۶۰ ثانیه یک بار اجرا می‌شود
}

================daily_food_orders.php==============
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

================dashboard.php===========
<?php
// ==========================================================
// تنظیمات اولیه و دریافت داده‌های واقعی از دیتابیس
// ==========================================================

// دریافت اطلاعات کاربر جاری
$user = $_SESSION['user'] ?? [
    'id' => 1,
    'full_name' => 'مدیر سیستم',
    'national_code' => '2122767111',
    'role' => 'مدیر ارشد'
];

// ===== تابع تبدیل اعداد به فارسی =====
function persianNumber($number) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english, $persian, (string)$number);
}

// ===== گرفتن آمار واقعی از دیتابیس =====
$db = Database::getInstance();

// آمار بانک دانش
$knowledgeStats = $db->execute("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM knowledge_entries
")->fetch();

// آمار بانک لینک
$linkStats = $db->execute("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
    FROM link_bank_entries
")->fetch();

// آمار مرکز دانلود
$downloadStats = $db->execute("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
    FROM download_items
")->fetch();

// آمار اموال
$assetStats = $db->execute("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned
    FROM asset_stock
")->fetch();

// آمار دبیرخانه
$secretariatStats = $db->execute("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN type = 'incoming' THEN 1 ELSE 0 END) as incoming,
        SUM(CASE WHEN type = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM correspondences
")->fetch();

// آمار یادآور
$reminderStats = $db->execute("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN DATE(remind_date) = CURDATE() THEN 1 ELSE 0 END) as today
    FROM reminders
")->fetch();

// ===== آخرین فعالیت‌ها =====
$logs = $db->execute("
    SELECT 
        al.*,
        u.full_name as user_name,
        u.national_code as username
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT 10
")->fetchAll();

// ===== توابع کمکی برای نمایش =====
function getActivityColor($module) {
    $colors = [
        'knowledge' => '#2da44e',
        'links' => '#8250df',
        'downloads' => '#0969da',
        'assets' => '#cf222e',
        'secretariat' => '#e91e63',
        'reminder' => '#ff9800',
        'users' => '#d4a72c',
        'auth' => '#57606a',
        'default' => '#8b949e'
    ];
    return $colors[$module] ?? $colors['default'];
}

function getActivityMessage($log) {
    $userName = $log['user_name'] ?? $log['username'] ?? 'کاربر';
    $actionLabels = [
        'create' => 'ایجاد کرد', 'edit' => 'ویرایش کرد', 'delete' => 'حذف کرد',
        'approve' => 'تأیید کرد', 'reject' => 'رد کرد', 'publish' => 'منتشر کرد',
        'assign' => 'واگذار کرد', 'return' => 'بازگرداند', 'login' => 'وارد شد',
        'logout' => 'خروج کرد', 'download' => 'دانلود کرد', 'view' => 'مشاهده کرد',
        'update' => 'به‌روزرسانی کرد', 'send' => 'ارسال کرد', 'receive' => 'دریافت کرد'
    ];
    $moduleLabels = [
        'knowledge' => 'مقاله', 'links' => 'لینک', 'downloads' => 'فایل',
        'assets' => 'اموال', 'secretariat' => 'نامه', 'reminder' => 'یادآور',
        'users' => 'کاربر', 'auth' => 'سیستم'
    ];
    $action = $actionLabels[$log['action']] ?? $log['action'];
    $module = $moduleLabels[$log['module']] ?? $log['module'];
    $desc = trim($log['description'] ?? '');
    if ($desc) {
        return "{$userName} {$module} «{$desc}» را {$action}";
    }
    return "{$userName} یک {$module} را {$action}";
}

function getSmartTime($timestamp) {
    $now = new DateTime();
    $time = new DateTime($timestamp);
    $diff = $now->diff($time);
    if ($diff->days == 0) {
        if ($diff->h == 0 && $diff->i < 3) return 'لحظاتی پیش';
        if ($diff->h == 0) return $diff->i . ' دقیقه پیش';
        if ($diff->h == 1) return 'یک ساعت پیش';
        if ($diff->h < 6) return $diff->h . ' ساعت پیش';
        return 'امروز ' . $time->format('H:i');
    } elseif ($diff->days == 1) {
        return 'دیروز ' . $time->format('H:i');
    } else {
        return $time->format('Y/m/d H:i');
    }
}

// تاریخ شمسی
$today = jdate('Y/m/d H:i');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد مدیریت | SIMAK</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================
           RESET & BASE
           ========================================================== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #f6f8fa;
            color: #24292f;
            display: flex;
            min-height: 100vh;
            direction: rtl;
        }
        a { text-decoration: none; }

        /* ==========================================================
           SIDEBAR
           ========================================================== */
        .sidebar {
            width: 260px;
            background: #ffffff;
            border-left: 1px solid #e1e4e8;
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow-y: auto;
            flex-shrink: 0;
            z-index: 100;
            transition: all 0.3s ease;
        }

        .sidebar-brand {
            padding: 0 8px 20px;
            border-bottom: 1px solid #e1e4e8;
            margin-bottom: 20px;
        }
        .sidebar-brand .brand {
            font-size: 20px;
            font-weight: 800;
            color: #24292f;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-brand .brand i {
            color: #0969da;
            font-size: 24px;
        }
        .sidebar-brand .sub {
            font-size: 12px;
            color: #57606a;
            font-weight: 400;
            margin-top: 2px;
            padding-right: 36px;
        }

        .sidebar-menu {
            list-style: none;
            flex: 1;
        }
        .sidebar-menu .label {
            font-size: 11px;
            font-weight: 600;
            color: #8b949e;
            padding: 12px 12px 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sidebar-menu li a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            color: #57606a;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.15s ease;
            margin-bottom: 2px;
        }
        .sidebar-menu li a i {
            width: 18px;
            font-size: 15px;
            color: #8b949e;
        }
        .sidebar-menu li a:hover {
            background: #f0f2f4;
            color: #24292f;
        }
        .sidebar-menu li a.active {
            background: #f0f2f4;
            color: #0969da;
        }
        .sidebar-menu li a.active i {
            color: #0969da;
        }
        .sidebar-menu .divider {
            height: 1px;
            background: #e1e4e8;
            margin: 12px 8px;
        }

        .sidebar-footer {
            border-top: 1px solid #e1e4e8;
            padding-top: 16px;
        }
        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            color: #57606a;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.15s ease;
        }
        .sidebar-footer a:hover {
            background: #f0f2f4;
            color: #24292f;
        }

        /* ==========================================================
           MAIN CONTENT
           ========================================================== */
        .main-content {
            flex: 1;
            padding: 28px 36px 20px;
            overflow-y: auto;
        }

        /* ===== TOP BAR ===== */
        .top-bar {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            padding: 12px 24px;
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .top-bar h1 {
            font-size: 20px;
            font-weight: 700;
            color: #24292f;
        }
        .top-bar h1 span {
            font-weight: 400;
            font-size: 13px;
            color: #57606a;
            margin-right: 8px;
        }
        .top-right {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .top-right .date {
            font-size: 13px;
            color: #57606a;
            background: #f6f8fa;
            padding: 6px 14px;
            border-radius: 6px;
            border: 1px solid #e1e4e8;
            white-space: nowrap;
        }
        .top-right .date i {
            margin-left: 6px;
            color: #8b949e;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            padding: 4px 12px 4px 16px;
            border-radius: 6px;
            border: 1px solid #e1e4e8;
        }
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #0969da;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 13px;
        }
        .user-name {
            font-size: 13px;
            font-weight: 600;
            color: #24292f;
        }
        .user-role {
            font-size: 11px;
            color: #57606a;
        }
        .role-switcher {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .role-switcher select {
            padding: 2px 8px;
            border: 1px solid #e1e4e8;
            border-radius: 4px;
            font-size: 11px;
            font-family: 'Vazirmatn', sans-serif;
            background: #fff;
            color: #24292f;
            outline: none;
        }
        .role-switcher .btn-switch {
            padding: 2px 10px;
            background: #0969da;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            transition: background 0.15s;
        }
        .role-switcher .btn-switch:hover {
            background: #0550b3;
        }

        /* ===== STATS GRID ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px;
            margin-bottom: 36px;
        }

        .stat-card {
            padding: 16px 16px 14px;
            border-radius: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
            display: block;
            border: 1px solid transparent;
        }
        .stat-card .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }
        .stat-card .stat-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            background: #ffffff;
            border: 1px solid;
        }
        .stat-card .stat-number {
            font-size: 20px;
            font-weight: 800;
            color: #1a1a2e;
            letter-spacing: -0.3px;
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a2e;
            margin-top: 2px;
        }
        .stat-card .stat-details {
            font-size: 11px;
            color: #3d3d5c;
            margin-top: 8px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .stat-card .stat-details span {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #ffffff;
            padding: 1px 10px;
            border-radius: 20px;
            border: 1px solid #e1e4e8;
            font-size: 10px;
        }

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

        .stat-card.pink-light { background: #fce4ec; border-color: #f8bbd0; }
        .stat-card.pink-light .stat-icon { border-color: #f8bbd0; color: #e91e63; }
        .stat-card.pink-light:hover { box-shadow: 0 8px 30px rgba(233,30,99,0.20); transform: translateY(-3px); border-color: #e91e63; }

        .stat-card.orange-light { background: #fff3e0; border-color: #ffcc80; }
        .stat-card.orange-light .stat-icon { border-color: #ffcc80; color: #ff9800; }
        .stat-card.orange-light:hover { box-shadow: 0 8px 30px rgba(255,152,0,0.20); transform: translateY(-3px); border-color: #ff9800; }

        /* ===== DASHBOARD GRID ===== */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #e1e4e8;
            overflow: hidden;
        }

        .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e1e4e8;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }
        .card-header .title {
            font-size: 14px;
            font-weight: 600;
            color: #24292f;
        }
        .card-header .title i {
            margin-left: 8px;
            color: #8b949e;
        }
        .card-header .badge {
            font-size: 12px;
            color: #57606a;
            background: rgba(255,255,255,0.7);
            padding: 2px 10px;
            border-radius: 12px;
        }

        /* ===== ACTIVITY TIMELINE ===== */
        .activity-timeline {
            padding: 0;
        }
        .activity-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 11px 20px;
            border-bottom: 1px solid #f0f2f4;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        .activity-item:hover {
            background: #f8f9fa;
        }
        .activity-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
            box-shadow: 0 0 0 2px rgba(255,255,255,0.6);
        }
        .activity-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1px;
        }
        .activity-message {
            font-size: 14px;
            color: #24292f;
            line-height: 1.6;
        }
        .activity-time {
            font-size: 12px;
            color: #8b949e;
            white-space: nowrap;
        }
        .activity-time i {
            margin-left: 4px;
            font-size: 11px;
        }

        /* ===== QUICK STATS ===== */
        .quick-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid #f0f2f4;
        }
        .quick-item:last-child { border-bottom: none; }
        .quick-item .ql {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .quick-item .ql i {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 14px;
        }
        .quick-item .ql span {
            font-size: 13px;
            color: #24292f;
        }
        .quick-item .qv {
            font-size: 15px;
            font-weight: 700;
        }

        .quick-item.green { background: #ecfdf3; }
        .quick-item.green .ql i { background: #dafbe1; color: #2da44e; }
        .quick-item.green .qv { color: #2da44e; }

        .quick-item.yellow { background: #fffbeb; }
        .quick-item.yellow .ql i { background: #fff8c5; color: #d4a72c; }
        .quick-item.yellow .qv { color: #d4a72c; }

        .quick-item.purple { background: #f5f0ff; }
        .quick-item.purple .ql i { background: #f1e8ff; color: #8250df; }
        .quick-item.purple .qv { color: #8250df; }

        .quick-item.blue { background: #eff6ff; }
        .quick-item.blue .ql i { background: #ddf4ff; color: #0969da; }
        .quick-item.blue .qv { color: #0969da; }

        .quick-item.red { background: #fef2f2; }
        .quick-item.red .ql i { background: #ffe3e6; color: #cf222e; }
        .quick-item.red .qv { color: #cf222e; }

        .quick-item.pink { background: #fce4ec; }
        .quick-item.pink .ql i { background: #f8bbd0; color: #e91e63; }
        .quick-item.pink .qv { color: #e91e63; }

        .quick-item.orange { background: #fff3e0; }
        .quick-item.orange .ql i { background: #ffcc80; color: #ff9800; }
        .quick-item.orange .qv { color: #ff9800; }

        /* ===== FOOTER ===== */
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e1e4e8;
            text-align: center;
            font-size: 14px;
            color: #57606a;
        }
        .footer a {
            color: #0969da;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .footer a:hover {
            color: #0550b3;
            text-decoration: underline;
        }
        .footer .divider {
            color: #d0d7de;
            margin: 0 8px;
        }
        .footer .version {
            font-size: 12px;
            color: #8b949e;
            background: #f6f8fa;
            padding: 2px 12px;
            border-radius: 12px;
        }

        /* ==========================================================
           RESPONSIVE
           ========================================================== */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 992px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .sidebar {
                position: fixed;
                right: -280px;
                width: 280px;
                height: 100vh;
                top: 0;
                box-shadow: 0 0 40px rgba(0,0,0,0.15);
            }
            .sidebar.open { right: 0; }
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.3);
                z-index: 99;
            }
            .sidebar-overlay.active { display: block; }
            .mobile-toggle {
                display: flex !important;
                position: fixed;
                top: 16px;
                right: 16px;
                z-index: 101;
                background: #fff;
                border: 1px solid #e1e4e8;
                border-radius: 6px;
                padding: 10px 12px;
                font-size: 18px;
                color: #24292f;
                cursor: pointer;
            }
            .main-content { padding: 20px 16px; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-right { flex-wrap: wrap; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .activity-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }
            .activity-time { align-self: flex-start; }
        }

        .mobile-toggle { display: none; }
    </style>
</head>
<body>

<!-- ==========================================================
   SIDEBAR OVERLAY (برای موبایل)
   ========================================================== -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ==========================================================
   SIDEBAR
   ========================================================== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand">
            <i class="fas fa-cubes"></i>
            SIMAK
        </div>
        <div class="sub">سامانه یکپارچه مدیریت انجام کار</div>
    </div>

    <ul class="sidebar-menu">
        <li class="label">منوی اصلی</li>
        <li><a href="#" class="active"><i class="fas fa-chart-pie"></i> داشبورد</a></li>
        <li><a href="#"><i class="fas fa-inbox"></i> دبیرخانه</a></li>
        <li><a href="#"><i class="fas fa-bell"></i> یادآور</a></li>
        <li><a href="#"><i class="fas fa-route"></i> هزینه مأموریت</a></li>
        <li><a href="#"><i class="fas fa-boxes"></i> مدیریت اموال</a></li>

        <li class="divider"></li>
        <li class="label">داده و دانش</li>
        <li><a href="#"><i class="fas fa-database"></i> پایگاه دانش</a></li>
        <li><a href="#"><i class="fas fa-link"></i> بانک لینک</a></li>
        <li><a href="#"><i class="fas fa-download"></i> مرکز دانلود</a></li>

        <li class="divider"></li>
        <li class="label">مدیریت</li>
        <li><a href="#"><i class="fas fa-project-diagram"></i> طرح‌ها و پروژه‌ها</a></li>
        <li><a href="#"><i class="fas fa-users"></i> درخواست همکاری</a></li>
        <li><a href="#"><i class="fas fa-user-cog"></i> مدیریت کاربران</a></li>
    </ul>

    <div class="sidebar-footer">
        <a href="/simak/logout"><i class="fas fa-sign-out-alt"></i> خروج از سامانه</a>
    </div>
</aside>

<!-- ==========================================================
   MAIN CONTENT
   ========================================================== -->
<main class="main-content">

    <!-- ===== TOP BAR ===== -->
    <div class="top-bar">
        <h1>
            داشبورد
            <span>مدیریت سامانه</span>
        </h1>
        <div class="top-right">
            <div class="date">
                <i class="fas fa-calendar-alt"></i> <?= persianNumber($today) ?>
            </div>
            <div class="user-profile">
                <div class="user-avatar">
                    <?= mb_substr($user['full_name'] ?? 'کاربر', 0, 1, 'UTF-8') ?>
                </div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? 'کاربر') ?></div>
                    <div class="user-role"><?= htmlspecialchars($user['role'] ?? 'مدیر ارشد') ?></div>
                </div>
                <!-- اگر چند نقش داشت، اینجا انتخابگر نقش می‌آید -->
            </div>
        </div>
    </div>

    <!-- ===== STATS GRID (۶ کارت آماری) ===== -->
    <div class="stats-grid">
        <!-- ۱. بانک دانش -->
        <a href="#" class="stat-card green-light">
            <div class="stat-top">
                <span class="stat-icon"><i class="fas fa-database"></i></span>
                <span class="stat-number"><?= persianNumber($knowledgeStats['total'] ?? 0) ?></span>
            </div>
            <div class="stat-label">پایگاه دانش</div>
            <div class="stat-details">
                <span>تایید <?= persianNumber($knowledgeStats['approved'] ?? 0) ?></span>
                <span>در انتظار <?= persianNumber($knowledgeStats['pending'] ?? 0) ?></span>
            </div>
        </a>

        <!-- ۲. بانک لینک -->
        <a href="#" class="stat-card purple-light">
            <div class="stat-top">
                <span class="stat-icon"><i class="fas fa-link"></i></span>
                <span class="stat-number"><?= persianNumber($linkStats['total'] ?? 0) ?></span>
            </div>
            <div class="stat-label">بانک لینک</div>
            <div class="stat-details">
                <span>فعال <?= persianNumber($linkStats['active'] ?? 0) ?></span>
            </div>
        </a>

        <!-- ۳. مرکز دانلود -->
        <a href="#" class="stat-card blue-light">
            <div class="stat-top">
                <span class="stat-icon"><i class="fas fa-download"></i></span>
                <span class="stat-number"><?= persianNumber($downloadStats['total'] ?? 0) ?></span>
            </div>
            <div class="stat-label">مرکز دانلود</div>
            <div class="stat-details">
                <span>دانلود امروز <?= persianNumber($downloadStats['today'] ?? 0) ?></span>
            </div>
        </a>

        <!-- ۴. مدیریت اموال -->
        <a href="#" class="stat-card red-light">
            <div class="stat-top">
                <span class="stat-icon"><i class="fas fa-boxes"></i></span>
                <span class="stat-number"><?= persianNumber($assetStats['total'] ?? 0) ?></span>
            </div>
            <div class="stat-label">مدیریت اموال</div>
            <div class="stat-details">
                <span>واگذار <?= persianNumber($assetStats['assigned'] ?? 0) ?></span>
            </div>
        </a>

        <!-- ۵. دبیرخانه -->
        <a href="#" class="stat-card pink-light">
            <div class="stat-top">
                <span class="stat-icon"><i class="fas fa-inbox"></i></span>
                <span class="stat-number"><?= persianNumber($secretariatStats['total'] ?? 0) ?></span>
            </div>
            <div class="stat-label">دبیرخانه</div>
            <div class="stat-details">
                <span>ورودی <?= persianNumber($secretariatStats['incoming'] ?? 0) ?></span>
                <span>خروجی <?= persianNumber($secretariatStats['outgoing'] ?? 0) ?></span>
                <span>در انتظار <?= persianNumber($secretariatStats['pending'] ?? 0) ?></span>
            </div>
        </a>

        <!-- ۶. یادآور -->
        <a href="#" class="stat-card orange-light">
            <div class="stat-top">
                <span class="stat-icon"><i class="fas fa-bell"></i></span>
                <span class="stat-number"><?= persianNumber($reminderStats['total'] ?? 0) ?></span>
            </div>
            <div class="stat-label">یادآور</div>
            <div class="stat-details">
                <span>فعال <?= persianNumber($reminderStats['active'] ?? 0) ?></span>
                <span>امروز <?= persianNumber($reminderStats['today'] ?? 0) ?></span>
            </div>
        </a>
    </div>

    <!-- ===== GRID: فعالیت‌ها + آمار سریع ===== -->
    <div class="dashboard-grid">

        <!-- ===== فعالیت‌های اخیر ===== -->
        <div class="card">
            <div class="card-header">
                <span class="title"><i class="fas fa-history"></i> آخرین فعالیت‌ها</span>
                <span class="badge"><?= count($logs) ?> مورد</span>
            </div>
            <div class="activity-timeline">
                <?php if (empty($logs)): ?>
                    <div class="activity-item" style="justify-content:center; color:#8b949e;">
                        هیچ فعالیتی ثبت نشده است.
                    </div>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        $color = getActivityColor($log['module'] ?? 'default');
                        $message = getActivityMessage($log);
                        $time = getSmartTime($log['created_at']);
                    ?>
                    <div class="activity-item">
                        <div class="activity-dot" style="background-color: <?= $color ?>;"></div>
                        <div class="activity-content">
                            <div class="activity-message"><?= $message ?></div>
                        </div>
                        <div class="activity-time">
                            <i class="fas fa-clock"></i> <?= persianNumber($time) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== آمار سریع ===== -->
        <div class="card">
            <div class="card-header">
                <span class="title"><i class="fas fa-chart-simple"></i> آمار سریع</span>
                <span class="badge">لحظه‌ای</span>
            </div>
            <div>
                <div class="quick-item green">
                    <div class="ql"><i class="fas fa-check-circle"></i><span>مقالات تأیید شده</span></div>
                    <div class="qv"><?= persianNumber($knowledgeStats['approved'] ?? 0) ?></div>
                </div>
                <div class="quick-item yellow">
                    <div class="ql"><i class="fas fa-hourglass-half"></i><span>در انتظار تأیید</span></div>
                    <div class="qv"><?= persianNumber($knowledgeStats['pending'] ?? 0) ?></div>
                </div>
                <div class="quick-item purple">
                    <div class="ql"><i class="fas fa-link"></i><span>لینک‌های فعال</span></div>
                    <div class="qv"><?= persianNumber($linkStats['active'] ?? 0) ?></div>
                </div>
                <div class="quick-item blue">
                    <div class="ql"><i class="fas fa-download"></i><span>دانلود امروز</span></div>
                    <div class="qv"><?= persianNumber($downloadStats['today'] ?? 0) ?></div>
                </div>
                <div class="quick-item red">
                    <div class="ql"><i class="fas fa-box"></i><span>اموال واگذار شده</span></div>
                    <div class="qv"><?= persianNumber($assetStats['assigned'] ?? 0) ?></div>
                </div>
                <div class="quick-item pink">
                    <div class="ql"><i class="fas fa-inbox"></i><span>نامه‌های دبیرخانه</span></div>
                    <div class="qv"><?= persianNumber($secretariatStats['total'] ?? 0) ?></div>
                </div>
                <div class="quick-item orange">
                    <div class="ql"><i class="fas fa-bell"></i><span>یادآورهای فعال</span></div>
                    <div class="qv"><?= persianNumber($reminderStats['active'] ?? 0) ?></div>
                </div>
            </div>
        </div>

    </div>

    <!-- ===== FOOTER ===== -->
    <div class="footer">
        <span>
            طراحی و توسعه:
            <a href="http://www.pdfco.ir" target="_blank">پیشگامان دنیای فناوری</a>
        </span>
        <span class="divider">|</span>
        <span class="version">نسخه ۲.۰</span>
    </div>

</main>

<!-- ===== TOGGLE MOBILE ===== -->
<button class="mobile-toggle" id="mobileToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- ==========================================================
   SCRIPTS
   ========================================================== -->
<script>
    document.getElementById('mobileToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    });
    document.getElementById('sidebarOverlay').addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    });
</script>

</body>
</html>

=============date_test.php===========
<?php
require_once 'config.php';
require_once 'functions.php';

echo "زمان فعلی (timestamp): " . time() . "<br>";
echo "تاریخ میلادی: " . date('Y-m-d H:i:s') . "<br>";
echo "تاریخ شمسی (nowShamsi): " . nowShamsi('Y/m/d H:i:s') . "<br>";

=============download_file.php============
<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه فایل مشخص نشده است');
}

$id = (int)$_GET['id'];

// دریافت اطلاعات فایل
$stmt = $pdo->prepare("SELECT * FROM download_center WHERE id = ? AND status = 1");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die('فایل یافت نشد یا غیرفعال است');
}

// بررسی وجود فایل
if (empty($item['file_path']) || !file_exists($item['file_path'])) {
    die('فایل روی سرور وجود ندارد');
}

// افزایش تعداد دانلود (استفاده از download_count)
$stmt = $pdo->prepare("UPDATE download_center SET download_count = download_count + 1 WHERE id = ?");
$stmt->execute([$id]);

// دانلود فایل
$file_path = $item['file_path'];
$file_name = basename($file_path);

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file_name . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

readfile($file_path);
exit();
?>


===========downloads.php============
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'دانلود سنتر';


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
$categories = $pdo->query("SELECT * FROM download_categories ORDER BY name")->fetchAll();

// ===== پردازش فیلترها =====
$where = [];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where[] = "(d.title LIKE :search OR d.description LIKE :search)";
    $params[':search'] = '%' . $_GET['search'] . '%';
}
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $where[] = "d.category_id = :category";
    $params[':category'] = $_GET['category'];
}
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $where[] = "d.status = :status";
    $params[':status'] = $_GET['status'];
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ===== دریافت کل فایل‌ها =====
$query = "
    SELECT d.*, 
           c.name as category_name, 
           c.color as category_color,
           u.full_name as creator_name
    FROM download_center d
    LEFT JOIN download_categories c ON d.category_id = c.id
    LEFT JOIN users u ON d.created_by = u.id
    $whereClause
    ORDER BY d.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$downloads = $stmt->fetchAll();

// ===== آمار =====
$stats = [
    'total' => count($downloads),
    'active' => count(array_filter($downloads, fn($a) => $a['status'] == 1)),
    'inactive' => count(array_filter($downloads, fn($a) => $a['status'] == 0))
];

// ===== پردازش عملیات =====
if (isset($_POST['download_action'])) {
    $action = $_POST['download_action'];
    $download_id = $_POST['download_id'] ?? 0;
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
    $download_url = trim($_POST['download_url'] ?? '');
    $file_size = trim($_POST['file_size'] ?? '');
    $file_type = trim($_POST['file_type'] ?? '');
    
$shamsi_date = isset($_POST['created_date']) ? trim($_POST['created_date']) : nowShamsi();    
    // ===== پردازش آپلود فایل =====
    $file_path = '';
    $file_name = '';
    
    if (isset($_FILES['file_upload']) && $_FILES['file_upload']['error'] == 0) {
        $upload_dir = 'uploads/downloads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file = $_FILES['file_upload'];
        $file_name = time() . '_' . basename($file['name']);
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            // اگر حجم فایل وارد نشده، از روی فایل محاسبه کن
            if (empty($file_size)) {
                $file_size = formatFileSize($file['size']);
            }
            if (empty($file_type)) {
                $file_type = strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION));
            }
        } else {
            $_SESSION['error'] = 'خطا در آپلود فایل';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    try {
        if ($action === 'add' && !empty($title)) {
            if (empty($download_url) && empty($file_path)) {
                $_SESSION['error'] = 'لطفاً یا لینک دانلود را وارد کنید یا فایل را آپلود نمایید.';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            }
            
            $stmt = $pdo->prepare("INSERT INTO download_center (title, description, file_name, file_path, download_url, file_size, file_type, category_id, status, created_by, created_at, shamsi_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->execute([$title, $description, $file_name, $file_path, $download_url, $file_size, $file_type, $category_id, $status, $user['id'], $shamsi_date]);
            $_SESSION['success'] = 'فایل با موفقیت اضافه شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'edit' && !empty($title) && $download_id > 0) {
            // اگر فایل جدید آپلود شده، فایل قبلی رو حذف کن
            if (!empty($file_path)) {
                $old = $pdo->prepare("SELECT file_path FROM download_center WHERE id = ?");
                $old->execute([$download_id]);
                $old_file = $old->fetch();
                if ($old_file && !empty($old_file['file_path']) && file_exists($old_file['file_path'])) {
                    unlink($old_file['file_path']);
                }
            }
            
            $stmt = $pdo->prepare("UPDATE download_center SET title = ?, description = ?, category_id = ?, status = ?, shamsi_date = ? WHERE id = ?");
            $stmt->execute([$title, $description, $category_id, $status, $shamsi_date, $download_id]);
            
            // اگر فایل جدید آپلود شده، آپدیت کن
            if (!empty($file_path)) {
                $stmt = $pdo->prepare("UPDATE download_center SET file_name = ?, file_path = ?, file_size = ?, file_type = ? WHERE id = ?");
                $stmt->execute([$file_name, $file_path, $file_size, $file_type, $download_id]);
            }
            
            // اگر لینک دانلود تغییر کرده
            if (!empty($download_url)) {
                $stmt = $pdo->prepare("UPDATE download_center SET download_url = ? WHERE id = ?");
                $stmt->execute([$download_url, $download_id]);
            }
            
            $_SESSION['success'] = 'فایل با موفقیت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'change_status' && $download_id > 0) {
            $new_status = isset($_POST['new_status']) ? (int)$_POST['new_status'] : 1;
            $stmt = $pdo->prepare("UPDATE download_center SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $download_id]);
            $_SESSION['success'] = 'وضعیت فایل با موفقیت تغییر کرد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'delete' && $download_id > 0) {
            // حذف فایل از سرور
            $stmt = $pdo->prepare("SELECT file_path FROM download_center WHERE id = ?");
            $stmt->execute([$download_id]);
            $file = $stmt->fetch();
            if ($file && !empty($file['file_path']) && file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }
            
            $stmt = $pdo->prepare("DELETE FROM download_center WHERE id = ?");
            $stmt->execute([$download_id]);
            $_SESSION['success'] = 'فایل با موفقیت حذف شد.';
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
    
    try {
        if ($action === 'add' && !empty($cat_name)) {
            $stmt = $pdo->prepare("INSERT INTO download_categories (name, color) VALUES (?, ?)");
            $stmt->execute([$cat_name, $cat_color]);
            $_SESSION['success'] = 'دسته‌بندی با موفقیت اضافه شد.';
        } elseif ($action === 'edit' && !empty($cat_name) && $cat_id > 0) {
            $stmt = $pdo->prepare("UPDATE download_categories SET name = ?, color = ? WHERE id = ?");
            $stmt->execute([$cat_name, $cat_color, $cat_id]);
            $_SESSION['success'] = 'دسته‌بندی با موفقیت ویرایش شد.';
        } elseif ($action === 'delete' && $cat_id > 0) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM download_center WHERE category_id = ?");
            $check->execute([$cat_id]);
            if ($check->fetchColumn() > 0) {
                $_SESSION['error'] = 'این دسته‌بندی دارای فایل است و نمی‌توان آن را حذف کرد.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM download_categories WHERE id = ?");
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

// ===== تابع کمکی برای حجم فایل =====
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دانلود سنتر</title>
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

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 28px; }
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
            padding: 8px 20px; background: #0969da; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif; display: inline-flex; align-items: center; gap: 6px;
            margin-right: auto;
        }
        .filters-bar .btn-add:hover { background: #0550b3; }
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
        .table-modern .actions .btn-icon.download { color: #2da44e; }
        .table-modern .actions .btn-icon.download:hover { background: #ddf4e6; }
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
            max-width: 600px; width: 95%; max-height: 90vh; overflow-y: auto;
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

        .file-upload-wrapper {
            border: 2px dashed #e1e4e8;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: #f8f9fa;
        }
        .file-upload-wrapper:hover {
            border-color: #0969da;
            background: #f0f4ff;
        }
        .file-upload-wrapper .file-info {
            color: #57606a;
            font-size: 13px;
        }
        .file-upload-wrapper .file-info i {
            font-size: 30px;
            color: #0969da;
            display: block;
            margin-bottom: 8px;
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

        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
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
        <li><a href="assets.php"><i class="fas fa-boxes"></i> بانک اموال</a></li>
        <li><a href="knowledge.php"><i class="fas fa-database"></i> بانک دانش</a></li>
        <li><a href="links.php"><i class="fas fa-link"></i> بانک لینک</a></li>
        <li><a href="downloads.php" class="active"><i class="fas fa-download"></i> مرکز دانلود</a></li>
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
                <i class="fas fa-download" style="color: #0969da; margin-left: 10px;"></i>
                دانلود سنتر
                <span>مدیریت فایل‌های قابل دانلود</span>
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
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-file"></i></span><span class="stat-number"><?= $stats['total'] ?></span></div>
            <div class="stat-label">کل فایل‌ها</div>
        </div>
        <div class="stat-card green-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-check-circle"></i></span><span class="stat-number"><?= $stats['active'] ?></span></div>
            <div class="stat-label">فعال</div>
        </div>
        <div class="stat-card red-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-times-circle"></i></span><span class="stat-number"><?= $stats['inactive'] ?></span></div>
            <div class="stat-label">غیرفعال</div>
        </div>
        <div class="stat-card purple-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-tags"></i></span><span class="stat-number"><?= count($categories) ?></span></div>
            <div class="stat-label">دسته‌بندی‌ها</div>
        </div>
    </div>

    <!-- ===== فیلترها ===== -->
    <form class="filters-bar" method="GET" action="">
        <div class="filter-group">
            <input type="text" name="search" placeholder="جستجوی عنوان یا توضیحات..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
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
                <option value="1" <?= (isset($_GET['status']) && $_GET['status'] == '1') ? 'selected' : '' ?>>فعال</option>
                <option value="0" <?= (isset($_GET['status']) && $_GET['status'] == '0') ? 'selected' : '' ?>>غیرفعال</option>
            </select>
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> فیلتر</button>
        <a href="downloads.php" class="btn-reset"><i class="fas fa-undo"></i> پاک کردن</a>
        <button type="button" class="btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> فایل جدید</button>
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
                        <th style="width:200px;">فایل / لینک</th>
                        <th style="width:130px;">دسته‌بندی</th>
                        <th style="width:80px;">حجم</th>
                        <th style="width:100px;">وضعیت</th>
                        <th style="width:110px;">تاریخ ثبت</th>
                        <th style="width:200px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($downloads)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:40px;color:#8b93a5;">هیچ فایلی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($downloads as $item): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                                    <?php if (!empty($item['description'])): ?>
                                        <div style="font-size:12px;color:#8b93a5;margin-top:2px;"><?= htmlspecialchars(mb_substr($item['description'], 0, 60)) ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:13px;">
                                    <?php if (!empty($item['file_path']) && file_exists($item['file_path'])): ?>
                                        <span style="color:#2da44e;">
                                            <i class="fas fa-file"></i> <?= htmlspecialchars(basename($item['file_path'])) ?>
                                        </span>
                                    <?php elseif (!empty($item['download_url'])): ?>
                                        <a href="<?= htmlspecialchars($item['download_url']) ?>" target="_blank" style="color:#0969da;text-decoration:none;font-size:12px;">
                                            <i class="fas fa-external-link-alt"></i> لینک دانلود
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#8b93a5;font-size:12px;">فایلی موجود نیست</span>
                                    <?php endif; ?>
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
                                <td style="font-size:12px;color:#57606a;text-align:center;">
                                    <?= htmlspecialchars($item['file_size'] ?? '-') ?>
                                </td>
                                <td>
                                    <?php if ($item['status'] == 1): ?>
                                        <span class="status-badge" style="background:#d1fae5;color:#065f46;">فعال</span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background:#fecaca;color:#991b1b;">غیرفعال</span>
                                    <?php endif; ?>
                                </td>
<td style="font-size:13px;color:#57606a;">
    <?= !empty($article['shamsi_date']) ? $article['shamsi_date'] : nowShamsi() ?>
</td>
                                <td>
                                    <div class="actions">
                                        <?php if (!empty($item['file_path']) && file_exists($item['file_path'])): ?>
                                            <a href="download_file.php?id=<?= $item['id'] ?>" class="btn-icon download" title="دانلود فایل">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php elseif (!empty($item['download_url'])): ?>
                                            <a href="<?= htmlspecialchars($item['download_url']) ?>" target="_blank" class="btn-icon download" title="دانلود">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                        <?php endif; ?>
                                        <button class="btn-icon view" onclick="viewDownload(<?= $item['id'] ?>)" title="مشاهده">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon edit" onclick="editDownload(<?= $item['id'] ?>)" title="ویرایش">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon quick-status" onclick="quickChangeStatus(<?= $item['id'] ?>)" title="تغییر وضعیت">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                        <button class="btn-icon delete" onclick="deleteDownload(<?= $item['id'] ?>)" title="حذف">
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

<!-- ===== مودال افزودن/ویرایش فایل ===== -->
<div class="modal-overlay" id="downloadModal">
    <div class="modal-box">
        <h2 id="downloadModalTitle">فایل جدید</h2>
        <form method="POST" action="" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="download_action" id="download_action" value="add">
            <input type="hidden" name="download_id" id="download_id" value="0">
            
            <div class="form-group">
                <label>تاریخ ثبت <span style="color:#8b949e;font-weight:400;">(شمسی)</span></label>
                <input type="text" name="created_date" id="created_date" class="persian-date" placeholder="تاریخ ثبت" value="<?= jdate('Y/m/d') ?>" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label>عنوان فایل</label>
                <input type="text" name="title" id="download_title" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <textarea name="description" id="download_description" rows="2"></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>دسته‌بندی</label>
                    <select name="category_id" id="download_category">
                        <option value="">بدون دسته‌بندی</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>وضعیت</label>
                    <select name="status" id="download_status">
                        <option value="1">فعال</option>
                        <option value="0">غیرفعال</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>آپلود فایل</label>
                <div class="file-upload-wrapper" onclick="document.getElementById('file_upload').click()">
                    <i class="fas fa-cloud-upload-alt" style="font-size:30px;color:#0969da;display:block;margin-bottom:8px;"></i>
                    <div class="file-info">برای آپلود فایل کلیک کنید یا فایل را بکشید و رها کنید</div>
                    <div id="file_name_display" style="margin-top:8px;font-size:13px;color:#2da44e;display:none;"></div>
                </div>
                <input type="file" name="file_upload" id="file_upload" style="display:none;" onchange="document.getElementById('file_name_display').textContent = '📎 ' + this.files[0].name; document.getElementById('file_name_display').style.display = 'block';">
            </div>
            
            <div class="form-group" style="border-top:1px dashed #e1e4e8;padding-top:16px;margin-top:8px;">
                <label style="color:#8b949e;">یا وارد کردن لینک دانلود مستقیم</label>
                <input type="url" name="download_url" id="download_url" placeholder="https://example.com/file.zip" autocomplete="off">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>حجم فایل (اختیاری)</label>
                    <input type="text" name="file_size" id="download_size" placeholder="مثلاً: 2.5 MB" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>نوع فایل (اختیاری)</label>
                    <input type="text" name="file_type" id="download_type" placeholder="مثلاً: PDF" autocomplete="off">
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('downloadModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره فایل</button>
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
                            <button class="btn-icon edit" onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name']) ?>', '<?= htmlspecialchars($cat['color'] ?? '#0969da') ?>')"><i class="fas fa-pen"></i></button>
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
        <h2>تغییر وضعیت فایل</h2>
        <form method="POST" action="">
            <input type="hidden" name="download_action" value="change_status">
            <input type="hidden" name="download_id" id="quick_status_download_id" value="0">
            <div class="form-group">
                <label>وضعیت جدید</label>
                <select name="new_status" id="quick_status_select">
                    <option value="1">فعال</option>
                    <option value="0">غیرفعال</option>
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
        document.getElementById('downloadModalTitle').textContent = 'فایل جدید';
        document.getElementById('download_action').value = 'add';
        document.getElementById('download_id').value = 0;
        document.getElementById('download_title').value = '';
        document.getElementById('download_description').value = '';
        document.getElementById('download_category').value = '';
        document.getElementById('download_status').value = '1';
        document.getElementById('download_url').value = '';
        document.getElementById('download_size').value = '';
        document.getElementById('download_type').value = '';
        document.getElementById('file_upload').value = '';
        document.getElementById('file_name_display').style.display = 'none';
        document.getElementById('created_date').value = '<?= jdate('Y/m/d') ?>';
        document.getElementById('downloadModal').classList.add('active');
    }

    function editDownload(id) {
        fetch('get_download.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('downloadModalTitle').textContent = 'ویرایش فایل';
                    document.getElementById('download_action').value = 'edit';
                    document.getElementById('download_id').value = id;
                    document.getElementById('download_title').value = data.title || '';
                    document.getElementById('download_description').value = data.description || '';
                    document.getElementById('download_category').value = data.category_id || '';
                    document.getElementById('download_status').value = data.status || '1';
                    document.getElementById('download_url').value = data.download_url || '';
                    document.getElementById('download_size').value = data.file_size || '';
                    document.getElementById('download_type').value = data.file_type || '';
                    document.getElementById('created_date').value = data.shamsi_date || '<?= jdate('Y/m/d') ?>';
                    if (data.file_name) {
                        document.getElementById('file_name_display').textContent = '📎 ' + data.file_name;
                        document.getElementById('file_name_display').style.display = 'block';
                    }
                    document.getElementById('downloadModal').classList.add('active');
                } else {
                    alert('خطا در دریافت اطلاعات: ' + (data.error || 'نامشخص'));
                }
            })
            .catch(function() {
                alert('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
            });
    }

    function viewDownload(id) {
        window.open('view_download.php?id=' + id, '_blank');
    }

    function deleteDownload(id) {
        if (confirm('آیا از حذف این فایل مطمئن هستید؟ این عملیات غیرقابل بازگشت است!')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            var input1 = document.createElement('input');
            input1.type = 'hidden';
            input1.name = 'download_action';
            input1.value = 'delete';
            var input2 = document.createElement('input');
            input2.type = 'hidden';
            input2.name = 'download_id';
            input2.value = id;
            form.appendChild(input1);
            form.appendChild(input2);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function quickChangeStatus(id) {
        document.getElementById('quick_status_download_id').value = id;
        document.getElementById('quick_status_select').value = '1';
        document.getElementById('quickStatusModal').classList.add('active');
    }

    function openCategoryModal() {
        document.getElementById('category_action').value = 'add';
        document.getElementById('cat_id').value = 0;
        document.getElementById('cat_name').value = '';
        document.getElementById('cat_color').value = '#0969da';
        document.getElementById('categorySubmitBtn').textContent = 'افزودن دسته‌بندی';
        document.getElementById('categoryModal').classList.add('active');
    }

    function editCategory(id, name, color) {
        document.getElementById('category_action').value = 'edit';
        document.getElementById('cat_id').value = id;
        document.getElementById('cat_name').value = name;
        document.getElementById('cat_color').value = color;
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

===============error_log.txt=================
[11-Jul-2026 15:04:39 Asia/Tehran] PHP Fatal error:  Uncaught Error: Undefined constant "persian_number_str" in C:\xampp\htdocs\company_kb\secretariat.php:912
Stack trace:
#0 {main}
  thrown in C:\xampp\htdocs\company_kb\secretariat.php on line 912
[11-Jul-2026 15:05:14 Asia/Tehran] PHP Fatal error:  Uncaught Error: Undefined constant "persian_number_str" in C:\xampp\htdocs\company_kb\secretariat.php:909
Stack trace:
#0 {main}
  thrown in C:\xampp\htdocs\company_kb\secretariat.php on line 909
[11-Jul-2026 15:28:31 Asia/Tehran] PHP Fatal error:  Uncaught Error: Call to undefined function shamsi_date() in C:\xampp\htdocs\company_kb\secretariat.php:1031
Stack trace:
#0 {main}
  thrown in C:\xampp\htdocs\company_kb\secretariat.php on line 1031

=============footer.php===================
<style>
    .footer {
        background: #ffffff;
        border-top: 1px solid #e1e4e8;
        padding: 14px 20px;
        margin-top: 30px;
        border-radius: 10px;
        text-align: center;
        width: 100%;
    }
    .footer-content {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        flex-wrap: wrap;
        font-size: 14px;
        color: #57606a;
        direction: rtl;
    }
    .footer-content a {
        color: #0969da;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.2s ease;
    }
    .footer-content a:hover {
        color: #0550b3;
        text-decoration: underline;
    }
    .footer-divider {
        color: #d0d7de;
    }
    .footer-version {
        font-size: 12px;
        color: #8b949e;
        background: #f6f8fa;
        padding: 2px 12px;
        border-radius: 12px;
    }
    @media (max-width: 480px) {
        .footer {
            padding: 12px 16px;
        }
        .footer-content {
            font-size: 12px;
            flex-direction: column;
            gap: 6px;
        }
        .footer-divider {
            display: none;
        }
    }
</style>

=================force_send.php================
<?php
require_once 'config.php';
require_once 'includes/ReminderService.php';

// شناسه آخرین یادآوری که ثبت کردید (اینجا عدد ۴ است)
$reminderId = 4;

$service = new ReminderService($pdo);
$result = $service->sendReminder($reminderId);

echo "<h2>نتیجه ارسال:</h2>";
echo "<pre>";
print_r($result);
echo "</pre>";

// نمایش لاگ‌ها
echo "<h2>لاگ‌های ارسال:</h2>";
$logs = $pdo->query("SELECT * FROM reminder_logs ORDER BY id DESC LIMIT 5")->fetchAll();
echo "<pre>";
print_r($logs);
echo "</pre>";


==============functions.php==============
<?php
require_once 'config.php';
//require_once 'includes/shamsi.php';
require_once 'includes/jdf.php';
require_once 'includes/encryption.php';

// ==================== توابع کاربری ====================

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
}

function getCurrentUser() {
    global $pdo;
    if (!isLoggedIn()) return null;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function getUserRoles($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function userHasRole($user_id, $role_name) {
    $roles = getUserRoles($user_id);
    return in_array($role_name, $roles);
}

function userCan($permission) {
    $user = getCurrentUser();
    if (!$user) return false;
    if (userHasRole($user['id'], 'admin')) return true;
    return false;
}


// ====================  1توابع تاریخ شمسی ====================

function nowShamsi1() {
    date_default_timezone_set('Asia/Tehran');
    require_once 'includes/jdf.php';
    
    $timestamp = time();
    // اضافه کردن 4 روز برای جبران اختلاف
    $timestamp += 4 * 24 * 60 * 60;
    
    return jdate('Y/m/d H:i', $timestamp);
}

function toShamsi1($timestamp) {
    date_default_timezone_set('Asia/Tehran');
    require_once 'includes/jdf.php';
    if (is_string($timestamp)) {
        $timestamp = strtotime($timestamp);
    }
    // اضافه کردن 4 روز برای جبران اختلاف
    $timestamp += 4 * 24 * 60 * 60;
    return jdate('Y/m/d H:i', $timestamp);
}


// ==================== توابع تاریخ شمسی ====================

function nowShamsi() {
    date_default_timezone_set('Asia/Tehran');
    require_once 'includes/jdf.php';
    
    $timestamp = time();
    // اضافه کردن 4 روز برای جبران اختلاف
    $timestamp += 4 * 24 * 60 * 60;
    
    return jdate('Y/m/d', $timestamp);
}

function toShamsi($timestamp) {
    date_default_timezone_set('Asia/Tehran');
    require_once 'includes/jdf.php';
    if (is_string($timestamp)) {
        $timestamp = strtotime($timestamp);
    }
    // اضافه کردن 4 روز برای جبران اختلاف
    $timestamp += 4 * 24 * 60 * 60;
    return jdate('Y/m/d', $timestamp);
}

// ==================== توابع Captcha ====================

function generateCaptcha() {
    $code = rand(1000, 9999);
    $_SESSION['captcha'] = $code;
    return $code;
}

function verifyCaptcha($input) {
    return isset($_SESSION['captcha']) && $_SESSION['captcha'] == $input;
}

// ==================== توابع لاگ ====================

function logActivity($user_id, $module, $action, $description = '') {
    global $pdo;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, module, action, description, ip_address) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([$user_id, $module, $action, $description, $ip]);
}

// ==================== توابع امنیتی ====================

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// ==================== توابع وضعیت رنگی ====================

function getStatusBadge($status) {
    $colors = [
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'needs_revision' => 'orange',
        'assigned' => 'primary',
        'returned' => 'success',
        'lost' => 'danger',
        'incoming' => 'info',
        'outgoing' => 'success',
        'active' => 'success',
        'done' => 'info',
        'expired' => 'danger',
        'low' => 'success',
        'medium' => 'warning',
        'high' => 'danger'
    ];
    $labels = [
        'pending' => 'در انتظار تایید',
        'approved' => 'تایید شده',
        'rejected' => 'رد شده',
        'needs_revision' => 'نیاز به اصلاح',
        'assigned' => 'واگذار شده',
        'returned' => 'بازگشت داده شده',
        'lost' => 'گم شده',
        'incoming' => 'ورودی',
        'outgoing' => 'خروجی',
        'active' => 'فعال',
        'done' => 'انجام شده',
        'expired' => 'منقضی',
        'low' => 'کم',
        'medium' => 'متوسط',
        'high' => 'زیاد'
    ];
    $color = $colors[$status] ?? 'secondary';
    $label = $labels[$status] ?? $status;
    return "<span class='badge bg-$color'>$label</span>";
}

// ==================== توابع آمار ====================

function getModuleStats($module) {
    global $pdo;
    $stats = [];
    
    switch($module) {
        case 'knowledge':
            $stats['total'] = $pdo->query("SELECT COUNT(*) FROM knowledge_base")->fetchColumn();
            $stats['pending'] = $pdo->query("SELECT COUNT(*) FROM knowledge_base WHERE status = 'pending'")->fetchColumn();
            $stats['approved'] = $pdo->query("SELECT COUNT(*) FROM knowledge_base WHERE status = 'approved'")->fetchColumn();
            $stats['rejected'] = $pdo->query("SELECT COUNT(*) FROM knowledge_base WHERE status = 'rejected'")->fetchColumn();
            break;
            
        case 'links':
            $stats['total'] = $pdo->query("SELECT COUNT(*) FROM link_bank")->fetchColumn();
            $stats['active'] = $pdo->query("SELECT COUNT(*) FROM link_bank WHERE status = 1")->fetchColumn();
            break;
            
        case 'downloads':
            $stats['total'] = $pdo->query("SELECT COUNT(*) FROM download_center")->fetchColumn();
            $stats['today'] = $pdo->query("SELECT COUNT(*) FROM download_center WHERE DATE(created_at) = CURDATE()")->fetchColumn();
            break;
            
        case 'assets':
            $stats['total'] = $pdo->query("SELECT COUNT(*) FROM asset_assignments")->fetchColumn();
            $stats['assigned'] = $pdo->query("SELECT COUNT(*) FROM asset_assignments WHERE status = 'assigned'")->fetchColumn();
            $stats['returned'] = $pdo->query("SELECT COUNT(*) FROM asset_assignments WHERE status = 'returned'")->fetchColumn();
            break;
            
        case 'users':
            $stats['total'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stats['active'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 1")->fetchColumn();
            break;
            
        case 'secretariat':
            $stats['total'] = $pdo->query("SELECT COUNT(*) FROM secretariat")->fetchColumn();
            $stats['incoming'] = $pdo->query("SELECT COUNT(*) FROM secretariat WHERE type = 'incoming'")->fetchColumn();
            $stats['outgoing'] = $pdo->query("SELECT COUNT(*) FROM secretariat WHERE type = 'outgoing'")->fetchColumn();
            $stats['pending'] = $pdo->query("SELECT COUNT(*) FROM secretariat WHERE status = 'pending'")->fetchColumn();
            $stats['approved'] = $pdo->query("SELECT COUNT(*) FROM secretariat WHERE status = 'approved'")->fetchColumn();
            $stats['rejected'] = $pdo->query("SELECT COUNT(*) FROM secretariat WHERE status = 'rejected'")->fetchColumn();
            break;
            
        case 'reminder':
            $stats['total'] = $pdo->query("SELECT COUNT(*) FROM reminders")->fetchColumn();
            $stats['active'] = $pdo->query("SELECT COUNT(*) FROM reminders WHERE status = 'active' AND reminder_date >= CURDATE()")->fetchColumn();
            $stats['expired'] = $pdo->query("SELECT COUNT(*) FROM reminders WHERE status = 'expired' OR reminder_date < CURDATE()")->fetchColumn();
            $stats['today'] = $pdo->query("SELECT COUNT(*) FROM reminders WHERE DATE(reminder_date) = CURDATE()")->fetchColumn();
            $stats['done'] = $pdo->query("SELECT COUNT(*) FROM reminders WHERE status = 'done'")->fetchColumn();
            break;
    }
    return $stats;
}

// ==================== توابع نقش و دسترسی ====================

function getRolePermissions($roleName) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT p.permission_key 
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            JOIN roles r ON rp.role_id = r.id
            WHERE r.role_name = :role_name
        ");
        $stmt->execute([':role_name' => $roleName]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
}

// ==================== تابع تبدیل تاریخ شمسی به میلادی برای دیتابیس ====================

function shamsiToMiladiForDB($shamsiDate) {
    // اگر تاریخ خالی بود، تاریخ امروز را برمی‌گردانیم
    if (empty($shamsiDate)) {
        return date('Y-m-d');
    }
    
    // پاکسازی ورودی
    $shamsiDate = trim($shamsiDate);
    $shamsiDate = str_replace(['-', '_', '،', ';'], '/', $shamsiDate);
    
    // جدا کردن سال، ماه، روز
    $parts = explode('/', $shamsiDate);
    if (count($parts) != 3) {
        return date('Y-m-d');
    }
    
    $year = (int)trim($parts[0]);
    $month = (int)trim($parts[1]);
    $day = (int)trim($parts[2]);
    
    // بررسی اعتبار تاریخ
    if ($year < 1300 || $year > 1500 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return date('Y-m-d');
    }
    
    // ===== استفاده از روش دستی برای تبدیل =====
    // تعداد روزهای هر ماه شمسی
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    
    // محاسبه روز سال شمسی
    $j_day_of_year = 0;
    for ($i = 0; $i < $month - 1; $i++) {
        $j_day_of_year += $j_days_in_month[$i];
    }
    $j_day_of_year += $day;
    
    // روزهای سال میلادی
    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    
    // پیدا کردن سال میلادی معادل
    $g_year = $year + 621;
    $g_day_of_year = $j_day_of_year + 79;
    
    // اگر روز سال از 365 بیشتر شد، سال میلادی را یک سال جلو ببر
    if ($g_day_of_year > 365) {
        $g_year++;
        $g_day_of_year -= 365;
        // اگر سال کبیسه میلادی بود
        if (($g_year % 4 == 0 && $g_year % 100 != 0) || $g_year % 400 == 0) {
            $g_day_of_year--;
        }
    }
    
    // تبدیل روز سال به ماه و روز میلادی
    $g_month = 1;
    $g_day = $g_day_of_year;
    for ($i = 0; $i < 12; $i++) {
        $days_in_this_month = $g_days_in_month[$i];
        // اگر سال کبیسه بود، روزهای بهمن را 29 کن
        if ($i == 1 && (($g_year % 4 == 0 && $g_year % 100 != 0) || $g_year % 400 == 0)) {
            $days_in_this_month = 29;
        }
        if ($g_day <= $days_in_this_month) {
            $g_month = $i + 1;
            break;
        }
        $g_day -= $days_in_this_month;
    }
    
    // فرمت نهایی
    return $g_year . '-' . str_pad($g_month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($g_day, 2, '0', STR_PAD_LEFT);
}

// ============================================
// 🔐 توابع جدید برای ماژول کاربران
// ============================================

// ============================================
// توابع CSRF
// ============================================
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================
// اعتبارسنجی کد ملی ایران
// ============================================
function validateNationalId($nationalId) {
    // حذف فاصله‌ها و کاراکترهای غیرعددی
    $nationalId = preg_replace('/[^0-9]/', '', $nationalId);
    
    // بررسی طول ۱۰ رقمی
    if (strlen($nationalId) != 10) return false;
    
    // بررسی اینکه همه یک رقم نباشند
    if (preg_match('/^(\d)\1+$/', $nationalId)) return false;
    
    // الگوریتم اعتبارسنجی
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$nationalId[$i] * (10 - $i);
    }
    $remainder = $sum % 11;
    $controlDigit = ($remainder < 2) ? $remainder : 11 - $remainder;
    
    return $controlDigit == (int)$nationalId[9];
}

// ============================================
// آپلود عکس پروفایل
// ============================================
function uploadProfilePicture($file) {
    $targetDir = 'uploads/profiles/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    $fileName = time() . '_' . basename($file['name']);
    $targetPath = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($fileType, $allowedTypes)) {
        return ['success' => false, 'error' => 'فرمت فایل مجاز نیست (فقط JPG, PNG, GIF)'];
    }
    if ($file['size'] > 2 * 1024 * 1024) { // 2MB
        return ['success' => false, 'error' => 'حجم فایل بیش از ۲ مگابایت است'];
    }
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => true, 'path' => $targetPath];
    } else {
        return ['success' => false, 'error' => 'خطا در آپلود فایل'];
    }
}

// پایان فایل
?>

================generate_captcha.php=================
<?php
session_start();
require_once 'config.php';

function generateNumericCaptcha() {
    $code = rand(100000, 999999);
    $_SESSION['captcha'] = $code;
    return $code;
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'captcha' => generateNumericCaptcha()]);
    exit();
}

====================get_article.php===================
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


===================get_asset.php=================
<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه اموال مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];

// دریافت اطلاعات اموال
$stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) {
    echo json_encode(['success' => false, 'error' => 'اموال یافت نشد']);
    exit();
}

// دریافت اطلاعات واگذاری
$stmt = $pdo->prepare("SELECT * FROM asset_assignments WHERE asset_id = ? AND status = 'assigned' ORDER BY id DESC LIMIT 1");
$stmt->execute([$id]);
$assignment = $stmt->fetch();

echo json_encode([
    'success' => true,
    'title' => $asset['title'],
    'code' => $asset['code'],
    'description' => $asset['description'],
    'category_id' => $asset['category_id'],
    'status' => $asset['status'],
    'purchase_price' => $asset['purchase_price'],
    'purchase_date' => $asset['purchase_date'],
    'shamsi_date' => $asset['shamsi_date'],
    'assignment' => $assignment
]);
?>

============get_download.php==============
<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه فایل مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM download_center WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if ($item) {
    echo json_encode([
        'success' => true,
        'title' => $item['title'],
        'description' => $item['description'],
        'file_name' => $item['file_name'],
        'file_path' => $item['file_path'],
        'download_url' => $item['download_url'],
        'file_size' => $item['file_size'],
        'file_type' => $item['file_type'],
        'category_id' => $item['category_id'],
        'status' => $item['status'],
        'shamsi_date' => $item['shamsi_date']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'فایل یافت نشد']);
}
?>




==============get_expense.php===============
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

===============get_food_order.php===============
<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'شناسه نامعتبر']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM food_orders WHERE id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'سفارش یافت نشد']);
        exit;
    }

    $items = $pdo->prepare("SELECT * FROM food_order_items WHERE order_id = ?");
    $items->execute([$id]);
    $order['items'] = $items->fetchAll();

    echo json_encode(['success' => true] + $order);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


==============get_letter.php=================
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

===============get_link.php==============
<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه لینک مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM link_bank WHERE id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();

if ($link) {
    echo json_encode([
        'success' => true,
        'title' => $link['title'],
        'url' => $link['url'],
        'description' => $link['description'],
        'category_id' => $link['category_id'],
        'status' => $link['status'],
        'username' => $link['username'],
        'password' => $link['password'],
        'shamsi_date' => $link['shamsi_date']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'لینک یافت نشد']);
}
?>


===================get_reminder.php=============
<?php
/**
 * دریافت اطلاعات یک یادآور برای ویرایش
 */

header('Content-Type: application/json');

require_once 'config.php';
require_once 'functions.php';
requireLogin();

$id = $_GET['id'] ?? 0;

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ?");
    $stmt->execute([$id]);
    $reminder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reminder) {
        echo json_encode(['success' => true] + $reminder);
    } else {
        echo json_encode(['success' => false, 'error' => 'یادآور یافت نشد']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'شناسه نامعتبر']);
}

=============get_signature.php=============
<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه امضا مشخص نشده است']);
    exit();
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM signatures WHERE id = ?");
$stmt->execute([$id]);
$sig = $stmt->fetch();

if ($sig) {
    echo json_encode([
        'success' => true,
        'name' => $sig['name'],
        'position' => $sig['position'],
        'image_path' => $sig['image_path'],
        'is_default' => $sig['is_default']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'امضا یافت نشد']);
}
?>

=================get_template.php============
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

==============get_user=================
<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'شناسه کاربر ارسال نشده']);
    exit();
}

$stmt = $pdo->prepare("SELECT id, full_name, mobile, email, telegram_id FROM users WHERE id = ?");
$stmt->execute([$_GET['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo json_encode(['success' => true, 'full_name' => $user['full_name'], 'mobile' => $user['mobile'], 'email' => $user['email'], 'telegram_id' => $user['telegram_id']]);
} else {
    echo json_encode(['success' => false, 'error' => 'کاربر یافت نشد']);
}


=====================hash.php==============

<?php
// پسورد مورد نظر خود را اینجا وارد کنید
$password = 'password';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "پسورد: " . $password . "<br>";
echo "هش تولید شده: " . $hash . "<br>";
echo "<hr>";
echo "برای تست:<br>";
echo "<a href='test_login.php?username=admin&password=password'>تست ورود</a>";
?>


=============header.php==============

<!DOCTYPE html>
<html lang="fa" dir="rtl" class="<?php echo $_COOKIE['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'سامانه مدیریت دانش'; ?></title>
    
    <!-- ====== Font Awesome (پشتیبانی کامل) ====== -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- ====== Lucide Icons (مدرن و سبک) ====== -->
    <link href="https://cdn.jsdelivr.net/npm/lucide-static@0.344.0/font/lucide.min.css" rel="stylesheet">
    
    <!-- ====== Bootstrap 5 RTL ====== -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    
    <!-- ====== فونت وزیرمتن (برای زیبایی) ====== -->
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    
    <!-- ====== استایل‌های سفارشی ====== -->
    <link href="/company_kb/assets/css/style.css" rel="stylesheet">
    <link href="/company_kb/assets/css/themes.css" rel="stylesheet">
    
    <style>
        /* ====== تنظیمات پایه ====== */
        * {
            font-family: 'Vazirmatn', 'IRANSans', 'Tahoma', sans-serif;
        }
        
        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: background 0.3s ease, color 0.3s ease;
        }
        
        /* ====== دکمه تغییر تم ====== */
        .theme-toggle {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            color: var(--text-primary);
            box-shadow: var(--shadow);
            font-size: 18px;
        }
        
        .theme-toggle:hover {
            transform: rotate(30deg);
            box-shadow: var(--shadow-md);
        }
        
        /* ====== Top Bar استایل ====== */
        .top-bar {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 15px 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid var(--border-color);
        }
        
        .top-bar .page-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .top-bar .page-title h4 {
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }
        
        .top-bar .page-title .icon-box {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .top-bar .left-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .top-bar .date-time {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .top-bar .date-time i {
            margin-left: 8px;
            color: var(--primary);
        }
        
        /* ====== اسکرول بار سفارشی ====== */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-primary);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
        
        /* ====== انیمیشن fade-in ====== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-up {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        .animate-delay-1 { animation-delay: 0.1s; }
        .animate-delay-2 { animation-delay: 0.2s; }
        .animate-delay-3 { animation-delay: 0.3s; }
        .animate-delay-4 { animation-delay: 0.4s; }
        
        /* ====== واکنش‌گرا برای موبایل ====== */
        @media (max-width: 768px) {
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .top-bar .left-section {
                width: 100%;
                justify-content: space-between;
            }
            
            .top-bar .date-time {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    
    <!-- ====== لودینگ (در صورت نیاز) ====== -->
    <div id="loader" style="display:none;">
        <div class="loader-bar">
            <div class="progress"></div>
        </div>
        <div class="loader-text">در حال بارگذاری...</div>
    </div>
    
    <!-- ====== اسکریپت تغییر تم ====== -->
    <script>
        // ====== سیستم تم دارک/لایت ======
        (function() {
            // دریافت تم ذخیره شده
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.className = savedTheme;
            
            // تابع تغییر تم
            window.toggleTheme = function() {
                const html = document.documentElement;
                const current = html.className;
                const next = current === 'light' ? 'dark' : 'light';
                html.className = next;
                localStorage.setItem('theme', next);
                
                // آپدیت آیکون دکمه
                const icon = document.querySelector('.theme-toggle i');
                if (icon) {
                    icon.className = next === 'light' ? 'fas fa-moon' : 'fas fa-sun';
                }
            };
            
            // تنظیم آیکون دکمه در لود
            document.addEventListener('DOMContentLoaded', function() {
                const icon = document.querySelector('.theme-toggle i');
                if (icon) {
                    const current = document.documentElement.className;
                    icon.className = current === 'light' ? 'fas fa-moon' : 'fas fa-sun';
                }
            });
        })();
    </script>




===============index.php==============
<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// ===== تولید کپچای ۶ رقمی عددی =====
function generateNumericCaptcha() {
    $code = rand(100000, 999999);
    $_SESSION['captcha'] = $code;
    return $code;
}

// ===== بررسی درخواست رفرش کپچا (AJAX) =====
if (isset($_GET['ajax_captcha'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'captcha' => generateNumericCaptcha()]);
    exit();
}

// ===== بررسی ورود =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $captcha = trim($_POST['captcha'] ?? '');
    
    if (!isset($_SESSION['captcha']) || $captcha != $_SESSION['captcha']) {
        $error = '❌ کد امنیتی اشتباه است.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (national_id = ? OR email = ?) AND status = 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            logActivity($user['id'], 'login', 'ورود به سیستم', 'ورود موفق');
            
            header('Location: dashboard.php');
            exit();
        } else {
            $error = '❌ کد ملی یا رمز عبور اشتباه است.';
        }
    }
}

$captchaCode = generateNumericCaptcha();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به سامانه</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: linear-gradient(135deg, #e8f0fe, #f0f4ff);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            display: flex;
            flex-direction: row;
            max-width: 1000px;
            width: 100%;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            overflow: hidden;
            min-height: 560px;
        }
        .login-left {
            flex: 1;
            background: linear-gradient(135deg, #1a73e8, #0d47a1);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            position: relative;
        }
        .login-left img {
            max-width: 100%;
            max-height: 350px;
            object-fit: contain;
            filter: drop-shadow(0 4px 20px rgba(0,0,0,0.3));
            border-radius: 12px;
        }
        .login-left .overlay-text {
            position: absolute;
            bottom: 30px;
            color: rgba(255,255,255,0.6);
            font-size: 14px;
            font-weight: 300;
            text-align: center;
            width: 100%;
        }
        .login-right {
            flex: 1;
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }
        .login-right .brand {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-right .brand h1 {
            font-size: 26px;
            font-weight: 800;
            color: #1a2332;
            letter-spacing: -0.5px;
        }
        .login-right .brand h1 i {
            color: #1a73e8;
            margin-left: 8px;
        }
        .login-right .brand p {
            color: #5f6b7a;
            font-size: 14px;
            margin-top: 4px;
        }
        .login-right .form-group {
            margin-bottom: 20px;
        }
        .login-right .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
        }
        /* ===== فیلدهای راست‌چین (کد ملی و رمز عبور) ===== */
        .login-right .form-group input[type="text"],
        .login-right .form-group input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Vazirmatn', sans-serif;
            transition: all 0.3s ease;
            background: #f7fafc;
            direction: rtl;
            text-align: right;
        }
        .login-right .form-group input[type="text"]:focus,
        .login-right .form-group input[type="password"]:focus {
            outline: none;
            border-color: #1a73e8;
            box-shadow: 0 0 0 4px rgba(26,115,232,0.15);
            background: #ffffff;
        }
        .login-right .form-group input[type="text"]::placeholder,
        .login-right .form-group input[type="password"]::placeholder {
            text-align: right;
            color: #9aa6b5;
        }
        /* ===== فیلد کپچا (چپ‌چین برای اعداد) ===== */
        .login-right .form-group input.captcha-input {
            direction: ltr;
            text-align: center;
            letter-spacing: 2px;
            font-weight: 600;
        }
        .login-right .form-group input.captcha-input::placeholder {
            text-align: center;
            font-weight: 400;
            letter-spacing: 0;
            font-size: 14px;
        }
        .login-right .captcha-container {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            background: #f7fafc;
            border-radius: 10px;
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
        }
        .login-right .captcha-container .captcha-number {
            font-size: 28px;
            font-weight: 800;
            color: #1a2332;
            letter-spacing: 6px;
            background: #ffffff;
            padding: 6px 20px;
            border-radius: 8px;
            border: 1px dashed #1a73e8;
            font-family: 'Courier New', monospace;
            direction: ltr;
            user-select: none;
            order: 2; /* عدد کپچا به راست می‌رود */
        }
        .login-right .captcha-container .refresh-btn {
            background: none;
            border: none;
            color: #1a73e8;
            font-size: 20px;
            cursor: pointer;
            padding: 6px 10px;
            border-radius: 8px;
            transition: 0.2s;
            order: 1; /* دکمه وسط */
        }
        .login-right .captcha-container .refresh-btn:hover {
            background: #e8f0fe;
            transform: rotate(45deg);
        }
        .login-right .captcha-container input {
            flex: 1;
            min-width: 120px;
            border: none;
            background: transparent;
            padding: 10px 6px;
            font-size: 16px;
            font-family: 'Vazirmatn', sans-serif;
            direction: ltr;
            text-align: center;
            letter-spacing: 2px;
            font-weight: 600;
            order: 0; /* فیلد ورودی به چپ می‌رود */
        }
        .login-right .captcha-container input::placeholder {
            text-align: center;
            font-weight: 400;
            letter-spacing: 0;
            font-size: 14px;
        }
        .login-right .captcha-container input:focus {
            outline: none;
            box-shadow: none;
        }
        .login-right .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1a73e8, #0d47a1);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .login-right .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(26,115,232,0.35);
        }
        .login-right .btn-login:active {
            transform: translateY(0);
        }
        .login-right .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-weight: 500;
            font-size: 14px;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .login-right .alert i {
            font-size: 18px;
        }

        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                max-width: 480px;
                min-height: auto;
            }
            .login-left {
                padding: 20px;
                min-height: 160px;
            }
            .login-left img {
                max-height: 120px;
            }
            .login-left .overlay-text {
                display: none;
            }
            .login-right {
                padding: 32px 24px;
            }
            .login-right .brand h1 {
                font-size: 22px;
            }
            .login-right .captcha-container .captcha-number {
                font-size: 22px;
                padding: 4px 14px;
            }
        }
        @media (max-width: 480px) {
            .login-right {
                padding: 24px 16px;
            }
            .login-right .captcha-container {
                flex-wrap: wrap;
                justify-content: center;
            }
            .login-right .captcha-container input {
                min-width: 100%;
                order: 0;
            }
            .login-right .captcha-container .refresh-btn {
                order: 1;
            }
            .login-right .captcha-container .captcha-number {
                order: 2;
            }
        }
    </style>
</head>
<body>

<div class="login-container">
    <!-- ===== سمت چپ: فرم ===== -->
    <div class="login-right">
        <div class="brand">
            <h1><i class="fas fa-heartbeat"></i> سامانه مدیریت</h1>
            <p>پرونده الکترونیک سلامت</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label><i class="fas fa-id-card"></i> کد ملی</label>
                <input type="text" 
                       name="username" 
                       placeholder="کد ملی خود را وارد کنید" 
                       required 
                       autofocus
                       maxlength="10"
                       inputmode="numeric"
                       pattern="[0-9]*"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10)">
            </div>
            <div class="form-group">
                <label><i class="fas fa-lock"></i> رمز عبور</label>
                <input type="password" name="password" placeholder="رمز عبور خود را وارد کنید" required>
            </div>
            <div class="form-group">
                <label><i class="fas fa-shield-alt"></i> کد امنیتی</label>
                <div class="captcha-container">
                    <input type="text" 
                           name="captcha" 
                           class="captcha-input"
                           placeholder="۶ رقم را وارد کنید" 
                           maxlength="6" 
                           required 
                           inputmode="numeric"
                           pattern="[0-9]*"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)">
                    <button type="button" class="refresh-btn" onclick="refreshCaptcha()" title="تولید مجدد">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <span class="captcha-number" id="captchaDisplay"><?= $captchaCode ?></span>
                </div>
            </div>
            <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt"></i> ورود به سامانه</button>
        </form>
    </div>

    <!-- ===== سمت راست: تصویر ===== -->
    <div class="login-left">
        <img src="assets/images/logo.png" alt="پرونده الکترونیک سلامت">
        <div class="overlay-text">پرونده الکترونیک سلامت</div>
    </div>
</div>

<script>
function refreshCaptcha() {
    fetch('?ajax_captcha=1')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('captchaDisplay').textContent = data.captcha;
            }
        })
        .catch(error => console.error('خطا در دریافت کپچا:', error));
}
</script>

</body>
</html>


===============install_reminder_tables.php=================
<?php
// ===== فایل: install_reminder_tables.php =====
// این فایل را در مسیر C:\xampp\htdocs\company_kb\ ایجاد کنید

require_once 'config.php';

try {
    // ===== 1. جدول reminder_categories =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reminder_categories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `color` varchar(20) DEFAULT '#0969da',
            `icon` varchar(50) DEFAULT 'fa-tag',
            `description` text,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول reminder_categories ایجاد شد.<br>";
    
    // ===== 2. جدول user_groups =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `description` text,
            `members` text,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول user_groups ایجاد شد.<br>";
    
    // ===== 3. جدول reminders =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reminders` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `description` text,
            `category_id` int(11) DEFAULT NULL,
            `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
            `status` enum('active','done','expired','canceled') DEFAULT 'active',
            `reminder_date` date NOT NULL,
            `reminder_time` time DEFAULT NULL,
            `shamsi_reminder_date` varchar(20) DEFAULT NULL,
            `repeat_type` enum('none','daily','weekly','monthly','yearly') DEFAULT 'none',
            `repeat_until` date DEFAULT NULL,
            `shamsi_repeat_until` varchar(20) DEFAULT NULL,
            `assigned_to` int(11) DEFAULT NULL,
            `assigned_group` int(11) DEFAULT NULL,
            `is_group` tinyint(1) DEFAULT 0,
            `send_sms` tinyint(1) DEFAULT 0,
            `send_email` tinyint(1) DEFAULT 0,
            `send_telegram` tinyint(1) DEFAULT 0,
            `send_whatsapp` tinyint(1) DEFAULT 0,
            `send_system` tinyint(1) DEFAULT 1,
            `sms_text` text,
            `email_subject` varchar(255) DEFAULT NULL,
            `email_body` text,
            `sent_at` datetime DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            `shamsi_date` varchar(20) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `category_id` (`category_id`),
            KEY `assigned_to` (`assigned_to`),
            KEY `assigned_group` (`assigned_group`),
            KEY `created_by` (`created_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول reminders ایجاد شد.<br>";
    
    // ===== 4. جدول reminder_logs =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reminder_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `reminder_id` int(11) NOT NULL,
            `user_id` int(11) DEFAULT NULL,
            `send_type` enum('sms','email','telegram','whatsapp','system') NOT NULL,
            `send_status` enum('pending','sent','failed') DEFAULT 'pending',
            `send_response` text,
            `sent_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `reminder_id` (`reminder_id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول reminder_logs ایجاد شد.<br>";
    
    // ===== 5. داده‌های اولیه برای دسته‌بندی‌ها =====
    $pdo->exec("
        INSERT IGNORE INTO `reminder_categories` (`name`, `color`, `icon`) VALUES
        ('شخصی', '#0969da', 'fa-user'),
        ('کاری', '#2da44e', 'fa-briefcase'),
        ('پروژه', '#8250df', 'fa-project-diagram'),
        ('مشتری', '#cf222e', 'fa-user-tie'),
        ('پشتیبانی', '#ff9800', 'fa-headset'),
        ('یادبود', '#e91e63', 'fa-gift'),
        ('آموزشی', '#00bcd4', 'fa-graduation-cap')
    ");
    echo "✅ داده‌های اولیه وارد شدند.<br>";
    
    echo "<br><strong style='color:green;font-size:18px;'>✅ همه جداول با موفقیت ایجاد شدند!</strong>";
    echo "<br><br><a href='reminder.php' style='padding:12px 30px;background:#0969da;color:#fff;text-decoration:none;border-radius:8px;font-size:16px;'>👉 رفتن به صفحه یادآور</a>";
    
} catch(PDOException $e) {
    echo "❌ خطا: " . $e->getMessage();
}
?>

===============knowledge.php=============
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
=====================letter_settings.php================
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'تنظیمات دبیرخانه';

// ===== دریافت تنظیمات شماره‌گذاری =====
$currentYear = jdate('Y');
$numbering = $pdo->prepare("SELECT * FROM letter_numbering WHERE year = ?");
$numbering->execute([$currentYear]);
$numbering = $numbering->fetch();

if (!$numbering) {
    $pdo->prepare("INSERT INTO letter_numbering (year, start_number, current_number) VALUES (?, 1, 0)")->execute([$currentYear]);
    $numbering = $pdo->prepare("SELECT * FROM letter_numbering WHERE year = ?");
    $numbering->execute([$currentYear]);
    $numbering = $numbering->fetch();
}

// ===== دریافت سربرگ‌ها =====
$headers = $pdo->query("SELECT * FROM letter_headers ORDER BY is_default DESC, title")->fetchAll();

// ===== پردازش سربرگ =====
if (isset($_POST['header_action'])) {
    $action = $_POST['header_action'];
    $header_id = $_POST['header_id'] ?? 0;
    $title = trim($_POST['title'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $fax = trim($_POST['fax'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    
    // ===== پردازش آپلود لوگو =====
    $logo_path = '';
    if (isset($_FILES['logo_upload']) && $_FILES['logo_upload']['error'] == 0) {
        $upload_dir = 'uploads/letter_logos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file = $_FILES['logo_upload'];
        $file_name = time() . '_' . basename($file['name']);
        $logo_path = $upload_dir . $file_name;
        
        if (!move_uploaded_file($file['tmp_name'], $logo_path)) {
            $_SESSION['error'] = 'خطا در آپلود لوگو';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    try {
        if ($action === 'add' && !empty($title)) {
            $stmt = $pdo->prepare("INSERT INTO letter_headers (title, address, phone, fax, email, website, is_default, logo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $address, $phone, $fax, $email, $website, $is_default, $logo_path]);
            $_SESSION['success'] = 'سربرگ با موفقیت اضافه شد.';
        } elseif ($action === 'edit' && !empty($title) && $header_id > 0) {
            if (!empty($logo_path)) {
                $old = $pdo->prepare("SELECT logo_path FROM letter_headers WHERE id = ?");
                $old->execute([$header_id]);
                $old_logo = $old->fetch();
                if ($old_logo && !empty($old_logo['logo_path']) && file_exists($old_logo['logo_path'])) {
                    unlink($old_logo['logo_path']);
                }
                $stmt = $pdo->prepare("UPDATE letter_headers SET title = ?, address = ?, phone = ?, fax = ?, email = ?, website = ?, is_default = ?, logo_path = ? WHERE id = ?");
                $stmt->execute([$title, $address, $phone, $fax, $email, $website, $is_default, $logo_path, $header_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE letter_headers SET title = ?, address = ?, phone = ?, fax = ?, email = ?, website = ?, is_default = ? WHERE id = ?");
                $stmt->execute([$title, $address, $phone, $fax, $email, $website, $is_default, $header_id]);
            }
            $_SESSION['success'] = 'سربرگ با موفقیت ویرایش شد.';
        } elseif ($action === 'delete' && $header_id > 0) {
            $old = $pdo->prepare("SELECT logo_path FROM letter_headers WHERE id = ?");
            $old->execute([$header_id]);
            $old_logo = $old->fetch();
            if ($old_logo && !empty($old_logo['logo_path']) && file_exists($old_logo['logo_path'])) {
                unlink($old_logo['logo_path']);
            }
            $stmt = $pdo->prepare("DELETE FROM letter_headers WHERE id = ?");
            $stmt->execute([$header_id]);
            $_SESSION['success'] = 'سربرگ با موفقیت حذف شد.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در انجام عملیات: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ===== پردازش شماره‌گذاری =====
if (isset($_POST['numbering_action'])) {
    $year = (int)$_POST['year'];
    $start_number = (int)$_POST['start_number'];
    $prefix = trim($_POST['prefix'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    
    try {
        $stmt = $pdo->prepare("UPDATE letter_numbering SET start_number = ?, prefix = ?, suffix = ? WHERE year = ?");
        $stmt->execute([$start_number, $prefix, $suffix, $year]);
        $_SESSION['success'] = 'تنظیمات شماره‌گذاری با موفقیت ذخیره شد.';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در ذخیره تنظیمات: ' . $e->getMessage();
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
    <title>تنظیمات دبیرخانه</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Vazirmatn', sans-serif; background: #f6f8fa; color: #24292f; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .page-header {
            background: #fff;
            padding: 20px 24px;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-header h1 { font-size: 20px; font-weight: 700; }
        .page-header h1 i { color: #0969da; margin-left: 10px; }
        .btn-back {
            padding: 8px 20px;
            background: #f0f2f4;
            color: #57606a;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-back:hover { background: #e1e4e8; }
        
        .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            overflow: hidden;
        }
        .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e1e4e8;
            background: #f8f9fa;
            font-weight: 600;
            font-size: 15px;
        }
        .card-header i { margin-left: 8px; color: #0969da; }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #57606a; margin-bottom: 4px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #e1e4e8;
            font-size: 14px;
            font-family: 'Vazirmatn', sans-serif;
            outline: none;
            transition: 0.2s;
            background: #f8f9fa;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: #0969da;
            box-shadow: 0 0 0 3px rgba(9,105,218,0.1);
        }
        .form-row { display: flex; gap: 12px; flex-wrap: wrap; }
        .form-row .form-group { flex: 1; min-width: 120px; }
        .btn {
            padding: 8px 24px;
            border-radius: 6px;
            border: none;
            font-size: 14px;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-primary { background: #0969da; color: #fff; }
        .btn-primary:hover { background: #0550b3; }
        .btn-success { background: #2da44e; color: #fff; }
        .btn-success:hover { background: #22863a; }
        .btn-danger { background: #cf222e; color: #fff; }
        .btn-danger:hover { background: #a0111f; }
        .btn-secondary { background: #f0f2f4; color: #57606a; }
        .btn-secondary:hover { background: #e1e4e8; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        
        .table-mini { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-mini th {
            text-align: right;
            padding: 8px 10px;
            background: #f8f9fa;
            border-bottom: 1px solid #e1e4e8;
            font-weight: 600;
            color: #57606a;
        }
        .table-mini td { padding: 8px 10px; border-bottom: 1px solid #f0f2f4; }
        .table-mini tr:hover { background: #f8f9fa; }
        .badge-default { background: #d1fae5; color: #065f46; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        
        .info-box {
            background: #eff6ff;
            padding: 12px 16px;
            border-radius: 8px;
            border-right: 4px solid #0969da;
            margin-bottom: 16px;
            font-size: 13px;
            color: #1e40af;
        }
        .logo-preview {
            max-width: 150px;
            max-height: 60px;
            margin-bottom: 8px;
            border: 1px solid #e1e4e8;
            border-radius: 4px;
            padding: 4px;
        }
        .logo-preview img { max-width: 100%; max-height: 60px; }
        .file-upload-wrapper {
            border: 2px dashed #e1e4e8;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: #f8f9fa;
        }
        .file-upload-wrapper:hover {
            border-color: #0969da;
            background: #f0f4ff;
        }
        
        @media (max-width: 768px) {
            .settings-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-cog"></i> تنظیمات دبیرخانه</h1>
            <a href="secretariat.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:6px;"></i>بازگشت</a>
        </div>
        
        <div class="settings-grid">
            <!-- ===== شماره‌گذاری ===== -->
            <div class="card">
                <div class="card-header"><i class="fas fa-sort-numeric-up"></i> شماره‌گذاری نامه‌ها</div>
                <div class="card-body">
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        شماره نامه‌ها به صورت خودکار و بر اساس سال تولید می‌شود.
                        <br>
                        <strong>سال جاری:</strong> <?= $currentYear ?>
                        | <strong>شماره فعلی:</strong> <?= $numbering['current_number'] ?>
                        | <strong>شماره شروع:</strong> <?= $numbering['start_number'] ?>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="numbering_action" value="1">
                        <input type="hidden" name="year" value="<?= $currentYear ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>شماره شروع</label>
                                <input type="number" name="start_number" value="<?= $numbering['start_number'] ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label>پیشوند</label>
                                <input type="text" name="prefix" value="<?= htmlspecialchars($numbering['prefix'] ?? '') ?>" placeholder="مثلاً: A-">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>پسوند</label>
                            <input type="text" name="suffix" value="<?= htmlspecialchars($numbering['suffix'] ?? '') ?>" placeholder="مثلاً: -P">
                        </div>
                        <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
                    </form>
                </div>
            </div>
            
            <!-- ===== سربرگ‌ها ===== -->
            <div class="card">
                <div class="card-header"><i class="fas fa-print"></i> سربرگ‌ها</div>
                <div class="card-body">
                    <div style="max-height:250px;overflow-y:auto;margin-bottom:12px;">
                        <table class="table-mini">
                            <thead>
                                <tr>
                                    <th>عنوان</th>
                                    <th style="width:80px;">لوگو</th>
                                    <th style="width:80px;">پیش‌فرض</th>
                                    <th style="width:100px;">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($headers as $h): ?>
                                <tr>
                                    <td><?= htmlspecialchars($h['title']) ?></td>
                                    <td>
                                        <?php if (!empty($h['logo_path']) && file_exists($h['logo_path'])): ?>
                                            <img src="<?= htmlspecialchars($h['logo_path']) ?>" style="max-height:30px;max-width:60px;">
                                        <?php else: ?>
                                            <span style="color:#8b949e;font-size:11px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $h['is_default'] ? '<span class="badge-default">پیش‌فرض</span>' : '-' ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="editHeader(<?= $h['id'] ?>)">ویرایش</button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteHeader(<?= $h['id'] ?>)">حذف</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" id="headerForm">
                        <input type="hidden" name="header_action" id="header_action" value="add">
                        <input type="hidden" name="header_id" id="header_id" value="0">
                        
                        <div class="form-group">
                            <label>عنوان سربرگ</label>
                            <input type="text" name="title" id="header_title" required>
                        </div>
                        
                        <div class="form-group">
                            <label>آپلود لوگو</label>
                            <div class="file-upload-wrapper" onclick="document.getElementById('logo_upload').click()">
                                <i class="fas fa-image" style="font-size:24px;color:#0969da;display:block;margin-bottom:8px;"></i>
                                <div>برای آپلود لوگو کلیک کنید</div>
                                <div id="logo_name_display" style="font-size:12px;color:#2da44e;display:none;margin-top:6px;"></div>
                                <div id="logo_preview" style="margin-top:8px;display:none;">
                                    <img id="logo_preview_img" src="" style="max-height:60px;max-width:150px;">
                                </div>
                            </div>
                            <input type="file" name="logo_upload" id="logo_upload" accept="image/*" style="display:none;" 
                                   onchange="document.getElementById('logo_name_display').textContent = '📎 ' + this.files[0].name; 
                                           document.getElementById('logo_name_display').style.display = 'block';
                                           var reader = new FileReader();
                                           reader.onload = function(e) {
                                               document.getElementById('logo_preview_img').src = e.target.result;
                                               document.getElementById('logo_preview').style.display = 'block';
                                           };
                                           reader.readAsDataURL(this.files[0]);">
                        </div>
                        
                        <div class="form-group">
                            <label>آدرس</label>
                            <input type="text" name="address" id="header_address">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>تلفن</label>
                                <input type="text" name="phone" id="header_phone">
                            </div>
                            <div class="form-group">
                                <label>فکس</label>
                                <input type="text" name="fax" id="header_fax">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>ایمیل</label>
                                <input type="email" name="email" id="header_email">
                            </div>
                            <div class="form-group">
                                <label>وبسایت</label>
                                <input type="text" name="website" id="header_website">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_default" id="header_default" value="1">
                                سربرگ پیش‌فرض
                            </label>
                        </div>
                        <button type="submit" class="btn btn-success" id="headerSubmitBtn">افزودن سربرگ</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // ===== سربرگ =====
        function editHeader(id) {
            fetch('get_header.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('header_action').value = 'edit';
                        document.getElementById('header_id').value = id;
                        document.getElementById('header_title').value = data.title || '';
                        document.getElementById('header_address').value = data.address || '';
                        document.getElementById('header_phone').value = data.phone || '';
                        document.getElementById('header_fax').value = data.fax || '';
                        document.getElementById('header_email').value = data.email || '';
                        document.getElementById('header_website').value = data.website || '';
                        document.getElementById('header_default').checked = data.is_default == 1;
                        document.getElementById('headerSubmitBtn').textContent = 'ویرایش سربرگ';
                        
                        if (data.logo_path) {
                            document.getElementById('logo_preview_img').src = data.logo_path;
                            document.getElementById('logo_preview').style.display = 'block';
                            document.getElementById('logo_name_display').textContent = '📎 لوگو موجود';
                            document.getElementById('logo_name_display').style.display = 'block';
                        }
                    }
                });
        }
        
        function deleteHeader(id) {
            if (confirm('آیا از حذف این سربرگ مطمئن هستید؟')) {
                document.getElementById('header_action').value = 'delete';
                document.getElementById('header_id').value = id;
                document.getElementById('headerForm').submit();
            }
        }
    </script>
</body>
</html>


==============links.php===============
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'بانک لینک';

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

// ===== دریافت دسته‌بندی‌ها (برای لینک‌ها) =====
$categories = $pdo->query("SELECT * FROM link_categories ORDER BY name")->fetchAll();

// ===== پردازش فیلترها =====
$where = [];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where[] = "(l.title LIKE :search OR l.url LIKE :search OR l.description LIKE :search)";
    $params[':search'] = '%' . $_GET['search'] . '%';
}
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $where[] = "l.category_id = :category";
    $params[':category'] = $_GET['category'];
}
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $where[] = "l.status = :status";
    $params[':status'] = $_GET['status'];
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ===== دریافت کل لینک‌ها =====
$query = "
    SELECT l.*, 
           c.name as category_name, 
           c.color as category_color,
           u.full_name as creator_name
    FROM link_bank l
    LEFT JOIN link_categories c ON l.category_id = c.id
    LEFT JOIN users u ON l.created_by = u.id
    $whereClause
    ORDER BY l.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$links = $stmt->fetchAll();

// ===== آمار =====
$stats = [
    'total' => count($links),
    'active' => count(array_filter($links, fn($a) => $a['status'] == 1)),
    'inactive' => count(array_filter($links, fn($a) => $a['status'] == 0))
];

// ===== پردازش عملیات =====
if (isset($_POST['link_action'])) {
    $action = $_POST['link_action'];
    $link_id = $_POST['link_id'] ?? 0;
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // تاریخ شمسی
    $shamsi_date = isset($_POST['created_date']) ? trim($_POST['created_date']) : jdate('Y/m/d');
    
    try {
        if ($action === 'add' && !empty($title) && !empty($url)) {
            $stmt = $pdo->prepare("INSERT INTO link_bank (title, url, description, category_id, status, username, password, created_by, created_at, shamsi_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->execute([$title, $url, $description, $category_id, $status, $username, $password, $user['id'], $shamsi_date]);
            $_SESSION['success'] = 'لینک با موفقیت اضافه شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'edit' && !empty($title) && !empty($url) && $link_id > 0) {
            $stmt = $pdo->prepare("UPDATE link_bank SET title = ?, url = ?, description = ?, category_id = ?, status = ?, username = ?, password = ?, shamsi_date = ? WHERE id = ?");
            $stmt->execute([$title, $url, $description, $category_id, $status, $username, $password, $shamsi_date, $link_id]);
            $_SESSION['success'] = 'لینک با موفقیت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'change_status' && $link_id > 0) {
            $new_status = isset($_POST['new_status']) ? (int)$_POST['new_status'] : 1;
            $stmt = $pdo->prepare("UPDATE link_bank SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $link_id]);
            $_SESSION['success'] = 'وضعیت لینک با موفقیت تغییر کرد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'delete' && $link_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM link_bank WHERE id = ?");
            $stmt->execute([$link_id]);
            $_SESSION['success'] = 'لینک با موفقیت حذف شد.';
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
    $cat_color = $_POST['cat_color'] ?? '#8250df';
    
    try {
        if ($action === 'add' && !empty($cat_name)) {
            $stmt = $pdo->prepare("INSERT INTO link_categories (name, color) VALUES (?, ?)");
            $stmt->execute([$cat_name, $cat_color]);
            $_SESSION['success'] = 'دسته‌بندی با موفقیت اضافه شد.';
        } elseif ($action === 'edit' && !empty($cat_name) && $cat_id > 0) {
            $stmt = $pdo->prepare("UPDATE link_categories SET name = ?, color = ? WHERE id = ?");
            $stmt->execute([$cat_name, $cat_color, $cat_id]);
            $_SESSION['success'] = 'دسته‌بندی با موفقیت ویرایش شد.';
        } elseif ($action === 'delete' && $cat_id > 0) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM link_bank WHERE category_id = ?");
            $check->execute([$cat_id]);
            if ($check->fetchColumn() > 0) {
                $_SESSION['error'] = 'این دسته‌بندی دارای لینک است و نمی‌توان آن را حذف کرد.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM link_categories WHERE id = ?");
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
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بانک لینک</title>
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

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 28px; }
        .stat-card { padding: 16px 16px 14px; border-radius: 10px; transition: all 0.3s ease; cursor: default; border: 1px solid transparent; }
        .stat-card .stat-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .stat-card .stat-icon { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; background: #ffffff; border: 1px solid; }
        .stat-card .stat-number { font-size: 20px; font-weight: 800; color: #1a1a2e; letter-spacing: -0.3px; line-height: 1.2; }
        .stat-card .stat-label { font-size: 13px; font-weight: 700; color: #1a1a2e; margin-top: 2px; }

        .stat-card.purple-light { background: #f5f0ff; border-color: #d4c4f0; }
        .stat-card.purple-light .stat-icon { border-color: #d4c4f0; color: #8250df; }
        .stat-card.purple-light:hover { box-shadow: 0 8px 30px rgba(130,80,223,0.20); transform: translateY(-3px); border-color: #8250df; }

        .stat-card.green-light { background: #ecfdf3; border-color: #a8e6c1; }
        .stat-card.green-light .stat-icon { border-color: #a8e6c1; color: #2da44e; }
        .stat-card.green-light:hover { box-shadow: 0 8px 30px rgba(45,164,78,0.20); transform: translateY(-3px); border-color: #2da44e; }

        .stat-card.red-light { background: #fef2f2; border-color: #f5c8c8; }
        .stat-card.red-light .stat-icon { border-color: #f5c8c8; color: #cf222e; }
        .stat-card.red-light:hover { box-shadow: 0 8px 30px rgba(207,34,46,0.20); transform: translateY(-3px); border-color: #cf222e; }

        .stat-card.blue-light { background: #eff6ff; border-color: #b8d4f5; }
        .stat-card.blue-light .stat-icon { border-color: #b8d4f5; color: #0969da; }
        .stat-card.blue-light:hover { box-shadow: 0 8px 30px rgba(9,105,218,0.20); transform: translateY(-3px); border-color: #0969da; }

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
            padding: 8px 20px; background: #8250df; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif; display: inline-flex; align-items: center; gap: 6px;
            margin-right: auto;
        }
        .filters-bar .btn-add:hover { background: #6a3fc7; }
        .filters-bar .btn-category {
            padding: 8px 20px; background: #0969da; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: 'Vazirmatn', sans-serif; display: inline-flex; align-items: center; gap: 6px;
        }
        .filters-bar .btn-category:hover { background: #0550b3; }

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

        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
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
        <li><a href="assets.php"><i class="fas fa-boxes"></i> بانک اموال</a></li>
        <li><a href="knowledge.php"><i class="fas fa-database"></i> بانک دانش</a></li>
        <li><a href="links.php" class="active"><i class="fas fa-link"></i> بانک لینک</a></li>
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
                <i class="fas fa-link" style="color: #8250df; margin-left: 10px;"></i>
                بانک لینک
                <span>مدیریت لینک‌های مفید</span>
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
        <div class="stat-card purple-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-link"></i></span><span class="stat-number"><?= $stats['total'] ?></span></div>
            <div class="stat-label">کل لینک‌ها</div>
        </div>
        <div class="stat-card green-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-check-circle"></i></span><span class="stat-number"><?= $stats['active'] ?></span></div>
            <div class="stat-label">فعال</div>
        </div>
        <div class="stat-card red-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-times-circle"></i></span><span class="stat-number"><?= $stats['inactive'] ?></span></div>
            <div class="stat-label">غیرفعال</div>
        </div>
        <div class="stat-card blue-light">
            <div class="stat-top"><span class="stat-icon"><i class="fas fa-tags"></i></span><span class="stat-number"><?= count($categories) ?></span></div>
            <div class="stat-label">دسته‌بندی‌ها</div>
        </div>
    </div>

    <!-- ===== فیلترها ===== -->
    <form class="filters-bar" method="GET" action="">
        <div class="filter-group">
            <input type="text" name="search" placeholder="جستجوی عنوان یا آدرس..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
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
                <option value="1" <?= (isset($_GET['status']) && $_GET['status'] == '1') ? 'selected' : '' ?>>فعال</option>
                <option value="0" <?= (isset($_GET['status']) && $_GET['status'] == '0') ? 'selected' : '' ?>>غیرفعال</option>
            </select>
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> فیلتر</button>
        <a href="links.php" class="btn-reset"><i class="fas fa-undo"></i> پاک کردن</a>
        <button type="button" class="btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> لینک جدید</button>
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
                        <th>آدرس</th>
                        <th style="width:130px;">دسته‌بندی</th>
                        <th style="width:100px;">وضعیت</th>
                        <th style="width:110px;">تاریخ ثبت</th>
                        <th style="width:200px;">اطلاعات ورود</th>
                        <th style="width:180px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($links)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:40px;color:#8b93a5;">هیچ لینکی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($links as $link): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($link['title']) ?></strong>
                                    <?php if (!empty($link['description'])): ?>
                                        <div style="font-size:12px;color:#8b93a5;margin-top:2px;"><?= htmlspecialchars(mb_substr($link['description'], 0, 60)) ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td style="direction:ltr;text-align:left;font-size:13px;color:#0969da;">
                                    <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" style="color:#0969da;text-decoration:none;">
                                        <?= htmlspecialchars(mb_substr($link['url'], 0, 50)) ?>...
                                    </a>
                                </td>
                                <td>
                                    <?php if ($link['category_name']): ?>
                                        <span class="category-badge" style="background:<?= htmlspecialchars($link['category_color'] ?? '#e2e8f0') ?>20;color:<?= htmlspecialchars($link['category_color'] ?? '#475569') ?>;border:1px solid <?= htmlspecialchars($link['category_color'] ?? '#e2e8f0') ?>40;">
                                            <i class="fas fa-folder" style="margin-left:4px;font-size:10px;"></i>
                                            <?= htmlspecialchars($link['category_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#8b93a5;font-size:12px;">دسته‌بندی نشده</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($link['status'] == 1): ?>
                                        <span class="status-badge" style="background:#d1fae5;color:#065f46;">فعال</span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background:#fecaca;color:#991b1b;">غیرفعال</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:13px;color:#57606a;">
                                    <?= !empty($link['shamsi_date']) ? $link['shamsi_date'] : jdate('Y/m/d', strtotime($link['created_at'])) ?>
                                </td>
                                <!-- ===== ستون اطلاعات ورود (نام کاربری و رمز عبور) ===== -->
                                <td>
                                    <div style="display:flex;flex-direction:column;gap:4px;font-size:12px;">
                                        <?php if (!empty($link['username'])): ?>
                                            <div style="display:flex;align-items:center;gap:4px;background:#f8f9fa;padding:2px 8px;border-radius:4px;">
                                                <i class="fas fa-user" style="color:#8250df;font-size:11px;"></i>
                                                <span style="direction:ltr;font-size:11px;"><?= htmlspecialchars($link['username']) ?></span>
                                                <button onclick="copyText('<?= htmlspecialchars($link['username']) ?>')" style="background:none;border:none;cursor:pointer;color:#8b949e;font-size:11px;padding:2px;" title="کپی">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($link['password'])): ?>
                                            <div style="display:flex;align-items:center;gap:4px;background:#f8f9fa;padding:2px 8px;border-radius:4px;">
                                                <i class="fas fa-lock" style="color:#cf222e;font-size:11px;"></i>
                                                <span style="direction:ltr;font-size:11px;" id="pass_<?= $link['id'] ?>">••••••••</span>
                                                <button onclick="togglePass(<?= $link['id'] ?>, '<?= htmlspecialchars($link['password']) ?>')" style="background:none;border:none;cursor:pointer;color:#8b949e;font-size:11px;padding:2px;" title="نمایش/مخفی">
                                                    <i class="fas fa-eye" id="eye_<?= $link['id'] ?>"></i>
                                                </button>
                                                <button onclick="copyText('<?= htmlspecialchars($link['password']) ?>')" style="background:none;border:none;cursor:pointer;color:#8b949e;font-size:11px;padding:2px;" title="کپی">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button class="btn-icon view" onclick="viewLink(<?= $link['id'] ?>)" title="مشاهده">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon edit" onclick="editLink(<?= $link['id'] ?>)" title="ویرایش">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon quick-status" onclick="quickChangeStatus(<?= $link['id'] ?>)" title="تغییر وضعیت">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                        <button class="btn-icon delete" onclick="deleteLink(<?= $link['id'] ?>)" title="حذف">
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

<!-- ===== مودال افزودن/ویرایش لینک ===== -->
<div class="modal-overlay" id="linkModal">
    <div class="modal-box">
        <h2 id="linkModalTitle">لینک جدید</h2>
        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="link_action" id="link_action" value="add">
            <input type="hidden" name="link_id" id="link_id" value="0">
            
            <div class="form-group">
                <label>تاریخ ثبت <span style="color:#8b949e;font-weight:400;">(شمسی)</span></label>
                <input type="text" name="created_date" id="created_date" class="persian-date" placeholder="تاریخ ثبت" value="<?= jdate('Y/m/d') ?>" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label>عنوان لینک</label>
                <input type="text" name="title" id="link_title" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>آدرس (URL)</label>
                <input type="url" name="url" id="link_url" required autocomplete="off" placeholder="https://example.com">
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <textarea name="description" id="link_description" rows="2"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>دسته‌بندی</label>
                    <select name="category_id" id="link_category">
                        <option value="">بدون دسته‌بندی</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>وضعیت</label>
                    <select name="status" id="link_status">
                        <option value="1">فعال</option>
                        <option value="0">غیرفعال</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>نام کاربری (اختیاری)</label>
                    <input type="text" name="username" id="link_username" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>رمز عبور (اختیاری)</label>
                    <input type="text" name="password" id="link_password" autocomplete="off">
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('linkModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره لینک</button>
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
                            <span style="display:inline-block;width:24px;height:24px;border-radius:50%;background:<?= htmlspecialchars($cat['color'] ?? '#8250df') ?>;border:1px solid #ddd;"></span>
                        </td>
                        <td style="padding:8px 10px;text-align:center;">
                            <button class="btn-icon edit" onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name']) ?>', '<?= htmlspecialchars($cat['color'] ?? '#8250df') ?>')"><i class="fas fa-pen"></i></button>
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
                    <input type="color" name="cat_color" id="cat_color" value="#8250df" style="padding:2px;height:42px;cursor:pointer;">
                </div>
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
        <h2>تغییر وضعیت لینک</h2>
        <form method="POST" action="">
            <input type="hidden" name="link_action" value="change_status">
            <input type="hidden" name="link_id" id="quick_status_link_id" value="0">
            <div class="form-group">
                <label>وضعیت جدید</label>
                <select name="new_status" id="quick_status_select">
                    <option value="1">فعال</option>
                    <option value="0">غیرفعال</option>
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

    // ===== تابع کپی متن =====
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showToast('✅ متن با موفقیت کپی شد!');
            }).catch(function() {
                fallbackCopyText(text);
            });
        } else {
            fallbackCopyText(text);
        }
    }

    function fallbackCopyText(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showToast('✅ متن با موفقیت کپی شد!');
        } catch (err) {
            showToast('❌ خطا در کپی متن');
        }
        document.body.removeChild(textarea);
    }

    function showToast(message) {
        var toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1a1a2e;color:#fff;padding:12px 24px;border-radius:8px;font-family:Vazirmatn,sans-serif;font-size:14px;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,0.2);transition:opacity 0.3s;';
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() {
                document.body.removeChild(toast);
            }, 300);
        }, 2000);
    }

    function togglePass(id, password) {
        var span = document.getElementById('pass_' + id);
        var eye = document.getElementById('eye_' + id);
        if (span.textContent === '••••••••') {
            span.textContent = password;
            eye.className = 'fas fa-eye-slash';
        } else {
            span.textContent = '••••••••';
            eye.className = 'fas fa-eye';
        }
    }

    // ===== لینک =====
    function openAddModal() {
        document.getElementById('linkModalTitle').textContent = 'لینک جدید';
        document.getElementById('link_action').value = 'add';
        document.getElementById('link_id').value = 0;
        document.getElementById('link_title').value = '';
        document.getElementById('link_url').value = '';
        document.getElementById('link_description').value = '';
        document.getElementById('link_category').value = '';
        document.getElementById('link_status').value = '1';
        document.getElementById('link_username').value = '';
        document.getElementById('link_password').value = '';
        document.getElementById('created_date').value = '<?= jdate('Y/m/d') ?>';
        document.getElementById('linkModal').classList.add('active');
    }

    function editLink(id) {
        fetch('get_link.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('linkModalTitle').textContent = 'ویرایش لینک';
                    document.getElementById('link_action').value = 'edit';
                    document.getElementById('link_id').value = id;
                    document.getElementById('link_title').value = data.title || '';
                    document.getElementById('link_url').value = data.url || '';
                    document.getElementById('link_description').value = data.description || '';
                    document.getElementById('link_category').value = data.category_id || '';
                    document.getElementById('link_status').value = data.status || '1';
                    document.getElementById('link_username').value = data.username || '';
                    document.getElementById('link_password').value = data.password || '';
                    document.getElementById('created_date').value = data.shamsi_date || '<?= jdate('Y/m/d') ?>';
                    document.getElementById('linkModal').classList.add('active');
                } else {
                    alert('خطا در دریافت اطلاعات لینک: ' + (data.error || 'نامشخص'));
                }
            })
            .catch(function() {
                alert('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
            });
    }

    function viewLink(id) {
        window.open('view_link.php?id=' + id, '_blank');
    }

    function deleteLink(id) {
        if (confirm('آیا از حذف این لینک مطمئن هستید؟ این عملیات غیرقابل بازگشت است!')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            var input1 = document.createElement('input');
            input1.type = 'hidden';
            input1.name = 'link_action';
            input1.value = 'delete';
            var input2 = document.createElement('input');
            input2.type = 'hidden';
            input2.name = 'link_id';
            input2.value = id;
            form.appendChild(input1);
            form.appendChild(input2);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function quickChangeStatus(id) {
        document.getElementById('quick_status_link_id').value = id;
        document.getElementById('quick_status_select').value = '1';
        document.getElementById('quickStatusModal').classList.add('active');
    }

    // ===== دسته‌بندی =====
    function openCategoryModal() {
        document.getElementById('category_action').value = 'add';
        document.getElementById('cat_id').value = 0;
        document.getElementById('cat_name').value = '';
        document.getElementById('cat_color').value = '#8250df';
        document.getElementById('categorySubmitBtn').textContent = 'افزودن دسته‌بندی';
        document.getElementById('categoryModal').classList.add('active');
    }

    function editCategory(id, name, color) {
        document.getElementById('category_action').value = 'edit';
        document.getElementById('cat_id').value = id;
        document.getElementById('cat_name').value = name;
        document.getElementById('cat_color').value = color;
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

===============loading.php=============
<!DOCTYPE html>
<html>
<head>
    <style>
        #loader {
            position: fixed;
            inset: 0;
            background: var(--bg-primary);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: opacity 0.5s;
        }
        #loader.hide {
            opacity: 0;
            pointer-events: none;
        }
        .loader-bar {
            width: 300px;
            height: 4px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }
        .loader-bar .progress {
            height: 100%;
            background: linear-gradient(90deg, #6C63FF, #FF6584);
            animation: loadProgress 2s ease-in-out forwards;
        }
        @keyframes loadProgress {
            0% { width: 0%; }
            100% { width: 90%; }
        }
        .loader-text {
            margin-top: 20px;
            color: var(--text-secondary);
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div id="loader">
        <div class="loader-bar">
            <div class="progress"></div>
        </div>
        <div class="loader-text">در حال بارگذاری سامانه...</div>
    </div>
    <script>
        window.addEventListener('load', () => {
            setTimeout(() => {
                document.getElementById('loader').classList.add('hide');
            }, 1500);
        });
    </script>
</body>
</html>

================logout.php==============
<?php
require_once 'config.php';
require_once 'functions.php';

if (isLoggedIn()) {
    logActivity($_SESSION['user_id'], 'auth', 'logout', 'کاربر از سیستم خارج شد');
}

$_SESSION = array();
session_destroy();
header('Location: index.php');
exit();
?>

=================print_expense.php=============
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die('شناسه هزینه نامعتبر است');

// تابع تبدیل اعداد به فارسی
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

// دریافت اطلاعات هزینه
$stmt = $pdo->prepare("
    SELECT te.*,
           u.full_name as user_name,
           u.national_id as user_national_id,
           p.name as project_name,
           ap.full_name as approved_by_name,
           op.name as origin_province,
           oc.name as origin_city,
           dp.name as destination_province,
           dc.name as destination_city,
           cr.full_name as created_by_name
    FROM travel_expenses te
    LEFT JOIN users u ON te.user_id = u.id
    LEFT JOIN projects p ON te.project_id = p.id
    LEFT JOIN users ap ON te.approved_by = ap.id
    LEFT JOIN provinces op ON te.origin_province_id = op.id
    LEFT JOIN cities oc ON te.origin_city_id = oc.id
    LEFT JOIN provinces dp ON te.destination_province_id = dp.id
    LEFT JOIN cities dc ON te.destination_city_id = dc.id
    LEFT JOIN users cr ON te.created_by = cr.id
    WHERE te.id = ?
");
$stmt->execute([$id]);
$expense = $stmt->fetch();

if (!$expense) die('هزینه‌ای با این شناسه یافت نشد');

// دریافت ردیف‌های هزینه
$items = $pdo->prepare("
    SELECT ei.*, ec.name as category_name
    FROM expense_items ei
    LEFT JOIN expense_categories ec ON ei.category_id = ec.id
    WHERE ei.expense_id = ?
    ORDER BY ei.id
");
$items->execute([$id]);
$items = $items->fetchAll();

$totalAmount = array_sum(array_column($items, 'amount'));

// ===== تابع تبدیل عدد به حروف =====
function numberToWords($num) {
    if ($num == 0) return 'صفر';
    if ($num < 0) return 'منفی ' . numberToWords(abs($num));
    
    $units = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $hundreds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    $thousands = ['', 'هزار', 'میلیون', 'میلیارد'];
    
    $num = (int)$num;
    if ($num < 1000) {
        $h = floor($num / 100);
        $r = $num % 100;
        $words = '';
        if ($h > 0) $words .= $hundreds[$h] . ' ';
        if ($r >= 20) {
            $t = floor($r / 10);
            $u = $r % 10;
            $words .= $tens[$t];
            if ($u > 0) $words .= ' و ' . $units[$u];
        } elseif ($r > 0 && $r < 10) {
            $words .= $units[$r];
        } elseif ($r >= 10 && $r < 20) {
            $words .= $teens[$r - 10];
        }
        return trim($words);
    }
    
    $parts = [];
    $temp = $num;
    $i = 0;
    while ($temp > 0) {
        $part = $temp % 1000;
        if ($part > 0) {
            $parts[$i] = $part;
        }
        $temp = floor($temp / 1000);
        $i++;
    }
    
    $result = [];
    foreach ($parts as $index => $part) {
        $partWords = '';
        $h = floor($part / 100);
        $r = $part % 100;
        if ($h > 0) $partWords .= $hundreds[$h] . ' ';
        if ($r >= 20) {
            $t = floor($r / 10);
            $u = $r % 10;
            $partWords .= $tens[$t];
            if ($u > 0) $partWords .= ' و ' . $units[$u];
        } elseif ($r > 0 && $r < 10) {
            $partWords .= $units[$r];
        } elseif ($r >= 10 && $r < 20) {
            $partWords .= $teens[$r - 10];
        }
        if (!empty($partWords)) {
            $result[] = trim($partWords) . ' ' . $thousands[$index];
        }
    }
    $words = implode(' و ', array_reverse($result));
    return trim($words);
}

function getPaymentTypeLabel($type) {
    $labels = ['cash'=>'نقدی', 'non_cash'=>'غیرنقدی', 'card'=>'کارت بانکی', 'check'=>'چک', 'transfer'=>'اینترنتی'];
    return $labels[$type] ?? $type;
}

function getDocumentTypeLabel($type) {
    $labels = ['invoice'=>'فاکتور', 'receipt'=>'رسید', 'bill'=>'قبض', 'other'=>'سایر'];
    return $labels[$type] ?? $type;
}

function getStatusLabel($status) {
    $labels = ['pending'=>'در انتظار تایید', 'approved'=>'تایید شده', 'rejected'=>'رد شده'];
    return $labels[$status] ?? $status;
}

function getStatusColor($status) {
    $colors = ['pending'=>'#fef3c7', 'approved'=>'#d1fae5', 'rejected'=>'#fecaca'];
    return $colors[$status] ?? '#f3f4f6';
}

function getStatusTextColor($status) {
    $colors = ['pending'=>'#92400e', 'approved'=>'#065f46', 'rejected'=>'#991b1b'];
    return $colors[$status] ?? '#6b7280';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرم هزینه ماموریت</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #e8ecf0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .paper-a5 {
            width: 148mm;
            min-height: 210mm;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 8mm 6mm;
            position: relative;
            direction: rtl;
            font-size: 12px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0969da;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .header .company {
            font-size: 16px;
            font-weight: 800;
            color: #1a2332;
        }
        .header .company i { color: #0969da; }
        .header .title {
            font-size: 18px;
            font-weight: 700;
            color: #0969da;
            margin-top: 2px;
        }
        .header .sub {
            font-size: 11px;
            color: #57606a;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .main-table td, .main-table th {
            border: 1px solid #1a2332;
            padding: 5px 8px;
            vertical-align: middle;
            font-size: 12px;
        }
        .main-table .label-cell {
            background: #f8f9fa;
            font-weight: 600;
            white-space: nowrap;
            width: 20%;
        }
        .main-table .value-cell {
            font-weight: 500;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        .items-table th {
            background: #f8f9fa;
            border: 1px solid #1a2332;
            padding: 4px 6px;
            text-align: center;
            font-weight: 700;
            font-size: 11px;
        }
        .items-table td {
            border: 1px solid #1a2332;
            padding: 4px 6px;
            text-align: center;
            font-size: 11px;
        }
        .items-table .total-row {
            background: #eff6ff;
            font-weight: 700;
        }
        .total-box {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            padding: 6px 10px;
            background: #0969da;
            color: #fff;
            border-radius: 4px;
            font-weight: 700;
            font-size: 12px;
        }
        .total-box .words {
            font-size: 11px;
            font-weight: 400;
        }
        .companions-box {
            margin-top: 8px;
            padding: 6px 10px;
            background: #f8f9fa;
            border-radius: 4px;
            border-right: 4px solid #0969da;
        }
        .companions-box .label {
            font-weight: 600;
            font-size: 12px;
        }
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-top: 14px;
            padding-top: 10px;
            border-top: 2px solid #1a2332;
        }
        .signature-item {
            text-align: center;
        }
        .signature-item .line {
            border-bottom: 2px solid #1a2332;
            height: 35px;
            margin-bottom: 4px;
        }
        .signature-item .label {
            font-size: 11px;
            color: #57606a;
            font-weight: 500;
        }
        .signature-item .name {
            font-size: 12px;
            color: #1a2332;
            font-weight: 700;
            margin-top: 2px;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .footer-text {
            text-align: center;
            font-size: 9px;
            color: #8b949e;
            margin-top: 10px;
            border-top: 1px solid #e8ecf0;
            padding-top: 6px;
        }
        .print-btn {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: #0969da;
            color: #fff;
            border: none;
            border-radius: 50px;
            padding: 12px 32px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(9,105,218,0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 1000;
        }
        .print-btn:hover {
            transform: translateX(-50%) translateY(-2px);
            box-shadow: 0 8px 30px rgba(9,105,218,0.5);
            background: #0550b3;
        }
        
        /* ===== اصلاح استایل چاپ ===== */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .paper-a5 {
                width: 100% !important;
                min-height: auto !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                padding: 6mm 5mm !important;
                background: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .print-btn {
                display: none !important;
            }
            .signature-item .line {
                height: 25px !important;
            }
            .main-table td, .main-table th {
                border-color: #1a2332 !important;
            }
            .items-table th, .items-table td {
                border-color: #1a2332 !important;
            }
            .total-box {
                background: #0969da !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .status-badge {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
        
        @page {
            size: A5;
            margin: 4mm;
        }
    </style>
</head>
<body>

<div class="paper-a5" id="printArea">
    <div class="header">
        <div class="company"><i class="fas fa-heartbeat"></i> شرکت دانش بنیان پیشگامان دنیای فناوری</div>
        <div class="title">فرم هزینه ماموریت</div>
        <div class="sub">پرونده الکترونیک سلامت</div>
    </div>

    <table class="main-table">
        <tr>
            <td class="label-cell">نام و نام خانوادگی</td>
            <td class="value-cell" colspan="3"><?= htmlspecialchars($expense['full_name'] ?? $expense['user_name'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="label-cell">تاریخ شروع</td>
            <td class="value-cell"><?= persian_number_str($expense['travel_date']) ?></td>
            <td class="label-cell" style="width:15%;">تاریخ اتمام</td>
            <td class="value-cell"><?= persian_number_str($expense['end_date'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="label-cell">مبدا</td>
            <td class="value-cell">
                <?php if ($expense['origin_city']): ?>
                    <?= htmlspecialchars($expense['origin_city']) ?>
                    <?php if ($expense['origin_province']): ?> (<?= htmlspecialchars($expense['origin_province']) ?>)<?php endif; ?>
                <?php else: ?>-<?php endif; ?>
            </td>
            <td class="label-cell">مقصد</td>
            <td class="value-cell">
                <?php if ($expense['destination_city']): ?>
                    <?= htmlspecialchars($expense['destination_city']) ?>
                    <?php if ($expense['destination_province']): ?> (<?= htmlspecialchars($expense['destination_province']) ?>)<?php endif; ?>
                <?php else: ?>-<?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="label-cell">نام پروژه</td>
            <td class="value-cell" colspan="3"><?= htmlspecialchars($expense['project_name'] ?? '-') ?></td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:12%;">هزینه (ریال)</th>
                <th style="width:14%;">دسته بندی</th>
                <th style="width:14%;">نوع پرداخت</th>
                <th style="width:18%;">نوع سند</th>
                <th style="width:42%;">توضیحات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" style="text-align:center;color:#57606a;">هیچ ردیف هزینه‌ای ثبت نشده است.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= persian_number(number_format($item['amount'])) ?></td>
                    <td><?= htmlspecialchars($item['category_name'] ?? '-') ?></td>
                    <td><?= getPaymentTypeLabel($item['payment_type']) ?></td>
                    <td><?= getDocumentTypeLabel($item['document_type']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($item['description'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="total-box">
        <span>جمع کل به حروف: <span class="words"><?= numberToWords($totalAmount) ?> ریال</span></span>
        <span>جمع کل به عدد: <?= persian_number(number_format($totalAmount)) ?> ریال</span>
    </div>

    <div class="companions-box">
        <span class="label"><i class="fas fa-users"></i> نام و نام خانوادگی افراد همراه:</span>
        <span><?= htmlspecialchars($expense['companions'] ?? '-') ?></span>
    </div>

    <div style="margin-top:6px;text-align:center;">
        <span class="status-badge" style="background:<?= getStatusColor($expense['status']) ?>;color:<?= getStatusTextColor($expense['status']) ?>;">
            وضعیت: <?= getStatusLabel($expense['status']) ?>
            <?php if ($expense['approved_by_name']): ?>
                (تایید کننده: <?= htmlspecialchars($expense['approved_by_name']) ?>)
            <?php endif; ?>
        </span>
    </div>

    <div class="signatures">
        <div class="signature-item">
            <div class="line"></div>
            <div class="label">امضای متقاضی</div>
            <div class="name"><?= htmlspecialchars($expense['full_name'] ?? $expense['user_name'] ?? '') ?></div>
        </div>
        <div class="signature-item">
            <div class="line"></div>
            <div class="label">امضای مدیر مالی</div>
            <div class="name"><?= $expense['status'] === 'approved' ? htmlspecialchars($expense['approved_by_name'] ?? '') : '.............................' ?></div>
        </div>
        <div class="signature-item">
            <div class="line"></div>
            <div class="label">مهر سازمان</div>
            <div class="name" style="font-size:10px;color:#0969da;">.............................</div>
        </div>
    </div>

 
</div>

<button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> پرینت فرم</button>

</body>
</html>

================print_letter.php=================
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه نامه مشخص نشده است');
}

$id = (int)$_GET['id'];

$query = "
    SELECT l.*, 
           u.full_name as creator_name,
           h.title as header_title,
           h.logo_path as header_logo,
           h.address as header_address,
           h.phone as header_phone,
           h.fax as header_fax,
           h.email as header_email,
           h.website as header_website,
           s.name as signatory_name,
           s.image_path as signature_image,
           s.position as signatory_position
    FROM letters l
    LEFT JOIN users u ON l.created_by = u.id
    LEFT JOIN letter_headers h ON l.header_id = h.id
    LEFT JOIN signatures s ON l.signature_id = s.id
    WHERE l.id = ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$letter = $stmt->fetch();

if (!$letter) {
    die('نامه یافت نشد');
}

$header_image = 'uploads/letter_headers/a4_header.jpg';
$stamp_image = 'uploads/signatures/stamp.png';

$has_attachment = $letter['has_attachment'] ?? 0;
$attachment_text = $has_attachment ? 'دارد' : 'ندارد';

$content = $letter['content'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>چاپ نامه</title>
    <style>
        @font-face {
            font-family: 'BNazanin';
            src: url('fonts/B-NAZANIN.TTF') format('truetype');
        }
        
        @page { 
            size: A4; 
            margin: 0; 
        }
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        body { 
            margin: 0; 
            padding: 0; 
            font-family: 'BNazanin', 'B Nazanin', 'Tahoma', sans-serif; 
            background: #fff; 
        }
        
        .page { 
            width: 210mm; 
            height: 297mm; 
            position: relative; 
            background: #fff; 
            overflow: hidden; 
            margin: 0 auto;
        }
        
        /* ===== سربرگ ===== */
        .page .header-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            line-height: 0;
            font-size: 0;
        }
        
        .page .header-bg img {
            width: 100%;
            height: 100%;
            object-fit: fill;
            display: block;
        }
        
        .page .overlay { 
            position: absolute; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            z-index: 1; 
            padding: 0; 
        }
        
        /* ===== اطلاعات بالای نامه ===== */
        .page .overlay .top-info {
            position: absolute;
            top: 18mm;
            right: 25mm;
            left: 14mm;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 3px;
            font-size: 14px;
            font-weight: bold;
            color: #1a1a2e;
            padding-bottom: 5px;
            direction: ltr;
        }
        
        .page .overlay .top-info .info-item {
            display: flex;
            align-items: center;
            gap: 5px;
            direction: ltr;
            font-weight: bold;
        }
        
        .page .overlay .top-info .info-item .value {
            font-weight: bold;
            color: #333;
        }
        
        .page .overlay .top-info .date-item {
            margin-top: 0px;
        }
        
        .page .overlay .top-info .number-item {
            margin-top: 8px;
        }
        
        .page .overlay .top-info .attachment-item {
            margin-top: 8px;
        }
        
          /* ===== کانتینر اصلی متن (قابل تنظیم دستی) ===== */
        .page .overlay .main-content {
            position: absolute;
            /* ===== تنظیمات دستی موقعیت متن ===== */
            top: 40mm;      /* فاصله از بالا - افزایش = پایین‌تر */
            left: 25mm;     /* فاصله از چپ - افزایش = راست‌تر */
            right: 33mm;    /* فاصله از راست - افزایش = چپ‌تر */
            bottom: 10mm;   /* فاصله از پایین - افزایش = بالاتر */
            /* =================== */
            display: flex;
            flex-direction: column;
            padding-bottom: 15mm;
        }
        
        /* ===== متن نامه ===== */
        .page .overlay .main-content .content {
            line-height: 2.2;
            font-size: 14px;
            text-align: justify;
            padding: 10px 0 5px 0;
            flex-shrink: 0;
            width: 100%;
            direction: rtl;
        }
        
        /* ===== فاصله 2 سانتی ===== */
        .page .overlay .main-content .spacer {
            flex-shrink: 0;
            height: 20mm;
        }
        
        /* ===== بخش پایین ===== */
        .page .overlay .main-content .bottom-section {
            flex-shrink: 0;
            width: 100%;
            padding: 5px 0;
            position: relative;
            margin-top: -20mm;
            display: flex;
            justify-content: flex-start;
        }
        
        .page .overlay .main-content .bottom-section .signature-group {
            position: relative;
            display: inline-block;
            direction: rtl;
            text-align: right;
        }
        
        /* ===== متن ===== */
        .page .overlay .main-content .bottom-section .signature-group .text-box {
            padding: 5px 20px 5px 15px;
            background: transparent;
            text-align: right;
            line-height: 2.2;
            border: none;
            box-shadow: none;
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .text-box .prayer {
            font-family: 'B Titr', 'BNazanin', 'B Nazanin', 'Tahoma', sans-serif;
            font-size: 14px;
            font-weight: bold;
            color: #1a1a2e;
            text-align: center;
            margin-bottom: 2px;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .text-box .company {
            font-family: 'B Titr', 'BNazanin', 'B Nazanin', 'Tahoma', sans-serif;
            font-size: 12px;
            font-weight: bold;
            color: #1a1a2e;
            text-align: center;
        }
        
        /* ===== امضا ===== */
        .page .overlay .main-content .bottom-section .signature-group .signature-box {
            position: absolute;
            top: 50%;
            left: -35px;
            transform: translateY(-50%);
            text-align: center;
            z-index: 2;
            width: 40mm;
            height: 40mm;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .signature-box .signature-img {
            width: 35mm;
            height: 35mm;
            object-fit: contain;
            display: block;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .signature-box .line {
            width: 80px;
            border-bottom: 1px solid #000;
            margin: 2px auto;
        }
        
        /* ===== مهر ===== */
        .page .overlay .main-content .bottom-section .signature-group .stamp-box {
            position: absolute;
            right: -50px;
            top: 50%;
            transform: translateY(-50%);
            width: 40mm;
            height: 40mm;
            z-index: 2;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .stamp-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }
        
        /* ===== پاورقی - حذف شده ===== */
        /* دیگر پاورقی وجود ندارد */
        
        .no-print {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 999;
            display: flex;
            gap: 10px;
            background: rgba(255,255,255,0.95);
            padding: 12px 24px;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
        }
        
        .no-print button { 
            padding: 10px 30px; 
            border: none; 
            border-radius: 6px; 
            font-size: 14px; 
            cursor: pointer; 
            font-family: 'BNazanin', 'B Nazanin', 'Tahoma', sans-serif; 
            font-weight: bold;
        }
        
        .no-print .btn-print { background: #0969da; color: #fff; }
        .no-print .btn-close { background: #f0f2f4; color: #57606a; }
        
        @media print {
            body { 
                background: #fff;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .no-print { display: none !important; }
            
            .page { 
                box-shadow: none;
                margin: 0 !important;
                padding: 0 !important;
                width: 210mm !important;
                height: 297mm !important;
            }
            
            .page .header-bg {
                width: 100% !important;
                height: 100% !important;
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
            }
            
            .page .header-bg img {
                width: 100% !important;
                height: 100% !important;
                object-fit: fill !important;
                display: block !important;
            }
        }
    </style>
</head>
<body>
    <div class="page" id="printArea">
        
        <!-- ===== سربرگ ===== -->
        <div class="header-bg">
            <img src="<?= $header_image ?>" alt="سربرگ">
        </div>
        
        <div class="overlay">
            
            <!-- ===== اطلاعات بالای نامه ===== -->
            <div class="top-info">
                <div class="info-item date-item">
                    <span class="value"><?= htmlspecialchars(explode(' ', $letter['shamsi_letter_date'] ?? '')[0]) ?></span>
                </div>
                
                <div class="info-item number-item">
                    <span class="value"><?= htmlspecialchars($letter['letter_number'] ?? '') ?></span>
                </div>
                
                <div class="info-item attachment-item">
                    <span class="value"><?= $attachment_text ?></span>
                </div>
            </div>

            <div class="main-content">
                
                <!-- ===== متن نامه ===== -->
                <div class="content">
                    <?= $content ?>
                </div>

                <!-- ===== فاصله 2 سانتی متری ===== -->
                <div class="spacer"></div>

                <!-- ===== بخش پایین ===== -->
                <div class="bottom-section">
                    <div class="signature-group">
                        <!-- متن -->
                        <div class="text-box">
                            <div class="prayer">و من ا... توفیق</div>
                            <div class="company">شرکت دانش بنیان پیشگامان دنیای فناوری</div>
                        </div>
                        
                        <!-- امضا -->
                        <div class="signature-box">
                            <?php if (!empty($letter['signature_image']) && file_exists($letter['signature_image'])): ?>
                                <img src="<?= $letter['signature_image'] ?>" class="signature-img" alt="امضاء">
                            <?php else: ?>
                                <div class="line"></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- مهر -->
                        <div class="stamp-box">
                            <img src="<?= $stamp_image ?>" alt="مهر شرکت">
                        </div>
                    </div>
                </div>
                
            </div>

            <!-- ===== پاورقی حذف شد ===== -->

        </div>
    </div>

    <div class="no-print">
        <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> چاپ</button>
        <button class="btn-close" onclick="window.close()"><i class="fas fa-times"></i> بستن</button>
    </div>
    
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>


============profile.php=============

<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

$user = getCurrentUser();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name']);
    $password = $_POST['password'] ?? '';
    
    if (empty($full_name)) {
        $message = 'نام کامل را وارد کنید.';
        $messageType = 'danger';
    } else {
        try {
            if (!empty($password)) {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$full_name, hashPassword($password), $_SESSION['user_id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?");
                $stmt->execute([$full_name, $_SESSION['user_id']]);
            }
            $message = 'پروفایل با موفقیت به‌روزرسانی شد.';
            $messageType = 'success';
            logActivity($_SESSION['user_id'], 'profile', 'update', 'پروفایل ویرایش شد');
            
            // به‌روزرسانی سشن
            $_SESSION['full_name'] = $full_name;
        } catch (PDOException $e) {
            $message = 'خطا: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پروفایل کاربر</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; font-family: 'Tahoma', 'IRANSans', sans-serif; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%); padding: 20px; color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 15px; border-radius: 8px; margin-bottom: 5px; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .sidebar .nav-link i { margin-left: 10px; width: 20px; }
        .main-content { padding: 30px; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 2px solid #f0f2f5; padding: 15px 20px; font-weight: bold; border-radius: 15px 15px 0 0 !important; }
        .avatar-circle { width: 40px; height: 40px; background: #3498db; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 18px; }
        .profile-avatar { width: 120px; height: 120px; background: #3498db; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 48px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block sidebar p-0">
                <div class="p-3 text-center border-bottom border-secondary">
                    <div class="avatar-circle mx-auto mb-2" style="width:60px;height:60px;font-size:24px;">
                        <?php echo mb_substr($user['full_name'] ?? 'کاربر', 0, 1); ?>
                    </div>
                    <h6 class="text-white mb-0"><?php echo htmlspecialchars($user['full_name'] ?? 'کاربر'); ?></h6>
                    <small class="text-secondary">مدیر سیستم</small>
                </div>
                <ul class="nav flex-column p-3">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-chart-pie"></i> داشبورد</a></li>
                    <li class="nav-item"><a class="nav-link" href="users.php"><i class="fas fa-users"></i> مدیریت کاربران</a></li>
                    <li class="nav-item"><a class="nav-link active" href="profile.php"><i class="fas fa-user"></i> پروفایل</a></li>
                    <li class="nav-item mt-3"><a class="nav-link text-danger" href="logout.php"><i class="fas fa-sign-out-alt"></i> خروج</a></li>
                </ul>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <h4 class="mb-4"><i class="fas fa-user me-2"></i> پروفایل کاربر</h4>
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="card text-center p-4">
                            <div class="profile-avatar">
                                <?php echo mb_substr($user['full_name'] ?? 'کاربر', 0, 1); ?>
                            </div>
                            <h5 class="mt-3"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                            <p class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></p>
                            <div class="mt-2">
                                <span class="badge <?php echo $user['status'] == 1 ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo $user['status'] == 1 ? 'فعال' : 'غیرفعال'; ?>
                                </span>
                            </div>
                            <p class="mt-3 text-muted small">
                                <i class="fas fa-calendar me-1"></i>
                                عضو از: <?php echo date('Y/m/d', strtotime($user['created_at'])); ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-edit me-2"></i> ویرایش پروفایل
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label">نام کاربری</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                                        <small class="text-muted">نام کاربری قابل تغییر نیست.</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">نام کامل</label>
                                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">رمز عبور جدید (اختیاری)</label>
                                        <input type="password" name="password" class="form-control" placeholder="برای تغییر رمز، جدید را وارد کنید">
                                        <small class="text-muted">اگر نمی‌خواهید رمز را تغییر دهید، خالی بگذارید.</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> ذخیره تغییرات
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


================reminder.php================
<?php
// ============================================
// ماژول یادآور حرفه ای - نسخه کامل با تمام مودال‌ها
// ============================================

require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
require_once 'includes/ReminderService.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'یادآور';

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

// ===== دریافت نقش‌های کاربر =====
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

$reminderService = new ReminderService($pdo);

// ===== دریافت اطلاعات پایه =====
$categories = $pdo->query("SELECT * FROM reminder_categories ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name, national_id, email, mobile, telegram_id FROM users WHERE status = 1 ORDER BY full_name")->fetchAll();
$groups = $pdo->query("SELECT * FROM user_groups ORDER BY name")->fetchAll();

// ===== دریافت تنظیمات =====
$sms_settings = $pdo->query("SELECT * FROM sms_settings WHERE status = 1 AND is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$sms_settings) {
    $sms_settings = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'sms_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
}

$email_settings = $pdo->query("SELECT * FROM settings WHERE setting_key LIKE 'email_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$templates = $pdo->query("SELECT * FROM reminder_templates ORDER BY name")->fetchAll();

// ===== دریافت آخرین فعالیت‌ها (فقط ۴ مورد) =====
$lastActivities = $pdo->query("
    SELECT * FROM activity_logs 
    WHERE module = 'reminder' 
    ORDER BY created_at DESC 
    LIMIT 4
")->fetchAll();

// ===== توابع کمکی =====
function getStatusStyle($status) {
    $styles = [
        'active' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'label' => 'فعال', 'icon' => 'fa-clock'],
        'done' => ['bg' => '#d1fae5', 'color' => '#065f46', 'label' => 'انجام شده', 'icon' => 'fa-check-circle'],
        'expired' => ['bg' => '#fecaca', 'color' => '#991b1b', 'label' => 'منقضی شده', 'icon' => 'fa-times-circle'],
        'canceled' => ['bg' => '#f3f4f6', 'color' => '#6b7280', 'label' => 'لغو شده', 'icon' => 'fa-ban']
    ];
    return $styles[$status] ?? $styles['active'];
}

function getPriorityStyle($priority) {
    $styles = [
        'low' => ['bg' => '#d1fae5', 'color' => '#065f46', 'label' => 'کم', 'icon' => 'fa-arrow-down'],
        'medium' => ['bg' => '#fef3c7', 'color' => '#92400e', 'label' => 'متوسط', 'icon' => 'fa-minus'],
        'high' => ['bg' => '#fecaca', 'color' => '#991b1b', 'label' => 'زیاد', 'icon' => 'fa-arrow-up'],
        'urgent' => ['bg' => '#fee2e2', 'color' => '#dc2626', 'label' => 'فوری', 'icon' => 'fa-exclamation-circle']
    ];
    return $styles[$priority] ?? $styles['medium'];
}

function getRepeatLabel($type) {
    $labels = [
        'none' => 'بدون تکرار',
        'daily' => 'روزانه',
        'weekly' => 'هفتگی',
        'monthly' => 'ماهانه',
        'yearly' => 'سالانه'
    ];
    return $labels[$type] ?? $type;
}

// ===== توابع فعالیت‌ها =====
function getActivityStyle($action, $module = 'reminder') {
    $styles = [
        'add' => ['color' => '#0f9d58', 'bg' => '#d1fae5', 'icon' => 'fa-plus-circle', 'label' => 'افزودن'],
        'edit' => ['color' => '#0969da', 'bg' => '#dbeafe', 'icon' => 'fa-edit', 'label' => 'ویرایش'],
        'delete' => ['color' => '#cf222e', 'bg' => '#fecaca', 'icon' => 'fa-trash', 'label' => 'حذف'],
        'send' => ['color' => '#0f9d58', 'bg' => '#d1fae5', 'icon' => 'fa-check-circle', 'label' => 'ارسال شد'],
        'status_change' => ['color' => '#d4a72c', 'bg' => '#fef3c7', 'icon' => 'fa-exchange-alt', 'label' => 'تغییر وضعیت'],
    ];
    return $styles[$action] ?? ['color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => 'fa-circle', 'label' => $action];
}

function getTimeAgo($timestamp) {
    date_default_timezone_set('Asia/Tehran');
    $now = time();
    $diff = $now - strtotime($timestamp);
    
    if ($diff < 60) return 'لحظاتی پیش';
    if ($diff < 3600) return floor($diff / 60) . ' دقیقه پیش';
    if ($diff < 86400) return floor($diff / 3600) . ' ساعت پیش';
    if ($diff < 604800) return floor($diff / 86400) . ' روز پیش';
    if ($diff < 2592000) return floor($diff / 604800) . ' هفته پیش';
    return jdate('Y/m/d', strtotime($timestamp));
}

function cleanDescription($text) {
    $text = preg_replace('/\s*\(ID:\s*\d+\)\s*$/', '', $text);
    return $text;
}

// ===== دریافت لیست یادآورها با فیلتر =====
$where = [];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where[] = "(r.title LIKE :search OR r.description LIKE :search)";
    $params[':search'] = '%' . $_GET['search'] . '%';
}
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $where[] = "r.category_id = :category";
    $params[':category'] = $_GET['category'];
}
if (isset($_GET['priority']) && !empty($_GET['priority'])) {
    $where[] = "r.priority = :priority";
    $params[':priority'] = $_GET['priority'];
}
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $where[] = "r.status = :status";
    $params[':status'] = $_GET['status'];
}
if (isset($_GET['assigned_to']) && !empty($_GET['assigned_to'])) {
    $where[] = "r.assigned_to = :assigned_to";
    $params[':assigned_to'] = $_GET['assigned_to'];
}
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where[] = "r.shamsi_reminder_date >= :date_from";
    $params[':date_from'] = $_GET['date_from'];
}
if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where[] = "r.shamsi_reminder_date <= :date_to";
    $params[':date_to'] = $_GET['date_to'];
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "
    SELECT r.*, 
           c.name as category_name,
           c.color as category_color,
           c.icon as category_icon,
           u.full_name as assigned_user_name,
           u.mobile as assigned_user_mobile,
           u.email as assigned_user_email,
           g.name as group_name,
           u2.full_name as creator_name
    FROM reminders r
    LEFT JOIN reminder_categories c ON r.category_id = c.id
    LEFT JOIN users u ON r.assigned_to = u.id
    LEFT JOIN user_groups g ON r.assigned_group = g.id
    LEFT JOIN users u2 ON r.created_by = u2.id
    $whereClause
    ORDER BY r.id DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reminders = $stmt->fetchAll();

// ===== آمار =====
$stats = [
    'total' => count($reminders),
    'active' => count(array_filter($reminders, fn($a) => $a['status'] === 'active')),
    'done' => count(array_filter($reminders, fn($a) => $a['status'] === 'done')),
    'urgent' => count(array_filter($reminders, fn($a) => $a['priority'] === 'urgent')),
    'expired' => count(array_filter($reminders, fn($a) => $a['status'] === 'expired'))
];

// ===== پردازش فرم =====
if (isset($_POST['reminder_action'])) {
    $action = $_POST['reminder_action'];
    $reminder_id = $_POST['reminder_id'] ?? 0;
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $priority = $_POST['priority'] ?? 'medium';
    $status = $_POST['status'] ?? 'active';
    $reminder_date = trim($_POST['reminder_date'] ?? '');
    $reminder_time = trim($_POST['reminder_time'] ?? '');
    $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
    $assigned_group = !empty($_POST['assigned_group']) ? (int)$_POST['assigned_group'] : null;
    $is_group = isset($_POST['is_group']) ? 1 : 0;
    $send_sms = isset($_POST['send_sms']) ? 1 : 0;
    $send_email = isset($_POST['send_email']) ? 1 : 0;
    $send_telegram = isset($_POST['send_telegram']) ? 1 : 0;
    $send_whatsapp = isset($_POST['send_whatsapp']) ? 1 : 0;
    $send_system = isset($_POST['send_system']) ? 1 : 0;
    $sms_text = trim($_POST['sms_text'] ?? '');
    $email_subject = trim($_POST['email_subject'] ?? '');
    $email_body = trim($_POST['email_body'] ?? '');
    $repeat_type = $_POST['repeat_type'] ?? 'none';
    $repeat_until = trim($_POST['repeat_until'] ?? '');
    
    try {
        if ($action === 'add' && !empty($title) && !empty($reminder_date)) {
            $stmt = $pdo->prepare("INSERT INTO reminders (title, description, category_id, priority, status, reminder_date, reminder_time, shamsi_reminder_date, assigned_to, assigned_group, is_group, send_sms, send_email, send_telegram, send_whatsapp, send_system, sms_text, email_subject, email_body, repeat_type, repeat_until, created_by, shamsi_date, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $category_id, $priority, $status, $reminder_date, $reminder_time, $reminder_date, $assigned_to, $assigned_group, $is_group, $send_sms, $send_email, $send_telegram, $send_whatsapp, $send_system, $sms_text, $email_subject, $email_body, $repeat_type, $repeat_until, $user['id'], nowShamsi('Y/m/d'), $assigned_to ?? $user['id']]);
            
            $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, module, action, description, created_at) VALUES (?, 'reminder', 'add', ?, NOW())");
            $logStmt->execute([$user['id'], "یادآور جدید: {$title}"]);
            
            $_SESSION['success'] = 'یادآور با موفقیت ایجاد شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'edit' && !empty($title) && !empty($reminder_date) && $reminder_id > 0) {
            $stmt = $pdo->prepare("UPDATE reminders SET title = ?, description = ?, category_id = ?, priority = ?, status = ?, reminder_date = ?, reminder_time = ?, shamsi_reminder_date = ?, assigned_to = ?, assigned_group = ?, is_group = ?, send_sms = ?, send_email = ?, send_telegram = ?, send_whatsapp = ?, send_system = ?, sms_text = ?, email_subject = ?, email_body = ?, repeat_type = ?, repeat_until = ?, user_id = ? WHERE id = ?");
            $stmt->execute([$title, $description, $category_id, $priority, $status, $reminder_date, $reminder_time, $reminder_date, $assigned_to, $assigned_group, $is_group, $send_sms, $send_email, $send_telegram, $send_whatsapp, $send_system, $sms_text, $email_subject, $email_body, $repeat_type, $repeat_until, $assigned_to ?? $user['id'], $reminder_id]);
            $_SESSION['success'] = 'یادآور با موفقیت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'change_status' && $reminder_id > 0) {
            $new_status = $_POST['new_status'] ?? 'active';
            $stmt = $pdo->prepare("UPDATE reminders SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $reminder_id]);
            $_SESSION['success'] = 'وضعیت یادآور تغییر کرد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'delete' && $reminder_id > 0) {
            $pdo->prepare("DELETE FROM reminders WHERE id = ?")->execute([$reminder_id]);
            $_SESSION['success'] = 'یادآور با موفقیت حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'send_now' && $reminder_id > 0) {
            $result = $reminderService->sendReminder($reminder_id);
            if ($result['success']) {
                $_SESSION['success'] = 'یادآور با موفقیت ارسال شد.';
            } else {
                $_SESSION['error'] = 'خطا در ارسال: ' . $result['error'];
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'add_category' && !empty($_POST['cat_name'])) {
            $cat_name = trim($_POST['cat_name']);
            $cat_color = trim($_POST['cat_color'] ?? '#0969da');
            $cat_icon = trim($_POST['cat_icon'] ?? 'fa-tag');
            $pdo->prepare("INSERT INTO reminder_categories (name, color, icon) VALUES (?, ?, ?)")->execute([$cat_name, $cat_color, $cat_icon]);
            $_SESSION['success'] = 'دسته‌بندی جدید اضافه شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'delete_category' && !empty($_POST['cat_id'])) {
            $pdo->prepare("DELETE FROM reminder_categories WHERE id = ?")->execute([$_POST['cat_id']]);
            $_SESSION['success'] = 'دسته‌بندی حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'edit_category' && !empty($_POST['cat_id']) && !empty($_POST['cat_name'])) {
            $catId = (int)$_POST['cat_id'];
            $cat_name = trim($_POST['cat_name']);
            $cat_color = trim($_POST['cat_color'] ?? '#4facfe');
            $cat_icon = trim($_POST['cat_icon'] ?? 'fa-tag');
            
            $stmt = $pdo->prepare("UPDATE reminder_categories SET name = ?, color = ?, icon = ? WHERE id = ?");
            $stmt->execute([$cat_name, $cat_color, $cat_icon, $catId]);
            $_SESSION['success'] = 'دسته‌بندی با موفقیت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'add_group' && !empty($_POST['group_name'])) {
            $group_name = trim($_POST['group_name']);
            $group_desc = trim($_POST['group_description'] ?? '');
            $members = isset($_POST['group_members']) ? implode(',', $_POST['group_members']) : '';
            $stmt = $pdo->prepare("INSERT INTO user_groups (name, description, members, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$group_name, $group_desc, $members, $user['id']]);
            $_SESSION['success'] = 'گروه جدید اضافه شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'delete_group' && !empty($_POST['group_id'])) {
            $pdo->prepare("DELETE FROM user_groups WHERE id = ?")->execute([$_POST['group_id']]);
            $_SESSION['success'] = 'گروه حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'edit_group' && !empty($_POST['group_id']) && !empty($_POST['group_name'])) {
            $groupId = (int)$_POST['group_id'];
            $group_name = trim($_POST['group_name']);
            $group_desc = trim($_POST['group_description'] ?? '');
            $members = isset($_POST['group_members']) ? implode(',', $_POST['group_members']) : '';
            
            $stmt = $pdo->prepare("UPDATE user_groups SET name = ?, description = ?, members = ? WHERE id = ?");
            $stmt->execute([$group_name, $group_desc, $members, $groupId]);
            $_SESSION['success'] = 'گروه با موفقیت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'add_user' && !empty($_POST['user_name']) && !empty($_POST['user_mobile'])) {
            $user_name = trim($_POST['user_name']);
            $user_mobile = trim($_POST['user_mobile']);
            $user_email = trim($_POST['user_email'] ?? '');
            $user_telegram = trim($_POST['user_telegram'] ?? '');
            $user_username = 'user_' . time();
            $user_password = password_hash('123456', PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("INSERT INTO users (full_name, username, password_hash, email, mobile, telegram_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$user_name, $user_username, $user_password, $user_email, $user_mobile, $user_telegram]);
            $_SESSION['success'] = 'کاربر جدید اضافه شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'delete_user' && !empty($_POST['user_id']) && $_POST['user_id'] != $user['id']) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$_POST['user_id']]);
            $_SESSION['success'] = 'کاربر حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'edit_user' && !empty($_POST['user_id']) && !empty($_POST['user_name'])) {
            $userId = (int)$_POST['user_id'];
            $user_name = trim($_POST['user_name']);
            $user_mobile = trim($_POST['user_mobile']);
            $user_email = trim($_POST['user_email'] ?? '');
            $user_telegram = trim($_POST['user_telegram'] ?? '');
            $user_password = trim($_POST['user_password'] ?? '');
            
            if (!empty($user_password)) {
                $hashed = password_hash($user_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, mobile = ?, email = ?, telegram_id = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$user_name, $user_mobile, $user_email, $user_telegram, $hashed, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, mobile = ?, email = ?, telegram_id = ? WHERE id = ?");
                $stmt->execute([$user_name, $user_mobile, $user_email, $user_telegram, $userId]);
            }
            $_SESSION['success'] = 'کاربر با موفقیت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'save_sms_settings') {
            $provider = trim($_POST['sms_provider'] ?? 'smsir');
            $api_key = trim($_POST['sms_api_key'] ?? '');
            $sender_number = trim($_POST['sms_sender_number'] ?? '1000596446');
            
            $stmt = $pdo->prepare("INSERT INTO sms_settings (provider, api_key, sender_number, is_default, status) VALUES (?, ?, ?, 1, 1) ON DUPLICATE KEY UPDATE api_key = ?, sender_number = ?");
            $stmt->execute([$provider, $api_key, $sender_number, $api_key, $sender_number]);
            
            $settings = [
                'sms_provider' => $provider,
                'sms_api_key' => $api_key,
                'sms_sender_number' => $sender_number
            ];
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            
            $_SESSION['success'] = 'تنظیمات پیامک ذخیره شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'save_email_settings') {
            $smtp_host = trim($_POST['smtp_host'] ?? '');
            $smtp_port = trim($_POST['smtp_port'] ?? '587');
            $smtp_username = trim($_POST['smtp_username'] ?? '');
            $smtp_password = trim($_POST['smtp_password'] ?? '');
            $smtp_encryption = trim($_POST['smtp_encryption'] ?? 'tls');
            $mail_from = trim($_POST['mail_from'] ?? '');
            $mail_from_name = trim($_POST['mail_from_name'] ?? '');
            
            $settings = [
                'email_smtp_host' => $smtp_host,
                'email_smtp_port' => $smtp_port,
                'email_smtp_username' => $smtp_username,
                'email_smtp_password' => $smtp_password,
                'email_smtp_encryption' => $smtp_encryption,
                'email_mail_from' => $mail_from,
                'email_mail_from_name' => $mail_from_name
            ];
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            $_SESSION['success'] = 'تنظیمات ایمیل ذخیره شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($action === 'save_telegram_settings') {
            $token = trim($_POST['telegram_bot_token'] ?? '');
            $chat = trim($_POST['telegram_default_chat'] ?? '');
            
            $settings = [
                'telegram_bot_token' => $token,
                'telegram_default_chat' => $chat
            ];
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            $_SESSION['success'] = 'تنظیمات تلگرام ذخیره شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا: ' . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } catch (Exception $e) {
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
    <title>یادآور حرفه ای</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/jalalidatepicker.min.css">
    <script src="assets/js/jalalidatepicker.min.js"></script>
    <script src="assets/js/persian-date.js"></script>
    <script src="assets/js/global.js"></script>
    <style>
        /* ===== استایل‌های کامل دبیرخانه ===== */
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
        .stat-card.purple-light { background: #f5f0ff; border-color: #d4c4f0; }
        .stat-card.purple-light .stat-icon { border-color: #d4c4f0; color: #8250df; }
        .stat-card.purple-light:hover { box-shadow: 0 8px 30px rgba(130,80,223,0.20); transform: translateY(-3px); border-color: #8250df; }
        .stat-card.orange-light { background: #fff3e0; border-color: #ffcc80; }
        .stat-card.orange-light .stat-icon { border-color: #ffcc80; color: #ff9800; }
        .stat-card.orange-light:hover { box-shadow: 0 8px 30px rgba(255,152,0,0.20); transform: translateY(-3px); border-color: #ff9800; }
        .stat-card.red-light { background: #fef2f2; border-color: #f5c8c8; }
        .stat-card.red-light .stat-icon { border-color: #f5c8c8; color: #cf222e; }
        .stat-card.red-light:hover { box-shadow: 0 8px 30px rgba(207,34,46,0.20); transform: translateY(-3px); border-color: #cf222e; }

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
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
        }
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
        .btn-reminder { background: linear-gradient(135deg, #0f9d58, #0b7a44); }
        .btn-reminder:hover { background: linear-gradient(135deg, #0c8b4a, #09693a); box-shadow: 0 8px 25px rgba(15,157,88,0.4); }
        .btn-category-action { background: linear-gradient(135deg, #7c3aed, #5b21b6); }
        .btn-category-action:hover { background: linear-gradient(135deg, #6d28d9, #4c1d95); box-shadow: 0 8px 25px rgba(124,58,237,0.4); }
        .btn-group-action { background: linear-gradient(135deg, #ea580c, #c2410c); }
        .btn-group-action:hover { background: linear-gradient(135deg, #d97706, #b45309); box-shadow: 0 8px 25px rgba(234,88,12,0.4); }
        .btn-user { background: linear-gradient(135deg, #0891b2, #0e7490); }
        .btn-user:hover { background: linear-gradient(135deg, #0e7490, #0a5c7a); box-shadow: 0 8px 25px rgba(8,145,178,0.4); }
        .btn-sms { background: linear-gradient(135deg, #0f9d58, #0b7a44); }
        .btn-sms:hover { background: linear-gradient(135deg, #0c8b4a, #09693a); box-shadow: 0 8px 25px rgba(15,157,88,0.4); }
        .btn-email { background: linear-gradient(135deg, #d97706, #b45309); }
        .btn-email:hover { background: linear-gradient(135deg, #ca8a04, #a16207); box-shadow: 0 8px 25px rgba(217,119,6,0.4); }
        .btn-telegram { background: linear-gradient(135deg, #1da1f2, #0d8bd9); }
        .btn-telegram:hover { background: linear-gradient(135deg, #1a8cd8, #0a6bb5); box-shadow: 0 8px 25px rgba(29,161,242,0.4); }

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
        .activities-timeline { display: flex; flex-direction: column; gap: 8px; }
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
        .activity-title { font-size: 13px; font-weight: 500; color: #24292f; }
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
        .badge-category { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; display: inline-block; white-space: nowrap; }
        .badge-priority { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; white-space: nowrap; }
        
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
        .table-modern .actions .btn-icon.send { color: #2da44e; }
        .table-modern .actions .btn-icon.send:hover { background: #ddf4e6; }
        .table-modern .actions .btn-icon.delete { color: #991b1b; }
        .table-modern .actions .btn-icon.delete:hover { background: #fecaca; }
        
        .send-methods { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; }
        .send-method-icon {
            width: 24px; height: 24px; border-radius: 50%;
            display: inline-flex; align-items: center;
            justify-content: center; font-size: 10px;
            background: #f0f2f5; color: #57606a;
        }
        .send-method-icon.active { background: #dbeafe; color: #0969da; }
        .send-method-icon.telegram { background: #e3f0ff; color: #1da1f2; }
        .send-method-icon.whatsapp { background: #dcf8e6; color: #25d366; }
        .repeat-badge {
            background: #f0f2f5; color: #57606a;
            padding: 2px 12px; border-radius: 12px;
            font-size: 11px; display: inline-block;
        }
        .assigned-to {
            padding: 2px 12px; border-radius: 12px; font-size: 11px;
            display: inline-block; white-space: nowrap;
        }
        .assigned-to.user { background: #f0f2f5; color: #57606a; }
        .assigned-to.group { background: #dbeafe; color: #0969da; }

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
        .sub-section { border-top: 2px dashed #e1e4e8; padding-top: 16px; margin-top: 8px; }
        .sub-section-title { font-size: 15px; font-weight: 700; color: #1a1a2e; margin-bottom: 12px; }
        .sub-section-title i { margin-left: 8px; color: #0969da; }
        .checkbox-group { display: flex; gap: 18px; flex-wrap: wrap; padding: 8px 0; }
        .checkbox-group label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 400; font-size: 14px; color: #24292f; }
        .checkbox-group label input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: #0969da; }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 500;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a8e6c1; }
        .alert-danger { background: #fecaca; color: #991b1b; border: 1px solid #f5c8c8; }

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
            .filters-bar input[name="search"] { min-width: 100%; }
            .modal-box { padding: 20px; }
            .modal-box .form-row { flex-direction: column; }
            .modal-box .form-row .form-group { min-width: 100%; }
            .actions-bar { flex-direction: column; }
            .btn-action { min-width: 100%; justify-content: center; }
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
        <li><a href="reminder.php" class="active"><i class="fas fa-bell"></i> یادآور</a></li>
        <li><a href="travel_expenses.php"><i class="fas fa-route"></i> مأموریت</a></li>
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
                <i class="fas fa-bell" style="color:#0969da; margin-left:10px;"></i>
                یادآور
                <span>مدیریت حرفه‌ای یادآوری‌ها</span>
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

    <!-- ===== آمار ===== -->
    <div class="stats-grid">
        <div class="stat-card blue-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-bell"></i></span><span class="stat-number"><?= persian_number($stats['total']) ?></span></div><div class="stat-label">کل یادآورها</div></div>
        <div class="stat-card green-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-check-circle"></i></span><span class="stat-number"><?= persian_number($stats['active']) ?></span></div><div class="stat-label">فعال</div></div>
        <div class="stat-card purple-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-check-double"></i></span><span class="stat-number"><?= persian_number($stats['done']) ?></span></div><div class="stat-label">انجام شده</div></div>
        <div class="stat-card orange-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-exclamation-triangle"></i></span><span class="stat-number"><?= persian_number($stats['urgent']) ?></span></div><div class="stat-label">فوری</div></div>
        <div class="stat-card red-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-times-circle"></i></span><span class="stat-number"><?= persian_number($stats['expired']) ?></span></div><div class="stat-label">منقضی شده</div></div>
    </div>

    <!-- ===== فیلترها ===== -->
    <form class="filters-bar" method="GET" action="">
        <div class="filter-group">
            <input type="text" name="search" placeholder="جستجوی عنوان یا توضیحات..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
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
            <select name="priority">
                <option value="">همه اولویت‌ها</option>
                <option value="urgent" <?= (isset($_GET['priority']) && $_GET['priority'] == 'urgent') ? 'selected' : '' ?>>فوری</option>
                <option value="high" <?= (isset($_GET['priority']) && $_GET['priority'] == 'high') ? 'selected' : '' ?>>زیاد</option>
                <option value="medium" <?= (isset($_GET['priority']) && $_GET['priority'] == 'medium') ? 'selected' : '' ?>>متوسط</option>
                <option value="low" <?= (isset($_GET['priority']) && $_GET['priority'] == 'low') ? 'selected' : '' ?>>کم</option>
            </select>
        </div>
        <div class="filter-group">
            <select name="status">
                <option value="">همه وضعیت‌ها</option>
                <option value="active" <?= (isset($_GET['status']) && $_GET['status'] == 'active') ? 'selected' : '' ?>>فعال</option>
                <option value="done" <?= (isset($_GET['status']) && $_GET['status'] == 'done') ? 'selected' : '' ?>>انجام شده</option>
                <option value="expired" <?= (isset($_GET['status']) && $_GET['status'] == 'expired') ? 'selected' : '' ?>>منقضی شده</option>
            </select>
        </div>
        <div class="filter-group">
            <select name="assigned_to">
                <option value="">همه کاربران</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= (isset($_GET['assigned_to']) && $_GET['assigned_to'] == $u['id']) ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name'] ?? $u['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="date-wrapper">
            <input type="text" name="date_from" class="persian-date" data-jdp placeholder="از تاریخ" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>" autocomplete="off">
            <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();"><i class="fas fa-calendar-alt"></i></button>
        </div>
        <span style="color:#8b93a5;">تا</span>
        <div class="date-wrapper">
            <input type="text" name="date_to" class="persian-date" data-jdp placeholder="تا تاریخ" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>" autocomplete="off">
            <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();"><i class="fas fa-calendar-alt"></i></button>
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> فیلتر</button>
        <a href="reminder.php" class="btn-reset"><i class="fas fa-undo"></i> پاک کردن</a>
    </form>

    <!-- ===== دکمه‌های عملیات ===== -->
    <div class="actions-bar">
        <button class="btn-action btn-reminder" id="btnAddReminder">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-bell"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">یادآور جدید</div>
                    <div style="font-size:10px;opacity:0.8;">ثبت یادآوری</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-category-action" onclick="openCategoryModal()">
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
        <button class="btn-action btn-group-action" onclick="openGroupModal()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-users"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">گروه‌ها</div>
                    <div style="font-size:10px;opacity:0.8;">مدیریت گروه‌ها</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-user" onclick="openUserModal()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">کاربران</div>
                    <div style="font-size:10px;opacity:0.8;">مدیریت کاربران</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-sms" onclick="openSmsSettings()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-sms"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">تنظیمات پیامک</div>
                    <div style="font-size:10px;opacity:0.8;">تنظیمات SMS</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-email" onclick="openEmailSettings()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fas fa-envelope"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">تنظیمات ایمیل</div>
                    <div style="font-size:10px;opacity:0.8;">تنظیمات SMTP</div>
                </div>
            </div>
        </button>
        <button class="btn-action btn-telegram" onclick="openTelegramSettings()">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                    <i class="fab fa-telegram-plane"></i>
                </div>
                <div style="text-align:right;line-height:1.3;">
                    <div style="font-weight:700;font-size:14px;">تنظیمات تلگرام</div>
                    <div style="font-size:10px;opacity:0.8;">ربات تلگرام</div>
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
                    $style = getActivityStyle($activity['action'], $activity['module']);
                    $timeAgo = getTimeAgo($activity['created_at']);
                ?>
                    <div class="activity-card" style="border-right: 4px solid <?= $style['color'] ?>;">
                        <div class="activity-icon" style="background: <?= $style['bg'] ?>; color: <?= $style['color'] ?>;">
                            <i class="fas <?= $style['icon'] ?>"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title"><?= htmlspecialchars(persian_number_str(cleanDescription($activity['description'] ?? $activity['action']))) ?></div>
                            <div class="activity-meta">
                                <span class="activity-badge" style="background: <?= $style['bg'] ?>; color: <?= $style['color'] ?>;">
                                    <?php if ($activity['action'] === 'send'): ?>
                                        <i class="fas fa-check-circle" style="color:#0f9d58;"></i> ارسال شد
                                    <?php else: ?>
                                        <?= $style['label'] ?>
                                    <?php endif; ?>
                                </span>
                                <span class="activity-time"><i class="far fa-clock"></i> <?= persian_number_str($timeAgo) ?></span>
                            </div>
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
                        <th style="width:40px;">#</th>
                        <th>عنوان</th>
                        <th style="width:100px;">دسته‌بندی</th>
                        <th style="width:80px;">اولویت</th>
                        <th style="width:110px;">تاریخ</th>
                        <th style="width:80px;">وضعیت</th>
                        <th style="width:120px;">اختصاص به</th>
                        <th style="width:80px;">تکرار</th>
                        <th style="width:160px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reminders)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:50px;color:#57606a;">
                            <i class="fas fa-bell-slash" style="font-size:40px;display:block;margin-bottom:10px;color:#d0d7de;"></i>
                            هیچ یادآوری یافت نشد.
                        </td></tr>
                    <?php else: $i=1; foreach ($reminders as $item): 
                        $status = getStatusStyle($item['status']);
                        $priority = getPriorityStyle($item['priority']);
                        $assigned = $item['is_group'] ? ($item['group_name'] ?? 'گروه') : ($item['assigned_user_name'] ?? 'همه');
                        $assignedClass = $item['is_group'] ? 'group' : 'user';
                    ?>
                        <tr>
                            <td><?= persian_number($i++) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($item['title']) ?></strong>
                                <?php if (!empty($item['description'])): ?>
                                    <div style="font-size:12px;color:#57606a;margin-top:2px;"><?= htmlspecialchars(mb_substr($item['description'], 0, 50)) ?>...</div>
                                <?php endif; ?>
                                <div class="send-methods">
                                    <?php if ($item['send_sms']): ?><span class="send-method-icon active" title="پیامک"><i class="fas fa-sms"></i></span><?php endif; ?>
                                    <?php if ($item['send_email']): ?><span class="send-method-icon active" title="ایمیل"><i class="fas fa-envelope"></i></span><?php endif; ?>
                                    <?php if ($item['send_telegram']): ?><span class="send-method-icon telegram" title="تلگرام"><i class="fab fa-telegram-plane"></i></span><?php endif; ?>
                                    <?php if ($item['send_whatsapp']): ?><span class="send-method-icon whatsapp" title="واتساپ"><i class="fab fa-whatsapp"></i></span><?php endif; ?>
                                    <?php if ($item['send_system']): ?><span class="send-method-icon active" title="سیستمی"><i class="fas fa-bell"></i></span><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($item['category_name']): ?>
                                    <span class="badge-category" style="background:<?= $item['category_color'] ?>20;color:<?= $item['category_color'] ?>;border:1px solid <?= $item['category_color'] ?>40;">
                                        <i class="fas <?= $item['category_icon'] ?? 'fa-tag' ?>" style="margin-left:4px;font-size:10px;"></i>
                                        <?= htmlspecialchars($item['category_name']) ?>
                                    </span>
                                <?php else: ?><span style="color:#57606a;font-size:12px;">-</span><?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-priority" style="background:<?= $priority['bg'] ?>;color:<?= $priority['color'] ?>;">
                                    <i class="fas <?= $priority['icon'] ?>" style="margin-left:4px;font-size:10px;"></i>
                                    <?= $priority['label'] ?>
                                </span>
                            </td>
                            <td style="font-size:13px;color:#57606a;">
                                <?= persian_number_str($item['shamsi_reminder_date'] ?? $item['reminder_date']) ?>
                                <?php if (!empty($item['reminder_time'])): ?><br><span style="font-size:11px;color:#57606a;"><?= persian_number_str(substr($item['reminder_time'], 0, 5)) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-status" style="background:<?= $status['bg'] ?>;color:<?= $status['color'] ?>;">
                                    <i class="fas <?= $status['icon'] ?>" style="margin-left:4px;font-size:10px;"></i>
                                    <?= $status['label'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="assigned-to <?= $assignedClass ?>">
                                    <i class="fas <?= $item['is_group'] ? 'fa-users' : 'fa-user' ?>"></i>
                                    <?= htmlspecialchars($assigned) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($item['repeat_type'] != 'none'): ?>
                                    <span class="repeat-badge"><i class="fas fa-sync-alt"></i> <?= getRepeatLabel($item['repeat_type']) ?></span>
                                <?php else: ?><span style="color:#57606a;font-size:11px;">-</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <button class="btn-icon edit" onclick="editReminder(<?= $item['id'] ?>)" title="ویرایش"><i class="fas fa-edit"></i></button>
                                    <button class="btn-icon status" onclick="quickChangeStatus(<?= $item['id'] ?>)" title="تغییر وضعیت"><i class="fas fa-exchange-alt"></i></button>
                                    <?php if ($item['status'] == 'active' && $item['sent_at'] == null): ?>
                                        <a href="send_reminder.php?id=<?= $item['id'] ?>" target="_blank" class="btn-icon send" title="ارسال فوری" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;background:#f0f2f4;color:#2da44e;text-decoration:none;">
                                            <i class="fas fa-paper-plane"></i>
                                        </a>
                                    <?php endif; ?>
                                    <button class="btn-icon delete" onclick="deleteReminder(<?= $item['id'] ?>)" title="حذف"><i class="fas fa-trash-alt"></i></button>
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
        <span class="footer-version">نسخه 2.0 - یادآور حرفه‌ای</span>
    </div>
</div>

<!-- ============================================= -->
<!-- ===== مودال‌ها (کامل) ===== -->
<!-- ============================================= -->

<!-- ===== مودال یادآور ===== -->
<div class="modal-overlay" id="reminderModal">
    <div class="modal-box">
        <h2><i class="fas fa-bell" style="color:#0969da;"></i> <span id="reminderModalTitle">یادآور جدید</span></h2>
        <form method="POST" id="reminderForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="reminder_action" id="reminder_action" value="add">
            <input type="hidden" name="reminder_id" id="reminder_id" value="0">
            <div class="form-group">
                <label>عنوان <span style="color:red;">*</span></label>
                <input type="text" name="title" id="title" required placeholder="عنوان یادآور را وارد کنید">
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <textarea name="description" id="description" rows="3" placeholder="توضیحات کامل یادآور"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>دسته‌بندی</label>
                    <select name="category_id" id="category_id">
                        <option value="">بدون دسته</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>اولویت</label>
                    <select name="priority" id="priority">
                        <option value="low">کم</option>
                        <option value="medium" selected>متوسط</option>
                        <option value="high">زیاد</option>
                        <option value="urgent">فوری</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>زمان یادآوری</label>
                    <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                        <div style="flex:1; min-width:80px;">
                            <label style="font-size:12px;color:#57606a;display:block;margin-bottom:4px;">ساعت</label>
                            <select name="reminder_hour" id="reminder_hour" style="width:100%;padding:8px 12px;border-radius:6px;border:1px solid #e1e4e8;font-size:14px;font-family:'Vazirmatn',sans-serif;background:#f8f9fa;direction:ltr;text-align:center;" onchange="updateReminderTime()">
                                <option value="">ساعت</option>
                                <?php for($h=0;$h<24;$h++): ?><option value="<?= sprintf('%02d', $h) ?>"><?= sprintf('%02d', $h) ?></option><?php endfor; ?>
                            </select>
                        </div>
                        <div style="flex:1; min-width:80px;">
                            <label style="font-size:12px;color:#57606a;display:block;margin-bottom:4px;">دقیقه</label>
                            <select name="reminder_minute" id="reminder_minute" style="width:100%;padding:8px 12px;border-radius:6px;border:1px solid #e1e4e8;font-size:14px;font-family:'Vazirmatn',sans-serif;background:#f8f9fa;direction:ltr;text-align:center;" onchange="updateReminderTime()">
                                <option value="">دقیقه</option>
                                <?php for($m=0;$m<60;$m++): ?><option value="<?= sprintf('%02d', $m) ?>"><?= sprintf('%02d', $m) ?></option><?php endfor; ?>
                            </select>
                        </div>
                        <input type="hidden" name="reminder_time" id="reminder_time" value="">
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ یادآوری <span style="color:red;">*</span></label>
                    <div class="date-wrapper" style="width:100%;">
                        <input type="text" name="reminder_date" id="reminder_date" class="persian-date" data-jdp value="<?= nowShamsi('Y/m/d') ?>" autocomplete="off">
                        <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();"><i class="fas fa-calendar-alt"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label>نوع تکرار</label>
                    <select name="repeat_type" id="repeat_type" onchange="toggleRepeatUntil()">
                        <option value="none">بدون تکرار</option>
                        <option value="daily">روزانه</option>
                        <option value="weekly">هفتگی</option>
                        <option value="monthly">ماهانه</option>
                        <option value="yearly">سالانه</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" id="repeat_until_group" style="display:none;">
                    <label>تاریخ پایان تکرار</label>
                    <div class="date-wrapper" style="width:100%;">
                        <input type="text" name="repeat_until" id="repeat_until" class="persian-date" data-jdp placeholder="تاریخ پایان" autocomplete="off">
                        <button type="button" class="calendar-icon" onclick="this.parentElement.querySelector('input').focus();"><i class="fas fa-calendar-alt"></i></button>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>اختصاص به کاربر</label>
                    <select name="assigned_to" id="assigned_to">
                        <option value="">همه کاربران</option>
                        <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name'] ?? $u['username']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>اختصاص به گروه</label>
                    <select name="assigned_group" id="assigned_group">
                        <option value="">بدون گروه</option>
                        <?php foreach ($groups as $g): ?><option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group"><label><input type="checkbox" name="is_group" id="is_group" value="1"> ارسال به گروه</label></div>
            <div class="form-group">
                <label>وضعیت</label>
                <select name="status" id="status">
                    <option value="active">فعال</option>
                    <option value="done">انجام شده</option>
                    <option value="expired">منقضی شده</option>
                    <option value="canceled">لغو شده</option>
                </select>
            </div>
            <div class="sub-section">
                <div class="sub-section-title"><i class="fas fa-bell"></i> روش‌های ارسال</div>
                <div class="checkbox-group">
                    <label><input type="checkbox" name="send_sms" id="send_sms" value="1" checked> پیامک</label>
                    <label><input type="checkbox" name="send_email" id="send_email" value="1" checked> ایمیل</label>
                    <label><input type="checkbox" name="send_telegram" id="send_telegram" value="1"> تلگرام</label>
                    <label><input type="checkbox" name="send_whatsapp" id="send_whatsapp" value="1"> واتساپ</label>
                    <label><input type="checkbox" name="send_system" id="send_system" value="1" checked> اعلان سیستم</label>
                </div>
            </div>
            <div class="form-group">
                <label>متن پیامک</label>
                <textarea name="sms_text" id="sms_text" rows="2" placeholder="متن پیامک... (متغیرها: {name}, {title}, {date}, {time}, {description})"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>موضوع ایمیل</label>
                    <input type="text" name="email_subject" id="email_subject" placeholder="موضوع ایمیل">
                </div>
            </div>
            <div class="form-group">
                <label>متن ایمیل</label>
                <textarea name="email_body" id="email_body" rows="3" placeholder="متن ایمیل... (متغیرها: {name}, {title}, {date}, {time}, {description})"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('reminderModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره یادآور</button>
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
            <input type="hidden" name="reminder_action" value="change_status">
            <input type="hidden" name="reminder_id" id="quick_status_reminder_id" value="0">
            <div class="form-group">
                <label>وضعیت جدید</label>
                <select name="new_status" id="quick_status_select">
                    <option value="active">فعال</option>
                    <option value="done">انجام شده</option>
                    <option value="expired">منقضی شده</option>
                    <option value="canceled">لغو شده</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('quickStatusModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">تغییر وضعیت</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال دسته‌بندی ===== -->
<div class="modal-overlay" id="categoryModal">
    <div class="modal-box">
        <h2><i class="fas fa-tags" style="color:#8250df;"></i> مدیریت دسته‌بندی‌ها</h2>
        <div style="margin-bottom:20px;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="reminder_action" id="category_action" value="add_category">
                <input type="hidden" name="cat_id" id="cat_id" value="0">
                <div class="form-row">
                    <div class="form-group">
                        <label>نام دسته</label>
                        <input type="text" name="cat_name" id="cat_name" required placeholder="نام دسته‌بندی">
                    </div>
                    <div class="form-group">
                        <label>رنگ</label>
                        <input type="color" name="cat_color" id="cat_color" value="#0969da">
                    </div>
                    <div class="form-group">
                        <label>آیکون</label>
                        <input type="text" name="cat_icon" id="cat_icon" placeholder="fa-tag" value="fa-tag">
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end;">
                        <button type="submit" class="btn btn-primary" id="categorySubmitBtn">افزودن دسته</button>
                    </div>
                </div>
            </form>
        </div>
        <hr>
        <div style="margin-top:15px;">
            <table class="table-modern" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>نام</th>
                        <th>رنگ</th>
                        <th>آیکون</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?= htmlspecialchars($cat['name']) ?></td>
                            <td><span style="display:inline-block;width:20px;height:20px;border-radius:50%;background:<?= $cat['color'] ?>;border:1px solid #ddd;"></span></td>
                            <td><i class="fas <?= $cat['icon'] ?? 'fa-tag' ?>"></i></td>
                            <td>
                                <button class="btn-icon edit" onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name']) ?>', '<?= $cat['color'] ?>', '<?= $cat['icon'] ?? 'fa-tag' ?>')" title="ویرایش"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="reminder_action" value="delete_category">
                                    <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn-icon delete" onclick="return confirm('آیا از حذف این دسته مطمئن هستید؟')" title="حذف"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('categoryModal')">بستن</button>
        </div>
    </div>
</div>

<!-- ===== مودال گروه ===== -->
<div class="modal-overlay" id="groupModal">
    <div class="modal-box">
        <h2><i class="fas fa-users" style="color:#4facfe;"></i> مدیریت گروه‌ها</h2>
        <div style="margin-bottom:20px;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="reminder_action" value="add_group">
                <div class="form-row">
                    <div class="form-group">
                        <label>نام گروه</label>
                        <input type="text" name="group_name" required placeholder="نام گروه">
                    </div>
                    <div class="form-group">
                        <label>توضیحات</label>
                        <input type="text" name="group_description" placeholder="توضیحات">
                    </div>
                </div>
                <div class="form-group">
                    <label>اعضای گروه</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;max-height:100px;overflow-y:auto;border:1px solid #e1e4e8;padding:8px;border-radius:6px;">
                        <?php foreach ($users as $u): ?>
                            <label style="display:flex;align-items:center;gap:4px;font-weight:400;font-size:13px;cursor:pointer;">
                                <input type="checkbox" name="group_members[]" value="<?= $u['id'] ?>">
                                <?= htmlspecialchars($u['full_name'] ?? $u['username']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">ایجاد گروه</button>
            </form>
        </div>
        <hr>
        <div style="margin-top:15px;">
            <table class="table-modern" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>نام</th>
                        <th>توضیحات</th>
                        <th>تعداد اعضا</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $g): 
                        $memberCount = !empty($g['members']) ? count(explode(',', $g['members'])) : 0;
                        $memberIds = !empty($g['members']) ? explode(',', $g['members']) : [];
                        $membersJson = json_encode($memberIds);
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($g['name']) ?></td>
                            <td><?= htmlspecialchars($g['description']) ?></td>
                            <td><?= $memberCount ?></td>
                            <td>
                                <button class="btn-icon edit" 
                                        onclick="openEditGroupModal(<?= $g['id'] ?>, '<?= htmlspecialchars($g['name']) ?>', '<?= htmlspecialchars($g['description']) ?>', <?= $membersJson ?>)" 
                                        title="ویرایش">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="reminder_action" value="delete_group">
                                    <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                                    <button type="submit" class="btn-icon delete" onclick="return confirm('آیا از حذف این گروه مطمئن هستید؟')" title="حذف"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('groupModal')">بستن</button>
        </div>
    </div>
</div>

<!-- ===== مودال ویرایش گروه ===== -->
<div class="modal-overlay" id="editGroupModal">
    <div class="modal-box">
        <h2><i class="fas fa-edit" style="color:#d4a72c;"></i> <span id="editGroupTitle">ویرایش گروه</span></h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="reminder_action" value="edit_group">
            <input type="hidden" name="group_id" id="edit_group_id" value="0">
            <div class="form-group">
                <label>نام گروه</label>
                <input type="text" name="group_name" id="edit_group_name" required placeholder="نام گروه">
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <input type="text" name="group_description" id="edit_group_description" placeholder="توضیحات">
            </div>
            <div class="form-group">
                <label>اعضای گروه</label>
                <div style="display:flex;flex-wrap:wrap;gap:8px;max-height:100px;overflow-y:auto;border:1px solid #e1e4e8;padding:8px;border-radius:6px;">
                    <?php foreach ($users as $u): ?>
                        <label style="display:flex;align-items:center;gap:4px;font-weight:400;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="group_members[]" value="<?= $u['id'] ?>" class="edit-group-member">
                            <?= htmlspecialchars($u['full_name'] ?? $u['username']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editGroupModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال کاربر ===== -->
<div class="modal-overlay" id="userModal">
    <div class="modal-box">
        <h2><i class="fas fa-user-plus" style="color:#0f9d58;"></i> مدیریت کاربران</h2>
        <div style="margin-bottom:20px;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="reminder_action" value="add_user">
                <div class="form-row">
                    <div class="form-group">
                        <label>نام کامل</label>
                        <input type="text" name="user_name" required placeholder="نام کاربر">
                    </div>
                    <div class="form-group">
                        <label>موبایل</label>
                        <input type="text" name="user_mobile" required placeholder="09xxxxxxxxx">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>ایمیل</label>
                        <input type="email" name="user_email" placeholder="example@domain.com">
                    </div>
                    <div class="form-group">
                        <label>تلگرام</label>
                        <input type="text" name="user_telegram" placeholder="آیدی تلگرام">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">افزودن کاربر</button>
            </form>
        </div>
        <hr>
        <div style="margin-top:15px;">
            <table class="table-modern" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>نام</th>
                        <th>موبایل</th>
                        <th>ایمیل</th>
                        <th>تلگرام</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['full_name']) ?></td>
                            <td><?= htmlspecialchars($u['mobile']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><?= htmlspecialchars($u['telegram_id']) ?></td>
                            <td>
                                <button class="btn-icon edit" onclick="openEditUserModal(<?= $u['id'] ?>)" title="ویرایش"><i class="fas fa-edit"></i></button>
                                <?php if ($u['id'] != $user['id']): ?>
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="reminder_action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn-icon delete" onclick="return confirm('آیا از حذف این کاربر مطمئن هستید؟')" title="حذف"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('userModal')">بستن</button>
        </div>
    </div>
</div>

<!-- ===== مودال ویرایش کاربر ===== -->
<div class="modal-overlay" id="editUserModal">
    <div class="modal-box">
        <h2><i class="fas fa-user-edit" style="color:#d4a72c;"></i> ویرایش کاربر</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="reminder_action" value="edit_user">
            <input type="hidden" name="user_id" id="edit_user_id" value="0">
            <div class="form-group">
                <label>نام کامل</label>
                <input type="text" name="user_name" id="edit_user_name" required placeholder="نام کاربر">
            </div>
            <div class="form-group">
                <label>موبایل</label>
                <input type="text" name="user_mobile" id="edit_user_mobile" required placeholder="09xxxxxxxxx">
            </div>
            <div class="form-group">
                <label>ایمیل</label>
                <input type="email" name="user_email" id="edit_user_email" placeholder="example@domain.com">
            </div>
            <div class="form-group">
                <label>تلگرام</label>
                <input type="text" name="user_telegram" id="edit_user_telegram" placeholder="آیدی تلگرام">
            </div>
            <div class="form-group">
                <label>رمز عبور جدید (در صورت تغییر)</label>
                <input type="password" name="user_password" placeholder="رمز جدید را وارد کنید (اختیاری)">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال تنظیمات پیامک ===== -->
<div class="modal-overlay" id="smsSettingsModal">
    <div class="modal-box">
        <h2><i class="fas fa-sms" style="color:#0f9d58;"></i> تنظیمات پیامک</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="reminder_action" value="save_sms_settings">
            <div class="form-group">
                <label>ارائه‌دهنده</label>
                <select name="sms_provider">
                    <option value="smsir" <?= ($sms_settings['provider'] ?? '') == 'smsir' ? 'selected' : '' ?>>sms.ir</option>
                    <option value="kavenegar" <?= ($sms_settings['provider'] ?? '') == 'kavenegar' ? 'selected' : '' ?>>کاوه‌نگار</option>
                    <option value="farazsms" <?= ($sms_settings['provider'] ?? '') == 'farazsms' ? 'selected' : '' ?>>فراز اس‌ام‌اس</option>
                </select>
            </div>
            <div class="form-group">
                <label>کلید API</label>
                <input type="text" name="sms_api_key" value="<?= htmlspecialchars($sms_settings['api_key'] ?? '') ?>" placeholder="کلید API را وارد کنید">
            </div>
            <div class="form-group">
                <label>شماره فرستنده</label>
                <input type="text" name="sms_sender_number" value="<?= htmlspecialchars($sms_settings['sender_number'] ?? '') ?>" placeholder="شماره فرستنده">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('smsSettingsModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال تنظیمات ایمیل ===== -->
<div class="modal-overlay" id="emailSettingsModal">
    <div class="modal-box">
        <h2><i class="fas fa-envelope" style="color:#d97706;"></i> تنظیمات ایمیل (SMTP)</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="reminder_action" value="save_email_settings">
            <div class="form-group">
                <label>سرور SMTP</label>
                <input type="text" name="smtp_host" value="<?= htmlspecialchars($email_settings['email_smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>پورت</label>
                    <input type="text" name="smtp_port" value="<?= htmlspecialchars($email_settings['email_smtp_port'] ?? '587') ?>" placeholder="587">
                </div>
                <div class="form-group">
                    <label>رمزنگاری</label>
                    <select name="smtp_encryption">
                        <option value="tls" <?= ($email_settings['email_smtp_encryption'] ?? '') == 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= ($email_settings['email_smtp_encryption'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= ($email_settings['email_smtp_encryption'] ?? '') == 'none' ? 'selected' : '' ?>>بدون</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>نام کاربری</label>
                <input type="text" name="smtp_username" value="<?= htmlspecialchars($email_settings['email_smtp_username'] ?? '') ?>" placeholder="ایمیل یا نام کاربری">
            </div>
            <div class="form-group">
                <label>رمز عبور</label>
                <input type="password" name="smtp_password" value="<?= htmlspecialchars($email_settings['email_smtp_password'] ?? '') ?>" placeholder="رمز عبور">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>ایمیل فرستنده</label>
                    <input type="email" name="mail_from" value="<?= htmlspecialchars($email_settings['email_mail_from'] ?? '') ?>" placeholder="noreply@domain.com">
                </div>
                <div class="form-group">
                    <label>نام فرستنده</label>
                    <input type="text" name="mail_from_name" value="<?= htmlspecialchars($email_settings['email_mail_from_name'] ?? '') ?>" placeholder="نام سازمان">
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('emailSettingsModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال تنظیمات تلگرام ===== -->
<div class="modal-overlay" id="telegramSettingsModal">
    <div class="modal-box">
        <h2><i class="fab fa-telegram-plane" style="color:#1da1f2;"></i> تنظیمات تلگرام</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="reminder_action" value="save_telegram_settings">
            <div class="form-group">
                <label>توکن ربات</label>
                <input type="text" name="telegram_bot_token" value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '') ?>" placeholder="توکن ربات را وارد کنید">
            </div>
            <div class="form-group">
                <label>گفتگوی پیش‌فرض (Chat ID)</label>
                <input type="text" name="telegram_default_chat" value="<?= htmlspecialchars($settings['telegram_default_chat'] ?? '') ?>" placeholder="@channel یا عدد Chat ID">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('telegramSettingsModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
            </div>
        </form>
    </div>
</div>

<button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>

<script>
// ================================================
// ===== رویداد بارگذاری صفحه =====
// ================================================
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
    
    var btn = document.getElementById('btnAddReminder');
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

    document.querySelectorAll('.persian-date').forEach(function(input) {
        input.addEventListener('change', function() {
            var value = this.value;
            var persianDigits = '۰۱۲۳۴۵۶۷۸۹';
            var englishDigits = '0123456789';
            var persianValue = value.replace(/[0-9]/g, function(w) {
                return persianDigits[englishDigits.indexOf(w)];
            });
            this.value = persianValue;
        });
    });

    toggleRepeatUntil();
});

// ================================================
// ===== توابع سراسری =====
// ================================================

function toggleRepeatUntil() {
    var repeatType = document.getElementById('repeat_type');
    var group = document.getElementById('repeat_until_group');
    if (repeatType && group) {
        group.style.display = (repeatType.value != 'none') ? 'block' : 'none';
    }
}

function updateReminderTime() {
    var hour = document.getElementById('reminder_hour').value;
    var minute = document.getElementById('reminder_minute').value;
    var hiddenTime = document.getElementById('reminder_time');
    if (hour && minute) {
        hiddenTime.value = hour + ':' + minute;
    } else {
        hiddenTime.value = '';
    }
}

function openAddModal() {
    document.getElementById('reminderModalTitle').textContent = 'یادآور جدید';
    document.getElementById('reminder_action').value = 'add';
    document.getElementById('reminder_id').value = 0;
    document.getElementById('title').value = '';
    document.getElementById('description').value = '';
    document.getElementById('category_id').value = '';
    document.getElementById('priority').value = 'medium';
    document.getElementById('status').value = 'active';
    document.getElementById('reminder_date').value = '<?= nowShamsi('Y/m/d') ?>';
    document.getElementById('reminder_time').value = '';
    document.getElementById('assigned_to').value = '';
    document.getElementById('reminder_hour').value = '';
    document.getElementById('reminder_minute').value = '';
    document.getElementById('assigned_group').value = '';
    document.getElementById('is_group').checked = false;
    document.getElementById('repeat_type').value = 'none';
    document.getElementById('repeat_until').value = '';
    document.getElementById('repeat_until_group').style.display = 'none';
    document.getElementById('send_sms').checked = true;
    document.getElementById('send_email').checked = true;
    document.getElementById('send_telegram').checked = false;
    document.getElementById('send_whatsapp').checked = false;
    document.getElementById('send_system').checked = true;
    document.getElementById('sms_text').value = '';
    document.getElementById('email_subject').value = '';
    document.getElementById('email_body').value = '';
    document.getElementById('reminderModal').classList.add('active');
}

function editReminder(id) {
    fetch('get_reminder.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('reminderModalTitle').textContent = 'ویرایش یادآور';
                document.getElementById('reminder_action').value = 'edit';
                document.getElementById('reminder_id').value = id;
                document.getElementById('title').value = data.title || '';
                document.getElementById('description').value = data.description || '';
                document.getElementById('category_id').value = data.category_id || '';
                document.getElementById('priority').value = data.priority || 'medium';
                document.getElementById('status').value = data.status || 'active';
                
                var dateOnly = data.shamsi_reminder_date ? data.shamsi_reminder_date.split(' ')[0] : '';
                document.getElementById('reminder_date').value = dateOnly || '<?= nowShamsi('Y/m/d') ?>';
                
                document.getElementById('assigned_to').value = data.assigned_to || '';
                document.getElementById('assigned_group').value = data.assigned_group || '';
                document.getElementById('is_group').checked = data.is_group == 1;
                document.getElementById('repeat_type').value = data.repeat_type || 'none';
                document.getElementById('repeat_until').value = data.shamsi_repeat_until || '';
                toggleRepeatUntil();
                document.getElementById('send_sms').checked = data.send_sms == 1;
                document.getElementById('send_email').checked = data.send_email == 1;
                document.getElementById('send_telegram').checked = data.send_telegram == 1;
                document.getElementById('send_whatsapp').checked = data.send_whatsapp == 1;
                document.getElementById('send_system').checked = data.send_system == 1;
                document.getElementById('sms_text').value = data.sms_text || '';
                document.getElementById('email_subject').value = data.email_subject || '';
                document.getElementById('email_body').value = data.email_body || '';
                document.getElementById('reminderModal').classList.add('active');
            }
        })
        .catch(error => console.error('خطا:', error));
}

function deleteReminder(id) {
    if (confirm('آیا از حذف این یادآور مطمئن هستید؟')) {
        var form = document.createElement('form');
        form.method = 'POST';
        var i1 = document.createElement('input');
        i1.type = 'hidden';
        i1.name = 'reminder_action';
        i1.value = 'delete';
        var i2 = document.createElement('input');
        i2.type = 'hidden';
        i2.name = 'reminder_id';
        i2.value = id;
        form.appendChild(i1);
        form.appendChild(i2);
        document.body.appendChild(form);
        form.submit();
    }
}

function sendNow(id) {
    if (confirm('آیا از ارسال فوری این یادآور مطمئن هستید؟')) {
        var form = document.createElement('form');
        form.method = 'POST';
        var i1 = document.createElement('input');
        i1.type = 'hidden';
        i1.name = 'reminder_action';
        i1.value = 'send_now';
        var i2 = document.createElement('input');
        i2.type = 'hidden';
        i2.name = 'reminder_id';
        i2.value = id;
        form.appendChild(i1);
        form.appendChild(i2);
        document.body.appendChild(form);
        form.submit();
    }
}

function quickChangeStatus(id) {
    document.getElementById('quick_status_reminder_id').value = id;
    document.getElementById('quick_status_select').value = 'active';
    document.getElementById('quickStatusModal').classList.add('active');
}

function openEditUserModal(id) {
    fetch('get_user.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_user_id').value = id;
                document.getElementById('edit_user_name').value = data.full_name || '';
                document.getElementById('edit_user_mobile').value = data.mobile || '';
                document.getElementById('edit_user_email').value = data.email || '';
                document.getElementById('edit_user_telegram').value = data.telegram_id || '';
                document.getElementById('editUserModal').classList.add('active');
            }
        })
        .catch(error => console.error('خطا:', error));
}

function editCategory(id, name, color, icon) {
    document.getElementById('category_action').value = 'edit_category';
    document.getElementById('cat_id').value = id;
    document.getElementById('cat_name').value = name;
    document.getElementById('cat_color').value = color || '#0969da';
    document.getElementById('cat_icon').value = icon || 'fa-tag';
    document.getElementById('categorySubmitBtn').textContent = 'ویرایش دسته‌بندی';
    document.getElementById('categoryModal').classList.add('active');
}

function openCategoryModal() { document.getElementById('categoryModal').classList.add('active'); }
function openGroupModal() { document.getElementById('groupModal').classList.add('active'); }
function openUserModal() { document.getElementById('userModal').classList.add('active'); }
function openSmsSettings() { document.getElementById('smsSettingsModal').classList.add('active'); }
function openEmailSettings() { document.getElementById('emailSettingsModal').classList.add('active'); }
function openTelegramSettings() { document.getElementById('telegramSettingsModal').classList.add('active'); }

// ===== تابع جدید ویرایش گروه =====
function openEditGroupModal(id, name, description, memberIds) {
    document.getElementById('edit_group_id').value = id;
    document.getElementById('edit_group_name').value = name;
    document.getElementById('edit_group_description').value = description || '';
    document.querySelectorAll('#editGroupModal .edit-group-member').forEach(function(cb) {
        cb.checked = memberIds.indexOf(cb.value) !== -1;
    });
    document.getElementById('editGroupTitle').textContent = 'ویرایش گروه: ' + name;
    document.getElementById('editGroupModal').classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>

</body>
</html>


===============secretariat.php===============
<?php
ini_set('error_log', 'C:/xampp/htdocs/company_kb/error_log.txt');

require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'دبیرخانه';

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

$users = $pdo->query("SELECT id, full_name, national_id as username FROM users WHERE status = 1 ORDER BY full_name")->fetchAll();$headers = $pdo->query("SELECT * FROM letter_headers ORDER BY is_default DESC, title")->fetchAll();

$currentYear = jdate('Y');
$numbering = $pdo->prepare("SELECT * FROM letter_numbering WHERE year = ?");
$numbering->execute([$currentYear]);
$numbering = $numbering->fetch();

if (!$numbering) {
    $pdo->prepare("INSERT INTO letter_numbering (year, start_number, current_number) VALUES (?, 1, 0)")->execute([$currentYear]);
    $numbering = $pdo->prepare("SELECT * FROM letter_numbering WHERE year = ?");
    $numbering->execute([$currentYear]);
    $numbering = $numbering->fetch();
}

$where = [];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where[] = "(l.subject LIKE :search OR l.letter_number LIKE :search OR l.sender_name LIKE :search OR l.receiver_name LIKE :search)";
    $params[':search'] = '%' . $_GET['search'] . '%';
}
if (isset($_GET['type']) && !empty($_GET['type'])) {
    $where[] = "l.type = :type";
    $params[':type'] = $_GET['type'];
}
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $where[] = "l.status = :status";
    $params[':status'] = $_GET['status'];
}
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where[] = "l.shamsi_date >= :date_from";
    $params[':date_from'] = $_GET['date_from'];
}
if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where[] = "l.shamsi_date <= :date_to";
    $params[':date_to'] = $_GET['date_to'];
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "
    SELECT l.*, 
           u.full_name as creator_name,
           (SELECT COUNT(*) FROM letter_referrals WHERE letter_id = l.id AND status = 'pending') as pending_referrals,
           (SELECT COUNT(*) FROM letter_referrals WHERE letter_id = l.id AND status = 'answered') as answered_referrals
    FROM letters l
    LEFT JOIN users u ON l.created_by = u.id
    $whereClause
    ORDER BY l.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$letters = $stmt->fetchAll();

if (!$letters) {
    $letters = [];
}

$stats = [
    'total' => count($letters),
    'incoming' => count(array_filter($letters, fn($a) => $a['type'] == 'incoming')),
    'outgoing' => count(array_filter($letters, fn($a) => $a['type'] == 'outgoing')),
    'pending' => count(array_filter($letters, fn($a) => $a['status'] == 'draft' || $a['status'] == 'sent')),
    'answered' => count(array_filter($letters, fn($a) => $a['status'] == 'answered'))
];

function getLetterStatus($status) {
    $statuses = [
        'draft' => ['label' => 'پیش‌نویس', 'color' => '#e2e8f0', 'text' => '#475569'],
        'sent' => ['label' => 'ارسال شده', 'color' => '#dbeafe', 'text' => '#1e40af'],
        'received' => ['label' => 'دریافت شده', 'color' => '#d1fae5', 'text' => '#065f46'],
        'answered' => ['label' => 'پاسخ داده شده', 'color' => '#fef3c7', 'text' => '#92400e'],
        'archived' => ['label' => 'بایگانی', 'color' => '#f3f4f6', 'text' => '#6b7280']
    ];
    return $statuses[$status] ?? $statuses['draft'];
}

function getLetterType($type) {
    $types = [
        'incoming' => ['label' => 'وارده', 'color' => '#d1fae5', 'text' => '#065f46'],
        'outgoing' => ['label' => 'صادره', 'color' => '#dbeafe', 'text' => '#1e40af']
    ];
    return $types[$type] ?? $types['incoming'];
}

function getPriority($priority) {
    $priorities = [
        'low' => ['label' => 'کم', 'color' => '#d1fae5', 'text' => '#065f46'],
        'medium' => ['label' => 'متوسط', 'color' => '#fef3c7', 'text' => '#92400e'],
        'high' => ['label' => 'زیاد', 'color' => '#fecaca', 'text' => '#991b1b'],
        'urgent' => ['label' => 'فوری', 'color' => '#fee2e2', 'text' => '#dc2626']
    ];
    return $priorities[$priority] ?? $priorities['medium'];
}

if (isset($_POST['letter_action'])) {
    $action = $_POST['letter_action'];
    $letter_id = $_POST['letter_id'] ?? 0;
    $type = 'outgoing';
    $subject = trim($_POST['subject'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $status = $_POST['status'] ?? 'draft';
    $sender_name = trim($_POST['sender_name'] ?? '');
    $sender_organization = trim($_POST['sender_organization'] ?? '');
    $sender_phone = trim($_POST['sender_phone'] ?? '');
    $receiver_name = trim($_POST['receiver_name'] ?? '');
    $receiver_organization = trim($_POST['receiver_organization'] ?? '');
    $receiver_phone = trim($_POST['receiver_phone'] ?? '');
    $header_id = !empty($_POST['header_id']) ? (int)$_POST['header_id'] : null;
    
// ===== دریافت شماره دستی (برای وارده) =====
$letter_number_manual = trim($_POST['letter_number_manual'] ?? '');

// در کوئری INSERT:
// اگر نوع وارده است و شماره دستی وارد شده، از آن استفاده کن
// در غیر این صورت شماره خودکار تولید کن

if ($type === 'incoming' && !empty($letter_number_manual)) {
    $letter_number = $letter_number_manual;
} else {
    $letter_number = generateLetterNumber($pdo, $currentYear);
}





    $shamsi_date = isset($_POST['created_date']) ? trim($_POST['created_date']) : nowShamsi();
    $shamsi_letter_date = isset($_POST['letter_date']) ? trim($_POST['letter_date']) : nowShamsi();
    
    $has_attachment = isset($_POST['has_attachment']) ? (int)$_POST['has_attachment'] : 0;
    $attachment_count = isset($_POST['attachment_count']) ? (int)$_POST['attachment_count'] : 0;
    $attachment_description = trim($_POST['attachment_description'] ?? '');

    $signature_id = !empty($_POST['signature_id']) ? (int)$_POST['signature_id'] : 0;
    $signatory = '';
    $signature_path = '';

    if ($signature_id) {
        $stmt = $pdo->prepare("SELECT name, image_path FROM signatures WHERE id = ?");
        $stmt->execute([$signature_id]);
        $sig = $stmt->fetch();
        if ($sig) {
            $signatory = $sig['name'];
            $signature_path = $sig['image_path'];
        }
    }
    
    $attachment_file_path = null;
    if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] == 0) {
        $upload_dir = 'uploads/letter_attachments/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file = $_FILES['attachment_file'];
        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
        $attachment_file_path = $upload_dir . $file_name;
        move_uploaded_file($file['tmp_name'], $attachment_file_path);
    }
    
    try {
        if ($action === 'add' && !empty($subject)) {
            $letter_number = generateLetterNumber($pdo, $currentYear);
            
            $stmt = $pdo->prepare("INSERT INTO letters (letter_number, letter_date, shamsi_letter_date, type, subject, content, summary, priority, status, has_attachment, attachment_count, attachment_description, signatory, signature_path, signature_id, sender_name, sender_organization, sender_phone, receiver_name, receiver_organization, receiver_phone, header_id, created_by, created_at, shamsi_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->execute([$letter_number, date('Y-m-d'), $shamsi_letter_date, $type, $subject, $content, $summary, $priority, $status, $has_attachment, $attachment_count, $attachment_description, $signatory, $signature_path, $signature_id, $sender_name, $sender_organization, $sender_phone, $receiver_name, $receiver_organization, $receiver_phone, $header_id, $user['id'], $shamsi_date]);
            $letter_id = $pdo->lastInsertId();
            
            if ($attachment_file_path) {
                $stmt = $pdo->prepare("INSERT INTO letter_attachments (letter_id, file_name, original_name, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$letter_id, basename($attachment_file_path), $_FILES['attachment_file']['name'], $attachment_file_path, $_FILES['attachment_file']['size'], $user['id']]);
            }
            
            logLetterActivity($pdo, $letter_id, 'create', 'نامه جدید ایجاد شد', $user['id']);
            
            $_SESSION['success'] = 'نامه با موفقیت ثبت شد. شماره: ' . $letter_number;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
            
        } elseif ($action === 'edit' && !empty($subject) && $letter_id > 0) {
            $stmt = $pdo->prepare("UPDATE letters SET subject = ?, content = ?, summary = ?, priority = ?, status = ?, has_attachment = ?, attachment_count = ?, attachment_description = ?, signatory = ?, signature_path = ?, signature_id = ?, sender_name = ?, sender_organization = ?, sender_phone = ?, receiver_name = ?, receiver_organization = ?, receiver_phone = ?, header_id = ?, shamsi_date = ?, shamsi_letter_date = ? WHERE id = ?");
            $stmt->execute([$subject, $content, $summary, $priority, $status, $has_attachment, $attachment_count, $attachment_description, $signatory, $signature_path, $signature_id, $sender_name, $sender_organization, $sender_phone, $receiver_name, $receiver_organization, $receiver_phone, $header_id, $shamsi_date, $shamsi_letter_date, $letter_id]);
            
            if ($attachment_file_path) {
                $stmt = $pdo->prepare("INSERT INTO letter_attachments (letter_id, file_name, original_name, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$letter_id, basename($attachment_file_path), $_FILES['attachment_file']['name'], $attachment_file_path, $_FILES['attachment_file']['size'], $user['id']]);
            }
            
            logLetterActivity($pdo, $letter_id, 'edit', 'نامه ویرایش شد', $user['id']);
            
            $_SESSION['success'] = 'نامه با موفقیت ویرایش شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
            
        } elseif ($action === 'change_status' && $letter_id > 0) {
            $new_status = $_POST['new_status'] ?? 'draft';
            $stmt = $pdo->prepare("UPDATE letters SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $letter_id]);
            
            logLetterActivity($pdo, $letter_id, 'status_change', 'وضعیت نامه به ' . $new_status . ' تغییر یافت', $user['id']);
            
            $_SESSION['success'] = 'وضعیت نامه با موفقیت تغییر کرد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
            
        } elseif ($action === 'delete' && $letter_id > 0) {
            $pdo->prepare("DELETE FROM letter_referrals WHERE letter_id = ?")->execute([$letter_id]);
            $pdo->prepare("DELETE FROM letter_attachments WHERE letter_id = ?")->execute([$letter_id]);
            $pdo->prepare("DELETE FROM letter_logs WHERE letter_id = ?")->execute([$letter_id]);
            $stmt = $pdo->prepare("DELETE FROM letters WHERE id = ?");
            $stmt->execute([$letter_id]);
            
            $_SESSION['success'] = 'نامه با موفقیت حذف شد.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در انجام عملیات: ' . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

function generateLetterNumber($pdo, $year) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM letter_numbering WHERE year = ?");
        $stmt->execute([$year]);
        $numbering = $stmt->fetch();
        
        if (!$numbering) {
            $pdo->prepare("INSERT INTO letter_numbering (year, start_number, current_number) VALUES (?, 1, 0)")->execute([$year]);
            $current = 1;
        } else {
            $current = $numbering['current_number'] + 1;
            $pdo->prepare("UPDATE letter_numbering SET current_number = ? WHERE year = ?")->execute([$current, $year]);
        }
        
        $number = str_pad($current, 4, '0', STR_PAD_LEFT) . '/' . $year;
        return $number;
        
    } catch (PDOException $e) {
        return '0001/' . $year;
    }
}

function logLetterActivity($pdo, $letter_id, $action, $description, $user_id) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt = $pdo->prepare("INSERT INTO letter_logs (letter_id, action, description, user_id, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$letter_id, $action, $description, $user_id, $ip]);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دبیرخانه</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/jalalidatepicker.min.css">
    <script src="assets/js/jalalidatepicker.min.js"></script>
    <script src="assets/js/persian-date.js"></script>
    <script src="assets/js/global.js"></script>
    

<!-- ===== Summernote ===== -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.js"></script>









    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; margin: 0; padding: 0; }
        body { font-family: 'Vazirmatn', sans-serif; background: #f6f8fa; color: #24292f; display: flex; flex-direction: column; min-height: 100vh; }
        a { text-decoration: none; }




/* ===== استایل دکمه‌های عملیات ===== */
.actions-bar {
    background: #ffffff;
    padding: 12px 20px;
    border-radius: 12px;
    border: 1px solid #e1e4e8;
    margin-bottom: 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}

/* ===== استایل پایه دکمه‌ها ===== */
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

.btn-action:hover::before {
    left: 100%;
}

.btn-action:hover {
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.btn-action:active {
    transform: translateY(0px) scale(0.97);
}

/* ===== دکمه صادره (آبی) ===== */
.btn-outgoing {
    background: linear-gradient(135deg, #1a73e8, #0d47a1);
}
.btn-outgoing:hover {
    background: linear-gradient(135deg, #1565c0, #0a3d8a);
    box-shadow: 0 8px 25px rgba(26,115,232,0.4);
}

/* ===== دکمه وارده (سبز) ===== */
.btn-incoming {
    background: linear-gradient(135deg, #0f9d58, #0b7a44);
}
.btn-incoming:hover {
    background: linear-gradient(135deg, #0c8b4a, #09693a);
    box-shadow: 0 8px 25px rgba(15,157,88,0.4);
}

/* ===== دکمه تنظیمات (بنفش) ===== */
.btn-settings-action {
    background: linear-gradient(135deg, #7c3aed, #5b21b6);
}
.btn-settings-action:hover {
    background: linear-gradient(135deg, #6d28d9, #4c1d95);
    box-shadow: 0 8px 25px rgba(124,58,237,0.4);
}

/* ===== دکمه مدیریت امضا (طلایی/نارنجی) ===== */
.btn-signature {
    background: linear-gradient(135deg, #ea580c, #c2410c);
}
.btn-signature:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    box-shadow: 0 8px 25px rgba(234,88,12,0.4);
}

/* ===== پاسخگویی موبایل ===== */
@media (max-width: 768px) {
    .actions-bar > div {
        flex-direction: column !important;
        align-items: stretch !important;
    }
    
    .actions-bar > div > div {
        justify-content: center !important;
        width: 100%;
    }
    
    .btn-action {
        min-width: 100% !important;
        justify-content: center;
        padding: 12px 16px;
    }
}
















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

        .filters-bar .btn-settings:hover { background: #6a3fc7; }

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
        .table-modern .status-badge { padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; white-space: nowrap; }
        .table-modern .type-badge { padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; white-space: nowrap; }
        .table-modern .priority-badge { padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; display: inline-block; white-space: nowrap; }
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
        .table-modern .actions .btn-icon.print { color: #8250df; }
        .table-modern .actions .btn-icon.print:hover { background: #f5f0ff; }
        .table-modern .actions .btn-icon.refer { color: #2da44e; }
        .table-modern .actions .btn-icon.refer:hover { background: #ddf4e6; }

        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            z-index: 2000; justify-content: center; align-items: center;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff; border-radius: 16px; padding: 30px 35px;
            max-width: 850px; width: 95%; max-height: 90vh; overflow-y: auto;
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
        .modal-box .form-group textarea { min-height: 100px; resize: vertical; }
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
        .file-upload-wrapper {
            border: 2px dashed #e1e4e8;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: #f8f9fa;
        }
        .file-upload-wrapper:hover { border-color: #0969da; background: #f0f4ff; }

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

       





/* ===== استایل Summernote ===== */
.note-editor {
    border-radius: 8px !important;
    border: 1px solid #e1e4e8 !important;
}

.note-editor .note-toolbar {
    background: #f8f9fa !important;
    border-bottom: 1px solid #e1e4e8 !important;
    border-radius: 8px 8px 0 0 !important;
}

.note-editor .note-editable {
    min-height: 300px !important;
    direction: rtl !important;
    text-align: right !important;
    font-family: 'Times New Roman', serif !important;
    font-size: 14pt !important;
    line-height: 2 !important;
    padding: 20px !important;
}

.note-editor .note-dropdown-menu {
    z-index: 9999999 !important;
}








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
            .filters-bar .btn-add, .filters-bar .btn-settings { margin-right: 0; }
            .filters-bar input[name="search"] { min-width: 100%; }
            .modal-box { padding: 20px; }
            .modal-box .form-row { flex-direction: column; }
            .modal-box .form-row .form-group { min-width: 100%; }
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
   <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand"><i class="fas fa-heartbeat"></i> سامانه مدیریت پیشگامان</div>
        <div class="sub">پنل مدیریت</div>
    </div>
     <ul class="sidebar-menu">
        <li class="label">منو</li>
        <li><a href="dashboard.php"><i class="fas fa-chart-pie"></i> داشبورد</a></li>
        <li><a href="secretariat.php" class="active"><i class="fas fa-inbox"></i> دبیرخانه</a></li>
        <li><a href="reminder.php"><i class="fas fa-bell"></i> یادآور</a></li>
        <li><a href="travel_expenses.php"><i class="fas fa-route"></i> مأموریت</a></li>
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

<main class="main-content">

    <div class="top-bar-wrapper">
        <div class="top-bar">
            <h1>
                <i class="fas fa-inbox" style="color: #0969da; margin-left: 10px;"></i>
                دبیرخانه
                <span>مدیریت مکاتبات</span>
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
        <div class="stat-card blue-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-envelope"></i></span><span class="stat-number"><?=persian_number($stats['total'])?></span></div><div class="stat-label">کل نامه‌ها</div></div>
        <div class="stat-card green-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-arrow-left"></i></span><span class="stat-number"><?=persian_number($stats['incoming']) ?></span></div><div class="stat-label">وارده</div></div>
        <div class="stat-card orange-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-arrow-right"></i></span><span class="stat-number"><?=persian_number($stats['outgoing']) ?></span></div><div class="stat-label">صادره</div></div>
        <div class="stat-card red-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-clock"></i></span><span class="stat-number"><?=persian_number( $stats['pending']) ?></span></div><div class="stat-label">در انتظار</div></div>
        <div class="stat-card purple-light"><div class="stat-top"><span class="stat-icon"><i class="fas fa-check-circle"></i></span><span class="stat-number"><?=persian_number( $stats['answered']) ?></span></div><div class="stat-label">پاسخ داده شده</div></div>
    </div>

    <!-- ===== نوار فیلترها ===== -->
    <div class="filters-bar">
        <div class="filter-group">
            <input type="text" id="searchInput" placeholder="جستجوی موضوع یا شماره..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        </div>
        <div class="filter-group">
            <select id="typeSelect">
                <option value="">همه نوع‌ها</option>
                <option value="incoming" <?= (isset($_GET['type']) && $_GET['type'] == 'incoming') ? 'selected' : '' ?>>وارده</option>
                <option value="outgoing" <?= (isset($_GET['type']) && $_GET['type'] == 'outgoing') ? 'selected' : '' ?>>صادره</option>
            </select>
        </div>
        <div class="filter-group">
            <select id="statusSelect">
                <option value="">همه وضعیت‌ها</option>
                <option value="draft" <?= (isset($_GET['status']) && $_GET['status'] == 'draft') ? 'selected' : '' ?>>پیش‌نویس</option>
                <option value="sent" <?= (isset($_GET['status']) && $_GET['status'] == 'sent') ? 'selected' : '' ?>>ارسال شده</option>
                <option value="received" <?= (isset($_GET['status']) && $_GET['status'] == 'received') ? 'selected' : '' ?>>دریافت شده</option>
                <option value="answered" <?= (isset($_GET['status']) && $_GET['status'] == 'answered') ? 'selected' : '' ?>>پاسخ داده شده</option>
                <option value="archived" <?= (isset($_GET['status']) && $_GET['status'] == 'archived') ? 'selected' : '' ?>>بایگانی</option>
            </select>
        </div>
        <div class="filter-group">
            <div class="date-wrapper">
                <input type="text" id="dateFrom" class="persian-date" placeholder="از تاریخ" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                <button type="button" class="calendar-icon" onclick="openDatePicker(this)">
                    <i class="fas fa-calendar-alt"></i>
                </button>
            </div>
            <span style="color:#8b93a5;">تا</span>
            <div class="date-wrapper">
                <input type="text" id="dateTo" class="persian-date" placeholder="تا تاریخ" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                <button type="button" class="calendar-icon" onclick="openDatePicker(this)">
                    <i class="fas fa-calendar-alt"></i>
                </button>
            </div>
        </div>
        <button type="button" class="btn-filter" onclick="applyFilters()"><i class="fas fa-search"></i> فیلتر</button>
        <a href="secretariat.php" class="btn-reset"><i class="fas fa-undo"></i> پاک کردن</a>
    </div>
    
    <!-- ===== دکمه‌های عملیات ===== -->
   <!-- ===== دکمه‌های عملیات ===== -->
<!-- ===== دکمه‌های عملیات ===== -->
<div class="actions-bar">
    <div style="display:flex;gap:10px;flex-wrap:wrap;width:100%;justify-content:space-between;align-items:center;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <!-- ===== نامه صادره جدید ===== -->
            <button type="button" class="btn-action btn-outgoing" onclick="openAddModal('outgoing');">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div style="text-align:right;line-height:1.3;">
                        <div style="font-weight:700;font-size:14px;">نامه صادره</div>
                        <div style="font-size:10px;opacity:0.8;">ثبت نامه جدید</div>
                    </div>
                </div>
            </button>
            
            <!-- ===== نامه وارده جدید ===== -->
            <button type="button" class="btn-action btn-incoming" onclick="openAddModal('incoming');">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <div style="text-align:right;line-height:1.3;">
                        <div style="font-weight:700;font-size:14px;">نامه وارده</div>
                        <div style="font-size:10px;opacity:0.8;">ثبت نامه دریافتی</div>
                    </div>
                </div>
            </button>
        </div>
        
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <!-- ===== تنظیمات ===== -->
            <button type="button" class="btn-action btn-settings-action" onclick="openSettings();">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <div style="text-align:right;line-height:1.3;">
                        <div style="font-weight:700;font-size:14px;">تنظیمات</div>
                        <div style="font-size:10px;opacity:0.8;">مدیریت سیستم</div>
                    </div>
                </div>
            </button>
            
            <!-- ===== مدیریت امضا ===== -->
            <button type="button" class="btn-action btn-signature" onclick="window.location.href='signature_settings.php'">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">
                        <i class="fas fa-pen-fancy"></i>
                    </div>
                    <div style="text-align:right;line-height:1.3;">
                        <div style="font-weight:700;font-size:14px;">مدیریت امضا</div>
                        <div style="font-size:10px;opacity:0.8;">امضاهای دیجیتال</div>
                    </div>
                </div>
            </button>
        </div>
    </div>
</div>

    <div class="table-wrapper">
        <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:120px;">شماره</th>
                        <th>موضوع</th>
                        <th style="width:100px;">نوع</th>
                        <th style="width:120px;">وضعیت</th>
                        <th style="width:120px;">اولویت</th>
                        <th style="width:110px;">تاریخ</th>
                        <th style="width:200px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($letters)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:40px;color:#8b93a5;">هیچ نامه‌ای یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($letters as $item): 
                            $status = getLetterStatus($item['status']);
                            $type = getLetterType($item['type']);
                            $priority = getPriority($item['priority']);
                        ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td style="font-size:13px;font-weight:600;color:#0969da;"><?= htmlspecialchars(persian_number($item['letter_number'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($item['subject']) ?></strong>
                                    <?php if (!empty($item['summary'])): ?>
                                        <div style="font-size:12px;color:#8b93a5;margin-top:2px;"><?= htmlspecialchars(mb_substr($item['summary'], 0, 60)) ?>...</div>
                                    <?php endif; ?>
                                    <?php if ($item['pending_referrals'] > 0): ?>
                                        <span style="display:inline-block;margin-top:4px;background:#fef3c7;color:#92400e;font-size:10px;padding:1px 8px;border-radius:10px;">
                                            <i class="fas fa-clock"></i> <?= $item['pending_referrals'] ?> ارجاع در انتظار
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="type-badge" style="background:<?= $type['color'] ?>;color:<?= $type['text'] ?>;"><?= $type['label'] ?></span></td>
                                <td><span class="status-badge" style="background:<?= $status['color'] ?>;color:<?= $status['text'] ?>;"><?= $status['label'] ?></span></td>
                                <td><span class="priority-badge" style="background:<?= $priority['color'] ?>;color:<?= $priority['text'] ?>;"><?= $priority['label'] ?></span></td>
				<td style="font-size:13px;color:#57606a;"><?= !empty($item['shamsi_date']) ? persian_number_str($item['shamsi_date']) : persian_number_str(nowShamsi()) ?></td>
                                <td>
                                    <div class="actions">
                                        <a href="#" class="btn-icon view" onclick="viewLetter(<?= $item['id'] ?>); return false;" title="مشاهده"><i class="fas fa-eye"></i></a>
                                        <a href="#" class="btn-icon edit" onclick="editLetter(<?= $item['id'] ?>); return false;" title="ویرایش"><i class="fas fa-edit"></i></a>
                                        <a href="#" class="btn-icon print" onclick="printLetter(<?= $item['id'] ?>); return false;" title="چاپ"><i class="fas fa-print"></i></a>
                                        <a href="#" class="btn-icon refer" onclick="referLetter(<?= $item['id'] ?>); return false;" title="ارجاع"><i class="fas fa-share"></i></a>
                                        <a href="#" class="btn-icon quick-status" onclick="quickChangeStatus(<?= $item['id'] ?>); return false;" title="تغییر وضعیت"><i class="fas fa-exchange-alt"></i></a>
                                        <a href="#" class="btn-icon delete" onclick="deleteLetter(<?= $item['id'] ?>); return false;" title="حذف"><i class="fas fa-trash-alt"></i></a>
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

<div class="footer">
    <div class="footer-content">
        <span>طراحی و توسعه: <a href="#" target="_blank">شرکت دانش بنیان پیشگامان دنیای فناوری</a></span>
        <span class="footer-divider">|</span>
        <span class="footer-version">نسخه 2.0</span>
    </div>
</div>

<!-- ===== مودال نامه ===== -->
<!-- ===== مودال نامه ===== -->
<div class="modal-overlay" id="letterModal">
    <div class="modal-box">
        <h2 id="letterModalTitle">نامه جدید</h2>
        <form method="POST" action="" enctype="multipart/form-data" autocomplete="off" id="letterForm">
            <input type="hidden" name="letter_action" id="letter_action" value="add">
            <input type="hidden" name="letter_id" id="letter_id" value="0">
            <input type="hidden" name="letter_type" id="letter_type_hidden" value="outgoing">
            
            <!-- ===== انتخاب نوع نامه ===== -->
            <div class="form-group">
                <label>نوع نامه</label>
                <select name="type" id="letter_type" onchange="toggleLetterType(this.value)">
                    <option value="outgoing">📤 صادره</option>
                    <option value="incoming">📥 وارده</option>
                </select>
            </div>
<!-- ===== شماره نامه (دستی برای وارده) ===== -->
<div class="form-group" id="letter_number_section">
    <label id="letter_number_label">شماره نامه</label>
    <input type="text" name="letter_number_manual" id="letter_number_manual" placeholder="مثلاً: ۱۲۳۴۵/۹۸" autocomplete="off">
    <small style="color:#8b93a5;font-size:12px;" id="letter_number_hint">برای نامه‌های وارده، شماره درج شده روی نامه را وارد کنید</small>
</div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ ثبت (شمسی)</label>
                    <div class="date-wrapper">
                        <input type="text" name="created_date" id="created_date" class="persian-date" placeholder="تاریخ ثبت" value="<?= nowShamsi() ?>" autocomplete="off">
                        <button type="button" class="calendar-icon" onclick="openDatePicker(this)">
                            <i class="fas fa-calendar-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label>تاریخ نامه (شمسی)</label>
                    <div class="date-wrapper">
                        <input type="text" name="letter_date" id="letter_date" class="persian-date" placeholder="تاریخ نامه" value="<?= nowShamsi() ?>" autocomplete="off">
                        <button type="button" class="calendar-icon" onclick="openDatePicker(this)">
                            <i class="fas fa-calendar-alt"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>اولویت</label>
                    <select name="priority" id="letter_priority">
                        <option value="low">کم</option>
                        <option value="medium" selected>متوسط</option>
                        <option value="high">زیاد</option>
                        <option value="urgent">فوری</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>وضعیت</label>
                    <select name="status" id="letter_status">
                        <option value="draft">پیش‌نویس</option>
                        <option value="sent">ارسال شده</option>
                        <option value="received" selected>دریافت شده</option>
                        <option value="answered">پاسخ داده شده</option>
                        <option value="archived">بایگانی</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>موضوع <span style="color:red;">*</span></label>
                <input type="text" name="subject" id="letter_subject" required>
            </div>
            
            <div class="form-group">
                <label>خلاصه</label>
                <input type="text" name="summary" id="letter_summary">
            </div>
            
            <!-- ===== متن نامه (فقط برای صادره) ===== -->
            <div class="form-group" id="content_section">
                <label>متن نامه</label>
                <textarea name="content" id="letter_content" rows="10"></textarea>
            </div>

            <!-- ===== سربرگ (فقط برای صادره) ===== -->
            <div class="form-group" id="header_section">
                <label>سربرگ</label>
                <select name="header_id" id="letter_header">
                    <option value="">بدون سربرگ</option>
                    <?php foreach ($headers as $h): ?>
                        <option value="<?= $h['id'] ?>" <?= $h['is_default'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($h['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- ===== بخش امضا (فقط برای صادره) ===== -->
            <div class="sub-section" id="signature_section">
                <div class="sub-section-title"><i class="fas fa-signature"></i> امضاء</div>
                <div class="form-group">
                    <label>انتخاب امضا</label>
                    <select name="signature_id" id="signature_id" onchange="loadSignature()">
                        <option value="">انتخاب کنید...</option>
                        <?php 
                        $sig_list = $pdo->query("SELECT * FROM signatures ORDER BY is_default DESC, name")->fetchAll();
                        foreach ($sig_list as $sig): 
                        ?>
                            <option value="<?= $sig['id'] ?>" <?= $sig['is_default'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sig['name']) ?> (<?= htmlspecialchars($sig['position']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="signature_preview_area" style="display:none;margin-top:10px;padding:10px;background:#f8f9fa;border-radius:8px;border:1px solid #e1e4e8;">
                    <label style="font-size:13px;font-weight:600;color:#57606a;">پیش‌نمایش امضا:</label>
                    <div style="margin-top:6px;">
                        <img id="signature_preview_image" src="" style="max-height:60px;max-width:200px;">
                    </div>
                </div>
            </div>
            
            <!-- ===== اسکن نامه وارده (اجباری برای وارده) ===== -->
<!-- ===== اسکن نامه وارده ===== -->
<div class="sub-section" id="scan_section">
    <div class="sub-section-title"><i class="fas fa-scanner"></i> اسکن نامه وارده <span style="color:red;">*</span></div>
    <p style="font-size:13px;color:#57606a;margin-bottom:10px;">
        فایل اسکن شده نامه را آپلود کنید یا با دوربین اسکن بگیرید (PDF, JPG, PNG)
    </p>
    
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <!-- ===== دکمه آپلود ===== -->
        <div class="file-upload-wrapper" onclick="document.getElementById('scan_file').click()" style="border-color:#0969da;padding:20px;flex:2;min-width:200px;">
            <i class="fas fa-upload" style="font-size:24px;color:#0969da;display:block;margin-bottom:8px;"></i>
            <div>آپلود فایل اسکن</div>
            <div id="scan_file_name" style="font-size:12px;color:#2da44e;display:none;margin-top:6px;font-weight:600;"></div>
        </div>
        <input type="file" name="scan_file" id="scan_file" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" 
               onchange="document.getElementById('scan_file_name').textContent = '📎 ' + this.files[0].name; 
                       document.getElementById('scan_file_name').style.display = 'block';">
        
        <!-- ===== دکمه اسکن با دوربین ===== -->
        <button type="button" class="btn-settings" onclick="openScanner()" style="background:#2da44e;padding:20px;flex:1;min-width:150px;border:none;border-radius:8px;color:#fff;cursor:pointer;font-family:'Vazirmatn',sans-serif;">
            <i class="fas fa-camera" style="font-size:24px;display:block;margin-bottom:8px;"></i>
اسکنر یا دوربین
        </button>
    </div>
</div>
            
            <!-- ===== پیوست‌ها (اختیاری) ===== -->
            <div class="sub-section" id="attachment_section">
                <div class="sub-section-title"><i class="fas fa-paperclip"></i> پیوست‌ها (اختیاری)</div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="has_attachment" id="has_attachment" value="1" onchange="toggleAttachmentFields()">
                        این نامه دارای پیوست است
                    </label>
                </div>
                <div id="attachment_fields" style="display:none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label>تعداد پیوست</label>
                            <input type="number" name="attachment_count" id="attachment_count" value="1" min="1">
                        </div>
                        <div class="form-group">
                            <label>توضیحات پیوست</label>
                            <input type="text" name="attachment_description" id="attachment_description" placeholder="مثلاً: مدارک هویتی، قرارداد، ...">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>آپلود فایل پیوست</label>
                        <div class="file-upload-wrapper" onclick="document.getElementById('attachment_file').click()">
                            <i class="fas fa-upload" style="font-size:24px;color:#0969da;display:block;margin-bottom:8px;"></i>
                            <div>برای آپلود فایل کلیک کنید</div>
                            <div id="attachment_file_name" style="font-size:12px;color:#2da44e;display:none;margin-top:6px;"></div>
                        </div>
                        <input type="file" name="attachment_file" id="attachment_file" style="display:none;" 
                               onchange="document.getElementById('attachment_file_name').textContent = '📎 ' + this.files[0].name; 
                                       document.getElementById('attachment_file_name').style.display = 'block';">
                    </div>
                </div>
            </div>
            
            <!-- ===== اطلاعات فرستنده/گیرنده ===== -->
            <div class="sub-section">
                <div class="sub-section-title" id="contact_title"><i class="fas fa-user"></i> اطلاعات گیرنده</div>
                <div class="form-row">
                    <div class="form-group">
                        <label id="contact_name_label">نام گیرنده</label>
                        <input type="text" name="receiver_name" id="letter_receiver_name" placeholder="نام گیرنده">
                    </div>
                    <div class="form-group">
                        <label id="contact_org_label">سازمان گیرنده</label>
                        <input type="text" name="receiver_organization" id="letter_receiver_org" placeholder="سازمان">
                    </div>
                </div>
                <div class="form-group">
                    <label id="contact_phone_label">تلفن گیرنده</label>
                    <input type="text" name="receiver_phone" id="letter_receiver_phone" placeholder="تلفن">
                </div>
            </div>
            
            <!-- ===== اطلاعات فرستنده (برای وارده) ===== -->
            <div class="sub-section" id="sender_section">
                <div class="sub-section-title"><i class="fas fa-user-tie"></i> اطلاعات فرستنده (وارده)</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>نام فرستنده</label>
                        <input type="text" name="sender_name" id="letter_sender_name" placeholder="نام فرستنده">
                    </div>
                    <div class="form-group">
                        <label>سازمان فرستنده</label>
                        <input type="text" name="sender_organization" id="letter_sender_org" placeholder="سازمان">
                    </div>
                </div>
                <div class="form-group">
                    <label>تلفن فرستنده</label>
                    <input type="text" name="sender_phone" id="letter_sender_phone" placeholder="تلفن">
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('letterModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره نامه</button>
            </div>
        </form>
    </div>
</div>






<!-- ===== مودال تغییر وضعیت ===== -->
<div class="modal-overlay" id="quickStatusModal">
    <div class="modal-box">
        <h2>تغییر وضعیت نامه</h2>
        <form method="POST" action="">
            <input type="hidden" name="letter_action" value="change_status">
            <input type="hidden" name="letter_id" id="quick_status_letter_id" value="0">
            <div class="form-group">
                <label>وضعیت جدید</label>
                <select name="new_status" id="quick_status_select">
                    <option value="draft">پیش‌نویس</option>
                    <option value="sent">ارسال شده</option>
                    <option value="received">دریافت شده</option>
                    <option value="answered">پاسخ داده شده</option>
                    <option value="archived">بایگانی</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('quickStatusModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">تغییر وضعیت</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== مودال ارجاع ===== -->
<div class="modal-overlay" id="referModal">
    <div class="modal-box">
        <h2>ارجاع نامه</h2>
        <form method="POST" action="">
            <input type="hidden" name="letter_action" value="refer">
            <input type="hidden" name="letter_id" id="refer_letter_id" value="0">
            <div class="form-group">
                <label>ارجاع به</label>
                <select name="referred_to" id="refer_referred_to">
                    <option value="">انتخاب کنید...</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name'] ?? $u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>واحد ارجاع</label>
                <input type="text" name="referred_to_unit" id="refer_referred_unit" placeholder="نام واحد">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ ارجاع (شمسی)</label>
                    <div class="date-wrapper">
                        <input type="text" name="referral_date" id="refer_referral_date" class="persian-date" placeholder="تاریخ ارجاع" value="<?= nowShamsi() ?>" autocomplete="off">
                        <button type="button" class="calendar-icon" onclick="openDatePicker(this)">
                            <i class="fas fa-calendar-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label>تاریخ پاسخ (شمسی)</label>
                    <div class="date-wrapper">
                        <input type="text" name="due_date" id="refer_due_date" class="persian-date" placeholder="تاریخ پاسخ" autocomplete="off">
                        <button type="button" class="calendar-icon" onclick="openDatePicker(this)">
                            <i class="fas fa-calendar-alt"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <textarea name="referral_description" id="refer_description" rows="3"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('referModal')">انصراف</button>
                <button type="submit" class="btn btn-primary">ارجاع</button>
            </div>
        </form>
    </div>
</div>

<button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>

<script>




function toggleAttachmentFields() {
    var hasAttachment = document.getElementById('has_attachment');
    if (!hasAttachment) return;
    var attachmentFields = document.getElementById('attachment_fields');
    if (hasAttachment.checked) {
        attachmentFields.style.display = 'block';
    } else {
        attachmentFields.style.display = 'none';
        var fileInput = document.getElementById('attachment_file');
        if (fileInput) fileInput.value = '';
        var fileName = document.getElementById('attachment_file_name');
        if (fileName) fileName.style.display = 'none';
    }
}

function loadSignature() {
    var sigId = document.getElementById('signature_id');
    if (!sigId) return;
    var previewArea = document.getElementById('signature_preview_area');
    var previewImg = document.getElementById('signature_preview_image');
    if (sigId.value) {
        fetch('get_signature.php?id=' + sigId.value)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.image_path) {
                    previewImg.src = data.image_path;
                    previewArea.style.display = 'block';
                } else {
                    previewArea.style.display = 'none';
                }
            })
            .catch(function() { previewArea.style.display = 'none'; });
    } else {
        previewArea.style.display = 'none';
    }
}

// ===== Summernote =====
let editorInstance = false;

function initEditor() {
    if (editorInstance) return;
    const textarea = document.getElementById('letter_content');
    if (!textarea) return;
    if (textarea.offsetParent === null) {
        setTimeout(initEditor, 200);
        return;
    }
    
    if ($('#letter_content').summernote) {
        try {
            $('#letter_content').summernote('destroy');
        } catch(e) {}
    }
    
    $('#letter_content').summernote({
        height: 400,
        lang: 'fa-IR',
        direction: 'rtl',
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'underline', 'clear']],
            ['fontname', ['fontname']],
            ['fontsize', ['fontsize']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'video']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        fontNames: ['Arial', 'Arial Black', 'Comic Sans MS', 'Courier New', 'Georgia', 'Tahoma', 'Times New Roman', 'Verdana'],
        fontSizes: ['8', '9', '10', '11', '12', '14', '16', '18', '20', '24', '28', '32', '36', '48', '72'],
        callbacks: {
            onChange: function(contents) {
                document.getElementById('letter_content').value = contents;
            },
            onInit: function() {
                console.log('✅ Summernote راه‌اندازی شد');
                editorInstance = true;
            }
        }
    });
    
    editorInstance = true;
}


// ===== تغییر نوع نامه =====
function toggleLetterType(type) {
    var contentSection = document.getElementById('content_section');
    var signatureSection = document.getElementById('signature_section');
    var headerSection = document.getElementById('header_section');
    var senderSection = document.getElementById('sender_section');
    var scanSection = document.getElementById('scan_section');
    var attachmentSection = document.getElementById('attachment_section');
    var letterNumberSection = document.getElementById('letter_number_section');
    var letterNumberLabel = document.getElementById('letter_number_label');
    var letterNumberHint = document.getElementById('letter_number_hint');
    var letterNumberInput = document.getElementById('letter_number_manual');
    var contactTitle = document.getElementById('contact_title');
    var contactNameLabel = document.getElementById('contact_name_label');
    var contactOrgLabel = document.getElementById('contact_org_label');
    var contactPhoneLabel = document.getElementById('contact_phone_label');
    var typeHidden = document.getElementById('letter_type_hidden');
    var statusField = document.getElementById('letter_status');
    
    if (type === 'incoming') {
        // ===== وارده =====
        contentSection.style.display = 'none';
        signatureSection.style.display = 'none';
        headerSection.style.display = 'none';
        senderSection.style.display = 'block';
        scanSection.style.display = 'block';
        attachmentSection.style.display = 'block';
        
        // ===== نمایش فیلد شماره دستی =====
        letterNumberSection.style.display = 'block';
        letterNumberLabel.textContent = 'شماره نامه (درج شده روی نامه)';
        letterNumberHint.style.display = 'block';
        letterNumberInput.required = true;
        letterNumberInput.placeholder = 'مثلاً: ۱۲۳۴۵/۹۸';
        
        contactTitle.innerHTML = '<i class="fas fa-user"></i> اطلاعات گیرنده (من)';
        contactNameLabel.textContent = 'نام گیرنده (من)';
        contactOrgLabel.textContent = 'سازمان گیرنده (من)';
        contactPhoneLabel.textContent = 'تلفن گیرنده (من)';
        
        statusField.value = 'received';
        typeHidden.value = 'incoming';
        
        document.querySelector('#scan_section .sub-section-title').innerHTML = 
            '<i class="fas fa-scanner"></i> اسکن نامه وارده <span style="color:red;">*</span>';
            
    } else {
        // ===== صادره =====
        contentSection.style.display = 'block';
        signatureSection.style.display = 'block';
        headerSection.style.display = 'block';
        senderSection.style.display = 'none';
        scanSection.style.display = 'none';
        attachmentSection.style.display = 'block';
        
        // ===== مخفی کردن فیلد شماره دستی =====
        letterNumberSection.style.display = 'none';
        letterNumberInput.required = false;
        letterNumberInput.value = '';
        
        contactTitle.innerHTML = '<i class="fas fa-user"></i> اطلاعات گیرنده';
        contactNameLabel.textContent = 'نام گیرنده';
        contactOrgLabel.textContent = 'سازمان گیرنده';
        contactPhoneLabel.textContent = 'تلفن گیرنده';
        
        statusField.value = 'sent';
        typeHidden.value = 'outgoing';
        
        document.querySelector('#scan_section .sub-section-title').innerHTML = 
            '<i class="fas fa-scanner"></i> اسکن نامه وارده';
    }
}






// ===== باز کردن دوربین برای اسکن =====
function openScanner() {
    // ===== بررسی وجود دوربین =====
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('مرورگر شما از دوربین پشتیبانی نمی‌کند. لطفاً فایل را آپلود کنید.');
        return;
    }
    
    // ===== ایجاد مودال =====
    var scanModal = document.createElement('div');
    scanModal.id = 'scanModal';
    scanModal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:20px;';
    
    scanModal.innerHTML = `
        <div style="background:#fff;border-radius:16px;padding:20px;max-width:600px;width:100%;">
            <h3 style="margin-bottom:15px;text-align:center;">اسکن با دوربین</h3>
            <div id="scannerStatus" style="text-align:center;padding:20px;color:#57606a;">
                <i class="fas fa-spinner fa-spin" style="font-size:24px;"></i>
                <p>در حال اتصال به دوربین...</p>
            </div>
            <video id="scannerVideo" style="width:100%;border-radius:8px;background:#000;display:none;" autoplay playsinline></video>
            <canvas id="scannerCanvas" style="display:none;"></canvas>
            <div style="display:flex;gap:10px;margin-top:15px;justify-content:center;">
                <button onclick="captureScan()" id="captureBtn" style="padding:10px 30px;background:#0969da;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:'Vazirmatn',sans-serif;display:none;">
                    <i class="fas fa-camera"></i> ثبت اسکن
                </button>
                <button onclick="closeScanner()" style="padding:10px 30px;background:#f0f2f4;color:#57606a;border:none;border-radius:8px;cursor:pointer;font-family:'Vazirmatn',sans-serif;">
                    انصراف
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(scanModal);
    
    var video = document.getElementById('scannerVideo');
    var status = document.getElementById('scannerStatus');
    var captureBtn = document.getElementById('captureBtn');
    
    // ===== تلاش برای دسترسی به دوربین =====
    navigator.mediaDevices.getUserMedia({ 
        video: { 
            facingMode: 'environment',
            width: { ideal: 1280 },
            height: { ideal: 720 }
        } 
    })
    .then(function(stream) {
        video.srcObject = stream;
        video.style.display = 'block';
        status.style.display = 'none';
        captureBtn.style.display = 'inline-block';
        video.play();
    })
    .catch(function(err) {
        // ===== مدیریت خطاهای مختلف =====
        var errorMsg = '';
        switch(err.name) {
            case 'NotFoundError':
            case 'DevicesNotFoundError':
                errorMsg = 'دوربین یا اسکنر روی دستگاه شما یافت نشد. لطفاً فایل را آپلود کنید.';
                break;
            case 'NotAllowedError':
            case 'PermissionDeniedError':
                errorMsg = 'دسترسی به دوربین یا اسکنر مجاز نیست. لطفاً در تنظیمات مرورگر دسترسی را فعال کنید.';
                break;
            case 'NotReadableError':
            case 'TrackStartError':
                errorMsg = 'دوربین یا اسکنر در حال استفاده توسط برنامه دیگری است. لطفاً آن را ببندید و دوباره تلاش کنید.';
                break;
            case 'OverconstrainedError':
                errorMsg = 'دوربین یا اسکنر مورد نظر یافت نشد. لطفاً فایل را آپلود کنید.';
                break;
            default:
                errorMsg = ' خطا در دسترسی به دوربین یا اسکنر: ' + err.message;
        }
        
        status.innerHTML = '<i class="fas fa-exclamation-triangle" style="font-size:24px;color:#cf222e;"></i><p style="color:#cf222e;">' + errorMsg + '</p>';
        status.style.color = '#cf222e';
        
        // ===== بعد از 3 ثانیه مودال را ببند =====
        setTimeout(function() {
            closeScanner();
            alert(errorMsg);
        }, 3000);
    });
}

// ===== بستن دوربین =====
function closeScanner() {
    var modal = document.getElementById('scanModal');
    if (modal) {
        var video = document.getElementById('scannerVideo');
        if (video && video.srcObject) {
            video.srcObject.getTracks().forEach(function(track) { 
                track.stop(); 
            });
            video.srcObject = null;
        }
        modal.remove();
    }
}

// ===== ثبت اسکن از دوربین =====
function captureScan() {
    var video = document.getElementById('scannerVideo');
    var canvas = document.getElementById('scannerCanvas');
    var context = canvas.getContext('2d');
    
    if (!video || video.readyState !== 4) {
        alert('لطفاً صبر کنید تا دوربین کامل آماده شود.');
        return;
    }
    
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    canvas.toBlob(function(blob) {
        if (!blob) {
            alert('خطا در ثبت اسکن. لطفاً دوباره تلاش کنید.');
            return;
        }
        
        var file = new File([blob], 'scan_' + Date.now() + '.png', { type: 'image/png' });
        
        var dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        document.getElementById('scan_file').files = dataTransfer.files;
        
        document.getElementById('scan_file_name').textContent = '📸 ' + file.name;
        document.getElementById('scan_file_name').style.display = 'block';
        
        closeScanner();
        
        alert('اسکن با موفقیت ثبت شد!');
    }, 'image/png');
}










// ===== توابع مودال =====
function openAddModal(type) {
    console.log('📝 باز کردن مودال جدید - نوع: ' + type);
    
    var modal = document.getElementById('letterModal');
    modal.classList.add('active');
    
    document.getElementById('letterModalTitle').textContent = (type === 'incoming') ? 'نامه وارده جدید' : 'نامه صادره جدید';
    document.getElementById('letter_action').value = 'add';
    document.getElementById('letter_id').value = 0;
    document.getElementById('letter_subject').value = '';
    document.getElementById('letter_summary').value = '';
    document.getElementById('letter_priority').value = 'medium';
    document.getElementById('letter_header').value = '';
    document.getElementById('letter_receiver_name').value = '';
    document.getElementById('letter_receiver_org').value = '';
    document.getElementById('letter_receiver_phone').value = '';
    document.getElementById('letter_sender_name').value = '';
    document.getElementById('letter_sender_org').value = '';
    document.getElementById('letter_sender_phone').value = '';
    document.getElementById('created_date').value = '<?= nowShamsi() ?>';
    document.getElementById('letter_date').value = '<?= nowShamsi() ?>';
    document.getElementById('has_attachment').checked = false;
    document.getElementById('attachment_count').value = '1';
    document.getElementById('attachment_description').value = '';
    document.getElementById('signature_id').value = '';
    document.getElementById('signature_preview_area').style.display = 'none';
    document.getElementById('attachment_file').value = '';
    document.getElementById('attachment_file_name').style.display = 'none';
    document.getElementById('scan_file').value = '';
    document.getElementById('scan_file_name').style.display = 'none';
    document.getElementById('letter_content').value = '';
    
    document.getElementById('letter_type').value = type;
    toggleLetterType(type);
    
    setTimeout(function() {
        initEditor();
    }, 300);
}

function editLetter(id) {
    console.log('📝 ویرایش نامه: ' + id);
    fetch('get_letter.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('letterModalTitle').textContent = 'ویرایش نامه';
                document.getElementById('letter_action').value = 'edit';
                document.getElementById('letter_id').value = id;
                document.getElementById('letter_subject').value = data.subject || '';
                document.getElementById('letter_summary').value = data.summary || '';
                document.getElementById('letter_priority').value = data.priority || 'medium';
                document.getElementById('letter_status').value = data.status || 'draft';
                document.getElementById('letter_header').value = data.header_id || '';
                document.getElementById('letter_receiver_name').value = data.receiver_name || '';
                document.getElementById('letter_receiver_org').value = data.receiver_organization || '';
                document.getElementById('letter_receiver_phone').value = data.receiver_phone || '';
                document.getElementById('letter_sender_name').value = data.sender_name || '';
                document.getElementById('letter_sender_org').value = data.sender_organization || '';
                document.getElementById('letter_sender_phone').value = data.sender_phone || '';
                document.getElementById('created_date').value = data.shamsi_date || '<?= nowShamsi() ?>';
                document.getElementById('letter_date').value = data.shamsi_letter_date || '<?= nowShamsi() ?>';
                document.getElementById('has_attachment').checked = data.has_attachment == 1;
                document.getElementById('attachment_count').value = data.attachment_count || '1';
                document.getElementById('attachment_description').value = data.attachment_description || '';
                document.getElementById('signature_id').value = data.signature_id || '';
                if (data.signature_id) { loadSignature(); }
                toggleAttachmentFields();
                if (data.type === 'incoming') {
                    document.getElementById('sender_section').style.display = 'block';
                } else {
                    document.getElementById('sender_section').style.display = 'none';
                }
                document.getElementById('letter_content').value = data.content || '';
                document.getElementById('letterModal').classList.add('active');
                setTimeout(function() {
                    if ($('#letter_content').summernote) {
                        try {
                            $('#letter_content').summernote('destroy');
                        } catch(e) {}
                    }
                    initEditor();
                    setTimeout(function() {
                        if ($('#letter_content').summernote) {
                            $('#letter_content').summernote('code', data.content || '');
                        }
                    }, 300);
                }, 300);
            } else {
                alert('خطا: ' + (data.error || 'نامشخص'));
            }
        })
        .catch(function() {
            alert('خطا در ارتباط با سرور');
        });
}

// ===== بقیه توابع به همان شکل =====
function viewLetter(id) {
    window.open('view_letter.php?id=' + id, '_blank');
}

function printLetter(id) {
    window.open('print_letter.php?id=' + id, '_blank');
}

function referLetter(id) {
    document.getElementById('refer_letter_id').value = id;
    document.getElementById('refer_referred_to').value = '';
    document.getElementById('refer_referred_unit').value = '';
    document.getElementById('refer_referral_date').value = '<?= nowShamsi() ?>';
    document.getElementById('refer_due_date').value = '';
    document.getElementById('refer_description').value = '';
    document.getElementById('referModal').classList.add('active');
}

function deleteLetter(id) {
    if (confirm('آیا از حذف این نامه مطمئن هستید؟ این عملیات غیرقابل بازگشت است!')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        var input1 = document.createElement('input');
        input1.type = 'hidden';
        input1.name = 'letter_action';
        input1.value = 'delete';
        var input2 = document.createElement('input');
        input2.type = 'hidden';
        input2.name = 'letter_id';
        input2.value = id;
        form.appendChild(input1);
        form.appendChild(input2);
        document.body.appendChild(form);
        form.submit();
    }
}

function quickChangeStatus(id) {
    document.getElementById('quick_status_letter_id').value = id;
    document.getElementById('quick_status_select').value = 'draft';
    document.getElementById('quickStatusModal').classList.add('active');
}

function openSettings() {
    window.location.href = 'letter_settings.php';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    if (id === 'letterModal') {
        if ($('#letter_content').summernote) {
            try {
                $('#letter_content').summernote('destroy');
            } catch(e) {}
        }
        editorInstance = false;
    }
    document.querySelectorAll('.modal-overlay').forEach(function(modal) {
        modal.style.pointerEvents = 'auto';
    });
}

function applyFilters() {
    var search = document.getElementById('searchInput').value;
    var type = document.getElementById('typeSelect').value;
    var status = document.getElementById('statusSelect').value;
    var dateFrom = document.getElementById('dateFrom').value;
    var dateTo = document.getElementById('dateTo').value;
    var url = 'secretariat.php?';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    if (type) url += 'type=' + encodeURIComponent(type) + '&';
    if (status) url += 'status=' + encodeURIComponent(status) + '&';
    if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
    if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo) + '&';
    window.location.href = url;
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

document.getElementById('letterForm').addEventListener('submit', function(e) {
    if ($('#letter_content').summernote) {
        document.getElementById('letter_content').value = $('#letter_content').summernote('code');
    }
});

var mobileToggle = document.getElementById('mobileToggle');
if (mobileToggle) {
    mobileToggle.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    });
}
var sidebarOverlay = document.getElementById('sidebarOverlay');
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    });
}

console.log('✅ دبیرخانه با Summernote بارگذاری شد');











</script>
</body>
</html>










====================send_reminder.php==================
<?php
require_once 'config.php';
require_once 'includes/ReminderService.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    die('شناسه یادآور نامعتبر است');
}

$service = new ReminderService($pdo);
$result = $service->sendReminder($id);

if ($result['success']) {
    echo "✅ پیامک با موفقیت ارسال شد!";
    echo "<br><a href='reminder.php'>بازگشت به لیست یادآورها</a>";
} else {
    echo "❌ خطا: " . $result['error'];
    echo "<br><a href='reminder.php'>بازگشت به لیست یادآورها</a>";
}


==================send_reminder_now.php=============
<?php
/**
 * ارسال فوری یک یادآور
 */

require_once 'config.php';
require_once 'functions.php';
require_once 'includes/ReminderService.php';
requireLogin();

$id = $_GET['id'] ?? 0;

if ($id) {
    $reminderService = new ReminderService($pdo);
    $result = $reminderService->sendReminder($id);
    
    if ($result['success']) {
        $_SESSION['success'] = 'یادآور با موفقیت ارسال شد.';
    } else {
        $_SESSION['error'] = 'خطا در ارسال: ' . $result['error'];
    }
}

header('Location: reminder.php');
exit;

============sidebar.php=================
<!-- ===== سایدبار ===== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand"><i class="fas fa-heartbeat"></i> سامانه مدیریت پیشگامان</div>
        <div class="sub">پنل مدیریت</div>
    </div>
    <ul class="sidebar-menu">
        <li class="label">منو</li>
        <li><a href="dashboard.php" class="active"><i class="fas fa-chart-pie"></i> داشبورد</a></li>
        
        <!-- ماژول‌های اصلی -->
        <li><a href="knowledge.php"><i class="fas fa-database"></i> بانک دانش</a></li>
        <li><a href="links.php"><i class="fas fa-link"></i> بانک لینک</a></li>
        <li><a href="downloads.php"><i class="fas fa-download"></i> مرکز دانلود</a></li>
        <li><a href="assets.php"><i class="fas fa-boxes"></i> بانک اموال</a></li>
        <li><a href="users.php"><i class="fas fa-users"></i> مدیریت کاربران</a></li>
        
        <!-- ماژول‌های جدید -->
        <li><a href="secretariat.php"><i class="fas fa-inbox"></i> دبیرخانه</a></li>
        <li><a href="reminder.php"><i class="fas fa-bell"></i> یادآور</a></li>
        
        <li class="divider"></li>
        <li class="label">سیستم</li>
        <li><a href="settings.php"><i class="fas fa-cog"></i> تنظیمات</a></li>
    </ul>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> خروج</a>
    </div>
</aside>

=============signature_settings.php================

<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'مدیریت امضاها';

// ===== دریافت لیست امضاها =====
$signatures = $pdo->query("SELECT * FROM signatures ORDER BY name")->fetchAll();

// ===== پردازش فرم =====
if (isset($_POST['signature_action'])) {
    $action = $_POST['signature_action'];
    $sig_id = $_POST['sig_id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    
    $image_path = '';
    if (isset($_FILES['image_upload']) && $_FILES['image_upload']['error'] == 0) {
        $upload_dir = 'uploads/signatures/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file = $_FILES['image_upload'];
        $file_name = time() . '_' . basename($file['name']);
        $image_path = $upload_dir . $file_name;
        move_uploaded_file($file['tmp_name'], $image_path);
    }
    
    try {
        if ($action === 'add' && !empty($name) && !empty($image_path)) {
            $stmt = $pdo->prepare("INSERT INTO signatures (name, position, image_path, is_default) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $position, $image_path, $is_default]);
            $_SESSION['success'] = 'امضا با موفقیت اضافه شد.';
        } elseif ($action === 'edit' && !empty($name) && $sig_id > 0) {
            if (!empty($image_path)) {
                $old = $pdo->prepare("SELECT image_path FROM signatures WHERE id = ?");
                $old->execute([$sig_id]);
                $old_img = $old->fetch();
                if ($old_img && !empty($old_img['image_path']) && file_exists($old_img['image_path'])) {
                    unlink($old_img['image_path']);
                }
                $stmt = $pdo->prepare("UPDATE signatures SET name = ?, position = ?, image_path = ?, is_default = ? WHERE id = ?");
                $stmt->execute([$name, $position, $image_path, $is_default, $sig_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE signatures SET name = ?, position = ?, is_default = ? WHERE id = ?");
                $stmt->execute([$name, $position, $is_default, $sig_id]);
            }
            $_SESSION['success'] = 'امضا با موفقیت ویرایش شد.';
        } elseif ($action === 'delete' && $sig_id > 0) {
            $old = $pdo->prepare("SELECT image_path FROM signatures WHERE id = ?");
            $old->execute([$sig_id]);
            $old_img = $old->fetch();
            if ($old_img && !empty($old_img['image_path']) && file_exists($old_img['image_path'])) {
                unlink($old_img['image_path']);
            }
            $stmt = $pdo->prepare("DELETE FROM signatures WHERE id = ?");
            $stmt->execute([$sig_id]);
            $_SESSION['success'] = 'امضا با موفقیت حذف شد.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا: ' . $e->getMessage();
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
    <title>مدیریت امضاها</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Vazirmatn', sans-serif; background: #f6f8fa; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .page-header {
            background: #fff;
            padding: 20px 24px;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-header h1 { font-size: 20px; font-weight: 700; }
        .page-header h1 i { color: #0969da; margin-left: 10px; }
        .btn-back {
            padding: 8px 20px;
            background: #f0f2f4;
            color: #57606a;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-back:hover { background: #e1e4e8; }
        
        .card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e1e4e8;
            background: #f8f9fa;
            font-weight: 600;
            font-size: 15px;
        }
        .card-header i { margin-left: 8px; color: #0969da; }
        .card-body { padding: 20px; }
        
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #57606a; margin-bottom: 4px; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #e1e4e8;
            font-size: 14px;
            font-family: 'Vazirmatn', sans-serif;
            outline: none;
            background: #f8f9fa;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: #0969da;
            box-shadow: 0 0 0 3px rgba(9,105,218,0.1);
        }
        .form-row { display: flex; gap: 12px; flex-wrap: wrap; }
        .form-row .form-group { flex: 1; min-width: 120px; }
        
        .btn {
            padding: 8px 24px;
            border-radius: 6px;
            border: none;
            font-size: 14px;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-primary { background: #0969da; color: #fff; }
        .btn-primary:hover { background: #0550b3; }
        .btn-success { background: #2da44e; color: #fff; }
        .btn-success:hover { background: #22863a; }
        .btn-danger { background: #cf222e; color: #fff; }
        .btn-danger:hover { background: #a0111f; }
        .btn-secondary { background: #f0f2f4; color: #57606a; }
        .btn-secondary:hover { background: #e1e4e8; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        
        .table-mini { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-mini th {
            text-align: right;
            padding: 8px 10px;
            background: #f8f9fa;
            border-bottom: 1px solid #e1e4e8;
            font-weight: 600;
            color: #57606a;
        }
        .table-mini td { padding: 8px 10px; border-bottom: 1px solid #f0f2f4; vertical-align: middle; }
        .table-mini tr:hover { background: #f8f9fa; }
        .badge-default { background: #d1fae5; color: #065f46; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        
        .file-upload-wrapper {
            border: 2px dashed #e1e4e8;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: #f8f9fa;
        }
        .file-upload-wrapper:hover { border-color: #0969da; background: #f0f4ff; }
        .signature-preview { max-height: 50px; max-width: 150px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-signature"></i> مدیریت امضاها</h1>
            <a href="secretariat.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:6px;"></i>بازگشت</a>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> لیست امضاها</div>
            <div class="card-body">
                <div style="overflow-x:auto;">
                    <table class="table-mini">
                        <thead>
                            <tr>
                                <th>نام</th>
                                <th>سمت</th>
                                <th style="width:120px;">امضا</th>
                                <th style="width:80px;">پیش‌فرض</th>
                                <th style="width:100px;">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($signatures)): ?>
                                <tr><td colspan="5" style="text-align:center;color:#8b93a5;padding:20px;">هیچ امضایی ثبت نشده است.</td></tr>
                            <?php else: ?>
                                <?php foreach ($signatures as $sig): ?>
                                <tr>
                                    <td><?= htmlspecialchars($sig['name']) ?></td>
                                    <td><?= htmlspecialchars($sig['position']) ?></td>
                                    <td>
                                        <?php if (!empty($sig['image_path']) && file_exists($sig['image_path'])): ?>
                                            <img src="<?= htmlspecialchars($sig['image_path']) ?>" class="signature-preview">
                                        <?php else: ?>
                                            <span style="color:#8b93a5;font-size:11px;">ندارد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $sig['is_default'] ? '<span class="badge-default">پیش‌فرض</span>' : '-' ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="editSignature(<?= $sig['id'] ?>)">ویرایش</button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteSignature(<?= $sig['id'] ?>)">حذف</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-plus"></i> افزودن امضای جدید</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="signatureForm">
                    <input type="hidden" name="signature_action" id="signature_action" value="add">
                    <input type="hidden" name="sig_id" id="sig_id" value="0">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>نام (صاحب امضا)</label>
                            <input type="text" name="name" id="sig_name" required placeholder="مثلاً: مدیرعامل">
                        </div>
                        <div class="form-group">
                            <label>سمت</label>
                            <input type="text" name="position" id="sig_position" placeholder="مثلاً: مدیرعامل شرکت">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>تصویر امضا</label>
                        <div class="file-upload-wrapper" onclick="document.getElementById('image_upload').click()">
                            <i class="fas fa-image" style="font-size:24px;color:#0969da;display:block;margin-bottom:8px;"></i>
                            <div>برای آپلود تصویر امضا کلیک کنید</div>
                            <div id="image_name_display" style="font-size:12px;color:#2da44e;display:none;margin-top:6px;"></div>
                            <div id="image_preview" style="margin-top:8px;display:none;">
                                <img id="image_preview_img" src="" style="max-height:60px;max-width:200px;">
                            </div>
                        </div>
                        <input type="file" name="image_upload" id="image_upload" accept="image/*" style="display:none;" 
                               onchange="document.getElementById('image_name_display').textContent = '📎 ' + this.files[0].name; 
                                       document.getElementById('image_name_display').style.display = 'block';
                                       var reader = new FileReader();
                                       reader.onload = function(e) {
                                           document.getElementById('image_preview_img').src = e.target.result;
                                           document.getElementById('image_preview').style.display = 'block';
                                       };
                                       reader.readAsDataURL(this.files[0]);">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_default" id="sig_default" value="1">
                            امضای پیش‌فرض
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-success" id="submitBtn">افزودن امضا</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function editSignature(id) {
            fetch('get_signature.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('signature_action').value = 'edit';
                        document.getElementById('sig_id').value = id;
                        document.getElementById('sig_name').value = data.name || '';
                        document.getElementById('sig_position').value = data.position || '';
                        document.getElementById('sig_default').checked = data.is_default == 1;
                        document.getElementById('submitBtn').textContent = 'ویرایش امضا';
                        if (data.image_path) {
                            document.getElementById('image_preview_img').src = data.image_path;
                            document.getElementById('image_preview').style.display = 'block';
                            document.getElementById('image_name_display').textContent = '📎 تصویر موجود';
                            document.getElementById('image_name_display').style.display = 'block';
                        }
                    }
                });
        }
        
        function deleteSignature(id) {
            if (confirm('آیا از حذف این امضا مطمئن هستید؟')) {
                document.getElementById('signature_action').value = 'delete';
                document.getElementById('sig_id').value = id;
                document.getElementById('signatureForm').submit();
            }
        }
    </script>
</body>
</html>

==================test_cron.php==================


<?php
require_once 'config.php';
require_once 'functions.php';        // ← این خط را اضافه کنید
require_once 'includes/ReminderService.php';

echo "شروع تست کرون...<br>";

// دریافت تاریخ فعلی با nowShamsi
$now = nowShamsi('Y/m/d H:i');
echo "تاریخ فعلی: $now<br><br>";

try {
    $service = new ReminderService($pdo);
    
    // پیدا کردن یادآورهای فعال که زمانشان رسیده
    $stmt = $pdo->prepare("
        SELECT id, title, shamsi_reminder_date, reminder_time, sent_at
        FROM reminders 
        WHERE status = 'active' 
        AND CONCAT(shamsi_reminder_date, ' ', IFNULL(reminder_time, '00:00')) <= ?
        AND sent_at IS NULL
        LIMIT 10
    ");
    $stmt->execute([$now]);
    $pending = $stmt->fetchAll();
    
    if (empty($pending)) {
        echo "❌ هیچ یادآور زمان‌داری برای ارسال وجود ندارد.<br>";
        echo "تاریخ فعلی: $now<br><br>";
        
        // نمایش آخرین یادآورها برای بررسی
        $stmt = $pdo->query("
            SELECT id, title, shamsi_reminder_date, reminder_time, sent_at 
            FROM reminders 
            ORDER BY id DESC 
            LIMIT 5
        ");
        $last = $stmt->fetchAll();
        echo "آخرین یادآورها:<br>";
        foreach ($last as $r) {
            $sent = $r['sent_at'] ?: '❌ ارسال نشده';
            echo "ID: {$r['id']} - {$r['title']} - تاریخ: {$r['shamsi_reminder_date']} {$r['reminder_time']} - وضعیت: $sent<br>";
        }
    } else {
        echo "✅ تعداد یادآورهای منتظر: " . count($pending) . "<br><br>";
        foreach ($pending as $row) {
            echo "ارسال یادآور ID: {$row['id']} - {$row['title']} ... ";
            $result = $service->sendReminder($row['id']);
            if ($result['success']) {
                echo "✅ موفق<br>";
            } else {
                echo "❌ خطا: " . $result['error'] . "<br>";
            }
        }
    }
} catch (Exception $e) {
    echo "❌ خطا: " . $e->getMessage();
}


===================travel_expenses.php===============
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



===================upload_profile.php============
<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['profile_picture'])) {
    $targetDir = 'uploads/profiles/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    
    $fileName = time() . '_' . basename($_FILES['profile_picture']['name']);
    $targetPath = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($fileType, $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'فرمت فایل مجاز نیست']);
        exit();
    }
    if ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) { // 2MB
        echo json_encode(['success' => false, 'error' => 'حجم فایل بیش از ۲ مگابایت است']);
        exit();
    }
    
    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
        echo json_encode(['success' => true, 'path' => $targetPath]);
    } else {
        echo json_encode(['success' => false, 'error' => 'خطا در آپلود فایل']);
    }
}
?>

================users.php===============

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


==============view_asset.php================
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه اموال مشخص نشده است');
}

$id = (int)$_GET['id'];

$query = "
    SELECT a.*, 
           c.name as category_name, 
           c.color as category_color,
           u.full_name as creator_name,
           aa.assigned_to,
           aa.project,
           aa.unit,
           aa.shamsi_assigned_date,
           aa.shamsi_return_date,
           (SELECT full_name FROM users WHERE id = aa.assigned_to) as assigned_to_name
    FROM assets a
    LEFT JOIN asset_categories c ON a.category_id = c.id
    LEFT JOIN users u ON a.created_by = u.id
    LEFT JOIN asset_assignments aa ON a.id = aa.asset_id AND aa.status = 'assigned'
    WHERE a.id = ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) {
    die('اموال یافت نشد');
}

$status = getAssetStatus($asset['status']);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده اموال</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #f6f8fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            max-width: 650px;
            width: 100%;
        }
        h2 {
            color: #1a1a2e;
            border-bottom: 2px solid #cf222e;
            padding-bottom: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f2f4;
        }
        .info-label {
            color: #57606a;
            font-weight: 500;
            min-width: 120px;
        }
        .info-value {
            color: #1a1a2e;
            font-weight: 600;
            word-break: break-word;
            text-align: left;
            flex: 1;
        }
        .btn-back {
            display: inline-block;
            padding: 10px 24px;
            background: #f0f2f4;
            color: #57606a;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            margin-top: 20px;
            transition: 0.2s;
        }
        .btn-back:hover {
            background: #e1e4e8;
        }
        .status-badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-box" style="color:#cf222e;margin-left:10px;"></i>مشاهده اموال</h2>
        
        <div class="info-row">
            <span class="info-label">عنوان</span>
            <span class="info-value"><?= htmlspecialchars($asset['title']) ?></span>
        </div>
        
        <?php if (!empty($asset['code'])): ?>
        <div class="info-row">
            <span class="info-label">کد شناسایی</span>
            <span class="info-value"><?= htmlspecialchars($asset['code']) ?></span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($asset['description'])): ?>
        <div class="info-row">
            <span class="info-label">توضیحات</span>
            <span class="info-value"><?= nl2br(htmlspecialchars($asset['description'])) ?></span>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">دسته‌بندی</span>
            <span class="info-value"><?= htmlspecialchars($asset['category_name'] ?? 'دسته‌بندی نشده') ?></span>
        </div>
        
        <div class="info-row">
            <span class="info-label">وضعیت</span>
            <span class="info-value">
                <span class="status-badge" style="background:<?= $status['color'] ?>;color:<?= $status['text'] ?>;">
                    <?= $status['label'] ?>
                </span>
            </span>
        </div>
        
        <?php if (!empty($asset['purchase_price'])): ?>
        <div class="info-row">
            <span class="info-label">قیمت خرید</span>
            <span class="info-value"><?= number_format($asset['purchase_price']) ?> تومان</span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($asset['purchase_date'])): ?>
        <div class="info-row">
            <span class="info-label">تاریخ خرید</span>
            <span class="info-value"><?= $asset['purchase_date'] ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($asset['status'] == 'assigned' && !empty($asset['assigned_to_name'])): ?>
        <div class="info-row" style="border-top:2px dashed #e1e4e8;margin-top:8px;padding-top:16px;flex-direction:column;gap:8px;">
            <span class="info-label" style="margin-bottom:4px;">اطلاعات واگذاری</span>
            <div style="display:flex;flex-direction:column;gap:4px;width:100%;">
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#57606a;">واگذار به</span>
                    <span style="font-weight:600;"><?= htmlspecialchars($asset['assigned_to_name']) ?></span>
                </div>
                <?php if (!empty($asset['project']) || !empty($asset['unit'])): ?>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#57606a;">پروژه / واحد</span>
                    <span style="font-weight:600;">
                        <?= htmlspecialchars($asset['project'] ?? '') ?>
                        <?php if (!empty($asset['project']) && !empty($asset['unit'])): ?>
                            -
                        <?php endif; ?>
                        <?= htmlspecialchars($asset['unit'] ?? '') ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if (!empty($asset['shamsi_assigned_date'])): ?>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#57606a;">تاریخ واگذاری</span>
                    <span style="font-weight:600;"><?= htmlspecialchars($asset['shamsi_assigned_date']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($asset['shamsi_return_date'])): ?>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#57606a;">تاریخ بازگشت</span>
                    <span style="font-weight:600;"><?= htmlspecialchars($asset['shamsi_return_date']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">تاریخ ثبت</span>
            <span class="info-value"><?= !empty($asset['shamsi_date']) ? $asset['shamsi_date'] : nowShamsi() ?></span>
        </div>
        
        <div class="info-row">
            <span class="info-label">ثبت کننده</span>
            <span class="info-value"><?= htmlspecialchars($asset['creator_name'] ?? 'نامشخص') ?></span>
        </div>
        
        <a href="assets.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:8px;"></i>بازگشت</a>
    </div>
</body>
</html>


================view_download.php=============
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه فایل مشخص نشده است');
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("
    SELECT d.*, c.name as category_name, u.full_name as creator_name 
    FROM download_center d
    LEFT JOIN download_categories c ON d.category_id = c.id
    LEFT JOIN users u ON d.created_by = u.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die('فایل یافت نشد');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده فایل</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #f6f8fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            max-width: 650px;
            width: 100%;
        }
        h2 {
            color: #1a1a2e;
            border-bottom: 2px solid #0969da;
            padding-bottom: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f2f4;
        }
        .info-label {
            color: #57606a;
            font-weight: 500;
            min-width: 120px;
        }
        .info-value {
            color: #1a1a2e;
            font-weight: 600;
            word-break: break-word;
            text-align: left;
            flex: 1;
        }
        .info-value a {
            color: #0969da;
            text-decoration: none;
        }
        .info-value a:hover {
            text-decoration: underline;
        }
        .btn-back, .btn-download {
            display: inline-block;
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            margin-top: 20px;
            transition: 0.2s;
        }
        .btn-back {
            background: #f0f2f4;
            color: #57606a;
        }
        .btn-back:hover {
            background: #e1e4e8;
        }
        .btn-download {
            background: #2da44e;
            color: #fff;
        }
        .btn-download:hover {
            background: #22863a;
        }
        .status-badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-badge.inactive {
            background: #fecaca;
            color: #991b1b;
        }
        .file-icon {
            font-size: 48px;
            color: #0969da;
            text-align: center;
            padding: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-file" style="color:#0969da;margin-left:10px;"></i>مشاهده فایل</h2>
        
        <?php if (!empty($item['file_path']) && file_exists($item['file_path'])): ?>
        <div class="file-icon">
            <i class="fas fa-file-<?= strtolower($item['file_type'] ?? 'file') ?>"></i>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">عنوان</span>
            <span class="info-value"><?= htmlspecialchars($item['title']) ?></span>
        </div>
        
        <?php if (!empty($item['description'])): ?>
        <div class="info-row">
            <span class="info-label">توضیحات</span>
            <span class="info-value"><?= nl2br(htmlspecialchars($item['description'])) ?></span>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">دسته‌بندی</span>
            <span class="info-value"><?= htmlspecialchars($item['category_name'] ?? 'دسته‌بندی نشده') ?></span>
        </div>
        
        <?php if (!empty($item['file_path']) && file_exists($item['file_path'])): ?>
        <div class="info-row">
            <span class="info-label">نام فایل</span>
            <span class="info-value"><?= htmlspecialchars(basename($item['file_path'])) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">حجم</span>
            <span class="info-value"><?= htmlspecialchars($item['file_size'] ?? 'نامشخص') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">نوع</span>
            <span class="info-value"><?= htmlspecialchars($item['file_type'] ?? 'نامشخص') ?></span>
        </div>
        <?php elseif (!empty($item['download_url'])): ?>
        <div class="info-row">
            <span class="info-label">لینک دانلود</span>
            <span class="info-value">
                <a href="<?= htmlspecialchars($item['download_url']) ?>" target="_blank">
                    <?= htmlspecialchars($item['download_url']) ?>
                </a>
            </span>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">وضعیت</span>
            <span class="info-value">
                <span class="status-badge <?= $item['status'] == 1 ? 'active' : 'inactive' ?>">
                    <?= $item['status'] == 1 ? 'فعال' : 'غیرفعال' ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">تعداد دانلود</span>
            <span class="info-value"><?= number_format($item['download_count'] ?? 0) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">تاریخ ثبت</span>
            <span class="info-value"><?= !empty($item['shamsi_date']) ? $item['shamsi_date'] : jdate('Y/m/d', strtotime($item['created_at'])) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">ثبت کننده</span>
            <span class="info-value"><?= htmlspecialchars($item['creator_name'] ?? 'نامشخص') ?></span>
        </div>
        
        <div style="display:flex;gap:10px;">
            <a href="downloads.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:8px;"></i>بازگشت</a>
            <?php if (!empty($item['file_path']) && file_exists($item['file_path'])): ?>
                <a href="download_file.php?id=<?= $item['id'] ?>" class="btn-download"><i class="fas fa-download" style="margin-left:8px;"></i>دانلود فایل</a>
            <?php elseif (!empty($item['download_url'])): ?>
                <a href="<?= htmlspecialchars($item['download_url']) ?>" target="_blank" class="btn-download"><i class="fas fa-external-link-alt" style="margin-left:8px;"></i>دانلود</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

================view_expense.php===============
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die('شناسه هزینه نامعتبر است');

// توابع تبدیل اعداد به فارسی
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

// دریافت اطلاعات هزینه
$stmt = $pdo->prepare("
    SELECT te.*,
           u.full_name as user_name,
           u.national_id as user_national_id,
           p.name as project_name,
           ap.full_name as approved_by_name,
           op.name as origin_province,
           oc.name as origin_city,
           dp.name as destination_province,
           dc.name as destination_city,
           cr.full_name as created_by_name
    FROM travel_expenses te
    LEFT JOIN users u ON te.user_id = u.id
    LEFT JOIN projects p ON te.project_id = p.id
    LEFT JOIN users ap ON te.approved_by = ap.id
    LEFT JOIN provinces op ON te.origin_province_id = op.id
    LEFT JOIN cities oc ON te.origin_city_id = oc.id
    LEFT JOIN provinces dp ON te.destination_province_id = dp.id
    LEFT JOIN cities dc ON te.destination_city_id = dc.id
    LEFT JOIN users cr ON te.created_by = cr.id
    WHERE te.id = ?
");
$stmt->execute([$id]);
$expense = $stmt->fetch();

if (!$expense) die('هزینه‌ای با این شناسه یافت نشد');

$items = $pdo->prepare("
    SELECT ei.*, ec.name as category_name
    FROM expense_items ei
    LEFT JOIN expense_categories ec ON ei.category_id = ec.id
    WHERE ei.expense_id = ?
    ORDER BY ei.id
");
$items->execute([$id]);
$items = $items->fetchAll();

$totalAmount = array_sum(array_column($items, 'amount'));

function numberToWords($num) {
    if ($num == 0) return 'صفر';
    if ($num < 0) return 'منفی ' . numberToWords(abs($num));
    
    $units = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $hundreds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    $thousands = ['', 'هزار', 'میلیون', 'میلیارد'];
    
    $num = (int)$num;
    if ($num < 1000) {
        $h = floor($num / 100);
        $r = $num % 100;
        $words = '';
        if ($h > 0) $words .= $hundreds[$h] . ' ';
        if ($r >= 20) {
            $t = floor($r / 10);
            $u = $r % 10;
            $words .= $tens[$t];
            if ($u > 0) $words .= ' و ' . $units[$u];
        } elseif ($r > 0 && $r < 10) {
            $words .= $units[$r];
        } elseif ($r >= 10 && $r < 20) {
            $words .= $teens[$r - 10];
        }
        return trim($words);
    }
    
    $parts = [];
    $temp = $num;
    $i = 0;
    while ($temp > 0) {
        $part = $temp % 1000;
        if ($part > 0) $parts[$i] = $part;
        $temp = floor($temp / 1000);
        $i++;
    }
    
    $result = [];
    foreach ($parts as $index => $part) {
        $partWords = '';
        $h = floor($part / 100);
        $r = $part % 100;
        if ($h > 0) $partWords .= $hundreds[$h] . ' ';
        if ($r >= 20) {
            $t = floor($r / 10);
            $u = $r % 10;
            $partWords .= $tens[$t];
            if ($u > 0) $partWords .= ' و ' . $units[$u];
        } elseif ($r > 0 && $r < 10) {
            $partWords .= $units[$r];
        } elseif ($r >= 10 && $r < 20) {
            $partWords .= $teens[$r - 10];
        }
        if (!empty($partWords)) {
            $result[] = trim($partWords) . ' ' . $thousands[$index];
        }
    }
    $words = implode(' و ', array_reverse($result));
    return trim($words);
}

function getPaymentTypeLabel($type) {
    $labels = ['cash'=>'نقدی', 'non_cash'=>'غیرنقدی', 'card'=>'کارت بانکی', 'check'=>'چک', 'transfer'=>'اینترنتی'];
    return $labels[$type] ?? $type;
}

function getDocumentTypeLabel($type) {
    $labels = ['invoice'=>'فاکتور', 'receipt'=>'رسید', 'bill'=>'قبض', 'other'=>'سایر'];
    return $labels[$type] ?? $type;
}

function getStatusLabel($status) {
    $labels = ['pending'=>'در انتظار تایید', 'approved'=>'تایید شده', 'rejected'=>'رد شده'];
    return $labels[$status] ?? $status;
}

function getStatusColor($status) {
    $colors = ['pending'=>'#fef3c7', 'approved'=>'#d1fae5', 'rejected'=>'#fecaca'];
    return $colors[$status] ?? '#f3f4f6';
}

function getStatusTextColor($status) {
    $colors = ['pending'=>'#92400e', 'approved'=>'#065f46', 'rejected'=>'#991b1b'];
    return $colors[$status] ?? '#6b7280';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده هزینه ماموریت</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #e8ecf0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .paper-a5 {
            width: 148mm;
            min-height: 210mm;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 8mm 6mm;
            position: relative;
            direction: rtl;
            font-size: 12px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0969da;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .header .company {
            font-size: 16px;
            font-weight: 800;
            color: #1a2332;
        }
        .header .company i { color: #0969da; }
        .header .title {
            font-size: 18px;
            font-weight: 700;
            color: #0969da;
            margin-top: 2px;
        }
        .header .sub {
            font-size: 11px;
            color: #57606a;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .main-table td, .main-table th {
            border: 1px solid #1a2332;
            padding: 5px 8px;
            vertical-align: middle;
            font-size: 12px;
        }
        .main-table .label-cell {
            background: #f8f9fa;
            font-weight: 600;
            white-space: nowrap;
            width: 20%;
        }
        .main-table .value-cell {
            font-weight: 500;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        .items-table th {
            background: #f8f9fa;
            border: 1px solid #1a2332;
            padding: 4px 6px;
            text-align: center;
            font-weight: 700;
            font-size: 11px;
        }
        .items-table td {
            border: 1px solid #1a2332;
            padding: 4px 6px;
            text-align: center;
            font-size: 11px;
        }
        .items-table .total-row {
            background: #eff6ff;
            font-weight: 700;
        }
        .total-box {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            padding: 6px 10px;
            background: #0969da;
            color: #fff;
            border-radius: 4px;
            font-weight: 700;
            font-size: 12px;
        }
        .total-box .words {
            font-size: 11px;
            font-weight: 400;
        }
        .companions-box {
            margin-top: 8px;
            padding: 6px 10px;
            background: #f8f9fa;
            border-radius: 4px;
            border-right: 4px solid #0969da;
        }
        .companions-box .label {
            font-weight: 600;
            font-size: 12px;
        }
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-top: 14px;
            padding-top: 10px;
            border-top: 2px solid #1a2332;
        }
        .signature-item {
            text-align: center;
        }
        .signature-item .line {
            border-bottom: 2px solid #1a2332;
            height: 35px;
            margin-bottom: 4px;
        }
        .signature-item .label {
            font-size: 11px;
            color: #57606a;
            font-weight: 500;
        }
        .signature-item .name {
            font-size: 12px;
            color: #1a2332;
            font-weight: 700;
            margin-top: 2px;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .footer-text {
            text-align: center;
            font-size: 9px;
            color: #8b949e;
            margin-top: 10px;
            border-top: 1px solid #e8ecf0;
            padding-top: 6px;
        }
        .back-btn {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: #0969da;
            color: #fff;
            border: none;
            border-radius: 50px;
            padding: 12px 32px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(9,105,218,0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 1000;
            text-decoration: none;
        }
        .back-btn:hover {
            transform: translateX(-50%) translateY(-2px);
            box-shadow: 0 8px 30px rgba(9,105,218,0.5);
            background: #0550b3;
        }
        @media print {
            body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
            .paper-a5 { width: 100% !important; min-height: auto !important; border-radius: 0 !important; box-shadow: none !important; padding: 5mm 4mm !important; }
            .back-btn { display: none !important; }
            .signature-item .line { height: 25px !important; }
        }
        @page { size: A5; margin: 4mm; }
    </style>
</head>
<body>

<div class="paper-a5">
    <div class="header">
        <div class="company"><i class="fas fa-heartbeat"></i> شرکت دانش بنیان پیشگامان دنیای فناوری</div>
        <div class="title">فرم هزینه ماموریت</div>
        <div class="sub">پرونده الکترونیک سلامت</div>
    </div>

    <table class="main-table">
        <tr>
            <td class="label-cell">نام و نام خانوادگی</td>
            <td class="value-cell" colspan="3"><?= htmlspecialchars($expense['full_name'] ?? $expense['user_name'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="label-cell">تاریخ شروع</td>
            <td class="value-cell"><?= persian_number_str($expense['travel_date']) ?></td>
            <td class="label-cell" style="width:15%;">تاریخ اتمام</td>
            <td class="value-cell"><?= persian_number_str($expense['end_date'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="label-cell">مبدا</td>
            <td class="value-cell">
                <?php if ($expense['origin_city']): ?>
                    <?= htmlspecialchars($expense['origin_city']) ?>
                    <?php if ($expense['origin_province']): ?> (<?= htmlspecialchars($expense['origin_province']) ?>)<?php endif; ?>
                <?php else: ?>-<?php endif; ?>
            </td>
            <td class="label-cell">مقصد</td>
            <td class="value-cell">
                <?php if ($expense['destination_city']): ?>
                    <?= htmlspecialchars($expense['destination_city']) ?>
                    <?php if ($expense['destination_province']): ?> (<?= htmlspecialchars($expense['destination_province']) ?>)<?php endif; ?>
                <?php else: ?>-<?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="label-cell">نام پروژه</td>
            <td class="value-cell" colspan="3"><?= htmlspecialchars($expense['project_name'] ?? '-') ?></td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:12%;">هزینه (ریال)</th>
                <th style="width:14%;">دسته بندی</th>
                <th style="width:14%;">نوع پرداخت</th>
                <th style="width:18%;">نوع سند</th>
                <th style="width:42%;">توضیحات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" style="text-align:center;color:#57606a;">هیچ ردیف هزینه‌ای ثبت نشده است.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= persian_number(number_format($item['amount'])) ?></td>
                    <td><?= htmlspecialchars($item['category_name'] ?? '-') ?></td>
                    <td><?= getPaymentTypeLabel($item['payment_type']) ?></td>
                    <td><?= getDocumentTypeLabel($item['document_type']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($item['description'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="total-box">
        <span>جمع کل به حروف: <span class="words"><?= numberToWords($totalAmount) ?> ریال</span></span>
        <span>جمع کل به عدد: <?= persian_number(number_format($totalAmount)) ?> ریال</span>
    </div>

    <div class="companions-box">
        <span class="label"><i class="fas fa-users"></i> نام و نام خانوادگی افراد همراه:</span>
        <span><?= htmlspecialchars($expense['companions'] ?? '-') ?></span>
    </div>

    <div style="margin-top:6px;text-align:center;">
        <span class="status-badge" style="background:<?= getStatusColor($expense['status']) ?>;color:<?= getStatusTextColor($expense['status']) ?>;">
            وضعیت: <?= getStatusLabel($expense['status']) ?>
            <?php if ($expense['approved_by_name']): ?>
                (تایید کننده: <?= htmlspecialchars($expense['approved_by_name']) ?>)
            <?php endif; ?>
        </span>
    </div>

    <div class="signatures">
        <div class="signature-item">
            <div class="line"></div>
            <div class="label">امضای متقاضی</div>
            <div class="name"><?= htmlspecialchars($expense['full_name'] ?? $expense['user_name'] ?? '') ?></div>
        </div>
        <div class="signature-item">
            <div class="line"></div>
            <div class="label">امضای مدیر مالی</div>
            <div class="name"><?= $expense['status'] === 'approved' ? htmlspecialchars($expense['approved_by_name'] ?? '') : '.............................' ?></div>
        </div>
        <div class="signature-item">
            <div class="line"></div>
            <div class="label">مهر سازمان</div>
            <div class="name" style="font-size:10px;color:#0969da;">.............................</div>
        </div>
    </div>

    <div class="footer-text">
        تاریخ چاپ: <?= persian_number_str(jdate('Y/m/d H:i')) ?> &nbsp;|&nbsp; این فرم به‌صورت الکترونیکی ثبت شده است.
    </div>
</div>

<a href="travel_expenses.php" class="back-btn"><i class="fas fa-arrow-right"></i> بازگشت</a>

</body>
</html>




=====================view_food_order.php=============


<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die('شناسه سفارش نامعتبر است');

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

// دریافت اطلاعات سفارش
$stmt = $pdo->prepare("
    SELECT fo.*, u.full_name as creator_name
    FROM food_orders fo
    LEFT JOIN users u ON fo.created_by = u.id
    WHERE fo.id = ?
");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die('سفارشی با این شناسه یافت نشد');

// دریافت آیتم‌ها
$items = $pdo->prepare("
    SELECT foi.*, p.name as project_name
    FROM food_order_items foi
    LEFT JOIN projects p ON foi.project_id = p.id
    WHERE foi.order_id = ?
    ORDER BY foi.id
");
$items->execute([$id]);
$items = $items->fetchAll();

// پر کردن ردیف‌های خالی تا ۲۰
$allItems = [];
for ($i = 1; $i <= 20; $i++) {
    $allItems[$i] = $items[$i-1] ?? null;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرم تهیه غذای پرسنل</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #e8ecf0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 10px;
        }
        /* ===== تغییر سایز کاغذ به A6 ===== */
        .paper-a6 {
            width: 105mm;
            min-height: 148mm;
            background: #ffffff;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.12);
            padding: 5mm 4mm;
            direction: rtl;
            font-size: 7.5pt;
            line-height: 1.2;
        }
        .header {
            text-align: center;
            border-bottom: 1.5px solid #0969da;
            padding-bottom: 3px;
            margin-bottom: 4px;
        }
        .header .company {
            font-size: 9pt;
            font-weight: 800;
            color: #1a2332;
        }
        .header .company i { color: #0969da; }
        .header .title {
            font-size: 10pt;
            font-weight: 700;
            color: #0969da;
        }
        .header .sub { font-size: 6.5pt; color: #57606a; }

        .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            margin-bottom: 3px;
            padding: 2px 0;
        }
        .info-row .label { font-weight: 700; color: #1a2332; }
        .info-row .value { font-weight: 500; color: #24292f; }

        /* ===== یک جدول ۲۰ ردیفی ساده ===== */
        .form-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 6.5pt;
            margin-top: 3px;
        }
        .form-table th {
            background: #f8f9fa;
            border: 1px solid #1a2332;
            padding: 2px 3px;
            text-align: center;
            font-weight: 700;
            font-size: 6pt;
        }
        .form-table td {
            border: 1px solid #1a2332;
            padding: 2px 3px;
            text-align: center;
            font-size: 6pt;
        }
        .form-table td:first-child { font-weight: 700; width: 10%; }
        .form-table td:nth-child(2) { width: 35%; }
        .form-table td:nth-child(3) { width: 30%; }
        .form-table td:nth-child(4) { width: 25%; }

        .form-footer {
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1.5px solid #1a2332;
        }
        .form-footer .notes {
            font-size: 6pt;
            color: #57606a;
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        .form-footer .notes i { color: #cf222e; margin-left: 2px; }

        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 4px;
            margin-top: 2px;
        }
        .signature-item { text-align: center; }
        .signature-item .line {
            border-bottom: 1.5px solid #1a2332;
            height: 16px;
            margin-bottom: 2px;
        }
        .signature-item .label { font-size: 6pt; color: #57606a; font-weight: 600; }
        .signature-item .name { font-size: 6.5pt; color: #1a2332; font-weight: 700; }

        .print-btn, .back-btn {
            position: fixed;
            padding: 8px 20px;
            border: none;
            border-radius: 30px;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            font-weight: 600;
            z-index: 1000;
            text-decoration: none;
        }
        .print-btn {
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #0969da;
            color: #fff;
            font-size: 12px;
            box-shadow: 0 2px 12px rgba(9,105,218,0.3);
        }
        .print-btn:hover { background: #0550b3; }
        .back-btn {
            bottom: 70px;
            left: 50%;
            transform: translateX(-50%);
            background: #f0f2f4;
            color: #57606a;
            font-size: 11px;
        }
        .back-btn:hover { background: #e1e4e8; }

        .footer-text {
            text-align: center;
            font-size: 5.5pt;
            color: #8b949e;
            margin-top: 3px;
            border-top: 1px solid #e1e4e8;
            padding-top: 2px;
        }

        @media print {
            body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
            .paper-a6 {
                width: 100% !important;
                min-height: auto !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                padding: 4mm 3mm !important;
            }
            .print-btn, .back-btn { display: none !important; }
            .form-table th, .form-table td { border-color: #1a2332 !important; }
        }
        @page {
            size: A6;
            margin: 3mm;
        }
    </style>
</head>
<body>

<div class="paper-a6" id="printArea">
    <!-- ===== هدر ===== -->
    <div class="header">
        <div class="company"><i class="fas fa-heartbeat"></i> شرکت دانش بنیان پیشگامان</div>
        <div class="title">فرم تهیه غذای پرسنل</div>
        <div class="sub"><?= persian_number_str($order['order_date']) ?> - <?= htmlspecialchars($order['meal_type']) ?></div>
    </div>

    <!-- ===== اطلاعات بالا ===== -->
    <div class="info-row">
        <span><span class="label">تاریخ:</span> <span class="value"><?= persian_number_str($order['order_date']) ?></span></span>
        <span><span class="label">وعده:</span> <span class="value"><?= htmlspecialchars($order['meal_type']) ?></span></span>
        <span><span class="label">ثبت:</span> <span class="value"><?= htmlspecialchars($order['creator_name'] ?? '-') ?></span></span>
    </div>

    <!-- ===== یک جدول ۲۰ ردیفی ===== -->
    <table class="form-table">
        <thead>
            <tr>
                <th>ردیف</th>
                <th>نام و نام خانوادگی همکار</th>
                <th>نام پروژه</th>
                <th>نوع غذا</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 1; $i <= 20; $i++): 
                $item = $allItems[$i] ?? null;
            ?>
            <tr>
                <td><?= persian_number($i) ?></td>
                <td><?= htmlspecialchars($item['colleague_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($item['project_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($item['food_name'] ?? '') ?></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <!-- ===== پاورقی ===== -->
    <div class="form-footer">
        <div class="notes">
            <span><i class="fas fa-info-circle"></i> * حضور در واحد کاری حداقل تا ساعت ۱۸:۰۰</span>
            <span><i class="fas fa-info-circle"></i> * حضور در روزهای تعطیل</span>
        </div>

        <div class="signatures">
            <div class="signature-item">
                <div class="line"></div>
                <div class="label">مدیر پروژه</div>
                <div class="name"><?= htmlspecialchars($order['signature_project'] ?? '.............................') ?></div>
            </div>
            <div class="signature-item">
                <div class="line"></div>
                <div class="label">مدیر واحد مالی</div>
                <div class="name"><?= htmlspecialchars($order['signature_finance'] ?? '.............................') ?></div>
            </div>
            <div class="signature-item">
                <div class="line"></div>
                <div class="label">تاریخ ثبت</div>
                <div class="name"><?= persian_number_str($order['order_date']) ?></div>
            </div>
        </div>
    </div>

    <div class="footer-text">
        تاریخ چاپ: <?= persian_number_str(nowShamsi('Y/m/d H:i')) ?>
    </div>
</div>

<button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> پرینت</button>
<a href="daily_food_orders.php" class="back-btn"><i class="fas fa-arrow-right"></i> بازگشت</a>

</body>
</html>

=============view_letter.php===============

<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه نامه مشخص نشده است');
}

$id = (int)$_GET['id'];

$query = "
    SELECT l.*, 
           u.full_name as creator_name,
           h.title as header_title,
           h.logo as header_logo,
           h.address as header_address,
           h.phone as header_phone,
           h.fax as header_fax,
           h.email as header_email,
           h.website as header_website,
           t.title as template_title
    FROM letters l
    LEFT JOIN users u ON l.created_by = u.id
    LEFT JOIN letter_headers h ON l.header_id = h.id
    LEFT JOIN letter_templates t ON l.template_id = t.id
    WHERE l.id = ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$letter = $stmt->fetch();

if (!$letter) {
    die('نامه یافت نشد');
}

// دریافت ارجاعات
$stmt = $pdo->prepare("
    SELECT r.*, 
           u.full_name as referred_to_name,
           u2.full_name as referred_by_name
    FROM letter_referrals r
    LEFT JOIN users u ON r.referred_to = u.id
    LEFT JOIN users u2 ON r.referred_by = u2.id
    WHERE r.letter_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$id]);
$referrals = $stmt->fetchAll();

// دریافت پیوست‌ها
$stmt = $pdo->prepare("SELECT * FROM letter_attachments WHERE letter_id = ?");
$stmt->execute([$id]);
$attachments = $stmt->fetchAll();

// دریافت لاگ‌ها
$stmt = $pdo->prepare("
    SELECT l.*, u.full_name as user_name
    FROM letter_logs l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.letter_id = ?
    ORDER BY l.created_at DESC
");
$stmt->execute([$id]);
$logs = $stmt->fetchAll();

function getStatusLabel($status) {
    $statuses = [
        'draft' => 'پیش‌نویس',
        'sent' => 'ارسال شده',
        'received' => 'دریافت شده',
        'answered' => 'پاسخ داده شده',
        'archived' => 'بایگانی'
    ];
    return $statuses[$status] ?? $status;
}

function getStatusColor($status) {
    $colors = [
        'draft' => '#e2e8f0',
        'sent' => '#dbeafe',
        'received' => '#d1fae5',
        'answered' => '#fef3c7',
        'archived' => '#f3f4f6'
    ];
    return $colors[$status] ?? '#e2e8f0';
}

function getTypeLabel($type) {
    return $type == 'incoming' ? 'وارده' : 'صادره';
}

function getPriorityLabel($priority) {
    $priorities = [
        'low' => 'کم',
        'medium' => 'متوسط',
        'high' => 'زیاد',
        'urgent' => 'فوری'
    ];
    return $priorities[$priority] ?? 'متوسط';
}

function getReferralStatus($status) {
    $statuses = [
        'pending' => ['label' => 'در انتظار', 'color' => '#fef3c7', 'text' => '#92400e'],
        'seen' => ['label' => 'مشاهده شده', 'color' => '#dbeafe', 'text' => '#1e40af'],
        'answered' => ['label' => 'پاسخ داده شده', 'color' => '#d1fae5', 'text' => '#065f46'],
        'closed' => ['label' => 'بسته شده', 'color' => '#f3f4f6', 'text' => '#6b7280']
    ];
    return $statuses[$status] ?? $statuses['pending'];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده نامه</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #f6f8fa;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
        }
        .letter-header {
            border-bottom: 2px solid #0969da;
            padding-bottom: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .letter-header h2 {
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .letter-number {
            font-size: 16px;
            color: #0969da;
            font-weight: 600;
            background: #eff6ff;
            padding: 4px 16px;
            border-radius: 20px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f4;
        }
        .info-label {
            color: #57606a;
            font-weight: 500;
            min-width: 120px;
        }
        .info-value {
            color: #1a1a2e;
            font-weight: 600;
            word-break: break-word;
            text-align: left;
            flex: 1;
        }
        .content-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 16px 0;
            border: 1px solid #e1e4e8;
            line-height: 2.2;
            font-size: 14px;
        }
        .content-box p {
            margin-bottom: 8px;
        }
        .badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .btn-back {
            display: inline-block;
            padding: 10px 24px;
            background: #f0f2f4;
            color: #57606a;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-back:hover {
            background: #e1e4e8;
        }
        .btn-print {
            display: inline-block;
            padding: 10px 24px;
            background: #8250df;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-print:hover {
            background: #6a3fc7;
        }
        .referral-item {
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 8px;
            border-right: 4px solid #0969da;
        }
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 16px 0 12px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-title i {
            color: #0969da;
        }
        .log-item {
            font-size: 13px;
            color: #57606a;
            padding: 4px 0;
            border-bottom: 1px solid #f0f2f4;
        }
        .log-item .time {
            color: #8b949e;
            font-size: 11px;
        }
        /* ===== استایل‌های فونت‌های مختلف ===== */
        .content-box .b-yekan { font-family: 'B Yekan', 'Tahoma', sans-serif; }
        .content-box .b-titr { font-family: 'B Titr', 'Tahoma', sans-serif; }
        .content-box .b-mitra { font-family: 'B Mitra', 'Tahoma', sans-serif; }
        .content-box .b-nazanin { font-family: 'B Nazanin', 'Tahoma', sans-serif; }
    </style>
</head>
<body>
    <div class="container">
        <!-- ===== هدر نامه ===== -->
        <div class="letter-header">
            <h2>
                <i class="fas fa-envelope" style="color:#0969da;"></i>
                مشاهده نامه
            </h2>
            <span class="letter-number"><?= htmlspecialchars($letter['letter_number']) ?></span>
        </div>

        <!-- ===== اطلاعات اصلی ===== -->
        <div class="info-row">
            <span class="info-label">شماره نامه</span>
            <span class="info-value" style="direction:ltr;text-align:left;"><?= htmlspecialchars($letter['letter_number']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">تاریخ نامه</span>
            <span class="info-value"><?= htmlspecialchars($letter['shamsi_letter_date']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">نوع</span>
            <span class="info-value">
                <span class="badge" style="background:<?= $letter['type'] == 'incoming' ? '#d1fae5' : '#dbeafe' ?>;color:<?= $letter['type'] == 'incoming' ? '#065f46' : '#1e40af' ?>;">
                    <?= getTypeLabel($letter['type']) ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">اولویت</span>
            <span class="info-value">
                <span class="badge" style="background:<?= $letter['priority'] == 'urgent' ? '#fee2e2' : ($letter['priority'] == 'high' ? '#fecaca' : ($letter['priority'] == 'medium' ? '#fef3c7' : '#d1fae5')) ?>;color:<?= $letter['priority'] == 'urgent' ? '#dc2626' : ($letter['priority'] == 'high' ? '#991b1b' : ($letter['priority'] == 'medium' ? '#92400e' : '#065f46')) ?>;">
                    <?= getPriorityLabel($letter['priority']) ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">وضعیت</span>
            <span class="info-value">
                <span class="badge" style="background:<?= getStatusColor($letter['status']) ?>;color:#1a1a2e;">
                    <?= getStatusLabel($letter['status']) ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">موضوع</span>
            <span class="info-value"><?= htmlspecialchars($letter['subject']) ?></span>
        </div>

        <?php if (!empty($letter['summary'])): ?>
        <div class="info-row">
            <span class="info-label">خلاصه</span>
            <span class="info-value"><?= htmlspecialchars($letter['summary']) ?></span>
        </div>
        <?php endif; ?>

        <!-- ===== متن نامه (بدون htmlspecialchars برای نمایش صحیح HTML) ===== -->
        <div class="section-title"><i class="fas fa-file-alt"></i> متن نامه</div>
        <div class="content-box">
            <?= $letter['content'] ?>
        </div>

        <!-- ===== اطلاعات فرستنده/گیرنده ===== -->
        <div class="section-title"><i class="fas fa-user"></i> اطلاعات فرستنده / گیرنده</div>
        <?php if (!empty($letter['sender_name']) || !empty($letter['sender_organization'])): ?>
        <div class="info-row">
            <span class="info-label">فرستنده</span>
            <span class="info-value">
                <?= htmlspecialchars($letter['sender_name'] ?? '') ?>
                <?php if (!empty($letter['sender_organization'])): ?>
                    <span style="color:#8b949e;font-weight:400;">(<?= htmlspecialchars($letter['sender_organization']) ?>)</span>
                <?php endif; ?>
                <?php if (!empty($letter['sender_phone'])): ?>
                    <span style="color:#8b949e;font-weight:400;">| تلفن: <?= htmlspecialchars($letter['sender_phone']) ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <?php if (!empty($letter['receiver_name']) || !empty($letter['receiver_organization'])): ?>
        <div class="info-row">
            <span class="info-label">گیرنده</span>
            <span class="info-value">
                <?= htmlspecialchars($letter['receiver_name'] ?? '') ?>
                <?php if (!empty($letter['receiver_organization'])): ?>
                    <span style="color:#8b949e;font-weight:400;">(<?= htmlspecialchars($letter['receiver_organization']) ?>)</span>
                <?php endif; ?>
                <?php if (!empty($letter['receiver_phone'])): ?>
                    <span style="color:#8b949e;font-weight:400;">| تلفن: <?= htmlspecialchars($letter['receiver_phone']) ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- ===== ارجاعات ===== -->
        <?php if (!empty($referrals)): ?>
        <div class="section-title"><i class="fas fa-share"></i> ارجاعات</div>
        <?php foreach ($referrals as $r): 
            $rStatus = getReferralStatus($r['status']);
        ?>
        <div class="referral-item">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div>
                    <strong>به: </strong>
                    <?= htmlspecialchars($r['referred_to_name'] ?? $r['referred_to_unit'] ?? 'نامشخص') ?>
                </div>
                <div>
                    <span class="badge" style="background:<?= $rStatus['color'] ?>;color:<?= $rStatus['text'] ?>;">
                        <?= $rStatus['label'] ?>
                    </span>
                </div>
            </div>
            <div style="font-size:13px;color:#57606a;margin-top:4px;">
                تاریخ ارجاع: <?= htmlspecialchars($r['shamsi_referral_date']) ?>
                <?php if (!empty($r['shamsi_due_date'])): ?>
                    | تاریخ پاسخ: <?= htmlspecialchars($r['shamsi_due_date']) ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($r['description'])): ?>
                <div style="font-size:13px;color:#57606a;margin-top:4px;"><?= htmlspecialchars($r['description']) ?></div>
            <?php endif; ?>
            <?php if (!empty($r['answer_text'])): ?>
                <div style="font-size:13px;color:#065f46;margin-top:4px;background:#ecfdf3;padding:8px;border-radius:4px;">
                    <strong>پاسخ: </strong><?= nl2br(htmlspecialchars($r['answer_text'])) ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ===== پیوست‌ها ===== -->
        <?php if (!empty($attachments)): ?>
        <div class="section-title"><i class="fas fa-paperclip"></i> پیوست‌ها</div>
        <?php foreach ($attachments as $a): ?>
        <div class="info-row">
            <span class="info-label"><?= htmlspecialchars($a['file_name']) ?></span>
            <span class="info-value" style="font-weight:400;font-size:13px;">
                <?= htmlspecialchars($a['file_size'] ?? '') ?>
                <a href="<?= htmlspecialchars($a['file_path']) ?>" download style="color:#0969da;margin-right:8px;">
                    <i class="fas fa-download"></i> دانلود
                </a>
            </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ===== لاگ‌ها ===== -->
        <?php if (!empty($logs)): ?>
        <div class="section-title"><i class="fas fa-history"></i> تاریخچه</div>
        <?php foreach ($logs as $log): ?>
        <div class="log-item">
            <span><?= htmlspecialchars($log['user_name'] ?? 'سیستم') ?></span>
            <span><?= htmlspecialchars($log['description']) ?></span>
            <span class="time">| <?= $log['created_at'] ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ===== اطلاعات ثبت ===== -->
        <div class="section-title"><i class="fas fa-info-circle"></i> اطلاعات ثبت</div>
        <div class="info-row">
            <span class="info-label">تاریخ ثبت</span>
            <span class="info-value"><?= htmlspecialchars($letter['shamsi_date'] ?? '-') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">ثبت کننده</span>
            <span class="info-value"><?= htmlspecialchars($letter['creator_name'] ?? 'نامشخص') ?></span>
        </div>

        <!-- ===== دکمه‌ها ===== -->
        <div style="display:flex;gap:10px;margin-top:24px;flex-wrap:wrap;">
            <a href="secretariat.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:8px;"></i>بازگشت</a>
            <a href="print_letter.php?id=<?= $letter['id'] ?>" target="_blank" class="btn-print"><i class="fas fa-print" style="margin-left:8px;"></i>چاپ نامه</a>
        </div>
    </div>
</body>
</html>

=================view_link.php=============
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه لینک مشخص نشده است');
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT l.*, c.name as category_name, u.full_name as creator_name 
                       FROM link_bank l
                       LEFT JOIN link_categories c ON l.category_id = c.id
                       LEFT JOIN users u ON l.created_by = u.id
                       WHERE l.id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();

if (!$link) {
    die('لینک یافت نشد');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده لینک</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #f6f8fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            max-width: 650px;
            width: 100%;
        }
        h2 {
            color: #1a1a2e;
            border-bottom: 2px solid #0969da;
            padding-bottom: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f2f4;
        }
        .info-label {
            color: #57606a;
            font-weight: 500;
            min-width: 120px;
        }
        .info-value {
            color: #1a1a2e;
            font-weight: 600;
            word-break: break-word;
            text-align: left;
            flex: 1;
        }
        .info-value a {
            color: #0969da;
            text-decoration: none;
        }
        .info-value a:hover {
            text-decoration: underline;
        }
        .btn-back {
            display: inline-block;
            padding: 10px 24px;
            background: #0969da;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            margin-top: 20px;
            transition: 0.2s;
        }
        .btn-back:hover {
            background: #0550b3;
        }
        .status-badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-badge.inactive {
            background: #fecaca;
            color: #991b1b;
        }
        .copy-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #8b949e;
            padding: 0 5px;
            font-size: 14px;
            transition: 0.2s;
        }
        .copy-btn:hover {
            color: #0969da;
        }
        .credential-box {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            direction: ltr;
        }
        .credential-box i {
            color: #8250df;
        }
        .toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #1a1a2e;
            color: #fff;
            padding: 12px 24px;
            border-radius: 8px;
            font-family: 'Vazirmatn', sans-serif;
            font-size: 14px;
            z-index: 9999;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            transition: opacity 0.3s;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-link" style="color:#8250df;margin-left:10px;"></i>مشاهده لینک</h2>
        
        <div class="info-row">
            <span class="info-label">عنوان</span>
            <span class="info-value"><?= htmlspecialchars($link['title']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">آدرس</span>
            <span class="info-value">
                <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank">
                    <?= htmlspecialchars($link['url']) ?>
                </a>
            </span>
        </div>
        <?php if (!empty($link['description'])): ?>
        <div class="info-row">
            <span class="info-label">توضیحات</span>
            <span class="info-value"><?= nl2br(htmlspecialchars($link['description'])) ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="info-label">دسته‌بندی</span>
            <span class="info-value"><?= htmlspecialchars($link['category_name'] ?? 'دسته‌بندی نشده') ?></span>
        </div>
        
        <?php if (!empty($link['username']) || !empty($link['password'])): ?>
        <div class="info-row" style="flex-direction:column;gap:8px;padding:15px 0;">
            <span class="info-label" style="margin-bottom:5px;">اطلاعات ورود</span>
            <div style="display:flex;flex-direction:column;gap:8px;width:100%;">
                <?php if (!empty($link['username'])): ?>
                <div class="credential-box">
                    <i class="fas fa-user"></i>
                    <span><?= htmlspecialchars($link['username']) ?></span>
                    <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($link['username']) ?>')" title="کپی نام کاربری">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($link['password'])): ?>
                <div class="credential-box">
                    <i class="fas fa-lock" style="color:#cf222e;"></i>
                    <span id="view_pass_display">••••••••</span>
                    <button class="copy-btn" onclick="toggleViewPass('<?= htmlspecialchars($link['password']) ?>')" title="نمایش/مخفی رمز">
                        <i class="fas fa-eye" id="view_eye_icon"></i>
                    </button>
                    <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($link['password']) ?>')" title="کپی رمز عبور">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">وضعیت</span>
            <span class="info-value">
                <span class="status-badge <?= $link['status'] == 1 ? 'active' : 'inactive' ?>">
                    <?= $link['status'] == 1 ? 'فعال' : 'غیرفعال' ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">تاریخ ثبت</span>
            <span class="info-value"><?= !empty($link['shamsi_date']) ? $link['shamsi_date'] : jdate('Y/m/d', strtotime($link['created_at'])) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">ثبت کننده</span>
            <span class="info-value"><?= htmlspecialchars($link['creator_name'] ?? 'نامشخص') ?></span>
        </div>
        
        <a href="links.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:8px;"></i>بازگشت</a>
    </div>

    <script>
        function copyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    showToast('✅ متن با موفقیت کپی شد!');
                }).catch(function() {
                    fallbackCopyText(text);
                });
            } else {
                fallbackCopyText(text);
            }
        }

        function fallbackCopyText(text) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                showToast('✅ متن با موفقیت کپی شد!');
            } catch (err) {
                showToast('❌ خطا در کپی متن');
            }
            document.body.removeChild(textarea);
        }

        function showToast(message) {
            var toast = document.createElement('div');
            toast.className = 'toast';
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(function() {
                toast.style.opacity = '0';
                setTimeout(function() {
                    document.body.removeChild(toast);
                }, 300);
            }, 2000);
        }

        function toggleViewPass(password) {
            var span = document.getElementById('view_pass_display');
            var eye = document.getElementById('view_eye_icon');
            if (span.textContent === '••••••••') {
                span.textContent = password;
                eye.className = 'fas fa-eye-slash';
            } else {
                span.textContent = '••••••••';
                eye.className = 'fas fa-eye';
            }
        }
    </script>
</body>
</html>




