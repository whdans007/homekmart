---
template: design
version: 1.0
feature: mall-fresh-products
date: 2026-09-06
author: whdans007
project: HOME K MART
version_project: '-'
---

# mall-fresh-products Design Document

> **Summary**: 몰 신선상품(과일/채소/정육/수산)을 고객이 100g 단위(무게 상품) 또는 개수 단위(낱개 상품)로 주문하고, 점포 직원이 준비중 단계에서 실측 무게를 입력해 확정금액을 계산, 배송 시 현금(COD)으로 정산하는 전체 흐름의 상세 기술 설계.
>
> **Project**: HOME K MART
> **Version**: -
> **Author**: whdans007
> **Date**: 2026-09-06
> **Status**: Draft
> **Planning Doc**: [mall-fresh-products.plan.md](../01-plan/features/mall-fresh-products.plan.md)

### Pipeline References (if applicable)

| Phase | Document | Status |
|-------|----------|--------|
| Phase 1 | Schema Definition | N/A (미사용) |
| Phase 2 | Coding Conventions | N/A (CLAUDE.md 컨벤션으로 대체) |
| Phase 3 | Mockup | N/A |
| Phase 4 | API Spec | 본 문서 §4에서 직접 정의 |

> ⚠️ **Plan 문서와의 차이(중요)**: `mall-fresh-products.plan.md`의 FR-02/FR-03(점포 상품코드 ↔ 몰 대표코드 자동 매칭 후보 제안, `mall_fresh_product_store_links`)은 **더 이상 유효하지 않다**. 이후 별도 작업(`fresh-purchase-remove-store-product-match.plan.md`, `fresh-purchase-remove-store-product-column.plan.md`)에서 "실사용에서 불필요"하다는 사용자 판단에 따라 매칭 기능이 완전히 제거되었고, 현재 매입 등록(`admin/add_fresh_purchase_item.php`)은 `mall_fresh_products`(몰 대표코드)를 직접 선택해 매입을 기록한다. `mall_fresh_product_store_links` 테이블은 런타임에서 전혀 참조되지 않는 죽은 설계다. 이번 Design은 **이 현재 구조를 그대로 전제**하고, 매칭 관련 요구사항은 스코프에서 제외한다. 원가/마진(FR-04, FR-10 상당)은 `fresh_margin_rules` + `lib/fresh_margin_helper.php`로 이미 별도 구현되어 있어 재사용한다.

---

## 1. Overview

### 1.1 Design Goals

- 고객이 신선상품을 카테고리 화면에서 발견하고, 무게(100g) 또는 개수 단위로 주문할 수 있게 한다
- 기존 정가상품 장바구니/주문 파이프라인(`mall/lib/cart.php`, `mall/lib/order.php`, `mall_orders`/`mall_order_items`)을 **전혀 변경하지 않고** 완전히 분리된 신선상품 전용 테이블로 병행 처리한다(이번 프로젝트가 신선상품 매입 때부터 일관되게 지켜온 원칙)
- 무게 상품은 "예상금액"(주문 시)과 "확정금액"(실측 후)이 다를 수 있음을 구조적으로 표현하고, 배송 시 확정금액 기준 COD로 정산한다
- 기존 접수확인→준비중→준비완료→배정→배송 파이프라인(`mall-delivery-dispatch` 설계)에 실측 입력 단계만 자연스럽게 끼워 넣는다

### 1.2 Design Principles

