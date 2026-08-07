---
template: plan
version: 1.3
---

# wholesale-cost-price-fix Planning Document

> **Summary**: 도매판매 등록 화면(admin/wholesale_sales.php)의 박스/낱개 원가 표시 버그를 수정하고, 상품 검색·바코드 입력 시 정상도매가/기존판매가/도매등록가 3종 참고가를 표시한다.
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: whdans007
> **Date**: 2026-08-05
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 도매판매 등록 시 판매단위(박스/낱개)와 무관하게 원가가 잘못 표시되며(박스 선택 시 낱개원가 노출, 낱개 선택 시 0원 노출), 담당자가 참고할 수 있는 가격 기준(정상도매가/기존판매가/도매등록가)이 한 화면에 모이지 않아 가격 결정이 비효율적이다. |
| **Solution** | (1) 바코드 조회 API가 박스/낱개 원가를 분리 반환하도록 수정하고, "기존 납품상품" 모달의 판매단위 하드코딩 버그를 제거한다. (2) 상품 검색/바코드 조회 시 정상도매가·기존판매가(동일 판매단위 기준)·도매등록가 3종을 함께 표시하고, 클릭으로 단가를 선택 적용할 수 있게 한다. |
| **Function/UX Effect** | 판매단위 토글(BOX/PCS) 시 원가가 항상 해당 단위 값으로 정확히 표시되고, 담당자가 3가지 참고가 중 하나를 클릭 한 번으로 적용할 수 있어 오입력이 줄고 가격 결정 속도가 빨라진다. |
| **Core Value** | 원가 정확도 확보(마진 계산 신뢰성) + 가격 결정 참고정보 통합으로 도매판매 운영 효율 향상 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 판매단위별 원가 표시 오류로 마진 계산이 부정확해지고, 가격 결정에 필요한 참고정보가 흩어져 있어 비효율적 |
| **WHO** | 도매판매 등록 담당 직원 (wholesale_management 권한 보유자) |
| **RISK** | 원가/가격 계산 로직 변경이 기존 저장된 판매 데이터(custom_cost_price)나 거래처 예외가 학습 로직과 충돌할 수 있음 |
| **SUCCESS** | 박스/낱개 토글 시 원가가 항상 올바른 단위 값으로 표시되고, 3종 참고가가 검색/바코드 결과에 노출되며 기존 기능(반품, 거래처 예외가 학습)이 회귀 없이 동작 |
| **SCOPE** | ① 원가 표시 버그 수정 (2건) → ② 3종 참고가 조회/표시 → ③ 참고가 클릭 적용 UI |

---

## 1. Overview

### 1.1 Purpose

`admin/wholesale_sales.php`에서 도매판매 등록 시 상품의 원가(매입가)가 판매단위(박스/낱개)와 일치하지 않게 표시되는 버그를 수정하고, 담당자가 단가를 결정할 때 참고할 수 있는 3가지 가격(정상도매가/기존판매가/도매등록가)을 한번에 확인할 수 있도록 개선한다.

### 1.2 Background

매입 시 박스 매입과 낱개 매입이 별도로 기록되며(`wholesale_products.cost_price` = 박스원가, `cost_price_piece` = 낱개원가), 도매판매 등록 화면은 판매단위 토글(BOX/PCS)에 따라 해당 단위의 원가를 보여줘야 한다. 코드 조사 결과 다음 2가지 구현 결함이 확인되었다:

1. `admin/ajax_get_wholesale_product_by_barcode.php`가 `wholesale_products.cost_price`/`cost_price_piece`(박스/낱개 원가)를 반환하지 않고 인벤토리 소매원가(`cost_price`) 하나만 반환한다. 이 때문에 바코드로 담은 상품은 `cost_price_piece`가 항상 0으로, `cost_price`(박스원가로 취급)는 소매원가로 잘못 채워진다.
2. `wholesale_sales.php`의 "기존 납품상품" 모달 추가 함수(`addExistingProductToCart`, 약 3219번 줄)가 단가는 박스가(`wholesale_price`)를 쓰면서 `sale_unit`은 무조건 `'piece'`로 고정한다. 렌더링 시 `sale_unit`을 기준으로 원가 필드를 선택하므로 박스가가 표시된 행에 낱개원가가 노출되는 불일치가 발생한다.

추가로 가격 결정 시 참고할 수 있는 정보(원가 기반 정상도매가, 이 거래처 기존판매가, 도매상품 등록가)가 흩어져 있어 통합 표시가 필요하다.

### 1.3 Related Documents

