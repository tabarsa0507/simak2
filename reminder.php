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