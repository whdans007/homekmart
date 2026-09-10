<?php
/**
 * 권한 관리 헬퍼 함수들
 * 세분화된 사용자 권한 검사 및 관리 기능 제공
 */

/**
 * 권한 조회 전용 PDO 커넥션을 요청 단위로 1개만 생성해 재사용합니다.
 *
 * 이전에는 권한 관련 함수가 호출될 때마다 new PDO 로 새 커넥션을 열었습니다.
 * admin 헤더 한 번 렌더링에 has_permission() 이 33회 호출되고, 각 호출이
 * get_role_permissions() 를 통해 커넥션을 하나 더 열어 페이지 1회 요청에
 * 40~70개의 커넥션이 생성/해제되었습니다. 그 결과 호스팅의 연결 제한에
 * 걸려 요청 후반부의 get_db_connection() 이 "Operation not permitted" 로
 * 실패했습니다. (admin 대시보드 통계 조회 실패의 직접 원인)
 *
 * @return PDO
 * @throws PDOException 연결 실패 시 (호출측 폴백 로직이 처리)
 */
function permission_pdo() {
    static $pdo = null;
    static $connect_error = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // 같은 요청 안에서 이미 연결에 실패했다면 재시도하지 않습니다.
    // (DB 장애 시 한 요청이 수십 번 연결을 시도해 제한을 더 압박하는 것을 방지)
    if ($connect_error !== null) {
        throw $connect_error;
    }

    require_once __DIR__ . '/../config/db_config.php';
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        $connect_error = $e;
        throw $e;
    }
}

/**
 * 요청 단위 권한 캐시 저장소입니다. (참조 반환)
 * @return array
 */
function &permission_cache_store() {
    static $store = [
        'user'    => [],   // user_id => 사용자 행(role, permissions)
        'role'    => [],   // role_key => 권한 배열
        'label'   => [],   // role_key => 라벨
        'has_col' => null, // users.permissions 컬럼 존재 여부
    ];
    return $store;
}

/**
 * 권한 캐시를 비웁니다. 권한/역할을 변경한 직후에 호출합니다.
 * @return void
 */
function permission_cache_reset() {
    $store = &permission_cache_store();
    $store = ['user' => [], 'role' => [], 'label' => [], 'has_col' => null];
}

/**
 * users 테이블에 permissions 컬럼이 있는지 확인합니다. (요청당 1회만 조회)
 * @return bool
 */
function permission_users_has_permissions_column() {
    $store = &permission_cache_store();
    if ($store['has_col'] !== null) {
        return $store['has_col'];
    }

    $check = permission_pdo()->prepare("SHOW COLUMNS FROM users LIKE 'permissions'");
    $check->execute();

    return $store['has_col'] = (bool)$check->fetch();
}

/**
 * 권한 판정에 쓰이는 사용자 행을 조회합니다. (요청당 사용자별 1회만 조회)
 * @param int $user_id 사용자 ID
 * @return array|null role, permissions 키를 가진 배열 (없으면 null)
 */
