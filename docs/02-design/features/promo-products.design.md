# promo-products Design Document

> **Summary**: 유통기한 임박 LOT 재고를 할인가로 별도 관리·출고하는 "프로모 상품" 기능의 기술 설계
>
> **Project**: HOME K MART — logistics(물류센터) 모듈
> **Author**: Claude Code (Codex 사전 상담 + 사용자 체크포인트 반영)
> **Date**: 2026-09-18
> **Status**: Draft
> **Planning Doc**: [promo-products.plan.md](../01-plan/features/promo-products.plan.md)

---

## Context Anchor

> Plan 문서에서 복사(2026-09-18 Design 체크포인트 반영 최신본)

| Key | Value |
|-----|-------|
| **WHY** | 유통기한 임박 LOT 재고가 정상가로만 출고돼 폐기 손실 위험이 크고, 임박 재고를 할인으로 소진시킬 수단이 없음 |
| **WHO** | 물류직원(`lc_require_staff()`, 할인 등록/취소), 점포 발주 담당자(`order_new.php`, 할인 LOT 조회/주문) |
| **RISK** | `lc_allocate_order_stock()`의 FEFO 음수 허용 차감 로직이 할인 LOT에 그대로 적용되면 정책 위반 가능 — 승인 시점 서버 재검증 필수 |
| **SUCCESS** | (1) LOT 단위 할인 등록/취소 (2) 프로모 상품 메뉴에 활성 LOT만 자동 노출 (3) 승인 시 정확히 해당 LOT에서 할인가로 차감, 초과분은 자동 분할 |
| **SCOPE** | 1차(Option B, 완전 자동화): 할인 등록/취소, LOT 분리 주문행, 승인 시 자동 차감+분할, 동시성 보호. 후속: 부분 출고(분할 배송), 주문 생성 시점 재고 예약/홀드 |

---

## 1. Overview

### 1.1 Design Goals

- 기존 `lc_inventory`(LOT)/`lc_order_items`/`lc_order_item_lots` 구조와 `lc_allocate_order_stock()` 승인 시점 차감 흐름을 최대한 재사용하면서, 프로모션 LOT만 별도 검증·차감 경로로 분기한다.
- 물류직원의 등록/취소 조작과 점포의 조회/주문 흐름을 명확히 분리하고, 서버가 모든 수량/권한/가격을 재검증하도록 한다(클라이언트 표시값을 신뢰하지 않음).
- 재고 소진에 따른 목록 자동 제외를 배치 작업 없이 쿼리 조건만으로 구현한다.

### 1.2 Design Principles

- **단일 진실 공급원**: 할인가는 `lc_lot_promotions.discounted_price`에 등록 시점 스냅샷으로 고정하고, 이후 원가/판매가 변동과 무관하게 유지한다.
- **기존 동시성 모델 재사용**: 새로운 "생성 시점 예약" 개념을 도입하지 않고, 기존과 동일하게 "승인 시점 `SELECT ... FOR UPDATE`"로 경쟁 상태를 방지한다.
- **이력 보존, 물리 삭제 금지**: 취소/소진된 프로모션도 행은 남기고 조회 조건으로만 제외한다.

---

## 2. Architecture Options

### 2.0 Architecture Comparison (Checkpoint 3 완료)

| Criteria | Option A: 최소 변경 | Option B: Clean(완전 자동화) | Option C: 실용적 균형 |
|----------|:-:|:-:|:-:|
| **Approach** | `lc_inventory`에 할인 컬럼만 추가, 배지 표시만, 출고 후 수동 보정 | 별도 테이블+분리 주문행+승인시 자동 차감/분할, 전 엣지케이스 1차 포함 | Option B와 동일 구조이나 초과주문 분할·부분출고는 후속 이관 |
| **New Files** | 1 (AJAX) | 4 (신규 페이지 1, AJAX 2, 마이그레이션 1) | 4 |
| **Modified Files** | 3 | 8 | 8 |
| **Complexity** | Low | High | Medium |
| **Risk** | High (FR-06 요구사항 미충족) | Medium (승인 로직 회귀 위험, 테스트로 완화) | Low |
| **Recommendation** | 비권장 (요구사항 미달) | — | 기본 추천 |

