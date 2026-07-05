# box-pcs-unit Design Document

> **Summary**: BOX·PCS 단위 분리 재고 관리 — 입고/지점오더/지점출고 단위 선택 + 박스 개봉(파손 등록) 기능 상세 설계
>
> **Project**: sunset (Logistics Center)
> **Version**: -
> **Author**: whdans007 + Claude
> **Date**: 2026-06-11
> **Status**: Draft
> **Planning Doc**: [box-pcs-unit.plan.md](../../01-plan/features/box-pcs-unit.plan.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | BOX/PCS 혼합 입출고 시 단위 구분이 없어 재고·원가 왜곡 + 박스 일부 파손 시 낱개 전환 수단 부재 |
| **WHO** | 물류센터 직원(입고/지점출고/박스 개봉), 지점 담당자(지점오더) |
| **RISK** | BOX lot과 PCS lot 분리로 출고·FEFO·재고집계 로직 전반이 단위별 분기 — 차감 단위 불일치 버그가 최대 리스크 |
| **SUCCESS** | BOX 5개 입고 → BOX 재고 5 / 1박스 개봉(1개 파손) → BOX 4 + PCS 19, 파손 이력 1건 / PCS 10개 출고 → PCS 9 |
| **SCOPE** | ① DB 마이그레이션(v18) ② 입고 화면/저장 ③ 입고 리스트 3종 ④ 지점출고 ⑤ 지점오더 ⑥ 박스 개봉 페이지(신규) ⑦ 재고 화면 분리 표시 |

---

## 1. Overview

### 1.1 Design Goals

1. **단위 분기 일원화**: 단위 정규화·환산·표기·FEFO 단위 필터를 `lib/unit_helper.php` 한 곳에 집중해 "차감 단위 불일치" 버그를 구조적으로 차단
2. **하위 호환**: 기존 FEFO 함수는 `$unit` 파라미터를 옵션(기본 null=전체)으로 추가 — 기존 호출부는 무수정 동작
3. **수량 무변경 마이그레이션**: lot 수량을 건드리지 않고 단위 라벨만 부여 (가장 안전)
4. **원가 정확성**: BOX 입고 lot에서 파생된 PCS lot의 단가는 `cost_price_pcs`(BOX원가÷ppb) 사용

### 1.2 Design Principles

- 단위(BOX/PCS)는 항상 **lot 속성** — 상품 속성(unit)은 기본값 제안에만 사용
- 모든 차감/집계 쿼리는 단위 필터 필수 (`unit = ?`) — 전체 합산은 표시용으로만 제한적 허용
- 클라이언트가 보낸 단위·환산값은 서버에서 재검증
- 개봉 트랜잭션은 원자적: BOX 차감 + PCS lot 생성 + 이력 기록이 모두 성공하거나 모두 롤백

---

## 2. Architecture

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 기존 함수에 unit 파라미터 직접 추가, 페이지별 인라인 분기 | 신규 함수군 + 전 페이지 재고 쿼리 리팩토링 | unit_helper.php 신설 + 기존 함수 하위호환 확장 |
| **New Files** | 3 | 7+ | 5 |
| **Modified Files** | ~13 | ~16 | ~13 |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Low | High | High |
| **Effort** | Low | High | Medium |
| **Risk** | High (분기 누락) | Low (회귀 범위 큼) | Low |

**Selected**: **Option C** — **Rationale**: 단위 로직 집중으로 분기 누락 차단 + 기본값 파라미터로 기존 호출부 무수정 (Checkpoint 3 사용자 확정)

### 2.1 Component Diagram

```
┌─────────────────────┐      ┌──────────────────────────┐      ┌─────────────┐
│ Pages (Presentation)│      │ AJAX APIs + lib (Logic)  │      │ MariaDB     │
│ inbound_add.php     │─────▶│ lib/unit_helper.php(신규)│─────▶│ lc_inbound  │
│ box_break.php(신규) │      │ lib/inventory_helper.php │      │ lc_inventory│
│ branch_outbound.php │      │ ajax/branch_outbound.php │      │ lc_order_*  │
│ order_new.php       │      │ ajax/box_break.php(신규) │      │ lc_box_breaks(신규)│
│ inventory.php 외    │      │ ajax/search_product_*.php│      │ lc_products │
└─────────────────────┘      └──────────────────────────┘      └─────────────┘
```

### 2.2 Data Flow

```
[입고]  토글(BOX/PCS) → 스캔 → 행 추가(단위 포함) → POST
        → lc_inbound(unit, ppb, cost_price_pcs) + lc_inventory(unit) lot 생성

[개봉]  상품 스캔 → BOX lot 선택 → 박스 수·파손 수 입력 → POST
        → BOX lot quantity_out += 박스수
        → PCS lot 신규 생성 (quantity_in = 박스수×ppb − 파손, 단가 = cost_price_pcs)
        → lc_box_breaks 이력 기록

[출고]  토글(BOX/PCS) → 장바구니(행별 단위) → ship
        → 단위별 FEFO: WHERE unit = ? 인 lot에서만 차감
        → lc_order_items.order_unit 기록, lot 단가는 단위별 유효단가
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| 모든 페이지/API | `lib/unit_helper.php` | 단위 정규화·환산·표기·단위별 재고 조회 |
| `ajax/box_break.php` | `lib/unit_helper.php`, lc_inbound 스냅샷 | 개봉 트랜잭션 |
| FEFO 함수군 | `lc_inventory.unit`, 유효단가 CASE식 | 단위별 차감·원가 |

---

## 3. Data Model

### 3.1 Migration v18 (`lc_migration_v18.sql` + `run_migration_v18.php`)

```sql
-- 1) lc_inbound: 입고 단위 + ppb 스냅샷 + PCS 환산 원가
ALTER TABLE lc_inbound
    ADD COLUMN inbound_unit   ENUM('BOX','PCS') NOT NULL DEFAULT 'PCS' COMMENT '입고 단위' AFTER quantity,
    ADD COLUMN pieces_per_box INT NOT NULL DEFAULT 1                  COMMENT '입고 시점 박스당 낱개 수(스냅샷)' AFTER inbound_unit,
    ADD COLUMN cost_price_pcs DECIMAL(15,4) NOT NULL DEFAULT 0        COMMENT 'PCS 환산 원가 (BOX: cost_price/ppb, PCS: cost_price)' AFTER discount_rate;

