---
feature: pack-unit
phase: design
created: 2026-07-01
updated: 2026-07-01
level: Dynamic
status: active
architecture: "Option C — 실용적 균형 (핵심 일반화 + UI 옵션 추가)"
base: box-pcs-unit (migration v18, lib/unit_helper.php)
plan_ref: docs/01-plan/features/pack-unit.plan.md
---

# Design: PACK 단위 추가 (묶음 단위 일반화)

## Context Anchor

| Dimension | Value |
|-----------|-------|
| **WHY** | 팩 단위 상품을 BOX와 동일한 묶음 관리(입고·개봉·원가환산) 흐름에 편입해야 한다. 단위가 코드 전반에 하드코딩되어 "묶음 단위" 추상화가 필요하다. |
| **WHO** | 물류센터 담당자(상품등록·입고·개봉), 출고/주문 담당자(PACK 주문·FEFO 출고), 시스템(단위별 재고 집계·원가환산). |
| **RISK** | BOX/PCS 하드코딩 누락 → 집계/개봉 오류. 마이그레이션 후 기존 데이터 회귀. ENUM 변경 시 기존 행 영향. |
| **SUCCESS** | PACK 등록·입고·개봉·FEFO 출고 정상 + 단위별 집계/표기 PACK 포함 + 기존 BOX/PCS 무회귀. |
| **SCOPE** | 마이그레이션 v22(ENUM 3컬럼) + `unit_helper.php` 묶음단위 일반화 + UI 7~9개 파일 + L1 테스트. lc_products 스키마·단위별 ppb 다중화 비대상. |

---

## 1. Overview

### 1.1 목적
기존 BOX/PCS 2단위 재고 시스템(box-pcs-unit, migration v18)에 **PACK을 BOX와 동급의 묶음 단위(bundle unit)**로 추가한다. 개봉 로직(`box_break`)을 BOX 전용에서 "묶음 개봉(BOX/PACK → PCS)"으로 일반화한다.

### 1.2 아키텍처 결정 (Option C)
- **핵심 재고 정합성 로직**(개봉·유효단가·FEFO·집계·검증)은 `lib/unit_helper.php`의 단일 지점에서 일반화 → 회귀 표면 최소화.
- **UI 셀렉트/토글**은 PACK 옵션만 추가 → 과설계 회피.
- "묶음 단위" 판별을 `lc_is_bundle_unit()` + `LC_BUNDLE_UNITS` 상수로 표준화하여 향후 단위 추가 비용 절감.

### 1.3 설계 원칙
- 단위 분기는 반드시 헬퍼 경유. 페이지 인라인 `=== 'BOX'` 신규 작성 금지 (Plan NFR).
- 함수/테이블 명칭(`lc_execute_box_break`, `lc_box_breaks`, `boxes_opened`)은 호환 위해 유지, 의미만 "묶음"으로 확장.
- 마이그레이션은 ENUM 값 추가(비파괴). 기존 데이터 무변경.

---

## 2. Architecture

### 2.1 단위 모델 (개념)
```
묶음 단위 (LC_BUNDLE_UNITS) : BOX, PACK   ← 개봉 가능, ppb개 PCS로 분해
최소 단위                    : PCS         ← 개봉 불가
상품당 단위 1개 (lc_products.unit ∈ {BOX, PACK, PCS})
pieces_per_box = 묶음당 낱개 수 (BOX당/PACK당 공용)
```

### 2.2 단위 흐름
```
[상품등록] unit ∈ {BOX,PACK,PCS}, ppb
      ↓
[입고] inbound_unit=PACK, ppb 스냅샷, cost_price_pcs = 원가/ppb
      ↓
[재고 lot] lc_inventory.unit=PACK
      ↓
[개봉] PACK lot → (packs×ppb − damaged) PCS lot 생성 + 이력
      ↓
[출고/주문] order_unit=PACK → PACK lot FEFO / PCS → PCS lot FEFO
```

### 2.3 유효단가 규칙 (일반화)
| lot unit | inbound_unit | 유효단가 |
|----------|-------------|----------|
| BOX | BOX | cost_price |
| PACK | PACK | cost_price |
| PCS | PCS | cost_price |
| PCS | BOX 또는 PACK (개봉 파생) | cost_price_pcs |

---

## 3. Data Model

