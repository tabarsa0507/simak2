<?php
/**
 * کلاس Mailer ساده برای جلوگیری از خطا
 * مسیر: includes/mailer.php
 */
class Mailer {
    private $settings;
    
    public function __construct($settings) {
        $this->settings = $settings;
    }
    
    /**
     * ارسال ایمیل (نسخه لاگ برای تست)
     */
    public function send($to, $subject, $body) {
        // لاگ کردن ایمیل به جای ارسال واقعی (برای تست در لوکال)
        $log = date('Y-m-d H:i:s') . " - To: $to, Subject: $subject\n";
        $log .= "Body: $body\n----------------------------------------\n";
        
        $logFile = __DIR__ . '/../logs/mail.log';
        if (!is_dir(__DIR__ . '/../logs')) {
            mkdir(__DIR__ . '/../logs', 0777, true);
        }
        file_put_contents($logFile, $log, FILE_APPEND);
        
        // اگر تنظیمات SMTP واقعی دارید، می‌توانید بعداً این بخش را تکمیل کنید
        // فعلاً برای تست، فرض می‌کنیم موفق بوده
        return ['success' => true, 'message' => 'ایمیل در لاگ ثبت شد'];
    }
}