- **완전 분리 유지**: `products`/`inventory`/`purchase_items`/`mall_products`/`mall_cart_items`/`mall_order_items` 스키마 무변경. 신선상품 전용 카트/주문 테이블을 신설한다(Plan 원안은 `mall_order_items`를 확장하려 했으나, 이 프로젝트가 이미 `fresh_purchase_items`를 `purchase_items`와 분리한 것과 동일한 이유로 — `mall_order_items.product_id`가 `NOT NULL` FK라 신선 전용 행을 넣으려면 그 컬럼을 NULL 허용으로 바꿔야 하는데, 이는 정가상품 주문 로직 전체의 암묵적 가정을 흔드는 위험한 변경이다. §2.0 Option 비교 참고)
- **판매방식(sale_type)에 따라 흐름이 갈린다**: `weight`(저울)만 100g 단위 주문 + 실측 입력이 필요하고, `piece`(낱개/정가)는 고정가 × 개수로 이미 확정된 금액이라 실측 단계가 필요 없다 — 이 부분은 Plan 원안에 없던, 실제 `mall_fresh_products.sale_type` 스키마를 반영한 이번 Design의 핵심 보강 사항이다
- **기존 함수형 헬퍼 패턴 재사용**: `mall/lib/cart.php`/`order.php`와 대칭되는 `mall/lib/fresh_cart.php`/`fresh_order.php`를 신설해 같은 스타일(오너 절 IDOR 방지, 트랜잭션, 서버 재계산)을 따른다
- **원가/마진은 이미 구현된 것을 재사용**: `fresh_margin_rules`, `lib/fresh_margin_helper.php`(`calculate_fresh_sale_price`, `get_fresh_margin_rate`) — 새로 만들지 않는다

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: mall_order_items 확장(Plan 원안) | Option B: 완전 분리 신규 테이블 | Option C: 카트만 분리, 주문은 통합 |
|----------|:-:|:-:|:-:|
| **Approach** | `mall_order_items.product_id`를 NULL 허용으로 변경 + 신선 컬럼 추가 | `mall_fresh_cart_items`/`mall_fresh_order_items` 신규 테이블 | 카트는 분리하되 체크아웃 시 `mall_order_items`에 함께 INSERT(더미 product_id 필요) |
| **New Tables** | 0 | 2 | 1 |
| **Modified Tables** | `mall_order_items`(컬럼 추가 + `product_id` 제약 완화) | 0 | `mall_order_items`(더미 FK 문제 그대로 남음) |
| **Risk** | High — `product_id NOT NULL FK`를 완화하면 기존 정가상품 주문 조회/합계 로직(`mall_recalculate_order_totals` 등)이 암묵적으로 product_id 존재를 가정하는 부분이 있는지 전수 재검토 필요 | Low — 기존 테이블 무변경, 기존 코드 회귀 위험 없음 | Medium — 더미 product_id를 만들어야 하는 억지 설계, 두 세계가 뒤섞임 |
| **Query Complexity(주문 조회)** | 낮음(테이블 1개) | 중간(두 테이블 UNION 필요) | 낮음 |
| **Recommendation** | 비권장 | **Default choice** | 비권장 |

**Selected**: Option B — **Rationale**: 이 기능의 최우선 제약(Plan §1.4, 이번 Design 서두)은 "기존 정가상품 파이프라인 무변경"이다. `mall_order_items.product_id`는 현재 `NOT NULL` FK로 정가상품 조회/재계산 로직 전반이 이를 전제한다. 완전 분리 테이블은 주문 조회 시 UNION이 필요해 약간의 복잡도가 늘지만, 이미 `fresh_purchase_items`를 `purchase_items`와 분리한 이 프로젝트의 일관된 리스크 회피 패턴과 맞고, 정가상품 흐름에 회귀 위험이 전혀 없다.

> 아래 상세 설계는 Option B 기준으로 작성됨.

### 2.1 Component Diagram

