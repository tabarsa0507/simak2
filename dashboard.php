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