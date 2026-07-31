<?php
// این فایل را در خط فرمان ویندوز اجرا کنید: php cron_runner.php
while (true) {
    include 'cron_reminder.php';
    sleep(60); // هر ۶۰ ثانیه یک بار اجرا می‌شود
}