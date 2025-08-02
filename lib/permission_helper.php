<?php
/**
 * 권한 관리 헬퍼 함수들
 * 세분화된 사용자 권한 검사 및 관리 기능 제공
 */

/**
 * 사용자의 특정 권한을 확인합니다.
 * @param string $permission 확인할 권한명
 * @param int|null $user_id 사용자 ID (null이면 현재 세션 사용자)
 * @return bool 권한 여부
 */
function has_permission($permission, $user_id = null) {
    // 세션 시작 확인
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!$user_id) {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        $user_id = $_SESSION['user_id'];
    }
    
    try {
        require_once __DIR__ . '/../config/db_config.php';
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // permissions 컬럼 존재 여부 확인
        $column_check = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'permissions'");
        $column_check->execute();
        $has_permissions_column = $column_check->fetch();
        
        if ($has_permissions_column) {
            $stmt = $pdo->prepare("SELECT role, permissions FROM users WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        }
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return false;
        }
        
        // super_admin은 항상 모든 권한 보유
        if ($user['role'] === 'super_admin') {
            return true;
        }
        
        // permissions 컬럼이 있고 JSON 데이터가 있는 경우
        if ($has_permissions_column && !empty($user['permissions'])) {
            $permissions = json_decode($user['permissions'], true);
            if (is_array($permissions) && isset($permissions[$permission])) {
                return (bool)$permissions[$permission];
            }
        }
        
        // 기본 권한 체크 (하위 호환성)
        return check_legacy_permission($permission, $user['role']);
        
    } catch (PDOException $e) {
        error_log("Permission check error: " . $e->getMessage());
        // 오류 발생시 기본 권한으로 폴백
        if (isset($_SESSION['role'])) {
            return check_legacy_permission($permission, $_SESSION['role']);
        }
        return false;
    } catch (Exception $e) {
        error_log("General permission error: " . $e->getMessage());
        // 오류 발생시 기본 권한으로 폴백
        if (isset($_SESSION['role'])) {
            return check_legacy_permission($permission, $_SESSION['role']);
        }
        return false;
    }
}

/**
 * 기존 role 기반 권한 체크 (하위 호환성)
 * @param string $permission 권한명
 * @param string $role 사용자 역할
 * @return bool 권한 여부
 */
function check_legacy_permission($permission, $role) {
    $legacy_permissions = [
        'admin_access' => ['super_admin', 'admin'],
        'user_management' => ['super_admin', 'admin'],
        'store_management' => ['super_admin'],
        'product_management' => ['super_admin', 'admin'],
        'purchase_management' => ['super_admin', 'admin'],
        'brand_management' => ['super_admin', 'admin'],
        'category_management' => ['super_admin', 'admin'],
        'supplier_management' => ['super_admin', 'admin'],
        'settings' => ['super_admin'],
        'shop_access' => ['super_admin', 'admin', 'user', 'staff', 'office_staff'],
        'barcode_management' => ['super_admin', 'admin', 'staff', 'office_staff'],
        'accounting_management' => ['super_admin', 'admin', 'office_staff']
    ];
    
    return isset($legacy_permissions[$permission]) && 
           in_array($role, $legacy_permissions[$permission]);
}

/**
 * 사용자의 모든 권한을 반환합니다.
 * @param int|null $user_id 사용자 ID
 * @return array 권한 배열
 */
function get_user_permissions($user_id = null) {
    if (!$user_id) {
        if (!isset($_SESSION['user_id'])) {
            return [];
        }
        $user_id = $_SESSION['user_id'];
    }
    
    try {
        require_once __DIR__ . '/../config/db_config.php';
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT role, permissions FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return [];
        }
        
        // super_admin은 모든 권한
        if ($user['role'] === 'super_admin') {
            return [
                'admin_access' => true,
                'user_management' => true,
                'store_management' => true,
                'product_management' => true,
                'purchase_management' => true,
                'brand_management' => true,
                'category_management' => true,
                'supplier_management' => true,
                'settings' => true,
                'shop_access' => true,
                'barcode_management' => true,
                'accounting_management' => true
            ];
        }
        
        // permissions 컬럼이 있는 경우
        if ($user['permissions']) {
            $permissions = json_decode($user['permissions'], true);
            if (is_array($permissions)) {
                return $permissions;
            }
        }
        
        // 기본 권한 반환
        return get_default_permissions($user['role']);
        
    } catch (PDOException $e) {
        error_log("Get permissions error: " . $e->getMessage());
        return [];
    }
}

