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