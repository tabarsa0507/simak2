<?php
require_once 'config.php';
require_once 'functions.php';

class UserService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * دریافت لیست کاربران با فیلتر و صفحه‌بندی
     */
    public function getUsers($filters = [], $page = 1, $limit = 10) {
        $offset = ($page - 1) * $limit;
        $where = [];
        $params = [];
        
        if (!empty($filters['search'])) {
            $where[] = "(u.full_name LIKE :search OR u.national_id LIKE :search OR u.mobile LIKE :search OR u.email LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['role_id'])) {
            $where[] = "ur.role_id = :role_id";
            $params[':role_id'] = $filters['role_id'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = "u.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "DATE(u.created_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "DATE(u.created_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        // فقط کاربرانی که حذف نشده‌اند
        $where[] = "u.deleted_at IS NULL";
        
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // کوئری اصلی با نقش
        $sql = "SELECT u.*, r.role_name, r.id as role_id
                FROM users u
                LEFT JOIN user_roles ur ON u.id = ur.user_id
                LEFT JOIN roles r ON ur.role_id = r.id
                $whereClause
                ORDER BY u.id DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        // دریافت تعداد کل برای صفحه‌بندی
        $countSql = "SELECT COUNT(*) FROM users u $whereClause";
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = $countStmt->fetchColumn();
        
        return [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit)
        ];
    }
    
    /**
     * دریافت یک کاربر با ID
     */
    public function getUserById($id) {
        $stmt = $this->pdo->prepare("SELECT u.*, r.role_name, r.id as role_id 
                                     FROM users u
                                     LEFT JOIN user_roles ur ON u.id = ur.user_id
                                     LEFT JOIN roles r ON ur.role_id = r.id
                                     WHERE u.id = ? AND u.deleted_at IS NULL");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * دریافت کاربر با کد ملی
     */
    public function getUserByNationalId($nationalId) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE national_id = ? AND deleted_at IS NULL");
        $stmt->execute([$nationalId]);
        return $stmt->fetch();
    }
    
    /**
     * افزودن کاربر جدید
     */
    public function addUser($data) {
        // اعتبارسنجی
        if (empty($data['national_id']) || !validateNationalId($data['national_id'])) {
            throw new Exception('کد ملی نامعتبر است.');
        }
        if (empty($data['password'])) {
            throw new Exception('رمز عبور الزامی است.');
        }
        if (empty($data['full_name'])) {
            throw new Exception('نام کامل الزامی است.');
        }
        if (empty($data['mobile']) || !preg_match('/^09[0-9]{9}$/', $data['mobile'])) {
            throw new Exception('شماره همراه نامعتبر است (مثال: 09123456789).');
        }
        if (empty($data['birth_year']) || !is_numeric($data['birth_year']) || strlen($data['birth_year']) != 4) {
            throw new Exception('سال تولد معتبر وارد کنید (مثال: 1370).');
        }
        if (empty($data['birth_city'])) {
            throw new Exception('شهر تولد الزامی است.');
        }
        if (empty($data['role_id'])) {
            throw new Exception('نقش کاربر الزامی است.');
        }
        
        // بررسی تکراری نبودن کد ملی و موبایل
        $check = $this->pdo->prepare("SELECT id FROM users WHERE national_id = ? OR mobile = ? AND deleted_at IS NULL");
        $check->execute([$data['national_id'], $data['mobile']]);
        if ($check->fetch()) {
            throw new Exception('کد ملی یا شماره همراه قبلاً ثبت شده است.');
        }
        
        $this->pdo->beginTransaction();
        try {
            // درج کاربر
            $stmt = $this->pdo->prepare("INSERT INTO users 
                (national_id, password_hash, full_name, email, mobile, birth_year, birth_city, 
                 profile_picture, status, last_password_change, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([
                $data['national_id'],
                hashPassword($data['password']),
                $data['full_name'],
                $data['email'] ?? null,
                $data['mobile'],
                $data['birth_year'],
                $data['birth_city'],
                $data['profile_picture'] ?? null,
                $data['status'] ?? 1
            ]);
            $userId = $this->pdo->lastInsertId();
            
            // اختصاص نقش
            $stmtRole = $this->pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            $stmtRole->execute([$userId, $data['role_id']]);
            
            // لاگ
            logActivity($_SESSION['user_id'], 'users', 'add', "کاربر جدید با کد ملی {$data['national_id']} اضافه شد");
            
            $this->pdo->commit();
            return $userId;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * ویرایش کاربر
     */
    public function editUser($id, $data) {
        $user = $this->getUserById($id);
        if (!$user) {
            throw new Exception('کاربر یافت نشد.');
        }
        
        // اعتبارسنجی
        if (empty($data['national_id']) || !validateNationalId($data['national_id'])) {
            throw new Exception('کد ملی نامعتبر است.');
        }
        if (empty($data['full_name'])) {
            throw new Exception('نام کامل الزامی است.');
        }
        if (empty($data['mobile']) || !preg_match('/^09[0-9]{9}$/', $data['mobile'])) {
            throw new Exception('شماره همراه نامعتبر است.');
        }
        if (empty($data['birth_year']) || !is_numeric($data['birth_year']) || strlen($data['birth_year']) != 4) {
            throw new Exception('سال تولد معتبر وارد کنید.');
        }
        if (empty($data['birth_city'])) {
            throw new Exception('شهر تولد الزامی است.');
        }
        if (empty($data['role_id'])) {
            throw new Exception('نقش کاربر الزامی است.');
        }
        
        // بررسی تکراری نبودن (به جز خود کاربر)
        $check = $this->pdo->prepare("SELECT id FROM users WHERE (national_id = ? OR mobile = ?) AND id != ? AND deleted_at IS NULL");
        $check->execute([$data['national_id'], $data['mobile'], $id]);
        if ($check->fetch()) {
            throw new Exception('کد ملی یا شماره همراه قبلاً ثبت شده است.');
        }
        
        $this->pdo->beginTransaction();
        try {
            $updateFields = "national_id = ?, full_name = ?, email = ?, mobile = ?, 
                             birth_year = ?, birth_city = ?, status = ?";
            $params = [
                $data['national_id'],
                $data['full_name'],
                $data['email'] ?? null,
                $data['mobile'],
                $data['birth_year'],
                $data['birth_city'],
                $data['status'] ?? 1
            ];
            
            // اگر رمز عبور جدید داده شده
            if (!empty($data['new_password'])) {
                $updateFields .= ", password_hash = ?, last_password_change = NOW()";
                $params[] = hashPassword($data['new_password']);
            }
            
            // اگر عکس آپلود شده
            if (!empty($data['profile_picture'])) {
                $updateFields .= ", profile_picture = ?";
                $params[] = $data['profile_picture'];
            }
            
            $params[] = $id;
            $sql = "UPDATE users SET $updateFields WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            // به‌روزرسانی نقش
            $delRole = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $delRole->execute([$id]);
            $insRole = $this->pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            $insRole->execute([$id, $data['role_id']]);
            
            // لاگ
            logActivity($_SESSION['user_id'], 'users', 'edit', "کاربر با کد ملی {$data['national_id']} ویرایش شد");
            
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * حذف نرم کاربر
     */
    public function deleteUser($id, $currentUserId) {
        if ($id == $currentUserId) {
            throw new Exception('نمی‌توانید خودتان را حذف کنید!');
        }
        
        $stmt = $this->pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        logActivity($currentUserId, 'users', 'delete', "کاربر با ID {$id} حذف شد");
        return true;
    }
    
    /**
     * تغییر وضعیت کاربر
     */
    public function changeStatus($id, $status) {
        $stmt = $this->pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        logActivity($_SESSION['user_id'], 'users', 'change_status', "وضعیت کاربر ID {$id} به {$status} تغییر کرد");
        return true;
    }
    
    /**
     * تغییر نقش کاربر
     */
    public function changeRole($id, $roleId) {
        $del = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $del->execute([$id]);
        $ins = $this->pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
        $ins->execute([$id, $roleId]);
        logActivity($_SESSION['user_id'], 'users', 'change_role', "نقش کاربر ID {$id} تغییر کرد");
        return true;
    }
    
    /**
     * دریافت آمار کاربران
     */
    public function getStats() {
        $stats = [];
        $stats['total'] = $this->pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
        $stats['active'] = $this->pdo->query("SELECT COUNT(*) FROM users WHERE status = 1 AND deleted_at IS NULL")->fetchColumn();
        $stats['inactive'] = $this->pdo->query("SELECT COUNT(*) FROM users WHERE status = 0 AND deleted_at IS NULL")->fetchColumn();
        $stats['admins'] = $this->pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE r.role_name = 'admin'")->fetchColumn();
        $stats['managers'] = $this->pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE r.role_name = 'manager'")->fetchColumn();
        $stats['employees'] = $this->pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE r.role_name = 'employee'")->fetchColumn();
        $stats['today_logins'] = $this->pdo->query("SELECT COUNT(*) FROM users WHERE DATE(last_login) = CURDATE() AND deleted_at IS NULL")->fetchColumn();
        $stats['locked'] = $this->pdo->query("SELECT COUNT(*) FROM users WHERE lockout_until > NOW() AND deleted_at IS NULL")->fetchColumn();
        return $stats;
    }
}
?>