- 관련 코드: `admin/wholesale_sales.php`, `admin/ajax_get_wholesale_product_by_barcode.php`, `admin/ajax_search_wholesale_products.php`, `admin/ajax_search_wholesale_customer_products.php`, `admin/diag_wholesale_price.php`
- 관련 테이블: `wholesale_products`(도매등록가/원가), `wholesale_sale_items`(판매이력), `wholesale_customer_prices`(거래처 예외가), `inventory`(소매 원가/판매가)

---

## 2. Scope

### 2.1 In Scope

- [ ] 버그 수정 A: `ajax_get_wholesale_product_by_barcode.php`가 박스원가/낱개원가를 분리 반환하도록 수정 (KIMS MALL 폴백 로직 포함, 검색 API와 동일한 규칙 적용)
- [ ] 버그 수정 B: `addExistingProductToCart()`의 `sale_unit: 'piece'` 하드코딩 제거 → 기본값 `'box'`로 통일 (다른 3개 추가 경로와 일관성 확보)
- [ ] 신규: 상품 검색 결과 및 바코드 조회 결과에 **정상도매가**(원가 + 마진율 기준 계산가), **기존판매가**(이 거래처에 동일 판매단위로 판매했던 직전 단가), **도매등록가**(`wholesale_products.wholesale_price`/`wholesale_price_piece`, 등록된 경우만) 3종을 함께 표시
- [ ] 신규: 3종 가격을 클릭하면 해당 값이 장바구니 단가(`unit_price`)에 적용되는 UI (라디오/칩 형태)
- [ ] 신규: 도매등록가와 기존판매가(현재 선택 단위 기준)가 모두 없을 경우 정상도매가를 기본 적용가로 자동 채움
- [ ] 판매단위(BOX/PCS) 토글 시 3종 가격 및 원가 표시가 해당 단위 값으로 재계산되어 갱신

### 2.2 Out of Scope

- `wholesale_sales_list.php`(판매 목록/이력 화면) 자체 변경 없음 — 원가 입력 모달(품목 없는 빠른등록 건)은 이번 스코프 아님
- 거래처 예외가 학습(`wholesale_customer_prices`) 로직 자체 변경 없음 (참고가 표시만 추가, 저장 로직 유지)
- 반품(return) 처리 로직 변경 없음
- 모바일 화면(`mobile_main.php` 등) 대응은 별도 스코프

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 바코드로 상품 조회 시 박스원가/낱개원가를 분리하여 반환하고, 장바구니 원가 필드에 정확히 매핑한다 | High | Pending |
| FR-02 | "기존 납품상품" 모달에서 상품 추가 시 `sale_unit` 기본값을 `'box'`로 통일한다 | High | Pending |
| FR-03 | 상품 검색/바코드 조회 응답에 정상도매가(원가+마진율), 기존판매가(동일 판매단위 기준 최근 판매단가), 도매등록가(박스/낱개)를 함께 포함한다 | High | Pending |
| FR-04 | 검색결과·바코드결과 UI에 3종 가격을 칩/버튼 형태로 표시하고 클릭 시 해당 값을 장바구니 단가로 적용한다 | High | Pending |
| FR-05 | 도매등록가·기존판매가(단위 일치)가 모두 없으면 정상도매가를 기본 적용가로 자동 채운다 | Medium | Pending |
| FR-06 | 기존판매가는 판매 당시 `sale_unit`이 현재 선택된 판매단위와 일치하는 이력만 참고한다 (불일치 시 "이력 없음") | High | Pending |
| FR-07 | BOX/PCS 토글 시 3종 가격 표시와 원가 표시가 해당 단위 기준으로 재조회/재계산된다 | High | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| 정확성 | 판매단위 변경 시 원가/참고가가 항상 해당 단위 값과 일치 | 수동 QA: 박스↔낱개 토글 반복 테스트 |
| 하위 호환 | 기존 판매 등록/수정/반품/거래처 예외가 학습 흐름에 회귀 없음 | 기존 시나리오 회귀 테스트 |
| 응답 속도 | 상품 검색/바코드 조회 응답 시간 기존 대비 크게 저하되지 않음 (서브쿼리 추가 최소화) | 육안 확인 (개발 환경) |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] 박스 선택 시 항상 박스원가, 낱개 선택 시 항상 낱개원가(또는 0이 아닌 올바른 값)가 표시됨
- [ ] 상품 검색 결과와 바코드 스캔 결과 모두에서 정상도매가/기존판매가/도매등록가 3종이 노출됨
- [ ] 3종 가격 클릭 시 장바구니 단가에 정확히 반영됨
- [ ] 도매등록가·기존판매가 없는 상품은 정상도매가가 자동 채워짐
- [ ] PHP 문법 검사(`php -l`) 통과

### 4.2 Quality Criteria

