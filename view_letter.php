<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه نامه مشخص نشده است');
}

$id = (int)$_GET['id'];

$query = "
    SELECT l.*, 
           u.full_name as creator_name,
           h.title as header_title,
           h.logo as header_logo,
           h.address as header_address,
           h.phone as header_phone,
           h.fax as header_fax,
           h.email as header_email,
           h.website as header_website,
           t.title as template_title
    FROM letters l
    LEFT JOIN users u ON l.created_by = u.id
    LEFT JOIN letter_headers h ON l.header_id = h.id
    LEFT JOIN letter_templates t ON l.template_id = t.id
    WHERE l.id = ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$letter = $stmt->fetch();

if (!$letter) {
    die('نامه یافت نشد');
}

// دریافت ارجاعات
$stmt = $pdo->prepare("
    SELECT r.*, 
           u.full_name as referred_to_name,
           u2.full_name as referred_by_name
    FROM letter_referrals r
    LEFT JOIN users u ON r.referred_to = u.id
    LEFT JOIN users u2 ON r.referred_by = u2.id
    WHERE r.letter_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$id]);
$referrals = $stmt->fetchAll();

// دریافت پیوست‌ها
$stmt = $pdo->prepare("SELECT * FROM letter_attachments WHERE letter_id = ?");
$stmt->execute([$id]);
$attachments = $stmt->fetchAll();