```
┌──────────────┐   ┌─────────────────────────────┐   ┌───────────────────────────┐
│   Browser    │──▶│ mall/fresh_product.php│──▶│  MySQL (기존 DB)          │
│ (고객)        │   │ mall/cart.php (확장)         │   │  mall_fresh_products(기존)│
├──────────────┤   │ mall/order_checkout.php(확장)│   │  mall_fresh_cart_items    │
│   Browser    │──▶│                              │   │   (신규)                  │
│ (관리자,      │   │ mall/admin/orders.php(확장)  │   │  mall_fresh_order_items   │
│  준비중 실측) │   │  ── 실측 입력 모달/AJAX       │   │   (신규)                  │
└──────────────┘   └──────────────┬───────────────┘   └───────────────────────────┘
                                   │ require
                                   ▼
                    ┌───────────────────────────┐
                    │ mall/lib/fresh_cart.php    │ (신규 — mall_cart_* 대칭)
                    │ mall/lib/fresh_order.php   │ (신규 — mall_create_order 등 대칭)
                    │ mall/lib/catalog.php (확장)│ — mall_get_eligible_fresh_products() 신규 함수(별도 조회, UNION 아님)
                    │ mall/lib/order.php (확장)  │ — mall_recalculate_order_totals에 신선 합산
                    └─────────────┬──────────────┘
                                  │ mall_get_db_connection() / get_db_connection()
                                  ▼
                    ┌───────────────────────────┐
                    │ config/db_config.php (기존)│
                    └───────────────────────────┘
```

### 2.2 Data Flow

```
[발견] 카테고리 화면(mall_get_eligible_products 확장) → 신선상품 카드(정가상품과 같은 그리드에 뱃지로 구분)
       → 클릭 → fresh_product.php

[주문 — weight 타입] 100g 단위 +/- 스테퍼 → 예상금액 실시간 계산(JS, price_per_100g 기준)
       → "장바구니 담기" → mall_fresh_cart_add(weight_g 저장)
       → 체크아웃 → mall_fresh_create_order_items() → estimated_price 스냅샷 저장, actual_weight_g/confirmed_price는 NULL

[주문 — piece 타입] 개수 스테퍼(정가상품과 동일 UX) → 장바구니 담기 → 체크아웃
       → estimated_price = confirmed_price = price_per_100g(고정가) × quantity 로 즉시 확정 저장(실측 불필요)

[준비중 실측 — weight 타입만] 관리자 orders.php 준비중 화면에서 신선 라인에 "실측 무게(g)" 입력
       → mall_fresh_confirm_weight() → actual_weight_g 저장 + confirmed_price = round(actual_weight_g/100 * unit_price_snapshot, 2)
       → mall_recalculate_order_totals()가 정가상품 line_total + 신선상품 confirmed_price(또는 미실측이면 estimated_price)를 합산해 mall_orders 재계산
       → 준비완료 처리는 "이 주문의 모든 weight 타입 신선 라인이 실측 완료"일 때만 허용(차단 규칙)

[배송/정산] 피킹슬립/영수증(order_print.php, order_receipt.php)에 확정금액(estimated 대비 다르면 둘 다) 표시
       → 배송기사 COD 수령은 mall_orders.total_amount(확정 반영된 최종값) 기준 — 별도 정산 로직 불필요(기존 흐름 그대로 재사용)
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `mall/lib/fresh_cart.php` | `mall/lib/pricing.php`(참고만, 실제로는 안 씀), `mall_get_db_connection()` | 신선 장바구니 CRUD |
| `mall/lib/fresh_order.php` | `mall/lib/fresh_cart.php`, `mall/lib/order.php`(공유 트랜잭션/주소 로직 재사용) | 체크아웃 시 신선 라인 INSERT, 실측 확정 |
| `mall/lib/catalog.php`(확장) | `mall_fresh_products` | `mall_get_eligible_fresh_products()` 신규 함수로 카테고리별 신선상품 목록 별도 조회 |
| `mall/admin/orders.php`(확장) | `mall/lib/fresh_order.php` | 준비중 화면 실측 입력 UI |
| `mall/admin/order_print.php`, `order_receipt.php`(확장) | `mall/lib/fresh_order.php` | 확정금액 표시 |

---

## 3. Data Model

### 3.1 mall_fresh_products 확장 (표시용 참고 — 이미 존재/이미 관리자 큐레이션 작업에서 확장됨)

이번 기능은 아래 컬럼을 **읽기만** 한다(신규 컬럼 추가 없음): `category_id`(카테고리 노출 대상), `sale_type`, `price_per_100g`, `status`, `is_sold_out`, `image_url`. (`category_id`/`is_sold_out`/할인 플래그는 `mall-fresh-curation-tab` 작업에서 이미 추가/활용 중.)

### 3.2 mall_fresh_cart_items (신규)

```sql
CREATE TABLE `mall_fresh_cart_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) DEFAULT NULL,
  `guest_token` varchar(64) DEFAULT NULL,
  `mall_fresh_product_id` int(11) NOT NULL,
  `weight_g` int(11) DEFAULT NULL COMMENT 'sale_type=weight일 때만 값 존재(100g 단위)',
  `quantity` int(11) DEFAULT NULL COMMENT 'sale_type=piece일 때만 값 존재(개수)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_product` (`member_id`,`mall_fresh_product_id`),
  UNIQUE KEY `guest_product` (`guest_token`,`mall_fresh_product_id`),
  KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
  CONSTRAINT `mfci_ibfk_1` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='신선상품 전용 장바구니(정가상품 mall_cart_items와 완전 분리)';
