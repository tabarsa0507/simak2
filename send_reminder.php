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