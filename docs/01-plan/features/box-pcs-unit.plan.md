# box-pcs-unit Planning Document

> **Summary**: 물류센터 입고/지점오더/지점출고에 BOX·PCS 단위 선택 기능 추가 + BOX/PCS 재고 분리 관리 + 박스 개봉(파손 등록) 기능
>
> **Project**: sunset (Logistics Center)
> **Version**: -
> **Author**: whdans007 + Claude
> **Date**: 2026-06-11
> **Status**: Draft (v0.2 — 재고 분리 모델로 변경)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 상품이 BOX 또는 PCS(낱개)로 입고되는데 현재 시스템은 단위 구분 없이 수량만 저장해, 입고 단위가 섞이면 재고·원가가 부정확해진다. 또한 박스 내 일부 파손 시 나머지를 낱개 재고로 전환할 방법이 없다. |
| **Solution** | 입고/지점오더/지점출고 화면에 BOX·PCS 토글을 추가하고, 재고 lot에 단위를 기록해 **BOX 재고와 PCS 재고를 분리 관리**한다. 별도의 **박스 개봉 페이지**에서 BOX→PCS 전환과 파손 수량 등록을 처리한다. BOX 입고 시 BOX 원가와 PCS 원가(BOX원가÷박스당 낱개수)를 동시 기록·표시한다. |
| **Function/UX Effect** | 스캔 전 토글만 선택하면 해당 단위로 입고/출고되고, 재고는 "5 BOX + 12 PCS"처럼 분리 표시된다. 파손 발생 시 개봉 페이지에서 박스를 풀어 파손분을 제외한 낱개 재고를 만들 수 있다. |
| **Core Value** | 단위 혼합 입출고와 파손 처리까지 재고 수량·원가가 항상 실물과 일치한다. |

---

## Context Anchor

> Auto-generated from Executive Summary. Propagated to Design/Do documents for context continuity.

| Key | Value |
|-----|-------|
| **WHY** | BOX/PCS 혼합 입출고 시 단위 구분이 없어 재고·원가 왜곡 + 박스 일부 파손 시 낱개 전환 수단 부재 |
| **WHO** | 물류센터 직원(입고/지점출고/박스 개봉), 지점 담당자(지점오더) |
| **RISK** | BOX lot과 PCS lot 분리로 출고·FEFO·재고집계 로직 전반이 단위별 분기 — 차감 단위 불일치 버그가 최대 리스크 |
| **SUCCESS** | BOX 5개 입고 → BOX 재고 5 / 1박스 개봉(1개 파손) → BOX 4 + PCS 19, 파손 이력 1건 / PCS 10개 출고 → PCS 9 |
| **SCOPE** | ① DB 마이그레이션(v18) ② 입고 화면/저장 ③ 입고 리스트 3종 ④ 지점출고 ⑤ 지점오더 ⑥ 박스 개봉 페이지(신규) ⑦ 재고 화면 분리 표시 |

---

## 1. Overview

### 1.1 Purpose

물류센터 입출고 전 과정(입고 → 재고 → 지점오더/지점출고)에 BOX/PCS 단위 개념을 도입한다.
사용자가 화면 토글로 단위를 선택한 뒤 바코드를 스캔하면 해당 단위로 입출고되며,
재고는 lot 단위로 BOX/PCS가 분리 관리된다. 박스 개봉 기능으로 BOX 재고를 PCS 재고로
전환할 수 있고, 이때 파손 수량을 제외하고 등록하며 파손 이력이 남는다.

### 1.2 Background

- 상품 마스터(`lc_products`)에는 이미 `pieces_per_box`(박스당 낱개 수), `barcode_unit`(낱개 바코드), `barcode_box`(박스 바코드)가 존재
- 그러나 입고(`lc_inbound`)·재고(`lc_inventory`)·주문(`lc_order_items`)에는 단위 컬럼이 없어 수량의 단위가 암묵적(상품의 `unit` 값)임
- 실제 운영에서는 같은 상품이 BOX로도, 낱개로도 입고되며, 박스 내 일부 파손 시 나머지를 낱개로 판매/출고해야 함
- **사용자 결정**: 재고를 PCS로 환산 통일하지 않고 BOX lot / PCS lot을 분리 유지 (파손→낱개 전환 시나리오 지원)

### 1.3 Related Documents

- 기존 기능: `docs/01-plan/features/branch-outbound.plan.md`, `docs/02-design/features/branch-outbound.design.md`
- 스키마: `logistics/sql/lc_migration.sql` ~ `lc_migration_v16.sql`

