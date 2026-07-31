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