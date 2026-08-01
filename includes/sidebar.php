<?php
// ==========================================================
// includes/sidebar.php - سایدبار یکپارچه برای همه ماژول‌ها
// ==========================================================
// این فایل در تمام صفحات (dashboard, secretariat, reminder, ...) 
// با دستور require_once 'includes/sidebar.php'; فراخوانی می‌شود.
// ==========================================================

// متغیرهای مورد نیاز:
// $current_page - نام صفحه فعلی (برای active کردن آیتم منو)
// مثلاً در dashboard.php: $current_page = 'dashboard';
// در secretariat.php: $current_page = 'secretariat';

$current_page = $current_page ?? 'dashboard';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand">
            <i class="fas fa-cubes"></i>
            SIMAK
        </div>
        <div class="sub">سامانه یکپارچه مدیریت انجام کار</div>
    </div>

    <ul class="sidebar-menu">
        <li class="label">منوی اصلی</li>
        <li>
            <a href="dashboard.php" class="<?= $current_page === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-chart-pie"></i> داشبورد
            </a>
        </li>
        <li>
            <a href="secretariat.php" class="<?= $current_page === 'secretariat' ? 'active' : '' ?>">
                <i class="fas fa-inbox"></i> دبیرخانه
            </a>
        </li>
        <li>
            <a href="reminder.php" class="<?= $current_page === 'reminder' ? 'active' : '' ?>">
                <i class="fas fa-bell"></i> یادآور
            </a>
        </li>
        <li>
            <a href="travel_expenses.php" class="<?= $current_page === 'travel' ? 'active' : '' ?>">
                <i class="fas fa-route"></i> هزینه مأموریت
            </a>
        </li>
        <li>
            <a href="assets.php" class="<?= $current_page === 'assets' ? 'active' : '' ?>">
                <i class="fas fa-boxes"></i> مدیریت اموال
            </a>
        </li>

        <li class="divider"></li>
        <li class="label">داده و دانش</li>
        <li>
            <a href="knowledge.php" class="<?= $current_page === 'knowledge' ? 'active' : '' ?>">
                <i class="fas fa-database"></i> پایگاه دانش
            </a>
        </li>
        <li>
            <a href="links.php" class="<?= $current_page === 'links' ? 'active' : '' ?>">
                <i class="fas fa-link"></i> بانک لینک
            </a>
        </li>
        <li>
            <a href="downloads.php" class="<?= $current_page === 'downloads' ? 'active' : '' ?>">
                <i class="fas fa-download"></i> مرکز دانلود
            </a>
        </li>

        <li class="divider"></li>
        <li class="label">مدیریت</li>
        <li>
            <a href="users.php" class="<?= $current_page === 'users' ? 'active' : '' ?>">
                <i class="fas fa-users"></i> مدیریت کاربران
            </a>
        </li>
        <li>
            <a href="settings.php" class="<?= $current_page === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> تنظیمات
            </a>
        </li>
    </ul>

    <div class="sidebar-footer">
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> خروج از سامانه</a>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>