// دریافت لاگ‌ها
$stmt = $pdo->prepare("
    SELECT l.*, u.full_name as user_name
    FROM letter_logs l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.letter_id = ?
    ORDER BY l.created_at DESC
");
$stmt->execute([$id]);
$logs = $stmt->fetchAll();

function getStatusLabel($status) {
    $statuses = [
        'draft' => 'پیش‌نویس',
        'sent' => 'ارسال شده',
        'received' => 'دریافت شده',
        'answered' => 'پاسخ داده شده',
        'archived' => 'بایگانی'
    ];
    return $statuses[$status] ?? $status;
}

function getStatusColor($status) {
    $colors = [
        'draft' => '#e2e8f0',
        'sent' => '#dbeafe',
        'received' => '#d1fae5',
        'answered' => '#fef3c7',
        'archived' => '#f3f4f6'
    ];
    return $colors[$status] ?? '#e2e8f0';
}

function getTypeLabel($type) {
    return $type == 'incoming' ? 'وارده' : 'صادره';
}

function getPriorityLabel($priority) {
    $priorities = [
        'low' => 'کم',
        'medium' => 'متوسط',
        'high' => 'زیاد',
        'urgent' => 'فوری'
    ];
    return $priorities[$priority] ?? 'متوسط';
}

function getReferralStatus($status) {
    $statuses = [
        'pending' => ['label' => 'در انتظار', 'color' => '#fef3c7', 'text' => '#92400e'],
        'seen' => ['label' => 'مشاهده شده', 'color' => '#dbeafe', 'text' => '#1e40af'],
        'answered' => ['label' => 'پاسخ داده شده', 'color' => '#d1fae5', 'text' => '#065f46'],
        'closed' => ['label' => 'بسته شده', 'color' => '#f3f4f6', 'text' => '#6b7280']
    ];
    return $statuses[$status] ?? $statuses['pending'];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده نامه</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #f6f8fa;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
        }
        .letter-header {
            border-bottom: 2px solid #0969da;
            padding-bottom: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .letter-header h2 {
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .letter-number {
            font-size: 16px;
            color: #0969da;
            font-weight: 600;
            background: #eff6ff;
            padding: 4px 16px;
            border-radius: 20px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f4;
        }
        .info-label {
            color: #57606a;
            font-weight: 500;
            min-width: 120px;
        }
        .info-value {
            color: #1a1a2e;
            font-weight: 600;
            word-break: break-word;
            text-align: left;
            flex: 1;
        }
        .content-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 16px 0;
            border: 1px solid #e1e4e8;
            line-height: 2.2;
            font-size: 14px;
        }
        .content-box p {
            margin-bottom: 8px;
        }
        .badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .btn-back {
            display: inline-block;
            padding: 10px 24px;
            background: #f0f2f4;
            color: #57606a;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-back:hover {
            background: #e1e4e8;
        }
        .btn-print {
            display: inline-block;
            padding: 10px 24px;
            background: #8250df;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-print:hover {
            background: #6a3fc7;
        }
        .referral-item {
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 8px;
            border-right: 4px solid #0969da;
        }
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 16px 0 12px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-title i {
            color: #0969da;
        }
        .log-item {
            font-size: 13px;
            color: #57606a;
            padding: 4px 0;
            border-bottom: 1px solid #f0f2f4;
        }
        .log-item .time {
            color: #8b949e;
            font-size: 11px;
        }
        /* ===== استایل‌های فونت‌های مختلف ===== */
        .content-box .b-yekan { font-family: 'B Yekan', 'Tahoma', sans-serif; }
        .content-box .b-titr { font-family: 'B Titr', 'Tahoma', sans-serif; }
        .content-box .b-mitra { font-family: 'B Mitra', 'Tahoma', sans-serif; }
        .content-box .b-nazanin { font-family: 'B Nazanin', 'Tahoma', sans-serif; }
    </style>
</head>
<body>
    <div class="container">
        <!-- ===== هدر نامه ===== -->
        <div class="letter-header">
            <h2>
                <i class="fas fa-envelope" style="color:#0969da;"></i>
                مشاهده نامه
            </h2>
            <span class="letter-number"><?= htmlspecialchars($letter['letter_number']) ?></span>
        </div>

        <!-- ===== اطلاعات اصلی ===== -->
        <div class="info-row">
            <span class="info-label">شماره نامه</span>
            <span class="info-value" style="direction:ltr;text-align:left;"><?= htmlspecialchars($letter['letter_number']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">تاریخ نامه</span>
            <span class="info-value"><?= htmlspecialchars($letter['shamsi_letter_date']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">نوع</span>
            <span class="info-value">
                <span class="badge" style="background:<?= $letter['type'] == 'incoming' ? '#d1fae5' : '#dbeafe' ?>;color:<?= $letter['type'] == 'incoming' ? '#065f46' : '#1e40af' ?>;">
                    <?= getTypeLabel($letter['type']) ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">اولویت</span>
            <span class="info-value">
                <span class="badge" style="background:<?= $letter['priority'] == 'urgent' ? '#fee2e2' : ($letter['priority'] == 'high' ? '#fecaca' : ($letter['priority'] == 'medium' ? '#fef3c7' : '#d1fae5')) ?>;color:<?= $letter['priority'] == 'urgent' ? '#dc2626' : ($letter['priority'] == 'high' ? '#991b1b' : ($letter['priority'] == 'medium' ? '#92400e' : '#065f46')) ?>;">
                    <?= getPriorityLabel($letter['priority']) ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">وضعیت</span>
            <span class="info-value">
                <span class="badge" style="background:<?= getStatusColor($letter['status']) ?>;color:#1a1a2e;">
                    <?= getStatusLabel($letter['status']) ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">موضوع</span>
            <span class="info-value"><?= htmlspecialchars($letter['subject']) ?></span>
        </div>

        <?php if (!empty($letter['summary'])): ?>
        <div class="info-row">
            <span class="info-label">خلاصه</span>
            <span class="info-value"><?= htmlspecialchars($letter['summary']) ?></span>
        </div>
        <?php endif; ?>

        <!-- ===== متن نامه (بدون htmlspecialchars برای نمایش صحیح HTML) ===== -->
        <div class="section-title"><i class="fas fa-file-alt"></i> متن نامه</div>
        <div class="content-box">
            <?= $letter['content'] ?>
        </div>

        <!-- ===== اطلاعات فرستنده/گیرنده ===== -->
        <div class="section-title"><i class="fas fa-user"></i> اطلاعات فرستنده / گیرنده</div>
        <?php if (!empty($letter['sender_name']) || !empty($letter['sender_organization'])): ?>
        <div class="info-row">
            <span class="info-label">فرستنده</span>
            <span class="info-value">
                <?= htmlspecialchars($letter['sender_name'] ?? '') ?>
                <?php if (!empty($letter['sender_organization'])): ?>
                    <span style="color:#8b949e;font-weight:400;">(<?= htmlspecialchars($letter['sender_organization']) ?>)</span>
                <?php endif; ?>
                <?php if (!empty($letter['sender_phone'])): ?>
                    <span style="color:#8b949e;font-weight:400;">| تلفن: <?= htmlspecialchars($letter['sender_phone']) ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <?php if (!empty($letter['receiver_name']) || !empty($letter['receiver_organization'])): ?>
        <div class="info-row">
            <span class="info-label">گیرنده</span>
            <span class="info-value">
                <?= htmlspecialchars($letter['receiver_name'] ?? '') ?>
                <?php if (!empty($letter['receiver_organization'])): ?>
                    <span style="color:#8b949e;font-weight:400;">(<?= htmlspecialchars($letter['receiver_organization']) ?>)</span>
                <?php endif; ?>
                <?php if (!empty($letter['receiver_phone'])): ?>
                    <span style="color:#8b949e;font-weight:400;">| تلفن: <?= htmlspecialchars($letter['receiver_phone']) ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- ===== ارجاعات ===== -->
        <?php if (!empty($referrals)): ?>
        <div class="section-title"><i class="fas fa-share"></i> ارجاعات</div>
        <?php foreach ($referrals as $r): 
            $rStatus = getReferralStatus($r['status']);
        ?>
        <div class="referral-item">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div>
                    <strong>به: </strong>
                    <?= htmlspecialchars($r['referred_to_name'] ?? $r['referred_to_unit'] ?? 'نامشخص') ?>
                </div>
                <div>
                    <span class="badge" style="background:<?= $rStatus['color'] ?>;color:<?= $rStatus['text'] ?>;">
                        <?= $rStatus['label'] ?>
                    </span>
                </div>
            </div>
            <div style="font-size:13px;color:#57606a;margin-top:4px;">
                تاریخ ارجاع: <?= htmlspecialchars($r['shamsi_referral_date']) ?>
                <?php if (!empty($r['shamsi_due_date'])): ?>
                    | تاریخ پاسخ: <?= htmlspecialchars($r['shamsi_due_date']) ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($r['description'])): ?>
                <div style="font-size:13px;color:#57606a;margin-top:4px;"><?= htmlspecialchars($r['description']) ?></div>
            <?php endif; ?>
            <?php if (!empty($r['answer_text'])): ?>
                <div style="font-size:13px;color:#065f46;margin-top:4px;background:#ecfdf3;padding:8px;border-radius:4px;">
                    <strong>پاسخ: </strong><?= nl2br(htmlspecialchars($r['answer_text'])) ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ===== پیوست‌ها ===== -->
        <?php if (!empty($attachments)): ?>
        <div class="section-title"><i class="fas fa-paperclip"></i> پیوست‌ها</div>
        <?php foreach ($attachments as $a): ?>
        <div class="info-row">
            <span class="info-label"><?= htmlspecialchars($a['file_name']) ?></span>
            <span class="info-value" style="font-weight:400;font-size:13px;">
                <?= htmlspecialchars($a['file_size'] ?? '') ?>
                <a href="<?= htmlspecialchars($a['file_path']) ?>" download style="color:#0969da;margin-right:8px;">
                    <i class="fas fa-download"></i> دانلود
                </a>
            </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ===== لاگ‌ها ===== -->
        <?php if (!empty($logs)): ?>
        <div class="section-title"><i class="fas fa-history"></i> تاریخچه</div>
        <?php foreach ($logs as $log): ?>
        <div class="log-item">
            <span><?= htmlspecialchars($log['user_name'] ?? 'سیستم') ?></span>
            <span><?= htmlspecialchars($log['description']) ?></span>
            <span class="time">| <?= $log['created_at'] ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ===== اطلاعات ثبت ===== -->
        <div class="section-title"><i class="fas fa-info-circle"></i> اطلاعات ثبت</div>
        <div class="info-row">
            <span class="info-label">تاریخ ثبت</span>
            <span class="info-value"><?= htmlspecialchars($letter['shamsi_date'] ?? '-') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">ثبت کننده</span>
            <span class="info-value"><?= htmlspecialchars($letter['creator_name'] ?? 'نامشخص') ?></span>
        </div>

        <!-- ===== دکمه‌ها ===== -->
        <div style="display:flex;gap:10px;margin-top:24px;flex-wrap:wrap;">
            <a href="secretariat.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:8px;"></i>بازگشت</a>
            <a href="print_letter.php?id=<?= $letter['id'] ?>" target="_blank" class="btn-print"><i class="fas fa-print" style="margin-left:8px;"></i>چاپ نامه</a>
        </div>
    </div>
</body>
</html>