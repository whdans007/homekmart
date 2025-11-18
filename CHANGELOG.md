# Changelog

## [2025-11-18] - 매입 편집 순번 기능 추가

### 추가됨
- 매입 편집 페이지에 순번(sort_order) 기능 추가
- 드래그 앤 드롭으로 매입 항목 순번 변경 가능
- 변경된 순번이 데이터베이스에 자동 저장

### 변경됨
- `purchase_items` 테이블에 `sort_order` 컬럼 추가
- 매입 항목 정렬 순서가 `sort_order` 기준으로 변경

### 보안
- 확정된 매입은 순번 변경 불가능하도록 제한
- 서버측 권한 검증 추가

### 기술 스택
- SortableJS 1.15.0 라이브러리 사용
- AJAX를 통한 실시간 순번 저장

### 데이터베이스 변경
- `purchase_items.sort_order` 컬럼 추가 (INT NOT NULL DEFAULT 0)
- `idx_purchase_sort` 인덱스 추가

### 파일 변경
- `admin/edit_purchase.php` - 메인 기능 구현
- `admin/sql/add_sort_order_to_purchase_items.sql` - 스키마 변경 SQL
