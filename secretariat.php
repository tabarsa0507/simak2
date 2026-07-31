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