---

## 2. Scope

### 2.1 In Scope

- [ ] **DB 마이그레이션 v18** (+ PHP 실행 스크립트 — 필수 규칙)
  - `lc_inbound`: `inbound_unit` ENUM('BOX','PCS'), `pieces_per_box`(입고 시점 스냅샷), `cost_price_pcs`(PCS 환산 원가) 추가
  - `lc_inventory`: `unit` ENUM('BOX','PCS') 추가 — **수량 환산 없음**, 기존 lot은 상품 unit 값 따라 라벨링
  - `lc_order_items`: `order_unit` ENUM('BOX','PCS'), `pieces_per_box` 추가
  - 신규 테이블 `lc_box_breaks`: 박스 개봉/파손 이력 (개봉 lot, 박스 수, 생성 PCS 수, 파손 수량, 파손 원가손실, 담당자, 일시)
- [ ] **입고 화면(`logistics/inbound_add.php`)**: BOX/PCS 토글(전역, 스캔 시 행에 적용), 행별 단위 표시·변경, BOX 선택 시 PCS 원가 자동 계산 표시, 저장 시 단위 기록 (inventory lot에도 단위 기록)
- [ ] **입고 수정(`logistics/inbound_edit.php`)**: 단위 표시·수정 반영
- [ ] **입고 리스트 표시**: `inbound.php`, `inbound_items.php`, `inbound_detail.php` — BOX 입고 행은 BOX원가와 PCS원가 동시 표시, 수량은 "5 BOX" 형식 단위 병기
- [ ] **박스 개봉 페이지(신규, `logistics/box_break.php`)**: 바코드 스캔/검색으로 상품 선택 → BOX lot 선택 → 개봉 박스 수·파손 수량 입력 → BOX 재고 차감 + PCS lot 생성(유통기한/lot번호/보관위치 승계) + 파손 이력 기록
- [ ] **지점출고(`logistics/branch_outbound.php` + `ajax/branch_outbound.php`)**: BOX/PCS 토글, 행별 단위, 단위별 재고 표시("5 BOX + 12 PCS"), 차감·FEFO는 동일 단위 lot에서만. PCS 부족 시 개봉 안내 메시지(자동 개봉 없음)
- [ ] **지점오더(`logistics/order_new.php`)**: 주문 수량에 BOX/PCS 단위 선택, 단위별 재고 표시, `lc_order_items`에 단위 기록
- [ ] **재고 화면(`inventory.php`)**: 상품별 BOX/PCS 분리 집계 표시 + BOX lot 행에 [개봉] 바로가기
- [ ] **검색 API(`ajax/search_product_by_barcode.php`)**: 응답에 `pieces_per_box` 포함

### 2.2 Out of Scope