**Selected**: **Option B** — **Rationale**: 사용자가 Checkpoint 3에서 "처음부터 전부 자동화"를 명시적으로 선택. 초과 주문 자동 분할(FR-12)과 승인 시점 자동 할인가 반영(FR-06)을 1차 범위에 포함. 단, 설계 검증 과정에서 "단위 환산가" 이슈는 애초에 발생하지 않음이 확인되어 원래 Option B에 포함하려던 항목 중 하나는 자연히 해소됨(§Plan FR-13 참고). "주문 생성 시점 재고 예약/홀드"는 기존 전체 주문 시스템의 동시성 모델을 건드리는 별개 과제라 Option B 범위에서도 제외하고 후속으로 이관(Plan §2.2 명시).

### 2.1 Component Diagram

```
[물류직원]                                   [점포 사용자]
    │                                             │
    ▼                                             ▼
inventory.php?filter=expiring              order_new.php
  (LOT 단위 행 + 할인등록/취소 UI)              (일반 재고 + 프로모션 분리 섹션)
    │                                             │
    ▼                                             ▼
ajax/promo_register.php   ajax/promo_cancel.php   주문 폼 제출 (promotion_id 포함)
    │                          │                        │
    ▼                          ▼                        ▼
        lc_lot_promotions (신규 테이블)          lc_order_items.promotion_id (신규 컬럼)
                    │                                    │
                    │                                    ▼
                    │                        [주문 승인 액션] → lc_allocate_order_stock()
                    │                                    │
                    │                     ┌──────────────┴──────────────┐
                    │                     ▼                             ▼
                    │        promotion_id NOT NULL              promotion_id IS NULL
                    │                     │                             │
                    │                     ▼                             ▼
                    └──────────▶ lc_ship_promo_lot() (신규)   lc_fifo_ship_allow_negative() (기존)
                                          │                             │
                                          └──────────────┬──────────────┘
                                                          ▼
                                            lc_order_item_lots (기존 테이블, cost_price에
                                            할인가/원가 각각 기록) + lc_order_items.unit_price
                                            (가중평균, 기존 로직과 동일 패턴)

promo_products.php (신규, staff 전용)
  = lc_lot_promotions ⋈ lc_inventory ⋈ lc_products
    WHERE status='active' AND quantity_remain > 0
```

### 2.2 Data Flow — 할인 등록부터 출고까지

