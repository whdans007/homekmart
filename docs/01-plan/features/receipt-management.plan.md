# Receipt Management — 영수증 관리

## Executive Summary

| 관점 | 내용 |
|------|------|
| **문제** | 상품구매·비품구매 지출 등록 전에 모든 비용이 영수증과 함께 발생하나, 영수증을 별도로 보관·관리하는 시스템이 없어 지출 등록 시 정보를 다시 입력해야 함 |
| **솔루션** | 영수증 먼저 등록(업체명·내용·금액·파일첨부) 후, 구매 지출 등록 시 '영수증에서 불러오기'로 자동 채움. 사용된 영수증은 자동으로 '등록됨' 상태로 전환되어 선택 목록에서 제외. |
| **UX 효과** | 이중 입력 제거, 영수증 디지털 보관, 지출 증빙 일원화, 미처리 영수증 누락 방지 |
| **핵심 가치** | 영수증 등록 → 지출 연결의 단방향 흐름으로 증빙 누락 방지. 1영수증 = 1지출 원칙 |

---

## 1. 기능 목적 (Intent Discovery)

- **핵심 문제**: 지출 등록 전 영수증 증빙 관리 체계가 없음
- **대상 사용자**: 점포 관리자 (office 시스템 사용자)
- **성공 기준**:
  1. 영수증을 먼저 등록하고 파일을 첨부할 수 있다
  2. 상품구매·비품구매 등록 시 영수증에서 업체명·금액을 자동으로 불러온다
  3. 미등록 업체를 영수증 등록 중 바로 추가할 수 있다
  4. 지출에 사용된 영수증은 '등록됨' 상태로 표시되며 불러오기 목록에 나타나지 않는다
  5. 지출이 삭제되면 연결된 영수증은 다시 '미사용' 상태로 복원된다

---

## 2. 탐색한 대안 (Alternatives Explored)

| 방식 | 설명 | 결정 |
|------|------|------|
| **A: 영수증 독립 모듈** | 영수증을 별도 메뉴에서 관리, 구매 등록 시 불러오기 | ✅ **채택** |
| B: 구매 화면에 첨부 통합 | 기존 구매 폼에 파일 첨부만 추가 | 흐름 지원 안 됨 |
| C: 문서 유형별 관리 | 분류·태그·대시보드 포함 | 과도한 복잡도 |

---

## 3. YAGNI 검토 (1차 버전 범위)

### 포함
- 영수증 CRUD (목록, 등록, 수정, 삭제)
- 파일 첨부 (JPG, PNG, PDF 업로드 및 보기)
- 업체 인라인 등록 (admin의 suppliers 자동완성, 미등록 시 텍스트 직접 입력)
- '영수증에서 불러오기' 연동 (모달 검색 → 자동 채움, **미사용 영수증만 표시**)
- 영수증 사용 상태 추적 (미사용 / 등록됨) + 지출 삭제 시 자동 복원

### 제외 (Out of Scope)
- 영수증 유형 분류 (배달/구매/세금계산서)
- 월별 지출 요약 대시보드
- 승인 워크플로우
- OCR 자동 인식

---

## 4. 기술 설계

### 4.1 데이터베이스

```sql
CREATE TABLE office_receipts (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  store_id            INT NOT NULL,
  supplier_name       VARCHAR(255) NOT NULL,     -- 업체명 (텍스트, 기존 방식 일치)
  description         TEXT,                      -- 내용
  amount              DECIMAL(12,2) NOT NULL,    -- 금액
  receipt_date        DATE NOT NULL,             -- 영수증 날짜
  file_path           VARCHAR(500) NULL,         -- 서버 저장 경로
  file_original_name  VARCHAR(255) NULL,         -- 원본 파일명
  file_mime           VARCHAR(100) NULL,         -- image/jpeg, application/pdf 등
  notes               TEXT NULL,                 -- 추가 메모
  -- 사용 상태 추적
  linked_purchase_type ENUM('product','equipment') NULL,  -- 연결된 지출 유형
  linked_purchase_id   INT NULL,                 -- 연결된 지출 PK (NULL = 미사용)
  used_at             TIMESTAMP NULL,            -- 사용(연결)된 시각
  created_by          INT NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, receipt_date),
  INDEX idx_unused (store_id, linked_purchase_id)  -- 미사용 필터링 최적화
);
```

**사용 상태 규칙**:
- `linked_purchase_id IS NULL` → 미사용 (불러오기 목록에 표시)
- `linked_purchase_id IS NOT NULL` → 등록됨 (불러오기 목록에서 제외, 영수증 목록에 '등록됨' 배지 표시)
- 1영수증 = 1지출만 연결 가능 (단일 FK)

**업체명 처리**: `supplier_name`은 텍스트로 저장 (기존 `office_product_purchases`, `office_equipment_purchases`와 동일). 등록 시 admin `suppliers` 테이블에서 자동완성 제공.

### 4.2 파일 구조

