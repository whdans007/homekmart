---
template: design
version: 1.3
---

# wholesale-sales-return Design Document

> **Summary**: 도매판매 반품(전체/부분) 기능을 위한 DB 스키마, AJAX API, UI 설계
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: Claude (PDCA)
> **Date**: 2026-07-09
> **Status**: Draft
> **Planning Doc**: [wholesale-sales-return.plan.md](../01-plan/features/wholesale-sales-return.plan.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 도매판매 반품을 기록/처리할 방법이 없어 삭제로만 대응 중 → 데이터 유실 및 재고/미수금 부정확 |
| **WHO** | 도매판매 권한(`wholesale_management`)을 가진 관리자/직원, 각 점포 스코프 사용자 |
| **RISK** | 재고 이중 복원, 반품 수량이 판매 수량을 초과하는 데이터 정합성 오류 |
| **SUCCESS** | 품목별 부분 반품과 전체 반품이 모두 정확히 처리되고, 재고/금액/미수금이 반품량만큼 정확히 반영됨 |
| **SCOPE** | `wholesale_sales.php`(수정 로직 보호), `wholesale_sale_preview.php`(반품 UI 신규), `wholesale_sales_list.php`(상태 표시), 신규 AJAX 엔드포인트, DB 스키마 변경 |

---

## 1. Overview

### 1.1 Design Goals

- 품목별 부분 반품과 판매 건 전체 반품을 하나의 UI/API로 지원
- 반품 처리는 단일 트랜잭션으로 재고 복원 + 금액 차감 + 이력 기록을 원자적으로 수행
- 기존 프로젝트 관행(절차적 PHP 페이지 + `ajax_*.php` 패턴, PDO 트랜잭션)을 그대로 따름 — 신규 추상화 계층(서비스/헬퍼 클래스) 도입하지 않음
- 이미 반품된 품목이 판매 수정(edit) 로직에 의해 삭제/축소되지 않도록 앱 레벨 + DB 레벨(FK RESTRICT) 이중 보호

### 1.2 Design Principles

- **기존 패턴 재사용**: `wholesale_sale_preview.php`의 `action=delete`/`mark_paid` POST 처리 패턴과 동일한 권한·점포 스코프 검증 구조 사용
- **단일 트랜잭션 원자성**: 반품 기록, 수량 갱신, 재고 갱신, 금액 갱신이 하나의 `PDO::beginTransaction()` 안에서 수행되고 실패 시 전체 롤백
- **감사 가능성(Auditability)**: 반품 헤더/품목을 별도 테이블에 스냅샷으로 저장하여 "무엇을 얼마에 반품했는지" 영구 기록

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 폼 재제출(페이지 리로드) | 헬퍼 라이브러리 + AJAX | AJAX 엔드포인트 격리, 헬퍼 없음 |
| **New Files** | 1 | 4 | 2 |
| **Modified Files** | 3 | 3 | 3 |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Medium | High | High |
| **Effort** | Low | High | Medium |
| **Risk** | Low (UX 저하) | Low (관행 이탈) | Low |
| **Recommendation** | Quick wins | 타 채널 확장 시 | **Default choice** |

**Selected**: **Option C** — **Rationale**: 프로젝트 전반이 `ajax_*.php` 파일 단위로 트랜잭션을 격리하는 절차적 패턴을 사용하며 서비스/헬퍼 레이어가 없음. Option C는 이 관행을 유지하면서 다품목 부분 반품에 필요한 모달 기반 UX를 AJAX로 구현.

> 아래 상세 설계는 Option C 기준으로 작성됨.

### 2.1 Component Diagram

```
┌──────────────────────────┐     ┌──────────────────────────────┐     ┌─────────────┐
│ wholesale_sale_preview.php│────▶│ ajax_wholesale_sale_return.php│────▶│   MySQL     │
│  (반품 모달 UI, JS fetch) │     │  (PDO 트랜잭션, 검증, 반영)    │     │ (5개 테이블) │
└──────────────────────────┘     └──────────────────────────────┘     └─────────────┘
          │
          ▼
┌──────────────────────────┐
│ wholesale_sales_list.php │  (return_status 배지 표시, READ only)
└──────────────────────────┘
          │
          ▼
┌──────────────────────────┐
│    wholesale_sales.php    │  (edit 모드에서 반품된 품목 축소/삭제 차단)
└──────────────────────────┘
```

### 2.2 Data Flow

```
[반품 모달 오픈] → [품목별 반품 가능 수량 표시 (quantity - returned_quantity)]
    → [사용자 입력: 품목별 반품 수량 or "전체 반품" 버튼]
    → [클라이언트 검증: 0 < 입력수량 ≤ 반품가능수량]
    → [POST ajax_wholesale_sale_return.php]
    → [서버: 점포 스코프 검증 → SELECT ... FOR UPDATE로 잠금 → 수량 재검증]
    → [INSERT wholesale_sale_returns/return_items]
    → [UPDATE wholesale_sale_items.returned_quantity]
    → [등록 상품(product_id NOT NULL)만: UPDATE inventory.stock_quantity += 반품수량×환산계수]
    → [UPDATE wholesale_sales.total_amount/final_amount/returned_amount/return_status]
    → [COMMIT] → [JSON 응답] → [화면 새로고침/부분 갱신]
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `ajax_wholesale_sale_return.php` | `config/db_config.php`, `lib/permission_helper.php`, `lib/session_helper.php` | DB 연결, 권한/점포 스코프 검증 (기존 페이지와 동일 require 패턴) |
| 재고 환산 로직 | `products.pieces_per_box` | `add_purchase.php`의 박스→낱개 환산 로직(`quantity × pieces_per_box`)과 동일 공식 재사용 |

---

## 3. Data Model

### 3.1 Database Schema (MySQL)

```sql
-- 1) wholesale_sales 확장 (반품 누적액/상태)
ALTER TABLE `wholesale_sales`
  ADD COLUMN `returned_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '누적 반품 금액' AFTER `final_amount`,
  ADD COLUMN `return_status` ENUM('none','partial','full') NOT NULL DEFAULT 'none' COMMENT '반품 상태' AFTER `returned_amount`;

-- 2) wholesale_sale_items 확장 (품목별 누적 반품 수량)
ALTER TABLE `wholesale_sale_items`
  ADD COLUMN `returned_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '누적 반품 수량' AFTER `quantity`;

