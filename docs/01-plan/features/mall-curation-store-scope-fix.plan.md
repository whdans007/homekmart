---
template: plan
version: 1.3
---

# mall-curation-store-scope-fix Planning Document

> **Summary**: Reference Store를 바꿔도 몰 큐레이션 상품 목록은 그대로 유지되고, 가격만 선택한 점포 기준으로 보이도록 관리자 화면 조회 로직을 수정한다.
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: Claude Code (사용자 확인 하에 직접 구현)
> **Date**: 2026-09-24
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | `mall/admin/products.php`의 큐레이션 조회가 `mall_products.store_id = 선택된 점포`로 필터링되어 있어, Reference Store를 바꾸면 기존에 큐레이션해둔 상품들이 화면에서 통째로 사라진다. |
| **Solution** | 큐레이션(무엇을 몰에 노출할지) 관련 조회는 `product_id` 기준 전역으로 바꾸고, 가격/재고(`inventory`, POS 판매량) 조회만 계속 Reference Store 기준을 따르게 분리한다. |
| **Function/UX Effect** | 관리자가 Reference Store를 어떤 점포로 바꾸든 큐레이션 목록·카테고리 개수·홈 노출(오늘의특가/기획전/새상품) 목록은 그대로 유지되고, 화면에 표시되는 원가/판매가만 선택한 점포 값으로 바뀐다. |
| **Core Value** | 사용자가 명시한 2가지 요구사항(① 점포가 바뀌어도 큐레이션 상품은 그대로 다 보인다 ② 가격은 바뀐 점포 기준으로 보인다)을 정확히 충족한다. |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | Reference Store를 선셋점(store_id=9)으로 바꿔 저장했더니 기존 큐레이션 상품이 전부 사라짐 — `mall_products.store_id` 필터가 원인 |
| **WHO** | 몰 관리자(HOME K MART 운영자) — `mall/admin/products.php` 사용자 |
| **RISK** | 가격 override 저장 비교 기준이 화면에 보여준 점포와 어긋나면 의도치 않은 override가 저장될 수 있음 (아래 5.2) |
| **SUCCESS** | Reference Store를 어떤 값으로 바꿔도 기존 큐레이션 200건이 화면에 그대로 보이고, 가격만 바뀐 점포의 `inventory` 값으로 표시됨 |
| **SCOPE** | `mall/admin/products.php` 조회 쿼리 + `mall/admin/ajax/save_retail_product.php`의 add/update 액션만 수정. 실제 고객용 몰(`catalog.php` 등)과 `mall_products.store_id` 컬럼 자체는 이번 범위에서 제외 |

---

## 1. Overview

### 1.1 Purpose

Reference Store(몰 가격/재고/품절 자동화 기준 점포)를 변경해도 이미 큐레이션된 상품 목록이 사라지지 않고, 그 상품들의 표시 가격만 새로 선택한 점포의 `inventory` 값을 따라가도록 관리자 화면 조회 로직을 고친다.

### 1.2 Background

- 사용자가 2026-09-24 운영 서버(homekmart.net)에서 Reference Store를 선셋점(store_id=9)으로 바꿔 저장한 뒤 기존 큐레이션 상품들이 전부 안 보이는 것을 발견 (아직 그 상태에서 신규 상품 등록은 하지 않음 — 확인 완료).
- 원인 조사 결과 `mall_products.product_id`에 UNIQUE 제약이 있어 애초에 상품별로 점포마다 다른 큐레이션 행이 존재할 수 없는 구조인데(=이미 몰 전체 단일 카탈로그), 조회 쿼리만 `mp.store_id = 선택된 점포`로 필터링해서 생긴 화면 버그. 데이터 삭제는 아님.
- Codex(OpenAI)와 사전 상의하여 원인·영향 범위를 교차 검증함 (아래 6절 참고).

### 1.3 Related Documents