```

> `mall_cart_items`와 달리 `channel`(retail/wholesale) 컬럼이 없다 — 신선상품은 채널 구분 없이 단일가다(§1.2).

### 3.3 mall_fresh_order_items (신규 — 가격 스냅샷)

```sql
CREATE TABLE `mall_fresh_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `mall_fresh_product_id` int(11) NOT NULL,
  `product_name_snapshot` varchar(255) NOT NULL,
  `sale_type_snapshot` enum('piece','weight') NOT NULL,
  `unit_price_snapshot` decimal(10,2) NOT NULL COMMENT 'weight: 100g당 단가 / piece: 개당 고정가',
  `weight_g` int(11) DEFAULT NULL COMMENT '고객 요청 그램수(주문 시점, weight만)',
  `actual_weight_g` int(11) DEFAULT NULL COMMENT '준비중 실측 그램수(weight만, 입력 전 NULL)',
  `quantity` int(11) DEFAULT NULL COMMENT '개수(piece만)',
  `estimated_price` decimal(12,2) NOT NULL COMMENT '주문 시점 예상금액',
  `confirmed_price` decimal(12,2) DEFAULT NULL COMMENT 'weight: 실측 후 확정 / piece: INSERT 시점에 estimated_price와 동일하게 즉시 채움',
  `is_sold_out` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
  CONSTRAINT `mfoi_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mfoi_ibfk_2` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='신선상품 전용 주문 항목(가격 스냅샷). mall_order_items와 완전 분리, order_id로만 연결';
```

> `mall_orders`는 **스키마 변경 없음**. `mall_fresh_order_items.order_id`가 기존 `mall_orders.id`를 참조하는 것만으로 한 주문에 정가+신선 라인이 함께 담긴다.

### 3.4 Entity Relationships

```
[mall_orders] 1 ──── N [mall_order_items]        (기존, 무변경)
[mall_orders] 1 ──── N [mall_fresh_order_items] ──── 1 [mall_fresh_products]  (신규)
[mall_fresh_cart_items] N ──── 1 [mall_fresh_products]  (신규, 회원/게스트별 owner)
```

---

## 4. API Specification

> 기존과 동일하게 PHP AJAX 엔드포인트(`ajax_*.php` → JSON `{success, data, error}`) 패턴을 사용한다.

### 4.1 Endpoint List