function permission_user_row($user_id) {
    $store = &permission_cache_store();
    if (array_key_exists($user_id, $store['user'])) {
        return $store['user'][$user_id];
    }

    if (permission_users_has_permissions_column()) {
        $stmt = permission_pdo()->prepare("SELECT role, permissions FROM users WHERE id = ?");
    } else {
        $stmt = permission_pdo()->prepare("SELECT role FROM users WHERE id = ?");
    }
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return $store['user'][$user_id] = null;
    }

    // permissions 컬럼이 없는 스키마에서도 키가 항상 존재하도록 보정
    $user['permissions'] = $user['permissions'] ?? null;

    return $store['user'][$user_id] = $user;
}

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
        $user = permission_user_row($user_id);

        if (!$user) {
            return false;
        }

        // super_admin은 항상 모든 권한 보유
        if ($user['role'] === 'super_admin') {
            return true;
        }

        // permissions 컬럼이 있고 JSON 데이터가 있는 경우
        if (!empty($user['permissions'])) {
            $permissions = json_decode($user['permissions'], true);
            if (is_array($permissions) && isset($permissions[$permission])) {
                return (bool)$permissions[$permission];
            }
        }
        
        // 역할별 권한 매트릭스 체크 (Design Ref: role-permission-management §역할별 권한)
        return get_role_permission_value($permission, $user['role']);

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
        'admin_access' => ['super_admin', 'admin', 'manager'],
        'user_management' => ['super_admin', 'admin'],
        'store_management' => ['super_admin'],
        'product_management' => ['super_admin', 'admin'],
        'purchase_management' => ['super_admin', 'admin', 'manager'],
        'brand_management' => ['super_admin', 'admin'],
        'category_management' => ['super_admin', 'admin'],
        'supplier_management' => ['super_admin', 'admin'],
        'wholesale_management' => ['super_admin', 'admin'],
        'store_transfer_management' => ['super_admin', 'admin'],
        'customer_management' => ['super_admin', 'admin'],
        'delivery_management' => ['super_admin', 'admin'],
        'settings' => ['super_admin'],
        'shop_access' => ['super_admin', 'admin', 'user', 'staff', 'office_staff', 'manager'],
        'barcode_management' => ['super_admin', 'admin', 'staff', 'office_staff'],
        'accounting_management' => ['super_admin', 'admin', 'office_staff'],
        // 물류센터 전용 권한
        'logistics_purchase_management' => ['super_admin', 'admin'],
        'logistics_outbound_management'  => ['super_admin', 'admin'],
        'logistics_inventory_management' => ['super_admin', 'admin'],
        'mall_management' => ['super_admin', 'admin'],
        'foodpang_management' => ['super_admin', 'admin'],
        'foodpang_wholesale_management' => ['super_admin', 'admin'],
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
        $user = permission_user_row($user_id);

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
                'wholesale_management' => true,
                'store_transfer_management' => true,
                'customer_management' => true,
                'delivery_management' => true,
                'settings' => true,
                'shop_access' => true,
                'barcode_management' => true,
                'accounting_management' => true,
                'logistics_purchase_management' => true,
                'logistics_outbound_management' => true,
                'logistics_inventory_management' => true,
                'mall_management' => true,
                'foodpang_management' => true,
                'foodpang_wholesale_management' => true,
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
 * 시스템에서 관리하는 전체 권한 키 목록을 반환합니다.
 * @return array 권한 키 목록
 */
function get_all_permission_keys() {
    return [
        'admin_access', 'user_management', 'store_management', 'product_management',
        'purchase_management', 'brand_management', 'category_management', 'supplier_management',
        'wholesale_management', 'store_transfer_management', 'customer_management', 'delivery_management',
        'settings', 'shop_access', 'barcode_management', 'accounting_management',
        'logistics_purchase_management', 'logistics_outbound_management', 'logistics_inventory_management',
        'mall_management', 'foodpang_management', 'foodpang_wholesale_management',
    ];
}

/**
 * 역할별 권한 매트릭스를 role_permissions 테이블에서 조회합니다.
 * DB 조회 실패 또는 데이터 없음 시 기존 하드코딩 기본값으로 폴백합니다.
 * Design Ref: role-permission-management §데이터 모델 - role_permissions
 * @param string $role 역할 키
 * @return array 권한 배열 (permission_key => bool)
 */
function get_role_permissions($role) {
    $store = &permission_cache_store();
    if (array_key_exists($role, $store['role'])) {
        return $store['role'][$role];
    }

    try {
        $stmt = permission_pdo()->prepare("SELECT permission_key, enabled FROM role_permissions WHERE role_key = ?");
        $stmt->execute([$role]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return $store['role'][$role] = get_legacy_default_permissions($role);
        }

        $permissions = [];
        foreach ($rows as $row) {
            $permissions[$row['permission_key']] = (bool)$row['enabled'];
        }
        return $store['role'][$role] = $permissions;

    } catch (Exception $e) {
        error_log("Get role permissions error: " . $e->getMessage());
        // 조회 실패는 캐시하지 않습니다. (일시적 장애 후 정상 값 복구를 위해)
        return get_legacy_default_permissions($role);
    }
}

/**
 * 특정 역할이 특정 권한을 가지고 있는지 확인합니다.
 * @param string $permission 권한명
 * @param string $role 역할 키
 * @return bool 권한 여부
 */