- `docs/02-design/features/unified-inventory-reference-store.design.md` — Reference Store 개념 원설계 문서(§2.1, §5: Reference Store는 "몰 가격/재고/품절 자동화 기준"으로만 규정)
- `lib/reference_store_service.php` — Reference Store 현재값 조회/변경 서비스 (변경 없음, 원인 아님을 확인함)

---

## 2. Scope

### 2.1 In Scope

- [ ] `mall/admin/products.php` — 카테고리별 큐레이션 개수(`cat_count_stmt`), 소분류 개수(`all_sub_stmt`), 일반 큐레이션 목록(`$curated_where`/`$count_stmt`/`$curated_stmt`), 홈 노출 슬롯 큐레이션 목록(`elseif ($selected_home_slot)` 분기)에서 `mp.store_id = ?` 필터 제거 — 큐레이션은 `product_id` 기준 전역으로 조회
- [ ] 위 쿼리들에서 가격/재고 표시용 `inventory` LEFT JOIN은 계속 `$selected_store_id`(Reference Store)를 사용하도록 유지
- [ ] `mall/admin/ajax/save_retail_product.php` `add` 액션 — 신규 큐레이션 등록 시 `mall_products.store_id`를 화면에서 넘어온 값이 아니라 항상 `MALL_STORE_ID`로 고정 저장 (사용자 확인 완료)
- [ ] `mall/admin/ajax/save_retail_product.php` `update` 액션 — 가격 override 비교 시 `mall_products.store_id`(항상 저장된 값)가 아니라, 화면에 실제 표시된 Reference Store id를 명시적으로 전달받아 그 점포의 `inventory` 가격과 비교하도록 수정 (현재는 `$mp_row['store_id']`를 써서 화면에 보여준 점포와 저장 시 비교 기준 점포가 어긋날 수 있음)

### 2.2 Out of Scope

- 실제 고객용 쇼핑몰(`mall/lib/catalog.php`, `mall/product.php`, `mall/lib/pricing.php`, `mall/lib/home_layout.php`)을 Reference Store와 연동하는 작업 — 현재 이 파일들은 Reference Store를 전혀 읽지 않고 `MALL_STORE_ID`(=1) 하드코딩만 사용하며, 이번 버그와 무관하게 동작하고 있음을 확인함. 더 큰 별도 작업으로 분리.
- `mall/admin/ajax/toggle_home_section_product.php`, `save_today_deal_promo.php`, `delete_product_image.php`, `reorder_product_images.php` — 확인 결과 이미 `MALL_STORE_ID` 하드코딩만 사용하고 Reference Store를 읽지 않아 이번 버그의 영향을 받지 않음. 수정 불필요.
- `mall_products.store_id` 컬럼/FK/인덱스 제거 — 스키마 변경은 이번 핫픽스와 분리된 후속 마이그레이션으로 남겨둠 (당장 제거해도 이득이 없고 회귀 위험만 커짐).

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | Reference Store를 변경해도 기존에 큐레이션된 일반상품·카테고리 개수·홈 노출 슬롯 목록이 그대로 보여야 한다 | High | Pending |
| FR-02 | 큐레이션 상품의 원가/판매가 표시는 현재 선택된(또는 임시 조회 중인) 점포의 `inventory` 값을 따라야 한다 | High | Pending |
| FR-03 | 신규 큐레이션 등록 시 `mall_products.store_id`는 항상 `MALL_STORE_ID`로 저장되어야 한다(화면에 임시로 다른 점포를 보고 있어도) | High | Pending |
| FR-04 | 가격 override 저장 시 비교 기준은 화면에 실제 표시된 점포의 `inventory` 가격이어야 한다(저장된 `mall_products.store_id`가 아니라) | High | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| 데이터 정합성 | 기존 200건의 큐레이션 데이터는 수정 없이 그대로 유지(마이그레이션 없음) | 수정 전/후 `SELECT COUNT(*) FROM mall_products` 비교 |
| 회귀 방지 | 큐레이션 추가/수정/삭제, 홈 노출 등록, 카테고리 이동 등 기존 기능이 깨지지 않아야 함 | 로컬에서 수동 시나리오 테스트 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] Reference Store를 store A → store B로 바꿔도 큐레이션 목록/카테고리 개수/홈 노출 슬롯 목록 건수가 동일함을 로컬에서 확인
- [ ] 큐레이션 목록에 표시되는 원가/판매가가 store B의 `inventory` 값으로 바뀜을 확인
- [ ] 신규 상품 큐레이션 등록 후 DB에서 `mall_products.store_id = MALL_STORE_ID`(1)임을 확인
- [ ] 임시 조회 점포(store B)에서 가격을 편집해 저장 → `mall_products`에 store B 기준으로 override가 정확히 계산되어 저장됨을 확인
- [ ] `php -l`로 수정 파일 문법 검증 통과
- [ ] `git status`로 계획서에 명시한 파일만 변경되었는지 확인

