-- Design Ref: §2 - 데이터 모델
-- 마이너스 재고 기능을 위한 스키마 변경

-- 1. stock 테이블 수정: 음수 허용
ALTER TABLE stock MODIFY quantity INT DEFAULT 0;
-- 기존 검증 제거: NOT NULL CHECK(quantity >= 0)

-- 2. 감사 로그 테이블 신규 생성
CREATE TABLE IF NOT EXISTS stock_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  stock_id INT,
  action VARCHAR(50) NOT NULL, -- 'NEGATIVE_STOCK', 'NORMALIZE', 'MANUAL_ADJUST'
  old_quantity INT,
  new_quantity INT,
  reason TEXT,
  branch_id INT,
  user_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_product_id (product_id),
  INDEX idx_action (action),
  INDEX idx_created_at (created_at)
);

-- 3. 음수 재고 조회용 인덱스
CREATE INDEX IF NOT EXISTS idx_stock_quantity ON stock(quantity);
