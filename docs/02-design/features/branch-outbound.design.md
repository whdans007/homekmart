---
template: design
version: 1.3
---

# branch-outbound (지점출고) Design Document

> **Summary**: 물류센터 직원이 지점을 선택하고 바코드 스캔(또는 `숫자/` 프리픽스로 수량 자동입력)으로 상품을 장바구니에 담아 원가 그대로 즉시 출고 처리하는 신규 메뉴의 설계
>
> **Project**: Home K Mart - Logistics Center
> **Version**: 1.0
> **Author**: whdans007
> **Date**: 2026-06-10
> **Status**: Draft
> **Plan Reference**: `docs/01-plan/features/branch-outbound.plan.md`

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 물류센터 직원이 지점에 즉시 물건을 출고할 때, 별도 주문/승인 절차 없이 원가 기준으로 빠르게 처리할 수단이 없음 |
| **WHO** | 물류센터 직원 (lc_require_staff) |
| **RISK** | 재고 초과 출고(음수 재고) 허용으로 인한 데이터 정합성 — FIFO 함수가 부족 재고를 어떻게 처리할지 명확히 설계 필요 |
| **SUCCESS** | 바코드 스캔→`숫자/`프리픽스 자동수량→장바구니→등록까지 전 과정이 동작하고, lc_orders/lc_order_items/lc_order_item_lots/lc_inventory가 정확히 기록되며 Outbound History에 반영됨 |
| **SCOPE** | 단일 페이지(branch_outbound.php) + ajax 처리 + 사이드바 메뉴 추가. lc_orders 등 기존 스키마 재사용, 신규 컬럼/테이블 없음 |

> **Design Anchor**: N/A — 본 기능은 기존 logistics 모듈의 Tailwind/teal 테마 및 `inbound_add.php` UI 패턴을 그대로 재사용하므로 별도 Pencil 디자인 토큰 캡처는 불필요.

---

## 1. Overview

### 1.1 Design Goals

- `inbound_add.php`의 검증된 바코드 스캔 UX를 출고 화면에 재사용하여 개발 속도와 일관성 확보
- 재고 부족 시에도 출고를 차단하지 않고 음수 재고를 허용하되, 기존 `lc_fifo_ship()` 계약은 변경하지 않음
- 출고 단가(unit_price)는 항상 차감된 lot들의 cost_price 가중평균 — 마진 0 보장
- `숫자/` 프리픽스 입력이 기존 8자리 이상 숫자=바코드 자동검색 로직과 충돌하지 않도록 입력 파싱을 명확히 분리

### 1.2 Design Principles

- 기존 ajax 액션 기반 디스패치 패턴(`ajax/distribute_to_stores.php`)을 따름
- 트랜잭션 내에서만 재고 차감 + 주문 생성 수행 (autocommit false)
- 신규 함수는 기존 함수와 분리 (`lc_fifo_ship_allow_negative`), 기존 호출부 영향 없음

---

## 2. Architecture Options

### 2.1 Comparison

| Criteria | Option A: Minimal | Option B: Clean | **Option C: Pragmatic (선택됨)** |
|----------|:-:|:-:|:-:|
| Approach | 단일 파일에서 GET+POST 모두 처리 | 액션별 ajax 파일 분리 + 서비스 레이어 | 페이지(GET) + 단일 `ajax/branch_outbound.php`(action 기반) |
| New Files | 1 | 4+ | 2 |
| Complexity | Low | High | Medium |
| Maintainability | Medium | High | High |
| Effort | Low | High | Medium |
| 기존 컨벤션 일치 | 보통 | 낮음(과설계) | 높음 (`distribute_to_stores.php`와 동일 패턴) |

> **Checkpoint 3 결과**: Option C 선택됨.

### 2.2 Component Diagram