```
신규 파일
─────────────────────────────────────────
office/receipts/list.php      영수증 목록 (점포별)
office/receipts/add.php       영수증 등록 (파일 업로드 포함)
office/receipts/edit.php      영수증 수정
office/receipts/delete.php    영수증 삭제
office/receipts/view.php      첨부파일 보기 (이미지/PDF)
office/ajax_search_receipts.php   불러오기용 AJAX (미사용 영수증만 반환)
office/sql/create_receipts.sql    DB 마이그레이션

수정 파일
─────────────────────────────────────────
office/equipment_purchase/add.php  → '영수증에서 불러오기' 버튼 + 모달 추가
office/product_purchase/add.php    → '영수증에서 불러오기' 버튼 + 모달 추가

파일 저장 경로
─────────────────────────────────────────
uploads/receipts/YYYY/MM/{uniqid}.{ext}
```

### 4.3 사용자 흐름

```
[영수증 등록]
  1. office/receipts/add.php 접속
  2. 업체명 입력 (admin suppliers 자동완성 → 없으면 텍스트 입력)
  3. 내용, 금액, 날짜 입력
  4. 파일 첨부 (선택)
  5. 저장 → 목록으로 이동 (상태: 미사용)

[상품구매/비품구매 등록]
  1. 기존 add.php 접속
  2. '영수증에서 불러오기' 버튼 클릭
  3. 모달: 날짜 범위·업체명 검색 → 미사용 영수증만 표시
  4. 선택 → 거래처명, 금액 자동 채움 (선택한 receipt_id를 hidden 필드에 저장)
  5. 저장 시 → office_receipts.linked_purchase_id 업데이트 (상태: 등록됨)

[영수증 목록에서 상태 표시]
  - 미사용: 기본 표시
  - 등록됨: '등록됨' 배지 + 연결된 지출 유형 표시 (상품구매/비품구매)

[지출 삭제 시 자동 복원]
  - 상품구매/비품구매 delete.php에서 삭제 전
    office_receipts WHERE linked_purchase_type=? AND linked_purchase_id=?
    → linked_purchase_id=NULL, used_at=NULL 으로 초기화
```

### 4.4 보안

- 파일 업로드: 허용 MIME 타입 화이트리스트 (image/jpeg, image/png, application/pdf)
- 파일명 난수화 (uniqid 사용, 원본명은 DB에 별도 저장)
- 업로드 디렉토리 직접 접근 차단 (.htaccess)
- office 권한 확인 (`require_office_permission()`)

---

## 5. 브레인스토밍 로그

| 결정 | 근거 |
|------|------|
| office/ 하위에 영수증 모듈 배치 | 사용 주체가 office 사용자이므로 admin과 분리 |
| supplier_name 텍스트 저장 | 기존 purchase 테이블과 일관성 유지 |
| suppliers 자동완성만 제공 | 인라인 suppliers DB 등록은 복잡도 증가 → 자동완성으로 편의 제공 |
| 모달 방식 불러오기 | UX상 페이지 이동 없이 영수증 검색·선택 가능 |
| 1영수증 = 1지출 원칙 | 하나의 영수증이 여러 지출에 중복 사용되는 혼선 방지 |
| 지출 삭제 시 영수증 복원 | 지출 삭제 후 영수증이 다시 선택 가능해야 재처리 가능 |

---

## 6. 구현 순서

1. DB 마이그레이션 (`office/sql/create_receipts.sql`)
2. 영수증 CRUD (`office/receipts/`)
3. 파일 업로드 처리 (add.php POST 핸들러)
4. AJAX 검색 (`ajax_search_receipts.php`, 미사용 필터 포함)
5. 불러오기 모달 (`equipment_purchase/add.php`, `product_purchase/add.php` 수정)
6. 지출 저장 시 영수증 상태 업데이트 (linked_purchase_id 세팅)
7. 지출 삭제 시 영수증 복원 (`equipment_purchase/delete.php`, `product_purchase/delete.php` 수정)

---

## 7. 완료 기준

- [ ] 영수증 등록·수정·삭제·목록 동작
- [ ] JPG/PNG/PDF 첨부 및 보기 동작
- [ ] 업체명 입력 시 suppliers 자동완성 동작
- [ ] 상품구매 등록 시 영수증 불러오기 → 자동 채움 동작
- [ ] 비품구매 등록 시 동일 동작
- [ ] 파일 보안 (허용 타입만, 난수 파일명)
- [ ] 사용된 영수증은 불러오기 모달에 표시되지 않음
- [ ] 영수증 목록에 '미사용'/'등록됨' 상태 배지 표시
- [ ] 지출 저장 시 연결된 영수증 상태 자동 업데이트
- [ ] 지출 삭제 시 영수증 상태 자동 복원 (미사용으로)

### 추가된 수정 파일
- [ ] `office/equipment_purchase/delete.php` → 삭제 전 영수증 복원 처리
- [ ] `office/product_purchase/delete.php` → 삭제 전 영수증 복원 처리