-- 2) lc_inventory: lot 단위
ALTER TABLE lc_inventory
    ADD COLUMN unit ENUM('BOX','PCS') NOT NULL DEFAULT 'PCS' COMMENT 'lot 단위' AFTER product_id,
    ADD INDEX idx_product_unit (product_id, unit);

-- 3) lc_order_items: 주문 단위 + ppb 스냅샷
ALTER TABLE lc_order_items
    ADD COLUMN order_unit     ENUM('BOX','PCS') NOT NULL DEFAULT 'PCS' COMMENT '주문/출고 단위' AFTER quantity,
    ADD COLUMN pieces_per_box INT NOT NULL DEFAULT 1                   COMMENT '주문 시점 ppb 스냅샷' AFTER order_unit;

-- 4) 박스 개봉/파손 이력 (신규)
CREATE TABLE IF NOT EXISTS lc_box_breaks (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    product_id          INT NOT NULL              COMMENT 'lc_products.id',
    source_inventory_id INT NOT NULL              COMMENT '개봉한 BOX lot (lc_inventory.id)',
    new_inventory_id    INT NULL                  COMMENT '생성된 PCS lot (전량 파손 시 NULL)',
    boxes_opened        INT NOT NULL              COMMENT '개봉 박스 수',
    pieces_per_box      INT NOT NULL              COMMENT '개봉 시 적용 ppb',
    pcs_created         INT NOT NULL              COMMENT '생성 PCS 수 (= boxes×ppb − damaged)',
    damaged_qty         INT NOT NULL DEFAULT 0    COMMENT '파손 수량(PCS)',
    damage_cost         DECIMAL(15,4) NOT NULL DEFAULT 0 COMMENT '파손 손실 (= damaged × PCS단가)',
    notes               VARCHAR(255)              COMMENT '비고',
    created_by          INT                       COMMENT 'users.id',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id)          REFERENCES lc_products(id)  ON DELETE RESTRICT,
    FOREIGN KEY (source_inventory_id) REFERENCES lc_inventory(id) ON DELETE RESTRICT,
    INDEX idx_product_time (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='박스 개봉/파손 이력';

-- 5) 기존 데이터 라벨링 (수량 무변경 — Plan FR-10)
--    BOX 판정: 상품 unit 정규화 후 'BOX' 계열이면 BOX
UPDATE lc_inventory i JOIN lc_products p ON i.product_id = p.id
SET i.unit = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'), 'BOX', 'PCS');