-- 3) 반품 헤더 테이블 (신규)
CREATE TABLE `wholesale_sale_returns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `sale_id` INT(11) NOT NULL COMMENT '원 판매 ID',
  `reason` VARCHAR(255) DEFAULT NULL COMMENT '반품 사유',
  `total_amount` DECIMAL(12,2) NOT NULL COMMENT '이 반품 건의 총 금액',
  `processed_by` INT(11) UNSIGNED NOT NULL COMMENT '처리자 user_id', -- users.id가 UNSIGNED이므로 FK 타입 일치 필요
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_wsr_sale` (`sale_id`),
  CONSTRAINT `wsr_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `wholesale_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wsr_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매판매 반품 헤더';

-- 4) 반품 품목 테이블 (신규)
CREATE TABLE `wholesale_sale_return_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `return_id` INT(11) NOT NULL COMMENT '반품 헤더 ID',
  `sale_item_id` INT(11) NOT NULL COMMENT '원 판매 품목 ID',
  `quantity` DECIMAL(10,2) NOT NULL COMMENT '반품 수량',
  `unit_price` DECIMAL(10,2) NOT NULL COMMENT '반품 시점 단가 스냅샷 (원 판매 단가)',
  `amount` DECIMAL(12,2) NOT NULL COMMENT '반품 금액 (quantity × unit_price)',
  `restocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '재고 복원 여부 (수기 품목=0)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_wsri_return` (`return_id`),
  INDEX `idx_wsri_sale_item` (`sale_item_id`),
  CONSTRAINT `wsri_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `wholesale_sale_returns` (`id`) ON DELETE CASCADE,
  -- RESTRICT: 반품 이력이 있는 판매 품목은 삭제 불가 (wholesale_sales.php 수정 로직의 delete-and-reinsert로부터 보호, FR-09 DB 레벨 안전장치)
  CONSTRAINT `wsri_ibfk_2` FOREIGN KEY (`sale_item_id`) REFERENCES `wholesale_sale_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매판매 반품 품목';
