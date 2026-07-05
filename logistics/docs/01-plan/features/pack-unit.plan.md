---
feature: pack-unit
phase: plan
created: 2026-06-30
updated: 2026-06-30
level: Dynamic
status: active
base: box-pcs-unit (v18 확장)
---

# Plan: PACK 단위 추가 (묶음 단위 일반화)

## Executive Summary

| Perspective | Description |
|------------|-------------|
| **Problem** | 상품 단위가 BOX/PCS 2종뿐이라 "팩(PACK)"으로 묶여 입고·판매되는 상품을 등록·관리할 수 없다. 현재 단위 로직(ENUM·헬퍼·개봉)이 BOX/PCS에 하드코딩되어 있어 단순 옵션 추가만으로 해결되지 않는다. |
| **Solution** | PACK을 BOX와 동급의 **묶음 단위(bundle unit)**로 추가한다. 기존 `box_break`(BOX→PCS)를 "묶음 개봉(BOX/PACK→PCS)"으로 일반화하고, 단위 분기 로직을 `lib/unit_helper.php`에 집중시켜 BOX/PACK/PCS 3종을 일관 처리한다. |
| **Function UX Effect** | 상품등록·입고·재고집계·주문/출고·개봉 전 화면의 단위 셀렉트에 PACK이 추가되고, PACK lot도 BOX처럼 개봉하여 PCS로 분해·차감된다. 재고 표기는 "5 BOX + 3 PACK + 12 PCS"처럼 단위별로 표시된다. |
| **Core Value** | 팩 단위 상품의 정확한 재고·원가 관리, 단위 로직 일원화로 향후 단위 추가 시 확장 비용 최소화, ENUM 값 추가 기반의 무손실 마이그레이션. |

## Context Anchor

| Dimension | Value |
|-----------|-------|
| **WHY** | 팩 단위 상품을 BOX와 동일한 묶음 관리(입고·개봉·원가환산) 흐름에 편입해야 한다. 단위가 코드 전반에 하드코딩되어 "묶음 단위" 추상화가 필요하다. |
| **WHO** | 물류센터 담당자(상품등록·입고·개봉), 출고/주문 담당자(PACK 단위 주문·FEFO 출고), 시스템(단위별 재고 집계·원가환산). |
| **RISK** | BOX/PCS 하드코딩(`=== 'BOX'`, `['BOX','PCS']`) 누락 지점에서 PACK 미반영 → 집계/개봉 오류. 마이그레이션 후 기존 BOX/PCS 데이터 회귀. ENUM 변경 시 기존 행 영향. |
| **SUCCESS** | ✅ 상품을 PACK 단위로 등록·입고 가능 ✅ PACK lot 개봉 → PCS 생성·원가환산 정확 ✅ 단위별 재고 집계·표기에 PACK 포함 ✅ PACK 주문/출고 FEFO 차감 ✅ 기존 BOX/PCS 동작 무회귀 |
| **SCOPE** | 마이그레이션 v22(ENUM 3컬럼 확장) + `unit_helper.php` 묶음단위 일반화 + UI/페이지 7~9개 PACK 반영 + L1 테스트. lc_products 스키마 변경·단위별 ppb 다중화는 비대상. |

---

## 1. Requirements

### Functional Requirements

**FR-01: PACK 단위 상품 등록**
- `product_add.php` / `product_edit.php`의 Unit 셀렉트에 `PACK` 옵션 추가
- 상품당 묶음 단위는 1개(`lc_products.unit ∈ {BOX, PACK, PCS}`)
- `pieces_per_box` 컬럼을 PACK 상품에서는 "팩당 낱개 수"로 재사용 (스키마 추가 없음)
- `lc_valid_unit()` 허용 집합에 PACK 추가

**FR-02: PACK 입고**
- `inbound_add.php` / `inbound_edit.php` / `ajax/update_inbound_item.php`에서 입고 단위로 PACK 선택 가능
- `lc_inbound.inbound_unit` ENUM에 PACK 추가
- PACK 입고 시 `cost_price_pcs` = PACK 원가 / ppb (BOX와 동일 환산식)
- 행별 ppb 오버라이드(`lc_resolve_row_ppb`)는 PACK에도 동일 적용

