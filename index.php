<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// ===== تولید کپچای ۶ رقمی عددی =====
function generateNumericCaptcha() {
    $code = rand(100000, 999999);
    $_SESSION['captcha'] = $code;
    return $code;
}

// ===== بررسی درخواست رفرش کپچا (AJAX) =====
if (isset($_GET['ajax_captcha'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'captcha' => generateNumericCaptcha()]);
    exit();
}

// ===== بررسی ورود =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $captcha = trim($_POST['captcha'] ?? '');
    
    if (!isset($_SESSION['captcha']) || $captcha != $_SESSION['captcha']) {
        $error = '❌ کد امنیتی اشتباه است.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (national_id = ? OR email = ?) AND status = 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            logActivity($user['id'], 'login', 'ورود به سیستم', 'ورود موفق');
            
            header('Location: dashboard.php');
            exit();
        } else {
            $error = '❌ کد ملی یا رمز عبور اشتباه است.';
        }
    }
}

$captchaCode = generateNumericCaptcha();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به سامانه</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: linear-gradient(135deg, #e8f0fe, #f0f4ff);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            display: flex;
            flex-direction: row;
            max-width: 1000px;
            width: 100%;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            overflow: hidden;
            min-height: 560px;
        }
        .login-left {
            flex: 1;
            background: linear-gradient(135deg, #1a73e8, #0d47a1);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            position: relative;
        }
        .login-left img {
            max-width: 100%;
            max-height: 350px;
            object-fit: contain;
            filter: drop-shadow(0 4px 20px rgba(0,0,0,0.3));
            border-radius: 12px;
        }
        .login-left .overlay-text {
            position: absolute;
            bottom: 30px;
            color: rgba(255,255,255,0.6);
            font-size: 14px;
            font-weight: 300;
            text-align: center;
            width: 100%;
        }
        .login-right {
            flex: 1;
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }
        .login-right .brand {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-right .brand h1 {
            font-size: 26px;
            font-weight: 800;
            color: #1a2332;
            letter-spacing: -0.5px;
        }
        .login-right .brand h1 i {
            color: #1a73e8;
            margin-left: 8px;
        }
        .login-right .brand p {
            color: #5f6b7a;
            font-size: 14px;
            margin-top: 4px;
        }
        .login-right .form-group {
            margin-bottom: 20px;
        }
        .login-right .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
        }
        /* ===== فیلدهای راست‌چین (کد ملی و رمز عبور) ===== */
        .login-right .form-group input[type="text"],
        .login-right .form-group input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Vazirmatn', sans-serif;
            transition: all 0.3s ease;
            background: #f7fafc;
            direction: rtl;
            text-align: right;
        }
        .login-right .form-group input[type="text"]:focus,
        .login-right .form-group input[type="password"]:focus {
            outline: none;
            border-color: #1a73e8;
            box-shadow: 0 0 0 4px rgba(26,115,232,0.15);
            background: #ffffff;
        }
        .login-right .form-group input[type="text"]::placeholder,
        .login-right .form-group input[type="password"]::placeholder {
            text-align: right;
            color: #9aa6b5;
        }
        /* ===== فیلد کپچا (چپ‌چین برای اعداد) ===== */
        .login-right .form-group input.captcha-input {
            direction: ltr;
            text-align: center;
            letter-spacing: 2px;
            font-weight: 600;
        }
        .login-right .form-group input.captcha-input::placeholder {
            text-align: center;
            font-weight: 400;
            letter-spacing: 0;
            font-size: 14px;
        }
        .login-right .captcha-container {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            background: #f7fafc;
            border-radius: 10px;
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
        }
        .login-right .captcha-container .captcha-number {
            font-size: 28px;
            font-weight: 800;
            color: #1a2332;
            letter-spacing: 6px;
            background: #ffffff;
            padding: 6px 20px;
            border-radius: 8px;
            border: 1px dashed #1a73e8;
            font-family: 'Courier New', monospace;
            direction: ltr;
            user-select: none;
            order: 2; /* عدد کپچا به راست می‌رود */
        }
        .login-right .captcha-container .refresh-btn {
            background: none;
            border: none;
            color: #1a73e8;
            font-size: 20px;
            cursor: pointer;
            padding: 6px 10px;
            border-radius: 8px;
            transition: 0.2s;
            order: 1; /* دکمه وسط */
        }
        .login-right .captcha-container .refresh-btn:hover {
            background: #e8f0fe;
            transform: rotate(45deg);
        }
        .login-right .captcha-container input {
            flex: 1;
            min-width: 120px;
            border: none;
            background: transparent;
            padding: 10px 6px;
            font-size: 16px;
            font-family: 'Vazirmatn', sans-serif;
            direction: ltr;
            text-align: center;
            letter-spacing: 2px;
            font-weight: 600;
            order: 0; /* فیلد ورودی به چپ می‌رود */
        }
        .login-right .captcha-container input::placeholder {
            text-align: center;
            font-weight: 400;
            letter-spacing: 0;
            font-size: 14px;
        }
        .login-right .captcha-container input:focus {
            outline: none;
            box-shadow: none;
        }
        .login-right .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1a73e8, #0d47a1);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Vazirmatn', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .login-right .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(26,115,232,0.35);
        }
        .login-right .btn-login:active {
            transform: translateY(0);
        }
        .login-right .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-weight: 500;
            font-size: 14px;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .login-right .alert i {
            font-size: 18px;
        }

        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                max-width: 480px;
                min-height: auto;
            }
            .login-left {
                padding: 20px;
                min-height: 160px;
            }
            .login-left img {
                max-height: 120px;
            }
            .login-left .overlay-text {
                display: none;
            }
            .login-right {
                padding: 32px 24px;
            }
            .login-right .brand h1 {
                font-size: 22px;
            }
            .login-right .captcha-container .captcha-number {
                font-size: 22px;
                padding: 4px 14px;
            }
        }
        @media (max-width: 480px) {
            .login-right {
                padding: 24px 16px;
            }
            .login-right .captcha-container {
                flex-wrap: wrap;
                justify-content: center;
            }
            .login-right .captcha-container input {
                min-width: 100%;
                order: 0;
            }
            .login-right .captcha-container .refresh-btn {
                order: 1;
            }
            .login-right .captcha-container .captcha-number {
                order: 2;
            }
        }
    </style>
