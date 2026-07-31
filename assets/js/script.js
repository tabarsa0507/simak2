// ========== SIDEBAR TOGGLE ==========
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.querySelector('.mobile-toggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        });
    }
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });
    }
});

// ========== CAPTCHA REFRESH ==========
function refreshCaptcha() {
    fetch('includes/captcha_refresh.php')
        .then(response => response.json())
        .then(data => {
            const display = document.querySelector('.captcha-box');
            if (display) display.textContent = data.code;
        })
        .catch(err => console.error('Error refreshing captcha:', err));
}

// ========== TOOLTIP ==========
document.querySelectorAll('[data-tooltip]').forEach(el => {
    el.addEventListener('mouseenter', function(e) {
        const tip = document.createElement('div');
        tip.className = 'tooltip-custom';
        tip.textContent = this.dataset.tooltip;
        document.body.appendChild(tip);
        const rect = this.getBoundingClientRect();
        tip.style.left = rect.left + rect.width / 2 - tip.offsetWidth / 2 + 'px';
        tip.style.top = rect.top - tip.offsetHeight - 10 + 'px';
        tip.style.opacity = '1';
    });
    
    el.addEventListener('mouseleave', function() {
        document.querySelectorAll('.tooltip-custom').forEach(t => t.remove());
    });
});

// ========== CONFIRM DELETE ==========
document.querySelectorAll('.confirm-delete').forEach(el => {
    el.addEventListener('click', function(e) {
        if (!confirm('آیا از حذف این مورد اطمینان دارید؟')) {
            e.preventDefault();
        }
    });
});

// ========== AUTO HIDE ALERTS ==========
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        alert.style.transition = 'all 0.5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    }, 5000);
});

// ========== PERSIAN DATE PICKER ==========
// برای استفاده از تاریخ شمسی در فرم‌ها

// ========== CHARTS INIT ==========
// اگر از Chart.js استفاده می‌کنید