function get_role_permission_value($permission, $role) {
    $permissions = get_role_permissions($role);
    if (isset($permissions[$permission])) {
        return (bool)$permissions[$permission];
    }
    return check_legacy_permission($permission, $role);
}

/**
 * 역할별 기본 권한을 반환합니다. (role_permissions 테이블 기반)
 * @param string $role 사용자 역할
 * @return array 권한 배열
 */
function get_default_permissions($role) {
    return get_role_permissions($role);
}

/**
 * 역할별 기본 권한 하드코딩 값 (DB 조회 실패 시 폴백 / 마이그레이션 이전 호환용)
 * @param string $role 사용자 역할
 * @return array 기본 권한 배열
 */
function get_legacy_default_permissions($role) {
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
            'wholesale_management' => true,
            'delivery_management' => true,
            'settings' => true,
            'shop_access' => true,
            'barcode_management' => true,
            'accounting_management' => true,
            'logistics_purchase_management' => true,
            'logistics_outbound_management' => true,
            'logistics_inventory_management' => true,
            'mall_management' => true,
            'foodpang_management' => true,
            'foodpang_wholesale_management' => true,
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
            'wholesale_management' => true,
            'delivery_management' => true,
            'settings' => false,
            'shop_access' => true,
            'barcode_management' => true,
            'accounting_management' => true,
            'logistics_purchase_management' => true,
            'logistics_outbound_management' => true,
            'logistics_inventory_management' => true,
            'mall_management' => true,
            'foodpang_management' => true,
            'foodpang_wholesale_management' => true,
        ],
        'manager' => [
            'admin_access' => true,
            'user_management' => false,
            'store_management' => false,
            'product_management' => false,
            'purchase_management' => true,
            'brand_management' => false,
            'category_management' => false,
            'supplier_management' => false,
            'wholesale_management' => false,
            'settings' => false,
            'shop_access' => true,
            'barcode_management' => false,
            'accounting_management' => false,
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
            'wholesale_management' => false,
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
            'wholesale_management' => false,
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
            'wholesale_management' => false,
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
        'wholesale_management' => '도매판매 관리',
        'customer_management' => '고객 관리',
        'delivery_management' => '배달 관리',
        'settings' => '환경 설정',
        'shop_access' => '쇼핑몰 접근',
        'barcode_management' => '바코드 관리',
        'accounting_management' => '회계 관리',
        'logistics_purchase_management' => '물류 매입 관리',
        'logistics_outbound_management' => '물류 출고 관리',
        'logistics_inventory_management' => '물류 재고 현황',
        'mall_management' => '쇼핑몰 관리',
        'foodpang_management' => 'Foodpang 관리',
        'foodpang_wholesale_management' => 'Foodpang 도매 매핑 관리',
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
        $pdo = permission_pdo();
        
        $permissions_json = json_encode($permissions);
        $stmt = $pdo->prepare("UPDATE users SET permissions = ? WHERE id = ?");
        $result = $stmt->execute([$permissions_json, $user_id]);

        permission_cache_reset(); // 변경 직후 같은 요청에서 옛 권한이 쓰이지 않도록
        return $result;

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
    // 세션 시작 확인
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!has_permission($required_permission)) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '해당 기능에 접근할 권한이 없습니다.'
        ];
        
        // 상대 경로를 절대 경로로 변환
        if (!preg_match('#^https?://#', $redirect_url) && strpos($redirect_url, '/') !== 0) {
            // 현재 디렉토리를 기준으로 한 상대 경로 처리
            $current_dir = dirname($_SERVER['PHP_SELF']);
            if ($current_dir !== '/') {
                $redirect_url = $current_dir . '/' . ltrim($redirect_url, '/');
            }
        }
        
        header("Location: " . $redirect_url);
        exit;
    }
}

/**
 * 현재 로그인 사용자가 물류센터 지점 소속인지 확인합니다.
 * users.store_id → stores.name = '물류센터' 이면 true
 * @return bool
 */
