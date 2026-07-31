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