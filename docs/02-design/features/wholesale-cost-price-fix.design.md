---
template: design
version: 1.3
---

# wholesale-cost-price-fix Design Document

> **Summary**: 도매판매 등록 화면의 박스/낱개 원가 표시 버그 수정 + 정상도매가/기존판매가/도매등록가 3종 참고가 표시·적용 기능
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: whdans007
> **Date**: 2026-08-05
> **Status**: Draft
> **Planning Doc**: [wholesale-cost-price-fix.plan.md](../01-plan/features/wholesale-cost-price-fix.plan.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 판매단위별 원가 표시 오류로 마진 계산이 부정확해지고, 가격 결정 참고정보가 흩어져 있어 비효율적 |
| **WHO** | 도매판매 등록 담당 직원 (wholesale_management 권한 보유자) |
| **RISK** | 원가/가격 계산 로직 변경이 기존 저장 데이터(custom_cost_price)나 거래처 예외가 학습 로직과 충돌할 수 있음 |
| **SUCCESS** | 박스/낱개 토글 시 원가가 항상 올바른 단위 값으로 표시, 3종 참고가 노출, 기존 기능 회귀 없음 |
| **SCOPE** | ① 원가 표시 버그 수정 → ② 3종 참고가 조회/표시 → ③ 참고가 클릭 적용 UI |

---

## 1. Overview

### 1.1 Design Goals

- 판매단위(박스/낱개)와 원가 필드 매핑을 어느 진입 경로(검색/바코드/기존납품이력)로 상품을 담아도 항상 일관되게 유지한다.
- 가격 산정 로직(정상도매가 계산, 기존판매가 조회, 도매등록가 조회)을 한 곳에 모아 바코드 API와 검색 API가 동일한 규칙을 공유하게 한다.
- 기존 UI 패턴(장바구니 테이블, BOX/PCS 토글 버튼, 검색결과 카드)을 최대한 재사용하고 신규 UI는 "가격 칩 3개"로 최소화한다.

### 1.2 Design Principles

- **단일 진실 공급원(SSOT)**: 정상도매가/기존판매가/도매등록가 계산 로직은 `lib/wholesale_pricing_helper.php` 한 곳에서만 정의한다.
- **기존 데이터 흐름 유지**: `cart` 배열 구조(`cost_price`, `cost_price_piece`, `sale_unit`, `wholesale_price`, `wholesale_price_piece`)는 그대로 두고 필드 채움 소스만 정확하게 고친다.
- **점진적 적용**: 버그 수정(A, B)은 독립적으로 먼저 배포 가능하도록 설계하고, 3종 가격 기능은 그 위에 얹는다.

---

## 2. Architecture Options (v1.7.0)

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 각 AJAX 파일에 인라인 추가 | 바코드/검색 API 전체를 헬퍼로 통합 | 가격 계산 로직만 공유 헬퍼로 분리 |
| **New Files** | 0 | 1 (헬퍼) + 대규모 리팩토링 | 1 (`lib/wholesale_pricing_helper.php`) |
| **Modified Files** | 3 | 2 (API 전체 재작성) + wholesale_sales.php | 3 (API 2개 + wholesale_sales.php) |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Low (중복 3벌) | High | High (가격 로직만 High, 나머지는 기존 유지) |
| **Effort** | Low | High | Medium |
| **Risk** | Low (버그 재발 가능) | Medium (API 전체 리팩토링 회귀 위험) | Low |
| **Recommendation** | Quick hotfix only | Long-term ideal | **Default choice** |

**Selected**: Option C — **Rationale**: 가격 계산(정상도매가/기존판매가/도매등록가) 로직이 바코드·검색 API 양쪽에서 필요해 공유 헬퍼로 분리하되, API 응답 형태나 기존 조회 로직 자체는 리팩토링하지 않아 회귀 위험을 최소화한다. 버그 수정 A/B는 헬퍼 도입과 무관하게 최소 패치로 처리한다.

> 이하 상세 설계는 Option C 기준으로 작성한다.

### 2.1 Component Diagram

```
┌───────────────────────────┐
│  admin/wholesale_sales.php │ (JS: 검색/바코드 렌더링, 장바구니, 단위토글)
└───────────┬────────────────┘
            │ fetch (POST/GET)
            ▼
┌─────────────────────────────────────┐      ┌──────────────────────────────┐
│ ajax_search_wholesale_products.php   │─────▶│ lib/wholesale_pricing_helper.php │
│ ajax_get_wholesale_product_by_barcode.php │─▶│  - get_normal_wholesale_price() │
└─────────────────────────────────────┘      │  - get_existing_sale_price()    │
            │                                  │  - get_registered_price()       │
            ▼                                  └──────────────────────────────┘
┌─────────────────────────────────────┐
│ MySQL: wholesale_products, inventory,│
│ wholesale_sale_items, wholesale_sales│
└─────────────────────────────────────┘
```

### 2.2 Data Flow

```
[검색어/바코드 입력]
   → AJAX 요청 (product_id 또는 검색어 + customer_id + sale_unit 힌트)
   → PHP: 상품/재고/도매상품 조회 (기존 로직)
   → lib/wholesale_pricing_helper.php 로 3종 가격 계산
       - normal_wholesale_price (box/piece) = 원가 + 마진율 (원가 0이면 판매가×0.93/0.9 폴백, 기존 규칙 재사용)
       - existing_sale_price (box/piece)    = 이 거래처 + 이 상품 + 판매단위 일치 + status != cancelled 최신 1건
       - registered_price (box/piece)       = wholesale_products.wholesale_price / wholesale_price_piece (등록된 경우만)
   → JSON 응답에 3종 가격 포함
   → JS: 검색결과/바코드결과 카드에 가격 칩 3개 렌더링
   → 사용자가 칩 클릭 → 장바구니 unit_price/cost_price(_piece) 갱신
   → BOX/PCS 토글 시 setCartUnit() 이 현재 캐시된 아이템 가격 데이터에서 해당 단위 값 재적용 (재조회 없음)
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `ajax_get_wholesale_product_by_barcode.php` | `lib/wholesale_pricing_helper.php` | 정상도매가/기존판매가/도매등록가 계산 공유 |
| `ajax_search_wholesale_products.php` | `lib/wholesale_pricing_helper.php` | 상동 |
| `wholesale_sales.php` (JS) | 위 두 API의 확장된 JSON 응답 | 가격 칩 렌더링 및 적용 |

---

## 3. Data Model

기존 테이블 스키마 변경 없음 (신규 컬럼/테이블 없음). 아래는 3종 가격 계산에 관여하는 기존 필드 정리.

| 가격 종류 | 데이터 출처 | 박스 필드 | 낱개 필드 |
|-----------|------------|-----------|-----------|
| 정상도매가 | `inventory.cost_price`(또는 `wholesale_products.cost_price`/`cost_price_piece`, KIMS MALL 폴백) + `margin_rate` | 계산값 (원가×(1+마진율)) | 계산값 |
| 기존판매가 | `wholesale_sale_items.unit_price` (JOIN `wholesale_sales.customer_id`, `status != 'cancelled'`, `sale_unit` 일치, 최신 1건) | `unit_price` (sale_unit='box') | `unit_price` (sale_unit='piece') |
| 도매등록가 | `wholesale_products.wholesale_price` / `wholesale_price_piece` (등록 시에만) | `wholesale_price` | `wholesale_price_piece` |

### 3.1 응답 페이로드 확장 (개념)

```
product = {
  ...기존 필드,
  price_ref: {
    normal:     { box: number, piece: number },
    existing:   { box: number|null, piece: number|null, sale_date: string|null },
    registered: { box: number|null, piece: number|null }   // 미등록 상품이면 둘 다 null
  }
}
```

---

## 4. API Specification

### 4.1 Endpoint List (변경분만)

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET/POST | `ajax_get_wholesale_product_by_barcode.php` | 바코드 조회 — 응답에 `cost_box`, `cost_piece`, `price_ref` 필드 추가 | wholesale_management |
| POST | `ajax_search_wholesale_products.php` | 상품명/SKU 검색 — 응답에 `price_ref` 필드 추가 (박스/낱개 원가는 이미 `wp_cost_box`/`wp_cost_piece`로 존재) | wholesale_management 또는 product_management |

### 4.2 신규 공유 헬퍼: `lib/wholesale_pricing_helper.php`

```php
/**
 * 정상도매가 계산 (원가 + 마진율, 원가 0이면 판매가 기준 폴백)
 * @return array ['box' => float, 'piece' => float]
 */
function wp_calc_normal_price(float $costBox, float $costPiece, float $sellingPrice, float $marginRate): array;

/**
 * 이 거래처에 대한 기존판매가 (판매단위 일치 + 취소 제외 + 최신 1건)
 * @return array ['box' => ?float, 'piece' => ?float, 'sale_date' => ?string]
 */
function wp_get_existing_sale_price(PDO $pdo, int $productId, int $customerId): array;

/**
 * 도매등록가 (wholesale_products, 미등록이면 null)
 * @return array ['box' => ?float, 'piece' => ?float]
 */
function wp_get_registered_price(?float $wholesalePrice, ?float $wholesalePricePiece): array;
```

- `wp_get_existing_sale_price()`는 box/piece 각각 별도 최신 1건을 조회한다 (판매단위별로 독립적인 이력이므로 UNION 또는 2회 서브쿼리).
- KIMS MALL 폴백(store_id=6) 규칙은 기존 `ajax_search_wholesale_products.php`의 `$cost_box_expr`/`$cost_piece_expr` 패턴을 헬퍼로 그대로 이관한다 (로직 변경 없음, 위치만 이동).

### 4.3 Detailed Specification

#### `GET ajax_get_wholesale_product_by_barcode.php`

**Request (기존 + 변경 없음):**
```
?barcode=8801045575865&store_id=1&margin_rate=15&customer_id=12
```

**Response (200, 필드 추가):**
```json
{
  "success": true,
  "data": {
    "product_id": 101,
    "cost_price": 4500,
    "cost_box": 12000,
    "cost_piece": 500,
    "wholesale_price": 15000,
    "wholesale_price_piece": 600,
    "price_ref": {
      "normal": { "box": 13800, "piece": 575 },
      "existing": { "box": 14500, "piece": null, "sale_date": "2026-07-20" },
      "registered": { "box": 15000, "piece": 600 }
    }
  }
}
```

**Error Responses (기존 유지):**
- `success:false` + `message`: 바코드 미발견, 권한 없음, 원가/판매가 정보 없음

---

## 5. UI/UX Design

### 5.1 화면 레이아웃 (검색결과/바코드결과 카드 내 가격 칩)

```
┌──────────────────────────────────────────────┐
│ [상품명]                         [도매상품 배지] │
│ SKU: ... | 박스입수: 12                        │
│ 원가(박스): 12,000 | 원가(낱개): 500 | 마진율: 15% │
│ ┌───────────┐ ┌───────────┐ ┌───────────┐     │
│ │정상도매가  │ │기존판매가  │ │도매등록가  │     │
│ │ 13,800    │ │ 14,500    │ │ 15,000    │     │ ← 클릭 시 해당 값 적용 (선택된 칩 강조)
│ │ (계산값)   │ │ (07-20)   │ │ (등록가) *선택│    │
│ └───────────┘ └───────────┘ └───────────┘     │
└──────────────────────────────────────────────┘
```

- 값이 없는 칩(예: 미등록 상품의 도매등록가, 이력 없는 기존판매가)은 비활성(회색, 클릭 불가)으로 표시하고 "없음"으로 표기.
- 기본 자동 선택(하이라이트): 도매등록가 > 기존판매가(단위 일치) > 정상도매가 순으로 존재하는 첫 값을 자동 강조 표시하되, **사용자가 3개 중 아무거나 클릭하면 즉시 그 값으로 재적용**된다 (Plan §Checkpoint 확정사항).
- BOX/PCS 토글 시 칩 3개의 값도 해당 단위 기준으로 갱신된다 (`price_ref.*.box` ↔ `price_ref.*.piece` 전환, 재요청 없이 캐시된 값 사용).

### 5.2 User Flow

```
상품 검색/바코드 스캔 → 검색결과 카드에 3종 가격 칩 노출
   → (등록가·기존판매가 모두 없음) 정상도매가 자동 선택되어 장바구니에 담김
   → (등록가 있음) 도매등록가 자동 선택되어 장바구니에 담김
   → 사용자가 다른 칩 클릭 → unit_price 즉시 교체
   → BOX/PCS 토글 → cost_price(_piece) 및 3종 칩 값 해당 단위로 갱신
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|-----------------|
| 가격 칩 렌더러 | `wholesale_sales.php` JS `renderPriceRefChips(product)` (신규 함수) | 3종 가격 칩 HTML 생성 및 클릭 핸들러 바인딩 |
| 가격 계산 헬퍼 | `lib/wholesale_pricing_helper.php` (신규) | 정상도매가/기존판매가/도매등록가 계산 |
| 장바구니 렌더러 | `wholesale_sales.php` JS `updateCart()` (기존, 수정 없음) | sale_unit 기준 원가 표시 (이미 정상 동작, 데이터만 정확해지면 됨) |

### 5.4 Page UI Checklist

#### 도매판매 등록 (wholesale_sales.php)

- [ ] 검색결과 카드: 정상도매가 칩 (값, "계산값" 라벨)
- [ ] 검색결과 카드: 기존판매가 칩 (값, 최근 판매일자 라벨, 없으면 비활성+"이력없음")
- [ ] 검색결과 카드: 도매등록가 칩 (값, "등록가" 라벨, 미등록이면 비활성+"미등록")
- [ ] 바코드 스캔 결과: 동일 3종 칩 (검색결과와 동일 컴포넌트 재사용)
- [ ] 장바구니 원가 입력란: BOX 선택 시 항상 박스원가, PCS 선택 시 항상 낱개원가 표시 (버그 수정 확인용)
- [ ] "기존 납품상품" 모달에서 상품 추가 시 기본 판매단위 BOX로 표시 (버그 수정 확인용)

---

## 6. Error Handling

### 6.1 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| (success:false) | "해당 바코드의 상품을 찾을 수 없습니다." | 바코드 미등록 | 기존 동작 유지 |
| (success:false) | "권한이 없습니다." | wholesale_management 권한 없음 | 기존 동작 유지 |
| N/A | 기존판매가/도매등록가 조회 실패(서브쿼리 예외) | DB 일시 오류 | try/catch로 감싸 해당 칩만 "없음" 처리, 전체 요청은 실패시키지 않음 (기존 파일들의 방어적 패턴 준수) |

### 6.2 Error Response Format

기존 `{"success": false, "message": "..."}` 포맷 유지 (변경 없음).

---

## 7. Security Considerations

- [x] 기존 `ensure_logged_in()` + `has_permission('wholesale_management')` 권한 체크 유지 (신규 헬퍼 함수는 인증/인가를 직접 수행하지 않고, 호출측 AJAX 파일의 기존 체크에 의존)
- [x] 신규 헬퍼 함수는 모두 Prepared Statement 사용 (기존 패턴 준수), 사용자 입력(product_id, customer_id)은 정수 캐스팅 후 바인딩
- [x] XSS: 가격 칩은 숫자만 렌더링하므로 별도 escaping 불필요하나, 날짜 라벨은 `escapeHtml` 처리
- [ ] Rate Limiting: 해당 없음 (기존 AJAX 엔드포인트에 미적용, 이번 스코프 아님)

---

## 8. Test Plan

이 프로젝트는 자동화 테스트 인프라(PHPUnit/Playwright)가 없으므로, `php -l` 문법 검사 + 수동 시나리오 테스트로 검증한다.

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| 문법 검사 | 수정/신규 PHP 파일 전체 | `php -l` | Do |
| 수동 기능 테스트 | 검색/바코드/기존납품상품 3개 진입경로 × BOX/PCS 2단위 | 브라우저 수동 QA | Check |
| 회귀 테스트 | 판매 등록/수정/반품/거래처 예외가 학습 | 브라우저 수동 QA | Check |

### 8.2 수동 테스트 시나리오

| # | 시나리오 | 절차 | 기대 결과 |
|---|----------|------|-----------|
| 1 | 바코드로 등록 도매상품 추가 후 PCS 전환 | 바코드 스캔 → 장바구니 담김 → PCS 클릭 | 원가란이 0이 아닌 낱개원가로 표시 |
| 2 | 상품명 검색으로 등록 도매상품 추가 후 BOX/PCS 반복 토글 | 검색 → 담기 → BOX↔PCS 3회 토글 | 매 토글마다 원가가 해당 단위 값과 일치 |
| 3 | 기존 납품상품 모달에서 추가 | 거래처 선택 → 기존납품상품 → 상품 클릭 | 기본 판매단위가 BOX로 표시되고 원가도 박스원가 |
| 4 | 도매등록가 없는(미등록) 상품 검색 | 미등록 상품 검색 | 정상도매가만 활성, 자동 선택됨 |
| 5 | 도매등록가·기존판매가 모두 있는 상품 | 과거 판매 이력 있는 등록 상품 검색 | 3개 칩 모두 값 표시, 도매등록가 자동 선택, 클릭으로 다른 칩 전환 가능 |
| 6 | 기존판매가 — 판매단위 불일치 | 박스로만 팔았던 상품을 PCS로 전환 | 기존판매가 칩이 "이력없음" 비활성 |
| 7 | 회귀: 신규 판매 등록 → 저장 → 미리보기 확인 | 전체 플로우 1회 | 기존과 동일하게 저장/표시됨 |
| 8 | 회귀: 거래처 예외가 학습 체크박스 저장 | 가격 칩으로 단가 변경 후 저장 | `wholesale_customer_prices`에 정상 upsert |

### 8.3 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| 도매상품(wholesale_products) | 1건 이상 | cost_price, cost_price_piece, wholesale_price, wholesale_price_piece 모두 값 존재 |
| 도매판매 이력(wholesale_sale_items) | 특정 거래처+상품 조합 1건 이상 (box/piece 각각) | sale_unit, unit_price |
| 미등록 일반상품 | 1건 | inventory.cost_price 또는 selling_price |

---

## 9. Clean Architecture

이 프로젝트는 Enterprise 레이어 구조가 아닌 PHP 절차적 구조(admin/ + lib/)를 사용한다. 이번 기능의 계층 배치는 다음과 같다.

### 9.4 This Feature's Layer Assignment

| Component | Layer(유사 개념) | Location |
|-----------|-------|----------|
| `wp_calc_normal_price()` 등 | 비즈니스 로직(헬퍼) | `lib/wholesale_pricing_helper.php` |
| 바코드/검색 AJAX 핸들러 | 컨트롤러/API | `admin/ajax_*.php` |
| 가격 칩 렌더링 JS | 프레젠테이션 | `admin/wholesale_sales.php` (인라인 JS) |
| `wholesale_products`, `wholesale_sale_items` 등 | 데이터 | MySQL |

---

## 10. Coding Convention Reference

### 10.4 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| PHP 함수 네이밍 | 기존 프로젝트 스네이크케이스 함수명 패턴 준수 (`get_role_level()` 등과 동일 스타일) |
| DB 접근 | PDO + Prepared Statement (기존 `admin/ajax_*.php` 패턴) |
| 금액 표시 | 소숫점 둘째자리까지 (CLAUDE.md 규칙) |
| 다국어 | `t('wholesale.xxx')` 키 추가 (한국어/영어) — 정상도매가/기존판매가/도매등록가 라벨 |
| 주석 | 변경 근거는 `// Design Ref: wholesale-cost-price-fix.design.md §{절}` 형식으로 표기 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
lib/
└── wholesale_pricing_helper.php   (신규)

admin/
├── ajax_get_wholesale_product_by_barcode.php   (수정 — 버그A + price_ref)
├── ajax_search_wholesale_products.php          (수정 — price_ref 추가)
└── wholesale_sales.php                          (수정 — 버그B + 가격칩 UI)
```

### 11.2 Implementation Order

1. [ ] `lib/wholesale_pricing_helper.php` 작성 (정상도매가/기존판매가/도매등록가 계산 함수 3개)
2. [ ] 버그 수정 B: `wholesale_sales.php`의 `addExistingProductToCart()` `sale_unit: 'piece'` → `'box'`
3. [ ] 버그 수정 A: `ajax_get_wholesale_product_by_barcode.php`에 `wp.cost_price`/`cost_price_piece` 조회 추가 (KIMS 폴백 포함) 후 `cost_box`/`cost_piece` 필드로 응답
4. [ ] `ajax_get_wholesale_product_by_barcode.php`, `ajax_search_wholesale_products.php`에 헬퍼 호출로 `price_ref` 필드 추가
5. [ ] `wholesale_sales.php` JS: 가격 칩 렌더 함수 추가 및 검색결과/바코드결과 카드에 통합, 클릭 시 unit_price 적용 핸들러
6. [ ] `setCartUnit()` 수정 — 단위 토글 시 캐시된 `price_ref`로 칩/원가 갱신
7. [ ] 다국어 라벨 추가 (`lib/lang_helper.php` 또는 해당 언어 파일)
8. [ ] 수동 회귀 테스트 (§8.2 시나리오 1~8)

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| 원가 표시 버그 수정 | `module-1` | 버그 A(바코드 원가 분리) + 버그 B(sale_unit 기본값) | 15-20 |
| 3종 가격 헬퍼 + API 확장 | `module-2` | `wholesale_pricing_helper.php` + 두 AJAX 응답 확장 | 25-30 |
| 가격 칩 UI + 단위토글 연동 | `module-3` | 검색/바코드 카드 UI, 클릭 적용, setCartUnit 연동 | 25-30 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 | 완료 |
| Session 2 | Do | `--scope module-1` | 15-20 |
| Session 3 | Do | `--scope module-2,module-3` | 40-50 |
| Session 4 | Check + Report | 전체 | 20-30 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-05 | Initial draft (Option C selected) | whdans007 |
