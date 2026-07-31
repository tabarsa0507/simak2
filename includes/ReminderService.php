<?php
/**
 * سرویس مدیریت یادآورها
 * مسیر: includes/ReminderService.php
 */

require_once __DIR__ . '/sms.php';

class ReminderService {
    private $pdo;
    private $mailer;
    private $sms;
    private $settings;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadSettings();
        $this->initMailer();
        $this->initSMS();
    }
    
    private function loadSettings() {
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
        $this->settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $stmt = $this->pdo->query("SELECT * FROM sms_settings WHERE status = 1 AND is_default = 1 LIMIT 1");
        $smsSetting = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($smsSetting) {
            $this->settings['sms_provider'] = $smsSetting['provider'] ?? 'smsir';
            $this->settings['sms_api_key'] = $smsSetting['api_key'] ?? '';
            $this->settings['sms_sender_number'] = $smsSetting['sender_number'] ?? '';
        }
    }
    
    private function initMailer() {
        require_once __DIR__ . '/mailer.php';
        $this->mailer = new Mailer($this->settings);
    }
    
    private function initSMS() {
        $this->sms = new SMS($this->settings);
    }
    
    /**
     * ارسال یادآور (اصلاح شده برای پشتیبانی از گروه و همه کاربران)
     */
    public function sendReminder($reminderId) {
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                SELECT r.*, 
                       u.full_name as user_name, 
                       u.mobile as user_mobile,
                       u.email as user_email,
                       u.telegram_id as user_telegram,
                       c.name as category_name
                FROM reminders r
                LEFT JOIN users u ON r.assigned_to = u.id OR r.user_id = u.id
                LEFT JOIN reminder_categories c ON r.category_id = c.id
                WHERE r.id = ?
            ");
            $stmt->execute([$reminderId]);
            $reminder = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reminder || $reminder['status'] != 'active') {
                return ['success' => false, 'error' => 'یادآور فعال نیست'];
            }
            
            // تشخیص گیرندگان
            if ($reminder['is_group'] && $reminder['assigned_group']) {
                $userIds = $this->getGroupMembers($reminder['assigned_group']);
            } elseif ($reminder['assigned_to']) {
                $userIds = [$reminder['assigned_to']];
            } else {
                // اگر اختصاص داده نشده، به همه کاربران فعال ارسال کن
                $userIds = $this->getAllActiveUsers();
            }
            
            if (empty($userIds)) {
                return ['success' => false, 'error' => 'هیچ گیرنده‌ای یافت نشد'];
            }
            
            $results = [];
            foreach ($userIds as $userId) {
                $user = $this->getUserById($userId);
                if (!$user) continue;
                
                $replacements = [
                    '{name}' => $user['full_name'] ?? 'کاربر',
                    '{title}' => $reminder['title'],
                    '{date}' => $reminder['shamsi_reminder_date'],
                    '{time}' => substr($reminder['reminder_time'] ?? '00:00', 0, 5),
                    '{description}' => $reminder['description'] ?? '',
                    '{category}' => $reminder['category_name'] ?? ''
                ];
                
                // SMS
                if ($reminder['send_sms'] && !empty($user['mobile'])) {
                    $smsText = str_replace(array_keys($replacements), $replacements, $reminder['sms_text']);
                    if (empty($smsText)) {
                        $smsText = "سلام {name}، یادآور: {title} در تاریخ {date} ساعت {time}";
                        $smsText = str_replace(array_keys($replacements), $replacements, $smsText);
                    }
                    $smsResult = $this->sms->send($user['mobile'], $smsText);
                    $this->logSend($reminderId, $userId, 'sms', $smsResult);
                    $results['sms'][] = $smsResult;
                }
                
                // Email
                if ($reminder['send_email'] && !empty($user['email'])) {
                    $emailSubject = str_replace(array_keys($replacements), $replacements, $reminder['email_subject']);
                    $emailBody = str_replace(array_keys($replacements), $replacements, $reminder['email_body']);
                    $emailResult = $this->mailer->send($user['email'], $emailSubject, $emailBody);
                    $this->logSend($reminderId, $userId, 'email', $emailResult);
                    $results['email'][] = $emailResult;
                }
                
                // System notification
                if ($reminder['send_system']) {
                    $this->createSystemNotification($userId, $reminder);
                }
            }
            
            // بروزرسانی زمان ارسال
            $stmt = $this->pdo->prepare("UPDATE reminders SET sent_at = NOW() WHERE id = ?");
            $stmt->execute([$reminderId]);
            
            // ثبت در activity_logs
            $this->logActivity($reminderId, 'send', 'یادآور ارسال شد');
            
            $this->pdo->commit();
            return ['success' => true, 'results' => $results];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * دریافت اعضای گروه
     */
    private function getGroupMembers($groupId) {
        $stmt = $this->pdo->prepare("SELECT members FROM user_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group || empty($group['members'])) {
            return [];
        }
        return explode(',', $group['members']);
    }
    
    /**
     * دریافت تمام کاربران فعال
     */
    private function getAllActiveUsers() {
        $stmt = $this->pdo->query("SELECT id FROM users WHERE status = 1");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * دریافت اطلاعات یک کاربر
     */
    private function getUserById($userId) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * محاسبه تاریخ بعدی برای یادآورهای تکراری
     */
    public function updateNextReminderDate($reminderId) {
        $stmt = $this->pdo->prepare("SELECT repeat_type, reminder_date, repeat_until, next_reminder_date FROM reminders WHERE id = ?");
        $stmt->execute([$reminderId]);
        $reminder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reminder || $reminder['repeat_type'] == 'none') {
            return;
        }
        
        $currentDate = new DateTime($reminder['next_reminder_date'] ?? $reminder['reminder_date']);
        $repeatUntil = $reminder['repeat_until'] ? new DateTime($reminder['repeat_until']) : null;
        
        switch ($reminder['repeat_type']) {
            case 'daily':   $nextDate = $currentDate->modify('+1 day'); break;
            case 'weekly':  $nextDate = $currentDate->modify('+1 week'); break;
            case 'monthly': $nextDate = $currentDate->modify('+1 month'); break;
            case 'yearly':  $nextDate = $currentDate->modify('+1 year'); break;
            default: return;
        }
        
        if ($repeatUntil && $nextDate > $repeatUntil) {
            $this->pdo->prepare("UPDATE reminders SET status = 'done', next_reminder_date = NULL WHERE id = ?")->execute([$reminderId]);
            return;
        }
        
        $update = $this->pdo->prepare("
            UPDATE reminders 
            SET next_reminder_date = :next_date,
                next_reminder_time = reminder_time,
                occurrence_count = occurrence_count + 1
            WHERE id = :id
        ");
        $update->execute([
            ':next_date' => $nextDate->format('Y-m-d'),
            ':id' => $reminderId
        ]);
    }
    
    /**
     * ثبت لاگ ارسال
     */
    private function logSend($reminderId, $userId, $type, $result) {
        $stmt = $this->pdo->prepare("
            INSERT INTO reminder_logs 
            (reminder_id, user_id, send_type, send_status, send_response, sent_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $reminderId,
            $userId,
            $type,
            $result['success'] ? 'sent' : 'failed',
            json_encode($result)
        ]);
    }
    
    /**
     * ثبت فعالیت
     */
    private function logActivity($reminderId, $action, $description) {
        $stmt = $this->pdo->prepare("
            INSERT INTO activity_logs (module, action, description, created_at)
            VALUES ('reminder', ?, ?, NOW())
        ");
        $stmt->execute([$action, $description . " (ID: $reminderId)"]);
    }
    
    /**
     * ایجاد اعلان سیستمی
     */
    private function createSystemNotification($userId, $reminder) {
        $stmt = $this->pdo->prepare("
            INSERT INTO user_notifications 
            (user_id, reminder_id, title, message, type, priority, created_at)
            VALUES (?, ?, ?, ?, 'reminder', ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $reminder['id'],
            $reminder['title'],
            $reminder['description'] ?? 'یادآوری جدید برای شما ثبت شده است.',
            $reminder['priority'] ?? 'medium'
        ]);
    }
}