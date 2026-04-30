-- ===================================================================
-- users 테이블 누락 컬럼 추가 마이그레이션
-- 생성일: 2026-04-15
-- 대상: sunset 데이터베이스
-- 용도: full_name, remember_token, remember_token_expiry, phone 컬럼 추가
-- ===================================================================

-- full_name 컬럼 추가 (없는 경우에만)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(255) DEFAULT NULL COMMENT '사용자 실명' AFTER `username`;

-- remember_token 컬럼 추가 (없는 경우에만)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `remember_token` VARCHAR(255) DEFAULT NULL COMMENT '로그인 유지 토큰' AFTER `is_active`;

-- remember_token_expiry 컬럼 추가 (없는 경우에만)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `remember_token_expiry` DATETIME DEFAULT NULL COMMENT '로그인 유지 토큰 만료일' AFTER `remember_token`;

-- phone 컬럼 추가 (없는 경우에만)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) DEFAULT NULL COMMENT '전화번호' AFTER `email`;

-- 적용 확인용 쿼리
SHOW COLUMNS FROM `users`;