```
┌─────────────────────────┐        ┌──────────────────────────────┐
│ logistics/branch_outbound│  GET   │ partials/header.php (수정)     │
│  .php (신규)              │◄──────►│  - 사이드바 "Branch Outbound" │
│  - 지점 선택              │        └──────────────────────────────┘
│  - 바코드/프리픽스 입력   │
│  - 장바구니 테이블(JS)    │
└─────────┬────────────────┘
          │ fetch (AJAX)
          ▼
┌─────────────────────────────────────┐     ┌──────────────────────────┐
│ logistics/ajax/branch_outbound.php   │     │ ajax/search_product_by_   │
│  (신규, action 기반)                  │◄───►│ barcode.php (기존 재사용)  │
│  - get_stores                        │     └──────────────────────────┘
│  - get_product_stock                 │
│  - submit (트랜잭션)                  │
└─────────┬─────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────┐
│ lib/inventory_helper.php (수정)       │
│  - lc_fifo_ship_allow_negative (신규) │
└─────────┬─────────────────────────────┘
          │ UPDATE / INSERT
          ▼
┌──────────────────────────────────────────────────────────┐
│ lc_inventory / lc_orders / lc_order_items / lc_order_item_lots │
└──────────────────────────────────────────────────────────┘
```

### 2.3 Data Flow

1. 페이지 로드(GET) → `get_stores` 액션으로 점포 목록을 채워 드롭다운 렌더링
2. 직원이 지점 선택
3. 바코드 입력창에 입력:
   - `8/` 입력 시 → "수량 모드" 진입(화면에 "수량 8 대기중" 표시), 이후 바코드 입력 대기
   - 8자리 이상 숫자만 입력 시(수량 모드 아님) → 200ms debounce 후 `search_product_by_barcode.php` 호출
   - 텍스트 2자 이상 입력 시 → 400ms debounce 후 동일 검색
4. 검색 결과가 1건이면 즉시, 복수면 드롭다운에서 선택 → `addProductRow(product, qty)` 호출
   - `addProductRow`는 동시에 `get_product_stock` 호출하여 평균원가/재고 표시
5. 장바구니에서 수량 수정 가능, 행별 소계 = 평균원가 × 수량 (참고용 표시)
6. "출고 등록" 클릭 → `submit` 액션으로 `{store_id, items:[{product_id, quantity}]}` POST
7. 서버: 트랜잭션 시작 → `lc_orders` 1건(status='shipped') INSERT → 각 item에 대해 `lc_fifo_ship_allow_negative()` 호출 → `lc_order_items`(unit_price=가중평균) + `lc_order_item_lots`(lot별 cost_price) INSERT → commit
8. 성공 응답 → `order_detail.php?id={order_id}`로 리다이렉트 + 성공 플래시

### 2.4 Dependencies

| Dependency | Type | Note |
|------------|------|------|
| `ajax/search_product_by_barcode.php` | 기존 재사용 | 변경 없음 |
| `lib/inventory_helper.php` | 수정 | `lc_fifo_ship_allow_negative` 추가 |
| `lib/auth.php` | 기존 재사용 | `lc_require_staff()`, `lc_csrf_token()`, `lc_verify_csrf()` |
| `lib/flash` (`lc_set_flash` 등) | 기존 재사용 | 등록 완료 메시지 |

---

## 3. Data Model

### 3.1 스키마 변경

**없음.** 기존 테이블을 그대로 사용한다.

| Table | 사용 방식 |
|-------|----------|
| `lc_orders` | INSERT 1건: `order_date=오늘`, `store_id`, `status='shipped'`, `total_amount=Σ(unit_price*quantity)`, `shipped_at=NOW()`, `created_by=현재 사용자` |
| `lc_order_items` | item별 INSERT: `order_id`, `product_id`, `quantity`, `unit_price=가중평균 cost_price` |
| `lc_order_item_lots` | lot별 INSERT: `order_item_id`, `inventory_id`, `inbound_id`, `quantity`, `cost_price` (음수 lot 포함 가능) |
| `lc_inventory` | `quantity_out` UPDATE (lot별 차감, 부족분은 마지막 lot에 음수 가능) |

