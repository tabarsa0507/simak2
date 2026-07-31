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