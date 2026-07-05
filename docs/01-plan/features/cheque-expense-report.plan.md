# Cheque Expense Report — Plan Document

**Feature**: cheque-expense-report
**Date**: 2026-05-12
**Method**: Plan Plus (Brainstorming-Enhanced)
**Reference**: `1. reference/Cheque_Expense_Report.png`

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 수표(Check) 결제 구매 항목을 날짜별로 분류·정리하여 Cheque Expense Report 서식으로 출력하는 기능이 없음. 수표 반품 시 재발행 추적도 불가 |
| **Solution** | Cash Disbursement와 동일한 드래그앤드롭 UI로 수표 구매를 4개 섹션에 분류하고, 각 row에 체크번호 수기 입력 + 반품/재발행 처리 후 A4 가로 서식으로 출력 |
| **UX Effect** | 기존 CD 사용 패턴 그대로 — 날짜 선택 → 드래그 분류 → 체크번호 입력 → 반품 체크(필요시) → Print/Excel |
| **Core Value** | 수표 지출 보고서를 5분 내 완성, 반품-재발행 이력을 보고서에 자동 반영 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 수표 구매 분류 및 Cheque Expense Report 서식 출력 + 반품/재발행 추적 |
| **WHO** | 오피스 스태프 (PREPARED: LIZA, APPROVED: SIR MIN) |
| **RISK** | 체크번호는 DB 저장이 아닌 JSON state에만 저장 — 브라우저 캐시 문제 방지 필요 |
| **SUCCESS** | 참고 이미지와 동일한 서식으로 A4 가로 출력 + 반품 표시 정상 동작 |
| **SCOPE** | office/cheque_expense_report/ 신규 모듈 (6파일) + header.php 수정 |

---

## 1. User Intent Discovery

### 1.1 핵심 문제
수표(Check) 결제 납품 항목을 날짜별(check_issued_date 기준)로 분류하고,
체크번호를 수기로 입력하여 Cheque Expense Report 서식으로 출력.
수표 반품 시 해당 row를 반품 표시하고 새 체크번호로 재발행 처리.

### 1.2 대상 사용자
오피스 스태프 — Cash Disbursement와 동일한 사용자

### 1.3 성공 기준
- 수표 구매가 날짜별로 Source List에 로드됨
- 드래그앤드롭으로 4개 섹션(Korean/Local/Fixed/Others)에 배치
- 각 row에서 Check Number 수기 입력 가능
- 반품 체크 시 row가 취소선 표시 + 새 체크번호 입력란 활성화
- print_cer.php: 참고 이미지와 동일한 A4 가로 서식 출력
- export_cer.php: Excel 다운로드

---

## 2. Reference Image Analysis

```
CHEQUE EXPENSE REPORT                     | PREPARED | APPROVED
(HOME PLUS SUNSET CORPORATION)            |  LISA    | SIR MIN
─────────────────────────────────────────────────────────────────
YEAR: 2026   MONTH: 5   DAY: 8

NO. | CHECK NUMBER | SUPPLIER NAME | DATE | SALES INVOICE | PARTICULAR | AMOUNT
────┼──────────────┼───────────────┼──────┼───────────────┼────────────┼────────
1. KOREAN
  1 | 357446       | JR ESSENTIALS | 5/2  | 20260505...   | CHAPAGETTI | 32,471.50
  2 | 557447       | COMMON GROUND | 5/4  | CG-DR-...     | BINGGRAE   | 113,956.00
  ...
────
2. LOCAL
  7 | 357452       | CLOUD99       | 5/7  | 1007568       | RELX PODS  | 130,000.00
  ...
────
3. FIXED EXPENSE (빈 행 포함)
────
4. OTHERS (빈 행 포함)
────────────────────────────────────────── TOTAL EXPENSES: 409,655.08
Note:
```

**컬럼 정의:**
- NO. : 섹션 내 순번
- CHECK NUMBER : 수기 입력 (DB에서 가져오지 않음)
- SUPPLIER NAME : `office_product_purchases.supplier_name`
- DATE : `check_issued_date`
- SALES INVOICE : `office_receipts.cv_no` (영수증 연결된 경우)
- PARTICULAR : `delivery_content`
- AMOUNT : `amount`

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| FR-01 | `check_issued_date` 기준으로 수표 구매 로드 | Must |
| FR-02 | 드래그앤드롭으로 4개 섹션 분류 | Must |
| FR-03 | 각 row에 Check Number 수기 입력란 | Must |
| FR-04 | DB SAVE 기능 (cer_saved_state 테이블) | Must |
| FR-05 | 반품 체크박스 — 체크 시 취소선 + 새 번호 입력란 활성화 | Must |
| FR-06 | 재발행 번호는 인쇄 시 원래 번호 옆에 표시 | Must |
| FR-07 | A4 가로 출력 (print_cer.php) | Must |
| FR-08 | Excel 내보내기 (export_cer.php) | Must |
| FR-09 | 네비게이션: Cash Disbursement 앞에 위치 | Must |

### 3.2 Non-Functional Requirements
- 기존 CD 기능에 영향 없음
- 체크번호는 state JSON에 저장 (DB 컬럼 추가 없음)

