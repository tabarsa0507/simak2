<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('شناسه لینک مشخص نشده است');
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT l.*, c.name as category_name, u.full_name as creator_name 
                       FROM link_bank l
                       LEFT JOIN link_categories c ON l.category_id = c.id
                       LEFT JOIN users u ON l.created_by = u.id
                       WHERE l.id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();

if (!$link) {
    die('لینک یافت نشد');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده لینک</title>
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
        .btn-back {
            display: inline-block;
            padding: 10px 24px;
            background: #0969da;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            margin-top: 20px;
            transition: 0.2s;
        }
        .btn-back:hover {
            background: #0550b3;
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
        .copy-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #8b949e;
            padding: 0 5px;
            font-size: 14px;
            transition: 0.2s;
        }
        .copy-btn:hover {
            color: #0969da;
        }
        .credential-box {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            direction: ltr;
        }
        .credential-box i {
            color: #8250df;
        }
        .toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #1a1a2e;
            color: #fff;
            padding: 12px 24px;
            border-radius: 8px;
            font-family: 'Vazirmatn', sans-serif;
            font-size: 14px;
            z-index: 9999;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            transition: opacity 0.3s;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-link" style="color:#8250df;margin-left:10px;"></i>مشاهده لینک</h2>
        
        <div class="info-row">
            <span class="info-label">عنوان</span>
            <span class="info-value"><?= htmlspecialchars($link['title']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">آدرس</span>
            <span class="info-value">
                <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank">
                    <?= htmlspecialchars($link['url']) ?>
                </a>
            </span>
        </div>
        <?php if (!empty($link['description'])): ?>
        <div class="info-row">
            <span class="info-label">توضیحات</span>
            <span class="info-value"><?= nl2br(htmlspecialchars($link['description'])) ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="info-label">دسته‌بندی</span>
            <span class="info-value"><?= htmlspecialchars($link['category_name'] ?? 'دسته‌بندی نشده') ?></span>
        </div>
        
        <?php if (!empty($link['username']) || !empty($link['password'])): ?>
        <div class="info-row" style="flex-direction:column;gap:8px;padding:15px 0;">
            <span class="info-label" style="margin-bottom:5px;">اطلاعات ورود</span>
            <div style="display:flex;flex-direction:column;gap:8px;width:100%;">
                <?php if (!empty($link['username'])): ?>
                <div class="credential-box">
                    <i class="fas fa-user"></i>
                    <span><?= htmlspecialchars($link['username']) ?></span>
                    <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($link['username']) ?>')" title="کپی نام کاربری">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($link['password'])): ?>
                <div class="credential-box">
                    <i class="fas fa-lock" style="color:#cf222e;"></i>
                    <span id="view_pass_display">••••••••</span>
                    <button class="copy-btn" onclick="toggleViewPass('<?= htmlspecialchars($link['password']) ?>')" title="نمایش/مخفی رمز">
                        <i class="fas fa-eye" id="view_eye_icon"></i>
                    </button>
                    <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($link['password']) ?>')" title="کپی رمز عبور">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">وضعیت</span>
            <span class="info-value">
                <span class="status-badge <?= $link['status'] == 1 ? 'active' : 'inactive' ?>">
                    <?= $link['status'] == 1 ? 'فعال' : 'غیرفعال' ?>
                </span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">تاریخ ثبت</span>
            <span class="info-value"><?= !empty($link['shamsi_date']) ? $link['shamsi_date'] : jdate('Y/m/d', strtotime($link['created_at'])) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">ثبت کننده</span>
            <span class="info-value"><?= htmlspecialchars($link['creator_name'] ?? 'نامشخص') ?></span>
        </div>
        
        <a href="links.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:8px;"></i>بازگشت</a>
    </div>

    <script>
        function copyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    showToast('✅ متن با موفقیت کپی شد!');
                }).catch(function() {
                    fallbackCopyText(text);
                });
            } else {
                fallbackCopyText(text);
            }
        }

        function fallbackCopyText(text) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                showToast('✅ متن با موفقیت کپی شد!');
            } catch (err) {
                showToast('❌ خطا در کپی متن');
            }
            document.body.removeChild(textarea);
        }

        function showToast(message) {
            var toast = document.createElement('div');
            toast.className = 'toast';
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(function() {
                toast.style.opacity = '0';
                setTimeout(function() {
                    document.body.removeChild(toast);
                }, 300);
            }, 2000);
        }

        function toggleViewPass(password) {
            var span = document.getElementById('view_pass_display');
            var eye = document.getElementById('view_eye_icon');
            if (span.textContent === '••••••••') {
                span.textContent = password;
                eye.className = 'fas fa-eye-slash';
            } else {
                span.textContent = '••••••••';
                eye.className = 'fas fa-eye';
            }
        }
    </script>
</body>
</html>