| Endpoint | Description | Auth |
|--------|------|------|
| `mall/ajax/fresh_cart_add.php` | 신선상품 장바구니 담기/수량(그램) 변경 | 회원 또는 게스트 |
| `mall/ajax/fresh_cart_remove.php` | 신선상품 장바구니 삭제 | 회원 또는 게스트(소유자만) |
| `mall/admin/ajax/confirm_fresh_weight.php` | 준비중 화면에서 실측 무게 입력 → 확정금액 계산 | admin + `mall_management` |

체크아웃(`mall/order_checkout.php` → 기존 주문 생성 엔드포인트)과 카트 조회(`mall/cart.php`)는 **신규 엔드포인트를 추가하지 않고** 기존 파일 내부에서 `mall_fresh_cart_get_summary()`/`mall_fresh_create_order_items()`를 함께 호출하도록 확장한다(고객 입장에서 "장바구니/주문은 하나"이므로 화면과 엔드포인트를 억지로 분리하지 않음).

### 4.2 Detailed Specification

#### `POST mall/ajax/fresh_cart_add.php`

**Request:** `mall_fresh_product_id`, `weight_g`(weight 타입) 또는 `quantity`(piece 타입), `csrf_token`

**처리:** `sale_type` 조회 후 대응하는 파라미터만 검증(weight_g는 100의 배수·양수, quantity는 양의 정수). `mall_fresh_cart_add()` — `ON DUPLICATE KEY UPDATE`로 기존 담긴 값을 덮어씀(정가상품처럼 "더하기"가 아니라 "이 상품을 이만큼 담겠다"는 최종값 지정 — 무게 스테퍼 UX와 더 자연스럽게 맞음).

**Response:** `{success:true, data:{cart_item_id, weight_g|quantity, estimated_price}}`

#### `POST mall/admin/ajax/confirm_fresh_weight.php`

**Request:** `mall_fresh_order_item_id`, `actual_weight_g`, `csrf_token`

**처리:** 대상 라인의 `sale_type_snapshot`이 `weight`인지 확인(아니면 `INVALID_STATE`). `confirmed_price = round(actual_weight_g / 100 * unit_price_snapshot, 2)` 계산 후 저장, 이어서 `mall_recalculate_order_totals($conn, $order_id)`(§4.3 확장) 호출.

**Response:** `{success:true, data:{confirmed_price, order_totals:{subtotal, total_amount, ...}}}`

### 4.3 기존 함수 확장 (신규 엔드포인트 아님)

| 함수 | 위치 | 확장 내용 |
|------|------|-----------|
| `mall_get_eligible_fresh_products($category_id, $search, $limit)` | `mall/lib/catalog.php` | **(신규 함수, Do 단계에서 구현 확정 — 원안의 "UNION" 대신 별도 함수로 변경)** `mall_fresh_products`(해당 category_id + 하위 소분류, status=active, is_sold_out=0)를 조회해 반환. `mall_get_eligible_products()`와 같은 배열에 절대 합치지 않는다 — `mall_fresh_products.id`와 `products.id`는 다른 id 공간이라, 한 배열로 섞으면 `mall_build_product_cards()`가 신선상품 id를 정가상품 id로 착각해 `mall_calculate_price()`/`mall_get_stock_quantity()`를 잘못 호출할 위험이 있다. 화면(카테고리 그리드)이 두 함수를 각각 호출해 `item_type`으로 구분 렌더링한다 |
| `mall_create_order()` | `mall/lib/order.php` | 정가상품 INSERT 이후, `mall_fresh_cart_get_summary()`로 신선 장바구니도 조회해 같은 `$order_id`로 `mall_fresh_order_items` INSERT + `mall_fresh_cart_items` 비우기(같은 트랜잭션). subtotal/total에는 신선상품 예상금액이 할인 없이 그대로 합산됨(§3.3 참고) |
| `mall_recalculate_order_totals()` | `mall/lib/order.php` | `mall_fresh_order_items`도 함께 SELECT해 `is_sold_out=0`인 라인의 금액(확정금액 있으면 확정금액, 없으면 예상금액)을 subtotal/total에 합산 |

