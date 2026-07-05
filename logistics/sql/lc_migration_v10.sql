-- ============================================================
-- Migration v10: 상품 등록/변경 이력 테이블
-- 실행: mysql -u root -p sunset < lc_migration_v10.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS lc_product_history (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT NOT NULL                        COMMENT 'lc_products.id',
    user_id     INT                                 COMMENT 'users.id',
    user_name   VARCHAR(100)                        COMMENT '변경 시점 사용자명 (스냅샷)',
    action      ENUM('create','update') NOT NULL    COMMENT 'create=등록, update=변경',
    field_name  VARCHAR(60)                         COMMENT '변경된 필드명 (update 시)',
    field_label VARCHAR(60)                         COMMENT '화면 표시용 레이블',
    old_value   TEXT                                COMMENT '변경 전 값',
    new_value   TEXT                                COMMENT '변경 후 값',
    changed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_time (product_id, changed_at),
    FOREIGN KEY (product_id) REFERENCES lc_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='상품 등록/변경 이력';

SELECT 'Migration v10 완료: lc_product_history 생성' AS result;
