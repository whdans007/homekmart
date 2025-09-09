# 가격표 시스템 배포 노트

## 배포 일시: 2025-09-09

## 주요 변경사항

### 1. 다국어 지원 구현
- **대상 파일**: `price_label_print.php`, `price_label_project_edit.php`
- **변경 내용**: 
  - 하드코딩된 한국어 텍스트를 번역 함수로 변경
  - JavaScript 번역 지원 추가
  - 한국어/영어 동시 지원

### 2. 화폐 단위 제거
- **대상 파일**: `lang/ko.json`, `lang/en.json`, `price_label_project_edit.php`
- **변경 내용**:
  - "원", "KRW" 화폐 단위 완전 제거
  - 가격은 숫자만 표시
  - JavaScript 코드에서 currency 참조 제거

### 3. 새로운 가격표 관리 시스템 추가
- **새 파일들**:
  - `price_label_lists.php` - 가격표 프로젝트 목록
  - `price_label_project_edit.php` - 가격표 프로젝트 편집
  - `ajax_*.php` 파일들 - AJAX 처리
  - `create_price_label_tables.sql` - 데이터베이스 테이블 생성 스크립트

## 배포 대상 파일 목록

### 수정된 기존 파일
- `admin/ajax_search_price_label_products.php`
- `admin/partials/header.php`
- `admin/price_label_print.php`
- `lang/en.json`
- `lang/ko.json`

### 새로 추가된 파일
- `admin/price_label_lists.php`
- `admin/price_label_project_edit.php`
- `admin/ajax_delete_price_label_project.php`
- `admin/ajax_load_price_label_project.php`
- `admin/ajax_save_price_label_project.php`
- `admin/create_price_label_tables.sql`
- `admin/README_price_label_system.md`

## 데이터베이스 변경사항

### 새로운 테이블 생성 필요
```sql
-- price_label_projects 테이블
-- price_label_project_items 테이블
```
**중요**: `admin/create_price_label_tables.sql` 파일을 실행하여 새 테이블을 생성해야 합니다.

## 배포 전 확인사항

### 1. 기능 테스트
- [ ] 가격표 출력 기능 정상 동작
- [ ] 다국어 전환 테스트 (한국어/영어)
- [ ] 가격표 프로젝트 생성/편집/삭제
- [ ] 상품 검색 및 추가 기능
- [ ] 바코드 스캐너 입력 테스트

### 2. 권한 확인
- [ ] `product_management` 권한 필요
- [ ] 관리자 및 직원 접근 권한 확인

### 3. 브라우저 호환성
- [ ] Chrome, Firefox, Safari 테스트
- [ ] 모바일 반응형 디자인 확인

## 배포 후 모니터링 항목
- 가격표 출력 성능
- 다국어 표시 정확성
- AJAX 요청 응답 시간
- 데이터베이스 쿼리 성능

## 롤백 계획
변경사항이 많으므로 배포 전 현재 상태의 백업을 권장합니다.
- 데이터베이스 백업
- 파일 시스템 백업

## 연락처
배포 관련 문제 발생 시 개발팀에 연락바랍니다.