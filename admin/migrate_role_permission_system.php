<?php
/**
 * 회원 등급/권한 동적 관리 시스템 마이그레이션
 * Design Ref: docs/02-design/features/role-permission-management.design.md
 * 실행일: 2026-06-12
 *
 * - roles, role_permissions, permission_change_log 테이블 생성
 * - users.role ENUM -> VARCHAR(50) 변경
 * - 9개 기본 역할 시드
 * - 기존 check_legacy_permission() 기준 권한값을 role_permissions에 이식 (기존 동작 무변화)
 * - 신규 4개 역할(supervisor, manager, branch_manager, ceo)은 전부 비활성으로 시작
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

ensure_logged_in();
if ($_SESSION['role'] !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();
$conn->autocommit(false);

// 기존 check_legacy_permission()/get_default_permissions() 기준 효과적 기본 권한
// Plan SC: 현재 설정되어 있는 권한은 그대로 유지
$permission_keys = [
    'admin_access', 'user_management', 'store_management', 'product_management',
    'purchase_management', 'brand_management', 'category_management', 'supplier_management',
    'wholesale_management', 'store_transfer_management', 'customer_management', 'delivery_management',
    'settings', 'shop_access', 'barcode_management', 'accounting_management',
    'logistics_purchase_management', 'logistics_outbound_management', 'logistics_inventory_management',
];

$role_permission_defaults = [
    'super_admin' => array_fill_keys($permission_keys, 1),
    'admin' => array_merge(array_fill_keys($permission_keys, 1), [
        'store_management' => 0,
        'settings' => 0,
    ]),
    'staff' => array_merge(array_fill_keys($permission_keys, 0), [
        'shop_access' => 1,
        'barcode_management' => 1,
    ]),
    'office_staff' => array_merge(array_fill_keys($permission_keys, 0), [
        'shop_access' => 1,
        'barcode_management' => 1,
        'accounting_management' => 1,
    ]),
    'user' => array_merge(array_fill_keys($permission_keys, 0), [
        'shop_access' => 1,
    ]),
    // 신규 역할: 전부 비활성으로 시작
    'supervisor' => array_fill_keys($permission_keys, 0),
    'manager' => array_fill_keys($permission_keys, 0),
    'branch_manager' => array_fill_keys($permission_keys, 0),
    'ceo' => array_fill_keys($permission_keys, 0),
];

try {
    echo "<h2>회원 등급/권한 동적 관리 시스템 마이그레이션</h2>";
    echo "<pre>";

    // 1. 테이블 생성
    echo "\n[1단계] roles / role_permissions / permission_change_log 테이블 생성...\n";

    $conn->query("
        CREATE TABLE IF NOT EXISTS roles (
            role_key VARCHAR(50) NOT NULL PRIMARY KEY,
            label VARCHAR(100) NOT NULL,
            level INT NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($conn->error) throw new Exception("roles 테이블 생성 실패: " . $conn->error);
    echo "  - roles 테이블 OK\n";

    $conn->query("
        CREATE TABLE IF NOT EXISTS role_permissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            role_key VARCHAR(50) NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_role_permission (role_key, permission_key),
            CONSTRAINT fk_role_permissions_role
                FOREIGN KEY (role_key) REFERENCES roles(role_key)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($conn->error) throw new Exception("role_permissions 테이블 생성 실패: " . $conn->error);
    echo "  - role_permissions 테이블 OK\n";

    $conn->query("
        CREATE TABLE IF NOT EXISTS permission_change_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            role_key VARCHAR(50) NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            old_value TINYINT(1) NOT NULL,
            new_value TINYINT(1) NOT NULL,
            changed_by INT UNSIGNED NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($conn->error) throw new Exception("permission_change_log 테이블 생성 실패: " . $conn->error);
    echo "  - permission_change_log 테이블 OK\n";

    // 2. users.role 컬럼 타입 변경 (ENUM -> VARCHAR(50))
    echo "\n[2단계] users.role 컬럼 타입 확인 및 변경...\n";
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch_assoc();
    echo "  현재 타입: {$col['Type']}\n";
    if (stripos($col['Type'], 'enum') !== false) {
        $conn->query("ALTER TABLE users MODIFY role VARCHAR(50) NOT NULL DEFAULT 'user'");
        if ($conn->error) throw new Exception("users.role 컬럼 변경 실패: " . $conn->error);
        echo "  - VARCHAR(50)으로 변경 완료\n";
    } else {
        echo "  - 이미 VARCHAR 타입입니다. 스킵.\n";
    }

    // 3. 기본 9개 역할 시드
    echo "\n[3단계] 기본 9개 역할 시드...\n";
    $roles_seed = [
        ['user', '일반사용자', 10, 1],
        ['staff', '직원', 20, 1],
        ['office_staff', '오피스 스텝', 25, 1],
        ['supervisor', '슈퍼바이져', 30, 0],
        ['manager', '매니져', 40, 0],
        ['branch_manager', '점장(센터장)', 50, 0],
        ['ceo', '사장', 60, 0],
        ['admin', '시스템 관리자', 90, 1],
        ['super_admin', '슈퍼어드민', 100, 1],
    ];
    $stmt = $conn->prepare("
        INSERT INTO roles (role_key, label, level, is_system) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE label = VALUES(label)
    ");
    foreach ($roles_seed as $r) {
        $stmt->bind_param('ssii', $r[0], $r[1], $r[2], $r[3]);
        $stmt->execute();
        echo "  - {$r[0]} ({$r[1]}) OK\n";
    }
    $stmt->close();

    // 4. role_permissions 시드 (기존 동작 보존: check_legacy_permission 기준)
    echo "\n[4단계] role_permissions 시드 (기존 권한 동작 보존)...\n";
    $stmt = $conn->prepare("
        INSERT INTO role_permissions (role_key, permission_key, enabled) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE enabled = enabled
    ");
    foreach ($role_permission_defaults as $role_key => $perms) {
        foreach ($perms as $perm_key => $enabled) {
            $stmt->bind_param('ssi', $role_key, $perm_key, $enabled);
            $stmt->execute();
        }
        echo "  - {$role_key}: " . count($perms) . "개 권한 등록 완료\n";
    }
    $stmt->close();

    $conn->commit();
    echo "\n마이그레이션이 성공적으로 완료되었습니다.\n";
    echo "</pre>";

} catch (Exception $e) {
    $conn->rollback();
    echo "\n오류 발생: " . $e->getMessage() . "\n";
    echo "</pre>";
}

$conn->close();