### 3.1 마이그레이션 v22 (ENUM 값 추가 — 비파괴)
```sql
-- sql/lc_migration_v22.sql
ALTER TABLE lc_inbound     MODIFY inbound_unit ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS' COMMENT '입고 단위';
ALTER TABLE lc_inventory   MODIFY unit         ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS' COMMENT 'lot 단위';
ALTER TABLE lc_order_items MODIFY order_unit   ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS' COMMENT '주문/출고 단위';
SELECT 'Migration v22 완료: PACK 단위 추가 (inbound_unit, inventory.unit, order_unit)' AS result;
```

**변경 없음**: `lc_products.unit`(문자열 컬럼, 정규화로 처리), `pieces_per_box`(의미만 확장), `lc_box_breaks`(구조 동일).

### 3.2 마이그레이션 러너 (`sql/run_migration_v22.php`)
- v21 패턴 답습: 재실행 안전 가드(이미 PACK 포함 시 skip) + 적용 전/후 ENUM 정의 검증 출력.
- 적용 전/후 각 테이블 행수 SELECT로 무변경 확인 로그.

```php
// 가드 예시
$col = $conn->query("SHOW COLUMNS FROM lc_inventory LIKE 'unit'")->fetch_assoc();
if (strpos($col['Type'], "'PACK'") !== false) { echo "이미 적용됨 — skip\n"; }
else { /* ALTER 실행 */ }
```

---

## 4. Core Logic — `lib/unit_helper.php` 변경 (§ 핵심)

### 4.1 상수 추가
```php
const LC_UNIT_BOX  = 'BOX';
const LC_UNIT_PACK = 'PACK';                 // 신규
const LC_UNIT_PCS  = 'PCS';
const LC_BUNDLE_UNITS = [LC_UNIT_BOX, LC_UNIT_PACK]; // 개봉 가능한 묶음 단위
const LC_ALL_UNITS    = [LC_UNIT_BOX, LC_UNIT_PACK, LC_UNIT_PCS];

// 유효단가 CASE식: 개봉 파생 PCS lot 판정에 PACK 포함
const LC_EFFECTIVE_COST_SQL =
    "CASE WHEN i.unit = 'PCS' AND b.inbound_unit IN ('BOX','PACK') THEN b.cost_price_pcs ELSE b.cost_price END";
```

### 4.2 신규/변경 함수
| 함수 | 변경 내용 |
|------|-----------|
| `lc_is_bundle_unit(?string $u): bool` | **신규**. `in_array(strtoupper(trim($u)), LC_BUNDLE_UNITS, true)` |
| `lc_valid_unit()` | 허용 집합 → `LC_ALL_UNITS` (BOX/PACK/PCS) |
| `lc_normalize_unit()` | '팩'/'PACK' → PACK 인식 추가. 반환: BOX/PACK/PCS |
| `lc_get_stock_by_unit()` | 반환 초기값 3키(`LC_ALL_UNITS` 기반 `array_fill_keys`) |
| `lc_get_avg_cost_by_unit()` | 동일하게 3키 초기화 |
| `lc_format_stock()` | `LC_ALL_UNITS` 루프로 표기 → "5 BOX + 3 PACK + 12 PCS" |
| `lc_execute_box_break()` | 소스 검증 `$lot['unit'] !== 'BOX'` → `!lc_is_bundle_unit($lot['unit'])` |
| `lc_unit_fefo_preview_allow_negative()` | suggest_break: PCS 부족 시 `stock[BOX] > 0 || stock[PACK] > 0` |
| `lc_unit_fifo_ship_allow_negative()` | 변경 없음 (이미 `lc_valid_unit($unit)`로 임의 단위 처리) — PACK 자동 지원 |

### 4.3 `lc_format_stock` 재구현 (루프화)
```php
function lc_format_stock(array $stock_by_unit): string {
    $parts = [];
    foreach (LC_ALL_UNITS as $u) {
        if (!empty($stock_by_unit[$u])) $parts[] = number_format($stock_by_unit[$u]) . ' ' . $u;
    }
    return $parts ? implode(' + ', $parts) : '0';
}
```

### 4.4 개봉 일반화 (핵심 변경 지점)
```php
// lc_execute_box_break() 내 소스 검증 (기존 line 303)
if (!$lot || !lc_is_bundle_unit($lot['unit'])) {   // 기존: $lot['unit'] !== LC_UNIT_BOX
    return ['success' => false, 'message' => '개봉 가능한 묶음(BOX/PACK) 재고가 아닙니다.'];
}
```
- 산출물은 항상 PCS lot (`INSERT ... unit='PCS'`) — 변경 없음.
- ppb·파손·damage_cost·이력 기록 로직 동일. PACK도 동일 규칙 적용됨.

