<?php
require_once 'config.php';
require_once 'functions.php';

echo "<h2>تست درج دسته‌بندی و پروژه</h2>";

// ===== 1. بررسی وجود جداول =====
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'expense_categories'")->fetchAll();
    if (empty($tables)) {
        echo "❌ جدول expense_categories وجود ندارد. در حال ایجاد...<br>";
        $pdo->exec("
            CREATE TABLE expense_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(20) DEFAULT '#0969da',
                icon VARCHAR(50) DEFAULT 'fa-tag',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "✅ جدول expense_categories ایجاد شد.<br>";
    } else {
        echo "✅ جدول expense_categories وجود دارد.<br>";
    }

    $tables = $pdo->query("SHOW TABLES LIKE 'projects'")->fetchAll();
    if (empty($tables)) {
        echo "❌ جدول projects وجود ندارد. در حال ایجاد...<br>";
        $pdo->exec("
            CREATE TABLE projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT,
                status TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "✅ جدول projects ایجاد شد.<br>";
    } else {
        echo "✅ جدول projects وجود دارد.<br>";
    }
} catch (PDOException $e) {
    die("خطا در ایجاد جداول: " . $e->getMessage());
}

// ===== 2. تست درج دسته‌بندی =====
try {
    $cat_name = 'تست از فایل ' . date('H:i:s');
    $stmt = $pdo->prepare("INSERT INTO expense_categories (name, color, icon) VALUES (?, ?, ?)");
    $stmt->execute([$cat_name, '#ff0000', 'fa-tag']);
    $newId = $pdo->lastInsertId();
    echo "✅ دسته‌بندی با نام '{$cat_name}' و ID {$newId} درج شد.<br>";
} catch (PDOException $e) {
    echo "❌ خطا در درج دسته‌بندی: " . $e->getMessage() . "<br>";
}

// ===== 3. تست درج پروژه =====
try {
    $pname = 'پروژه تست ' . date('H:i:s');
    $stmt = $pdo->prepare("INSERT INTO projects (name, description, status) VALUES (?, ?, 1)");
    $stmt->execute([$pname, 'توضیحات تست']);
    $newId = $pdo->lastInsertId();
    echo "✅ پروژه با نام '{$pname}' و ID {$newId} درج شد.<br>";
} catch (PDOException $e) {
    echo "❌ خطا در درج پروژه: " . $e->getMessage() . "<br>";
}

// ===== 4. نمایش لیست دسته‌بندی‌ها =====
echo "<h3>لیست دسته‌بندی‌های موجود:</h3>";
$categories = $pdo->query("SELECT * FROM expense_categories")->fetchAll();
if (empty($categories)) {
    echo "هیچ دسته‌بندی وجود ندارد.<br>";
} else {
    echo "<ul>";
    foreach ($categories as $cat) {
        echo "<li>ID: {$cat['id']} - نام: {$cat['name']} - رنگ: {$cat['color']}</li>";
    }
    echo "</ul>";
}

echo "<h3>لیست پروژه‌های موجود:</h3>";
$projects = $pdo->query("SELECT * FROM projects")->fetchAll();
if (empty($projects)) {
    echo "هیچ پروژه‌ای وجود ندارد.<br>";
} else {
    echo "<ul>";
    foreach ($projects as $p) {
        echo "<li>ID: {$p['id']} - نام: {$p['name']}</li>";
    }
    echo "</ul>";
}