-- 매입 확정 기능을 위한 purchases 테이블 스키마 수정
-- 실행 전에 현재 purchases 테이블 구조를 확인하고 백업을 권장합니다

-- 1. purchases 테이블에 확정 관련 필드 추가
ALTER TABLE purchases
ADD COLUMN is_confirmed TINYINT(1) DEFAULT 0 COMMENT '매입 확정 여부 (0: 미확정, 1: 확정)',
ADD COLUMN confirmed_at DATETIME DEFAULT NULL COMMENT '확정 일시',
ADD COLUMN confirmed_by_user_id INT DEFAULT NULL COMMENT '확정한 사용자 ID';

-- 2. 인덱스 추가 (조회 성능 향상)
ALTER TABLE purchases ADD INDEX idx_is_confirmed (is_confirmed);
ALTER TABLE purchases ADD INDEX idx_confirmed_at (confirmed_at);

-- 3. 외래키 제약 조건 추가 (confirmed_by_user_id -> users.id)
-- 주의: users 테이블이 존재하고 id 필드가 있는지 확인 후 실행
-- ALTER TABLE purchases ADD CONSTRAINT fk_purchases_confirmed_by FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- 4. 확인 쿼리 - 수정된 테이블 구조 확인
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'purchases'
  AND COLUMN_NAME IN ('is_confirmed', 'confirmed_at', 'confirmed_by_user_id')
ORDER BY ORDINAL_POSITION;

-- 5. 현재 데이터 확인 쿼리
-- SELECT purchase_id, is_confirmed, confirmed_at, confirmed_by_user_id FROM purchases LIMIT 5;