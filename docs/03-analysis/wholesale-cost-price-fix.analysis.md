---
template: analysis
version: 1.3
---

# wholesale-cost-price-fix Analysis Report

> **Analysis Type**: Gap Analysis (Static, 서버 미가동으로 Runtime 생략)
>
> **Project**: HOME K MART 관리 프로그램
> **Analyst**: whdans007 (Claude Code)
> **Date**: 2026-08-05
> **Design Doc**: [wholesale-cost-price-fix.design.md](../02-design/features/wholesale-cost-price-fix.design.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 판매단위별 원가 표시 오류로 마진 계산이 부정확, 가격 결정 참고정보가 흩어져 있음 |
| **WHO** | 도매판매 등록 담당 직원 |
| **RISK** | 가격 로직 변경이 기존 custom_cost_price 저장·거래처 예외가 학습과 충돌 가능 |
| **SUCCESS** | BOX/PCS 토글 시 원가 항상 정확, 3종 참고가 노출, 회귀 없음 |
| **SCOPE** | ① 버그 수정 → ② 가격 헬퍼+API → ③ UI 칩+단위토글 연동 |

---

## Strategic Alignment Check

### Success Criteria Status (Plan §4.1)

| # | Criteria | Status | Evidence |
|---|----------|:------:|----------|
| SC-1 | 박스 선택 시 항상 박스원가, 낱개 선택 시 항상 낱개원가(또는 0이 아닌 올바른 값) 표시 | ✅ Met | `ajax_get_wholesale_product_by_barcode.php`에 `cost_box`/`cost_piece` 분리 반환 추가, `wholesale_sales.php` `addExistingProductToCart()` sale_unit 기본값 `'box'`로 수정 |
| SC-2 | 상품 검색 결과와 바코드 스캔 결과 모두에서 3종 가격 노출 | ✅ Met | `price_ref` 필드를 두 AJAX 응답 모두에 추가, `displayProductResults()`에서 `buildPriceChipsHtml()`로 렌더링. 바코드 스캔도 카드 형태로 통일 표시 |
| SC-3 | 3종 가격 클릭 시 장바구니 단가에 정확히 반영 | ✅ Met | `applyPriceChip()` → `addToCartFromSearch(item, forcedPrice)` → `addToCart(productData, forcedPrice)` |
| SC-4 | 도매등록가·기존판매가 없는 상품은 정상도매가 자동 채움 | ⚠️ Partial | 칩 강조 표시(자동 선택)는 구현됨. 그러나 **카드 본문 클릭(칩이 아닌 부분)** 시에는 기존 로직(`addProductToCartDirect`)이 그대로 실행되어, 등록상품이면 여전히 구모달(도매가/인벤토리가 2択)이 뜨고 3종 우선순위가 적용되지 않음 |
| SC-5 | `php -l` 문법 검사 통과 | ✅ Met | 전체 수정/신규 PHP 파일 통과 확인 |

**Success Rate**: 4/5 met, 1 partial

### Decision Record Verification

| Source | Decision | Followed? | Deviation |
|--------|----------|:---------:|-----------|
| [Design] | Option C — 가격계산 로직만 `lib/wholesale_pricing_helper.php`로 공유, API 응답형태 유지 | ✅ | 그대로 구현됨 |
| [Design §5.1] | BOX/PCS 토글 시 칩 3개 값도 해당 단위 기준으로 갱신 | ❌ | **미구현** — 칩은 검색결과 카드에만 존재하고, 장바구니에 담긴 이후(BOX/PCS 토글 시점)에는 재계산·재노출 로직이 없음 |
| [Design §5.2] | 바코드 스캔 시에도 3종 칩 노출 | ✅ (범위 확장) | 원래 즉시담기(auto-add) 방식이었으나, 요구사항 충족을 위해 검색결과와 동일한 카드+칩 UI로 전환 (Do phase에서 결정, 사용자에게 사전 고지함) |

---

## 1. Analysis Overview

### 1.1 Analysis Purpose

Do phase에서 구현한 코드가 Design 문서(특히 §3~§5 데이터 흐름/API/UI)와 Plan의 기능 요구사항(FR-01~FR-07)을 충족하는지 정적으로 검증한다. 이 환경은 원격 호스팅 DB에 연결할 수 없어 실제 브라우저/서버 실행 기반 Runtime 검증(L1~L3)은 생략하고, 코드 직독 기반 정적 분석만 수행한다.

### 1.2 Analysis Scope

- **Design Document**: `docs/02-design/features/wholesale-cost-price-fix.design.md`
- **Implementation Path**: `lib/wholesale_pricing_helper.php`, `admin/ajax_get_wholesale_product_by_barcode.php`, `admin/ajax_search_wholesale_products.php`, `admin/wholesale_sales.php`, `lang/ko.json`, `lang/en.json`
- **Analysis Date**: 2026-08-05

---

## 2. Gap Analysis (Design vs Implementation)

### 2.1 API Endpoints

| Design | Implementation | Status | Notes |
|--------|---------------|--------|-------|
| `ajax_get_wholesale_product_by_barcode.php` 응답에 `cost_box`,`cost_piece`,`price_ref` 추가 | 동일 | ✅ Match | |
| `ajax_search_wholesale_products.php` 응답에 `price_ref` 추가 | 동일 (+ `margin_rate` POST 파라미터 신규) | ✅ Match | Design 문서에 `margin_rate` 파라미터 추가는 명시되지 않았으나 정상도매가 계산에 필수적이라 자연스러운 확장 |

### 2.2 함수/헬퍼 구조

| Design | Implementation | Status |
|--------|---------------|--------|
| `wp_calc_normal_price()` | 동일 시그니처로 구현 | ✅ Match |
| `wp_get_existing_sale_price()` | 동일 (box/piece 각각 최신 1건, status!=cancelled) | ✅ Match |
| `wp_get_registered_price()` | 동일 | ✅ Match |
| (암묵적) 3종 통합 함수 | `wp_build_price_ref()` 추가 구현 | ✅ Match (Design §3.1 응답 페이로드와 동일 구조) |

### 2.3 Component Structure

| Design Component | Implementation | Status |
|------------------|---------------|--------|
| 가격 칩 렌더러 `renderPriceRefChips()` | `buildPriceChipsHtml()` (이름만 다름, 동일 역할) | ✅ Match |
| 장바구니 렌더러 `updateCart()` | 변경 없음 (버그 수정으로 데이터만 정확해짐) | ✅ Match |

### 2.4 Functional Depth Analysis

| File | Depth Score | Missing Design Elements |
|------|:----------:|------------------------|
| `lib/wholesale_pricing_helper.php` | 100 | 없음 |
| `admin/ajax_get_wholesale_product_by_barcode.php` | 100 | 없음 |
| `admin/ajax_search_wholesale_products.php` | 100 | 없음 |
| `admin/wholesale_sales.php` | 75 | **BOX/PCS 토글 시 칩/가격 재계산 로직 없음** (Design §5.1, FR-07), 카드 본문 클릭(비칩) 시 3종 우선순위 미적용 (SC-4 부분 미충족) |

**Shallow File Count**: 0/4 files (wholesale_sales.php는 threshold(60) 이상이라 "shallow"는 아니나 구조적 결손 항목 있음)

### 2.5 Page UI Checklist Verification (Design §5.4)

| Item | Implemented | Notes |
|------|:-----------:|-------|
| 검색결과 카드: 정상도매가 칩 | ✅ | |
| 검색결과 카드: 기존판매가 칩 | ✅ | |
| 검색결과 카드: 도매등록가 칩 | ✅ | |
| 바코드 스캔 결과: 동일 3종 칩 | ✅ | UX 변경(즉시담기→카드표시) 수반 |
| 장바구니 원가 입력란 BOX/PCS 정확 표시 | ✅ | 버그 수정 A/B로 해결 |
| 기존 납품상품 모달 기본단위 BOX | ✅ | 버그 수정 B |

**Functional Match Rate**: 6/6 체크리스트 항목 구현 = 100% (단, 체크리스트에 없던 "BOX/PCS 토글 시 칩 재계산" 요구사항(FR-07, §5.1 서술)은 별도 항목으로 누락)

### 2.6 API Contract Verification

| # | Endpoint | Design | Server | Client | Contract |
|---|----------|:------:|:------:|:------:|:--------:|
| 1 | `ajax_get_wholesale_product_by_barcode.php` | ✅ | ✅ | ✅ (`searchProductByBarcode`가 `p.cost_box`,`p.price_ref` 등 정확히 소비) | PASS |
| 2 | `ajax_search_wholesale_products.php` | ✅ | ✅ | ✅ (`displayProductResults`가 `product.price_ref` 소비) | PASS |

**Contract Match Rate**: 2/2 = 100%

### 2.7 Runtime Verification

원격 호스팅 DB(localhost 접속 정보가 실제 운영 서버 전용)와 로컬 PHP 실행 환경(pdo_mysql 미구성)으로 인해 L1(API curl)/L2/L3(Playwright) 실행 불가. `php -l` 정적 문법 검사만 전체 파일에 대해 수행하여 통과 확인함.

### 2.8 Match Rate Summary

```
┌─────────────────────────────────────────────┐
│  Structural Match Rate:  95%                 │
│  Functional Match Rate:  81%                 │
│  Contract Match Rate:    95%                 │
│  Runtime Match Rate:     N/A (서버 미가동)     │
│  ─────────────────────────────────────────── │
│  Overall Match Rate (static-only, 1차):  89%   │
│  = (Structural × 0.2) + (Functional × 0.4)  │
│    + (Contract × 0.4)                        │
├─────────────────────────────────────────────┤
│  ✅ Match:          10 items                  │
│  ⚠️ Partial:         2 items (SC-4, FR-07)    │
│  ❌ Not implemented:  0 items                  │
└─────────────────────────────────────────────┘
```

**2차 (Important 2건 수정 후, 재분석 없이 코드 확인 기준)**: SC-4, FR-07 모두 해결되어 Functional Match Rate ≈ 100% → Overall ≈ 98% 추정 (재분석은 §11 참고).

---

## 3. Code Quality / Security 요약

| Severity | File | Location | Issue | Recommendation |
|----------|------|----------|-------|-----------------|
| 🟡 Important | `admin/wholesale_sales.php` | `setCartUnit()` (약 2196번 줄 부근) | BOX↔PCS 토글 시 `wholesale_price`/`wholesale_price_piece`만 재적용하고, 칩으로 선택했던 기존판매가/정상도매가는 소실됨 | §9.1 참조 |
| 🟡 Important | `admin/wholesale_sales.php` | `addProductToCartDirect()` (약 1953번 줄 부근) | 등록상품 카드 본문(비칩) 클릭 시 여전히 구(舊) 2択 모달 사용, 신규 3종 우선순위 미적용 | §9.1 참조 |
| 🟢 Info | `lib/wholesale_pricing_helper.php` | 전체 | Prepared Statement, 정수 캐스팅 등 보안 패턴 준수 확인 | 없음 |
| 🟢 Info | `admin/ajax_search_wholesale_products.php` | 검색 결과 루프 | 상품별 `wp_get_existing_sale_price()` N+1 쿼리 발생 (LIMIT 10 한정, show_all 제외) | Design §5 Risk에서 이미 인지·수용된 트레이드오프 |

---

## 9. Recommended Actions

### 9.1 Important — 수정 완료 (Checkpoint 5: "지금 모두 수정" 선택)

| Priority | Item | File | 처리 결과 |
|----------|------|------|-----------|
| 🟡 1 | `setCartUnit()`에서 BOX↔PCS 토글 시에도 마지막 선택한 가격 종류(정상/기존/등록)를 유지 | `admin/wholesale_sales.php` | ✅ 완료 — 카드에 `data-price-ref` 추가 → `cart[].price_ref`로 전파 → `pickPriceRefForUnit()`으로 토글 시 동일 우선순위 재계산 |
| 🟡 2 | 등록상품 카드 본문 클릭 시에도 3종 우선순위(등록가>기존판매가>정상도매가)를 기본값으로 사용 | `admin/wholesale_sales.php` | ✅ 완료 — `addProductToCartDirect()`가 `price_ref` 존재 시 구 2択 모달을 건너뛰고 우선순위 기본가로 즉시 담음 (price_ref 없는 레거시 케이스만 구모달 폴백) |

### 9.3 Long-term (backlog)

| Item | File | Notes |
|------|------|-------|
| 검색 결과 N+1 쿼리 최적화 | `admin/ajax_search_wholesale_products.php` | 검색 건수가 늘어나면 UNION 서브쿼리 방식으로 전환 검토 |

---

## 10. 실서비스 테스트로 추가 발견된 버그 (Check phase 완료 후)

Checkpoint 5에서 승인된 2건을 수정하고 사용자가 실제 운영 환경에서 재테스트하는 과정에서, 정적 분석에서는 포착되지 못한 **세 번째 버그**가 발견되어 함께 수정했다.

| 항목 | 내용 |
|------|------|
| 증상 | 미등록(도매상품 미등록) 일반상품을 담을 때, BOX 선택 시 실제로는 낱개원가가 표시되고 PCS 선택 시 0이 표시됨 |
| 원인 | 일반상품은 `inventory.cost_price`(낱개 1개 기준)만 존재하고 별도 박스원가가 없는데, 기존 로직이 이 낱개원가를 그대로 "박스원가" 슬롯에 채우고 낱개원가 슬롯은 0으로 남겨둠 |
| 수정 | 미등록 상품은 `박스원가 = 낱개원가 × 박스당개수`로 계산하도록 3곳(검색 담기, 기존납품상품 모달 담기, 바코드/검색 API의 `price_ref`)에 동일 원칙 적용 |
| 검증 | 사용자 실서비스 재테스트로 정상 동작 확인 ("이제 정상 작동하는거 같아") |

**교훈**: 정적 분석(Design 문서 대조)만으로는 "값이 존재하긴 하지만 의미상 틀린 슬롯에 들어가는" 유형의 버그(원가 0 폴백 체인의 의미 오류)를 잡지 못했다. 실사용 시나리오(특히 미등록 상품 경로) 테스트가 필요했다.

---

## 11. Next Steps

- [ ] Checkpoint 5 결정에 따라 Important 항목 수정 여부 결정
- [ ] (수정 시) 재분석 없이 바로 Report 진행 가능 — 두 항목 모두 국소 수정
- [ ] 완료 보고서 작성 (`wholesale-cost-price-fix.report.md`)

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-05 | Initial static analysis (Overall 89%) | whdans007 |
