---
template: plan
version: 1.3
---

# branch-outbound (지점출고) Planning Document

> **Summary**: 물류센터 직원이 지점을 선택하고 바코드 스캔으로 상품을 담아 원가 그대로 즉시 출고 처리하는 신규 메뉴
>
> **Project**: Home K Mart - Logistics Center
> **Version**: 1.0
> **Author**: whdans007
> **Date**: 2026-06-10
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 현재 지점 출고는 지점이 직접 주문(order_new.php)을 넣고 물류센터가 승인/배분하는 흐름만 있어, 물류센터가 즉석에서 지점에 물건을 보낼 때 빠르게 처리할 수단이 없음 |
| **Solution** | "지점출고" 메뉴 신설 — 지점 선택 → 바코드 스캔(또는 `8/`+스캔으로 수량 8 자동입력) → 장바구니 담기 → 일괄 등록. 등록 시 FEFO로 재고 차감하고 lc_orders(status=shipped)+lc_order_items+lc_order_item_lots 생성 |
| **Function/UX Effect** | inbound_add.php와 동일한 바코드 스캔 UX 패턴을 출고에 적용, 빠른 수량 입력(`숫자/` 프리픽스)으로 박스 단위 출고 작업 효율 향상 |
| **Core Value** | 출고가 = 원가(평균원가/FEFO lot원가) 그대로 적용되어 마진 0으로 정확한 원가 출고 기록을 남기고 Outbound History/Order Detail에서 즉시 추적 가능 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 물류센터 직원이 지점에 즉시 물건을 출고할 때, 별도 주문/승인 절차 없이 원가 기준으로 빠르게 처리할 수단이 없음 |
| **WHO** | 물류센터 직원 (lc_require_staff) |
| **RISK** | 재고 초과 출고(음수 재고) 허용으로 인한 데이터 정합성 — FIFO 함수가 부족 재고를 어떻게 처리할지 명확히 설계 필요 |
| **SUCCESS** | 바코드 스캔→`8/`프리픽스 자동수량→장바구니→등록까지 전 과정이 동작하고, lc_orders/lc_order_items/lc_order_item_lots/lc_inventory가 정확히 기록되며 Outbound History에 반영됨 |
| **SCOPE** | 단일 페이지(branch_outbound.php) + ajax 처리 + 사이드바 메뉴 추가. lc_orders 등 기존 스키마 재사용, 신규 컬럼/테이블 없음 (가능하면) |

---

## 1. Overview

### 1.1 Purpose

물류센터 직원이 특정 지점으로 보낼 상품을 바코드 스캔으로 빠르게 담고, 원가 그대로 즉시 출고 처리(재고 차감 + 주문 기록 생성)하는 화면을 제공한다.

### 1.2 Background

- 기존 `order_new.php`: 지점 담당자가 주문을 "요청"하는 화면 (unit_price=0, status=pending)
- 기존 `ajax/distribute_to_stores.php`: 물류센터가 여러 지점에 배분 주문을 "생성"하지만 재고 차감(FEFO) 없이 status='approved'로만 생성됨, 단일상품 단위
- 본 기능은 위 두 흐름과 별개로, **재고 차감까지 즉시 수행되는 출고**를 다룸. `inbound_add.php`의 바코드 스캔 UX와 `lc_fifo_ship()`의 FEFO 차감 로직을 재사용

### 1.3 Related Documents

- 참고 파일: `logistics/inbound_add.php`, `logistics/lib/inventory_helper.php`, `logistics/ajax/distribute_to_stores.php`, `logistics/ajax/search_product_by_barcode.php`, `logistics/orders.php`, `logistics/outbound.php`
- 기존 설계: `docs/02-design/features/logistics-center.design.md`

---

## 2. Scope

### 2.1 In Scope

- [ ] 사이드바 메뉴 "Branch Outbound" (지점출고) 추가 — Order List 다음 위치, 물류센터 직원 전용
- [ ] `logistics/branch_outbound.php` 신규 페이지
  - 지점(store) 선택 드롭다운/검색
  - 바코드 입력창: 일반 스캔(수량 1) / `숫자/` 프리픽스 입력 후 스캔(해당 수량 자동)
  - 장바구니 테이블: 상품명, 평균원가(참고용), 수량(수정 가능), 소계, 삭제
  - "출고 등록" 버튼 → 일괄 처리
- [ ] 신규 ajax 엔드포인트(또는 기존 페이지 POST): 등록 시
  - 트랜잭션 내에서 상품별 FEFO 차감 (`lc_fifo_ship` 활용 또는 음수 재고 허용 변형)
  - `lc_orders` (store_id, status='shipped', shipped_at=NOW(), order_date=오늘) 1건 생성
  - `lc_order_items` (product_id, quantity, unit_price=가중평균 lot원가) 생성
  - `lc_order_item_lots` (lot별 차감 내역 + cost_price) 생성
  - `lc_inventory.quantity_out` 갱신 (재고 부족 시에도 차감 허용 → quantity_remain 음수 가능)