- 바코드 종류(barcode_box vs barcode_unit)에 따른 단위 자동 전환 (사용자 결정: 토글 수동 선택만)
- PCS 출고 시 BOX 자동 개봉 (사용자 결정: 수동 개봉만 — 부족 시 안내만 표시)
- BOX/PCS 외 제3 단위(팔레트 등) 지원
- PCS → BOX 역방향 묶음(재포장) 기능
- 개봉 외 상황의 파손 등록(일반 재고조정) — 추후 별도 기능
- 기존 출고 완료 데이터(`lc_order_item_lots`)의 소급 단위 보정
- 관리자(admin)·오피스(office) 모듈 — 물류센터(logistics)만 대상

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 입고 화면에 BOX/PCS 토글 제공. 스캔/검색으로 추가되는 행은 토글의 현재 단위로 등록되며, 행별로 단위 변경 가능 | High | Pending |
| FR-02 | BOX 단위 입고 시 PCS 원가 = 최종원가 ÷ pieces_per_box 자동 계산(DECIMAL 15,4), 입력 행과 리스트에 표시 | High | Pending |
| FR-03 | 저장 시 `lc_inbound`에 단위·ppb 스냅샷·PCS원가 기록, `lc_inventory` lot에 단위 기록 (수량은 입고 단위 그대로) | High | Pending |
| FR-04 | 입고 리스트 3종에서 BOX 입고 행은 BOX원가+PCS원가 동시 표시, 수량은 단위 병기 | High | Pending |
| FR-05 | 지점출고: BOX/PCS 토글 + 행별 단위. 차감·FEFO는 동일 단위 lot에서만 수행. 단위별 재고 표시 | High | Pending |
| FR-06 | 지점출고에서 PCS 재고 부족 시 자동 개봉하지 않고 "박스 개봉 필요" 안내 표시 (BOX 재고 보유 시) | High | Pending |
| FR-07 | 박스 개봉 페이지: BOX lot 선택 → 개봉 박스 수 + 파손 수량 입력 → BOX 차감, PCS lot 생성(유통기한·lot번호·위치 승계, PCS원가 적용), 트랜잭션 처리 | High | Pending |
| FR-08 | 파손 이력 기록: `lc_box_breaks`에 일시/상품/lot/개봉 수/생성 PCS/파손 수량/파손 원가손실/담당자 저장, 조회 화면 제공 | High | Pending |
| FR-09 | 지점오더(order_new.php): 주문 수량에 단위 선택, 단위별 재고 표시, `lc_order_items`에 단위 기록 | High | Pending |
| FR-10 | 기존 데이터 마이그레이션: lot 수량 변경 없이 상품 unit 값 기준 단위 라벨링(BOX 계열 → 'BOX', 그 외 → 'PCS'). PHP 실행 스크립트 동반 | High | Pending |
| FR-11 | pieces_per_box가 1 또는 NULL인 상품은 BOX 입고/개봉 시 경고 — 환산 오류 방지 (서버는 ppb<1 시 1로 보정) | Medium | Pending |
| FR-12 | 재고 화면(inventory.php): 상품별 "N BOX + M PCS" 분리 집계, BOX lot 행에 개봉 바로가기 | Medium | Pending |
| FR-13 | 입고 수정(inbound_edit.php)에서 단위 변경 시 연결된 inventory lot의 단위·수량 일관 갱신 | Medium | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| 데이터 정합성 | 개봉 트랜잭션: BOX 차감 + PCS 생성 + 이력 기록이 원자적 (부분 실패 없음) | 코드 리뷰 + 테스트 |
| 마이그레이션 안전성 | lot 수량 무변경 (라벨링만) — 전후 수량 합계 동일 | 검증 쿼리 |
| 호환성 | 단위 컬럼 DEFAULT 값으로 기존 흐름 하위 호환 | 기존 페이지 회귀 확인 |
| 성능 | 리스트 페이지 응답 변화 없음 (스냅샷 컬럼 사용, 추가 JOIN 최소화) | 페이지 로드 육안 확인 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] SC-1: BOX 토글 상태에서 스캔 → BOX 단위 행 생성, 저장 시 BOX lot 등록 (재고 "5 BOX" 표시)
- [ ] SC-2: PCS 토글 상태에서 스캔 → PCS 단위 행 생성, 저장 시 PCS lot 등록
- [ ] SC-3: BOX 입고 행의 리스트 표시에 BOX원가와 PCS원가(=BOX원가÷ppb)가 동시 노출
- [ ] SC-4: 박스 개봉 — BOX 5 보유 상품 1박스(20개) 개봉, 파손 1 입력 → BOX 4 + PCS 19, `lc_box_breaks` 이력 1건(손실원가 = PCS원가×1)
- [ ] SC-5: 지점출고 BOX 단위 → BOX lot에서만 FEFO 차감, PCS 단위 → PCS lot에서만 차감
- [ ] SC-6: PCS 재고 부족 + BOX 재고 보유 시 출고 화면에 "박스 개봉 필요" 안내 표시
- [ ] SC-7: 지점오더에서 단위 선택 주문 생성, lc_order_items에 단위 기록
- [ ] SC-8: 마이그레이션 후 기존 lot이 상품 unit 따라 라벨링되고 수량 변화 없음 (검증 쿼리 통과)
- [ ] SC-9: 마이그레이션 SQL + PHP 실행 스크립트 한 쌍 제공 (run_migration_v18.php)

### 4.2 Quality Criteria

