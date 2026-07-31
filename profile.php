<?php
require_once 'config.php';
require_once 'functions.php';
requireLogin();

$user = getCurrentUser();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name']);
    $password = $_POST['password'] ?? '';
    
    if (empty($full_name)) {
        $message = 'نام کامل را وارد کنید.';
        $messageType = 'danger';
    } else {
        try {
            if (!empty($password)) {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$full_name, hashPassword($password), $_SESSION['user_id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?");
                $stmt->execute([$full_name, $_SESSION['user_id']]);
            }
            $message = 'پروفایل با موفقیت به‌روزرسانی شد.';
            $messageType = 'success';
            logActivity($_SESSION['user_id'], 'profile', 'update', 'پروفایل ویرایش شد');
            
            // به‌روزرسانی سشن
            $_SESSION['full_name'] = $full_name;
        } catch (PDOException $e) {
            $message = 'خطا: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پروفایل کاربر</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; font-family: 'Tahoma', 'IRANSans', sans-serif; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%); padding: 20px; color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 15px; border-radius: 8px; margin-bottom: 5px; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .sidebar .nav-link i { margin-left: 10px; width: 20px; }
        .main-content { padding: 30px; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 2px solid #f0f2f5; padding: 15px 20px; font-weight: bold; border-radius: 15px 15px 0 0 !important; }
        .avatar-circle { width: 40px; height: 40px; background: #3498db; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 18px; }
        .profile-avatar { width: 120px; height: 120px; background: #3498db; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 48px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block sidebar p-0">
                <div class="p-3 text-center border-bottom border-secondary">
                    <div class="avatar-circle mx-auto mb-2" style="width:60px;height:60px;font-size:24px;">
                        <?php echo mb_substr($user['full_name'] ?? 'کاربر', 0, 1); ?>
                    </div>
                    <h6 class="text-white mb-0"><?php echo htmlspecialchars($user['full_name'] ?? 'کاربر'); ?></h6>
                    <small class="text-secondary">مدیر سیستم</small>
                </div>
                <ul class="nav flex-column p-3">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-chart-pie"></i> داشبورد</a></li>
                    <li class="nav-item"><a class="nav-link" href="users.php"><i class="fas fa-users"></i> مدیریت کاربران</a></li>
                    <li class="nav-item"><a class="nav-link active" href="profile.php"><i class="fas fa-user"></i> پروفایل</a></li>
                    <li class="nav-item mt-3"><a class="nav-link text-danger" href="logout.php"><i class="fas fa-sign-out-alt"></i> خروج</a></li>
                </ul>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <h4 class="mb-4"><i class="fas fa-user me-2"></i> پروفایل کاربر</h4>
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="card text-center p-4">
                            <div class="profile-avatar">
                                <?php echo mb_substr($user['full_name'] ?? 'کاربر', 0, 1); ?>
                            </div>
                            <h5 class="mt-3"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                            <p class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></p>
                            <div class="mt-2">
                                <span class="badge <?php echo $user['status'] == 1 ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo $user['status'] == 1 ? 'فعال' : 'غیرفعال'; ?>
                                </span>
                            </div>
                            <p class="mt-3 text-muted small">
                                <i class="fas fa-calendar me-1"></i>
                                عضو از: <?php echo date('Y/m/d', strtotime($user['created_at'])); ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-edit me-2"></i> ویرایش پروفایل
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label">نام کاربری</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                                        <small class="text-muted">نام کاربری قابل تغییر نیست.</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">نام کامل</label>
                                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">رمز عبور جدید (اختیاری)</label>
                                        <input type="password" name="password" class="form-control" placeholder="برای تغییر رمز، جدید را وارد کنید">
                                        <small class="text-muted">اگر نمی‌خواهید رمز را تغییر دهید، خالی بگذارید.</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> ذخیره تغییرات
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>