- [ ] 평균원가 미리보기용 ajax (기존 `distribute_to_stores.php`의 `get_product_stock` 재사용 또는 신규 동일 로직)
- [ ] 등록 완료 후 Order Detail 또는 Outbound History로 이동, 성공 플래시 메시지

### 2.2 Out of Scope

- 지점 주문 승인/배달 워크플로우 변경 (order_new.php, orders.php 기존 흐름 유지)
- `distribute_to_stores.php` 다지점 배분 로직 변경
- 인쇄/영수증 출력 (필요 시 추후 print_outbound.php 확장)
- 재고 부족 알림/리포트 기능

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 사이드바에 "Branch Outbound" 메뉴를 Order List 다음에 추가 (물류센터 직원만 표시) | High | Pending |
| FR-02 | 지점 선택 UI 제공 (stores 테이블 조회) | High | Pending |
| FR-03 | 바코드 입력 시 `search_product_by_barcode.php`로 상품 검색, 단일/복수 결과 처리 (inbound_add.php와 동일 패턴) | High | Pending |
| FR-04 | 입력창에 `숫자/` (예: `8/`)를 입력 후 바코드를 스캔하면 해당 상품이 수량 8로 장바구니에 추가됨. `/` 없이 스캔 시 수량 1 | High | Pending |
| FR-05 | 장바구니 각 행은 상품명, 평균원가(참고), 수량(수정 가능), 소계를 표시하고 삭제 가능 | Medium | Pending |
| FR-06 | "출고 등록" 클릭 시 트랜잭션으로 모든 장바구니 항목을 처리: lc_orders(status='shipped') 1건 + 항목별 lc_order_items + lc_order_item_lots 생성 | High | Pending |
| FR-07 | 출고 단가(unit_price)는 FEFO로 차감된 lot들의 cost_price 가중평균값 (마진 0, 원가 그대로) | High | Pending |
| FR-08 | 재고가 부족해도 출고를 차단하지 않고 진행 (quantity_remain 음수 허용) — 단, 마지막 lot 기준으로 부족분 처리 | Medium | Pending |
| FR-09 | 등록 완료 시 성공 메시지와 함께 Order Detail(`order_detail.php?id=...`)로 이동 | Medium | Pending |
| FR-10 | 물류센터 직원(lc_require_staff)만 접근 가능 | High | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Consistency | 기존 inbound_add.php의 바코드 스캔 UI/JS 패턴과 톤 유지 | 코드 리뷰 |
| Data Integrity | 출고 등록은 단일 DB 트랜잭션으로 처리 (autocommit false + commit/rollback) | 코드 리뷰 |
| Security | CSRF 토큰 검증, lc_require_staff 권한 체크 | 코드 리뷰 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] 사이드바에 메뉴가 표시되고 클릭 시 페이지 이동
- [ ] 지점 선택, 바코드 스캔(일반/8프리픽스), 장바구니 담기/삭제/수량수정 동작
- [ ] 등록 시 lc_orders/lc_order_items/lc_order_item_lots/lc_inventory가 올바르게 갱신됨 (DB 확인)
- [ ] Outbound History 및 Order Detail에서 방금 등록한 출고 내역 확인 가능
- [ ] 재고 부족 상품도 정상 등록되고 quantity_remain이 음수가 됨을 확인

### 4.2 Quality Criteria

- [ ] PHP 문법 오류 없음 (php -l)
- [ ] 기존 페이지(orders.php, outbound.php, order_detail.php)에 회귀 없음

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| `lc_fifo_ship`이 재고 부족 시 false 반환 → 음수 재고 허용 요구사항과 충돌 | High | High | Design 단계에서 음수 재고를 허용하는 변형 함수(예: `lc_fifo_ship_allow_negative`) 작성 — 부족분은 마지막(최신) lot에서 음수로 차감 |
| 가중평균 unit_price 계산 시 부동소수점 오차로 lc_order_items.total_amount(quantity*unit_price)와 lc_order_item_lots 합계가 미세하게 불일치 | Low | Medium | DECIMAL 반올림(round 2~4자리) 통일, 차이는 허용 오차로 문서화 |
| `8/` 프리픽스 입력 UX가 기존 IME/숫자 자동검색(8자리 이상 바코드)과 충돌 | Medium | Medium | `/` 포함 여부로 먼저 분기 처리, `/` 있으면 프리픽스 모드로만 동작하도록 입력 파싱 로직 분리 |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `logistics/partials/header.php` | UI | "Branch Outbound" 메뉴 항목 추가 (데스크톱 + 모바일 사이드바) |
| `logistics/branch_outbound.php` | New Page | 신규 페이지 |
| `logistics/ajax/branch_outbound_*.php` (또는 기존 ajax 확장) | New/Modified | 점포목록/원가조회/출고등록 액션 |
| `logistics/lib/inventory_helper.php` | Code | 음수 재고 허용 FEFO 변형 함수 추가 (기존 함수는 변경하지 않음) |
| `lc_inventory`, `lc_orders`, `lc_order_items`, `lc_order_item_lots` | DB Data | 출고 등록 시 INSERT/UPDATE (스키마 변경 없음) |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `lc_inventory.quantity_out` | UPDATE | `lc_fifo_deduct`, `lc_fifo_ship` (order_new 승인 흐름 등) | None — 신규 함수 별도 추가, 기존 함수 미변경 |
| `lc_orders` / `lc_order_items` | READ | `orders.php`, `order_detail.php`, `outbound.php`, `print_*.php` | None — 동일 스키마/상태값(`shipped`) 사용하므로 기존 조회 로직 그대로 호환 |
| `lc_order_item_lots` | READ | `outbound.php` (유통기한 표시), `order_detail.php` | None — 동일 포맷으로 INSERT |
| `partials/header.php` 메뉴 구조 | READ | 모든 페이지 (header include) | None — 항목 추가만, 기존 항목 영향 없음 |

