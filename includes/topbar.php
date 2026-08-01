<?php
// ==========================================================
// includes/topbar.php - نوار بالای صفحه با منوی کاربری
// ==========================================================
// این فایل در تمام صفحات با دستور require_once 'includes/topbar.php'; فراخوانی می‌شود.
// متغیرهای مورد نیاز: $user, $userRoles, $currentRole (که در صفحه اصلی تعریف شده‌اند)
// ==========================================================

if (!isset($user) || !isset($userRoles) || !isset($currentRole)) {
    // اگر متغیرها تعریف نشده باشند، سعی می‌کنیم از سشن بگیریم
    $user = $_SESSION['user'] ?? getCurrentUser();
    $userRoles = getUserRoles($user['id'] ?? 0);
    $currentRole = $_SESSION['current_role'] ?? ($userRoles[0] ?? null);
    if (!$currentRole && !empty($userRoles)) {
        $currentRole = $userRoles[0];
        $_SESSION['current_role'] = $currentRole;
    }
}

// تغییر نقش از طریق فرم (برای زمانی که کاربر از dropdown انتخاب می‌کند)
if (isset($_POST['switch_role']) && in_array($_POST['switch_role'], $userRoles)) {
    $_SESSION['current_role'] = $_POST['switch_role'];
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// تاریخ شمسی
$today = nowShamsi1('Y/m/d H:i');
?>
<div class="top-bar">
    <h1>
        <?= $page_title ?? 'داشبورد' ?>
        <span><?= $page_subtitle ?? 'مدیریت سامانه' ?></span>
    </h1>
    <div class="top-right">
        <div class="date">
            <i class="fas fa-calendar-alt"></i> <?= persianNumber($today) ?>
        </div>

        <!-- ===== منوی کاربری با Dropdown ===== -->
        <div class="user-dropdown-wrapper">
            <div class="user-profile" onclick="toggleUserDropdown()">
                <div class="user-avatar">
                    <?php 
                        $avatar = !empty($user['profile_picture']) ? $user['profile_picture'] : '';
                        if ($avatar && file_exists($avatar)): 
                    ?>
                        <img src="<?= htmlspecialchars($avatar) ?>" alt="پروفایل" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <?= mb_substr($user['full_name'] ?? 'کاربر', 0, 1, 'UTF-8') ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? 'کاربر') ?></div>
                    <div class="user-role"><?= htmlspecialchars($currentRole ?? 'بدون نقش') ?></div>
                </div>
                <i class="fas fa-chevron-down" style="font-size:12px;color:#8b949e;margin-right:6px;"></i>
            </div>

            <!-- ===== Dropdown منوی کاربری ===== -->
            <div class="user-dropdown" id="userDropdown">
                <!-- بخش نقش‌ها -->
                <?php if (count($userRoles) > 1): ?>
                    <div class="dropdown-section">
                        <div class="dropdown-label">تغییر نقش</div>
                        <?php foreach ($userRoles as $role): ?>
                            <form method="POST" class="role-form">
                                <input type="hidden" name="switch_role" value="<?= htmlspecialchars($role) ?>">
                                <button type="submit" class="dropdown-item <?= $role === $currentRole ? 'active-role' : '' ?>">
                                    <i class="fas fa-user-tag"></i>
                                    <?= htmlspecialchars($role) ?>
                                    <?php if ($role === $currentRole): ?>
                                        <i class="fas fa-check" style="margin-right:auto;color:#2da44e;"></i>
                                    <?php endif; ?>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                    <div class="dropdown-divider"></div>
                <?php endif; ?>

                <!-- بخش تنظیمات -->
                <div class="dropdown-section">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user-cog"></i>
                        تنظیمات کاربری
                        <span style="margin-right:auto;font-size:11px;color:#8b949e;">پروفایل</span>
                    </a>
                    <a href="logout.php" class="dropdown-item" style="color:#cf222e;">
                        <i class="fas fa-sign-out-alt"></i>
                        خروج از سامانه
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // ===== باز و بسته شدن Dropdown =====
    function toggleUserDropdown() {
        var dropdown = document.getElementById('userDropdown');
        var isOpen = dropdown.classList.contains('show');
        // بستن همه dropdownهای باز
        document.querySelectorAll('.user-dropdown.show').forEach(function(el) {
            el.classList.remove('show');
        });
        if (!isOpen) {
            dropdown.classList.add('show');
        }
    }

    // ===== بستن dropdown با کلیک خارج از آن =====
    document.addEventListener('click', function(event) {
        var wrapper = document.querySelector('.user-dropdown-wrapper');
        if (wrapper && !wrapper.contains(event.target)) {
            document.getElementById('userDropdown').classList.remove('show');
        }
    });
</script>

<style>
    /* ===== استایل منوی کاربری با Dropdown ===== */
    .user-dropdown-wrapper {
        position: relative;
        display: inline-block;
    }

    .user-profile {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #fff;
        padding: 4px 14px 4px 16px;
        border-radius: 8px;
        border: 1px solid #e1e4e8;
        cursor: pointer;
        transition: all 0.2s ease;
        user-select: none;
    }
    .user-profile:hover {
        border-color: #0969da;
        box-shadow: 0 0 0 3px rgba(9,105,218,0.08);
    }
    .user-avatar {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #0969da;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        flex-shrink: 0;
        overflow: hidden;
    }
    .user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .user-name {
        font-size: 13px;
        font-weight: 600;
        color: #24292f;
        line-height: 1.3;
    }
    .user-role {
        font-size: 11px;
        color: #57606a;
        line-height: 1.2;
    }

    /* ===== Dropdown ===== */
    .user-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        min-width: 220px;
        background: #ffffff;
        border: 1px solid #e1e4e8;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        padding: 8px 0;
        z-index: 1000;
        overflow: hidden;
    }
    .user-dropdown.show {
        display: block;
        animation: dropdownFade 0.2s ease;
    }

    @keyframes dropdownFade {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .dropdown-section {
        padding: 4px 0;
    }
    .dropdown-label {
        font-size: 10px;
        font-weight: 600;
        color: #8b949e;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        padding: 6px 16px 4px;
        margin-bottom: 2px;
    }
    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 16px;
        width: 100%;
        border: none;
        background: transparent;
        font-family: 'Vazirmatn', sans-serif;
        font-size: 13px;
        color: #24292f;
        cursor: pointer;
        transition: all 0.15s ease;
        text-decoration: none;
        text-align: right;
    }
    .dropdown-item:hover {
        background: #f0f2f4;
    }
    .dropdown-item i {
        width: 18px;
        font-size: 14px;
        color: #57606a;
    }
    .dropdown-item.active-role {
        background: #f0f4ff;
        color: #0969da;
    }
    .dropdown-item.active-role i {
        color: #0969da;
    }

    .dropdown-divider {
        height: 1px;
        background: #e1e4e8;
        margin: 4px 12px;
    }

    /* ===== واکنش‌گرایی ===== */
    @media (max-width: 768px) {
        .user-profile {
            padding: 4px 10px;
        }
        .user-name {
            font-size: 12px;
        }
        .user-role {
            font-size: 10px;
        }
        .user-dropdown {
            min-width: 200px;
            right: 0;
            left: auto;
        }
    }
</style>