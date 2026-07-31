<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

$user = getCurrentUser();
$page_title = 'مدیریت امضاها';

// ===== دریافت لیست امضاها =====
$signatures = $pdo->query("SELECT * FROM signatures ORDER BY name")->fetchAll();

// ===== پردازش فرم =====
if (isset($_POST['signature_action'])) {
    $action = $_POST['signature_action'];
    $sig_id = $_POST['sig_id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    
    $image_path = '';
    if (isset($_FILES['image_upload']) && $_FILES['image_upload']['error'] == 0) {
        $upload_dir = 'uploads/signatures/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file = $_FILES['image_upload'];
        $file_name = time() . '_' . basename($file['name']);
        $image_path = $upload_dir . $file_name;
        move_uploaded_file($file['tmp_name'], $image_path);
    }
    
    try {
        if ($action === 'add' && !empty($name) && !empty($image_path)) {
            $stmt = $pdo->prepare("INSERT INTO signatures (name, position, image_path, is_default) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $position, $image_path, $is_default]);
            $_SESSION['success'] = 'امضا با موفقیت اضافه شد.';
        } elseif ($action === 'edit' && !empty($name) && $sig_id > 0) {
            if (!empty($image_path)) {
                $old = $pdo->prepare("SELECT image_path FROM signatures WHERE id = ?");
                $old->execute([$sig_id]);
                $old_img = $old->fetch();
                if ($old_img && !empty($old_img['image_path']) && file_exists($old_img['image_path'])) {
                    unlink($old_img['image_path']);
                }
                $stmt = $pdo->prepare("UPDATE signatures SET name = ?, position = ?, image_path = ?, is_default = ? WHERE id = ?");
                $stmt->execute([$name, $position, $image_path, $is_default, $sig_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE signatures SET name = ?, position = ?, is_default = ? WHERE id = ?");
                $stmt->execute([$name, $position, $is_default, $sig_id]);
            }
            $_SESSION['success'] = 'امضا با موفقیت ویرایش شد.';
        } elseif ($action === 'delete' && $sig_id > 0) {
            $old = $pdo->prepare("SELECT image_path FROM signatures WHERE id = ?");
            $old->execute([$sig_id]);
            $old_img = $old->fetch();
            if ($old_img && !empty($old_img['image_path']) && file_exists($old_img['image_path'])) {
                unlink($old_img['image_path']);
            }
            $stmt = $pdo->prepare("DELETE FROM signatures WHERE id = ?");
            $stmt->execute([$sig_id]);
            $_SESSION['success'] = 'امضا با موفقیت حذف شد.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'خطا: ' . $e->getMessage();
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
    <title>مدیریت امضاها</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Vazirmatn', sans-serif; background: #f6f8fa; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
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
        }
        .btn-back:hover { background: #e1e4e8; }
        
        .card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            overflow: hidden;
            margin-bottom: 20px;
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
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #e1e4e8;
            font-size: 14px;
            font-family: 'Vazirmatn', sans-serif;
            outline: none;
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
        .table-mini td { padding: 8px 10px; border-bottom: 1px solid #f0f2f4; vertical-align: middle; }
        .table-mini tr:hover { background: #f8f9fa; }
        .badge-default { background: #d1fae5; color: #065f46; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        
        .file-upload-wrapper {
            border: 2px dashed #e1e4e8;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: #f8f9fa;
        }
        .file-upload-wrapper:hover { border-color: #0969da; background: #f0f4ff; }
        .signature-preview { max-height: 50px; max-width: 150px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-signature"></i> مدیریت امضاها</h1>
            <a href="secretariat.php" class="btn-back"><i class="fas fa-arrow-right" style="margin-left:6px;"></i>بازگشت</a>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> لیست امضاها</div>
            <div class="card-body">
                <div style="overflow-x:auto;">
                    <table class="table-mini">
                        <thead>
                            <tr>
                                <th>نام</th>
                                <th>سمت</th>
                                <th style="width:120px;">امضا</th>
                                <th style="width:80px;">پیش‌فرض</th>
                                <th style="width:100px;">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($signatures)): ?>
                                <tr><td colspan="5" style="text-align:center;color:#8b93a5;padding:20px;">هیچ امضایی ثبت نشده است.</td></tr>
                            <?php else: ?>
                                <?php foreach ($signatures as $sig): ?>
                                <tr>
                                    <td><?= htmlspecialchars($sig['name']) ?></td>
                                    <td><?= htmlspecialchars($sig['position']) ?></td>
                                    <td>
                                        <?php if (!empty($sig['image_path']) && file_exists($sig['image_path'])): ?>
                                            <img src="<?= htmlspecialchars($sig['image_path']) ?>" class="signature-preview">
                                        <?php else: ?>
                                            <span style="color:#8b93a5;font-size:11px;">ندارد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $sig['is_default'] ? '<span class="badge-default">پیش‌فرض</span>' : '-' ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="editSignature(<?= $sig['id'] ?>)">ویرایش</button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteSignature(<?= $sig['id'] ?>)">حذف</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-plus"></i> افزودن امضای جدید</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="signatureForm">
                    <input type="hidden" name="signature_action" id="signature_action" value="add">
                    <input type="hidden" name="sig_id" id="sig_id" value="0">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>نام (صاحب امضا)</label>
                            <input type="text" name="name" id="sig_name" required placeholder="مثلاً: مدیرعامل">
                        </div>
                        <div class="form-group">
                            <label>سمت</label>
                            <input type="text" name="position" id="sig_position" placeholder="مثلاً: مدیرعامل شرکت">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>تصویر امضا</label>
                        <div class="file-upload-wrapper" onclick="document.getElementById('image_upload').click()">
                            <i class="fas fa-image" style="font-size:24px;color:#0969da;display:block;margin-bottom:8px;"></i>
                            <div>برای آپلود تصویر امضا کلیک کنید</div>
                            <div id="image_name_display" style="font-size:12px;color:#2da44e;display:none;margin-top:6px;"></div>
                            <div id="image_preview" style="margin-top:8px;display:none;">
                                <img id="image_preview_img" src="" style="max-height:60px;max-width:200px;">
                            </div>
                        </div>
                        <input type="file" name="image_upload" id="image_upload" accept="image/*" style="display:none;" 
                               onchange="document.getElementById('image_name_display').textContent = '📎 ' + this.files[0].name; 
                                       document.getElementById('image_name_display').style.display = 'block';
                                       var reader = new FileReader();
                                       reader.onload = function(e) {
                                           document.getElementById('image_preview_img').src = e.target.result;
                                           document.getElementById('image_preview').style.display = 'block';
                                       };
                                       reader.readAsDataURL(this.files[0]);">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_default" id="sig_default" value="1">
                            امضای پیش‌فرض
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-success" id="submitBtn">افزودن امضا</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function editSignature(id) {
            fetch('get_signature.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('signature_action').value = 'edit';
                        document.getElementById('sig_id').value = id;
                        document.getElementById('sig_name').value = data.name || '';
                        document.getElementById('sig_position').value = data.position || '';
                        document.getElementById('sig_default').checked = data.is_default == 1;
                        document.getElementById('submitBtn').textContent = 'ویرایش امضا';
                        if (data.image_path) {
                            document.getElementById('image_preview_img').src = data.image_path;
                            document.getElementById('image_preview').style.display = 'block';
                            document.getElementById('image_name_display').textContent = '📎 تصویر موجود';
                            document.getElementById('image_name_display').style.display = 'block';
                        }
                    }
                });
        }
        
        function deleteSignature(id) {
            if (confirm('آیا از حذف این امضا مطمئن هستید؟')) {
                document.getElementById('signature_action').value = 'delete';
                document.getElementById('sig_id').value = id;
                document.getElementById('signatureForm').submit();
            }
        }
    </script>
</body>
</html>