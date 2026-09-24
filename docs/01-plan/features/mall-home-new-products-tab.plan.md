---
template: plan
version: 1.3
---

# mall-home-new-products-tab Planning Document

> **Summary**: `mall/admin/products.php`의 홈 노출(오늘의특가/기획전상품/새상품) 진열 추가 패널에 "신상품" 탭을 추가해, `admin/new_products_management.php`와 동일하게 최근 7일 이내 등록된 상품을 검색 없이 바로 목록으로 보여주고 진열에 추가할 수 있게 한다
>
> **Project**: HOME K MART 관리 프로그램
> **Version**: 1.1.0
> **Author**: whdans007
> **Date**: 2026-09-24
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | `mall/admin/products.php`에서 홈 노출 슬롯(오늘의특가/기획전/새상품)에 상품을 추가하려면 상품명을 직접 검색해야 한다. 특히 "새상품" 슬롯에 최근 등록한 신상품을 넣으려면 관리자가 상품명을 정확히 기억해서 검색해야 해서 번거롭다 |
| **Solution** | 홈 노출 추가 패널(General/Fresh 탭 옆)에 "신상품" 탭을 추가한다. `admin/new_products_management.php`와 동일한 기준(최근 7일 이내 등록)으로 상품을 자동 조회해 검색 없이 목록으로 보여주고, 기존 General 탭과 동일한 "쇼핑몰에 추가"/"이동 등록"/"이미 추가됨" 버튼으로 바로 진열에 추가할 수 있게 한다 |
| **Function/UX Effect** | 관리자가 홈 노출 화면에서 "신상품" 탭만 누르면 최근 등록 상품이 바로 나열되어, 검색어를 몰라도 즉시 진열에 추가할 수 있다 |
| **Core Value** | 신상품 등록 → 홈 노출 등록까지의 절차가 짧아져 "최근 등록 상품이 왜 홈에 안 보이냐"는 혼란과 수작업 검색 부담이 줄어든다 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 홈 노출 슬롯에 상품을 추가하려면 매번 이름/SKU를 검색해야 해서, 최근 등록한 신상품을 빠르게 진열하기 번거로움 |
| **WHO** | `mall/admin/products.php`에서 홈 노출을 관리하는 관리자(`mall_management` 권한 보유자) |
| **RISK** | "최근 7일" 기준이 `new_products_management.php`와 어긋나면 "신상품 관리 화면에는 있는데 여기엔 없다"는 혼란이 생길 수 있음 → 동일한 SQL 조건(`DATE(p.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)`)을 그대로 재사용해 방지 |
| **SUCCESS** | 오늘의특가/기획전상품/새상품 중 아무 슬롯이나 선택 후 "신상품" 탭 클릭 시 최근 7일 이내 등록 상품이 검색 없이 나열되고, 기존과 동일한 버튼으로 진열에 추가/이미 추가됨 표시가 정상 동작 |
| **SCOPE** | v1: `mall/admin/products.php`에 탭 1개 + 조회 쿼리 1개 + 결과 테이블 1개 추가. 신규 테이블/컬럼/엔드포인트 없음. 신선상품은 대상 아님(신상품 관리 화면 자체가 일반상품만 다룸) |

---

## Codex 사전 상의 요약

`codex exec --sandbox read-only`로 설계안을 1회 검토받았다. 큰 방향은 그대로 채택하되 다음을 반영했다.

| Codex가 지적한 점 | 반영 내용 |
|---|---|
| `$search_tab`에 `'new'`를 전역으로 허용하면, 홈 노출 모드가 아닌 카테고리 브라우징 모드(943~987번째 줄)로 `search_tab=new`가 새어 들어갈 때 탭 UI(General/Fresh 둘 다 비활성 표시)와 실제 동작(General로 처리)이 모순되는 상태가 됨 | `$selected_home_slot` 여부에 따라 허용값을 다르게 정규화: 홈 노출 모드면 `general`/`fresh`/`new`, 아니면 `general`/`fresh`만 허용하고 그 외 값(`new` 포함)은 `general`로 되돌림 |
| `created_at`이 같은 상품이 여러 개면 정렬이 불안정할 수 있음 | `ORDER BY p.created_at DESC, p.id DESC`로 2차 정렬 키 추가 |
| 신상품 탭은 검색어 개념이 없는데 기존 검색 폼이 그대로 보이면 혼란 | `$search_tab === 'new'`일 때는 검색 폼(입력창+검색 버튼) 자체를 숨김 |
| 결과 테이블을 General 탭과 공용 partial로 만들면, General 탭에만 있는 `recent_sales_qty` 컬럼 때문에 오히려 리팩터링 범위가 커짐 | 계획대로 복제(§7.2) 유지 — 액션 셀 조건(`이미 추가됨`/`curate-home-slot-product-btn`/`add-to-home-slot-btn`)만 동일하게 맞추고 등록일 컬럼만 다르게 표시 |
| `MALL_STORE_ID` 사용이 기존 General 탭 큐레이션 판단과 일치하는지 확인 요청 | 확인됨 — 기존 코드도 `mp.store_id = MALL_STORE_ID`로 큐레이션 여부를 판단하므로 동일하게 사용 |
| `DATE(p.created_at) >= ...` 조건은 인덱스를 못 타 5만 행 스캔이 발생할 수 있음(현재 `created_at` 인덱스 없음) | v1에서는 `new_products_management.php`와 완전히 동일한 조건식을 유지해 "신상품" 정의 불일치를 우선 방지. 성능은 이번 UI 추가의 필수 조건이 아니라고 판단해 인덱스 추가는 범위에서 제외하고, 필요 시 별도 DB 최적화 작업으로 분리 |