### Error Codes 추가

| Code | Message | Cause |
|------|---------|-------|
| `INVALID_STATE` | 무게 상품이 아닙니다 | piece 타입 라인에 실측 입력 시도 |
| `PREPARING_BLOCKED` | 아직 실측 입력이 끝나지 않았습니다 | weight 타입 신선 라인이 남아있는데 "준비완료" 처리 시도 |

---

## 5. UI/UX Design

### 5.1 Screen Layout

```
[고객] mall/index.php, mall/category.php 등 카테고리 그리드 ── 신선상품 카드에 "신선" 뱃지
       mall/fresh_product.php (신규) ── 상세페이지, sale_type에 따라 스테퍼 UI 분기
       mall/cart.php (확장) ── 신선 라인 표시(무게/개수, 예상금액 안내 문구)
       mall/order_detail.php (확장) ── 신선 라인에 "실측 완료 전: 예상금액" / "실측 완료: 확정금액" 배지

[관리자] mall/admin/orders.php (확장) ── 준비중 화면, weight 타입 신선 라인마다 "실측 입력(g)" 인풋+저장
         mall/admin/order_print.php, order_receipt.php (확장) ── 확정/예상 금액 함께 표시
```

### 5.2 User Flow

```
[고객] 카테고리에서 신선상품 발견 → 상세페이지 → (무게: 100g 스테퍼 / 개수: 수량 스테퍼) → 장바구니 → 체크아웃(정가상품과 한 번에)
[관리자] 접수확인 → 준비중 화면에서 신선 weight 라인 실측 입력(전부 완료해야 준비완료 버튼 활성화) → 이후 기존 배정/배송 흐름 그대로
[배송] 확정금액 반영된 mall_orders.total_amount 기준 COD 수령 — 기존 배송 파이프라인 변경 없음
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| 신선상품 카드 뱃지 | 기존 카테고리/홈 그리드 컴포넌트 | `item_type==='fresh'`일 때 뱃지 표시, 클릭 시 `fresh_product.php`로 이동 |
| 무게 스테퍼 | `mall/fresh_product.php` | 100g 단위 +/-, 실시간 예상금액 계산(JS) |
| 실측 입력 위젯 | `mall/admin/orders.php`(인라인) | weight 신선 라인마다 그램 입력 + 저장, 확정 전/후 상태 표시 |

### 5.4 Page UI Checklist

#### 고객 — 신선상품 상세 (`mall/fresh_product.php`, 신규)
- [ ] weight 타입: 100g 단위 +/- 스테퍼, 실시간 예상금액(`price_per_100g × 그램/100`) 표시, "100g당 가격" 안내 문구
- [ ] piece 타입: 정가상품과 동일한 정수 수량 스테퍼(무게 관련 UI 전혀 없음)
- [ ] 이미지: `mall_fresh_products.image_url` 단일 이미지
- [ ] 품절(`is_sold_out=1`) 시 담기 버튼 비활성화

#### 고객 — 장바구니 (`mall/cart.php`, 기존 화면 확장)
- [ ] 신선 라인 구분 표시("예상금액이며 실제 무게에 따라 달라질 수 있습니다" 안내 문구, weight 타입만)
- [ ] 정가상품 라인과 함께 하나의 합계에 포함

#### 관리자 — 주문 준비중 (`mall/admin/orders.php`, 기존 화면 확장)
- [ ] weight 신선 라인: 실측 무게(g) 입력 인풋 + "저장" 버튼, 저장 시 확정금액 즉시 표시 + 주문 합계 갱신
- [ ] piece 신선 라인: 이미 확정된 금액만 표시(입력 UI 없음)
- [ ] "준비완료" 버튼: weight 신선 라인 중 미실측이 하나라도 있으면 비활성화 + 안내 문구

#### 관리자 — 피킹슬립/영수증 (`order_print.php`, `order_receipt.php`, 기존 화면 확장)
- [ ] 신선 라인: 상품명, (실측 전) "예상 {g}g" 또는 (실측 후) "확정 {g}g", 금액

---

## 6. Error Handling

### 6.1 Error Response Format

기존과 동일: `{"success": false, "error": {"code": "...", "message": "...", "details": {}}}`

### 6.2 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| VALIDATION_ERROR | 입력값을 확인해주세요 | weight_g가 100의 배수가 아니거나 quantity가 정수가 아님 | 필드별 안내 |
| SOLD_OUT | 품절된 상품입니다 | `is_sold_out=1`인 신선상품 담기 시도 | 담기 차단 |
| INVALID_STATE | 무게 상품이 아닙니다 | piece 라인에 실측 입력 시도 | 400 |
| PREPARING_BLOCKED | 아직 실측 입력이 끝나지 않았습니다 | 미실측 상태에서 준비완료 시도 | 버튼 비활성화(서버도 재검증) |

---

## 7. Security Considerations

- [ ] 모든 쿼리 prepared statement
- [ ] `mall_fresh_cart_items`의 소유자 검증은 `mall_cart_owner_clause()`와 동일한 패턴(`member_id` 우선, 없으면 `guest_token`)을 `mall/lib/fresh_cart.php`에서도 그대로 구현
- [ ] 실측 입력(`confirm_fresh_weight.php`)은 `mall_management` 권한 + CSRF, 대상 `order_id`가 실제 존재하고 `preparing` 상태인지 재검증
- [ ] 서버는 클라이언트가 보낸 예상금액을 신뢰하지 않고 `price_per_100g`을 다시 조회해 재계산(기존 `mall_calculate_price` 재계산 원칙과 동일)

---

## 8. Test Plan

> Playwright/E2E 미구성 — L1은 curl 기반, L2/L3는 수동 브라우저 체크리스트.

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1 | `mall/ajax/fresh_cart_add.php`, `confirm_fresh_weight.php` | curl | Do |
| L2 | §5.4 체크리스트 | 수동 브라우저 | Do/Check |
| L3 | 고객 주문 → 관리자 실측 → 배송 전체 흐름 | 수동 멀티 시나리오 | Do/Check |

### 8.2 L1: API Test Scenarios

| # | Endpoint | Test | Expected |
|---|----------|------|----------|
| 1 | `fresh_cart_add.php` | weight 타입에 weight_g=150(100의 배수 아님) | `VALIDATION_ERROR` |
| 2 | `fresh_cart_add.php` | piece 타입에 quantity=2 | `estimated_price = confirmed_price = price×2` |
| 3 | `confirm_fresh_weight.php` | piece 라인에 실측 입력 시도 | `INVALID_STATE` |
| 4 | `confirm_fresh_weight.php` | weight 라인에 정상 실측 입력 | `confirmed_price` 갱신 + `mall_orders.total_amount` 갱신 |
| 5 | 준비완료 처리 | weight 라인 미실측 상태로 시도 | `PREPARING_BLOCKED` |

### 8.3 L3: E2E Scenario

| # | Scenario | Steps | Success Criteria |
|---|----------|-------|-------------------|
| 1 | weight 신선상품 전체 흐름 | 고객: 300g 담기→체크아웃 → 관리자: 접수확인→준비중→실측 320g 입력→준비완료→배정→배송→완료 | 최종 `mall_orders.total_amount`에 확정금액(320g 기준) 반영, 영수증에 확정 표시 |
| 2 | piece 신선상품 흐름 | 고객: 3개 담기→체크아웃 → 관리자: 접수확인→준비중(실측 UI 없음)→준비완료 | 실측 단계 없이 바로 준비완료 가능, 금액 불변 |

---

## 9. Clean Architecture (프로젝트 컨벤션 매핑)

| Layer | Responsibility | Location |
|-------|---------------|----------|
| **Presentation** | 상세페이지/장바구니/체크아웃 화면, 준비중 실측 UI | `mall/fresh_product.php`, `mall/cart.php`, `mall/admin/orders.php` |
| **Application** | 신선 카트/주문 유스케이스 | `mall/lib/fresh_cart.php`, `mall/lib/fresh_order.php` |
| **Infrastructure** | DB 커넥션 | `mall/config/mall_config.php`, `config/db_config.php` |

---

## 10. Coding Convention Reference

| Item | Convention Applied |
|------|--------------------|
| 함수 네이밍 | `mall_fresh_` 접두사 (예: `mall_fresh_cart_add()`, `mall_fresh_confirm_weight()`) — 정가상품 `mall_cart_*`/`mall_create_order`와 대칭 |
| 에러 응답 | 기존 `{success, data, error:{code,message}}` 포맷 재사용 |
| 원가/마진 | 새로 만들지 않고 `lib/fresh_margin_helper.php` 재사용 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
mall/
├── lib/
│   ├── fresh_cart.php          (신규) mall_fresh_cart_add/remove/get_summary 등
│   ├── fresh_order.php         (신규) 체크아웃 시 신선 라인 생성, 실측 확정, 준비완료 차단 검증
│   ├── catalog.php             (수정) mall_get_eligible_fresh_products() 신규 함수 추가(UNION 아님)
│   └── order.php               (수정) mall_create_order / mall_recalculate_order_totals 확장
├── fresh_product.php    (신규) 고객 상세페이지
├── cart.php                    (수정) 신선 라인 표시
├── order_checkout.php          (수정) 신선 라인 함께 확정
├── order_detail.php            (수정) 신선 라인 예상/확정 배지
├── ajax/
│   ├── fresh_cart_add.php      (신규)
│   └── fresh_cart_remove.php   (신규)
└── admin/
    ├── orders.php               (수정) 준비중 실측 입력 UI
    ├── order_print.php          (수정)
    ├── order_receipt.php        (수정)
    └── ajax/
        └── confirm_fresh_weight.php  (신규)

sql/migrations/
└── run_add_mall_fresh_order_tables.php  (신규) mall_fresh_cart_items + mall_fresh_order_items 생성
```

