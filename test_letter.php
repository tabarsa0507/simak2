<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';

$user = getCurrentUser();
$currentYear = jdate('Y');

// تولید شماره نامه
function generateLetterNumber($pdo, $year) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM letter_numbering WHERE year = ?");
        $stmt->execute([$year]);
        $numbering = $stmt->fetch();
        
        if (!$numbering) {
            $pdo->prepare("INSERT INTO letter_numbering (year, start_number, current_number) VALUES (?, 1, 0)")->execute([$year]);
            $current = 1;
        } else {
            $current = $numbering['current_number'] + 1;
            $pdo->prepare("UPDATE letter_numbering SET current_number = ? WHERE year = ?")->execute([$current, $year]);
        }
        
        return str_pad($current, 4, '0', STR_PAD_LEFT) . '/' . $year;
    } catch (PDOException $e) {
        return '0001/' . $year;
    }
}

// ===== شبیه‌سازی ثبت نامه =====
$subject = 'تست از فرم';
$content = 'متن تست از فرم';
$type = 'incoming';
$status = 'draft';
$shamsi_date = jdate('Y/m/d');
$shamsi_letter_date = jdate('Y/m/d');
$has_attachment = 0;
$attachment_count = 0;
$attachment_description = '';
$signature_id = 0;
$signatory = '';
$signature_path = '';

echo "=== شروع تست فرم ===<br><br>";

try {
    $letter_number = generateLetterNumber($pdo, $currentYear);
    echo "1. شماره تولید شد: " . $letter_number . "<br>";
    
    $stmt = $pdo->prepare("INSERT INTO letters (letter_number, letter_date, shamsi_letter_date, type, subject, content, summary, priority, status, has_attachment, attachment_count, attachment_description, signatory, signature_path, signature_id, sender_name, sender_organization, sender_phone, receiver_name, receiver_organization, receiver_phone, header_id, template_id, created_by, created_at, shamsi_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
    $result = $stmt->execute([$letter_number, date('Y-m-d'), $shamsi_letter_date, $type, $subject, $content, '', 'medium', $status, $has_attachment, $attachment_count, $attachment_description, $signatory, $signature_path, $signature_id, '', '', '', '', '', '', null, null, $user['id'], $shamsi_date]);
    
    if ($result) {
        $id = $pdo->lastInsertId();
        echo "2. ✅ نامه با موفقیت ذخیره شد. ID: " . $id . "<br>";
        
        // بررسی اینکه نامه در دیتابیس هست
        $check = $pdo->query("SELECT * FROM letters WHERE id = $id")->fetch();
        if ($check) {
            echo "3. ✅ نامه در دیتابیس پیدا شد<br>";
            echo "   شماره: " . $check['letter_number'] . "<br>";
            echo "   موضوع: " . $check['subject'] . "<br>";
        }
    } else {
        echo "2. ❌ خطا در ذخیره: " . print_r($stmt->errorInfo(), true);
    }
    
} catch (PDOException $e) {
    echo "❌ خطا: " . $e->getMessage();
}
?>