UPDATE lc_inbound b JOIN lc_products p ON b.product_id = p.id
SET b.inbound_unit   = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'), 'BOX', 'PCS'),
    b.pieces_per_box = GREATEST(1, IFNULL(p.pieces_per_box, 1)),
    b.cost_price_pcs = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'),
                          b.cost_price / GREATEST(1, IFNULL(p.pieces_per_box, 1)),
                          b.cost_price);

UPDATE lc_order_items oi JOIN lc_products p ON oi.product_id = p.id
SET oi.order_unit     = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'), 'BOX', 'PCS'),
    oi.pieces_per_box = GREATEST(1, IFNULL(p.pieces_per_box, 1));
```

**run_migration_v18.php 필수 동작** (기존 run_migration_vN.php 컨벤션):
1. 마이그레이션 전 lot 수량 합계 스냅샷 → 실행 → 후 합계 비교 출력 (동일해야 통과)
2. 단위 라벨링 결과 리포트: BOX lot 수 / PCS lot 수 / cost_price_pcs=0인 행 수
3. 각 ALTER는 컬럼 존재 검사 후 실행 (재실행 안전)

### 3.2 유효 단가 규칙 (핵심 설계 결정)

lot의 단가는 **lot 단위와 입고 단위의 조합**으로 결정한다:

| lot unit | inbound_unit | 유효 단가 |
|----------|--------------|----------|
| BOX | BOX | `lc_inbound.cost_price` (BOX 단가) |
| PCS | PCS | `lc_inbound.cost_price` (PCS 단가) |
| PCS | BOX | **`lc_inbound.cost_price_pcs`** (개봉으로 파생된 PCS lot) |

SQL CASE 식 (FEFO·평균원가 쿼리 공통 사용, unit_helper에 상수로 정의):

```sql
CASE WHEN i.unit = 'PCS' AND b.inbound_unit = 'BOX'
     THEN b.cost_price_pcs ELSE b.cost_price END AS effective_cost
```

### 3.3 Entity Relationships

```
[lc_products] 1 ─── N [lc_inbound(inbound_unit, ppb, cost_price_pcs)]
                          │ 1
                          └── N [lc_inventory(unit)]  ←─┐ source/new
                                     │ 1                 │
                                     └── N [lc_box_breaks] (개봉 이력)