```

**마이그레이션 파일**: `admin/sql/add_wholesale_sale_return_schema.sql` (기존 `admin/sql/*.sql` 마이그레이션 파일 관행과 동일 위치, `run_wholesale_manual_entry_migration.php`처럼 웹에서 1회 실행하는 러너 스크립트도 함께 생성)

### 3.2 Entity Relationships

```
[wholesale_sales] 1 ──── N [wholesale_sale_items] (기존)
      │
      └── 1 ──── N [wholesale_sale_returns]
                        │
                        └── 1 ──── N [wholesale_sale_return_items] ──── N:1 [wholesale_sale_items]
```

### 3.3 재고 환산 규칙

`add_purchase.php`의 기존 환산 공식을 그대로 재사용한다:

```
restock_quantity(pieces) =
    sale_unit === 'box'
        ? return_quantity × products.pieces_per_box  (fallback: pieces_per_box <= 0 → 1)
        : return_quantity   // sale_unit === 'piece'
```

- `product_id IS NULL`(수기 입력 품목)인 경우 재고 대상이 없으므로 `restocked = 0`으로 기록하고 재고 UPDATE를 건너뜀
- 대상 `inventory` 행이 없으면(`store_id`+`product_id` 조합) `INSERT ... ON DUPLICATE KEY UPDATE`로 생성

---

## 4. API Specification

### 4.1 Endpoint List

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| POST | `admin/ajax_wholesale_sale_return.php` | 반품 처리 (부분/전체) | `wholesale_management` 권한 + 점포 스코프 |
| GET | `admin/ajax_wholesale_sale_return.php?sale_id={id}` | 해당 판매 건의 반품 이력 조회 (모달 표시용) | `wholesale_management` 권한 + 점포 스코프 |

### 4.2 Detailed Specification

#### `POST admin/ajax_wholesale_sale_return.php`

**Request (application/x-www-form-urlencoded 또는 JSON body):**
```json
{
  "sale_id": 123,
  "reason": "고객 단순 변심",
  "return_type": "partial",
  "items": [
    { "sale_item_id": 456, "quantity": 2 },
    { "sale_item_id": 457, "quantity": 1 }
  ]
}
```
- `return_type`이 `"full"`인 경우 `items`는 무시하고 서버가 각 품목의 `(quantity - returned_quantity)` 전량을 반품 처리

**Response (200 OK):**
```json
{
  "success": true,
  "return_id": 12,
  "total_amount": 15000.00,
  "sale": {
    "id": 123,
    "total_amount": 30000.00,
    "final_amount": 30000.00,
    "returned_amount": 15000.00,
    "return_status": "partial"
  }
}
```

**Error Responses:**
- `400 Bad Request`: `{"success": false, "error": "INVALID_QUANTITY", "message": "반품 수량이 반품 가능 수량을 초과했습니다.", "sale_item_id": 456}`
- `403 Forbidden`: `{"success": false, "error": "PERMISSION_DENIED"}` (권한 없음 또는 타 점포 데이터)
- `404 Not Found`: `{"success": false, "error": "SALE_NOT_FOUND"}`
- `409 Conflict`: `{"success": false, "error": "ALREADY_FULLY_RETURNED"}` (이미 전체 반품된 건에 재반품 시도)
- `500 Internal Server Error`: `{"success": false, "error": "SERVER_ERROR", "message": "..."}` (트랜잭션 실패 시 자동 rollback)

#### `GET admin/ajax_wholesale_sale_return.php?sale_id={id}`

**Response (200 OK):**
```json
{
  "success": true,
  "returns": [
    {
      "id": 12,
      "reason": "고객 단순 변심",
      "total_amount": 15000.00,
      "processed_by_name": "홍길동",
      "created_at": "2026-07-09 14:20:00",
      "items": [
        { "sale_item_id": 456, "product_name": "상품A", "quantity": 2, "unit_price": 5000.00, "amount": 10000.00 }
      ]
    }
  ]
}
```

---

## 5. UI/UX Design

### 5.1 Screen Layout — 반품 모달 (`wholesale_sale_preview.php` 내 신규)

```
┌────────────────────────────────────────────────┐
│  반품 처리                                  [X]  │
├────────────────────────────────────────────────┤
│  SKU  │ 상품명 │ 판매수량 │ 기반품 │ 반품가능 │ 반품수량 입력 │
│  ...  │  ...   │   ...   │  ...  │   ...   │  [   0   ]   │
├────────────────────────────────────────────────┤
│  반품 사유: [____________________]              │
│  반품 예정 금액: 15,000                          │
│  [ 전체 반품 ]           [ 취소 ]  [ 반품 처리 ] │
├────────────────────────────────────────────────┤
│  ▼ 기존 반품 이력 (있는 경우)                     │
│    2026-07-05 14:20 / 홍길동 / 10,000 / 사유: ...│
└────────────────────────────────────────────────┘
```

### 5.2 User Flow

```
판매 미리보기 화면 → "반품 처리" 버튼 클릭 → 반품 모달 오픈
  → 품목별 반품수량 입력 (또는 "전체 반품" 클릭 시 전량 자동 채움)
  → "반품 처리" 클릭 → 서버 검증/처리 → 성공 토스트 + 모달 닫힘 + 화면 데이터 갱신(금액/배지/이력)
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| 반품 처리 버튼 | `wholesale_sale_preview.php` 상단 액션 영역 | 반품 모달 오픈 (이미 `return_status='full'`이면 비활성화) |
| 반품 모달 | `wholesale_sale_preview.php` 내 신규 `<div id="return-modal">` | 품목별 반품 수량 입력 폼, 전체 반품 버튼, 반품 이력 표시 |
| 반품 상태 배지 | `wholesale_sales_list.php` 목록 행 | `return_status`가 `partial`/`full`일 때 배지 표시 |

### 5.4 Page UI Checklist

#### 판매 미리보기 (`wholesale_sale_preview.php`)

- [ ] Button: "반품 처리" (판매 상세 액션 버튼 영역, `return_status='full'`이면 disabled + "전체 반품 완료" 표시)
- [ ] Modal: 반품 처리 모달 (`#return-modal`)
  - [ ] Table row per 품목: SKU, 상품명, 판매수량, 기반품수량(`returned_quantity`), 반품가능수량(`quantity - returned_quantity`), 반품수량 입력(number input, max=반품가능수량)
  - [ ] Button: "전체 반품" (모든 품목의 반품수량 입력란을 반품가능수량으로 자동 채움)
  - [ ] Input: 반품 사유 (텍스트, 선택 입력)
  - [ ] Display: 반품 예정 금액 합계 (입력값 변경 시 실시간 재계산)
  - [ ] Button: "반품 처리" (제출), "취소" (모달 닫기)
- [ ] Section: 반품 이력 목록 (반품 건별: 일시, 처리자, 금액, 사유, 품목 상세 펼치기)
- [ ] Display: 반품 금액 반영된 최종 결제 금액(`final_amount`) — 기존 미수금 표시 영역 재사용

#### 판매 목록 (`wholesale_sales_list.php`)

- [ ] Badge: 반품 상태 (`partial` → "부분반품" 주황, `full` → "전체반품" 회색/취소선, `none` → 표시 없음)

---

## 6. Error Handling

### 6.1 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| `INVALID_QUANTITY` | 반품 수량이 반품 가능 수량을 초과했습니다 | 클라이언트 검증 우회 또는 동시 반품 요청(race) | 트랜잭션 내 `FOR UPDATE`로 재검증 후 거부, 프론트에 해당 품목 강조 표시 |
| `PERMISSION_DENIED` | 반품 권한이 없습니다 | `wholesale_management` 미보유 또는 타 점포 데이터 | 403 응답, UI에서 버튼 자체를 노출하지 않음(방어적으로 서버도 재검증) |
| `SALE_NOT_FOUND` | 판매 건을 찾을 수 없습니다 | 잘못된 sale_id | 404 응답 |
| `ALREADY_FULLY_RETURNED` | 이미 전체 반품 처리된 판매 건입니다 | 중복 요청 | 409 응답, 모달에서 안내 후 새로고침 유도 |
| `SERVER_ERROR` | 처리 중 오류가 발생했습니다 | DB 오류 등 | 500 응답, `pdo->rollback()`, `error_log()` 기록 |

### 6.2 Error Response Format

```json
{
  "success": false,
  "error": "INVALID_QUANTITY",
  "message": "반품 수량이 반품 가능 수량을 초과했습니다.",
  "sale_item_id": 456
}
```

---

## 7. Security Considerations

- [x] 모든 쿼리는 prepared statement 사용 (기존 `wholesale_sale_preview.php` PDO 패턴 준수)
- [x] `has_permission('wholesale_management')` 체크 (페이지 상단, 기존 패턴)
- [x] 점포 스코프 검증: `$_SESSION['role'] !== 'super_admin'`이면 `store_id = $current_store_id` 조건 필수 (기존 `action=delete` 패턴과 동일)
- [x] 반품 수량 검증은 클라이언트뿐 아니라 서버에서 `SELECT ... FOR UPDATE`로 반드시 재검증 (동시성 대응)
- [x] `reason` 등 사용자 입력은 저장 시 그대로 두고 출력 시 `htmlspecialchars()` 이스케이프 (기존 관행)
- [ ] Rate Limiting: 해당 없음 (내부 관리자 도구, 기존 프로젝트에 Rate Limiting 체계 없음)

---

## 8. Test Plan

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: API Tests | `ajax_wholesale_sale_return.php` — 상태코드, 응답 shape | curl | Do |
| L2: UI Action Tests | 반품 모달 입력/제출/배지 표시 | 수동 브라우저 테스트 (Playwright 미설치 프로젝트) | Do |
| L3: E2E Scenario | 판매 등록 → 부분반품 → 재고/금액 확인 → 전체반품 | 수동 시나리오 테스트 | Do |

> 본 프로젝트는 Playwright/자동화 테스트 도구가 설치되어 있지 않으므로(PHP 절차적 페이지), L2/L3는 수동 시나리오 테스트로 대체하고 체크리스트 형태로 Do phase에 문서화한다.

### 8.2 L1: API Test Scenarios

| # | Endpoint | Method | Test Description | Expected Status | Expected Response |
|---|----------|--------|-----------------|:--------------:|-------------------|
| 1 | `ajax_wholesale_sale_return.php` | POST | 정상 부분 반품 (품목 1개, 수량 2) | 200 | `.success=true`, `.sale.return_status="partial"` |
| 2 | `ajax_wholesale_sale_return.php` | POST | `return_type=full` 전체 반품 | 200 | `.sale.return_status="full"`, `.sale.final_amount=0` (해당 판매건 전액 반품 시) |
| 3 | `ajax_wholesale_sale_return.php` | POST | 반품 수량 > 반품 가능 수량 | 400 | `.error="INVALID_QUANTITY"` |
| 4 | `ajax_wholesale_sale_return.php` | POST | 타 점포 판매건에 접근(non-super_admin) | 403 | `.error="PERMISSION_DENIED"` |
| 5 | `ajax_wholesale_sale_return.php` | POST | 존재하지 않는 sale_id | 404 | `.error="SALE_NOT_FOUND"` |
| 6 | `ajax_wholesale_sale_return.php` | GET | 반품 이력 조회 | 200 | `.returns`는 배열, 각 항목에 `items` 포함 |

### 8.3 L2: UI Action Test Scenarios

| # | Page | Action | Expected Result | Data Verification |
|---|------|--------|----------------|-------------------|
| 1 | 판매 미리보기 | "반품 처리" 클릭 | 모달 오픈, 품목별 반품가능수량 표시 | `quantity - returned_quantity` 값과 일치 |
| 2 | 반품 모달 | 품목 1개 수량 입력 후 "반품 처리" | 성공 토스트, 모달 닫힘, 페이지 데이터 갱신 | `final_amount` 감소량 = 반품금액과 일치 |
| 3 | 반품 모달 | 반품가능수량 초과 입력 후 제출 | 에러 메시지 표시, 요청 차단(클라이언트) | 서버 400도 별도 검증 |
| 4 | 반품 모달 | "전체 반품" 클릭 | 모든 품목 반품가능수량 자동 채움 | 합계 금액 = 잔여 `final_amount`와 일치 |
| 5 | 판매 목록 | 부분/전체 반품된 건 조회 | 배지 정상 표시 | `return_status` 값과 배지 텍스트 일치 |

### 8.4 L3: E2E Scenario Test Scenarios

| # | Scenario | Steps | Success Criteria |
|---|----------|-------|-----------------|
| 1 | 부분 반품 재고 복원 | 도매판매 등록(등록상품 2개, 각 3박스) → 재고 확인 → 1박스 부분반품 → 재고 재확인 | `inventory.stock_quantity`가 `1 × pieces_per_box`만큼 증가 |
| 2 | 전체 반품 후 재반품 차단 | 판매 등록 → 전체 반품 → 동일 건에 추가 반품 시도 | 409 `ALREADY_FULLY_RETURNED` 응답, UI 버튼 비활성화 확인 |
| 3 | 수기 품목 반품 재고 미영향 | 수기 입력 품목 포함 판매 등록 → 해당 품목 반품 | `inventory` 변화 없음, `restocked=0` 기록 확인 |
| 4 | 판매 수정 시 반품 품목 보호 | 반품된 품목이 있는 판매 건을 수정 화면에서 수량 축소/삭제 시도 | 서버에서 차단(에러 메시지), DB FK RESTRICT로도 보호됨 확인 |
| 5 | 미수금 반영 | `payment_status=unpaid` 판매 건 부분 반품 | 목록의 미수금 표시 금액이 반품액만큼 감소 |

### 8.5 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| `wholesale_sales` | 3 | 등록상품만 있는 건 1, 수기품목 포함 건 1, 이미 부분반품된 건 1 |
| `wholesale_sale_items` | 5+ | `sale_unit` box/piece 혼합, `product_id` NULL 포함 |
| `inventory` | 해당 상품 수만큼 | `stock_quantity` 초기값 기록해두고 반품 전후 비교 |

---

## 9. Clean Architecture

> 본 프로젝트는 절차적 PHP 페이지 구조이며 Presentation/Application/Domain/Infrastructure 계층 분리를 사용하지 않음(N/A). 대신 아래와 같이 파일 단위 책임을 명시한다.

### 9.1 File Responsibility (this project's convention)

| File | Responsibility |
|------|---------------|
| `admin/wholesale_sale_preview.php` | 반품 모달 UI 렌더링 + 반품 이력 조회 표시(초기 로드 시 GET 재사용 가능) |
| `admin/ajax_wholesale_sale_return.php` | 반품 처리 트랜잭션 전담 (검증 → INSERT/UPDATE → COMMIT) |
| `admin/wholesale_sales_list.php` | `return_status` READ 및 배지 렌더링만 (쓰기 없음) |
| `admin/wholesale_sales.php` | 수정 모드 저장 로직에 반품 품목 보호 검증 추가 |
| `admin/sql/add_wholesale_sale_return_schema.sql` | DB 스키마 변경 정의 |

---

## 10. Coding Convention Reference

### 10.1 Naming Conventions (기존 프로젝트 관행)

| Target | Rule | Example |
|--------|------|---------|
| PHP 파일 | snake_case.php | `ajax_wholesale_sale_return.php` |
| DB 테이블/컬럼 | snake_case | `wholesale_sale_returns`, `returned_quantity` |
| 다국어 키 | `{namespace}.{key}` | `wholesale_sale_preview.return_button`, `wholesale_sale_preview.return_modal_title` |
| JS 변수/함수 | camelCase | `openReturnModal()`, `calcReturnTotal()` |

### 10.2 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| DB 접근 | PDO + prepared statement (`wholesale_sale_preview.php`와 동일) |
| 트랜잭션 | `beginTransaction()`/`commit()`/`rollback()`, 실패 시 `error_log()` |
| 권한 체크 | `has_permission('wholesale_management')` + 점포 스코프 인라인 검증 |
| 금액/수량 표시 | 소숫점 둘째자리까지 (CLAUDE.md 규칙) |
| i18n | `lib/lang_helper.php`의 `t()` 함수, 한/영 키 동시 등록 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
admin/
├── sql/
│   └── add_wholesale_sale_return_schema.sql          (신규)
├── ajax_wholesale_sale_return.php                     (신규)
├── wholesale_sale_preview.php                         (수정 — 반품 모달 UI/JS 추가)
├── wholesale_sales_list.php                           (수정 — 반품 배지)
└── wholesale_sales.php                                (수정 — 반품 품목 보호 검증)
```

### 11.2 Implementation Order

1. [ ] DB 마이그레이션 SQL 작성 및 실행 (`add_wholesale_sale_return_schema.sql` + 웹 러너)
2. [ ] `ajax_wholesale_sale_return.php` 구현 (POST 반품 처리, GET 이력 조회)
3. [ ] `wholesale_sale_preview.php`에 반품 버튼/모달/JS 추가 + 반품 이력 섹션
4. [ ] `wholesale_sales_list.php`에 반품 상태 배지 추가
5. [ ] `wholesale_sales.php` 수정 모드에 반품 품목 보호 검증 추가
6. [ ] 다국어 키 등록 (한/영)
7. [ ] 수동 시나리오 테스트 (§8.3, §8.4)

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| DB 스키마 + 마이그레이션 | `module-1` | 5개 테이블/컬럼 변경, 웹 러너 스크립트 | 5-10 |
| 반품 처리 API | `module-2` | `ajax_wholesale_sale_return.php` (검증/트랜잭션/재고/금액) | 20-25 |
| 반품 UI (미리보기 화면) | `module-3` | 모달, JS 검증, 반품 이력 표시 | 20-25 |
| 목록 배지 + 수정 보호 | `module-4` | `wholesale_sales_list.php`, `wholesale_sales.php` 보호 로직 | 10-15 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 | 완료 |
| Session 2 | Do | `--scope module-1,module-2` | 30-35 |
| Session 3 | Do | `--scope module-3,module-4` | 30-40 |
| Session 4 | Check + Report | 전체 | 20-30 |

---

## Revision Note (v0.2 — 2026-07-09, UX 변경)

> 완료 보고서 작성 이후 사용자 피드백으로 반품 등록 진입점이 변경됨.

**변경 전**: 판매 미리보기 화면(`wholesale_sale_preview.php`)에서 특정 판매 건을 연 뒤 "반품 처리" 버튼으로 해당 건만 반품.

**변경 후**: 신규 판매등록 화면(`wholesale_sales.php`)에서 거래처 선택 후 "반품등록" 버튼 클릭 → 해당 거래처의 최근 판매 이력(품목 단위, 반품가능수량>0)을 모달로 조회 → 품목을 반품 장바구니에 담아 수량 조정 → 저장. 한 번의 반품등록에 여러 판매 건(sale_id)의 품목이 섞일 수 있어, 클라이언트가 `sale_id`별로 그룹핑해 `ajax_wholesale_sale_return.php`를 그룹당 1회씩 순차 호출(기존 API 계약은 변경 없음, 오케스트레이션만 클라이언트에서 수행).

**신규 파일**: `admin/ajax_search_wholesale_customer_returns.php` (거래처 최근 반품가능 품목 조회, GET customer_id)

**제거**: `wholesale_sale_preview.php`의 반품 처리 버튼/모달/JS (반품 이력 표시 섹션과 상태 배지는 유지)

**영향받지 않음**: DB 스키마(§3), `ajax_wholesale_sale_return.php`의 API 계약(§4), `wholesale_sales_list.php`의 배지, `wholesale_sales.php`의 수정 보호 로직(FR-09)

---

## Revision Note (v0.3 — 2026-07-09, 회계 모델 변경)

> v0.2 적용 직후 사용자 피드백: "반품의 경우 예전 전표를 수정하면 안 됨. 현재 전표에 반품상으로 등록되어야 하고, 그 전표의 전체금액에서 빼야 함."

**핵심 변경**: v0.1~v0.2까지는 반품이 발생하면 **원본(예전) 판매 전표**의 `total_amount`/`final_amount`/`return_status`를 직접 수정했음. 이는 회계 원칙에 어긋남 — 이미 발행된 전표는 불변이어야 하고, 반품은 반품이 실제로 발생한 **현재 전표(오늘 작성 중인 새 판매)**에 별도 항목으로 기록되어 그 전표의 금액에서 차감되어야 함.

| 항목 | v0.2 (변경 전) | v0.3 (변경 후) |
|------|----------------|----------------|
| `wholesale_sale_returns.sale_id` 의미 | 반품된 원본 판매의 ID | **반품이 등록된 현재(신규) 판매의 ID** |
| 원본 전표(`wholesale_sales`)의 `total_amount`/`final_amount`/`returned_amount`/`return_status` | 반품 시 직접 차감/갱신 | **절대 변경하지 않음** (영구 불변) |
| 원본 `wholesale_sale_items.returned_quantity` | 갱신 (중복 반품 방지용) | 동일하게 갱신 유지 (금액에는 영향 없는 추적 전용 필드) |
| 반품 처리 시점/방식 | 판매 미리보기 화면에서 개별 API 호출(`ajax_wholesale_sale_return.php`)로 즉시 처리 | **신규 판매 등록 폼 제출과 동일 트랜잭션**으로 처리 (`wholesale_sales.php` POST 핸들러 내부) — 반품 단독으로도(신규 구매 없이) 저장 가능, 이 경우 `total_amount`가 음수인 전표가 생성됨 |
| `return_status` 값 의미 | none/partial(자신이 부분반품됨)/full(자신이 전체반품됨) | none/**partial(이 전표에 신규구매+반품 혼재)**/**full(이 전표가 반품 전용, 신규구매 없음)** |
| 배지 문구 | "부분반품"/"전체반품" | "반품포함"/"반품전표" |
| `ajax_wholesale_sale_return.php` | UI에서 직접 호출 | **UI에서 더 이상 호출하지 않음 (미사용 상태로 남음, 삭제 여부 사용자 확인 필요)** |
| 신규 파일 | - | 없음 (기존 `ajax_search_wholesale_customer_returns.php` 재사용, DB 스키마도 변경 없음 — 컬럼 의미만 재해석) |

**영향받지 않음**: DB 스키마 자체(추가 마이그레이션 불필요, `wholesale_sale_returns`/`wholesale_sale_return_items` 테이블 구조 그대로 재사용), 재고 복원 로직(박스→낱개 환산), `ajax_search_wholesale_customer_returns.php`(거래처 최근 반품가능 품목 조회)

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-09 | 최초 작성 (Option C 선택) | Claude (PDCA) |
| 0.2 | 2026-07-09 | 반품 등록 진입점을 wholesale_sale_preview.php → wholesale_sales.php(신규등록 화면)로 변경 | Claude (PDCA) |
| 0.3 | 2026-07-09 | 회계 모델 변경: 원본 전표는 불변, 반품은 현재(신규) 전표에 기록되어 그 전표 금액에서 차감 | Claude (PDCA) |
