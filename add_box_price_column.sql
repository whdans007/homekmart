-- inventory 테이블에 box_price 컬럼 추가
-- 점포별 박스단가 설정을 위한 데이터베이스 구조 변경

-- box_price 컬럼 추가 (박스단가)
ALTER TABLE inventory 
ADD COLUMN box_price DECIMAL(10,2) DEFAULT NULL COMMENT '점포별 박스단가 (박스 구매 시 기본 단가)';

-- 인덱스 추가 (성능 최적화)
CREATE INDEX idx_inventory_box_price ON inventory(box_price);

-- 컬럼 추가 확인
DESCRIBE inventory;