[lc_orders] 1 ─ N [lc_order_items(order_unit, ppb)] 1 ─ N [lc_order_item_lots]
```

- 개봉 생성 PCS lot의 `inbound_id`는 **원본 BOX lot의 inbound_id를 승계** → 유통기한·lot번호·공급처 추적 유지
- `lc_box_breaks.source_inventory_id` → 개봉된 BOX lot, `new_inventory_id` → 생성된 PCS lot

---

## 4. API Specification

### 4.1 Endpoint List

| Method | Path / action | Description | 변경 유형 |
|--------|---------------|-------------|-----------|
| GET | `ajax/search_product_by_barcode.php` | 상품 검색 — 응답에 `pieces_per_box`, `unit` 추가 | 수정 (additive) |
| GET | `ajax/branch_outbound.php?action=get_product_stock` | 단위별 재고/원가 분리 응답 | 수정 |
| GET | `ajax/branch_outbound.php?action=get_fefo_preview&unit=` | 단위 필터 FEFO 미리보기 | 수정 |
| POST | `ajax/branch_outbound.php` (save/update/ship_draft) | items에 `unit` 필드 추가 | 수정 |
| GET | `ajax/box_break.php?action=get_box_lots` | 상품의 BOX lot 목록 (개봉 대상) | **신규** |
| POST | `ajax/box_break.php` (action=submit_break) | 개봉 실행 | **신규** |
| GET | `ajax/box_break.php?action=get_history` | 개봉/파손 이력 조회 | **신규** |

### 4.2 Detailed Specification

#### `GET ajax/branch_outbound.php?action=get_product_stock&product_id=N`

**Response (변경)**:
```json
{
  "success": true,
  "product": {
    "id": 1, "name": "...", "unit": "BOX",
    "pieces_per_box": 20,
    "box_stock": 5,  "box_avg_cost": 100.0,
    "pcs_stock": 12, "pcs_avg_cost": 5.0
  }
}
```
- 단위별 집계: `SUM(quantity_remain) ... GROUP BY i.unit`, 평균원가는 §3.2 유효단가 가중평균

#### `GET ajax/box_break.php?action=get_box_lots&product_id=N`

**Response**:
```json
{
  "success": true,
  "lots": [{
    "inventory_id": 10, "lot_number": "L1", "expiry_date": "2026-12-01",
    "storage_location": "A-01", "quantity_remain": 5,
    "pieces_per_box": 20, "cost_price_box": 100.0, "cost_price_pcs": 5.0
  }]
}
```
- 조건: `i.product_id = ? AND i.unit = 'BOX' AND i.quantity_remain > 0`, 유통기한 ASC
- `pieces_per_box`/`cost_price_pcs`는 lc_inbound 스냅샷에서 (ppb<1이면 1 보정)

#### `POST ajax/box_break.php` (action=submit_break)

**Request**: `csrf_token, inventory_id, boxes_opened, damaged_qty, notes`

**처리 (단일 트랜잭션, Design 원칙 4)**:
1. `SELECT ... FOR UPDATE`로 BOX lot 잠금, `unit='BOX'` 및 `quantity_remain >= boxes_opened` 검증
2. inbound 스냅샷에서 ppb·cost_price_pcs 로드 (ppb = max(1, ppb))
3. 검증: `0 <= damaged_qty <= boxes_opened × ppb`
4. BOX lot: `quantity_out += boxes_opened`
5. `pcs_created = boxes_opened × ppb − damaged_qty` > 0이면 PCS lot INSERT
   (inbound_id·lot_number·expiry_date·storage_location 승계, `unit='PCS'`, `quantity_in=pcs_created`)
6. `lc_box_breaks` INSERT (`damage_cost = damaged_qty × cost_price_pcs`)
7. COMMIT → `{"success":true, "break_id":N, "pcs_created":19}`

**Error Responses**: `400` 검증 실패(재고 부족/파손 수량 초과/BOX lot 아님), `409` 동시성 충돌(재시도 안내)

#### `POST ajax/branch_outbound.php` items 형식 (변경)

```json
[{ "product_id": 1, "quantity": 2, "unit": "BOX" }]
```
- `unit` 누락 시 'PCS' 기본 (서버 검증: ENUM 외 값 거부)
- `ship_draft`: 품목별 `lc_unit_fifo_ship_allow_negative($conn, $pid, $qty, $unit)` 호출 — **동일 단위 lot에서만** 차감
- draft 저장 시 `lc_order_items.order_unit`, `pieces_per_box`(상품 스냅샷) 기록

### 4.3 unit_helper.php 함수 명세 (신규, `logistics/lib/unit_helper.php`)

```php
// 단위 정규화: 상품 unit 값 → 'BOX' | 'PCS'
function lc_normalize_unit(?string $product_unit): string;
// 'BOX','박스','box'(공백무시·대소문자무시) → 'BOX', 그 외 → 'PCS'

// PCS 환산 원가
function lc_pcs_cost(float $box_cost, int $ppb): float;   // round(box_cost / max(1,ppb), 4)

// 단위별 재고 집계 → ['BOX'=>int, 'PCS'=>int]
function lc_get_stock_by_unit(mysqli $conn, int $product_id): array;

// 재고 표기: "5 BOX + 12 PCS" / "12 PCS" / "0"
function lc_format_stock(array $stock_by_unit): string;

// 단위 필터 FEFO 차감 (음수 허용) — lc_fifo_ship_allow_negative의 단위 버전
// WHERE i.unit = $unit 필수, 유효단가 CASE식 사용(§3.2), ADJUST lot 생성 시 unit 지정
function lc_unit_fifo_ship_allow_negative(mysqli $conn, int $product_id, int $qty, string $unit): array;

