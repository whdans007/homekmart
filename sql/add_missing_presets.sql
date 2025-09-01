-- ===================================================================
-- 누락된 레이아웃 프리셋 추가
-- 생성일: 2025-01-19
-- 용도: 4등분, 5등분, 1-3-1 등 누락된 프리셋들 추가
-- ===================================================================

USE `u622428657_homekmart`;

-- 기존 시스템 프리셋들 업데이트 (JSON 형식 표준화)
UPDATE `layout_presets` SET 
    `layout_config` = '{"rows": [{"row_name": "메인 배너", "row_description": "전체 너비 배너 영역", "columns": [{"column_width": 12, "column_name": "전체 컬럼"}]}]}'
WHERE `preset_name` = '단일 컬럼' AND `is_system_preset` = 1;

UPDATE `layout_presets` SET 
    `layout_config` = '{"rows": [{"row_name": "2분할 영역", "row_description": "좌우 균등 분할 영역", "columns": [{"column_width": 6, "column_name": "왼쪽 컬럼"}, {"column_width": 6, "column_name": "오른쪽 컬럼"}]}]}'
WHERE `preset_name` = '2분할 레이아웃' AND `is_system_preset` = 1;

UPDATE `layout_presets` SET 
    `layout_config` = '{"rows": [{"row_name": "3분할 영역", "row_description": "3등분 균등 분할 영역", "columns": [{"column_width": 4, "column_name": "첫 번째 컬럼"}, {"column_width": 4, "column_name": "두 번째 컬럼"}, {"column_width": 4, "column_name": "세 번째 컬럼"}]}]}'
WHERE `preset_name` = '3분할 레이아웃' AND `is_system_preset` = 1;

UPDATE `layout_presets` SET 
    `layout_config` = '{"rows": [{"row_name": "메인-서브 영역", "row_description": "메인 콘텐츠와 사이드바 영역", "columns": [{"column_width": 8, "column_name": "메인 콘텐츠"}, {"column_width": 4, "column_name": "사이드바"}]}]}'
WHERE `preset_name` = '메인-서브 레이아웃' AND `is_system_preset` = 1;

UPDATE `layout_presets` SET 
    `layout_config` = '{"rows": [{"row_name": "서브-메인 영역", "row_description": "사이드바와 메인 콘텐츠 영역", "columns": [{"column_width": 4, "column_name": "사이드바"}, {"column_width": 8, "column_name": "메인 콘텐츠"}]}]}'
WHERE `preset_name` = '서브-메인 레이아웃' AND `is_system_preset` = 1;

UPDATE `layout_presets` SET 
    `layout_config` = '{"rows": [{"row_name": "상단 배너", "row_description": "전체 너비 배너", "columns": [{"column_width": 12, "column_name": "배너"}]}, {"row_name": "중간 2분할", "row_description": "2등분 콘텐츠 영역", "columns": [{"column_width": 6, "column_name": "왼쪽 콘텐츠"}, {"column_width": 6, "column_name": "오른쪽 콘텐츠"}]}, {"row_name": "하단 배너", "row_description": "전체 너비 하단 영역", "columns": [{"column_width": 12, "column_name": "하단 영역"}]}]}'
WHERE `preset_name` = '배너-2분할-배너' AND `is_system_preset` = 1;

-- 새로운 프리셋들 추가
INSERT INTO `layout_presets` (`preset_name`, `preset_description`, `pattern_code`, `layout_config`, `is_system_preset`) VALUES
('4분할 레이아웃', '4등분 균등 분할 레이아웃', '1-1-1-1', '{"rows": [{"row_name": "4분할 영역", "row_description": "4등분 균등 분할 영역", "columns": [{"column_width": 3, "column_name": "첫 번째 컬럼"}, {"column_width": 3, "column_name": "두 번째 컬럼"}, {"column_width": 3, "column_name": "세 번째 컬럼"}, {"column_width": 3, "column_name": "네 번째 컬럼"}]}]}', 1),

('5분할 레이아웃', '5등분 균등 분할 레이아웃 (반응형)', '1-1-1-1-1', '{"rows": [{"row_name": "5분할 영역", "row_description": "5등분 균등 분할 영역", "columns": [{"column_width": 2, "column_name": "첫 번째"}, {"column_width": 3, "column_name": "두 번째"}, {"column_width": 2, "column_name": "세 번째"}, {"column_width": 3, "column_name": "네 번째"}, {"column_width": 2, "column_name": "다섯 번째"}]}]}', 1),

