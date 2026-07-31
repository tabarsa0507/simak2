<!DOCTYPE html>
<html lang="fa" dir="rtl" class="<?php echo $_COOKIE['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'سامانه مدیریت دانش'; ?></title>
    
    <!-- ====== Font Awesome (پشتیبانی کامل) ====== -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- ====== Lucide Icons (مدرن و سبک) ====== -->
    <link href="https://cdn.jsdelivr.net/npm/lucide-static@0.344.0/font/lucide.min.css" rel="stylesheet">
    
    <!-- ====== Bootstrap 5 RTL ====== -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    
    <!-- ====== فونت وزیرمتن (برای زیبایی) ====== -->
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    
    <!-- ====== استایل‌های سفارشی ====== -->
    <link href="/company_kb/assets/css/style.css" rel="stylesheet">
    <link href="/company_kb/assets/css/themes.css" rel="stylesheet">
    
    <style>
        /* ====== تنظیمات پایه ====== */
        * {
            font-family: 'Vazirmatn', 'IRANSans', 'Tahoma', sans-serif;
        }
        
        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: background 0.3s ease, color 0.3s ease;
        }
        
        /* ====== دکمه تغییر تم ====== */
        .theme-toggle {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            color: var(--text-primary);
            box-shadow: var(--shadow);
            font-size: 18px;
        }
        
        .theme-toggle:hover {
            transform: rotate(30deg);
            box-shadow: var(--shadow-md);
        }
        
        /* ====== Top Bar استایل ====== */
        .top-bar {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 15px 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid var(--border-color);
        }
        
        .top-bar .page-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .top-bar .page-title h4 {
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }
        
        .top-bar .page-title .icon-box {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .top-bar .left-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .top-bar .date-time {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .top-bar .date-time i {
            margin-left: 8px;
            color: var(--primary);
        }
        
        /* ====== اسکرول بار سفارشی ====== */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-primary);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
        
        /* ====== انیمیشن fade-in ====== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-up {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        .animate-delay-1 { animation-delay: 0.1s; }
        .animate-delay-2 { animation-delay: 0.2s; }
        .animate-delay-3 { animation-delay: 0.3s; }
        .animate-delay-4 { animation-delay: 0.4s; }
        
        /* ====== واکنش‌گرا برای موبایل ====== */
        @media (max-width: 768px) {
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .top-bar .left-section {
                width: 100%;
                justify-content: space-between;
            }
            
            .top-bar .date-time {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    
    <!-- ====== لودینگ (در صورت نیاز) ====== -->
    <div id="loader" style="display:none;">
        <div class="loader-bar">
            <div class="progress"></div>
        </div>
        <div class="loader-text">در حال بارگذاری...</div>
    </div>
    
    <!-- ====== اسکریپت تغییر تم ====== -->
    <script>
        // ====== سیستم تم دارک/لایت ======
        (function() {
            // دریافت تم ذخیره شده
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.className = savedTheme;
            
            // تابع تغییر تم
            window.toggleTheme = function() {
                const html = document.documentElement;
                const current = html.className;
                const next = current === 'light' ? 'dark' : 'light';
                html.className = next;
                localStorage.setItem('theme', next);
                
                // آپدیت آیکون دکمه
                const icon = document.querySelector('.theme-toggle i');
                if (icon) {
                    icon.className = next === 'light' ? 'fas fa-moon' : 'fas fa-sun';
                }
            };
            
            // تنظیم آیکون دکمه در لود
            document.addEventListener('DOMContentLoaded', function() {
                const icon = document.querySelector('.theme-toggle i');
                if (icon) {
                    const current = document.documentElement.className;
                    icon.className = current === 'light' ? 'fas fa-moon' : 'fas fa-sun';
                }
            });
        })();
    </script>