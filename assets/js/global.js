// ============================================================
// global.js - کدهای عمومی کل پروژه
// ============================================================
document.addEventListener('DOMContentLoaded', function() {

    // ===== ۱. تنظیم ویژگی data-jdp برای تمام inputهای تاریخ شمسی =====
    document.querySelectorAll('.persian-date').forEach(function(input) {
        input.setAttribute('data-jdp', '');
    });

    // ===== ۲. فعال‌سازی یکباره کتابخانه JalaliDatepicker =====
    if (typeof jalaliDatepicker !== 'undefined') {
        jalaliDatepicker.startWatch({
            format: 'YYYY/MM/DD',
            persianDigits: true,
            autoClose: true,
            showTodayBtn: true,
            showEmptyBtn: true,
            autoHide: true,
            hideAfterChange: true,
            // مهم: چون مودال‌های شما z-index: 2000 دارند،
            // باید z-index تقویم از آن بیشتر باشد وگرنه
            // تقویم پشت مودال رندر می‌شود و دیده نمی‌شود.
            zIndex: 3000

        });
    }

    // ===== ۳. تبدیل تاریخ‌های میلادی به شمسی (در صورت نیاز) =====
    document.querySelectorAll('.shamsi-date').forEach(function(el) {
        var dateStr = el.getAttribute('data-date') || el.textContent;
        if (dateStr && dateStr.match(/^\d{4}-\d{2}-\d{2}/)) {
            el.textContent = toShamsi(dateStr);
        }
    });
});

// ===== تابع کمکی برای تبدیل تاریخ میلادی به شمسی =====
function toShamsi(dateStr) {
    // پیاده‌سازی موجود را نگه دارید یا از persian-date.js استفاده کنید
    return dateStr;
}