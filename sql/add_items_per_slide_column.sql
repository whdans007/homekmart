-- items_per_slide 컬럼을 display_sections 테이블에 추가
-- 기본값 4로 설정하여 기존 데이터와의 호환성 유지

ALTER TABLE display_sections 
ADD COLUMN items_per_slide INT(2) DEFAULT 4 NOT NULL 
COMMENT '슬라이드당 표시할 아이템 수 (1-10)';

-- 기존 데이터에 대해 기본값 4 적용
UPDATE display_sections 
SET items_per_slide = 4 
WHERE items_per_slide IS NULL OR items_per_slide = 0;

-- 컬럼 추가 확인
DESCRIBE display_sections;