('1-3-1 레이아웃', '좌우 사이드바와 중앙 3분할', '1-3-1', '{"rows": [{"row_name": "헤더-본문-풋터", "row_description": "사이드바 + 3분할 + 사이드바", "columns": [{"column_width": 2, "column_name": "왼쪽 사이드바"}, {"column_width": 3, "column_name": "첫 번째 콘텐츠"}, {"column_width": 2, "column_name": "두 번째 콘텐츠"}, {"column_width": 3, "column_name": "세 번째 콘텐츠"}, {"column_width": 2, "column_name": "오른쪽 사이드바"}]}]}', 1),

('2-3-2 레이아웃', '다단계 구성 레이아웃', '2-3-2', '{"rows": [{"row_name": "상단 2분할", "row_description": "상단 2등분 영역", "columns": [{"column_width": 6, "column_name": "상단 왼쪽"}, {"column_width": 6, "column_name": "상단 오른쪽"}]}, {"row_name": "중간 3분할", "row_description": "중간 3등분 영역", "columns": [{"column_width": 4, "column_name": "중간 첫 번째"}, {"column_width": 4, "column_name": "중간 두 번째"}, {"column_width": 4, "column_name": "중간 세 번째"}]}, {"row_name": "하단 2분할", "row_description": "하단 2등분 영역", "columns": [{"column_width": 6, "column_name": "하단 왼쪽"}, {"column_width": 6, "column_name": "하단 오른쪽"}]}]}', 1),

('6분할 레이아웃', '2x3 격자 레이아웃', '2-2-2', '{"rows": [{"row_name": "첫 번째 행", "row_description": "상단 2분할 영역", "columns": [{"column_width": 6, "column_name": "상단 왼쪽"}, {"column_width": 6, "column_name": "상단 오른쪽"}]}, {"row_name": "두 번째 행", "row_description": "중간 2분할 영역", "columns": [{"column_width": 6, "column_name": "중간 왼쪽"}, {"column_width": 6, "column_name": "중간 오른쪽"}]}, {"row_name": "세 번째 행", "row_description": "하단 2분할 영역", "columns": [{"column_width": 6, "column_name": "하단 왼쪽"}, {"column_width": 6, "column_name": "하단 오른쪽"}]}]}', 1),

('9분할 레이아웃', '3x3 격자 레이아웃', '3-3-3', '{"rows": [{"row_name": "첫 번째 행", "row_description": "상단 3분할 영역", "columns": [{"column_width": 4, "column_name": "상단1"}, {"column_width": 4, "column_name": "상단2"}, {"column_width": 4, "column_name": "상단3"}]}, {"row_name": "두 번째 행", "row_description": "중간 3분할 영역", "columns": [{"column_width": 4, "column_name": "중간1"}, {"column_width": 4, "column_name": "중간2"}, {"column_width": 4, "column_name": "중간3"}]}, {"row_name": "세 번째 행", "row_description": "하단 3분할 영역", "columns": [{"column_width": 4, "column_name": "하단1"}, {"column_width": 4, "column_name": "하단2"}, {"column_width": 4, "column_name": "하단3"}]}]}', 1),

('헤더-본문-풋터', '전통적인 웹 레이아웃', '1-2-1', '{"rows": [{"row_name": "헤더 영역", "row_description": "상단 전체폭 헤더", "columns": [{"column_width": 12, "column_name": "헤더"}]}, {"row_name": "본문 영역", "row_description": "메인 콘텐츠와 사이드바", "columns": [{"column_width": 8, "column_name": "메인 콘텐츠"}, {"column_width": 4, "column_name": "사이드바"}]}, {"row_name": "풋터 영역", "row_description": "하단 전체폭 풋터", "columns": [{"column_width": 12, "column_name": "풋터"}]}]}', 1),

('대시보드 레이아웃', '대시보드용 복합 레이아웃', 'dashboard', '{"rows": [{"row_name": "대시보드 헤더", "row_description": "전체 헤더", "columns": [{"column_width": 12, "column_name": "헤더"}]}, {"row_name": "메인 대시보드", "row_description": "좌측 메뉴 + 우측 3분할", "columns": [{"column_width": 3, "column_name": "메뉴"}, {"column_width": 3, "column_name": "위젯1"}, {"column_width": 3, "column_name": "위젯2"}, {"column_width": 3, "column_name": "위젯3"}]}, {"row_name": "상세 정보", "row_description": "하단 상세 정보 2분할", "columns": [{"column_width": 6, "column_name": "차트 영역"}, {"column_width": 6, "column_name": "테이블 영역"}]}]}', 1);

-- 프리셋 사용 횟수 초기화 (기존 프리셋들)
UPDATE `layout_presets` SET `usage_count` = 0 WHERE `is_system_preset` = 1;

-- ===================================================================
-- 완료 메시지
-- ===================================================================
-- 누락된 프리셋 데이터가 성공적으로 추가되었습니다.
-- 총 15개의 다양한 레이아웃 프리셋을 사용할 수 있습니다.