```
1. 물류직원이 inventory.php?filter=expiring에서 LOT 할인율 입력 → 할인등록
2. ajax/promo_register.php: lc_inbound.cost_price 조회 → base_price/discounted_price 계산 →
   lc_lot_promotions INSERT(status=active)
3. 프로모 상품 메뉴(promo_products.php)에 즉시 노출
4. 점포가 order_new.php에서 프로모션 섹션의 항목을 주문 수량 입력 후 제출
   → lc_order_items INSERT(promotion_id = lc_lot_promotions.id, unit_price=0 임시)
5. 물류직원이 주문 승인 → lc_allocate_order_stock() → promotion_id 있는 항목은
   lc_ship_promo_lot()으로 분기 → 잔량 내에서 할인가로 차감, 초과분은 기존 FEFO로 정상가 차감
   → unit_price를 가중평균으로 갱신, lc_order_item_lots에 LOT별 내역 기록
6. 프로모션 LOT의 quantity_remain이 0이 되면 promo_products.php / inventory.php 양쪽에서
   조회 조건(quantity_remain > 0)에 의해 자동으로 사라짐(행 자체는 status=active로 유지, 이력 보존)
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `lc_ship_promo_lot()` (신규) | `lc_lot_promotions`, `lc_inventory`, `lc_order_item_lots` | 프로모션 LOT 잠금·차감·초과분 위임 |
| `lc_allocate_order_stock()` (수정) | `lc_order_items.promotion_id`, `lc_ship_promo_lot()`, 기존 `lc_fifo_ship_allow_negative()` | 항목별 분기 |
| `promo_products.php` (신규) | `lc_lot_promotions`, `lc_inventory`, `lc_products`, `lc_require_staff()` | 목록 조회 |
| `ajax/promo_register.php`, `ajax/promo_cancel.php` (신규) | `lc_lot_promotions`, CSRF, `lc_require_staff()` | 등록/취소 |

---

## 3. Data Model

### 3.1 `lc_lot_promotions` (신규 테이블)

```sql
CREATE TABLE lc_lot_promotions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id    INT NOT NULL COMMENT 'lc_inventory.id FK — 할인 대상 LOT',
    product_id      INT NOT NULL COMMENT '조회 편의용 비정규화 — lc_products.id',
    lot_number      VARCHAR(100) NULL COMMENT '조회 편의용 비정규화 — 등록 시점 스냅샷',
    expiry_date     DATE NULL COMMENT '조회 편의용 비정규화 — 등록 시점 스냅샷',
    unit            ENUM('BOX','PACK','PCS') NOT NULL COMMENT '등록 시점 lc_inventory.unit 스냅샷(참고용 표시)',
    discount_rate   DECIMAL(5,2) NOT NULL COMMENT '할인율(%), 0 초과 100 미만',
    base_price      DECIMAL(12,2) NOT NULL COMMENT '등록 시점 lc_inbound.cost_price 스냅샷',
    discounted_price DECIMAL(12,2) NOT NULL COMMENT 'ROUND(base_price * (1 - discount_rate/100), 2)',
    status          ENUM('active','cancelled','expired') NOT NULL DEFAULT 'active',
    registered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    registered_by   INT NULL COMMENT 'users.id',
    cancelled_at    DATETIME NULL,
    cancelled_by    INT NULL COMMENT 'users.id',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES lc_inventory(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id)   REFERENCES lc_products(id)  ON DELETE RESTRICT,
    KEY idx_inventory_id (inventory_id),
    KEY idx_product_status (product_id, status),
    KEY idx_status_expiry (status, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='LOT 단위 할인(프로모션) 등록/이력';
```

> 이 프로젝트 기존 테이블(`lc_inventory`, `lc_orders` 등)이 전부 `INT`(UNSIGNED 아님) PK/FK를 쓰므로 동일하게 맞춤 — `INT UNSIGNED`로 만들면 `lc_inventory.id`와 타입이 달라 FK 생성이 실패한다(Plan 문서 작성 중 실제로 발견해 수정한 이슈).

### 3.2 `lc_order_items` 컬럼 추가

```sql
ALTER TABLE lc_order_items
    ADD COLUMN promotion_id INT NULL COMMENT 'lc_lot_promotions.id — 프로모션 지정 주문 항목만 설정, 일반 항목은 NULL' AFTER product_id,
    ADD KEY idx_promotion_id (promotion_id);
```

- FK 제약은 의도적으로 걸지 않는다(프로모션이 취소돼도 과거 주문 이력의 `promotion_id`는 참조 무결성 없이 그대로 남아야 감사 추적이 유지되며, `lc_lot_promotions` 행 자체도 물리 삭제하지 않으므로 실질적 무결성 위반은 발생하지 않는다).

### 3.3 Entity Relationships

```
lc_products 1 ──── N lc_inventory (LOT)
                        │ 1
                        │
                        ▼ 0..1 (활성 프로모션은 LOT당 최대 1개, §6.1 검증)
                    lc_lot_promotions
                        ▲ 0..N
                        │
lc_orders 1 ──── N lc_order_items ──(promotion_id)
                        │ 1
                        ▼ N
                lc_order_item_lots ──(inventory_id)──▶ lc_inventory
```

### 3.4 마이그레이션 스크립트

- 경로: `logistics/sql/run_migration_v23.php`
- `run_migration_v22.php`와 동일 패턴: `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, 재실행 가드(`SHOW TABLES LIKE`/`SHOW COLUMNS`로 존재 확인 후 skip), 단계별 결과를 `<pre>`로 출력.
- 한 파일에 (1) `lc_lot_promotions` CREATE TABLE, (2) `lc_order_items.promotion_id` ALTER TABLE을 모두 포함(§Plan §7.2).
- raw `.sql` 단독 배치 금지 — 실행 가능한 PHP 스크립트로만 작성.

---

## 4. API Specification (AJAX)

### 4.1 Endpoint List

| Method | Path | Description | Auth |
|--------|------|--------------|------|
| POST | `/logistics/ajax/promo_register.php` | LOT에 할인 등록 | staff |
| POST | `/logistics/ajax/promo_cancel.php` | 등록된 할인 취소 | staff |

### 4.2 `POST /logistics/ajax/promo_register.php`

**Request**
```
csrf_token=...&inventory_id=1234&discount_rate=20.00
```

**Response (성공, 200)**
```json
{
  "success": true,
  "message": "할인이 등록되었습니다.",
  "data": {
    "promotion_id": 55,
    "base_price": 10000.00,
    "discounted_price": 8000.00,
    "discount_rate": 20.00,
    "unit": "BOX"
  }
}
```

**Response (실패)**
```json
{ "success": false, "message": "이미 할인이 등록된 LOT입니다." }
```

**서버 검증 순서**:
1. `lc_require_staff()`, CSRF(`hash_equals`)
2. `discount_rate`가 숫자이며 `0 < discount_rate < 100` (0%/100% 경계값 거부 — 0%는 등록 의미 없음, 100%는 무상 제공이라 별도 정책 필요하므로 1차 범위에서 제외)
3. 대상 `lc_inventory` 행 존재 + `quantity_remain > 0`
4. 해당 `inventory_id`에 `status='active'`인 `lc_lot_promotions`가 이미 없는지 확인(중복 등록 방지 — LOT당 활성 프로모션 최대 1개)
5. `lc_inbound.cost_price`를 `lc_inventory.inbound_id`로 조회해 `base_price`로 스냅샷
6. `discounted_price = ROUND(base_price * (1 - discount_rate/100), 2)` (CLAUDE.md 소숫점 둘째자리 규칙)
7. INSERT, `registered_by = $_SESSION['user_id']`

### 4.3 `POST /logistics/ajax/promo_cancel.php`

**Request**
```
csrf_token=...&promotion_id=55
```

**Response (성공)**
```json
{ "success": true, "message": "할인이 취소되었습니다." }
```

**서버 검증**: staff 권한, CSRF, 대상이 `status='active'`인지(이미 취소/소진 건은 거부) → `status='cancelled', cancelled_at=NOW(), cancelled_by=$_SESSION['user_id']`로 UPDATE. 물리 삭제 금지. 이미 이 프로모션을 참조하는 `lc_order_items.promotion_id`가 있어도(과거 주문) 그대로 둔다 — 과거 출고 기록은 불변.

---

## 5. UI/UX Design

### 5.1 사이드바 메뉴

`logistics/partials/header.php`의 "Inventory Status"(`inventory.php`) 항목 바로 아래, `$_lc_is_staff` 분기 안에 추가:

```html
<a href="<?php echo LC_BASE; ?>/promo_products.php" ...>
    <i class="fas fa-tags mr-2 w-4 text-center"></i>
    <?php echo t('logistics.nav.promo_products'); ?>
</a>
```

### 5.2 User Flow

```
[물류직원] 대시보드 유통기한 임박 카드 → 전체보기
  → inventory.php?filter=expiring (LOT 단위 행)
  → 할인율 입력 → 할인등록 클릭 → ajax/promo_register.php
  → 성공 시 해당 행에 "할인중" 배지 표시 + 취소 버튼으로 전환
  → 사이드바 "프로모 상품" 메뉴 → promo_products.php에서 전체 목록/취소 확인

[점포 사용자] order_new.php 접속
  → "프로모션 상품" 섹션(활성 프로모션 있는 상품만) + 기존 "전체 상품" 섹션
  → 프로모션 항목에 수량 입력 → 주문 제출(promotion_id 포함)
  → 승인 후 orders.php에서 할인 반영된 단가 확인
```

### 5.3 Page UI Checklist

#### `inventory.php?filter=expiring` (LOT 단위 전환)

- [ ] 테이블 행 단위: 상품명, LOT번호, 유통기한, D-day 배지, 단위(BOX/PACK/PCS), 잔량
- [ ] 프로모션 미등록 행: 할인율 입력(number, 0.01~99.99, step 0.01) + "할인등록" 버튼
- [ ] 프로모션 등록된 행: "할인중 {rate}%" 배지 + 할인가 표시 + "할인취소" 버튼
- [ ] 다른 필터(`all`/`low`/`negative`/`out`)는 기존 상품 단위 표시 그대로 유지(회귀 없음 확인 대상)

#### `promo_products.php` (신규)

- [ ] 목록 테이블: 상품명, 카테고리/바코드, LOT번호, 유통기한, D-day, 남은수량, 정상가(`base_price`, 소숫점 둘째자리), 할인율, 할인가(`discounted_price`, 소숫점 둘째자리), 등록일(`registered_at`), 등록자, 취소 버튼
- [ ] 빈 상태: "등록된 할인 상품이 없습니다." 안내
- [ ] `WHERE status='active' AND quantity_remain > 0` 조건 적용(자동 제외 확인 대상)

#### `order_new.php` (프로모션 섹션 추가)

- [ ] "프로모션 상품" 섹션: 활성 프로모션 있는 상품만, 상품명 + "LOT {lot_number} / {expiry_date}까지 / {quantity_remain}{unit} / {discount_rate}% 할인" 서브텍스트 + 수량 입력
- [ ] 주문 폼 hidden input: 프로모션 항목마다 `promotion_id` 명시
- [ ] 기존 "전체 상품"(일반 재고) 섹션은 변경 없이 유지
- [ ] 프로모션 잔량 초과 입력 시 클라이언트 경고(서버 재검증은 필수, 클라이언트는 UX 보조용)

### 5.4 표시 규칙

CLAUDE.md 규칙에 따라 `base_price`/`discounted_price`/할인 관련 모든 금액은 소숫점 둘째자리까지 표시.

---

## 6. Error Handling

| Code (message key) | 상황 | 처리 |
|------|------|------|
| `logistics.ajax_promo_register.already_active` | 이미 활성 프로모션 있는 LOT에 재등록 시도 | 400, 등록 거부 |
| `logistics.ajax_promo_register.invalid_rate` | `discount_rate`가 0~100 범위 밖 | 400 |
| `logistics.ajax_promo_register.no_stock` | 대상 LOT `quantity_remain <= 0` | 400 |
| `logistics.ajax_promo_cancel.not_active` | 이미 취소/소진된 프로모션 취소 시도 | 400 |
| `logistics.ajax_promo_*.forbidden` | staff 권한 없음 | 403 |
| (승인 시) `lc_ship_promo_lot()` 예외 | 프로모션이 승인 시점엔 `cancelled`로 바뀐 경우 | 트랜잭션 롤백, 승인 액션에 에러 메시지로 전파(§7.3) |

---

## 7. 승인/출고 시점 처리 로직 (핵심 — Option B 자동 분할 포함)

### 7.1 수정 대상 함수 (`logistics/lib/inventory_helper.php`)

| 함수 | 변경 |
|------|------|
| `lc_allocate_order_stock()` | 주문 항목 루프에서 `promotion_id IS NOT NULL`인 항목은 `lc_ship_promo_lot()`로 위임, 나머지는 기존 `lc_fifo_ship_allow_negative()` 경로 그대로 |
| `lc_ship_promo_lot()` (신규) | 아래 §7.2 알고리즘 |
| `lc_restore_order_stock()` | 프로모션 항목도 `lc_order_item_lots` 기반으로 동일하게 복원 가능(LOT 단위 복원 로직은 프로모션 여부와 무관하게 재사용 — 신규 컬럼만 확인, 로직 변경 불필요로 판단되나 구현 단계에서 프로모션 항목 취소 후 재주문 시나리오로 검증 필요) |
| `lc_order_stock_allocated()` | 변경 불필요 |

### 7.2 `lc_ship_promo_lot()` 알고리즘 (FR-12 자동 분할 포함)

```
function lc_ship_promo_lot(conn, order_item_id, product_id, promotion_id, requested_qty):
    promo = SELECT * FROM lc_lot_promotions WHERE id = promotion_id FOR UPDATE
    if promo.status != 'active':
        throw Exception("프로모션이 더 이상 유효하지 않습니다")  // 승인 시점 재검증

    lot = SELECT * FROM lc_inventory WHERE id = promo.inventory_id FOR UPDATE
    promo_available = lot.quantity_remain
    promo_ship_qty  = MIN(promo_available, requested_qty)

    lots_used = []
    if promo_ship_qty > 0:
        UPDATE lc_inventory SET quantity_out = quantity_out + promo_ship_qty WHERE id = lot.id
        INSERT INTO lc_order_item_lots (order_item_id, inventory_id, inbound_id, quantity, cost_price)
            VALUES (order_item_id, lot.id, lot.inbound_id, promo_ship_qty, promo.discounted_price)
        lots_used.append({qty: promo_ship_qty, price: promo.discounted_price})

    remaining_qty = requested_qty - promo_ship_qty
    if remaining_qty > 0:
        // FR-12: 초과분은 같은 상품의 일반 FEFO 재고에서 정상 차감 (프로모션 LOT 자체는 이미 소진되어 자동 제외됨)
        fefo_lots = lc_fifo_ship_allow_negative(conn, product_id, remaining_qty)
        for fl in fefo_lots:
            INSERT INTO lc_order_item_lots (order_item_id, inventory_id, inbound_id, quantity, cost_price)
                VALUES (order_item_id, fl.inventory_id, fl.inbound_id, fl.quantity, fl.cost_price)
            lots_used.append({qty: fl.quantity, price: fl.cost_price})

    total_qty  = SUM(lots_used.qty)
    total_cost = SUM(lots_used.qty * lots_used.price)
    weighted_unit_price = ROUND(total_cost / total_qty, 2)  // 기존 lc_allocate_order_stock()과 동일한 가중평균 패턴
    UPDATE lc_order_items SET unit_price = weighted_unit_price WHERE id = order_item_id
```

- **음수 재고 비허용**: 프로모션 LOT 자체는 `quantity_remain`을 초과해서 차감하지 않는다(`MIN(promo_available, requested_qty)`로 상한 고정) — 기존 `lc_fifo_ship_allow_negative()`의 "부족해도 음수 허용" 정책과 다른 정책을 명시적으로 적용.
- **가중평균 단가**: 프로모션분+일반분이 섞여도 기존 시스템이 이미 사용하는 "차감된 LOT들의 가중평균을 `unit_price`에 저장" 패턴을 그대로 재사용해 일관성을 유지한다.
- **표시**: `orders.php`/`order_detail.php`에서 이 주문 항목이 프로모션을 일부 포함했는지 표시하려면 `lc_order_item_lots`를 조회해 `cost_price`가 프로모션 `discounted_price`와 일치하는 행이 있는지로 판별 가능(신규 컬럼 추가 없이 기존 데이터로 유추) — 1차 범위에서는 필수 UI는 아니나 §11.2 구현 순서에 후속 확인 항목으로 포함.

### 7.3 처리 순서 (트랜잭션)

```
1. 주문 승인 트랜잭션 시작 (기존 승인 액션 트랜잭션 그대로 재사용)
2. lc_order_items WHERE order_id = ? 전체 조회
3. FOR EACH item:
     IF item.promotion_id IS NOT NULL:
         lc_ship_promo_lot(item.id, item.product_id, item.promotion_id, item.quantity)
     ELSE:
         (기존 로직 그대로) lc_fifo_ship_allow_negative + 가중평균 unit_price 반영
4. 트랜잭션 커밋 (예외 발생 시 전체 롤백 — 부분 승인 없음, 기존 승인 액션의 원자성과 동일 기준)
```

---

## 8. Test Plan

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: 스크립트 검증 | `php -l` 전 대상 파일, `lang/*.json` 유효성 | PHP CLI | Do (배치별) |
| L2: 기능 시나리오 | 등록→목록→주문→승인→차감 전체 흐름 | 수동(Laragon 로컬) + Claude 코드 리뷰 | Check |
| L3: 회귀 확인 | 기존 일반 주문 승인 흐름 | 수동 | Check |

### 8.2 핵심 시나리오

| # | 시나리오 | 절차 | 기대 결과 |
|---|----------|------|-----------|
| 1 | 정상 등록 | 임박 LOT에 20% 할인 등록 | `lc_lot_promotions` 행 생성, `discounted_price` = `base_price*0.8` 반올림 둘째자리 |
| 2 | 중복 등록 방지 | 이미 활성 프로모션 있는 LOT에 재등록 시도 | 400 에러, 신규 행 생성 안 됨 |
| 3 | 자동 노출/제외 | 등록 직후 `promo_products.php` 조회 → LOT 재고 전량 출고 후 재조회 | 등록 직후 노출, 소진 후 목록에서 사라짐(행은 DB에 유지) |
| 4 | 정상 범위 내 주문 | 프로모션 LOT 잔량(예 10) 이내로 주문(8) 후 승인 | `lc_order_item_lots`에 8개 전부 `discounted_price`로 기록, `unit_price` = `discounted_price` |
| 5 | 초과 주문 자동 분할(FR-12) | 프로모션 LOT 잔량(예 3) 초과로 주문(8) 후 승인 | 3개는 할인가, 5개는 일반 FEFO 정상가로 분할 차감, `unit_price`는 가중평균 |
| 6 | 취소 후 승인 시도 | 등록 → 주문 생성(pending) → 취소 → 승인 시도 | 승인 시 예외 발생, 트랜잭션 롤백, 에러 메시지 노출 |
| 7 | 권한 | 비staff 세션으로 등록/취소/프로모 메뉴 접근 | 403 또는 리다이렉트 |
| 8 | 회귀 — 일반 주문 | 프로모션 없는 일반 주문 승인 | 기존과 동일하게 FEFO+가중평균 원가로 처리 (변경 없음 확인) |
| 9 | i18n | 한글/영문 전환 후 신규 화면 전부 확인 | 하드코딩 텍스트 없음 |

### 8.3 Seed Data Requirements

| Entity | 최소 개수 | 필수 필드 |
|--------|:---:|-----------|
| `lc_inventory` (유통기한 임박) | 2 (하나는 소량, 하나는 대량 — 시나리오 4/5용) | `expiry_date`(CURDATE()+7일 이내), `quantity_remain` > 0, `unit` |
| `lc_products` | 1 (프로모션 LOT과 연결된 상품) | `pieces_per_box` |
| `stores` | 1 | 주문 테스트용 |

---

## 9. Security Considerations

- [ ] `promo_register.php`/`promo_cancel.php` CSRF 토큰 검증(기존 logistics AJAX 컨벤션 — `hash_equals($_SESSION['lc_csrf'], $token)`)
- [ ] 두 엔드포인트 모두 `lc_require_staff()` 선행 호출
- [ ] `discount_rate` 서버 측 범위 검증(클라이언트 검증만으로 신뢰하지 않음)
- [ ] `order_new.php` 제출 시 `promotion_id`가 실제 활성 프로모션인지 승인 시점에 재검증(클라이언트가 조작된 `promotion_id`를 보내도 서버가 다시 확인)
- [ ] SQL 전부 prepared statement 사용(이 프로젝트 기존 컨벤션)

---

## 10. Coding Convention Reference

| Item | 적용 컨벤션 |
|------|-------------|
| 파일 조직 | 기존 `logistics/` 구조 그대로(`페이지 루트`, `lib/`, `ajax/`, `sql/`, `partials/`) |
| 네이밍 | 기존 `lc_*` 접두사 함수명 컨벤션 유지(`lc_ship_promo_lot` 등) |
| i18n | `t('logistics.promo.*')`, `t('logistics.ajax_promo_register.*')`, `t('logistics.ajax_promo_cancel.*')` — `lang/ko.json`/`lang/en.json` 동시 추가, 키 집합 diff로 검증(logistics-i18n.plan.md와 동일 절차) |
| 금액 표시 | 소숫점 둘째자리(CLAUDE.md) |
| DB 접근 | prepared statement, `mysqli`(기존 logistics 모듈 패턴) |

---

## 11. Implementation Guide

### 11.1 File Structure (변경/신규 파일 전체 목록)

```
logistics/
├── sql/run_migration_v23.php          (신규 — 스키마)
├── lib/inventory_helper.php            (수정 — lc_allocate_order_stock 분기 + lc_ship_promo_lot 신규)
├── partials/header.php                 (수정 — 사이드바 메뉴)
├── inventory.php                       (수정 — filter=expiring LOT 단위 전환 + 할인 UI)
├── promo_products.php                  (신규 — 목록 페이지)
├── order_new.php                       (수정 — 프로모션 섹션 + promotion_id 제출)
├── ajax/promo_register.php             (신규)
└── ajax/promo_cancel.php               (신규)

lang/ko.json, lang/en.json              (수정 — logistics.promo.*, logistics.nav.promo_products,
                                          logistics.ajax_promo_register.*, logistics.ajax_promo_cancel.* 등)
```

### 11.2 Implementation Order

1. [ ] `lc_lot_promotions` 테이블 + `lc_order_items.promotion_id` 컬럼(마이그레이션)
2. [ ] `ajax/promo_register.php`, `ajax/promo_cancel.php` (스키마만 있으면 독립 구현/테스트 가능)
3. [ ] `inventory.php` LOT 단위 전환 + 할인 등록/취소 UI (2번의 엔드포인트 사용)
4. [ ] `promo_products.php` 신규 페이지 (1번의 테이블만 있으면 됨, 2/3과 병렬 가능)
5. [ ] `logistics/partials/header.php` 메뉴 추가 (아무 때나 가능, 4번과 함께 진행 권장)
6. [ ] `lc_allocate_order_stock()` 수정 + `lc_ship_promo_lot()` 신규 (가장 리스크 높음 — 1~5 완료 후 단독 진행)
7. [ ] `order_new.php` 프로모션 섹션 + 폼 제출 (6번과 인터페이스 일치 필요, 6번과 묶어서 진행)
8. [ ] `lang/ko.json`/`lang/en.json` 전체 키 정리 및 diff 검증 (각 단계마다 점진 추가, 마지막에 전체 재검증)

### 11.3 Session Guide / Batch 전략 (Codex/Claude 오케스트레이션)

Plan 문서 §14와 동일하되, 승인 로직(6/7번)이 가장 리스크가 높으므로 반드시 마지막 단독 배치로 진행:

| 배치 | 대상 (위 구현순서 번호) | 비고 |
|------|------|------|
| B0 | 1 | 스키마+마이그레이션, 최우선 단독 |
| B1 | 3, 5 (inventory.php + header.php) | B0 이후 |
| B2 | 4 (promo_products.php) | B0 이후, B1과 병렬 가능 |
| B3 | 2 (신규 AJAX 2개) | B0 이후, B1/B2와 병렬 가능 — B1의 버튼이 이 엔드포인트를 호출하므로 §4 인터페이스 사전 고정 |
| B4 | 6, 7 (order_new.php + 승인 로직) | B0~B3 완료 후 단독. 기존 주문 승인 회귀 테스트(§8.2 시나리오 8) 필수 |

병합 게이트는 항상 Claude Code — 각 배치 `php -l` + json 검증 + (B4는 특히) 회귀 시나리오 확인 후 `/code-review`로 병합 전 최종 검토(CLAUDE.md, agent-orchestration.md 컨벤션).

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-18 | 최초 작성 — Option B(완전 자동화) 선택 반영, FR-13 단위환산 이슈 설계 검증 중 정정 | Claude Code |