// 단위 필터 FEFO 미리보기 — shortfall>0 && 반대편 BOX 재고 존재 시 'suggest_break'=>true 포함
function lc_unit_fefo_preview_allow_negative(mysqli $conn, int $product_id, int $qty, string $unit): array;

// 유효단가 SQL 조각 (상수)
const LC_EFFECTIVE_COST_SQL = "CASE WHEN i.unit='PCS' AND b.inbound_unit='BOX' THEN b.cost_price_pcs ELSE b.cost_price END";
```

> 기존 `lib/inventory_helper.php` 함수들은 **수정하지 않음** (Option C). 단위 인지가 필요한 호출부만
> `lc_unit_*` 함수로 교체. `lc_get_stock()` 등 전체 합산 함수는 표시 외 용도로 사용 금지 처리(주석 경고).

---

## 5. UI/UX Design

### 5.1 공통 UI 패턴 — BOX/PCS 토글

```
┌──────────────────────────────────────────────────┐
│ 입고 단위:  [ ● BOX ] [ ○ PCS ]   ← 전역 토글     │
│ ┌──────────────────────────────────────────────┐ │
│ │ 바코드 스캔 또는 상품명 입력...        [검색]  │ │
│ └──────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────┘
```
- 토글은 검색창 위에 배치, 선택 상태가 시각적으로 명확 (teal 강조)
- 스캔으로 추가되는 행은 토글의 현재 단위 적용, 행 안에서 개별 변경 가능 (select)
- 행 단위가 BOX이고 ppb≤1이면 행에 ⚠ 경고 배지 (FR-11)

### 5.2 User Flow

```
[입고]   토글 선택 → 스캔 → 행 확인(단위/수량/원가/PCS원가 자동표시) → Register
[개봉]   box_break.php → 상품 스캔 → BOX lot 선택 → 박스 수/파손 수 입력 → 확인 → 완료(재고 반영)
[지점출고] 토글 선택 → 스캔 → 장바구니(단위별 재고 표시) → 저장/최종출고
          └ PCS 부족+BOX 보유 시: "PCS 재고 부족 — 박스 개봉 필요 [개봉 페이지 →]" 안내
[지점오더] 상품 행에서 단위 select + 수량 입력 → Place Order
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| UnitToggle (전역) | inbound_add, branch_outbound | 스캔 적용 단위 선택 |
| 행별 unit select | 각 품목 행 | 행 단위 변경 + hidden input `unit[]` |
| PCS원가 자동표시 | inbound_add 행 | BOX 행: 최종원가÷ppb 실시간 계산 |
| BOX lot 선택 테이블 | box_break.php | FEFO 순 lot 나열, 선택 |
| 개봉 입력 폼 | box_break.php | 박스 수·파손 수·비고, 결과 미리보기("19 PCS 생성") |
| 개봉 이력 테이블 | box_break.php 하단 | 최근 개봉/파손 이력 |
| 단위별 재고 배지 | branch_outbound, order_new, inventory | "5 BOX + 12 PCS" |

### 5.4 Page UI Checklist

#### inbound_add.php (수정)

- [ ] Toggle: 입고 단위 BOX/PCS (기본 BOX, 검색창 위)
- [ ] Row: 단위 select (BOX/PCS, 토글값으로 초기화, name="unit[]")
- [ ] Row: BOX 행에서 PCS원가 자동 표시 (최종원가÷ppb, 소수 2자리, teal 텍스트)
- [ ] Row: ppb≤1 상품 BOX 선택 시 ⚠ 배지 "박스당 수량 미설정"
- [ ] 기존 행(existing-row): 단위 + PCS원가 표시
- [ ] POST: `unit[]` 배열 전송, 서버 검증(ENUM)

#### inbound 리스트 (inbound_items.php / inbound_detail.php)

> `inbound.php`는 배치(전표) 요약 리스트로 품목 행이 없어 단위 표시 대상에서 제외.
> 합계 금액(`SUM(quantity × cost_price)`)은 단위 그대로 유효 (BOX 수량 × BOX 원가).

