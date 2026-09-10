-- =====================================================================
-- create_fpwx_tables.sql
-- Foodpang 외부 도매 매핑(fpwx = FoodPang Wholesale eXternal) 전용 테이블 모음.
-- 목적: Foodpang이 제공하는 외부 도매 XLSX(Barcode / PMS_스냅샷 시트)를
--       원본 그대로(불변) 보관하고, 그 원본으로부터 HKM products와의
--       판매코드(sales_code) 매핑을 안전하게 구축/검토/이력관리한다.
--
-- 절대 원칙:
--   1) Foodpang 원본 데이터(raw 테이블)는 어떤 경우에도 UPDATE/DELETE 하지 않는다.
--      (재업로드 시 새 배치로 추가만 됨 - fpwx_import_batches 참고)
--   2) 기존 mall/기존 상품(products, inventory 등)은 이 모듈에서 절대 변경하지 않는다.
--      (products.id를 참조만 하는 매핑 테이블만 새로 추가)
--   3) fpwx_mapping_history는 append-only. 매핑 변경 시 반드시 이력을 남긴다.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) 업로드 배치 (XLSX 파일 1건 = 배치 1건, Barcode/PMS 두 시트를 함께 기록)
-- ---------------------------------------------------------------------
CREATE TABLE `fpwx_import_batches` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_hash` char(64) NOT NULL COMMENT '업로드 파일 바이트의 SHA-256 - 동일 파일 재업로드 감지(idempotent)',
  `original_filename` varchar(255) NOT NULL,
  `barcode_sheet_name` varchar(100) DEFAULT NULL,
  `pms_sheet_name` varchar(100) DEFAULT NULL,
  `barcode_row_count` int(11) NOT NULL DEFAULT 0,
  `pms_row_count` int(11) NOT NULL DEFAULT 0,
  `auto_matched_count` int(11) NOT NULL DEFAULT 0,
  `exception_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('uploaded','validated','processed','failed') NOT NULL DEFAULT 'uploaded',
  `error_message` text DEFAULT NULL,
  `uploaded_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `fpwx_import_batches_hash` (`batch_hash`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `fpwx_import_batches_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Foodpang 도매 XLSX 업로드 배치 (원본 스냅샷의 상위 단위)';

-- ---------------------------------------------------------------------
-- 2) 원본 스냅샷 (불변) - Barcode 시트 / PMS_스냅샷 시트
-- ---------------------------------------------------------------------
CREATE TABLE `fpwx_raw_barcode_rows` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) UNSIGNED NOT NULL,
  `row_number` int(11) NOT NULL COMMENT '시트상 실제 엑셀 행 번호(헤더 제외)',
  `sales_code` varchar(100) DEFAULT NULL COMMENT '헤더 인식 실패 시 A열로 폴백',
  `barcode` varchar(100) DEFAULT NULL COMMENT '헤더 인식 실패 시 B열로 폴백',
  `raw_data` longtext NOT NULL COMMENT 'JSON: 원본 행 전체(헤더명=>셀값) - 절대 수정하지 않는 원본 스냅샷',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`),
  KEY `sales_code` (`sales_code`),
  KEY `barcode` (`barcode`),
  CONSTRAINT `fpwx_raw_barcode_rows_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `fpwx_import_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Foodpang Barcode 시트 원본 행 (불변 스냅샷, UPDATE/DELETE 금지)';

CREATE TABLE `fpwx_raw_pms_rows` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) UNSIGNED NOT NULL,
  `row_number` int(11) NOT NULL COMMENT '시트상 실제 엑셀 행 번호(헤더 제외)',
  `sales_code` varchar(100) DEFAULT NULL COMMENT '헤더 인식 실패 시 A열로 폴백',
  `base_code` varchar(100) DEFAULT NULL COMMENT '헤더 인식 실패 시 B열로 폴백',
  `product_name` varchar(255) DEFAULT NULL COMMENT '헤더 인식 실패 시 T열로 폴백',
  `raw_data` longtext NOT NULL COMMENT 'JSON: 원본 행 전체(헤더명=>셀값) - 절대 수정하지 않는 원본 스냅샷',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`),
  KEY `sales_code` (`sales_code`),
  KEY `base_code` (`base_code`),
  CONSTRAINT `fpwx_raw_pms_rows_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `fpwx_import_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Foodpang PMS_스냅샷 시트 원본 행 (불변 스냅샷, UPDATE/DELETE 금지)';