---

## 5. UI / Page 변경

| 파일 | 변경 |
|------|------|
| `product_add.php` (line 490-493) | Unit `<select>`에 `<option value="PACK">PACK</option>` 추가 |
| `product_edit.php` | 동일 셀렉트에 PACK 옵션 |
| `inbound_add.php` (line 336-346) | 입고 단위 토글에 PACK 버튼 추가(색상: 초록 계열). 원가계산 `$unit === LC_UNIT_BOX` → `lc_is_bundle_unit($unit)` (line 141) |
| `inbound_edit.php` | 행 단위 셀렉트/토글 PACK 반영, 묶음 판정 헬퍼화 |
| `ajax/update_inbound_item.php` | 단위 검증·PCS원가 재계산 시 `lc_is_bundle_unit()` 사용 |
| `inventory.php` | 단위별 재고 집계/표기 — `lc_format_stock()` 경유(자동), 인라인 BOX/PCS 분기 있으면 헬퍼화 |
| `order_new.php` (line 123-124, 180-181, 191) | 재고 SQL에 `SUM(CASE WHEN i.unit='PACK'...)` 추가, `$unitOpts`에 PACK 조건 추가, `lc_format_stock()` 호출에 PACK 키 전달 |
| `branch_outbound.php` / `ajax/branch_outbound.php` | 단위 셀렉트·집계·미리보기 PACK 반영 |
| `box_break.php` / `ajax/box_break.php` | 개봉 대상 lot 목록 쿼리를 `unit IN ('BOX','PACK')`(또는 `lc_is_bundle_unit` 필터)로 확장, 화면 라벨 "묶음 개봉" |
| `ajax/search_product_by_barcode.php` | 단위 응답에 PACK 정규화 반영 |

### 5.1 order_new.php 재고 SQL 변경 (구체)
```sql
-- 기존 (line 123-124)
SUM(CASE WHEN i.unit = 'BOX' THEN i.quantity_remain ELSE 0 END) AS box_stock,
SUM(CASE WHEN i.unit = 'PCS' THEN i.quantity_remain ELSE 0 END) AS pcs_stock
-- 추가
SUM(CASE WHEN i.unit = 'PACK' THEN i.quantity_remain ELSE 0 END) AS pack_stock
```
```php
// $unitOpts 구성 (line 180-181)에 추가
if ($packStock > 0) $unitOpts[] = LC_UNIT_PACK;
// lc_format_stock 호출 (line 191)
lc_format_stock([LC_UNIT_BOX=>$boxStock, LC_UNIT_PACK=>$packStock, LC_UNIT_PCS=>$pcsStock]);
```

### 5.2 UI 색상 컨벤션
- BOX = 주황(amber), PCS = 파랑(blue) (기존). **PACK = 초록(emerald)** 신규 지정 — 토글/뱃지 일관 적용.

---

## 6. 개봉 대상 lot 쿼리 (box_break.php)
```sql
-- 개봉 가능 lot 목록: 묶음 단위만
SELECT i.id, i.unit, i.lot_number, i.quantity_remain, ...
FROM lc_inventory i
WHERE i.product_id = ? AND i.unit IN ('BOX','PACK') AND i.quantity_remain > 0
ORDER BY i.expiry_date ASC, i.id ASC
```
- 목록에 unit(BOX/PACK) 뱃지 표시. 개봉 실행은 `lc_execute_box_break()` 그대로 호출(일반화됨).

---

## 7. 동시성 / 트랜잭션
- 기존 정책 유지: 개봉·출고는 `autocommit(false)` + `SELECT ... FOR UPDATE` + commit/rollback.
- PACK도 동일 lock 경로 사용(단위 필터만 확장) — 신규 동시성 이슈 없음.

---

## 8. Test Plan

### 8.1 무회귀
- 기존 `test_box_pcs_unit.php` 전체 재실행 → 전부 PASS 확인 (BOX/PCS 로직 불변 검증).

