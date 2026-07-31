<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه اموال مشخص نشده است');
}

$id = (int)$_GET['id'];

$query = "
    SELECT a.*, 
           c.name as category_name, 
           c.color as category_color,
           u.full_name as creator_name,
           aa.assigned_to,
           aa.project,
           aa.unit,
           aa.shamsi_assigned_date,
           aa.shamsi_return_date,
           (SELECT full_name FROM users WHERE id = aa.assigned_to) as assigned_to_name
    FROM assets a
    LEFT JOIN asset_categories c ON a.category_id = c.id
    LEFT JOIN users u ON a.created_by = u.id
    LEFT JOIN asset_assignments aa ON a.id = aa.asset_id AND aa.status = 'assigned'
    WHERE a.id = ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) {
    die('اموال یافت نشد');
}

$status = getAssetStatus($asset['status']);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده اموال</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: #f6f8fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            max-width: 650px;
            width: 100%;
        }
        h2 {
            color: #1a1a2e;
            border-bottom: 2px solid #cf222e;
            padding-bottom: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
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
            margin-top: 20px;
            transition: 0.2s;
        }
        .btn-back:hover {
            background: #e1e4e8;
        }
        .status-badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-box" style="color:#cf222e;margin-left:10px;"></i>مشاهده اموال</h2>
        
        <div class="info-row">
            <span class="info-label">عنوان</span>
            <span class="info-value"><?= htmlspecialchars($asset['title']) ?></span>
        </div>
        
        <?php if (!empty($asset['code'])): ?>
        <div class="info-row">
            <span class="info-label">کد شناسایی</span>
            <span class="info-value"><?= htmlspecialchars($asset['code']) ?></span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($asset['description'])): ?>
        <div class="info-row">
            <span class="info-label">توضیحات</span>
            <span class="info-value"><?= nl2br(htmlspecialchars($asset['description'])) ?></span>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">دسته‌بندی</span>
            <span class="info-value"><?= htmlspecialchars($asset['category_name'] ?? 'دسته‌بندی نشده') ?></span>
        </div>
        
        <div class="info-row">
            <span class="info-label">وضعیت</span>
            <span class="info-value">
                <span class="status-badge" style="background:<?= $status['color'] ?>;color:<?= $status['text'] ?>;">
                    <?= $status['label'] ?>
                </span>
            </span>
        </div>
        
        <?php if (!empty($asset['purchase_price'])): ?>
        <div class="info-row">
            <span class="info-label">قیمت خرید</span>
            <span class="info-value"><?= number_format($asset['purchase_price']) ?> تومان</span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($asset['purchase_date'])): ?>
        <div class="info-row">
            <span class="info-label">تاریخ خرید</span>
            <span class="info-value"><?= $asset['purchase_date'] ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($asset['status'] == 'assigned' && !empty($asset['assigned_to_name'])): ?>
        <div class="info-row" style="border-top:2px dashed #e1e4e8;margin-top:8px;padding-top:16px;flex-direction:column;gap:8px;">
            <span class="info-label" style="margin-bottom:4px;">اطلاعات واگذاری</span>
            <div style="display:flex;flex-direction:column;gap:4px;width:100%;">
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#57606a;">واگذار به</span>
                    <span style="font-weight:600;"><?= htmlspecialchars($asset['assigned_to_name']) ?></span>
                </div>
                <?php if (!empty($asset['project']) || !empty($asset['unit'])): ?>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#57606a;">پروژه / واحد</span>
                    <span style="font-weight:600;">
                        <?= htmlspecialchars($asset['project'] ?? '') ?>
                        <?php if (!empty($asset['project']) && !empty($asset['unit'])): ?>
                            -
                        <?php endif; ?>
                        <?= htmlspecialchars($asset['unit'] ?? '') ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if (!empty($asset['shamsi_assigned_date'])): ?>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#57606a;">تاریخ واگذاری</span>
                    <span style="font-weight:600;"><?= htmlspecialchars($asset['shamsi_assigned_date']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($asset['shamsi_return_date'])): ?>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#57606a;">تاریخ بازگشت</span>
                    <span style="font-weight:600;"><?= htmlspecialchars($asset['shamsi_return_date']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">تاریخ ثبت</span>
            <span class="info-value"><?= !empty($asset['shamsi_date']) ? $asset['shamsi_date'] : nowShamsi() ?></span>
        </div>
        
        <div class="info-row">
            <span class="info-label">ثبت کننده</span>
            <span class="info-value"><?= htmlspecialchars($asset['creator_name'] ?? 'نامشخص') ?></span>
        </div>
        
        <a href="assets.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:8px;"></i>بازگشت</a>
    </div>
</body>
</html>