- [ ] 기존 도매판매 등록/수정/반품 시나리오 수동 회귀 테스트 통과
- [ ] `diag_wholesale_price.php` 진단 결과와 실제 UI 표시값 일치

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 바코드 API 응답 스키마 변경이 다른 화면/모바일 API 소비처에 영향 | Medium | Low | `ajax_get_wholesale_product_by_barcode.php` 사용처 전수 조사 후 필드 추가(제거 없이 확장)만 수행 |
| 기존판매가 "단위 일치 조건" 추가로 서브쿼리 복잡도 증가 → 검색 성능 저하 | Low | Low | 인덱스 확인(`wholesale_sale_items.product_id`, `wholesale_sales.customer_id`) 및 LIMIT 1 유지 |
| sale_unit 기본값 변경(B 수정)이 "기존 납품상품" 모달의 기존 사용 습관과 다르게 느껴질 수 있음 | Low | Low | 다른 3개 추가 경로와 동일하게 통일하는 것이므로 일관성 오히려 향상 — 사용자에게 변경사항 안내 |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `admin/ajax_get_wholesale_product_by_barcode.php` | API (AJAX) | 응답에 `cost_box`, `cost_piece`, `normal_wholesale_price`(box/piece), `existing_sale_price`(box/piece), `registered_price`(box/piece) 필드 추가 |
| `admin/ajax_search_wholesale_products.php` | API (AJAX) | 응답에 정상도매가/기존판매가(단위 일치) 필드 추가 (도매등록가는 기존 `wholesale_price`/`wholesale_price_piece` 재사용) |
| `admin/wholesale_sales.php` (JS) | Frontend Logic | `addExistingProductToCart()` sale_unit 수정, 검색/바코드 결과 렌더링에 3종 가격 칩 UI 및 클릭 적용 핸들러 추가, `setCartUnit()`에서 단위 변경 시 3종 가격 재조회 |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `ajax_get_wholesale_product_by_barcode.php` | READ | `wholesale_sales.php` → `searchProductByBarcode()` | 필드 추가이므로 기존 필드 사용 코드에 영향 없음 (Needs verification) |
| `ajax_search_wholesale_products.php` | READ | `wholesale_sales.php` → `searchProducts()` (상품명/SKU 검색) | 필드 추가이므로 영향 없음 (Needs verification) |
| `wholesale_sale_items.custom_cost_price` | WRITE | `wholesale_sales.php` POST 처리부 (신규/수정 등록) | 원가 저장 로직 자체는 변경 없음 — sale_unit 기본값 수정이 저장되는 custom_cost_price 값에 간접 영향 (Needs verification) |

### 6.3 Verification

- [ ] 바코드/검색 API를 사용하는 다른 화면(모바일 등)이 없는지 확인
- [ ] sale_unit 기본값 변경 후 신규 등록 시 custom_cost_price가 올바른 단위 원가로 저장되는지 확인
- [ ] 거래처 예외가 학습(`save_cust_prices`) 로직이 변경된 가격 필드와 충돌하지 않는지 확인

---

## 7. Architecture Considerations

기존 PHP + MySQLi/PDO + Vanilla JS 구조를 그대로 따른다 (신규 프레임워크/상태관리 도입 없음). `admin/ajax_*.php` 패턴과 `wholesale_sales.php` 내 인라인 JS 패턴을 유지한다.

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 3종 가격 조회 방식 | ① 기존 AJAX 응답 확장 / ② 별도 신규 AJAX 엔드포인트 | ① 기존 응답 확장 | 이미 검색/바코드 API가 유사 서브쿼리(last_sale_price 등)를 갖고 있어 확장이 자연스럽고 왕복 요청 최소화 |
| UI 표현 | ① 칩/버튼 클릭 선택 / ② 드롭다운 선택 | ① 칩/버튼 | 3개뿐이라 클릭 한 번으로 비교·적용 가능해야 함 (사용자 확인 완료) |

---

## 8. Convention Prerequisites

- [x] `CLAUDE.md`에 코딩 컨벤션 있음 (원가 소숫점 둘째자리 표시 규칙 등)
- 기존 `admin/ajax_*.php` PDO 패턴, `t()` 다국어 헬퍼 사용 패턴을 그대로 따른다
- 신규 번역 키(정상도매가/기존판매가/도매등록가 라벨 등)는 `lib/lang_helper.php` 언어 파일에 한국어/영어 추가

---

## 9. Next Steps

1. [ ] Design 문서 작성 (`wholesale-cost-price-fix.design.md`) — API 응답 스키마, SQL 서브쿼리, UI 칩 컴포넌트 상세 설계
2. [ ] 버그 수정(A, B) 우선 구현 및 검증
3. [ ] 3종 가격 표시/적용 기능 구현

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-05 | Initial draft | whdans007 |
