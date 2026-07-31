<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die('شناسه سفارش نامعتبر است');

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

// دریافت اطلاعات سفارش
$stmt = $pdo->prepare("
    SELECT fo.*, u.full_name as creator_name
    FROM food_orders fo
    LEFT JOIN users u ON fo.created_by = u.id
    WHERE fo.id = ?
");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die('سفارشی با این شناسه یافت نشد');

// دریافت آیتم‌ها
$items = $pdo->prepare("
    SELECT foi.*, p.name as project_name
    FROM food_order_items foi
    LEFT JOIN projects p ON foi.project_id = p.id
    WHERE foi.order_id = ?
    ORDER BY foi.id
");
$items->execute([$id]);
$items = $items->fetchAll();

// پر کردن ردیف‌های خالی تا ۲۰
$allItems = [];
for ($i = 1; $i <= 20; $i++) {
    $allItems[$i] = $items[$i-1] ?? null;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرم تهیه غذای پرسنل</title>
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
            padding: 10px;
        }
        /* ===== تغییر سایز کاغذ به A6 ===== */
        .paper-a6 {
            width: 105mm;
            min-height: 148mm;
            background: #ffffff;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.12);
            padding: 5mm 4mm;
            direction: rtl;
            font-size: 7.5pt;
            line-height: 1.2;
        }
        .header {
            text-align: center;
            border-bottom: 1.5px solid #0969da;
            padding-bottom: 3px;
            margin-bottom: 4px;
        }
        .header .company {
            font-size: 9pt;
            font-weight: 800;
            color: #1a2332;
        }
        .header .company i { color: #0969da; }
        .header .title {
            font-size: 10pt;
            font-weight: 700;
            color: #0969da;
        }
        .header .sub { font-size: 6.5pt; color: #57606a; }

        .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            margin-bottom: 3px;
            padding: 2px 0;
        }
        .info-row .label { font-weight: 700; color: #1a2332; }
        .info-row .value { font-weight: 500; color: #24292f; }

        /* ===== یک جدول ۲۰ ردیفی ساده ===== */
        .form-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 6.5pt;
            margin-top: 3px;
        }
        .form-table th {
            background: #f8f9fa;
            border: 1px solid #1a2332;
            padding: 2px 3px;
            text-align: center;
            font-weight: 700;
            font-size: 6pt;
        }
        .form-table td {
            border: 1px solid #1a2332;
            padding: 2px 3px;
            text-align: center;
            font-size: 6pt;
        }
        .form-table td:first-child { font-weight: 700; width: 10%; }
        .form-table td:nth-child(2) { width: 35%; }
        .form-table td:nth-child(3) { width: 30%; }
        .form-table td:nth-child(4) { width: 25%; }

        .form-footer {
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1.5px solid #1a2332;
        }
        .form-footer .notes {
            font-size: 6pt;
            color: #57606a;
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        .form-footer .notes i { color: #cf222e; margin-left: 2px; }

        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 4px;
            margin-top: 2px;
        }
        .signature-item { text-align: center; }
        .signature-item .line {
            border-bottom: 1.5px solid #1a2332;
            height: 16px;
            margin-bottom: 2px;
        }
        .signature-item .label { font-size: 6pt; color: #57606a; font-weight: 600; }
        .signature-item .name { font-size: 6.5pt; color: #1a2332; font-weight: 700; }

        .print-btn, .back-btn {
            position: fixed;
            padding: 8px 20px;
            border: none;
            border-radius: 30px;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            font-weight: 600;
            z-index: 1000;
            text-decoration: none;
        }
        .print-btn {
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #0969da;
            color: #fff;
            font-size: 12px;
            box-shadow: 0 2px 12px rgba(9,105,218,0.3);
        }
        .print-btn:hover { background: #0550b3; }
        .back-btn {
            bottom: 70px;
            left: 50%;
            transform: translateX(-50%);
            background: #f0f2f4;
            color: #57606a;
            font-size: 11px;
        }
        .back-btn:hover { background: #e1e4e8; }

        .footer-text {
            text-align: center;
            font-size: 5.5pt;
            color: #8b949e;
            margin-top: 3px;
            border-top: 1px solid #e1e4e8;
            padding-top: 2px;
        }

        @media print {
            body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
            .paper-a6 {
                width: 100% !important;
                min-height: auto !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                padding: 4mm 3mm !important;
            }
            .print-btn, .back-btn { display: none !important; }
            .form-table th, .form-table td { border-color: #1a2332 !important; }
        }
        @page {
            size: A6;
            margin: 3mm;
        }
    </style>
</head>
<body>

<div class="paper-a6" id="printArea">
    <!-- ===== هدر ===== -->
    <div class="header">
        <div class="company"><i class="fas fa-heartbeat"></i> شرکت دانش بنیان پیشگامان</div>
        <div class="title">فرم تهیه غذای پرسنل</div>
        <div class="sub"><?= persian_number_str($order['order_date']) ?> - <?= htmlspecialchars($order['meal_type']) ?></div>
    </div>

    <!-- ===== اطلاعات بالا ===== -->
    <div class="info-row">
        <span><span class="label">تاریخ:</span> <span class="value"><?= persian_number_str($order['order_date']) ?></span></span>
        <span><span class="label">وعده:</span> <span class="value"><?= htmlspecialchars($order['meal_type']) ?></span></span>
        <span><span class="label">ثبت:</span> <span class="value"><?= htmlspecialchars($order['creator_name'] ?? '-') ?></span></span>
    </div>

    <!-- ===== یک جدول ۲۰ ردیفی ===== -->
    <table class="form-table">
        <thead>
            <tr>
                <th>ردیف</th>
                <th>نام و نام خانوادگی همکار</th>
                <th>نام پروژه</th>
                <th>نوع غذا</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 1; $i <= 20; $i++): 
                $item = $allItems[$i] ?? null;
            ?>
            <tr>
                <td><?= persian_number($i) ?></td>
                <td><?= htmlspecialchars($item['colleague_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($item['project_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($item['food_name'] ?? '') ?></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <!-- ===== پاورقی ===== -->
    <div class="form-footer">
        <div class="notes">
            <span><i class="fas fa-info-circle"></i> * حضور در واحد کاری حداقل تا ساعت ۱۸:۰۰</span>
            <span><i class="fas fa-info-circle"></i> * حضور در روزهای تعطیل</span>
        </div>

        <div class="signatures">
            <div class="signature-item">
                <div class="line"></div>
                <div class="label">مدیر پروژه</div>
                <div class="name"><?= htmlspecialchars($order['signature_project'] ?? '.............................') ?></div>
            </div>
            <div class="signature-item">
                <div class="line"></div>
                <div class="label">مدیر واحد مالی</div>
                <div class="name"><?= htmlspecialchars($order['signature_finance'] ?? '.............................') ?></div>
            </div>
            <div class="signature-item">
                <div class="line"></div>
                <div class="label">تاریخ ثبت</div>
                <div class="name"><?= persian_number_str($order['order_date']) ?></div>
            </div>
        </div>
    </div>

    <div class="footer-text">
        تاریخ چاپ: <?= persian_number_str(nowShamsi('Y/m/d H:i')) ?>
    </div>
</div>

<button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> پرینت</button>
<a href="daily_food_orders.php" class="back-btn"><i class="fas fa-arrow-right"></i> بازگشت</a>

</body>
</html>