---

## 4. YAGNI Review

### In Scope (v1)
- [x] 드래그앤드롭 세션 분류
- [x] Check Number 수기 입력 (row별)
- [x] 반품 체크 + 새 check number 입력
- [x] DB 저장 (cer_saved_state)
- [x] A4 가로 인쇄
- [x] Excel 내보내기

### Out of Scope (v1 이후)
- 수표 반품 히스토리 별도 화면
- 체크번호 중복 검사
- 수표 만기일 알림

---

## 5. Technical Design

### 5.1 파일 구조

```
office/
└── cheque_expense_report/
    ├── index.php              # 메인 UI (드래그앤드롭 + 체크번호 입력)
    ├── print_cer.php          # A4 가로 인쇄
    ├── export_cer.php         # SpreadsheetML Excel
    ├── ajax_load_cheques.php  # 수표 구매 로드 + 저장 상태 복원
    ├── ajax_save_cer.php      # 상태 JSON 저장
    └── sql/
        └── create_cer_state.sql
```

### 5.2 DB 테이블

```sql
CREATE TABLE IF NOT EXISTS cer_saved_state (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  save_date  DATE NOT NULL,
  state_json MEDIUMTEXT NOT NULL COMMENT '섹션별 row 배치 + check_no + returned 상태',
  saved_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cer (store_id, save_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.3 Row 데이터 구조

```json
{
  "id": "row_1",
  "item_id": "p_5",
  "check_no": "357446",
  "date": "2026-05-02",
  "supplier": "JR ESSENTIALS CORPORATION",
  "sales_invoice": "20260505000044",
  "particular": "CHAPAGETTI THE BLACK NON FRYING MULTI-PACK ETC.",
  "amount": 32471.50,
  "returned": false,
  "new_check_no": ""
}
```

### 5.4 ajax_load_cheques.php 핵심 쿼리

```sql
SELECT pp.id, pp.supplier_name, pp.delivery_content, pp.amount,
       pp.check_issued_date,
       COALESCE(r.cv_no, '') AS sales_invoice
FROM office_product_purchases pp
LEFT JOIN office_receipts r
  ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
WHERE pp.store_id=? AND pp.payment_type='check'
  AND pp.check_issued_date=?
ORDER BY pp.id
```

### 5.5 인쇄 서식 (A4 가로)

```
용지: A4 landscape (297mm × 210mm), margin: 6mm
컬럼 너비 비율:
  NO.(18pt) | CHECK NO.(55pt) | SUPPLIER(90pt) | DATE(42pt)
  | SALES INVOICE(70pt) | PARTICULAR(100pt) | AMOUNT(55pt)

섹션 구분: KOREAN / LOCAL / FIXED EXPENSE / OTHERS (각 고정 행수)
반품 row: 취소선 + "(RE: 새번호)" 표시
TOTAL EXPENSES: 우하단
```

### 5.6 반품/재발행 UI

```
┌────┬──────────┬──────────────────┬──────┬─────────────────┬───────────────────┬──────────┐
│ ≡  │ [357446] │ JR ESSENTIALS    │ 5/2  │ 20260505...     │ CHAPAGETTI...     │ ₱32,471  │
│    │  ☐ 반품  │                  │      │                 │                   │    ×     │
└────┴──────────┴──────────────────┴──────┴─────────────────┴───────────────────┴──────────┘

반품 체크 후:
┌────┬──────────┬──────────────────┬──────┬─────────────────┬───────────────────┬──────────┐
│ ≡  │ ~~357446~│ JR ESSENTIALS    │ 5/2  │ 20260505...     │ CHAPAGETTI...     │ ₱32,471  │
│    │ New: [  ]│  (RETURNED)      │      │                 │                   │    ×     │
└────┴──────────┴──────────────────┴──────┴─────────────────┴───────────────────┴──────────┘
```

---

## 6. 네비게이션 수정

`office/partials/header.php` 수정:

```
기존: ... Equipment Purchase → Sales Report → Cash Disbursement → Employees ...
변경: ... Equipment Purchase → Sales Report → [Cheque Expense] → Cash Disbursement → Employees ...
```

---

## 7. 구현 순서

1. `sql/create_cer_state.sql` 작성
2. `ajax_load_cheques.php` — 수표 구매 로드
3. `ajax_save_cer.php` — 상태 저장
4. `index.php` — 메인 UI (CD 베이스로 수정)
5. `print_cer.php` — A4 가로 인쇄
6. `export_cer.php` — Excel
7. `header.php` — 네비게이션 추가

---

## 8. Brainstorming Log

| 결정 | 이유 |
|------|------|
| CD 코드 베이스 재사용 | 동일 패턴, 학습 비용 없음, 유지보수 일관성 |
| 체크번호 JSON 저장 | DB 스키마 변경 없이 유연하게 관리 |
| 반품 = 같은 row에 플래그 | 별도 row 추가 시 보고서 구조 복잡해짐 |
| A4 가로 인쇄 | 참고 이미지 컬럼이 7개로 세로 출력 시 좁음 |
| 별도 디렉터리 신규 생성 | CD 코드와 완전 분리, 독립적 유지보수 |