- [ ] 수량 컬럼: "5 BOX" / "12 PCS" 단위 병기
- [ ] 원가 컬럼: BOX 행은 2단 표시 — BOX원가(주) + "PCS 5.00"(보조, 회색 작은 글씨)
- [ ] PCS 행은 기존 단일 표시

#### box_break.php (신규)

- [ ] Input: 바코드 스캔/상품명 검색 (inbound_add 패턴 재사용)
- [ ] Table: BOX lot 목록 (lot번호/유통기한/위치/잔여 BOX/ppb/BOX원가/PCS원가), 유통기한 ASC
- [ ] Form: 개봉 박스 수(min 1, max 잔여), 파손 수량(min 0, max 박스수×ppb), 비고
- [ ] Preview: "2 BOX 개봉 → PCS 39개 생성 (파손 1, 손실 5.00)" 실시간 계산
- [ ] Button: 개봉 실행 (confirm 모달 — 되돌릴 수 없음 경고)
- [ ] Table: 개봉/파손 이력 (일시/상품/박스수/생성PCS/파손/손실/담당자), 페이징
- [ ] CSRF hidden + 성공/실패 플래시

#### branch_outbound.php (수정)

- [ ] Toggle: 출고 단위 BOX/PCS (검색창 위)
- [ ] Cart Row: 단위 배지 + 변경 select, 단위별 현재고 표시 ("BOX 5" 또는 "PCS 12")
- [ ] Cart Row: 동일 상품이라도 단위 다르면 별도 행
- [ ] FEFO 미리보기: 선택 단위 lot만 표시
- [ ] Alert: PCS 부족 + BOX 보유 시 "박스 개봉 필요" 안내 + box_break.php 링크 (자동 개봉 없음 — FR-06)
- [ ] save/ship: items JSON에 unit 포함

#### order_new.php (수정)

- [ ] Row: 재고 표시 "5 BOX + 12 PCS" (단위별)
- [ ] Row: 주문 단위 select (BOX/PCS) + 수량 입력, max는 해당 단위 재고
- [ ] POST: `order_unit[]` 전송

#### inventory.php (수정)

- [ ] 집계: 상품별 BOX/PCS 분리 표시 ("5 BOX + 12 PCS")
- [ ] BOX lot 행: [개봉] 링크 → box_break.php?product_id=N

---

## 6. Error Handling

| Code/상황 | Message | Handling |
|------|---------|----------|
| 개봉: 잔여 부족 | "잔여 박스(N)보다 많이 개봉할 수 없습니다." | 400, 폼 유지 |
| 개봉: 파손 초과 | "파손 수량이 개봉 낱개 수(N)를 초과합니다." | 400, 폼 유지 |
| 개봉: BOX lot 아님/이미 소진 | "개봉 가능한 BOX 재고가 아닙니다." | 409, lot 목록 새로고침 |
| 출고: PCS 부족+BOX 보유 | "PCS 재고 부족 (부족 N개) — 박스 개봉 후 출고하세요." | 경고 표시, 진행 시 음수재고 confirm |
| 단위 값 위조 | ENUM 외 값 → "잘못된 단위입니다." | 400 |
| ppb≤1 BOX 입고 | UI ⚠ 경고, 서버는 ppb=1로 보정 후 진행 | 경고만 |

응답 포맷은 기존 컨벤션 유지: `{"success": false, "message": "..."}`

---

## 7. Security Considerations

- [x] CSRF: 모든 POST에 `lc_verify_csrf()` (기존 패턴)
- [x] 권한: `lc_require_staff()` (box_break 포함), order_new는 `lc_require_login()` + store 검증
- [x] SQL Injection: prepared statement 일관 사용
- [x] 서버측 재검증: unit ENUM, 수량 범위, ppb 보정 — 클라이언트 계산값(PCS원가 등) 무시하고 서버 재계산
- [x] 동시성: 개봉·출고 모두 `SELECT ... FOR UPDATE` + 단일 트랜잭션

---

## 8. Test Plan

