-- ====================================================================
-- 고객 프로필 확장 마이그레이션 (조건부 실행)
-- 파일명: check_and_add_customer_fields.sql
-- 목적: 이미 존재하는 컬럼은 건너뛰고 필요한 컬럼만 추가
-- 작성일: 2025-10-03
-- ====================================================================

USE u622428657_homekmart;

-- 현재 customers 테이블 구조 확인
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'u622428657_homekmart'
  AND TABLE_NAME = 'customers'
ORDER BY ORDINAL_POSITION;

-- ====================================================================
-- 아래 ALTER TABLE 문은 하나씩 실행하세요
-- 이미 존재하는 컬럼은 오류가 발생하므로 해당 부분은 건너뛰면 됩니다
-- ====================================================================

-- 1. address 컬럼 추가 (이미 존재할 경우 이 명령 건너뛰기)
ALTER TABLE customers
ADD COLUMN address TEXT DEFAULT NULL COMMENT 'Complete address (Philippine format - free text)' AFTER phone;

-- 2. latitude 컬럼 추가
ALTER TABLE customers
ADD COLUMN latitude DECIMAL(10,8) DEFAULT NULL COMMENT 'Latitude (Google Maps API integration)' AFTER address;

-- 3. longitude 컬럼 추가
ALTER TABLE customers
ADD COLUMN longitude DECIMAL(11,8) DEFAULT NULL COMMENT 'Longitude (Google Maps API integration)' AFTER latitude;

-- 4. preferred_store_id 컬럼 추가
ALTER TABLE customers
ADD COLUMN preferred_store_id INT(11) DEFAULT NULL COMMENT 'Preferred store ID' AFTER longitude;

-- 5. 인덱스 추가 (이미 존재할 경우 건너뛰기)
ALTER TABLE customers
ADD INDEX idx_preferred_store (preferred_store_id);

-- ====================================================================
-- 최종 확인: 추가된 컬럼들 확인
-- ====================================================================
SELECT
    '최종 확인: customers 테이블 프로필 관련 컬럼' as message,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'u622428657_homekmart'
  AND TABLE_NAME = 'customers'
  AND COLUMN_NAME IN (
      'phone', 'address', 'latitude', 'longitude', 'preferred_store_id'
  )
ORDER BY ORDINAL_POSITION;