### 3.2 unit_price 계산식 (FR-07)

```
deductions = lc_fifo_ship_allow_negative($conn, $product_id, $qty)
// deductions: [{inventory_id, inbound_id, quantity, cost_price, ...}, ...]

total_cost = Σ (deduction.quantity * deduction.cost_price)
total_qty  = Σ (deduction.quantity)   // 음수 lot 포함 시 total_qty == 원래 요청 qty와 동일

unit_price = round(total_cost / total_qty, 4)   // DECIMAL(15,4)와 정밀도 맞춤
```

- `lc_order_items.total_amount`(GENERATED = quantity * unit_price)와 `lc_order_item_lots.cost_price*quantity` 합계 사이에 반올림 오차(최대 ±0.0001 * quantity 수준)가 발생할 수 있음 → Plan §5 Risk에서 허용 오차로 문서화됨. 별도 보정 로직 없음.

### 3.3 lc_fifo_ship_allow_negative 알고리즘

```
function lc_fifo_ship_allow_negative(mysqli $conn, int $product_id, int $qty_needed): array {
    // 1. lc_fifo_ship()와 동일하게 quantity_remain > 0인 lot을 만료일 ASC, id ASC로 FOR UPDATE 조회
    // 2. 정상 차감: 각 lot에서 min(remaining, quantity_remain)만큼 차감 (lc_fifo_ship과 동일)
    // 3. 모든 lot을 소진했는데도 remaining > 0 (재고 부족)인 경우:
    //    - 부족분(shortfall = remaining)을 "마지막으로 차감된 lot"
    //      (없으면, 즉 재고가 아예 0건이면 product_id의 가장 최근 lc_inbound 1건,
    //       그것도 없으면 lc_products.cost_price를 cost_price로 사용하는 가상 lot)
    //      에 대해 quantity_out += shortfall 로 추가 UPDATE (quantity_remain이 음수가 됨)
    //    - deductions 배열에 shortfall 만큼의 항목을 cost_price와 함께 추가
    // 4. lc_fifo_ship()과 달리 재고 부족이어도 false를 반환하지 않고 항상 배열을 반환
    // 반환: [{inventory_id, inbound_id, quantity, cost_price, storage_location, lot_number, expiry_date}, ...]
    //       quantity 합계 == $qty_needed (항상)
}
```

- 재고가 전혀 없는 상품(`lc_inventory`에 해당 product_id row가 없음)의 경우: `lc_inbound`에 해당 product_id의 최근 입고 1건이 있으면 그 `inventory_id`를 사용해 음수 차감(quantity_out 증가)한다. 입고 이력 자체가 없으면 `lc_inventory`/`lc_inbound`에 INSERT하지 않고, `lc_order_item_lots`에 `inventory_id=NULL, inbound_id=NULL, cost_price=lc_products.cost_price`로 기록한다 (FR-08 "출고 차단 안 함" 우선).
  - 이 경우를 위해 `lc_order_item_lots.inventory_id`, `inbound_id`는 NULL 허용이어야 함 → 기존 `lc_migration_v8.sql` 확인 결과 두 컬럼 모두 NULL 허용 FK이므로 스키마 변경 불필요.

---

## 4. API Specification

신규 파일: `logistics/ajax/branch_outbound.php` (action 기반, `lc_require_staff()` 적용)

### 4.1 GET `?action=get_stores`

- 기존 `ajax/distribute_to_stores.php`의 `get_stores`와 동일 쿼리 재사용
- Response: `{ "success": true, "stores": [{"id":1,"name":"강남점"}, ...] }`

### 4.2 GET `?action=get_product_stock&product_id={id}`

- 기존 `ajax/distribute_to_stores.php`의 `get_product_stock`과 동일 쿼리 재사용 (총재고, 가중평균원가)
- Response: `{ "success": true, "product": {"id":1,"name":"...","unit":"EA","total_stock":12,"avg_cost":1234.5} }`

