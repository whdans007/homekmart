-- stores 테이블에 is_active 컬럼 추가

-- 1. is_active 컬럼 추가 (기본값: 1로 모든 기존 점포는 활성 상태)
ALTER TABLE stores ADD COLUMN is_active TINYINT(1) DEFAULT 1;

-- 2. address 컬럼도 추가 (선택사항 - 주소 정보용)
ALTER TABLE stores ADD COLUMN address VARCHAR(255) DEFAULT NULL;

-- 3. phone 컬럼도 추가 (선택사항 - 전화번호용)
ALTER TABLE stores ADD COLUMN phone VARCHAR(20) DEFAULT NULL;

-- 4. 기존 데이터 업데이트 (모든 점포를 활성 상태로)
UPDATE stores SET is_active = 1 WHERE is_active IS NULL;