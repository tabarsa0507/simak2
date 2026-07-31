<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die('شناسه هزینه نامعتبر است');

// توابع تبدیل اعداد به فارسی
function persian_number($number) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english, $persian, (string)$number);
}

function persian_number_str($str) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english, $persian, $str);
}

// دریافت اطلاعات هزینه
$stmt = $pdo->prepare("
    SELECT te.*,
           u.full_name as user_name,
           u.national_id as user_national_id,
           p.name as project_name,
           ap.full_name as approved_by_name,
           op.name as origin_province,
           oc.name as origin_city,
           dp.name as destination_province,
           dc.name as destination_city,
           cr.full_name as created_by_name
    FROM travel_expenses te
    LEFT JOIN users u ON te.user_id = u.id
    LEFT JOIN projects p ON te.project_id = p.id
    LEFT JOIN users ap ON te.approved_by = ap.id
    LEFT JOIN provinces op ON te.origin_province_id = op.id
    LEFT JOIN cities oc ON te.origin_city_id = oc.id
    LEFT JOIN provinces dp ON te.destination_province_id = dp.id
    LEFT JOIN cities dc ON te.destination_city_id = dc.id
    LEFT JOIN users cr ON te.created_by = cr.id
    WHERE te.id = ?
");
$stmt->execute([$id]);
$expense = $stmt->fetch();

if (!$expense) die('هزینه‌ای با این شناسه یافت نشد');

$items = $pdo->prepare("
    SELECT ei.*, ec.name as category_name
    FROM expense_items ei
    LEFT JOIN expense_categories ec ON ei.category_id = ec.id
    WHERE ei.expense_id = ?
    ORDER BY ei.id
");
$items->execute([$id]);
$items = $items->fetchAll();

$totalAmount = array_sum(array_column($items, 'amount'));

function numberToWords($num) {
    if ($num == 0) return 'صفر';
    if ($num < 0) return 'منفی ' . numberToWords(abs($num));
    
    $units = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $hundreds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    $thousands = ['', 'هزار', 'میلیون', 'میلیارد'];
    
    $num = (int)$num;
    if ($num < 1000) {
        $h = floor($num / 100);
        $r = $num % 100;
        $words = '';
        if ($h > 0) $words .= $hundreds[$h] . ' ';
        if ($r >= 20) {
            $t = floor($r / 10);
            $u = $r % 10;
            $words .= $tens[$t];
            if ($u > 0) $words .= ' و ' . $units[$u];
        } elseif ($r > 0 && $r < 10) {
            $words .= $units[$r];
        } elseif ($r >= 10 && $r < 20) {
            $words .= $teens[$r - 10];
        }
        return trim($words);
    }
    
    $parts = [];
    $temp = $num;
    $i = 0;
    while ($temp > 0) {
        $part = $temp % 1000;
        if ($part > 0) $parts[$i] = $part;
        $temp = floor($temp / 1000);
        $i++;
    }
    
    $result = [];
    foreach ($parts as $index => $part) {
        $partWords = '';
        $h = floor($part / 100);
        $r = $part % 100;
        if ($h > 0) $partWords .= $hundreds[$h] . ' ';
        if ($r >= 20) {
            $t = floor($r / 10);
            $u = $r % 10;
            $partWords .= $tens[$t];
            if ($u > 0) $partWords .= ' و ' . $units[$u];
        } elseif ($r > 0 && $r < 10) {
            $partWords .= $units[$r];
        } elseif ($r >= 10 && $r < 20) {
            $partWords .= $teens[$r - 10];
        }
        if (!empty($partWords)) {
            $result[] = trim($partWords) . ' ' . $thousands[$index];
        }
    }
    $words = implode(' و ', array_reverse($result));
    return trim($words);
}

function getPaymentTypeLabel($type) {
    $labels = ['cash'=>'نقدی', 'non_cash'=>'غیرنقدی', 'card'=>'کارت بانکی', 'check'=>'چک', 'transfer'=>'اینترنتی'];
    return $labels[$type] ?? $type;
}

function getDocumentTypeLabel($type) {
    $labels = ['invoice'=>'فاکتور', 'receipt'=>'رسید', 'bill'=>'قبض', 'other'=>'سایر'];
    return $labels[$type] ?? $type;
}

function getStatusLabel($status) {
    $labels = ['pending'=>'در انتظار تایید', 'approved'=>'تایید شده', 'rejected'=>'رد شده'];
    return $labels[$status] ?? $status;
}

function getStatusColor($status) {
    $colors = ['pending'=>'#fef3c7', 'approved'=>'#d1fae5', 'rejected'=>'#fecaca'];
    return $colors[$status] ?? '#f3f4f6';
}

function getStatusTextColor($status) {
    $colors = ['pending'=>'#92400e', 'approved'=>'#065f46', 'rejected'=>'#991b1b'];
    return $colors[$status] ?? '#6b7280';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده هزینه ماموریت</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #e8ecf0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .paper-a5 {
            width: 148mm;
            min-height: 210mm;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 8mm 6mm;
            position: relative;
            direction: rtl;
            font-size: 12px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0969da;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .header .company {
            font-size: 16px;
            font-weight: 800;
            color: #1a2332;
        }
        .header .company i { color: #0969da; }
        .header .title {
            font-size: 18px;
            font-weight: 700;
            color: #0969da;
            margin-top: 2px;
        }
        .header .sub {
            font-size: 11px;
            color: #57606a;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .main-table td, .main-table th {
            border: 1px solid #1a2332;
            padding: 5px 8px;
            vertical-align: middle;
            font-size: 12px;
        }
        .main-table .label-cell {
            background: #f8f9fa;
            font-weight: 600;
            white-space: nowrap;
            width: 20%;
        }
        .main-table .value-cell {
            font-weight: 500;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        .items-table th {
            background: #f8f9fa;
            border: 1px solid #1a2332;
            padding: 4px 6px;
            text-align: center;
            font-weight: 700;
            font-size: 11px;
        }
        .items-table td {
            border: 1px solid #1a2332;
            padding: 4px 6px;
            text-align: center;
            font-size: 11px;
        }
        .items-table .total-row {
            background: #eff6ff;
            font-weight: 700;
        }
        .total-box {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            padding: 6px 10px;
            background: #0969da;
            color: #fff;
            border-radius: 4px;
            font-weight: 700;
            font-size: 12px;
        }
        .total-box .words {
            font-size: 11px;
            font-weight: 400;
        }
        .companions-box {
            margin-top: 8px;
            padding: 6px 10px;
            background: #f8f9fa;
            border-radius: 4px;
            border-right: 4px solid #0969da;
        }
        .companions-box .label {
            font-weight: 600;
            font-size: 12px;
        }
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-top: 14px;
            padding-top: 10px;
            border-top: 2px solid #1a2332;
        }
        .signature-item {
            text-align: center;
        }
        .signature-item .line {
            border-bottom: 2px solid #1a2332;
            height: 35px;
            margin-bottom: 4px;
        }
        .signature-item .label {
            font-size: 11px;
            color: #57606a;
            font-weight: 500;
        }
        .signature-item .name {
            font-size: 12px;
            color: #1a2332;
            font-weight: 700;
            margin-top: 2px;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .footer-text {
            text-align: center;
            font-size: 9px;
            color: #8b949e;
            margin-top: 10px;
            border-top: 1px solid #e8ecf0;
            padding-top: 6px;
        }
        .back-btn {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: #0969da;
            color: #fff;
            border: none;
            border-radius: 50px;
            padding: 12px 32px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(9,105,218,0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 1000;
            text-decoration: none;
        }
        .back-btn:hover {
            transform: translateX(-50%) translateY(-2px);
            box-shadow: 0 8px 30px rgba(9,105,218,0.5);
            background: #0550b3;
        }
        @media print {
            body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
            .paper-a5 { width: 100% !important; min-height: auto !important; border-radius: 0 !important; box-shadow: none !important; padding: 5mm 4mm !important; }
            .back-btn { display: none !important; }
            .signature-item .line { height: 25px !important; }
        }
        @page { size: A5; margin: 4mm; }
    </style>
</head>
<body>

<div class="paper-a5">
    <div class="header">
        <div class="company"><i class="fas fa-heartbeat"></i> شرکت دانش بنیان پیشگامان دنیای فناوری</div>
        <div class="title">فرم هزینه ماموریت</div>
        <div class="sub">پرونده الکترونیک سلامت</div>
    </div>

    <table class="main-table">
        <tr>
            <td class="label-cell">نام و نام خانوادگی</td>
            <td class="value-cell" colspan="3"><?= htmlspecialchars($expense['full_name'] ?? $expense['user_name'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="label-cell">تاریخ شروع</td>
            <td class="value-cell"><?= persian_number_str($expense['travel_date']) ?></td>
            <td class="label-cell" style="width:15%;">تاریخ اتمام</td>
            <td class="value-cell"><?= persian_number_str($expense['end_date'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="label-cell">مبدا</td>
            <td class="value-cell">
                <?php if ($expense['origin_city']): ?>
                    <?= htmlspecialchars($expense['origin_city']) ?>
                    <?php if ($expense['origin_province']): ?> (<?= htmlspecialchars($expense['origin_province']) ?>)<?php endif; ?>
                <?php else: ?>-<?php endif; ?>
            </td>
            <td class="label-cell">مقصد</td>
            <td class="value-cell">
                <?php if ($expense['destination_city']): ?>
                    <?= htmlspecialchars($expense['destination_city']) ?>
                    <?php if ($expense['destination_province']): ?> (<?= htmlspecialchars($expense['destination_province']) ?>)<?php endif; ?>
                <?php else: ?>-<?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="label-cell">نام پروژه</td>
            <td class="value-cell" colspan="3"><?= htmlspecialchars($expense['project_name'] ?? '-') ?></td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:12%;">هزینه (ریال)</th>
                <th style="width:14%;">دسته بندی</th>
                <th style="width:14%;">نوع پرداخت</th>
                <th style="width:18%;">نوع سند</th>
                <th style="width:42%;">توضیحات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" style="text-align:center;color:#57606a;">هیچ ردیف هزینه‌ای ثبت نشده است.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= persian_number(number_format($item['amount'])) ?></td>
                    <td><?= htmlspecialchars($item['category_name'] ?? '-') ?></td>
                    <td><?= getPaymentTypeLabel($item['payment_type']) ?></td>
                    <td><?= getDocumentTypeLabel($item['document_type']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($item['description'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="total-box">
        <span>جمع کل به حروف: <span class="words"><?= numberToWords($totalAmount) ?> ریال</span></span>
        <span>جمع کل به عدد: <?= persian_number(number_format($totalAmount)) ?> ریال</span>
    </div>

    <div class="companions-box">
        <span class="label"><i class="fas fa-users"></i> نام و نام خانوادگی افراد همراه:</span>
        <span><?= htmlspecialchars($expense['companions'] ?? '-') ?></span>
    </div>

    <div style="margin-top:6px;text-align:center;">
        <span class="status-badge" style="background:<?= getStatusColor($expense['status']) ?>;color:<?= getStatusTextColor($expense['status']) ?>;">
            وضعیت: <?= getStatusLabel($expense['status']) ?>
            <?php if ($expense['approved_by_name']): ?>
                (تایید کننده: <?= htmlspecialchars($expense['approved_by_name']) ?>)
            <?php endif; ?>
        </span>
    </div>

    <div class="signatures">
        <div class="signature-item">
            <div class="line"></div>
            <div class="label">امضای متقاضی</div>
            <div class="name"><?= htmlspecialchars($expense['full_name'] ?? $expense['user_name'] ?? '') ?></div>
        </div>
        <div class="signature-item">
            <div class="line"></div>
            <div class="label">امضای مدیر مالی</div>
            <div class="name"><?= $expense['status'] === 'approved' ? htmlspecialchars($expense['approved_by_name'] ?? '') : '.............................' ?></div>
        </div>
        <div class="signature-item">
            <div class="line"></div>
            <div class="label">مهر سازمان</div>
            <div class="name" style="font-size:10px;color:#0969da;">.............................</div>
        </div>
    </div>

    <div class="footer-text">
        تاریخ چاپ: <?= persian_number_str(jdate('Y/m/d H:i')) ?> &nbsp;|&nbsp; این فرم به‌صورت الکترونیکی ثبت شده است.
    </div>
</div>

<a href="travel_expenses.php" class="back-btn"><i class="fas fa-arrow-right"></i> بازگشت</a>

</body>
</html>