### 4.3 POST `?action=submit`

| Field | Type | Description |
|-------|------|-------------|
| `csrf_token` | string | `lc_csrf_token()` |
| `store_id` | int | 출고 대상 지점 |
| `items` | JSON string | `[{"product_id":1,"quantity":8}, ...]` |
| `notes` | string (optional) | 비고 |

처리 로직 (트랜잭션, autocommit false):

```php
$conn->autocommit(false);
// 1. lc_orders INSERT (status='shipped', shipped_at=NOW(), order_date=오늘, total_amount는 마지막에 UPDATE)
$order_id = $conn->insert_id;
$total_amount = 0;
foreach ($items as $item) {
    $deductions = lc_fifo_ship_allow_negative($conn, $item['product_id'], $item['quantity']);
    $total_cost = array_sum(array_map(fn($d) => $d['quantity'] * $d['cost_price'], $deductions));
    $unit_price = round($total_cost / $item['quantity'], 4);
    // lc_order_items INSERT (order_id, product_id, quantity, unit_price)
    $order_item_id = $conn->insert_id;
    foreach ($deductions as $d) {
        // lc_order_item_lots INSERT (order_item_id, inventory_id, inbound_id, quantity, cost_price)
    }
    $total_amount += $item['quantity'] * $unit_price;
}
// lc_orders UPDATE total_amount = $total_amount
$conn->commit();
```

Response (성공): `{ "success": true, "order_id": 123 }`
Response (실패): `{ "success": false, "message": "..." }` (CSRF 실패, store_id/items 누락, DB 예외 시 rollback)

> 재고 부족 검증을 하지 않으므로(FR-08) 이 액션은 항상 `success: true`를 반환한다 (DB 예외 제외).

### 4.4 기존 재사용 엔드포인트

- GET `ajax/search_product_by_barcode.php?barcode={code}` — 변경 없음, `branch_outbound.php`의 바코드 검색에 그대로 사용

---

## 5. UI/UX Design

### 5.1 Screen Layout

`branch_outbound.php` (header.php/footer.php 포함, teal 테마):

```
┌─────────────────────────────────────────────┐
│ 지점출고 (Branch Outbound)                     │
├─────────────────────────────────────────────┤
│ 출고 지점: [드롭다운 ▼]                         │
├─────────────────────────────────────────────┤
│ 바코드/상품 검색: [____________________]       │
│   (입력 안내: "숫자/" 입력 후 스캔 시 해당 수량) │
│   [수량 대기중: 8]  ← /입력 시에만 표시          │
│   ┌ 검색결과 패널 (복수 결과 시) ┐               │
│   │ ↑↓ Enter 로 선택            │               │
│   └─────────────────────────────┘               │
├─────────────────────────────────────────────┤
│ 장바구니                                       │
│ ┌────────┬────────┬──────┬──────┬────┐        │
│ │ 상품명 │ 평균원가│ 수량 │ 소계 │ 삭제│        │
│ ├────────┼────────┼──────┼──────┼────┤        │
│ │ ...    │ ...    │ [수정]│ ...  │ [x]│        │
│ └────────┴────────┴──────┴──────┴────┘        │
│                                  합계: ₩00,000 │
├─────────────────────────────────────────────┤
│                          [출고 등록] (teal-600)│
└─────────────────────────────────────────────┘
```

### 5.2 User Flow