- [ ] 기존 입고/출고/오더 회귀 없음 (단위 컬럼 DEFAULT 값으로 하위 호환)
- [ ] PHP 문법 오류 0 (php -l)
- [ ] 환산·차감 계산은 서버 측에서 최종 검증 (클라이언트 JS 값 신뢰하지 않음)
- [ ] 개봉 트랜잭션 동시성: 동일 lot 동시 개봉/출고 시 재고 음수 방지 (row lock)

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 단위별 차감 분기 누락 → BOX 주문이 PCS lot에서 차감되는 버그 | High | Medium | FEFO 쿼리에 `unit = ?` 조건 필수화, 단위 헬퍼 함수로 일원화(`lib/unit_helper.php`), 테스트 시나리오에 혼합 케이스 포함 |
| 기존 lot 단위 오판(라벨링) | Medium | Low | 수량 무변경 라벨링이라 영향 제한적. unit 값 정규화 규칙('BOX','박스','box'→BOX) 명시 + 마이그레이션 결과 리포트 출력 |
| 개봉 트랜잭션 부분 실패 → BOX만 차감되고 PCS 미생성 | High | Low | 단일 트랜잭션 + SELECT FOR UPDATE, 실패 시 전체 롤백 |
| pieces_per_box=1/NULL 상품의 BOX 운용 → 개봉해도 PCS 1개 | Medium | High | FR-11: UI 경고, 서버 보정 |
| 진행 중 출고대기(draft)에 단위 정보 없음 | Medium | Medium | draft 항목에 단위 컬럼 추가, 기존 draft는 상품 unit 따라 기본값 |
| 음수재고 정책(v16)과 단위 분리의 상호작용 | Medium | Medium | 음수재고는 요청 단위의 lot 기준으로만 발생하도록 설계 단계에서 명세 |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `lc_inbound` | DB Table | `inbound_unit`, `pieces_per_box`, `cost_price_pcs` 컬럼 추가 (additive) |
| `lc_inventory` | DB Table | `unit` 컬럼 추가 (additive, 수량 무변경) — 단, 읽는 쪽은 단위 인지 필요 |
| `lc_order_items` | DB Table | `order_unit`, `pieces_per_box` 컬럼 추가 |
| `lc_box_breaks` | DB Table (신규) | 박스 개봉/파손 이력 |
| `ajax/search_product_by_barcode.php` | API | 응답에 `pieces_per_box` 추가 (additive, 비파괴) |
| `ajax/branch_outbound.php` | API | get_product_stock/get_fefo_preview/save_draft/ship_draft에 단위 파라미터 + 단위별 분기 |
| `logistics/box_break.php` (+ ajax) | Page (신규) | 박스 개봉 페이지 |

### 6.2 Current Consumers

`lc_inventory` 수량을 읽거나 쓰는 모든 경로 (grep: quantity_remain/quantity_in/quantity_out):

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| lc_inventory | CREATE | `inbound_add.php` (INSERT quantity_in) | **Breaking** — unit 기록 추가 |
| lc_inventory | UPDATE | `inbound_edit.php` (수량 수정) | **Breaking** — unit 일관 갱신 |
| lc_inventory | READ/UPDATE | `ajax/branch_outbound.php` (FEFO 차감, 재고 조회) | **Breaking** — 단위별 분기 |
| lc_inventory | READ | `inventory.php`, `print_inventory.php`, `export_inventory.php` | Needs verification — BOX/PCS 분리 집계 표시 |
| lc_inventory | READ | `index.php` (대시보드 재고), `orders.php`, `order_new.php` | Needs verification — 단위 병기 |
| lc_inventory | READ | `ajax/get_expiry_alerts.php`, `ajax/get_available_products.php`, `ajax/product_inbound_history.php` | Needs verification — 단위 혼합 합산 주의 |
| lc_inventory | UPDATE | `ajax/distribute_to_stores.php` | Needs verification — 단위별 차감 확인 |
| lc_inbound | READ | `inbound.php`, `inbound_items.php`, `inbound_detail.php`, `print_inbound.php`, `export_inbound.php`, `lib/inbound_helper.php` | Needs verification — BOX/PCS 원가 표시 추가 |
| lc_order_items | CREATE/READ | `order_new.php`, `order_detail.php`, `print_orders.php`, `ajax/branch_outbound.php` | **Breaking** — 단위 컬럼 반영 |
| lc_order_item_lots | READ | `order_detail.php`, `print_outbound.php` | Needs verification — cost_price가 lot 단위 단가임을 명시 |

> ⚠️ 단위 혼합 합산 주의: 기존 코드의 `SUM(quantity_remain)`은 BOX와 PCS lot이 섞이면 의미가 없어짐.
> 모든 합산 지점을 단위별 GROUP BY 또는 단위 필터로 전환해야 함 — Design 단계에서 전수 명세.

### 6.3 Verification

- [ ] 위 모든 소비자가 단위 분리 후에도 올바르게 동작하는지 확인 (특히 SUM 합산 지점)
- [ ] 마이그레이션 전후 lot 수량 합계 동일 검증
- [ ] 기존 쿼리가 추가 컬럼으로 깨지지 않음 (additive 컬럼 + DEFAULT)

