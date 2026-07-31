<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/jdf.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'تنظیمات دبیرخانه';

// ===== دریافت تنظیمات شماره‌گذاری =====
$currentYear = jdate('Y');
$numbering = $pdo->prepare("SELECT * FROM letter_numbering WHERE year = ?");
$numbering->execute([$currentYear]);
$numbering = $numbering->fetch();

if (!$numbering) {
    $pdo->prepare("INSERT INTO letter_numbering (year, start_number, current_number) VALUES (?, 1, 0)")->execute([$currentYear]);
    $numbering = $pdo->prepare("SELECT * FROM letter_numbering WHERE year = ?");
    $numbering->execute([$currentYear]);
    $numbering = $numbering->fetch();
}

// ===== دریافت سربرگ‌ها =====
$headers = $pdo->query("SELECT * FROM letter_headers ORDER BY is_default DESC, title")->fetchAll();

// ===== پردازش سربرگ =====
if (isset($_POST['header_action'])) {
    $action = $_POST['header_action'];
    $header_id = $_POST['header_id'] ?? 0;
    $title = trim($_POST['title'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $fax = trim($_POST['fax'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    
    // ===== پردازش آپلود لوگو =====
    $logo_path = '';
    if (isset($_FILES['logo_upload']) && $_FILES['logo_upload']['error'] == 0) {
        $upload_dir = 'uploads/letter_logos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file = $_FILES['logo_upload'];
        $file_name = time() . '_' . basename($file['name']);
        $logo_path = $upload_dir . $file_name;
        
        if (!move_uploaded_file($file['tmp_name'], $logo_path)) {
            $_SESSION['error'] = 'خطا در آپلود لوگو';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    try {
        if ($action === 'add' && !empty($title)) {
            $stmt = $pdo->prepare("INSERT INTO letter_headers (title, address, phone, fax, email, website, is_default, logo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $address, $phone, $fax, $email, $website, $is_default, $logo_path]);
            $_SESSION['success'] = 'سربرگ با موفقیت اضافه شد.';
        } elseif ($action === 'edit' && !empty($title) && $header_id > 0) {
            if (!empty($logo_path)) {
                $old = $pdo->prepare("SELECT logo_path FROM letter_headers WHERE id = ?");
                $old->execute([$header_id]);
                $old_logo = $old->fetch();
                if ($old_logo && !empty($old_logo['logo_path']) && file_exists($old_logo['logo_path'])) {
                    unlink($old_logo['logo_path']);
                }
                $stmt = $pdo->prepare("UPDATE letter_headers SET title = ?, address = ?, phone = ?, fax = ?, email = ?, website = ?, is_default = ?, logo_path = ? WHERE id = ?");
                $stmt->execute([$title, $address, $phone, $fax, $email, $website, $is_default, $logo_path, $header_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE letter_headers SET title = ?, address = ?, phone = ?, fax = ?, email = ?, website = ?, is_default = ? WHERE id = ?");
                $stmt->execute([$title, $address, $phone, $fax, $email, $website, $is_default, $header_id]);
            }
            $_SESSION['success'] = 'سربرگ با موفقیت ویرایش شد.';
        } elseif ($action === 'delete' && $header_id > 0) {
            $old = $pdo->prepare("SELECT logo_path FROM letter_headers WHERE id = ?");
            $old->execute([$header_id]);
            $old_logo = $old->fetch();
            if ($old_logo && !empty($old_logo['logo_path']) && file_exists($old_logo['logo_path'])) {
                unlink($old_logo['logo_path']);
            }
            $stmt = $pdo->prepare("DELETE FROM letter_headers WHERE id = ?");
            $stmt->execute([$header_id]);
            $_SESSION['success'] = 'سربرگ با موفقیت حذف شد.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در انجام عملیات: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ===== پردازش شماره‌گذاری =====
if (isset($_POST['numbering_action'])) {
    $year = (int)$_POST['year'];
    $start_number = (int)$_POST['start_number'];
    $prefix = trim($_POST['prefix'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    
    try {
        $stmt = $pdo->prepare("UPDATE letter_numbering SET start_number = ?, prefix = ?, suffix = ? WHERE year = ?");
        $stmt->execute([$start_number, $prefix, $suffix, $year]);
        $_SESSION['success'] = 'تنظیمات شماره‌گذاری با موفقیت ذخیره شد.';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا در ذخیره تنظیمات: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تنظیمات دبیرخانه</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Vazirmatn', sans-serif; background: #f6f8fa; color: #24292f; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .page-header {
            background: #fff;
            padding: 20px 24px;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-header h1 { font-size: 20px; font-weight: 700; }
        .page-header h1 i { color: #0969da; margin-left: 10px; }
        .btn-back {
            padding: 8px 20px;
            background: #f0f2f4;
            color: #57606a;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-back:hover { background: #e1e4e8; }
        
        .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            overflow: hidden;
        }
        .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e1e4e8;
            background: #f8f9fa;
            font-weight: 600;
            font-size: 15px;
        }
        .card-header i { margin-left: 8px; color: #0969da; }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #57606a; margin-bottom: 4px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #e1e4e8;
            font-size: 14px;
            font-family: 'Vazirmatn', sans-serif;
            outline: none;
            transition: 0.2s;
            background: #f8f9fa;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: #0969da;
            box-shadow: 0 0 0 3px rgba(9,105,218,0.1);
        }
        .form-row { display: flex; gap: 12px; flex-wrap: wrap; }
        .form-row .form-group { flex: 1; min-width: 120px; }
        .btn {
            padding: 8px 24px;
            border-radius: 6px;
            border: none;
            font-size: 14px;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-primary { background: #0969da; color: #fff; }
        .btn-primary:hover { background: #0550b3; }
        .btn-success { background: #2da44e; color: #fff; }
        .btn-success:hover { background: #22863a; }
        .btn-danger { background: #cf222e; color: #fff; }
        .btn-danger:hover { background: #a0111f; }
        .btn-secondary { background: #f0f2f4; color: #57606a; }
        .btn-secondary:hover { background: #e1e4e8; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        
        .table-mini { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-mini th {
            text-align: right;
            padding: 8px 10px;
            background: #f8f9fa;
            border-bottom: 1px solid #e1e4e8;
            font-weight: 600;
            color: #57606a;
        }
        .table-mini td { padding: 8px 10px; border-bottom: 1px solid #f0f2f4; }
        .table-mini tr:hover { background: #f8f9fa; }
        .badge-default { background: #d1fae5; color: #065f46; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        
        .info-box {
            background: #eff6ff;
            padding: 12px 16px;
            border-radius: 8px;
            border-right: 4px solid #0969da;
            margin-bottom: 16px;
            font-size: 13px;
            color: #1e40af;
        }
        .logo-preview {
            max-width: 150px;
            max-height: 60px;
            margin-bottom: 8px;
            border: 1px solid #e1e4e8;
            border-radius: 4px;
            padding: 4px;
        }
        .logo-preview img { max-width: 100%; max-height: 60px; }
        .file-upload-wrapper {
            border: 2px dashed #e1e4e8;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: #f8f9fa;
        }
        .file-upload-wrapper:hover {
            border-color: #0969da;
            background: #f0f4ff;
        }
        
        @media (max-width: 768px) {
            .settings-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-cog"></i> تنظیمات دبیرخانه</h1>
            <a href="secretariat.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:6px;"></i>بازگشت</a>
        </div>
        
        <div class="settings-grid">
            <!-- ===== شماره‌گذاری ===== -->
            <div class="card">
                <div class="card-header"><i class="fas fa-sort-numeric-up"></i> شماره‌گذاری نامه‌ها</div>
                <div class="card-body">
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        شماره نامه‌ها به صورت خودکار و بر اساس سال تولید می‌شود.
                        <br>
                        <strong>سال جاری:</strong> <?= $currentYear ?>
                        | <strong>شماره فعلی:</strong> <?= $numbering['current_number'] ?>
                        | <strong>شماره شروع:</strong> <?= $numbering['start_number'] ?>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="numbering_action" value="1">
                        <input type="hidden" name="year" value="<?= $currentYear ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>شماره شروع</label>
                                <input type="number" name="start_number" value="<?= $numbering['start_number'] ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label>پیشوند</label>
                                <input type="text" name="prefix" value="<?= htmlspecialchars($numbering['prefix'] ?? '') ?>" placeholder="مثلاً: A-">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>پسوند</label>
                            <input type="text" name="suffix" value="<?= htmlspecialchars($numbering['suffix'] ?? '') ?>" placeholder="مثلاً: -P">
                        </div>
                        <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
                    </form>
                </div>
            </div>
            
            <!-- ===== سربرگ‌ها ===== -->
            <div class="card">
                <div class="card-header"><i class="fas fa-print"></i> سربرگ‌ها</div>
                <div class="card-body">
                    <div style="max-height:250px;overflow-y:auto;margin-bottom:12px;">
                        <table class="table-mini">
                            <thead>
                                <tr>
                                    <th>عنوان</th>
                                    <th style="width:80px;">لوگو</th>
                                    <th style="width:80px;">پیش‌فرض</th>
                                    <th style="width:100px;">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($headers as $h): ?>
                                <tr>
                                    <td><?= htmlspecialchars($h['title']) ?></td>
                                    <td>
                                        <?php if (!empty($h['logo_path']) && file_exists($h['logo_path'])): ?>
                                            <img src="<?= htmlspecialchars($h['logo_path']) ?>" style="max-height:30px;max-width:60px;">
                                        <?php else: ?>
                                            <span style="color:#8b949e;font-size:11px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $h['is_default'] ? '<span class="badge-default">پیش‌فرض</span>' : '-' ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="editHeader(<?= $h['id'] ?>)">ویرایش</button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteHeader(<?= $h['id'] ?>)">حذف</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" id="headerForm">
                        <input type="hidden" name="header_action" id="header_action" value="add">
                        <input type="hidden" name="header_id" id="header_id" value="0">
                        
                        <div class="form-group">
                            <label>عنوان سربرگ</label>
                            <input type="text" name="title" id="header_title" required>
                        </div>
                        
                        <div class="form-group">
                            <label>آپلود لوگو</label>
                            <div class="file-upload-wrapper" onclick="document.getElementById('logo_upload').click()">
                                <i class="fas fa-image" style="font-size:24px;color:#0969da;display:block;margin-bottom:8px;"></i>
                                <div>برای آپلود لوگو کلیک کنید</div>
                                <div id="logo_name_display" style="font-size:12px;color:#2da44e;display:none;margin-top:6px;"></div>
                                <div id="logo_preview" style="margin-top:8px;display:none;">
                                    <img id="logo_preview_img" src="" style="max-height:60px;max-width:150px;">
                                </div>
                            </div>
                            <input type="file" name="logo_upload" id="logo_upload" accept="image/*" style="display:none;" 
                                   onchange="document.getElementById('logo_name_display').textContent = '📎 ' + this.files[0].name; 
                                           document.getElementById('logo_name_display').style.display = 'block';
                                           var reader = new FileReader();
                                           reader.onload = function(e) {
                                               document.getElementById('logo_preview_img').src = e.target.result;
                                               document.getElementById('logo_preview').style.display = 'block';
                                           };
                                           reader.readAsDataURL(this.files[0]);">
                        </div>
                        
                        <div class="form-group">
                            <label>آدرس</label>
                            <input type="text" name="address" id="header_address">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>تلفن</label>
                                <input type="text" name="phone" id="header_phone">
                            </div>
                            <div class="form-group">
                                <label>فکس</label>
                                <input type="text" name="fax" id="header_fax">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>ایمیل</label>
                                <input type="email" name="email" id="header_email">
                            </div>
                            <div class="form-group">
                                <label>وبسایت</label>
                                <input type="text" name="website" id="header_website">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_default" id="header_default" value="1">
                                سربرگ پیش‌فرض
                            </label>
                        </div>
                        <button type="submit" class="btn btn-success" id="headerSubmitBtn">افزودن سربرگ</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // ===== سربرگ =====
        function editHeader(id) {
            fetch('get_header.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('header_action').value = 'edit';
                        document.getElementById('header_id').value = id;
                        document.getElementById('header_title').value = data.title || '';
                        document.getElementById('header_address').value = data.address || '';
                        document.getElementById('header_phone').value = data.phone || '';
                        document.getElementById('header_fax').value = data.fax || '';
                        document.getElementById('header_email').value = data.email || '';
                        document.getElementById('header_website').value = data.website || '';
                        document.getElementById('header_default').checked = data.is_default == 1;
                        document.getElementById('headerSubmitBtn').textContent = 'ویرایش سربرگ';
                        
                        if (data.logo_path) {
                            document.getElementById('logo_preview_img').src = data.logo_path;
                            document.getElementById('logo_preview').style.display = 'block';
                            document.getElementById('logo_name_display').textContent = '📎 لوگو موجود';
                            document.getElementById('logo_name_display').style.display = 'block';
                        }
                    }
                });
        }
        
        function deleteHeader(id) {
            if (confirm('آیا از حذف این سربرگ مطمئن هستید؟')) {
                document.getElementById('header_action').value = 'delete';
                document.getElementById('header_id').value = id;
                document.getElementById('headerForm').submit();
            }
        }
    </script>
</body>
</html>