# Changelog

## [2025-11-18] - 물류바코드 스캔 기능 추가

### 추가됨
- 박스 물류바코드 스캔으로 상품 자동 검색 기능
- 상품명/바코드 통합 검색 입력 필드 (자동 감지)
- ITF-14 바코드 형식 지원 (14자리 → 13자리 EAN-13 변환)
- 다양한 물류바코드 패턴 지원 (GS1-128, 접두사/접미사 패턴)
- 동일 상품 재스캔 시 수량 자동 증가 기능

### 변경됨
- 신규매입 페이지 검색 UI 개선 (통합 검색 필드)
- 바코드 패턴 자동 감지 및 검색 방식 전환
- 2단계 검색 전략 (정확 매칭 → LIKE 패턴 매칭)
- 중복 상품 등록 시 확인 대신 수량 자동 증가

### 수정됨
- 매입 등록 시 store_id 외래 키 제약 조건 오류 수정
- purchases 테이블 INSERT 시 store_id 필드 추가 (created_by는 선택적)
- super_admin 사용자 점포 미할당 시 첫 번째 점포 자동 설정 (동적 조회)
- 헤더의 current_store_id를 폴백으로 사용하여 점포 정보 누락 방지
- created_by 컬럼 누락 오류 해결 (선택적 필드로 처리)
- updateRowTotal 함수 정의 누락 오류 수정 (updateRow의 별칭으로 추가)
- store_id 검증 강화 및 폴백 로직 구현
- 프로덕션 배포를 위한 디버깅 로그 제거 완료

### 기술 사항
- 바코드 추출 알고리즘: ITF-14 첫 자리 제거하여 EAN-13 추출
- 체크 디지트 변형 대응: 앞 12자리 기준 LIKE 검색
- PDO 준비된 구문으로 SQL 인젝션 방지

### 파일 변경
- `admin/ajax_parse_logistics_barcode.php` - 물류바코드 파싱 AJAX 엔드포인트 (신규)
- `admin/add_purchase.php` - 통합 검색 UI 및 바코드 자동 감지 로직, 점포 정보 처리 개선
- `admin/edit_purchase.php` - 빠른 스캔 기능 유지
- `admin/sql/add_created_by_to_purchases.sql` - created_by 컬럼 추가 마이그레이션 (선택적)

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