### 11.2 Implementation Order

1. [ ] DB 마이그레이션(`mall_fresh_cart_items`, `mall_fresh_order_items` 신규 테이블) — 기존 테이블 무변경 확인
2. [ ] `mall/lib/fresh_cart.php`, `mall/lib/fresh_order.php` 구현 + `mall/lib/catalog.php`/`order.php` 확장
3. [ ] 고객 화면(상세페이지, 장바구니/체크아웃 확장, 주문상세 배지)
4. [ ] 관리자 화면(준비중 실측 입력, 준비완료 차단, 피킹슬립/영수증 표시)
5. [ ] L1/L2/L3 테스트 + 문서화

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description |
|--------|-----------|-------------|
| DB + lib 헬퍼 | `module-1` | 마이그레이션, `fresh_cart.php`, `fresh_order.php`, `catalog.php`/`order.php` 확장 |
| 고객 화면 | `module-2` | 상세페이지, 카트/체크아웃/주문상세 확장 |
| 관리자 화면 | `module-3` | 준비중 실측 UI, 준비완료 차단, 영수증/피킹슬립 |

#### Recommended Session Plan

| Session | Phase | Scope |
|---------|-------|-------|
| Session 1 | Plan + Design | 전체 (완료) |
| Session 2 | Do | `module-1` |
| Session 3 | Do | `module-2` |
| Session 4 | Do | `module-3` |
| Session 5 | Check + Report | 전체 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-06 | Initial draft — Option B(완전 분리) 선택, Plan 원안의 매칭 기능(FR-02/03) 폐기 반영, sale_type별 흐름 분기 추가 | whdans007 |