### 4.2 Quality Criteria

- [ ] 기존 코드 스타일(한글 주석, prepared statement, 네이밍) 유지
- [ ] 불필요한 리팩토링 없이 최소 변경으로 처리

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 카운트 쿼리(`cat_count_stmt`/`all_sub_stmt`/`count_stmt`)의 bind_param 파라미터 순서·타입 문자열을 잘못 조정하면 SQL 바인딩 오류 발생 | High | Medium | 각 쿼리 수정 후 `php -l` + 로컬에서 실제 페이지 로드해 카운트 숫자 확인 |
| `update` 액션에 Reference Store id를 새로 넘기도록 JS를 바꾸는데, 기존 다른 곳에서도 같은 JS 변수/hidden input을 참조하면 충돌 가능 | Medium | Low | `window.MALL_CSRF_TOKEN`과 같은 패턴으로 별도 전역 변수 추가, 기존 변수 재사용하지 않음 |
| 운영 DB에는 아직 반영 전이라 로컬 검증만으로는 운영 데이터(`store_id` 분포)를 100% 보장 못함 | Low | Low | 배포 전 운영 DB에 대해 읽기 전용으로 `SELECT store_id, COUNT(*) FROM mall_products GROUP BY store_id` 재확인 (이미 사용자 확인상 이상 없음) |

---

## 6. Impact Analysis

> Codex(OpenAI)와 사전 상의하여 아래 영향 범위를 교차 검증함.

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `mall/admin/products.php` | Query | `cat_count_stmt`, `all_sub_stmt`, 일반 큐레이션 쿼리, 홈 슬롯 큐레이션 쿼리에서 `mp.store_id = ?` 필터 제거 |
| `mall/admin/ajax/save_retail_product.php` (`add`) | Logic | `store_id`를 `$_POST` 값 대신 항상 `MALL_STORE_ID`로 저장 |
| `mall/admin/ajax/save_retail_product.php` (`update`) | Logic | 가격 override 비교 기준을 `$mp_row['store_id']` 대신 명시적으로 전달받은 Reference Store id로 변경 |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `mall_products` 큐레이션 존재 여부 | READ | `mall/admin/products.php` (카테고리 카운트, 큐레이션 목록, 홈 슬롯 목록) | 수정 대상 — store_id 필터 제거로 전역 조회 |
| `mall_products` 큐레이션 존재 여부 | READ | `mall/admin/ajax/toggle_home_section_product.php`, `save_today_deal_promo.php`, `delete_product_image.php`, `reorder_product_images.php` | 영향 없음 — 이미 `MALL_STORE_ID` 하드코딩(변경사항과 무관) |
| `mall_products` 큐레이션 존재 여부 | READ | `mall/lib/catalog.php`, `mall/product.php`, `mall/product_reviews.php`, `mall/lib/pricing.php`, `mall/lib/home_layout.php` (실제 몰) | 영향 없음 — 이미 `MALL_STORE_ID` 하드코딩, Reference Store 자체를 안 읽음. 이번 수정과 무관하게 그대로 동작 |
| `mall_products` | CREATE | `mall/admin/ajax/save_retail_product.php` (`add`) | 수정 대상 — store_id 저장값을 MALL_STORE_ID로 고정 |
| `mall_products` | UPDATE | `mall/admin/ajax/save_retail_product.php` (`update`) | 수정 대상 — 가격 비교 기준 파라미터 추가 |
| `mall_products` | DELETE | `mall/admin/ajax/save_retail_product.php` (`delete`) | 영향 없음 — `product_id` 무관하게 `mall_product_id`로만 삭제 |
| `mall_reference_store_history`/`system_settings` | READ/WRITE | `lib/reference_store_service.php`, `mall/admin/ajax/save_reference_store.php` | 영향 없음 — 확인 완료(이번 버그의 원인이 아니었음) |

