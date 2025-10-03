-- 인덱스 추가 (아직 없는 경우만)
USE u622428657_homekmart;

-- preferred_store_id 인덱스 추가 (이미 존재하면 오류 발생 - 무시하고 진행)
ALTER TABLE customers
ADD INDEX idx_preferred_store (preferred_store_id);

-- 인덱스 확인
SHOW INDEX FROM customers WHERE Key_name = 'idx_preferred_store';