---

## 1. Overview

### 1.1 Purpose

`mall/admin/products.php`의 홈 노출 추가 패널(General Products / Fresh Products 탭)에 "New Products"(신상품) 탭을 추가해, 최근 등록한 일반상품을 검색 없이 목록으로 보여주고 기존 진열 추가 버튼으로 바로 추가할 수 있게 한다.

### 1.2 Background

- `mall/admin/products.php`는 `$selected_home_slot`이 설정되면(홈 노출 가상 카테고리 선택) 우측에 "Add to New (all products)" 패널을 보여주며, 현재 General Products / Fresh Products 두 탭만 있다(`products.php:865-942`).
- General 탭은 `$search !== ''`일 때만 `$home_slot_search_results` 쿼리를 실행해 검색 결과를 보여준다(`products.php:186-205`). 검색어가 없으면 안내 문구도 없이 아무것도 안 보인다(`897번째 줄`의 `if ($search_tab === 'general' && $search !== '')` 조건).
- `admin/new_products_management.php`는 `products` 테이블에서 `DATE(p.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)` 조건으로 "신상품"을 정의한다(`new_products_management.php:54`). 이번 기능은 이 기준을 그대로 재사용해 두 화면의 "신상품" 정의가 어긋나지 않게 한다.
- General 탭 결과 행은 이미 "쇼핑몰에 추가"(미큐레이션 상품, `curate-home-slot-product-btn`)/"이동 등록"(이미 큐레이션된 상품, `add-to-home-slot-btn`)/"이미 추가됨" 세 가지 상태를 처리하는 JS 핸들러가 갖춰져 있다(`products.php:914-921`, 그리고 관련 JS는 이 파일 하단 `<script>`에 있음 — 이번 기능은 이 로직을 그대로 재사용하고 신규 JS 핸들러를 만들지 않는다).

### 1.3 Related Documents

- 참고 화면: `admin/new_products_management.php` (신상품 정의 기준)
- 관련 최근 계획: `docs/01-plan/features/mall-home-product-instant-apply.plan.md` (같은 화면의 홈 노출 발행 로직 변경, 이번 기능과 독립적)

---

## 2. Scope

### 2.1 In Scope

- [ ] `$search_tab` 허용값을 컨텍스트별로 정규화: `$selected_home_slot`이 있으면 `general`/`fresh`/`new`, 없으면 `general`/`fresh`만 허용하고 그 외 값은 `general`로 되돌림
- [ ] `$selected_home_slot`이 설정된 경우에만 탭 바에 "신상품" 탭 추가(General/Fresh 옆), 오늘의특가/기획전상품/새상품 세 슬롯 모두 동일하게 노출
- [ ] `$search_tab === 'new'`일 때 검색어와 무관하게 최근 7일 이내 등록된 일반상품 조회(`new_products_management.php`와 동일한 `DATE(p.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)` 조건, `p.is_active = 1`), `ORDER BY p.created_at DESC, p.id DESC`, 최대 50개
- [ ] 조회 결과를 General 탭과 동일한 컬럼 구조(`product_id`, `name_ko`, `name_en`, `sku`, `mall_product_id`, `display_name`)로 맞춰, 동일한 액션 셀 조건("쇼핑몰에 추가"/"이동 등록"/"이미 추가됨")과 기존 JS를 그대로 재사용. 두 번째 컬럼은 General 탭의 "최근 판매량" 대신 "등록일"로 표시
- [ ] "신상품" 탭에서는 검색 폼(입력창+검색 버튼)을 숨김(목록 자체가 필터 없는 최근 등록 목록)

### 2.2 Out of Scope (v1)