### 8.2 PACK L1 테스트 (`test_pack_unit.php` 신규, 트랜잭션 ROLLBACK)
| # | 시나리오 | 기대 |
|---|----------|------|
| 1 | `lc_is_bundle_unit('BOX'/'PACK'/'PCS')` | true/true/false |
| 2 | `lc_valid_unit('pack')` / `lc_normalize_unit('팩')` | PACK / PACK |
| 3 | PACK 상품(ppb=6) 5팩 입고 → `lc_get_stock_by_unit` | PACK=5, BOX=0, PCS=0 |
| 4 | `lc_format_stock` | "5 PACK" |
| 5 | 1팩 개봉(파손1) → `lc_execute_box_break` | pcs_created=5, PACK=4, PCS=5 |
| 6 | 개봉 파생 PCS lot 유효단가 | cost_price_pcs (원가/6) |
| 7 | PACK 2 출고(FEFO) | PACK lot에서만 차감, PCS 무변경 |
| 8 | PCS 부족 + PACK 보유 → preview | suggest_break=true |
| 9 | PCS lot 개봉 시도 | 거부(최소 단위) |
| 10 | BOX+PACK 혼재 상품 아님 확인(상품당 1단위) | 등록 시 unit 단일 |

### 8.3 수기 시나리오
PACK 상품 등록 → 입고 → box_break 화면에서 PACK lot 개봉 → PCS 출고 → 재고표기 "N PACK + M PCS" 확인.

---

## 9. Risks & Mitigations
| Risk | Mitigation |
|------|-----------|
| 하드코딩 누락(집계 SQL, `$unitOpts`) | §5 파일별 체크리스트 기준 일괄 점검, `lc_format_stock` 루프화로 표기 누락 원천 차단 |
| PCS lot 개봉 허용 회귀 | `lc_is_bundle_unit()` 명시 + L1 #9 |
| 유효단가 PACK 파생 누락 | `LC_EFFECTIVE_COST_SQL` 단일 상수 수정 → 전 쿼리 반영, L1 #6 |
| ENUM ALTER 데이터 영향 | v22 가드 + 적용 전/후 행수 검증 |

---

## 10. Rollback 전략
- 코드: git revert. 헬퍼/페이지 변경은 BOX/PCS 동작에 영향 없도록 순수 추가·일반화.
- DB: ENUM에서 PACK 제거는 PACK 데이터 존재 시 위험 → **rollback 시 PACK lot/입고/주문 존재 여부 확인 후에만** 축소. 원칙적으로 ENUM 값 추가는 되돌릴 필요 없음(미사용 시 무해).

---

## 11. Implementation Guide

### 11.1 구현 순서
1. `sql/lc_migration_v22.sql` + `sql/run_migration_v22.php` 작성·실행
2. `lib/unit_helper.php` — 상수/`lc_is_bundle_unit`/집계·표기/개봉·preview 일반화
3. 입고·개봉 페이지 (`inbound_add/edit`, `ajax/update_inbound_item`, `box_break`, `ajax/box_break`)
4. 상품등록·주문·출고·재고 (`product_add/edit`, `order_new`, `branch_outbound`, `ajax/branch_outbound`, `inventory`, `ajax/search_product_by_barcode`)
5. `test_pack_unit.php` 작성 + 기존 테스트 재실행

### 11.2 Key Files
- 변경 핵심: `lib/unit_helper.php` (일반화 단일 지점)
- 신규: `sql/lc_migration_v22.sql`, `sql/run_migration_v22.php`, `test_pack_unit.php`
- 수정 UI: 위 §5 표 (약 9개)

### 11.3 Session Guide (Module Map)

| Module | 범위 | 파일 | 산출물 |
|--------|------|------|--------|
| **module-1** | 마이그레이션 + 헬퍼 일반화 | `sql/lc_migration_v22.*`, `lib/unit_helper.php` | ENUM 확장, `lc_is_bundle_unit`, 집계/표기/개봉 일반화 |
| **module-2** | 입고 + 개봉 | `inbound_add/edit.php`, `ajax/update_inbound_item.php`, `box_break.php`, `ajax/box_break.php` | PACK 입고, 묶음 개봉(BOX/PACK) |
| **module-3** | 상품등록 + 주문/출고 + 표기 | `product_add/edit.php`, `order_new.php`, `branch_outbound.php`, `ajax/branch_outbound.php`, `inventory.php`, `ajax/search_product_by_barcode.php` | PACK 등록·주문·출고·재고표기 |
| **module-4** | 테스트 | `test_pack_unit.php` + 기존 재실행 | L1 PASS, 무회귀 |

**권장 세션 분할**: module-1 (기반, 필수 선행) → module-2 → module-3 → module-4.
각 모듈은 `/pdca do pack-unit --scope module-N`으로 개별 실행 가능.

---

## Next Steps

→ **Do Phase**: `/pdca do pack-unit --scope module-1` (마이그레이션 + 헬퍼부터)
- module-1 완료 후 기존 `test_box_pcs_unit.php` 재실행으로 무회귀 조기 검증 권장
