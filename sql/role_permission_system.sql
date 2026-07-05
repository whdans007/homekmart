-- ============================================================
-- 회원 등급/권한 동적 관리 시스템
-- Design Ref: docs/02-design/features/role-permission-management.design.md
-- 작성일: 2026-06-12
-- ============================================================

-- 1. roles 테이블: 동적 역할(등급) 정의
CREATE TABLE IF NOT EXISTS roles (
    role_key VARCHAR(50) NOT NULL PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    level INT NOT NULL DEFAULT 0,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. role_permissions 테이블: 역할별 기능 권한 매트릭스
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. permission_change_log 테이블: 권한 변경 이력
CREATE TABLE IF NOT EXISTS permission_change_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(50) NOT NULL,
    permission_key VARCHAR(100) NOT NULL,
    old_value TINYINT(1) NOT NULL,
    new_value TINYINT(1) NOT NULL,
    changed_by INT UNSIGNED NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. users.role: ENUM -> VARCHAR(50) (동적 역할 허용)
ALTER TABLE users MODIFY role VARCHAR(50) NOT NULL DEFAULT 'user';

-- 5. 기본 9개 역할 시드
INSERT INTO roles (role_key, label, level, is_system) VALUES
    ('user', '일반사용자', 10, 1),
    ('staff', '직원', 20, 1),
    ('office_staff', '오피스 스텝', 25, 1),
    ('supervisor', '슈퍼바이져', 30, 0),
    ('manager', '매니져', 40, 0),
    ('branch_manager', '점장(센터장)', 50, 0),
    ('ceo', '사장', 60, 0),
    ('admin', '시스템 관리자', 90, 1),
    ('super_admin', '슈퍼어드민', 100, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);