> PHP 멀티페이지 앱 — L1은 PHP CLI 테스트 스크립트(`logistics/test_box_pcs_unit.php`, 기존 `test_inbound_helper.php` 패턴) + curl로 수행. Playwright 미사용 환경이므로 L2/L3는 수동 체크리스트로 대체.

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: 로직/API | unit_helper 함수, 개봉 트랜잭션, 단위별 FEFO | PHP 테스트 스크립트 (트랜잭션 롤백 방식) | Do |
| L2: UI 동작 | 토글/행 단위/자동 계산 표시 | 수동 체크리스트 | Check |
| L3: E2E | 입고→개봉→출고 전체 흐름 | 수동 시나리오 | Check |

### 8.2 L1 Scenarios

| # | Test | Expected |
|---|------|----------|
| 1 | `lc_normalize_unit('박스'/'BOX'/'box '/'EA'/null)` | BOX/BOX/BOX/PCS/PCS |
| 2 | `lc_pcs_cost(100, 20)` / `lc_pcs_cost(100, 0)` | 5.0000 / 100.0000 (ppb 보정) |
| 3 | BOX 5 입고 → `lc_get_stock_by_unit` | ['BOX'=>5,'PCS'=>0] |
| 4 | 개봉: BOX 5에서 1박스(ppb20) 개봉, 파손 1 | BOX remain 4, PCS lot quantity_in 19, breaks 1건 damage_cost=PCS단가×1 |
| 5 | 개봉 검증: boxes>remain / damaged>boxes×ppb | 400 오류, 재고 무변경 |
| 6 | PCS 10 출고(FEFO) — PCS lot 19 보유 | PCS remain 9, BOX 무변경, lot 단가 = cost_price_pcs |
| 7 | BOX 2 출고 — BOX lot에서만 차감 | BOX remain 2, PCS 무변경, lot 단가 = cost_price(BOX) |
| 8 | PCS 30 출고, PCS 12 보유 → preview | shortfall 18 + suggest_break=true (BOX 보유 시) |
| 9 | 마이그레이션: 전후 lot 수량 합계 | 동일 (라벨링만) |
| 10 | 전량 파손 개봉 (damaged = boxes×ppb) | PCS lot 미생성(new_inventory_id NULL), 이력만 기록 |

### 8.3 L2 수동 체크리스트 (요약)

§5.4 Page UI Checklist 전 항목 — 토글 동작, 행 단위 select, PCS원가 실시간 계산, 단위별 재고 배지, 개봉 미리보기 계산.

### 8.4 L3 E2E 시나리오

| # | Scenario | Steps | Success Criteria |
|---|----------|-------|------------------|
| 1 | 혼합 입고 | BOX 토글로 상품A 5박스 + PCS 토글로 상품A 10개 입고 | 재고 "5 BOX + 10 PCS", 리스트에 BOX행 2단 원가 |
| 2 | 개봉→출고 | 1박스 개봉(파손1) → PCS 25개 지점출고 | BOX 4, PCS 4 (10+19−25), 출고 lot 단가 혼합 검증 |
| 3 | 지점오더 | BOX 2 + PCS 5 주문 생성 | order_items 2행, 단위 기록 확인 |
| 4 | 부족 안내 | PCS 재고 초과 출고 시도 | "박스 개봉 필요" 안내 노출, 자동 개봉 없음 |

### 8.5 Seed Data Requirements

| Entity | Count | Key Fields |
|--------|:-----:|------------|
| lc_products | 2 | ppb=20인 BOX 상품 1, ppb=1 상품 1 (경고 테스트) |
| lc_inbound/lc_inventory | 4 lots | BOX lot 2 + PCS lot 2, 유통기한 상이 (FEFO 검증) |

---

## 9. Architecture Layers (PHP 구조 적용)

| Layer | Responsibility | Location |
|-------|---------------|----------|
| Presentation | 페이지 PHP + 인라인 JS | `logistics/*.php` |
| Application | AJAX 액션 핸들러 | `logistics/ajax/*.php` |
| Domain/Logic | 단위·FEFO·재고 로직 | `logistics/lib/unit_helper.php`, `lib/inventory_helper.php` |
| Infrastructure | DB 연결/마이그레이션 | `logistics/config/db.php`, `logistics/sql/` |

**Import 규칙**: 페이지·ajax는 lib만 require. lib는 페이지를 모름. 단위 분기 로직을 페이지에 인라인으로 작성 금지 — 반드시 unit_helper 경유.

