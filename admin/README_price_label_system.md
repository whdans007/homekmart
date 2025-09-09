# 가격표 출력 리스트 관리 시스템

## 🎯 시스템 개요
purchase_management.php와 유사한 형태의 **가격표 출력 작업 관리 시스템**입니다. 가격표 출력 작업을 프로젝트 형태로 저장하고 관리할 수 있습니다.

## 📁 생성된 파일 목록

### 1. 데이터베이스
- `create_price_label_tables.sql` - 테이블 생성 SQL

### 2. 메인 페이지들
- `price_label_lists.php` - 메인 리스트 관리 페이지
- `price_label_project_edit.php` - 프로젝트 생성/편집 페이지
- `price_label_print.php` (수정) - 기존 가격표 출력 + 프로젝트 로드 기능

### 3. API 엔드포인트들
- `ajax_save_price_label_project.php` - 프로젝트 저장 API
- `ajax_load_price_label_project.php` - 프로젝트 로드 API  
- `ajax_delete_price_label_project.php` - 프로젝트 삭제 API

## 🗄️ 데이터베이스 구조

### `price_label_projects` 테이블
```sql
- id (PK) - 프로젝트 ID
- project_name - 프로젝트명
- description - 설명
- store_id - 점포 ID
- created_by - 생성자 ID
- created_at - 생성일
- updated_at - 수정일
```

### `price_label_project_items` 테이블
```sql
- id (PK) - 아이템 ID
- project_id (FK) - 프로젝트 ID
- product_id (FK) - 상품 ID
- quantity - 출력 매수
- remarks - 비고
- created_at - 추가일
```

## 🔄 워크플로우

### 1. 프로젝트 생성
1. `price_label_lists.php`에서 **"가격표만들기"** 버튼 클릭
2. `price_label_project_edit.php`에서 프로젝트 정보 입력
3. 상품 검색하여 추가 (바코드 스캔 지원)
4. 각 상품별 출력 매수 설정
5. **"프로젝트 저장"** 버튼으로 저장

### 2. 프로젝트 관리
- **목록 조회**: `price_label_lists.php`에서 저장된 프로젝트들 확인
- **편집**: 리스트에서 "편집" 버튼 클릭
- **삭제**: 리스트에서 "삭제" 버튼 클릭 (확인 후 삭제)

### 3. 가격표 출력
- **리스트에서 출력**: 프로젝트 행 클릭 또는 "출력" 버튼
- **자동 로드**: `price_label_print.php?project_id=123` 형태로 접근
- **기존 기능**: `price_label_print.php`의 모든 기능 그대로 사용 가능

## ✨ 주요 기능

### 📋 리스트 관리 페이지
- purchase_management.php와 동일한 디자인
- 페이지네이션 지원
- 반응형 테이블 (모바일 대응)
- 프로젝트명, 상품수, 총 출력매수, 작성자, 수정일 표시

### 🛠️ 프로젝트 편집
- 직관적인 상품 검색 (바코드 스캔 지원)
- 실시간 장바구니 업데이트
- 각 상품별 출력 매수 조정
- 비고 입력 기능

### 🔗 기존 시스템 연동
- 기존 `price_label_print.php` 완전 보존
- URL 파라미터로 프로젝트 자동 로드
- 모든 출력 기능 그대로 사용

## 🚀 설치 및 설정

### 1. 데이터베이스 설정
```sql
-- MySQL/MariaDB에서 실행
SOURCE Z:\homekmart\admin\create_price_label_tables.sql;
```

### 2. 권한 설정
- 상품 관리 권한 (`product_management`) 필요
- 기존 가격표 출력 권한과 동일

### 3. 네비게이션 추가 (선택사항)
관리자 메뉴에 "가격표 출력 리스트" 링크 추가:
```php
<a href="price_label_lists.php">가격표 출력 리스트</a>
```

## 💡 사용 시나리오

### 시나리오 1: 정기 세일 가격표
1. "2024년 12월 세일 가격표" 프로젝트 생성
2. 세일 대상 상품들 추가
3. 필요할 때마다 해당 프로젝트로 가격표 출력

### 시나리오 2: 신상품 가격표
1. "신상품 가격표" 프로젝트 생성
2. 신상품들 추가하고 저장
3. 진열 시마다 동일한 프로젝트로 출력

### 시나리오 3: 반복 작업 효율화
1. 자주 출력하는 상품 조합을 프로젝트로 저장
2. 매번 상품을 다시 검색할 필요 없이 바로 출력
3. 작업 시간 단축 및 실수 방지

## 🔒 보안 및 권한

- **점포별 분리**: 각 점포는 자신의 프로젝트만 조회/편집 가능
- **권한 기반**: `product_management` 권한 필요
- **데이터 무결성**: 외래키 제약조건으로 데이터 일관성 보장
- **트랜잭션**: 프로젝트 저장/삭제 시 트랜잭션 사용

## 📱 반응형 지원

- **데스크톱**: 모든 컬럼 표시
- **태블릿**: 부가 정보 일부 숨김
- **모바일**: 핵심 정보만 표시

## 🎨 디자인 특징

- **일관성**: purchase_management.php와 동일한 디자인 언어
- **색상**: 보라색 (Purple) 테마로 구분
- **아이콘**: FontAwesome 아이콘 사용
- **알림**: 토스트 알림으로 사용자 피드백

## 🚨 주의사항

1. **기존 파일 보존**: `price_label_print.php`의 기존 기능은 완전히 보존됨
2. **데이터베이스 백업**: 테이블 생성 전 데이터베이스 백업 권장
3. **권한 확인**: 사용자에게 적절한 권한이 부여되었는지 확인
4. **브라우저 호환성**: 모던 브라우저 (Chrome, Firefox, Safari, Edge) 지원

이제 가격표 출력 작업을 체계적으로 관리할 수 있습니다! 🎉