function is_logistics_department() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    // super_admin/admin 은 물류센터 제한 없이 모든 메뉴 이용 가능
    // (메뉴 제한은 물류 부서원만 적용)
    // 여기선 순수하게 '지점이 물류센터인가' 만 반환
    if (isset($_SESSION['is_logistics'])) {
        return (bool)$_SESSION['is_logistics'];
    }

    try {
        $pdo = permission_pdo();

        $stmt = $pdo->prepare(
            "SELECT s.name AS store_name
             FROM users u
             LEFT JOIN stores s ON u.store_id = s.id
             WHERE u.id = ?"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $is_logistics = (isset($row['store_name']) && $row['store_name'] === 'CENTER (물류센터)');
        $_SESSION['is_logistics'] = $is_logistics; // 세션 캐시

        return $is_logistics;

    } catch (Exception $e) {
        error_log("is_logistics_department error: " . $e->getMessage());
        return false;
    }
}

/**
 * 현재 사용자가 부여할 수 있는 역할 목록을 반환합니다.
 * super_admin은 전체 역할, 그 외는 자신보다 level이 낮은 역할만 반환합니다.
 * Design Ref: role-permission-management §5.4 - 회원 추가/수정 역할 드롭다운
 * @param string $current_role_key 현재 로그인한 사용자의 역할 키
 * @return array roles 목록 (level 오름차순)
 */
function get_assignable_roles($current_role_key) {
    $roles = get_all_roles();

    if ($current_role_key === 'super_admin') {
        return $roles;
    }

    $current_level = 0;
    foreach ($roles as $r) {
        if ($r['role_key'] === $current_role_key) {
            $current_level = (int)$r['level'];
            break;
        }
    }

    return array_values(array_filter($roles, function ($r) use ($current_level) {
        return (int)$r['level'] < $current_level;
    }));
}

/**
 * 전체 역할(등급) 목록을 level 순으로 반환합니다.
 * Design Ref: role-permission-management §역할 관리 화면
 * @return array roles 테이블 전체 행 목록
 */
function get_all_roles() {
    try {
        $pdo = permission_pdo();

        $stmt = $pdo->query("SELECT role_key, label, level, is_system FROM roles ORDER BY level ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Get all roles error: " . $e->getMessage());
        return [];
    }
}

/**
 * 역할 키에 해당하는 한글 라벨을 반환합니다.
 * @param string $role_key 역할 키
 * @return string 역할 라벨 (찾지 못하면 role_key 그대로 반환)
 */
