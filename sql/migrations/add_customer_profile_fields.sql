-- ====================================================================
-- 고객 프로필 확장 마이그레이션 (필리핀 주소 체계)
-- 파일명: add_customer_profile_fields.sql
-- 목적: 모바일 앱 개발을 위한 고객 주소 및 위치 정보 추가
-- 작성일: 2025-10-02
-- 수정일: 2025-10-03 (필리핀 주소 체계 반영)
-- ====================================================================

USE u622428657_homekmart;

-- 1. 주소 정보 컬럼 추가 (필리핀 주소 체계 - TEXT 타입)
ALTER TABLE customers
ADD COLUMN address TEXT DEFAULT NULL COMMENT 'Complete address (Philippine format - free text)' AFTER phone;

-- 2. 위치 좌표 컬럼 추가 (구글맵 연동 대비)
ALTER TABLE customers
ADD COLUMN latitude DECIMAL(10,8) DEFAULT NULL COMMENT 'Latitude (Google Maps API integration)' AFTER address;

ALTER TABLE customers
ADD COLUMN longitude DECIMAL(11,8) DEFAULT NULL COMMENT 'Longitude (Google Maps API integration)' AFTER latitude;

-- 3. 이용점포 컬럼 추가
ALTER TABLE customers
ADD COLUMN preferred_store_id INT(11) DEFAULT NULL COMMENT 'Preferred store ID' AFTER longitude;

-- 4. 인덱스 추가 (성능 최적화)
ALTER TABLE customers
ADD INDEX idx_preferred_store (preferred_store_id);

-- 5. 외래키 제약조건 추가
-- 외래키 제약조건은 선택사항입니다. 오류 발생 시 이 부분을 주석처리하고 진행하세요.
-- stores 테이블과 customers 테이블의 엔진이 모두 InnoDB여야 합니다.
ALTER TABLE customers
ADD CONSTRAINT fk_customers_preferred_store
FOREIGN KEY (preferred_store_id)
REFERENCES stores(id)
ON DELETE SET NULL
ON UPDATE CASCADE;

-- 6. 마이그레이션 완료 확인
SELECT
    'customers 테이블 구조 확인' as message,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'u622428657_homekmart'
  AND TABLE_NAME = 'customers'
  AND COLUMN_NAME IN (
      'address', 'latitude', 'longitude', 'preferred_store_id'
  )
ORDER BY ORDINAL_POSITION;

-- 마이그레이션 완료
SELECT '✅ customers 테이블 프로필 확장 완료 (Philippine address format)!' as status;