1. 페이지 진입 → 지점 드롭다운 자동 채움(`get_stores`)
2. 지점 선택 (필수, 미선택 시 등록 버튼 비활성)
3. 바코드 입력창에 포커스 자동 위치
4. (선택) `8/` 입력 → "수량 대기중: 8" 배지 표시, 입력창 초기화
5. 바코드 스캔 (8자리 이상 숫자 자동 입력) 또는 상품명 텍스트 입력
6. 검색 결과 1건 → 자동으로 장바구니에 추가 (수량 = 대기중 수량 또는 1, 이후 대기중 수량 초기화)
7. 검색 결과 복수 → 드롭다운에서 ↑↓/Enter로 선택 → 장바구니 추가
8. 장바구니에서 수량 인라인 수정 가능 (소계 자동 재계산)
9. 행 삭제 가능 ([x] 버튼)
10. "출고 등록" 클릭 → confirm 다이얼로그("N개 상품을 {지점명}으로 출고 등록하시겠습니까?") → `submit` 호출
11. 성공 → `order_detail.php?id={order_id}`로 이동, "지점출고가 등록되었습니다" 플래시

### 5.3 Component List

| Component | Description |
|-----------|-------------|
| 지점 선택 드롭다운 | `<select>` + `get_stores` 결과로 옵션 채움 |
| 바코드 입력창 | `inbound_add.php`의 입력 처리 로직 재사용 + `숫자/` 파싱 추가 |
| 수량 대기 배지 | `/` 프리픽스 입력 시 표시되는 작은 pill UI |
| 검색결과 패널 | `inbound_add.php`의 `barcodeMulti` 패턴 재사용 |
| 장바구니 테이블 | `<template>` row 클로닝 (inbound_add.php 패턴), 수량 input은 `editable` |
| 출고 등록 버튼 | teal-600, 장바구니 비어있으면 disabled |

### 5.4 Page UI Checklist

- [ ] 지점 드롭다운이 비어있으면 "지점을 선택하세요" placeholder 표시
- [ ] 바코드 입력창에 `숫자/` 입력 안내 placeholder 또는 헬프 텍스트 표시
- [ ] 수량 대기 배지는 `/` 입력 후 ~ 다음 상품 추가 전까지만 표시되고 자동 초기화됨
- [ ] 검색 결과 0건일 때 "상품을 찾을 수 없습니다" 메시지 표시
- [ ] 장바구니 빈 상태일 때 "스캔하여 상품을 추가하세요" placeholder row 표시
- [ ] 장바구니 수량 0 또는 음수 입력 시 자동으로 1로 보정 (또는 행 삭제 confirm)
- [ ] 합계 금액(평균원가 기준)이 실시간으로 갱신됨
- [ ] "출고 등록" 클릭 시 confirm 다이얼로그 표시
- [ ] 등록 중 버튼 disabled + 로딩 표시 (중복 클릭 방지)
- [ ] 등록 실패 시 에러 메시지 표시, 장바구니 데이터 유지

### 5.5 입력 파싱 로직 (`숫자/` 프리픽스)

```js
// 입력값 변경 시
const val = input.value;
const slashMatch = val.match(/^(\d+)\/(.*)$/);

if (slashMatch) {
    // "8/" 또는 "8/12345678" 형태
    pendingQty = parseInt(slashMatch[1], 10);
    showQtyBadge(pendingQty);
    const rest = slashMatch[2]; // '/' 뒤의 나머지 문자열
    if (rest.length === 0) {
        // "8/" 까지만 입력된 상태 — 검색 트리거 없음, 다음 입력 대기
        return;
    }
    // "8/12345678" 처럼 한 번에 붙여넣기/스캔된 경우 → rest를 바코드로 처리
    if (/^\d{8,}$/.test(rest)) {
        debounceSearch(rest, 200, pendingQty);
    } else if (rest.length >= 2) {
        debounceSearch(rest, 400, pendingQty);
    }
    input.value = ''; // 처리 후 입력창 초기화 (배지는 유지)
    return;
}

// '/' 없는 일반 입력 — 기존 inbound_add.php 로직 그대로
if (/^\d{8,}$/.test(val)) {
    debounceSearch(val, 200, pendingQty || 1);
} else if (val.length >= 2) {
    debounceSearch(val, 400, pendingQty || 1);
}
```

