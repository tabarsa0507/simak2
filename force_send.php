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