/**
 * 역할별 기본 권한을 반환합니다.
 * @param string $role 사용자 역할
 * @return array 기본 권한 배열
 */
function get_default_permissions($role) {
    $default_permissions = [
        'super_admin' => [
            'admin_access' => true,
            'user_management' => true,
            'store_management' => true,
            'product_management' => true,
            'purchase_management' => true,
            'brand_management' => true,
            'category_management' => true,
            'supplier_management' => true,
            'settings' => true,
            'shop_access' => true,
            'barcode_management' => true,
            'accounting_management' => true
        ],
        'admin' => [
            'admin_access' => true,
            'user_management' => true,
            'store_management' => false,
            'product_management' => true,
            'purchase_management' => true,
            'brand_management' => true,
            'category_management' => true,
            'supplier_management' => true,
            'settings' => false,
            'shop_access' => true,
            'barcode_management' => true,
            'accounting_management' => true
        ],
        'staff' => [
            'admin_access' => false,
            'user_management' => false,
            'store_management' => false,
            'product_management' => false,
            'purchase_management' => false,
            'brand_management' => false,
            'category_management' => false,
            'supplier_management' => false,
            'settings' => false,
            'shop_access' => true,
            'barcode_management' => true,
            'accounting_management' => false
        ],
        'office_staff' => [
            'admin_access' => false,
            'user_management' => false,
            'store_management' => false,
            'product_management' => false,
            'purchase_management' => false,
            'brand_management' => false,
            'category_management' => false,
            'supplier_management' => false,
            'settings' => false,
            'shop_access' => true,
            'barcode_management' => true,
            'accounting_management' => true
        ],
        'user' => [
            'admin_access' => false,
            'user_management' => false,
            'store_management' => false,
            'product_management' => false,
            'purchase_management' => false,
            'brand_management' => false,
            'category_management' => false,
            'supplier_management' => false,
            'settings' => false,
            'shop_access' => true,
            'barcode_management' => false,
            'accounting_management' => false
        ]
    ];
    
    return $default_permissions[$role] ?? $default_permissions['user'];
}

/**
 * 권한 이름을 한국어로 변환합니다.
 * @param string $permission 권한명
 * @return string 한국어 권한명
 */
function get_permission_label($permission) {
    $labels = [
        'admin_access' => '관리자 메뉴 접근',
        'user_management' => '회원 관리',
        'store_management' => '지점 관리',
        'product_management' => '상품 관리',
        'purchase_management' => '매입 관리',
        'brand_management' => '브랜드 관리',
        'category_management' => '카테고리 관리',
        'supplier_management' => '공급처 관리',
        'settings' => '환경 설정',
        'shop_access' => '쇼핑몰 접근',
        'barcode_management' => '바코드 관리',
        'accounting_management' => '회계 관리'
    ];
    
    return $labels[$permission] ?? $permission;
}

/**
 * 사용자 권한을 업데이트합니다.
 * @param int $user_id 사용자 ID
 * @param array $permissions 권한 배열
 * @return bool 성공 여부
 */
function update_user_permissions($user_id, $permissions) {
    try {
        require_once __DIR__ . '/../config/db_config.php';
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $permissions_json = json_encode($permissions);
        $stmt = $pdo->prepare("UPDATE users SET permissions = ? WHERE id = ?");
        return $stmt->execute([$permissions_json, $user_id]);
        
    } catch (PDOException $e) {
        error_log("Update permissions error: " . $e->getMessage());
        return false;
    }
}

/**
 * 관리자 메뉴 접근 권한 확인
 * @return bool 접근 가능 여부
 */
function can_access_admin() {
    return has_permission('admin_access');
}

/**
 * 페이지별 권한 체크 및 접근 제어
 * @param string $required_permission 필요한 권한
 * @param string $redirect_url 권한 없을 때 이동할 URL (기본: index.php)
 */
function require_permission($required_permission, $redirect_url = 'index.php') {
    if (!has_permission($required_permission)) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '해당 기능에 접근할 권한이 없습니다.'
        ];
        header("Location: " . $redirect_url);
        exit;
    }
}
?>