### 6.3 Verification

- [ ] 위 표의 "영향 없음" 항목들이 실제로 수정 후에도 동작에 변화가 없는지 로컬에서 재확인
- [ ] `mall_products.store_id`가 항상 1로 고정되는 전제가 다른 코드에서 깨지지 않는지 재검색 확인
- [ ] 기존 큐레이션 200건(store_id=1) 데이터에 대해 수정 후에도 카운트가 동일한지 확인

---

## 7. Architecture Considerations

> 본 프로젝트는 PHP 모놀리식 구조(Next.js/React 프레임워크 아님)로, 템플릿의 프레임워크 선택 섹션은 해당 없음(N/A).

### 7.1 Project Level Selection

해당 없음 — 기존 PHP 관리자 화면(`admin/`, `mall/admin/`) 구조를 그대로 따름.

### 7.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 큐레이션 스코프 모델 | 점포별 개별 큐레이션 / 전역 단일 큐레이션 | 전역 단일 큐레이션 | `mall_products.product_id` UNIQUE 제약상 이미 전역 모델이 실제 데이터 구조임 — 조회만 이에 맞춤 |
| 신규 큐레이션 store_id 기본값 | 화면에 선택된 Reference Store / 고정 `MALL_STORE_ID` | 고정 `MALL_STORE_ID` | 사용자 확인 완료 — 실제 몰이 `MALL_STORE_ID` 하드코딩을 쓰므로 이 값과 항상 일치시켜야 신규 상품이 실제 몰에도 노출됨 |
| `store_id` 컬럼 처리 | 즉시 제거 / 당분간 유지 | 당분간 유지 | 스키마 변경은 별도 후속 작업(Codex 권고), 이번 핫픽스는 동작 교정만 |

### 7.3 Clean Architecture Approach

해당 없음 — 기존 파일 내 쿼리/로직 최소 수정.

---

## 8. Convention Prerequisites

### 8.1 Existing Project Conventions

- [x] `CLAUDE.md`에 코딩 컨벤션 명시됨 (prepared statement, 한글 UI/주석, utf8mb4 등)
- [ ] 그 외 항목 해당 없음 (Next.js/TS 프로젝트 아님)

### 8.2~8.4

해당 없음 (Next.js/BaaS 전제 섹션 — 이 프로젝트는 순수 PHP/MySQL).

---

## 9. Next Steps

1. [x] Codex와 원인·영향 범위 상의 완료
2. [x] 사용자와 요구사항 확정 (2가지 핵심 요구사항 + 신규 등록 store_id 고정값 확인)
3. [x] Claude Code가 직접 구현 (`mall/admin/products.php`, `mall/admin/ajax/save_retail_product.php`)
4. [x] 로컬에서 시나리오 테스트 — store_id 1/6/9로 전환하며 확인: 큐레이션 200건 그대로 유지, 표시 가격만 점포별로 다르게 조회됨
5. [x] `php -l` 통과 확인, `git status`로 의도한 두 파일만 변경됐음을 확인
6. [ ] 운영 서버 배포 및 실제 화면에서 최종 확인 (사용자 승인 후 진행)

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-24 | Initial draft | Claude Code |