- `pendingQty`는 상품이 장바구니에 추가된 직후 `1`로 리셋되고 배지가 사라짐
- 기존 `/^\d{8,}$/` 정규식은 `/`가 포함된 입력에 대해서는 절대 매칭되지 않으므로 (정규식 자체가 숫자만 허용) 충돌 없음 — `/` 분기를 먼저 검사하는 것만으로 안전하게 분리됨

---

## 6. Error Handling

| Case | Handling |
|------|----------|
| CSRF 토큰 불일치 | `submit`에서 `{success:false, message:'Security error'}`, HTTP 200 + JS에서 alert |
| `store_id` 미선택 | 클라이언트에서 등록 버튼 disabled로 사전 차단, 서버에서도 검증 후 실패 응답 |
| `items` 빈 배열 | 서버에서 `{success:false, message:'장바구니가 비어있습니다.'}` |
| 바코드 검색 결과 0건 | "상품을 찾을 수 없습니다" 메시지, 장바구니 변경 없음 |
| `lc_fifo_ship_allow_negative` 처리 중 DB 예외 | `$conn->rollback()`, `{success:false, message: $e->getMessage()}` |
| 재고 0 + 입고 이력 없음 상품 | `lc_order_item_lots`에 `inventory_id=NULL`로 기록, 정상 처리 (출고 차단 안 함) |
| 네트워크 오류(fetch 실패) | "등록에 실패했습니다. 다시 시도해 주세요" alert, 버튼 재활성화 |

---

## 7. Security Considerations

- `lc_require_staff()`로 페이지/ajax 모두 접근 제어 (FR-10)
- `submit` 액션은 CSRF 토큰 검증 필수 (`lc_verify_csrf()` 또는 `hash_equals` 패턴, `distribute_to_stores.php`와 동일)
- 모든 SQL은 prepared statement 사용 (기존 컨벤션)
- `items` JSON 파싱 시 `product_id`, `quantity`는 정수로 캐스팅 후 `> 0` 검증 (quantity는 항상 양수 — 음수 재고는 서버 내부 lot 차감 로직에서만 발생, 사용자 입력값은 양수 강제)

---

## 8. Test Plan

### 8.1 L1 — API/Function Tests

| ID | Scenario | Expected |
|----|----------|----------|
| L1-01 | `get_stores` 호출 | `success:true`, stores 배열 반환 |
| L1-02 | `get_product_stock?product_id={유효}` | `success:true`, avg_cost/total_stock 포함 |
| L1-03 | `submit` (재고 충분한 상품 1개, qty=5) | lc_orders 1건(shipped), lc_order_items 1건(unit_price=평균원가), lc_order_item_lots N건, lc_inventory.quantity_out 증가 |
| L1-04 | `submit` (재고보다 많은 qty 요청) | 정상 처리, 마지막 lot의 `quantity_remain`이 음수가 됨 |
| L1-05 | `submit` (재고 0 + 입고이력 없음 상품) | 정상 처리, `lc_order_item_lots.inventory_id IS NULL`, unit_price = lc_products.cost_price |
| L1-06 | `submit` CSRF 토큰 누락/불일치 | `success:false`, DB 변경 없음 |
| L1-07 | `lc_fifo_ship_allow_negative` 단위 호출 (재고 충분) | 기존 `lc_fifo_ship`과 동일 결과 |

### 8.2 L2 — UI Action Tests

| ID | Scenario | Expected |
|----|----------|----------|
| L2-01 | 8자리 숫자 바코드 입력 | 200ms 후 검색, 결과 1건이면 장바구니에 수량 1로 추가 |
| L2-02 | `8/` 입력 후 바코드 스캔 | 장바구니에 수량 8로 추가, 배지 사라짐 |
| L2-03 | 검색 결과 복수 | 드롭다운 표시, ↑↓/Enter로 선택 가능 |
| L2-04 | 장바구니 수량 인라인 수정 | 소계/합계 즉시 갱신 |
| L2-05 | 행 삭제 | 장바구니에서 제거, 합계 갱신 |
| L2-06 | 지점 미선택 상태 | "출고 등록" 버튼 disabled |

