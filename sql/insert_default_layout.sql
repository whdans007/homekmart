-- 기본 레이아웃 데이터 생성
USE `u622428657_homekmart`;

-- 기본 레이아웃 행 생성 (2x2 구조)
INSERT INTO `layout_rows` (`row_name`, `row_order`, `row_description`, `is_active`, `margin_bottom`) VALUES
('첫 번째 행', 1, '추천상품과 신상품 영역', 1, 20),
('두 번째 행', 2, '카테고리별 상품 영역', 1, 20);

-- 컬럼 생성 (각 행에 2개씩)
INSERT INTO `layout_columns` (`row_id`, `column_order`, `column_width`, `section_id`, `is_active`) VALUES
-- 첫 번째 행의 컬럼들
(1, 1, 6, 1, 1),  -- 첫 번째 섹션 (ID 1)
(1, 2, 6, 2, 1),  -- 두 번째 섹션 (ID 2)
-- 두 번째 행의 컬럼들  
(2, 1, 6, 3, 1),  -- 세 번째 섹션 (ID 3)
(2, 2, 6, 4, 1);  -- 네 번째 섹션 (ID 4)

-- 기본 프리셋 추가
INSERT INTO `layout_presets` (`name`, `description`, `preset_data`) VALUES
('기본 2x2 레이아웃', '2개 행, 각 행당 2개 컬럼으로 구성된 기본 레이아웃', 
'{"rows": [{"columns": 2, "widths": [6, 6]}, {"columns": 2, "widths": [6, 6]}]}');