function get_role_label($role_key) {
    $store = &permission_cache_store();
    if (array_key_exists($role_key, $store['label'])) {
        return $store['label'][$role_key];
    }

    try {
        $pdo = permission_pdo();

        $stmt = $pdo->prepare("SELECT label FROM roles WHERE role_key = ?");
        $stmt->execute([$role_key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $store['label'][$role_key] = ($row ? $row['label'] : $role_key);

    } catch (Exception $e) {
        error_log("Get role label error: " . $e->getMessage());
        return $role_key;
    }
}

/**
 * 역할의 권한 매트릭스를 업데이트하고 변경 이력을 기록합니다.
 * Design Ref: role-permission-management §권한 매트릭스 화면
 * @param string $role_key 역할 키
 * @param array $permissions 권한 배열 (permission_key => bool)
 * @param int|null $changed_by 변경한 사용자 ID
 * @return bool 성공 여부
 */
function update_role_permissions($role_key, $permissions, $changed_by = null) {
    try {
        $pdo = permission_pdo();

        $pdo->beginTransaction();

        $current = get_role_permissions($role_key);

        $upsert = $pdo->prepare("
            INSERT INTO role_permissions (role_key, permission_key, enabled) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)
        ");
        $log = $pdo->prepare("
            INSERT INTO permission_change_log (role_key, permission_key, old_value, new_value, changed_by)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($permissions as $permission_key => $enabled) {
            $new_value = (int)(bool)$enabled;
            $old_value = (int)(bool)($current[$permission_key] ?? false);

            $upsert->execute([$role_key, $permission_key, $new_value]);

            if ($old_value !== $new_value) {
                $log->execute([$role_key, $permission_key, $old_value, $new_value, $changed_by]);
            }
        }

        $pdo->commit();
        permission_cache_reset(); // 변경 직후 같은 요청에서 옛 권한이 쓰이지 않도록
        return true;

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Update role permissions error: " . $e->getMessage());
        return false;
    }
}

/**
 * 새 역할(등급)을 생성하고 전체 권한을 비활성 상태로 시드합니다.
 * Design Ref: role-permission-management §역할 관리 화면 - 역할 추가
 * @param string $role_key 역할 키 (영문 소문자/숫자/언더스코어)
 * @param string $label 역할 라벨 (한글 표시명)
 * @param int $level 역할 레벨 (정렬/계층용 숫자)
 * @return bool|string 성공 시 true, 실패 시 오류 메시지 문자열
 */
function create_role($role_key, $label, $level) {
    if (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $role_key)) {
        return '역할 키는 영문 소문자/숫자/언더스코어로만 구성되어야 합니다.';
    }

    try {
        $pdo = permission_pdo();

        $check = $pdo->prepare("SELECT role_key FROM roles WHERE role_key = ?");
        $check->execute([$role_key]);
        if ($check->fetch()) {
            return '이미 존재하는 역할 키입니다.';
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO roles (role_key, label, level, is_system) VALUES (?, ?, ?, 0)");
        $stmt->execute([$role_key, $label, $level]);

        // 신규 역할은 전부 비활성으로 시작 (Plan 결정사항)
        $seed = $pdo->prepare("INSERT INTO role_permissions (role_key, permission_key, enabled) VALUES (?, ?, 0)");
        foreach (get_all_permission_keys() as $permission_key) {
            $seed->execute([$role_key, $permission_key]);
        }

        $pdo->commit();
        permission_cache_reset();
        return true;

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Create role error: " . $e->getMessage());
        return '역할 생성 중 오류가 발생했습니다.';
    }
}

/**
 * 역할의 라벨/레벨을 수정합니다. role_key는 변경할 수 없습니다.
 * Design Ref: role-permission-management §역할 관리 화면 - 역할 수정
 * @param string $role_key 역할 키
 * @param string $label 새 라벨
 * @param int $level 새 레벨
 * @return bool|string 성공 시 true, 실패 시 오류 메시지 문자열
 */
function update_role($role_key, $label, $level) {
    try {
        $pdo = permission_pdo();

        $stmt = $pdo->prepare("UPDATE roles SET label = ?, level = ? WHERE role_key = ?");
        $stmt->execute([$label, $level, $role_key]);

        permission_cache_reset();
        return true;

    } catch (Exception $e) {
        error_log("Update role error: " . $e->getMessage());
        return '역할 수정 중 오류가 발생했습니다.';
    }
}

/**
 * 역할(등급)을 삭제합니다. 시스템 역할이거나 해당 역할을 사용 중인 사용자가 있으면 거부합니다.
 * Design Ref: role-permission-management §역할 관리 화면 - 역할 삭제
 * @param string $role_key 역할 키
 * @return bool|string 성공 시 true, 실패 시 오류 메시지 문자열
 */
function delete_role($role_key) {
    try {
        $pdo = permission_pdo();

        $stmt = $pdo->prepare("SELECT is_system FROM roles WHERE role_key = ?");
        $stmt->execute([$role_key]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            return '존재하지 않는 역할입니다.';
        }
        if ((bool)$role['is_system']) {
            return '시스템 기본 역할은 삭제할 수 없습니다.';
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM users WHERE role = ?");
        $stmt->execute([$role_key]);
        $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        if ($count > 0) {
            return "이 역할을 사용 중인 사용자가 {$count}명 있어 삭제할 수 없습니다.";
        }

        $stmt = $pdo->prepare("DELETE FROM roles WHERE role_key = ?");
        $stmt->execute([$role_key]);

        permission_cache_reset();
        return true;

    } catch (Exception $e) {
        error_log("Delete role error: " . $e->getMessage());
        return '역할 삭제 중 오류가 발생했습니다.';
    }
}

/* ============================================================
 * 역할 level 기반 권한 (소속 지점 변경 / 점장 임명 / 변경 승인)
 * level 기준: branch_manager(점장/센터장)=50, ceo(사장)=60
 * Ref: sql/role_permission_system.sql §5 기본 역할 시드
 * ============================================================ */

if (!defined('LEVEL_BRANCH_MANAGER')) define('LEVEL_BRANCH_MANAGER', 50);
if (!defined('LEVEL_CEO')) define('LEVEL_CEO', 60);

/**
 * 역할 키의 level을 반환합니다. roles 테이블을 1회 캐시하며,
 * 조회 실패 시 레거시 기본값으로 폴백합니다.
 * @param string $role_key
 * @return int
 */
function get_role_level($role_key) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (get_all_roles() as $r) {
            $cache[$r['role_key']] = (int)$r['level'];
        }
    }
    if (isset($cache[$role_key])) {
        return $cache[$role_key];
    }
    $legacy = [
        'user' => 10, 'staff' => 20, 'office_staff' => 25, 'supervisor' => 30,
        'manager' => 40, 'branch_manager' => 50, 'ceo' => 60, 'admin' => 90, 'super_admin' => 100,
    ];
    return $legacy[$role_key] ?? 0;
}

/**
 * 현재 로그인 사용자의 역할 level을 반환합니다.
 * @return int
 */
function current_user_level() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return get_role_level($_SESSION['role'] ?? '');
}

/**
 * 소속 지점을 (요청 없이) 직접 변경할 수 있는지 여부. CEO 이상만 가능.
 * @return bool
 */
function can_change_store_directly() {
    return current_user_level() >= LEVEL_CEO;
}

/**
 * 점장(branch_manager) 역할을 임명할 수 있는지 여부. CEO 이상만 가능.
 * @return bool
 */
function can_appoint_branch_manager() {
    return current_user_level() >= LEVEL_CEO;
}

/**
 * 지점 변경 요청을 승인할 수 있는지 여부.
 * - CEO 이상(level>=60): 어느 지점이든 승인 가능
 * - 점장 이상(level>=50): 도착(발령) 지점이 본인 소속일 때만 승인 가능
 * @param int|null $to_store_id 도착(발령) 지점 ID. null이면 메뉴 노출 판정용.
 * @return bool
 */
function can_approve_store_change($to_store_id = null) {
    $level = current_user_level();
    if ($level >= LEVEL_CEO) {
        return true;
    }
    if ($level >= LEVEL_BRANCH_MANAGER) {
        if ($to_store_id === null) {
            return true;
        }
        return (int)($_SESSION['store_id'] ?? 0) === (int)$to_store_id;
    }
    return false;
}

/**
 * 현재 로그인 사용자가 main_office_admin 역할 이상인지 확인합니다.
 * 메인 오피스(전 점포 입력 자료 열람) 접근 권한 판정에 사용합니다.
 * super_admin은 level=100으로 항상 최상위이므로 별도 분기 없이 포함됩니다.
 * @return bool
 */
function is_main_office_admin() {
    $required = get_role_level('main_office_admin');
    // 역할이 아직 정의되지 않아 level을 알 수 없으면(0) 안전하게 차단(fail-closed)
    if ($required <= 0) {
        return false;
    }
    return current_user_level() >= $required;
}

/**
 * 라벨로 역할의 level을 조회합니다 ('물류센터'처럼 role_key를 모르는 커스텀 역할용).
 * roles 테이블에 해당 라벨이 없으면 null을 반환합니다.
 * @param string $label 역할 라벨 (한글 표시명)
 * @return int|null
 */
function get_role_level_by_label($label) {
    static $cache = [];
    if (array_key_exists($label, $cache)) {
        return $cache[$label];
    }
    try {
        $pdo = permission_pdo();

        $stmt = $pdo->prepare("SELECT level FROM roles WHERE label = ? LIMIT 1");
        $stmt->execute([$label]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $cache[$label] = ($row ? (int)$row['level'] : null);
    } catch (Exception $e) {
        error_log("get_role_level_by_label error: " . $e->getMessage());
        return $cache[$label] = null;
    }
}

/**
 * 현재 로그인 사용자의 역할 level이 주어진 라벨의 역할 level 이상인지 확인합니다.
 * 라벨에 해당하는 역할이 없으면 false를 반환합니다.
 * @param string $label 기준 역할 라벨 (예: '물류센터')
 * @return bool
 */
function current_role_at_least_label($label) {
    $required = get_role_level_by_label($label);
    if ($required === null) {
        return false;
    }
    return current_user_level() >= $required;
}
?>
