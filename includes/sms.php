<?php
/**
 * کلاس ارسال پیامک - پشتیبانی از sms.ir و کاوه‌نگار
 * مسیر: includes/sms.php
 */

class SMS {
    private $apiKey;
    private $senderNumber;
    private $provider;
    
    public function __construct($settings = null) {
        global $pdo;
        
        if ($settings && is_array($settings)) {
            $this->apiKey = $settings['sms_api_key'] ?? '';
            $this->senderNumber = $settings['sms_sender_number'] ?? '';
            $this->provider = $settings['sms_provider'] ?? 'smsir';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM sms_settings WHERE status = 1 AND is_default = 1 LIMIT 1");
            $stmt->execute();
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($setting) {
                $this->apiKey = $setting['api_key'] ?? '';
                $this->senderNumber = $setting['sender_number'] ?? '';
                $this->provider = $setting['provider'] ?? 'smsir';
            } else {
                $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'sms_%'");
                $stmt->execute();
                $smsSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
                $this->apiKey = $smsSettings['sms_api_key'] ?? '';
                $this->senderNumber = $smsSettings['sms_sender_number'] ?? '';
                $this->provider = $smsSettings['sms_provider'] ?? 'smsir';
            }
        }
    }
    
    public function send($to, $message) {
        $mobiles = is_array($to) ? $to : [$to];
        
        switch(strtolower($this->provider)) {
            case 'smsir':
                return $this->sendViaSmsIr($mobiles, $message);
            case 'kavenegar':
                return $this->sendViaKavenegar($mobiles, $message);
            default:
                return ['success' => false, 'error' => 'ارائه‌دهنده پیامک پشتیبانی نمی‌شود'];
        }
    }
    
    private function sendViaSmsIr($mobiles, $message) {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'کلید API sms.ir تنظیم نشده است'];
        }
        
        $url = 'https://api.sms.ir/v1/send/bulk';
        $data = [
            'lineNumber' => $this->senderNumber,
            'messageText' => $message,
            'mobiles' => $mobiles,
            'sendDateTime' => null
        ];
        
        $headers = [
            'X-API-KEY: ' . trim($this->apiKey),
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode == 200 && isset($result['status']) && $result['status'] == 1) {
            return ['success' => true, 'result' => $result];
        } else {
            return ['success' => false, 'error' => $result['message'] ?? 'خطا در ارسال پیامک'];
        }
    }
    
    private function sendViaKavenegar($mobiles, $message) {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'کلید API کاوه‌نگار تنظیم نشده است'];
        }
        
        $url = "https://api.kavenegar.com/v1/{$this->apiKey}/sms/send.json";
        $data = [
            'receptor' => implode(',', $mobiles),
            'message' => $message,
            'sender' => $this->senderNumber
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) {
            $result = json_decode($response, true);
            if (isset($result['return']['status']) && $result['return']['status'] == 200) {
                return ['success' => true, 'result' => $result];
            }
        }
        return ['success' => false, 'error' => 'خطا در ارسال پیامک از طریق کاوه‌نگار'];
    }
}