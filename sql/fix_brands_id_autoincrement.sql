-- ================================================
-- brands 테이블 ID AUTO_INCREMENT 수정
-- 작성일: 2025-01-25
-- 목적: brands 테이블의 id 필드를 AUTO_INCREMENT로 설정
-- ================================================

-- 1. 현재 테이블 구조 확인
SHOW CREATE TABLE brands;

-- 2. id 컬럼을 AUTO_INCREMENT로 변경
ALTER TABLE brands 
MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;

-- 3. 현재 최대 ID 값 확인 및 AUTO_INCREMENT 시작값 설정
SELECT MAX(id) FROM brands;

-- 4. AUTO_INCREMENT 시작값을 현재 최대값 + 1로 설정 (필요한 경우)
-- 아래 명령어의 숫자를 실제 MAX(id) + 1 값으로 변경하세요
-- ALTER TABLE brands AUTO_INCREMENT = 100;

-- 5. 변경 확인
SHOW CREATE TABLE brands;