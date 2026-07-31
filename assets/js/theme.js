// تغییر تم
const themeToggle = document.getElementById('themeToggle');

function setTheme(theme) {
    document.documentElement.className = theme;
    localStorage.setItem('theme', theme);
}

function toggleTheme() {
    const current = document.documentElement.className;
    setTheme(current === 'light' ? 'dark' : 'light');
}

// بارگذاری تم ذخیره شده
document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('theme') || 'light';
    setTheme(saved);
    
    if (themeToggle) {
        themeToggle.addEventListener('click', toggleTheme);
    }
});