<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه فایل مشخص نشده است');
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("
    SELECT d.*, c.name as category_name, u.full_name as creator_name 
    FROM download_center d
    LEFT JOIN download_categories c ON d.category_id = c.id
    LEFT JOIN users u ON d.created_by = u.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die('فایل یافت نشد');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده فایل</title>
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
            border-bottom: 2px solid #0969da;
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
        .info-value a {
            color: #0969da;
            text-decoration: none;
        }
        .info-value a:hover {
            text-decoration: underline;
        }
        .btn-back, .btn-download {
            display: inline-block;
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            margin-top: 20px;
            transition: 0.2s;
        }
        .btn-back {
            background: #f0f2f4;
            color: #57606a;
        }
        .btn-back:hover {
            background: #e1e4e8;
        }
        .btn-download {
            background: #2da44e;
            color: #fff;
        }
        .btn-download:hover {
            background: #22863a;
        }
        .status-badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-badge.inactive {
            background: #fecaca;
            color: #991b1b;
        }
        .file-icon {
            font-size: 48px;
            color: #0969da;
            text-align: center;
            padding: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-file" style="color:#0969da;margin-left:10px;"></i>مشاهده فایل</h2>
        
        <?php if (!empty($item['file_path']) && file_exists($item['file_path'])): ?>
        <div class="file-icon">
            <i class="fas fa-file-<?= strtolower($item['file_type'] ?? 'file') ?>"></i>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">عنوان</span>
            <span class="info-value"><?= htmlspecialchars($item['title']) ?></span>
        </div>
        
        <?php if (!empty($item['description'])): ?>
        <div class="info-row">
            <span class="info-label">توضیحات</span>
            <span class="info-value"><?= nl2br(htmlspecialchars($item['description'])) ?></span>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">دسته‌بندی</span>
            <span class="info-value"><?= htmlspecialchars($item['category_name'] ?? 'دسته‌بندی نشده') ?></span>
        </div>
        
        <?php if (!empty($item['file_path']) && file_exists($item['file_path'])): ?>
        <div class="info-row">
            <span class="info-label">نام فایل</span>
            <span class="info-value"><?= htmlspecialchars(basename($item['file_path'])) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">حجم</span>
            <span class="info-value"><?= htmlspecialchars($item['file_size'] ?? 'نامشخص') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">نوع</span>
            <span class="info-value"><?= htmlspecialchars($item['file_type'] ?? 'نامشخص') ?></span>
        </div>
        <?php elseif (!empty($item['download_url'])): ?>
        <div class="info-row">
            <span class="info-label">لینک دانلود</span>
            <span class="info-value">
                <a href="<?= htmlspecialchars($item['download_url']) ?>" target="_blank">
                    <?= htmlspecialchars($item['download_url']) ?>
                </a>
            </span>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">وضعیت</span>
            <span class="info-value">
                <span class="status-badge <?= $item['status'] == 1 ? 'active' : 'inactive' ?>">
                    <?= $item['status'] == 1 ? 'فعال' : 'غیرفعال' ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">تعداد دانلود</span>
            <span class="info-value"><?= number_format($item['download_count'] ?? 0) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">تاریخ ثبت</span>
            <span class="info-value"><?= !empty($item['shamsi_date']) ? $item['shamsi_date'] : jdate('Y/m/d', strtotime($item['created_at'])) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">ثبت کننده</span>
            <span class="info-value"><?= htmlspecialchars($item['creator_name'] ?? 'نامشخص') ?></span>
        </div>
        
        <div style="display:flex;gap:10px;">
            <a href="downloads.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:8px;"></i>بازگشت</a>
            <?php if (!empty($item['file_path']) && file_exists($item['file_path'])): ?>
                <a href="download_file.php?id=<?= $item['id'] ?>" class="btn-download"><i class="fas fa-download" style="margin-left:8px;"></i>دانلود فایل</a>
            <?php elseif (!empty($item['download_url'])): ?>
                <a href="<?= htmlspecialchars($item['download_url']) ?>" target="_blank" class="btn-download"><i class="fas fa-external-link-alt" style="margin-left:8px;"></i>دانلود</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>