---

## 7. Architecture Considerations

### 7.1 Project Level Selection

| Level | Selected |
|-------|:--------:|
| **Starter** | ☐ |
| **Dynamic** | ☑ (기존 프로젝트 레벨 유지 — PHP + MariaDB 멀티페이지 앱) |
| **Enterprise** | ☐ |

### 7.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 재고 모델 | PCS 정규화 / **BOX·PCS lot 분리** | **분리 관리** | 사용자 확정. 파손 시 박스 개봉 → 낱개 전환 시나리오 지원 필수 |
| PCS 부족 시 | 자동 개봉 / 수동 개봉 / 음수재고 | **수동 개봉만** | 사용자 확정. 부족 시 안내만 표시, 실물 확인 후 개봉 |
| 개봉 기능 위치 | 재고 화면 버튼 / **별도 페이지** | **별도 페이지** (`box_break.php`) | 사용자 확정. 바코드 스캔 포함 전용 워크플로우 |
| 파손 기록 | 이력 테이블 / 수량 차감만 | **이력 테이블** (`lc_box_breaks`) | 사용자 확정. 손실 집계 가능 |
| 기존 lot 단위 | 상품 unit 라벨링 / 일괄 BOX / 일괄 PCS | **상품 unit 따라 라벨링** | 사용자 확정. 수량 무변경 — 가장 안전 |
| 스캔 단위 결정 | 토글 수동 / 바코드 자동 | **토글 수동** | 사용자 확정 (요청대로) |
| PCS 원가 | 자동 계산 / 수동 입력 | **자동 계산** | BOX원가 ÷ pieces_per_box, DECIMAL(15,4) |
| ppb 스냅샷 | 입고 시점 저장 / 마스터 JOIN | **입고 시점 스냅샷** | 마스터 ppb 변경에도 과거 기록 불변. 개봉 시에도 inbound 스냅샷 사용 |
| 환산 로직 위치 | 페이지별 / **공용 헬퍼** | `lib/unit_helper.php` (신규) | 단위 분기·환산·표기 일원화로 분기 누락 방지 |

### 7.3 Clean Architecture Approach

기존 구조 유지: `logistics/*.php` (페이지) + `logistics/ajax/*.php` (API) + `logistics/lib/*.php` (헬퍼) + `logistics/sql/` (마이그레이션).
단위 환산·표기·FEFO 단위 필터 로직은 `logistics/lib/unit_helper.php`(신규)에 집중.

---

## 8. Convention Prerequisites

### 8.1 Existing Project Conventions

- [x] `CLAUDE.md` 존재 (프로젝트 루트)
- [x] 기존 마이그레이션 컨벤션: `lc_migration_vN.sql` + `run_migration_vN.php` 쌍 (다음 번호: **v18**)
- [x] Design Ref 주석 컨벤션 (`// Design Ref: §N`)
- [ ] ESLint/Prettier — 해당 없음 (PHP 프로젝트)

### 8.2 Conventions to Define/Verify

| Category | Current State | To Define | Priority |
|----------|---------------|-----------|:--------:|
| 단위 코드 | 없음 | ENUM 값 'BOX'/'PCS' 고정 (대문자) | High |
| BOX 판정 | 없음 | 상품 unit 값 정규화: 'BOX','박스','box' → BOX 계열, 그 외 → PCS | High |
| 수량 표기 | 없음 | 재고: "N BOX + M PCS" / 단일: "N BOX", "M PCS" | Medium |
| 원가 정밀도 | DECIMAL(15,2) | PCS 원가는 DECIMAL(15,4) (나누기 오차 최소화) | Medium |

### 8.3 Environment Variables Needed

해당 없음 (DB 접속은 기존 `logistics/config/db.php` 사용).

---

## 9. Next Steps

1. [ ] 설계 문서 작성: `/pdca design box-pcs-unit`
2. [ ] 마이그레이션 v18 + lc_box_breaks 상세 설계 (검증 쿼리 포함)
3. [ ] 구현 시작: `/pdca do box-pcs-unit`

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-06-11 | Initial draft — PCS 정규화 모델 | whdans007 + Claude |
| 0.2 | 2026-06-11 | **재고 분리 모델로 전환** (BOX·PCS lot 분리, 박스 개봉/파손 기능 추가) — 사용자 결정 반영 | whdans007 + Claude |
