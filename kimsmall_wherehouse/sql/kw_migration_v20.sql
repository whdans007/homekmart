-- ============================================================
-- kw_suppliers 독립 테이블 생성 (admin suppliers 분리)
-- DB: sunset
-- 실행 날짜: 2026-06-16
-- ============================================================

CREATE TABLE IF NOT EXISTS kw_suppliers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(200) NOT NULL               COMMENT '거래처명',
    contact_person  VARCHAR(100)                        COMMENT '담당자',
    phone           VARCHAR(50)                         COMMENT '전화번호',
    email           VARCHAR(100)                        COMMENT '이메일',
    memo            TEXT                                COMMENT '메모',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 전용 거래처 마스터';

SELECT '물류 거래처 테이블 생성 완료 (kw_suppliers)' AS result;
