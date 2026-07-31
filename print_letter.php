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
           h.logo_path as header_logo,
           h.address as header_address,
           h.phone as header_phone,
           h.fax as header_fax,
           h.email as header_email,
           h.website as header_website,
           s.name as signatory_name,
           s.image_path as signature_image,
           s.position as signatory_position
    FROM letters l
    LEFT JOIN users u ON l.created_by = u.id
    LEFT JOIN letter_headers h ON l.header_id = h.id
    LEFT JOIN signatures s ON l.signature_id = s.id
    WHERE l.id = ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$letter = $stmt->fetch();

if (!$letter) {
    die('نامه یافت نشد');
}

$header_image = 'uploads/letter_headers/a4_header.jpg';
$stamp_image = 'uploads/signatures/stamp.png';

$has_attachment = $letter['has_attachment'] ?? 0;
$attachment_text = $has_attachment ? 'دارد' : 'ندارد';

$content = $letter['content'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>چاپ نامه</title>
    <style>
        @font-face {
            font-family: 'BNazanin';
            src: url('fonts/B-NAZANIN.TTF') format('truetype');
        }
        
        @page { 
            size: A4; 
            margin: 0; 
        }
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        body { 
            margin: 0; 
            padding: 0; 
            font-family: 'BNazanin', 'B Nazanin', 'Tahoma', sans-serif; 
            background: #fff; 
        }
        
        .page { 
            width: 210mm; 
            height: 297mm; 
            position: relative; 
            background: #fff; 
            overflow: hidden; 
            margin: 0 auto;
        }
        
        /* ===== سربرگ ===== */
        .page .header-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            line-height: 0;
            font-size: 0;
        }
        
        .page .header-bg img {
            width: 100%;
            height: 100%;
            object-fit: fill;
            display: block;
        }
        
        .page .overlay { 
            position: absolute; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            z-index: 1; 
            padding: 0; 
        }
        
        /* ===== اطلاعات بالای نامه ===== */
        .page .overlay .top-info {
            position: absolute;
            top: 18mm;
            right: 25mm;
            left: 14mm;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 3px;
            font-size: 14px;
            font-weight: bold;
            color: #1a1a2e;
            padding-bottom: 5px;
            direction: ltr;
        }
        
        .page .overlay .top-info .info-item {
            display: flex;
            align-items: center;
            gap: 5px;
            direction: ltr;
            font-weight: bold;
        }
        
        .page .overlay .top-info .info-item .value {
            font-weight: bold;
            color: #333;
        }
        
        .page .overlay .top-info .date-item {
            margin-top: 0px;
        }
        
        .page .overlay .top-info .number-item {
            margin-top: 8px;
        }
        
        .page .overlay .top-info .attachment-item {
            margin-top: 8px;
        }
        
          /* ===== کانتینر اصلی متن (قابل تنظیم دستی) ===== */
        .page .overlay .main-content {
            position: absolute;
            /* ===== تنظیمات دستی موقعیت متن ===== */
            top: 40mm;      /* فاصله از بالا - افزایش = پایین‌تر */
            left: 25mm;     /* فاصله از چپ - افزایش = راست‌تر */
            right: 33mm;    /* فاصله از راست - افزایش = چپ‌تر */
            bottom: 10mm;   /* فاصله از پایین - افزایش = بالاتر */
            /* =================== */
            display: flex;
            flex-direction: column;
            padding-bottom: 15mm;
        }
        
        /* ===== متن نامه ===== */
        .page .overlay .main-content .content {
            line-height: 2.2;
            font-size: 14px;
            text-align: justify;
            padding: 10px 0 5px 0;
            flex-shrink: 0;
            width: 100%;
            direction: rtl;
        }
        
        /* ===== فاصله 2 سانتی ===== */
        .page .overlay .main-content .spacer {
            flex-shrink: 0;
            height: 20mm;
        }
        
        /* ===== بخش پایین ===== */
        .page .overlay .main-content .bottom-section {
            flex-shrink: 0;
            width: 100%;
            padding: 5px 0;
            position: relative;
            margin-top: -20mm;
            display: flex;
            justify-content: flex-start;
        }
        
        .page .overlay .main-content .bottom-section .signature-group {
            position: relative;
            display: inline-block;
            direction: rtl;
            text-align: right;
        }
        
        /* ===== متن ===== */
        .page .overlay .main-content .bottom-section .signature-group .text-box {
            padding: 5px 20px 5px 15px;
            background: transparent;
            text-align: right;
            line-height: 2.2;
            border: none;
            box-shadow: none;
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .text-box .prayer {
            font-family: 'B Titr', 'BNazanin', 'B Nazanin', 'Tahoma', sans-serif;
            font-size: 14px;
            font-weight: bold;
            color: #1a1a2e;
            text-align: center;
            margin-bottom: 2px;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .text-box .company {
            font-family: 'B Titr', 'BNazanin', 'B Nazanin', 'Tahoma', sans-serif;
            font-size: 12px;
            font-weight: bold;
            color: #1a1a2e;
            text-align: center;
        }
        
        /* ===== امضا ===== */
        .page .overlay .main-content .bottom-section .signature-group .signature-box {
            position: absolute;
            top: 50%;
            left: -35px;
            transform: translateY(-50%);
            text-align: center;
            z-index: 2;
            width: 40mm;
            height: 40mm;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .signature-box .signature-img {
            width: 35mm;
            height: 35mm;
            object-fit: contain;
            display: block;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .signature-box .line {
            width: 80px;
            border-bottom: 1px solid #000;
            margin: 2px auto;
        }
        
        /* ===== مهر ===== */
        .page .overlay .main-content .bottom-section .signature-group .stamp-box {
            position: absolute;
            right: -50px;
            top: 50%;
            transform: translateY(-50%);
            width: 40mm;
            height: 40mm;
            z-index: 2;
        }
        
        .page .overlay .main-content .bottom-section .signature-group .stamp-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }
        
        /* ===== پاورقی - حذف شده ===== */
        /* دیگر پاورقی وجود ندارد */
        
        .no-print {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 999;
            display: flex;
            gap: 10px;
            background: rgba(255,255,255,0.95);
            padding: 12px 24px;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
        }
        
        .no-print button { 
            padding: 10px 30px; 
            border: none; 
            border-radius: 6px; 
            font-size: 14px; 
            cursor: pointer; 
            font-family: 'BNazanin', 'B Nazanin', 'Tahoma', sans-serif; 
            font-weight: bold;
        }
        
        .no-print .btn-print { background: #0969da; color: #fff; }
        .no-print .btn-close { background: #f0f2f4; color: #57606a; }
        
        @media print {
            body { 
                background: #fff;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .no-print { display: none !important; }
            
            .page { 
                box-shadow: none;
                margin: 0 !important;
                padding: 0 !important;
                width: 210mm !important;
                height: 297mm !important;
            }
            
            .page .header-bg {
                width: 100% !important;
                height: 100% !important;
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
            }
            
            .page .header-bg img {
                width: 100% !important;
                height: 100% !important;
                object-fit: fill !important;
                display: block !important;
            }
        }
    </style>
</head>
<body>
    <div class="page" id="printArea">
        
        <!-- ===== سربرگ ===== -->
        <div class="header-bg">
            <img src="<?= $header_image ?>" alt="سربرگ">
        </div>
        
        <div class="overlay">
            
            <!-- ===== اطلاعات بالای نامه ===== -->
            <div class="top-info">
                <div class="info-item date-item">
                    <span class="value"><?= htmlspecialchars(explode(' ', $letter['shamsi_letter_date'] ?? '')[0]) ?></span>
                </div>
                
                <div class="info-item number-item">
                    <span class="value"><?= htmlspecialchars($letter['letter_number'] ?? '') ?></span>
                </div>
                
                <div class="info-item attachment-item">
                    <span class="value"><?= $attachment_text ?></span>
                </div>
            </div>

            <div class="main-content">
                
                <!-- ===== متن نامه ===== -->
                <div class="content">
                    <?= $content ?>
                </div>

                <!-- ===== فاصله 2 سانتی متری ===== -->
                <div class="spacer"></div>

                <!-- ===== بخش پایین ===== -->
                <div class="bottom-section">
                    <div class="signature-group">
                        <!-- متن -->
                        <div class="text-box">
                            <div class="prayer">و من ا... توفیق</div>
                            <div class="company">شرکت دانش بنیان پیشگامان دنیای فناوری</div>
                        </div>
                        
                        <!-- امضا -->
                        <div class="signature-box">
                            <?php if (!empty($letter['signature_image']) && file_exists($letter['signature_image'])): ?>
                                <img src="<?= $letter['signature_image'] ?>" class="signature-img" alt="امضاء">
                            <?php else: ?>
                                <div class="line"></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- مهر -->
                        <div class="stamp-box">
                            <img src="<?= $stamp_image ?>" alt="مهر شرکت">
                        </div>
                    </div>
                </div>
                
            </div>

            <!-- ===== پاورقی حذف شد ===== -->

        </div>
    </div>

    <div class="no-print">
        <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> چاپ</button>
        <button class="btn-close" onclick="window.close()"><i class="fas fa-times"></i> بستن</button>
    </div>
    
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>