---

## 10. Coding Convention Reference

| Item | Convention Applied |
|------|-------------------|
| 단위 상수 | 'BOX' / 'PCS' 대문자 ENUM 고정 |
| 함수 네이밍 | `lc_` 접두 + snake_case (기존 컨벤션) |
| Design Ref 주석 | `// Design Ref: §N — 설명` (기존 컨벤션 유지) |
| 표기 | 재고 "N BOX + M PCS", 수량 "N BOX"/"M PCS", PCS원가 소수 2자리 표시(저장 4자리) |
| 마이그레이션 | `lc_migration_v18.sql` + `run_migration_v18.php` 쌍, 재실행 안전 |
| JS | Vanilla JS + Tailwind, escHtml 패턴 (기존 컨벤션) |

---

## 11. Implementation Guide

### 11.1 File Structure

```
logistics/
├── sql/lc_migration_v18.sql          (신규)
├── sql/run_migration_v18.php         (신규)
├── lib/unit_helper.php               (신규)
├── box_break.php                     (신규)
├── ajax/box_break.php                (신규)
├── inbound_add.php                   (수정 — 토글/행 단위/PCS원가/저장)
├── inbound_edit.php                  (수정 — 단위 표시·수정)
├── inbound.php / inbound_items.php / inbound_detail.php  (수정 — 2단 원가/단위 병기)
├── lib/inbound_helper.php            (수정 — 리스트 쿼리에 단위·PCS원가 컬럼)
├── branch_outbound.php               (수정 — 토글/단위별 장바구니/개봉 안내)
├── ajax/branch_outbound.php          (수정 — 단위별 stock/FEFO/draft/ship)
├── order_new.php                     (수정 — 단위 select/단위별 재고/저장)
├── inventory.php                     (수정 — 단위별 집계 + 개봉 링크)
└── ajax/search_product_by_barcode.php (수정 — ppb·unit 응답 추가)
```

### 11.2 Implementation Order

1. [x] **마이그레이션 v18** (SQL + PHP 스크립트, 검증 리포트) — 실행 완료 (lot 34건 수량 무변경 검증 통과, BOX 32 / PCS 2 라벨링)
2. [x] **unit_helper.php** + L1 테스트 스크립트 (23/23 PASS)
3. [x] **search_product_by_barcode.php** ppb/unit 응답 추가
4. [x] **입고**: inbound_add → inbound_edit → 리스트 표시 (inbound_items/inbound_detail — inbound.php는 배치 요약이라 품목 단위 표시 해당 없음)
5. [x] **박스 개봉**: ajax/box_break.php → box_break.php (이력 포함)
6. [x] **지점출고**: ajax/branch_outbound.php → branch_outbound.php
7. [x] **지점오더**: order_new.php (단위 select + 단위별 max + order_unit/ppb 저장)
8. [x] **재고 표시**: inventory.php 분리 집계("N BOX + M PCS") + 개봉 링크 (목록 행 + lot 모달)
9. [ ] L3 수동 시나리오 검증 (§8.4 — 사용자 수동 확인 필요)

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| DB + 헬퍼 | `module-1` | 마이그레이션 v18, unit_helper.php, 검색 API, L1 테스트 | 15-20 |
| 입고 | `module-2` | inbound_add/edit + 리스트 3종 BOX/PCS 원가 표시 | 20-25 |
| 박스 개봉 | `module-3` | box_break.php + ajax (개봉 트랜잭션, 파손 이력) | 15-20 |
| 지점출고 | `module-4` | branch_outbound 페이지+ajax 단위별 FEFO | 20-25 |
| 오더/재고 | `module-5` | order_new + inventory 분리 표시 | 10-15 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Do | `--scope module-1` | 15-20 |
| Session 2 | Do | `--scope module-2` | 20-25 |
| Session 3 | Do | `--scope module-3,module-5` | 25-30 |
| Session 4 | Do | `--scope module-4` | 20-25 |
| Session 5 | Check + Report | 전체 | 30-40 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-06-11 | Initial draft — Option C (실용 균형) 기준 상세 설계 | whdans007 + Claude |