**FR-03: 묶음 개봉 일반화 (BOX/PACK → PCS)**
- `lc_execute_box_break()`의 소스 단위 검증을 "묶음 단위(BOX 또는 PACK)" 허용으로 일반화
- PACK lot 개봉 → ppb개 PCS lot 생성, 파손 수량·손실원가(`damage_cost`) 동일 규칙
- `box_break.php` / `ajax/box_break.php` 화면 1개에서 BOX·PACK lot 모두 개봉 가능
- PCS lot은 개봉 불가(최소 단위) — 기존 규칙 유지
- 개봉 이력(`lc_box_breaks`)은 BOX/PACK 구분 없이 동일 테이블 기록(소스 lot의 unit으로 판별 가능)

**FR-04: 단위별 재고 집계·표기 확장**
- `lc_get_stock_by_unit()` 반환 키를 `['BOX','PACK','PCS']` 3종으로 확장
- `lc_get_avg_cost_by_unit()` 동일하게 PACK 포함
- `lc_format_stock()` → "5 BOX + 3 PACK + 12 PCS" / "3 PACK" / "0" 형식
- `inventory.php` 단위별 재고 표시에 PACK 반영

**FR-05: PACK 주문·출고**
- `lc_order_items.order_unit` ENUM에 PACK 추가
- `order_new.php` / `branch_outbound.php` / `ajax/branch_outbound.php`에서 PACK 단위 주문/출고
- `lc_unit_fifo_ship_allow_negative()` / `lc_unit_fefo_preview_allow_negative()`가 PACK 단위 lot에서 FEFO 차감(음수 허용 정책 동일)
- PCS 부족 시 `suggest_break` 판정에 BOX뿐 아니라 PACK 보유도 포함

**FR-06: 유효단가 일관성**
- `LC_EFFECTIVE_COST_SQL` CASE식의 `b.inbound_unit = 'BOX'` → `b.inbound_unit IN ('BOX','PACK')`
- 개봉 파생 PCS lot은 `cost_price_pcs`, 원본 묶음 lot은 `cost_price`

### Non-Functional Requirements

- **무회귀**: 기존 BOX/PCS 입고·재고·주문·개봉 동작이 모두 종전과 동일해야 함
- **무손실 마이그레이션**: ENUM 값 추가는 기존 행 무변경(비파괴). 재실행 안전 가드 포함
- **로직 일원화**: 단위 분기는 `unit_helper.php` 경유. 페이지 인라인 `=== 'BOX'` 신규 작성 금지
- **표시 규칙**: 원가/합계 소숫점 둘째자리(프로젝트 지침 준수)

---

## 2. Scope & Constraints

### In Scope
- DB: `lc_inbound.inbound_unit`, `lc_inventory.unit`, `lc_order_items.order_unit` ENUM에 PACK 추가 (마이그레이션 v22)
- 헬퍼: `lib/unit_helper.php` 묶음 단위 일반화 (상수·검증·집계·표기·개봉·FEFO)
- UI: 상품등록/수정, 입고 추가/수정, 재고, 주문, 출고, 개봉 화면 PACK 반영
- 테스트: `test_box_pcs_unit.php` 패턴의 PACK L1 테스트 추가

### Out of Scope
- `lc_products` 스키마 변경 (단위별 ppb 다중화 — "한 상품 = 묶음 단위 1개" 결정으로 불필요)
- 한 상품이 BOX+PACK 동시 보유하는 모델
- BOX↔PACK 직접 환산(둘 다 PCS로만 개봉; 묶음↔묶음 변환 없음)
- 다국어 신규 라벨 외 UI 전면 개편

### Technical Constraints
- 기존 v18~v21 마이그레이션 패턴(`run_migration_vNN.php` 가드+검증) 답습
- 트랜잭션 정책(autocommit(false)+commit/rollback) 유지
- 단위 정규화(`lc_normalize_unit`)는 '팩'/'PACK' 입력도 PACK으로 인식하도록 확장

---

## 3. Architecture Overview

### Data Model 변경 (마이그레이션 v22)
```sql
ALTER TABLE lc_inbound     MODIFY inbound_unit ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS';
ALTER TABLE lc_inventory   MODIFY unit         ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS';
ALTER TABLE lc_order_items MODIFY order_unit   ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS';
```
- 기존 행: BOX/PCS 값 유지 (ENUM 값 추가는 데이터 무변경)
- `pieces_per_box` 컬럼 의미 확장: "묶음(BOX 또는 PACK)당 낱개 수"