### 6.3 Verification

- [ ] lc_orders.status='shipped' 데이터가 outbound.php / order_detail.php에서 정상 표시되는지 확인
- [ ] lc_order_item_lots 음수 quantity_remain 케이스에서 outbound.php 유통기한 표시 쿼리가 오류 없이 동작하는지 확인
- [ ] 사이드바 메뉴 추가가 기존 active 상태(`$_lc_page` 비교) 로직과 충돌하지 않는지 확인

---

## 7. Architecture Considerations

### 7.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure | Static sites | ☐ |
| **Dynamic** | Feature-based modules | Web apps with backend | ☑ |
| **Enterprise** | Strict layer separation | High-traffic systems | ☐ |

> 본 프로젝트는 PHP + MySQLi 기반 기존 logistics 모듈 구조를 그대로 따르는 Dynamic 수준의 단일 기능 추가.

### 7.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| Framework | 기존 PHP 절차형 + partials | 기존 패턴 유지 | 프로젝트 전체가 PHP/mysqli 절차형, 일관성 유지 |
| Barcode Scan UI | inbound_add.php 패턴 재사용 / 신규 작성 | inbound_add.php 패턴 재사용·축소 | 검증된 UX, 개발 속도 |
| FEFO 로직 | `lc_fifo_ship` 그대로 / 신규 변형 함수 | 신규 변형 함수 추가 | 음수 재고 허용 요구사항을 기존 함수 계약 변경 없이 충족 |
| 출고 등록 처리 위치 | 페이지 POST / 신규 ajax | 신규 ajax (`ajax/branch_outbound.php`) | 다른 ajax 파일들과 일관된 action 기반 패턴(`distribute_to_stores.php` 참고) |

### 7.3 Clean Architecture Approach

```
Selected Level: Dynamic (기존 PHP 구조 준수)

logistics/
├── branch_outbound.php          (신규: 페이지 - 지점선택 + 바코드 스캔 + 장바구니)
├── ajax/
│   └── branch_outbound.php      (신규: get_stores, get_product_stock, submit 액션)
├── lib/
│   └── inventory_helper.php     (수정: lc_fifo_ship_allow_negative 추가)
└── partials/
    └── header.php                (수정: 메뉴 항목 추가)
```

---

## 8. Convention Prerequisites

### 8.1 Existing Project Conventions

- [x] 기존 PHP 페이지 구조 (`partials/header.php` include, `lc_require_staff()`, CSRF 토큰)
- [x] Tailwind CSS (teal 테마) 일관 적용
- [x] ajax 파일은 `action` 파라미터 기반 분기 (`ajax/distribute_to_stores.php` 패턴)

### 8.2 Conventions to Define/Verify

| Category | Current State | To Define | Priority |
|----------|---------------|-----------|:--------:|
| **Naming** | exists | `branch_outbound.php`, `ajax/branch_outbound.php` 명명 | High |
| **Folder structure** | exists | 기존 `logistics/` 평면 구조 그대로 | High |
| **수량 프리픽스 파싱** | missing | `숫자/` 입력 파싱 규칙 정의 (Design에서 상세화) | Medium |

### 8.3 Environment Variables Needed

- 없음 (기존 `config/db.php` DB 연결 재사용)

---

## 9. Next Steps

1. [ ] 설계 문서 작성 (`branch-outbound.design.md`) — 음수 재고 허용 FEFO 함수, 가중평균 unit_price 계산식, `숫자/` 파싱 로직 상세 설계
2. [ ] 사용자 검토
3. [ ] 구현 시작

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-06-10 | Initial draft | whdans007 |
