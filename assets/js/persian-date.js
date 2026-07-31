// ============================================================
// persian-date.js - توابع کمکی تاریخ شمسی برای کل پروژه
// ============================================================
// ===== ۱. تبدیل تاریخ میلادی به شمسی =====
function toShamsi(dateString) {
    if (!dateString) return '';
    var parts = dateString.split('-');
    if (parts.length !== 3) return dateString;

    var g_y = parseInt(parts[0]);
    var g_m = parseInt(parts[1]);
    var g_d = parseInt(parts[2]);

    var g_days = [31,28,31,30,31,30,31,31,30,31,30,31];
    var j_days = [31,31,31,31,31,31,30,30,30,30,30,29];

    var gy = g_y - 1600;
    var gm = g_m - 1;
    var gd = g_d - 1;
    var g_day_no = 365 * gy + Math.floor((gy + 3) / 4) - Math.floor((gy + 99) / 100) + Math.floor((gy + 399) / 400);

    for (var i = 0; i < gm; i++) g_day_no += g_days[i];
    if (gm > 1 && ((g_y % 4 === 0 && g_y % 100 !== 0) || g_y % 400 === 0)) g_day_no++;
    g_day_no += gd;

    var j_day_no = g_day_no - 226899;
    var j_y = 1 + Math.floor((j_day_no * 33 + 3) / 12053);
    j_day_no -= Math.floor((j_y - 1) * 12053 / 33);

    for (var i = 0; i < 12; i++) {
        if (j_day_no < j_days[i]) {
            var j_m = i + 1;
            var j_d = j_day_no + 1;
            break;
        }
        j_day_no -= j_days[i];
    }

    return j_y + '/' + String(j_m).padStart(2, '0') + '/' + String(j_d).padStart(2, '0');
}
// ===== ۲. فعال‌سازی تقویم شمسی روی یک input =====
function initPersianDatepicker(inputId, options = {}) {
    var input = document.getElementById(inputId);
    if (!input) return null;

    // تنظیمات پیش‌فرض
    var defaultOptions = {
        format: 'YYYY/MM/DD',
        autoClose: true,
        persianDigits: true,
        showTodayBtn: true,
        showEmptyBtn: true,
        autoHide: true,
        hideAfterChange: true,
        zIndex: 3000 // باید از z-index مودال‌های پروژه (2000) بیشتر باشد
    timePicker: false
    };

    var finalOptions = Object.assign({}, defaultOptions, options);

    try {
        // اگر کتابخانه JalaliDatePicker موجود باشد
        if (typeof jalaliDatepicker !== 'undefined') {
            // اضافه کردن data-jdp به input
            input.setAttribute('data-jdp', '');
            jalaliDatepicker.startWatch(finalOptions);
            return input;
        }
        // اگر کتابخانه persian-datepicker موجود باشد
        else if (typeof $.fn.persianDatepicker !== 'undefined') {
            $(input).persianDatepicker(finalOptions);
            return input;
        }
    } catch(e) {
        console.warn('خطا در فعال‌سازی تقویم شمسی:', e);
    }

    return null;
}
// ===== ۳. فرمت کردن تاریخ شمسی برای نمایش =====
function formatShamsiDate(dateString) {
    if (!dateString) return '-';
    return toShamsi(dateString);
}