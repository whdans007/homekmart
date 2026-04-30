-- ============================================================
-- 물류센터 관리 시스템 DB 마이그레이션
-- 실행 날짜: 2026-03-19
-- ============================================================
-- 참고: 물류센터 부서는 지점(stores)으로 관리됩니다.
--       users.store_id → stores.name = '물류센터' 이면 물류센터 소속.

-- 1. 출고 관리 테이블 생성
CREATE TABLE IF NOT EXISTS logistics_outbound (
  outbound_id      INT AUTO_INCREMENT PRIMARY KEY,
  outbound_date    DATE NOT NULL COMMENT '출고일자',
  dest_store_id    INT NOT NULL COMMENT '출고 대상 지점 ID',
  product_id       INT NOT NULL COMMENT '상품 ID',
  quantity         INT NOT NULL DEFAULT 0 COMMENT '출고 수량 (낱개)',
  box_quantity     INT DEFAULT 0 COMMENT '박스 수량',
  unit_price       DECIMAL(15,2) DEFAULT 0 COMMENT '단가',
  total_amount     DECIMAL(15,2) DEFAULT 0 COMMENT '총 금액',
  status           ENUM('pending','approved','delivered','cancelled') DEFAULT 'pending' COMMENT '출고 상태',
  notes            TEXT COMMENT '비고',
  created_by       INT COMMENT '등록자 user id',
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (dest_store_id) REFERENCES stores(id),
  FOREIGN KEY (product_id)    REFERENCES products(id),
  FOREIGN KEY (created_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류센터 출고 관리';

-- 3. 물류센터 점포가 없으면 추가 (이미 있을 경우 무시)
INSERT IGNORE INTO stores (name) VALUES ('WHEREHOUSE (물류센터)');

-- 완료 메시지
SELECT '물류센터 관리 시스템 마이그레이션 완료' AS result;