-- ---------------------------------------------------------------------
-- 3) 정규화 테이블 - 최신 배치 기준으로 base_code / sales_code를 병합한 뷰 성격의 마스터
--    (원본 raw 테이블은 그대로 두고, 이 테이블들만 재계산/갱신한다)
-- ---------------------------------------------------------------------
CREATE TABLE `fpwx_base_products` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `base_code` varchar(100) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `sales_code_count` int(11) NOT NULL DEFAULT 0 COMMENT '이 기준코드에 연결된 판매코드(변형) 수 - 2 이상이면 1:N 변형(예외 검토 대상)',
  `last_batch_id` int(11) UNSIGNED DEFAULT NULL COMMENT '이 값을 마지막으로 갱신한 배치',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `fpwx_base_products_code` (`base_code`),
  KEY `last_batch_id` (`last_batch_id`),
  CONSTRAINT `fpwx_base_products_ibfk_1` FOREIGN KEY (`last_batch_id`) REFERENCES `fpwx_import_batches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Foodpang 기준코드(base_code) 정규화 마스터 (raw 스냅샷 기반 파생 데이터)';

CREATE TABLE `fpwx_sales_products` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_code` varchar(100) NOT NULL,
  `base_code` varchar(100) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `last_batch_id` int(11) UNSIGNED DEFAULT NULL COMMENT '이 값을 마지막으로 갱신한 배치',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `fpwx_sales_products_code` (`sales_code`),
  KEY `base_code` (`base_code`),
  KEY `barcode` (`barcode`),
  KEY `last_batch_id` (`last_batch_id`),
  CONSTRAINT `fpwx_sales_products_ibfk_1` FOREIGN KEY (`base_code`) REFERENCES `fpwx_base_products` (`base_code`) ON UPDATE CASCADE,
  CONSTRAINT `fpwx_sales_products_ibfk_2` FOREIGN KEY (`last_batch_id`) REFERENCES `fpwx_import_batches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Foodpang 판매코드(sales_code) 정규화 상품 (Barcode+PMS 스냅샷 병합 결과, raw 기반 파생 데이터)';

-- ---------------------------------------------------------------------
-- 4) 판매코드 -> HKM products 상시 매핑 (핵심 결과 테이블)
-- ---------------------------------------------------------------------
CREATE TABLE `fpwx_hkm_mappings` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_code` varchar(100) NOT NULL,
  `hkm_product_id` int(11) UNSIGNED NOT NULL,
  `match_type` enum('auto_exact','manual') NOT NULL DEFAULT 'manual' COMMENT 'auto_exact: sales_code==base_code 이고 바코드가 HKM products.sku와 정확히 일치',
  `package_type` varchar(50) DEFAULT NULL COMMENT '예: EA(낱개), BOX(박스) - 수동 매핑 시 입력',
  `units_per_sale` decimal(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Foodpang 판매코드 1건당 HKM 상품 환산 수량',
  `confidence_score` decimal(5,2) DEFAULT NULL,
  `status` enum('active','rejected') NOT NULL DEFAULT 'active',
  `mapped_by` int(11) UNSIGNED DEFAULT NULL COMMENT 'NULL이면 자동 매칭, 값이 있으면 수동 매핑한 관리자',
  `mapped_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `fpwx_hkm_mappings_sales_code` (`sales_code`),
  KEY `hkm_product_id` (`hkm_product_id`),
  KEY `mapped_by` (`mapped_by`),
  CONSTRAINT `fpwx_hkm_mappings_ibfk_1` FOREIGN KEY (`hkm_product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fpwx_hkm_mappings_ibfk_2` FOREIGN KEY (`mapped_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='판매코드(sales_code) -> HKM products 상시 매핑 (재업로드 배치가 바뀌어도 유지됨)';

-- ---------------------------------------------------------------------
-- 5) 매칭 후보/점수 + 예외 검토 대기열
-- ---------------------------------------------------------------------
CREATE TABLE `fpwx_match_candidates` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) UNSIGNED NOT NULL,
  `sales_code` varchar(100) NOT NULL,
  `hkm_product_id` int(11) UNSIGNED DEFAULT NULL COMMENT '후보가 없으면 NULL',
  `match_method` varchar(50) NOT NULL COMMENT 'exact_code_and_barcode | exact_barcode_only | name_similarity | none',
  `score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL COMMENT '예: one_to_many_variant, missing_barcode, barcode_not_found, multiple_candidates',
  `status` enum('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `resolved_by` int(11) UNSIGNED DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`),
  KEY `sales_code` (`sales_code`),
  KEY `hkm_product_id` (`hkm_product_id`),
  KEY `status` (`status`),
  CONSTRAINT `fpwx_match_candidates_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `fpwx_import_batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fpwx_match_candidates_ibfk_2` FOREIGN KEY (`hkm_product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='자동 매칭 후보/점수 및 예외 검토 대기열 (1:N 변형, 바코드 누락 등)';

-- ---------------------------------------------------------------------
-- 6) 매핑 변경 이력 (append-only)
-- ---------------------------------------------------------------------
CREATE TABLE `fpwx_mapping_history` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_code` varchar(100) NOT NULL,
  `hkm_product_id` int(11) UNSIGNED DEFAULT NULL,
  `action` varchar(50) NOT NULL COMMENT 'auto_matched | manual_mapped | updated | rejected | exported',
  `old_value` longtext DEFAULT NULL COMMENT 'JSON 스냅샷 (변경 전)',
  `new_value` longtext DEFAULT NULL COMMENT 'JSON 스냅샷 (변경 후)',
  `note` varchar(500) DEFAULT NULL,
  `changed_by` int(11) UNSIGNED DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sales_code` (`sales_code`),
  KEY `hkm_product_id` (`hkm_product_id`),
  KEY `changed_by` (`changed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='매핑 변경 이력 - Append-only, UPDATE/DELETE 금지';

-- ---------------------------------------------------------------------
-- 7) 내보내기(다운로드) 이력
-- ---------------------------------------------------------------------
CREATE TABLE `fpwx_export_logs` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `exported_by` int(11) UNSIGNED DEFAULT NULL,
  `row_count` int(11) NOT NULL DEFAULT 0,
  `filter_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `exported_by` (`exported_by`),
  CONSTRAINT `fpwx_export_logs_ibfk_1` FOREIGN KEY (`exported_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='매핑 내보내기(다운로드) 이력';