- 신선상품(`mall_fresh_products`)의 "최근 등록" 목록 — `new_products_management.php` 자체가 일반상품(`products`)만 다루므로 이번 기능도 동일 범위로 한정
- "신상품" 탭 내 검색/필터 기능
- `admin/new_products_management.php` 화면 자체의 변경
- 신규 DB 컬럼/테이블/엔드포인트 추가

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 홈 노출 슬롯(오늘의특가/기획전상품/새상품) 선택 시 "신상품" 탭이 General/Fresh 옆에 표시된다 | High | Pending |
| FR-02 | "신상품" 탭 클릭 시 검색어 없이 최근 7일 이내 등록된 활성 일반상품이 등록일 내림차순으로 나열된다 | High | Pending |
| FR-03 | 목록의 각 상품은 기존 General 탭과 동일하게 "쇼핑몰에 추가"/"이동 등록"/"이미 추가됨" 상태로 표시되고 클릭 시 동일하게 동작한다 | High | Pending |
| FR-04 | 최근 7일 이내 등록된 상품이 없으면 안내 문구가 표시된다("결과 없음"류 기존 문구 재사용) | Medium | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| 일관성 | "신상품" 기준(7일)이 `new_products_management.php`와 동일한 SQL 조건 사용 | 코드 리뷰로 조건문 대조 |
| 회귀 방지 | General/Fresh 탭 기존 동작(검색, 버튼)에 변화 없음 | 코드 리뷰 + 수동 테스트 |
| 성능 | LIMIT 50으로 과도한 조회 방지 | 코드 리뷰 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] "신상품" 탭 UI 및 쿼리 구현
- [ ] 기존 진열 추가 버튼/JS 재사용 확인(신규 JS 없음)
- [ ] `php -l` 린트 통과
- [ ] 코드 리뷰 완료(Claude Code)
- [ ] 테스트서버에서 수동 확인: 오늘의특가/기획전상품/새상품 각각에서 "신상품" 탭 → 최근 등록 상품 노출 → 추가 버튼 정상 동작 → 이미 추가된 상품은 "이미 추가됨" 표시

### 4.2 Quality Criteria

- [ ] 신규 스키마/엔드포인트 없이 기존 구조로 구현됨
- [ ] General/Fresh 탭 회귀 없음

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| "신상품" 정의(7일)가 두 화면에서 어긋나 혼란 발생 | Medium | Low | 동일 SQL 조건 재사용으로 원천 차단 |
| 결과가 너무 많아 패널이 길어짐 | Low | Low | LIMIT 50 + 등록일 내림차순으로 최신순 우선 노출 |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `mall/admin/products.php` | PHP 템플릿 | `$search_tab`에 `'new'` 추가, 홈 노출 패널에 "신상품" 탭 링크·최근 등록 상품 조회 쿼리·결과 테이블 추가(기존 General 탭 버튼/JS 재사용) |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `$search_tab` (기존 'general'/'fresh') | READ | `products.php` 전역(카테고리 모드 탭, 신선상품 조회 분기 등) | 'new' 값 추가는 기존 `=== 'fresh'`/`=== 'general'` 비교 조건에 영향 없음(신규 값은 새 분기에서만 사용) |
| `curate-home-slot-product-btn`/`add-to-home-slot-btn` 클릭 핸들러(JS) | - | `products.php` 하단 `<script>` | 변경 없음, data 속성만 동일하게 채워 재사용 |

### 6.3 Verification

- [ ] General/Fresh 탭 기존 검색 동작 회귀 없음 확인
- [ ] "신상품" 탭에서 방금 등록한 상품이 바로 나타나는지 확인
- [ ] 8일 이상 지난 상품은 "신상품" 탭에 안 나타나는지 확인

---

## 7. Architecture Considerations

> PHP 서버 렌더링 + MySQLi 구조, Next.js 템플릿 항목(7.1) 해당 없음(N/A).

### 7.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 결과 테이블 마크업 | 공용 partial로 추출 vs 기존 General 탭 블록을 그대로 복제 | 복제(동일 구조, 데이터 소스만 다름) | 범위가 작고 위험을 최소화하려면 기존 코드 흐름을 건드리지 않는 것이 안전(계획 §2.2 out-of-scope: 리팩터링 없음) |
| "신상품" 판별 기준 | 자체 기준 재정의 vs `new_products_management.php`와 동일 SQL 재사용 | 동일 SQL 재사용 | 두 화면 간 정의 불일치 방지(§5 리스크) |

---

## 8. Convention Prerequisites

### 8.1 담당 파일 범위

| 파일 | 작업 유형 | 담당 제안 |
|------|-----------|-----------|
| `mall/admin/products.php` (탭 추가 + 쿼리 + 결과 테이블) | 스펙이 명확한 UI/쿼리 추가, 스키마·엔드포인트 변경 없음 | Codex |
| 최종 검증 | 코드 리뷰 + 테스트서버 수동 확인 | Claude Code |

---

## 9. Next Steps

1. [ ] Codex와 상의 → 설계 검토 반영
2. [ ] 사용자 최종 승인
3. [ ] Codex에게 구현 위임(파일 범위: `mall/admin/products.php`만) → Claude Code가 `php -l` + 코드 리뷰 + 테스트서버 수동 확인으로 검증

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-24 | Initial draft | whdans007 |
