#!/usr/bin/env php
<?php
/**
 * اسکریپت ارسال خودکار یادآورها
 * این فایل باید در کرون جاب تنظیم شود
 * مثال: */5 * * * * php /path/to/cron/send_reminders.php
 */

// بارگذاری فایل‌های مورد نیاز
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/jdf.php';
require_once __DIR__ . '/../includes/ReminderService.php';

// لاگینگ
$logFile = __DIR__ . '/../logs/reminder_cron.log';
$logDir = dirname($logFile);

if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

try {
    logMessage("شروع پردازش یادآورها...");
    
    // ایجاد نمونه از سرویس
    $reminderService = new ReminderService($pdo);
    
    // پردازش یادآورها
    $results = $reminderService->processScheduledReminders();
    
    $successCount = 0;
    $failCount = 0;
    
    foreach ($results as $result) {
        if ($result['success']) {
            $successCount++;
        } else {
            $failCount++;
            logMessage("خطا در ارسال یادآور {$result['reminder_id']}: {$result['error']}");
        }
    }
    
    logMessage("پردازش کامل شد. موفق: $successCount، ناموفق: $failCount");
    
} catch (Exception $e) {
    logMessage("خطای کلی: " . $e->getMessage());
}