### 묶음 단위 추상화 (unit_helper.php)
```
LC_UNIT_BOX = 'BOX'
LC_UNIT_PACK = 'PACK'   (신규)
LC_UNIT_PCS = 'PCS'
LC_BUNDLE_UNITS = ['BOX','PACK']   (개봉 가능 = 묶음 단위)

lc_is_bundle_unit($u): in_array(정규화($u), LC_BUNDLE_UNITS)
lc_valid_unit():  허용 집합 {BOX,PACK,PCS}
lc_execute_box_break(): 소스 lot unit ∈ LC_BUNDLE_UNITS 허용 (산출물 PCS)
LC_EFFECTIVE_COST_SQL: inbound_unit IN ('BOX','PACK') → cost_price_pcs
```

### 단위 흐름
```
[상품등록] unit ∈ {BOX,PACK,PCS}, ppb=묶음당 낱개
      ↓
[입고] inbound_unit=PACK, ppb 스냅샷, cost_price_pcs=원가/ppb
      ↓
[재고] lc_inventory.unit=PACK lot
      ↓
[개봉] PACK lot → (boxes×ppb − damaged) PCS lot 생성
      ↓
[출고/주문] order_unit=PACK → PACK lot FEFO 차감 / PCS → PCS lot 차감
```

---

## 4. User Flows

### 상품등록 (PACK)
1. `product_add.php` → Unit 셀렉트에서 PACK 선택
2. "Units per Box(PKG)" 입력 = 팩당 낱개 수(ppb)
3. 저장 → `lc_products.unit='PACK'`, `pieces_per_box=ppb`

### 입고 (PACK)
1. `inbound_add.php` → 상품 선택 시 unit=PACK 자동, 입고 단위 PACK
2. PACK 수량·원가 입력 → `cost_price_pcs` 자동 환산
3. 저장 → `lc_inbound`(inbound_unit=PACK) + `lc_inventory`(unit=PACK) lot 생성

### 개봉 (PACK → PCS)
1. `box_break.php` → 묶음 lot 목록에 PACK lot 노출
2. 개봉 팩 수·파손 수 입력 → 검증(잔여·파손 상한)
3. 실행 → PACK lot 차감 + PCS lot 생성 + `lc_box_breaks` 이력

### 주문/출고 (PACK)
1. `order_new.php` / `branch_outbound.php` → 단위 PACK 선택, 수량 입력
2. 미리보기 → PACK lot FEFO 피킹(부족 시 shortfall·suggest_break 안내)
3. 확정 → PACK lot 차감(음수 허용 정책)

---

## 5. Success Criteria

| Criterion | Definition |
|-----------|-----------|
| **SC-01** | `product_add/edit`에서 PACK 단위 상품 등록·수정 가능, `lc_products.unit='PACK'` 저장 |
| **SC-02** | 마이그레이션 v22 적용 후 3개 ENUM에 PACK 포함, 기존 BOX/PCS 행 무변경 |
| **SC-03** | PACK 입고 시 `inbound_unit='PACK'`, `cost_price_pcs = 원가/ppb` 정확 |
| **SC-04** | PACK lot 개봉 → `pcs_created = packs×ppb − damaged`, 전량 파손 시 PCS lot 미생성 |
| **SC-05** | 개봉 파생 PCS lot 유효단가 = `cost_price_pcs`, 원본 PACK lot = `cost_price` |
| **SC-06** | `lc_get_stock_by_unit()`가 BOX/PACK/PCS 3키 반환, `lc_format_stock()` PACK 표기 |
| **SC-07** | PACK 주문/출고 시 PACK lot에서만 FEFO 차감(BOX/PCS lot 무변경) |
| **SC-08** | PCS 부족 + (BOX 또는 PACK 보유) 시 `suggest_break=true` |
| **SC-09** | 기존 box-pcs-unit L1 테스트 전부 PASS (무회귀) + PACK L1 테스트 신규 PASS |
| **SC-10** | PCS lot 개봉 시도는 거부(최소 단위), 잔여/파손 초과 개봉 거부 |

---

## 6. Risks & Mitigations