</head>
<body>

<div class="login-container">
    <!-- ===== سمت چپ: فرم ===== -->
    <div class="login-right">
        <div class="brand">
            <h1><i class="fas fa-heartbeat"></i> سامانه مدیریت</h1>
            <p>پرونده الکترونیک سلامت</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label><i class="fas fa-id-card"></i> کد ملی</label>
                <input type="text" 
                       name="username" 
                       placeholder="کد ملی خود را وارد کنید" 
                       required 
                       autofocus
                       maxlength="10"
                       inputmode="numeric"
                       pattern="[0-9]*"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10)">
            </div>
            <div class="form-group">
                <label><i class="fas fa-lock"></i> رمز عبور</label>
                <input type="password" name="password" placeholder="رمز عبور خود را وارد کنید" required>
            </div>
            <div class="form-group">
                <label><i class="fas fa-shield-alt"></i> کد امنیتی</label>
                <div class="captcha-container">
                    <input type="text" 
                           name="captcha" 
                           class="captcha-input"
                           placeholder="۶ رقم را وارد کنید" 
                           maxlength="6" 
                           required 
                           inputmode="numeric"
                           pattern="[0-9]*"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)">
                    <button type="button" class="refresh-btn" onclick="refreshCaptcha()" title="تولید مجدد">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <span class="captcha-number" id="captchaDisplay"><?= $captchaCode ?></span>
                </div>
            </div>
            <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt"></i> ورود به سامانه</button>
        </form>
    </div>

    <!-- ===== سمت راست: تصویر ===== -->
    <div class="login-left">
        <img src="assets/images/logo.png" alt="پرونده الکترونیک سلامت">
        <div class="overlay-text">پرونده الکترونیک سلامت</div>
    </div>
</div>

<script>
function refreshCaptcha() {
    fetch('?ajax_captcha=1')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('captchaDisplay').textContent = data.captcha;
            }
        })
        .catch(error => console.error('خطا در دریافت کپچا:', error));
}
</script>

</body>
</html>