### 8.3 L3 — E2E Scenario Tests

| ID | Scenario | Expected |
|----|----------|----------|
| L3-01 | 지점 선택 → 바코드 2개 스캔(하나는 `5/` 프리픽스) → 출고 등록 | `order_detail.php?id=...`로 이동, 플래시 메시지 표시, Order Detail에 2개 항목·올바른 unit_price 표시 |
| L3-02 | L3-01 직후 Outbound History 페이지 확인 | 방금 등록한 출고 건이 status='shipped'로 목록에 표시됨 |

### 8.4 Seed Data

- `lc_products`에 재고가 충분한 상품 1개(barcode 보유), 재고가 0인 상품 1개(입고이력 있음), 재고/입고이력 모두 없는 상품 1개
- `stores` 테이블에 점포 최소 1개

---

## 9. Clean Architecture

본 기능은 PHP 절차형 + partials 구조의 기존 logistics 모듈 컨벤션을 그대로 따르므로 별도의 레이어 분리(Controller/Service/Repository)는 적용하지 않는다 (N/A). `lib/inventory_helper.php`가 사실상의 도메인 로직 레이어 역할을 하며, `ajax/branch_outbound.php`가 액션 디스패처 겸 컨트롤러 역할을 한다.

---

## 10. Coding Convention Reference

- PHP: `lc_` 접두사 함수, mysqli prepared statement, `lc_require_staff()` / `lc_csrf_token()` / `lc_verify_csrf()` 사용
- 파일 구조: `partials/header.php` + 콘텐츠 + `partials/footer.php`
- Tailwind teal 테마 (`teal-600` 버튼, `teal-50`/`teal-100` hover/active)
- ajax 응답: `{success: bool, ...}` JSON, `header('Content-Type: application/json; charset=utf-8')`

---

## 11. Implementation Guide

### 11.1 File Structure

```
logistics/
├── branch_outbound.php          (신규)
├── ajax/
│   └── branch_outbound.php      (신규)
├── lib/
│   └── inventory_helper.php     (수정: lc_fifo_ship_allow_negative 추가)
└── partials/
    └── header.php                (수정: 사이드바 메뉴 추가)
```

### 11.2 Implementation Order

1. `lib/inventory_helper.php`에 `lc_fifo_ship_allow_negative()` 추가 (단독 단위 테스트 가능)
2. `ajax/branch_outbound.php` 작성: `get_stores`, `get_product_stock`(distribute_to_stores.php 로직 재사용), `submit`
3. `partials/header.php`에 사이드바 메뉴 항목 추가 (데스크톱 + 모바일)
4. `branch_outbound.php` 페이지 작성 (지점 선택 + 바코드 스캔 + 장바구니 UI, inbound_add.php JS 패턴 차용 + `숫자/` 파싱 추가)
5. 수동 QA: L1 → L2 → L3 순으로 검증

### 11.3 Session Guide

#### Module Map

| Module | Files | Depends On |
|--------|-------|------------|
| module-1: FEFO 음수 허용 함수 | `lib/inventory_helper.php` | - |
| module-2: ajax 엔드포인트 | `ajax/branch_outbound.php` | module-1 |
| module-3: 사이드바 메뉴 | `partials/header.php` | - |
| module-4: 페이지 UI | `branch_outbound.php` | module-2, module-3 |

#### Recommended Session Plan

- **Session 1**: module-1 + module-2 (백엔드 로직 + API, L1 테스트로 검증 가능)
- **Session 2**: module-3 + module-4 (프론트엔드 UI, L2/L3 테스트)

```
/pdca do branch-outbound --scope module-1,module-2
/pdca do branch-outbound --scope module-3,module-4
```

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-06-10 | Initial draft | whdans007 |
