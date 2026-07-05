# Plan: Expense Report 통합 (expense-report-unified)

**Date**: 2026-05-13
**Status**: Planning

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | Product Purchase, Equipment Purchase가 별도 페이지로 관리되어 Expense Report 작성 시 3개 메뉴를 왔다갔다 해야 함 |
| Solution | Expense Report에서 직접 항목 Add/Edit/Delete 가능하게 통합. Source List에서 Add 후 드래그앤드랍으로 섹션 배치 |
| UX Effect | 매일 경비 보고서 작성 시 Expense Report 한 화면에서 모든 작업 완결 |
| Core Value | 업무 흐름 단순화: 입력 → 분류 → 저장이 한 화면에서 완결 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| WHY | 경비 입력 → Expense Report 배치가 현재 3개 페이지를 왔다갔다 해야 하는 불편 해소 |
| WHO | Office staff (매일 경비 기록), super_admin/admin (조회/수정) |
| RISK | 기존 Product/Equipment Purchase 데이터와 연동 유지 필요. 다른 기능(Receipt 연동, Cheque Expense Report, Deferred Tracker)이 같은 테이블 참조함 |
| SUCCESS | Expense Report 한 화면에서 Add/Edit/Delete 후 즉시 드래그앤드랍 배치 가능 |
| SCOPE | expense_report/, product_purchase/, equipment_purchase/ 코드 수정. DB 테이블 구조 변경 없음 |

---

## 1. 현재 구조 분석

### 1.1 데이터 흐름 (현재)

```
[Product Purchase 페이지]  →  office_product_purchases 테이블
                                        ↓
[Equipment Purchase 페이지] →  office_equipment_purchases 테이블
                                        ↓
                           [Expense Report] ajax_load_items.php 로 읽음
                           → Source List 표시 → 드래그앤드랍 → 4개 섹션 배치
                           → er_saved_state 테이블에 배치 상태 저장
```

### 1.2 현재 문제점

| # | 문제 | 영향 |
|---|------|------|
| P1 | Expense Report에 Add 버튼 없음 | 항목 추가 시 다른 페이지로 이동 필요 |
| P2 | Source List 항목 Edit/Delete 불가 | 오타/오입력 시 다른 페이지로 이동 필요 |
| P3 | 섹션 배치 후 금액·내용 수정 불가 | 수정 후 다시 드래그앤드랍 해야 함 |
| P4 | 네비게이션에 3개 메뉴 노출 | 어디서 입력해야 하는지 혼란 |

### 1.3 유지해야 할 것

- `office_product_purchases`, `office_equipment_purchases` 테이블 구조 변경 없음
- Receipt 연동 (`linked_purchase_type`, `linked_purchase_id`) 그대로 유지
- Cheque Expense Report, Deferred Tracker 등 다른 모듈이 참조하는 데이터 유지
- Product Purchase, Equipment Purchase 페이지 파일은 유지 (다른 참조 가능)

---

## 2. 요구사항

### 2.1 핵심 기능 (Must)

| ID | 기능 | 설명 |
|----|------|------|
| F1 | Source List에서 직접 Add | Source List 상단 "+" 버튼 → 모달로 Product/Equipment 항목 추가 |
| F2 | Source List 항목 Edit | 카드에 Edit 아이콘 → 모달로 수정 (supplier, details, amount, date, payment_type) |
| F3 | Source List 항목 Delete | 카드에 Delete 아이콘 → 확인 후 DB 삭제, Source List에서 제거 |
| F4 | 네비게이션 정리 | Product Purchase, Equipment Purchase를 nav에서 숨김 (Expense Report로 통합) |
| F5 | 섹션 배치 항목 Edit | 배치된 행에서도 Edit 가능 (amount, supplier, details 수정) |

### 2.2 보조 기능 (Should)

| ID | 기능 | 설명 |
|----|------|------|
| F6 | 타입 자동 섹션 매핑 | Add 시 payment_type으로 auto_section 자동 결정 (cash→selling, check→check_sup, equipment→not_selling) |
| F7 | Add 후 즉시 Source List 반영 | 저장 후 페이지 새로고침 없이 AJAX로 Source List에 추가 |

### 2.3 범위 외 (Won't)

- DB 테이블 통합/마이그레이션
- 기존 Product/Equipment Purchase 페이지 삭제
- Receipt 연동 로직 변경

---

## 3. 구현 범위

### 3.1 수정 파일

| 파일 | 변경 내용 |
|------|-----------|
| `expense_report/index.php` | Source List에 Add/Edit/Delete 버튼 추가, 모달 HTML 추가 |
| `expense_report/ajax_add_item.php` | 신규: Add 처리 (product/equipment 구분하여 INSERT) |
| `expense_report/ajax_edit_item.php` | 신규: Edit 처리 (UPDATE) |
| `expense_report/ajax_delete_item.php` | 신규: Delete 처리 (DELETE + er_saved_state에서도 제거) |
| `office/partials/header.php` | Product Purchase, Equipment Purchase 메뉴 숨김 |

### 3.2 수정하지 않는 파일

- `product_purchase/`, `equipment_purchase/` — 파일 유지 (직접 URL 접근 가능)
- `ajax_load_items.php` — 쿼리 로직 유지
- `ajax_save_er.php` — 저장 로직 유지
- DB 테이블 — 변경 없음

---

## 4. 모달 UI 설계

### 4.1 Add 모달

```
┌─────────────────────────────┐
│  Add Expense Item           │
│─────────────────────────────│
│  Type:   [Product ▼]        │  → product / equipment
│  Payment:[Cash    ▼]        │  → cash / check (Product일 때만)
│  Date:   [2026-05-13]       │
│  Supplier: [____________]   │
│  Details:  [____________]   │
│  Amount:   [____________]   │
│─────────────────────────────│
│  [Cancel]        [Add]      │
└─────────────────────────────┘
```

### 4.2 Edit 모달

- Add 모달과 동일한 폼, 기존 값 pre-fill
- Type(product/equipment) 변경 불가 (다른 테이블이므로)

---

## 5. AJAX API 설계

### POST ajax_add_item.php
```
Input:  type, payment_type, date, supplier_name, delivery_content, amount, store_id
Output: { success, item: { id, type, supplier, details, amount, date, cv_no, auto_section } }
```

### POST ajax_edit_item.php
```
Input:  item_id (e.g. "p_123", "e_456"), supplier_name, delivery_content, amount, date, payment_type
Output: { success }
```

### POST ajax_delete_item.php
```
Input:  item_id (e.g. "p_123", "e_456"), date
Output: { success }
Side effect: er_saved_state에서도 해당 item_id 제거
```

---

## 6. 성공 기준

| 기준 | 측정 방법 |
|------|-----------|
| Expense Report 한 화면에서 항목 Add 가능 | 모달로 추가 후 Source List에 즉시 나타남 |
| Source List에서 Edit/Delete 가능 | DB 반영 및 Source List 즉시 업데이트 |
| 네비게이션에 Product/Equipment Purchase 미노출 | header.php 확인 |
| 기존 데이터 연동 유지 | Cheque Expense Report, Receipt 연동 정상 동작 |

---

## 7. 리스크

| 리스크 | 대응 |
|--------|------|
| Delete 시 er_saved_state에 item_id가 남아있으면 빈 행 표시 | ajax_delete_item.php에서 er_saved_state JSON도 정리 |
| Receipt가 연결된 항목 삭제 | 삭제 전 linked_purchase_id 체크, 연결된 경우 삭제 불가 안내 |
| item_id 파싱 ("p_123" → type + id) | ajax 파일에서 prefix 파싱 로직 공통화 |
