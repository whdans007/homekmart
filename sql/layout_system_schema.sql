-- ===================================================================
-- HOME K MART 동적 레이아웃 시스템 추가 테이블
-- 생성일: 2025-08-31
-- 용도: 메인 페이지 동적 레이아웃 구성 시스템
-- 설명: 행-열 기반으로 유연한 레이아웃 구성 (1-4개 컬럼 지원)
-- ===================================================================

USE `u622428657_homekmart`;

-- ===================================================================
-- 1. 레이아웃 행 관리 테이블
-- ===================================================================

-- 메인 페이지 레이아웃 행 정의 테이블
CREATE TABLE `layout_rows` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `row_name` varchar(100) DEFAULT NULL COMMENT '행 이름 (예: 메인배너, 추천상품영역)',
  `row_order` int(11) NOT NULL DEFAULT 0 COMMENT '행 표시 순서',
  `row_description` text DEFAULT NULL COMMENT '행 설명',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
  `margin_top` int(11) DEFAULT 0 COMMENT '상단 여백 (px)',
  `margin_bottom` int(11) DEFAULT 20 COMMENT '하단 여백 (px)',
  `background_color` varchar(7) DEFAULT NULL COMMENT '배경색 (#ffffff)',
  `custom_css_class` varchar(255) DEFAULT NULL COMMENT '커스텀 CSS 클래스',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_layout_rows_order` (`row_order`),
  INDEX `idx_layout_rows_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='메인 페이지 레이아웃 행 관리';

-- ===================================================================
-- 2. 레이아웃 컬럼 관리 테이블  
-- ===================================================================

-- 각 행의 컬럼 구성 및 섹션 매핑 테이블
CREATE TABLE `layout_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `row_id` int(11) NOT NULL COMMENT '레이아웃 행 ID',
  `column_order` int(11) NOT NULL DEFAULT 0 COMMENT '컬럼 순서',
  `column_width` int(11) NOT NULL DEFAULT 12 COMMENT 'Bootstrap 그리드 너비 (1-12)',
  `section_id` int(11) DEFAULT NULL COMMENT '배치된 섹션 ID',
  `column_name` varchar(100) DEFAULT NULL COMMENT '컬럼 이름',
  `min_height` int(11) DEFAULT NULL COMMENT '최소 높이 (px)',
  `padding_x` int(11) DEFAULT 15 COMMENT '좌우 패딩 (px)',
  `padding_y` int(11) DEFAULT 10 COMMENT '상하 패딩 (px)',
  `background_color` varchar(7) DEFAULT NULL COMMENT '배경색',
  `border_radius` int(11) DEFAULT 0 COMMENT '모서리 둥글기 (px)',
  `custom_css_class` varchar(255) DEFAULT NULL COMMENT '커스텀 CSS 클래스',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `row_id` (`row_id`),
  KEY `section_id` (`section_id`),
  INDEX `idx_layout_columns_order` (`row_id`, `column_order`),
  INDEX `idx_layout_columns_active` (`is_active`),
  CONSTRAINT `layout_columns_ibfk_1` FOREIGN KEY (`row_id`) REFERENCES `layout_rows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `layout_columns_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `display_sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='레이아웃 컬럼 및 섹션 매핑';

-- ===================================================================
-- 3. 레이아웃 프리셋 템플릿 테이블
-- ===================================================================

-- 자주 사용하는 레이아웃 패턴을 저장하는 테이블
CREATE TABLE `layout_presets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `preset_name` varchar(100) NOT NULL COMMENT '프리셋 이름',
  `preset_description` text DEFAULT NULL COMMENT '프리셋 설명',
  `pattern_code` varchar(50) NOT NULL COMMENT '패턴 코드 (예: 1-2-1, 2-2)',
  `layout_config` json NOT NULL COMMENT '레이아웃 구성 JSON',
  `thumbnail_url` varchar(500) DEFAULT NULL COMMENT '미리보기 이미지 URL',
  `usage_count` int(11) DEFAULT 0 COMMENT '사용 횟수',
  `is_system_preset` tinyint(1) DEFAULT 0 COMMENT '시스템 기본 프리셋 여부',
  `created_by` int(11) DEFAULT NULL COMMENT '생성자 ID',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `preset_name` (`preset_name`),
  INDEX `idx_layout_presets_pattern` (`pattern_code`),
  INDEX `idx_layout_presets_usage` (`usage_count` DESC),
  KEY `created_by` (`created_by`),
  CONSTRAINT `layout_presets_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='레이아웃 프리셋 템플릿';

-- ===================================================================
-- 4. 초기 데이터 삽입
-- ===================================================================

-- 기본 레이아웃 행 생성 (기존 섹션들과 호환을 위해)
INSERT INTO `layout_rows` (`row_name`, `row_order`, `row_description`, `margin_bottom`) VALUES
('메인 배너 영역', 10, '상단 메인 배너 또는 프로모션 영역', 30),
('추천 상품 영역', 20, '추천 상품 및 인기 상품 진열 영역', 20),
('신상품 영역', 30, '신상품 및 최신 상품 진열 영역', 20),
('카테고리 영역', 40, '카테고리별 상품 진열 영역', 20);

-- 기본 컬럼 구성 (1열 구성으로 시작)
INSERT INTO `layout_columns` (`row_id`, `column_order`, `column_width`, `column_name`) VALUES
(1, 1, 12, '메인 배너'),
(2, 1, 12, '추천 상품'),
(3, 1, 12, '신상품'),
(4, 1, 12, '카테고리');

-- 시스템 기본 프리셋 템플릿
INSERT INTO `layout_presets` (`preset_name`, `preset_description`, `pattern_code`, `layout_config`, `is_system_preset`) VALUES
('단일 컬럼', '전체 너비 단일 컬럼 레이아웃', '1', '{"rows": [{"columns": [{"width": 12}]}]}', 1),
('2분할 레이아웃', '좌우 균등 분할 레이아웃', '2-2', '{"rows": [{"columns": [{"width": 6}, {"width": 6}]}]}', 1),
('3분할 레이아웃', '3등분 균등 분할 레이아웃', '1-1-1', '{"rows": [{"columns": [{"width": 4}, {"width": 4}, {"width": 4}]}]}', 1),
('메인-서브 레이아웃', '메인 영역과 서브 영역', '2-1', '{"rows": [{"columns": [{"width": 8}, {"width": 4}]}]}', 1),
('서브-메인 레이아웃', '서브 영역과 메인 영역', '1-2', '{"rows": [{"columns": [{"width": 4}, {"width": 8}]}]}', 1),
('배너-2분할-배너', '상하 배너 + 중간 2분할', '1-2-1', '{"rows": [{"columns": [{"width": 12}]}, {"columns": [{"width": 6}, {"width": 6}]}, {"columns": [{"width": 12}]}]}', 1);

-- ===================================================================
-- 5. 인덱스 최적화
-- ===================================================================

-- 성능 최적화를 위한 추가 인덱스
CREATE INDEX idx_layout_system_active ON layout_rows (is_active, row_order);
CREATE INDEX idx_layout_columns_section ON layout_columns (section_id, is_active);
CREATE INDEX idx_layout_presets_system ON layout_presets (is_system_preset, usage_count DESC);

-- ===================================================================
-- 완료 메시지
-- ===================================================================
-- 동적 레이아웃 시스템 테이블이 성공적으로 생성되었습니다.
-- 다음 단계: 백엔드 API 개발 (ajax_layout_manager.php)