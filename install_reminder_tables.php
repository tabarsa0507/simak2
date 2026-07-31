<?php
// ===== فایل: install_reminder_tables.php =====
// این فایل را در مسیر C:\xampp\htdocs\company_kb\ ایجاد کنید

require_once 'config.php';

try {
    // ===== 1. جدول reminder_categories =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reminder_categories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `color` varchar(20) DEFAULT '#0969da',
            `icon` varchar(50) DEFAULT 'fa-tag',
            `description` text,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول reminder_categories ایجاد شد.<br>";
    
    // ===== 2. جدول user_groups =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `description` text,
            `members` text,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول user_groups ایجاد شد.<br>";
    
    // ===== 3. جدول reminders =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reminders` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `description` text,
            `category_id` int(11) DEFAULT NULL,
            `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
            `status` enum('active','done','expired','canceled') DEFAULT 'active',
            `reminder_date` date NOT NULL,
            `reminder_time` time DEFAULT NULL,
            `shamsi_reminder_date` varchar(20) DEFAULT NULL,
            `repeat_type` enum('none','daily','weekly','monthly','yearly') DEFAULT 'none',
            `repeat_until` date DEFAULT NULL,
            `shamsi_repeat_until` varchar(20) DEFAULT NULL,
            `assigned_to` int(11) DEFAULT NULL,
            `assigned_group` int(11) DEFAULT NULL,
            `is_group` tinyint(1) DEFAULT 0,
            `send_sms` tinyint(1) DEFAULT 0,
            `send_email` tinyint(1) DEFAULT 0,
            `send_telegram` tinyint(1) DEFAULT 0,
            `send_whatsapp` tinyint(1) DEFAULT 0,
            `send_system` tinyint(1) DEFAULT 1,
            `sms_text` text,
            `email_subject` varchar(255) DEFAULT NULL,
            `email_body` text,
            `sent_at` datetime DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            `shamsi_date` varchar(20) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `category_id` (`category_id`),
            KEY `assigned_to` (`assigned_to`),
            KEY `assigned_group` (`assigned_group`),
            KEY `created_by` (`created_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول reminders ایجاد شد.<br>";
    
    // ===== 4. جدول reminder_logs =====
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reminder_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `reminder_id` int(11) NOT NULL,
            `user_id` int(11) DEFAULT NULL,
            `send_type` enum('sms','email','telegram','whatsapp','system') NOT NULL,
            `send_status` enum('pending','sent','failed') DEFAULT 'pending',
            `send_response` text,
            `sent_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `reminder_id` (`reminder_id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ جدول reminder_logs ایجاد شد.<br>";
    
    // ===== 5. داده‌های اولیه برای دسته‌بندی‌ها =====
    $pdo->exec("
        INSERT IGNORE INTO `reminder_categories` (`name`, `color`, `icon`) VALUES
        ('شخصی', '#0969da', 'fa-user'),
        ('کاری', '#2da44e', 'fa-briefcase'),
        ('پروژه', '#8250df', 'fa-project-diagram'),
        ('مشتری', '#cf222e', 'fa-user-tie'),
        ('پشتیبانی', '#ff9800', 'fa-headset'),
        ('یادبود', '#e91e63', 'fa-gift'),
        ('آموزشی', '#00bcd4', 'fa-graduation-cap')
    ");
    echo "✅ داده‌های اولیه وارد شدند.<br>";
    
    echo "<br><strong style='color:green;font-size:18px;'>✅ همه جداول با موفقیت ایجاد شدند!</strong>";
    echo "<br><br><a href='reminder.php' style='padding:12px 30px;background:#0969da;color:#fff;text-decoration:none;border-radius:8px;font-size:16px;'>👉 رفتن به صفحه یادآور</a>";
    
} catch(PDOException $e) {
    echo "❌ خطا: " . $e->getMessage();
}
?>