| Risk | Severity | Mitigation |
|------|----------|-----------|
| BOX/PCS 하드코딩 누락 지점에 PACK 미반영 | High | 전수 조사된 12~13개 파일 기준 일괄 점검, 분기는 `lc_is_bundle_unit()`/`lc_valid_unit()`로 통일 |
| ENUM ALTER 중 기존 데이터/제약 영향 | Medium | ENUM 값 추가는 비파괴. v22 가드 스크립트에서 적용 전/후 행수·분포 검증 |
| `lc_format_stock`·집계 키 확장 누락으로 PACK 미표시 | Medium | 헬퍼 반환 키를 상수 배열 기반으로 생성, 표기 로직 단위 루프화 |
| 개봉 일반화 시 PCS lot까지 개봉 허용되는 회귀 | High | 소스 검증을 `lc_is_bundle_unit()`로 명시, L1 테스트(SC-10)로 거부 검증 |
| 유효단가 CASE식 누락(PACK 파생 PCS 단가 오류) | High | `LC_EFFECTIVE_COST_SQL` 단일 상수만 수정 → 전 쿼리 일괄 반영, SC-05로 검증 |

---

## 7. Implementation Plan

### Phase 1: 마이그레이션 v22 (0.5h)
1. `sql/lc_migration_v22.sql` — 3개 ENUM MODIFY (PACK 추가)
2. `sql/run_migration_v22.php` — 재실행 가드 + 적용 전/후 검증 (v21 패턴 답습)

### Phase 2: unit_helper.php 묶음 단위 일반화 (1.5h)
1. `LC_UNIT_PACK` 상수 + `LC_BUNDLE_UNITS` + `lc_is_bundle_unit()`
2. `lc_valid_unit()` / `lc_normalize_unit()` PACK 허용
3. `LC_EFFECTIVE_COST_SQL` → `inbound_unit IN ('BOX','PACK')`
4. `lc_get_stock_by_unit()` / `lc_get_avg_cost_by_unit()` 3키 확장
5. `lc_format_stock()` PACK 표기
6. `lc_execute_box_break()` 소스 검증 일반화
7. `lc_unit_fefo_preview_allow_negative()` suggest_break에 PACK 포함

### Phase 3: UI/페이지 PACK 반영 (2~3h)
- `product_add.php`, `product_edit.php` — Unit 셀렉트 PACK 옵션
- `inbound_add.php`, `inbound_edit.php`, `ajax/update_inbound_item.php` — 입고 단위 PACK
- `inventory.php` — 단위별 재고 표기 PACK
- `box_break.php`, `ajax/box_break.php` — 묶음 lot 목록에 PACK 노출
- `order_new.php`, `branch_outbound.php`, `ajax/branch_outbound.php` — 주문/출고 PACK
- `ajax/search_product_by_barcode.php` — 단위 응답 PACK 처리

### Phase 4: 테스트 & QA (1~2h)
1. 기존 `test_box_pcs_unit.php` 재실행 → 무회귀 확인
2. PACK L1 테스트 추가(개봉/FEFO/원가환산, 트랜잭션 ROLLBACK)
3. 수기 시나리오: PACK 등록→입고→개봉→PCS 출고

---

## 8. Open Questions

1. **PACK 한글 표기**: UI 라벨 "PACK" 그대로 vs "팩" 병기? → 권장: "PACK(팩)" 병기
2. **다국어**: lang_helper에 PACK 라벨 키 추가 필요 여부 → 기존 BOX/PCS 처리 방식 확인 후 동일 적용
3. **기존 box_break 네이밍**: 함수/테이블명(`box_break`, `lc_box_breaks`)은 호환 위해 유지(의미만 "묶음 개봉"으로 확장) → 권장: 유지

---

## 9. Dependencies

- 선행: box-pcs-unit (마이그레이션 v18, `unit_helper.php`) — 이미 구현·동작 중
- 신규 npm 패키지 없음
- 마이그레이션 v22 필요 (v18 이후 v19~v21 적용 상태 가정)

---

## 10. Success Metrics

- ✅ PACK 상품 등록→입고→개봉→출고 전 흐름 오류 0건
- ✅ 기존 BOX/PCS 회귀 0건 (기존 L1 테스트 전부 PASS)
- ✅ 단위 분기 신규 인라인 하드코딩 0건(전부 헬퍼 경유)

---

## Next Steps

→ **Design Phase**: `/pdca design pack-unit`
- 묶음 단위 추상화(`lc_is_bundle_unit`) 상세 설계 + 3개 아키텍처 옵션 비교
- 개봉 일반화 시퀀스/원가환산 데이터 모델 확정
- Session Guide: module-1(마이그레이션+헬퍼), module-2(입고/개봉), module-3(주문/출고/표기)
