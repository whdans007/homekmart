-- =====================================================================
-- drop_mall_home_sections.sql
-- 홈 레이아웃 빌더(관리자 섹션 편집 UI) 폐기에 따른 테이블 제거.
-- mall/admin/home_layout.php 및 관련 ajax/lib 파일들을 삭제하면서
-- 홈 화면은 고정 레이아웃 코드로 대체했다. 적용은
-- sql/run_drop_mall_home_sections_migration.php로 실행할 것.
-- =====================================================================

DROP TABLE IF EXISTS `mall_home_sections`;
