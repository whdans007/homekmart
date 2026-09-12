---
template: plan
version: 1.3
---

# fresh-curation-price-modal Planning Document

> **Summary**: 몰 쇼핑몰 상품 큐레이션(신선상품) 리스트에 "가격 설정" 모달을 추가해, 원가/기준도매가/기준판매가를 낱개 수량 또는 무게 기준으로 미리 계산·역산해볼 수 있게 한다
>
> **Project**: HOME K MART 관리 프로그램
> **Version**: 1.1.0
> **Author**: whdans007
> **Date**: 2026-09-12
> **Status**: Draft (Codex 2회 상의 반영 완료)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | `mall/admin/products.php` 신선상품 큐레이션 행에서 원가/기준도매가/기준판매가를 표 안의 좁은 인풋 3칸에 직접 숫자로 입력해야 해서, "낱개 상품을 몇 개 묶어 팔면 얼마인지", "무게 상품을 몇 g으로 팔면 얼마인지"를 관리자가 매번 암산해야 함 |
| **Solution** | 같은 행에 "가격 설정" 버튼을 추가해 모달을 열고, 낱개는 패키지 개수(N), 무게는 무게(g)를 입력하면 ①정방향(현재 단가 기준 N개/그 무게의 예상 총액 미리보기)과 ②역방향(원하는 총액을 입력하면 그에 맞는 1개당/100g당 단가를 역산해 제안)을 함께 제공한다. 계산 결과를 "적용"하면 기존 표의 원가/기준도매가/기준판매가 입력칸에 그 제안값이 채워지고, 실제 저장은 기존 저장 버튼·AJAX 흐름을 그대로 사용한다 |
| **Function/UX Effect** | 관리자가 "5개들이 박스를 20,000원에 매입했다"거나 "300g을 9,000원에 팔고 싶다"를 입력하면 개당/100g당 정확한 단가를 즉시 계산해 기존 입력칸에 반영할 수 있어, 암산 없이 표 안에서 바로 가격을 정할 수 있음 |
| **Core Value** | 신선상품 가격 정책 실수(잘못된 원가/도매가 입력, 단위 착오)를 줄이면서도, 기존 스키마·저장 흐름·고객 결제 로직(`price_per_100g × 수량/무게`)은 전혀 건드리지 않아 회귀·정합성 위험이 없음 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 낱개/무게 신선상품의 판매 단위별 가격을 암산 없이 빠르게 산정하고 싶음 |
| **WHO** | `mall/admin/products.php`에서 신선상품 큐레이션 가격을 관리하는 관리자(`mall_management` 권한 보유자) |
| **RISK** | 계산기 결과와 실제 저장되는 값의 "단위"가 다르면(예: 총액을 개당가격 자리에 저장) 고객 결제 금액이 왜곡됨 → v1은 항상 기존 필드와 동일한 단위(1개당/100g당)로만 값을 채워 넣어 방지 |
| **SUCCESS** | 신선상품 행에서 "가격 설정" 버튼 클릭 → 수량/무게 또는 원하는 총액 입력 시 1개당/100g당 원가·기준도매가·기준판매가가 계산됨 → "표에 적용" 시 기존 입력칸에 반영되고 기존 저장 버튼으로 정상 저장됨 |
| **SCOPE** | v1: 모달 UI + 정방향/역방향 계산기 + 기존 표 입력칸·저장 흐름 재사용. **스키마 변경 없음, 신규 AJAX action 없음**. v2(별도 계획): "N개들이 패키지"·"특정 무게 단위" 자체를 고객에게 노출되는 새 판매 단위로 스토어프론트·매입등록 화면까지 반영(아래 "보류된 대안" 참고) |

---

## Codex 사전 상의 요약

> 실제 구현 지시 전 이 계획을 Codex(`codex exec --sandbox read-only`)와 총 2회 상의했다. 최초 초안은 "수량/무게를 곱한 총액을 그대로 저장"하는 방식이었으나, 두 차례 검토를 거치며 스키마·스토어프론트·매입등록 로직을 전혀 건드리지 않는 현재의 "계산 후 기존 단가 필드에 적용" 방식으로 수렴했다.

### 1차 검토에서 지적된 문제와 반영

| Codex가 지적한 문제 | 원래 초안 | 반영한 결정 |
|---|---|---|
| 기존 원가/도매가/판매가는 "1개당" 또는 "100g당" 단가이지 총액이 아님. 수량/무게를 곱한 "총액"을 그대로 override 컬럼에 저장하면 단위가 깨짐 | 원가 = 수량 × 개당원가를 그대로 override 컬럼에 저장 | 계산 결과는 항상 역산해서 "1개당/100g당" 단위로 환산한 뒤에만 기존 입력칸에 채운다 |
| `mall/lib/fresh_cart.php`가 `price_per_100g × quantity`로 고객 결제금액을 계산 — 총액을 그 필드에 저장하면 고객이 여러 배를 결제하게 됨 | 신규 컬럼(패키지 개수)을 만들어 판매 단위 자체를 바꾸는 방향 검토 | 판매 단위 자체를 바꾸는 접근은 **폐기**(아래 "검토했다 보류한 대안" 참고). 항상 기존 단위(1개/100g)를 유지 |
| `price_per_100g`이 `DECIMAL(10,2)`라 총액→단가 환산 시 반올림 오차 발생 가능 | 반올림 정책 불명확 | 역산 결과는 소숫점 둘째자리까지 반올림해 보여주고, "역산값 적용 시 N개/무게 기준 재계산한 총액이 원래 입력한 총액과 정확히 일치하지 않을 수 있음"을 모달에 안내 문구로 명시(§5 리스크) |
| 신선상품 저장 성공 시 알림만 뜨고 "오리지널" 힌트가 자동 갱신되지 않음 | 저장 직후 힌트가 자동 갱신된다고 가정 | Design 단계에서 저장 성공 후 갱신 방식(리로드 또는 DOM 갱신)을 별도로 설계하도록 리스크에 반영 |
| 두 에이전트가 `products.php`의 계산·저장 연결부를 동시에 수정하면 충돌 위험 | 역할 분담만 표로 제시, 소유권 불명확 | §8.2에 "구현 소유자 단일화" 원칙 명시 |

### 2차 검토(패키지를 새 판매 단위로 도입하는 대안 검토) 후 최종 폐기 사유

1차 검토 반영 후 사용자에게 "낱개 패키지를 계산기로만 둘지, 실제 새 판매 단위로 스키마에 영속화할지"를 재확인했을 때 처음에는 후자를 선택했다. 이를 반영한 2차 초안(`mall_fresh_products.piece_pack_size` 신규 컬럼 도입)을 Codex에게 다시 검토받은 결과, 다음 문제가 추가로 발견되어 **최종적으로 폐기**했다:

| Codex가 2차로 지적한 문제 | 결론 |
|---|---|
| `piece_pack_size > 1`을 활성 상태로 저장하면 스토어프론트가 이를 몰라 고객이 1개 가격으로 오인하고 결제할 위험 — 경고 문구만으로는 부족하고 서버 측 강제 차단이 필요 | 서버 가드를 추가하는 안도 검토했으나, 아래 항목과 겹쳐 복잡도가 더 커짐 |
| **결정적 문제**: 신선상품 매입 등록 화면(`admin/add_fresh_purchase_item.php`, `admin/fresh_purchase_batch_detail.php`)이 이미 자체적으로 "최근 매입 개당원가 → `price_per_100g` 자동 재계산" 로직(별도 기능 `fresh-margin-management`)을 갖고 있어, 패키지 개념을 몰라 다음 매입 등록 시 값을 다시 "1개당 가격"으로 덮어써 버림 | 패키지를 실제 판매 단위로 영속화하려면 매입등록 화면까지 함께 손봐야 해 범위가 대폭 커짐 |
| 상품 수정 화면(`admin/edit_fresh_product.php`)도 패키지 개수를 모른 채 판매가를 제안·`sale_type`을 변경할 수 있어 단위가 깨질 수 있음 | 관련 화면 3곳 이상을 동시에 고쳐야 안전 |

**사용자 최종 결정(2026-09-12)**: 위 연쇄 영향 때문에 낱개 패키지도 무게와 동일하게 **"계산 후 기존 단가 필드에 적용"하는 계산기**로 범위를 축소한다. "N개들이 패키지/특정 무게를 고객에게 노출되는 새 판매 단위로 만드는 것"은 매입등록·상품수정·스토어프론트·주문·영수증을 함께 고치는 **별도의 큰 PDCA 사이클**(가칭 `mall-fresh-piece-pack-unit`)로 분리해 필요 시 나중에 별도 계획한다.

---

## 1. Overview

### 1.1 Purpose

신선상품 큐레이션 리스트(`mall/admin/products.php`)에서 원가/기준도매가/기준판매가를 정하는 작업을 "가격 설정" 모달로 보완하고, 낱개 상품은 패키지 개수, 무게 상품은 무게(g)를 기준으로 금액을 계산·역산해 기존 입력칸에 바로 적용할 수 있게 한다.

### 1.2 Background

- 현재 표는 `edit-cost-price` / `edit-wholesale-reference-price` / `edit-selling-price` 세 개의 숫자 입력 칸으로만 구성되어 있고(`mall/admin/products.php:653-671`), 계산 보조 없이 관리자가 직접 숫자를 입력해야 한다.
- `mall_fresh_products.sale_type`이 `piece`(낱개)/`weight`(무게)로 이미 구분되어 있고, `fresh_purchase_items.unit_cost_per_piece`/`unit_cost_per_100g`가 "최근 매입원가" 기준값으로 이미 조회되고 있다(`mall/admin/products.php:284-292`, `mall/admin/ajax/save_fresh_curation.php:113-124`).
- `mall/lib/fresh_cart.php`(고객 장바구니)는 `sale_type`이 `weight`면 `price_per_100g × (weight_g/100)`, `piece`면 `price_per_100g × quantity`로 결제금액을 계산한다 — 즉 `price_per_100g`은 항상 "1개당" 또는 "100g당" 단가로 고정된 의미이며, 이번 기능은 이 의미를 절대 바꾸지 않는다.
- 신선상품 매입 등록 화면(`admin/add_fresh_purchase_item.php`, `admin/fresh_purchase_batch_detail.php`)도 최근 매입원가를 기준으로 `price_per_100g`을 자동 재계산하는 별도 로직(`fresh-margin-management` 기능)을 갖고 있어, 이번 기능이 만드는 값도 결국 "1개당/100g당" 단위와 호환돼야 매입 등록 시 값이 덮어써지지 않는다.

### 1.3 Related Documents

- 관련 기존 계획: `docs/01-plan/features/fresh-margin-management.plan.md`(신선상품 매입-마진 자동계산, 매입등록 화면에서 `price_per_100g` 자동 재계산)
- 관련 스키마: `sql/migrations/create_mall_fresh_products.sql`, `sql/migrations/run_add_fresh_products_pkg.php`
- 보류된 후속 계획(가칭): `mall-fresh-piece-pack-unit` — 패키지/특정 무게를 실제 판매 단위로 스토어프론트에 노출하는 별도 계획(§ "2차 검토" 참고)

---

## 2. Scope

### 2.1 In Scope

- [ ] 신선상품 큐레이션 행에 "가격 설정" 버튼 추가 (`mall/admin/products.php`, 신선상품 row_type='fresh'에만 노출)
- [ ] 모달 UI: 원가/기준도매가/기준판매가(현재 오리지널/override 값) 표시 + sale_type별 입력 분기
  - [ ] `piece`: 패키지 개수(N) 입력(스테퍼, 최소 1) + "이 개수 매입/판매 총액" 입력(선택)
  - [ ] `weight`: 무게(g) 입력(100g 단위 스텝, `unit_step_g` 참고) + "이 무게 판매 총액" 입력(선택)
- [ ] 정방향 계산(총액 미리보기): 현재 저장된 1개당/100g당 원가·기준도매가·기준판매가 × (N 또는 무게/100) = 예상 총액. 입력만으로 바로 계산, 저장 없음
- [ ] 역방향 계산(단가 역산): "이 개수/무게에 얼마를 받고 싶다"를 입력하면 총액 ÷ (N 또는 무게/100) = 1개당/100g당 단가를 역산해 "제안값"으로 표시. 원가는 최근 매입원가(`unit_cost_per_piece`/`unit_cost_per_100g`) × N(또는 무게/100)을 총원가로 보여준 뒤 같은 방식으로 1개당/100g당 원가를 표시(사실상 그대로 최근 매입원가). 기준도매가는 `ceil(1개당·100g당 원가 × (1 + markup_rate/100))`로 계산(기존 `mall_wholesale_reference_markup_rate` 재사용, 올림 규칙 동일)
- [ ] "표에 적용" 버튼: 모달에서 계산된 1개당/100g당 원가·기준도매가·기준판매가를 기존 표의 3개 input(`edit-cost-price`/`edit-wholesale-reference-price`/`edit-selling-price`)에 채워 넣고 모달을 닫음. **이 시점엔 아직 저장되지 않으며**, 관리자가 표에서 기존 "저장" 버튼을 눌러야 실제로 저장됨(기존 흐름·AJAX·검증 100% 재사용)
- [ ] 원가 기준값(최근 매입원가)이 없는 신규 상품(매입 이력 없음)은 원가 계산 영역 비활성 + 안내 문구, 판매가 역산은 그대로 사용 가능
- [ ] 역산 결과를 표에 적용해 저장한 뒤 다시 N/무게를 곱해도 입력했던 총액과 반올림 오차가 있을 수 있다는 안내 문구 표시

### 2.2 Out of Scope (v1)

- `mall_fresh_products`에 패키지 개수/특정 판매 무게를 나타내는 신규 컬럼 추가 — **폐기됨**(§"Codex 2차 검토" 참고). 모달에 입력한 N/무게는 계산에만 쓰이고 저장되지 않음
- 낱개 상품을 "N개들이 패키지" 자체로 고객에게 노출하거나 주문/영수증/물류 화면에 반영하는 것 — 별도 후속 계획(`mall-fresh-piece-pack-unit`)에서 다룸. 이번 계획은 `mall/cart.php`, `order_checkout.php`, `order_detail.php`, `orders.php`, `order_print.php`, `order_receipt.php`, `fresh_product.php`, `catalog.php`, `product.php`, `ajax/add_fresh_to_cart.php`, `admin/add_fresh_purchase_item.php`, `admin/fresh_purchase_batch_detail.php`, `admin/edit_fresh_product.php`를 **전혀 수정하지 않는다**
- 신규 AJAX action 추가 — 기존 `save_fresh_curation.php` action=update를 그대로(파라미터 변경 없이) 사용
- `pkg_pieces_per_box`/`pkg_weight_kg`(매입등록 화면 참고값) 연동 — 의미가 다른 별개 컬럼이라 연동하지 않음
- 일반상품(mall_products, row_type='general') 행으로의 확장 — 이번 스코프는 신선상품 전용

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 신선상품 큐레이션 행에 "가격 설정" 버튼을 추가하고 클릭 시 모달이 열린다 | High | Pending |
| FR-02 | 모달은 현재 원가/기준도매가/기준판매가(오리지널 값 포함)를 초기값으로 표시한다 | High | Pending |
| FR-03 | `piece` 상품은 패키지 개수(N) 입력으로 N개 기준 예상 총액을 정방향 계산해 보여준다 | High | Pending |
| FR-04 | `weight` 상품은 무게(g) 입력으로 그 무게 기준 예상 총액을 정방향 계산해 보여준다 | High | Pending |
| FR-05 | N 또는 무게와 "원하는 총액"을 입력하면 1개당/100g당 단가(원가/기준도매가/기준판매가)를 역산해 제안한다 | High | Pending |
| FR-06 | "표에 적용" 클릭 시 모달의 계산 결과(1개당/100g당 값)가 표의 기존 3개 input에 채워지고, 모달은 닫히며 저장은 기존 저장 버튼으로 별도 수행한다 | High | Pending |
| FR-07 | 최근 매입원가가 없는 상품은 원가 계산 영역이 비활성화되고 안내 문구가 표시된다 | Medium | Pending |
| FR-08 | 모달은 신규 DB 컬럼이나 신규 AJAX 요청을 만들지 않는다(기존 표 input 값 채우기 + 기존 저장 흐름만 사용) | High | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| 데이터 정합성 | 모달 계산값과 표의 "오리지널" 힌트가 동일한 마크업 로직(`mall_wholesale_reference_markup_rate`, ceil 규칙)을 사용 | 코드 리뷰로 계산식 대조 |
| 단위 일관성 | 모달이 표에 채우는 값은 항상 "1개당" 또는 "100g당" 단위이며, 총액을 그대로 채우지 않는다 | 코드 리뷰 + 수동 테스트(N=5로 계산 후 적용된 값이 1개당 값인지 확인) |
| 국지화 | 원가/합계 금액은 소숫점 둘째자리까지 표시 (CLAUDE.md 데이터 표시 규칙) | 코드 리뷰 |
| 권한 | 기존과 동일하게 `mall_management` 권한 필요(신규 권한 없음) | 코드 리뷰 |
| 회귀 방지 | 기존 표 인라인 입력/저장 흐름은 그대로 동작(모달은 보조 기능) | 수동 테스트 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] "가격 설정" 버튼 및 모달 UI 구현
- [ ] piece/weight 정방향·역방향 계산 로직 구현 및 기존 마크업 규칙과 일치
- [ ] "표에 적용" → 기존 표 input 갱신 → 기존 저장 버튼으로 정상 저장 확인
- [ ] `php -l` 린트 통과
- [ ] 코드 리뷰 완료(Claude Code)

### 4.2 Quality Criteria

- [ ] 신규 컬럼/신규 AJAX action 없이 기존 스키마·엔드포인트로 구현됨
- [ ] 매입등록/상품수정 등 다른 화면에 영향 없음(코드 미변경이므로 자동 충족, 회귀 테스트로 재확인)
- [ ] 매입 이력 없는 상품에서 오류 없이 안내 문구 표시

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 관리자가 역산된 "제안값"을 그대로 표에 적용·저장 → 실제 마진 의도와 다른 가격이 반영됨 | Medium | Medium | 모달에 "제안값이며 표에 적용 후에도 자유롭게 수정 가능"함을 명시, 적용 시 확인 없이 바로 표 input만 바꾸고 저장은 별도 버튼으로 분리해 안전판 확보 |
| 최근 매입원가가 없는 상품에서 원가 계산이 0/오류 기준으로 표시됨 | Medium | Low | FR-07대로 매입 이력 없으면 원가 계산 영역 비활성화(판매가 역산은 원가와 무관하게 계속 사용 가능) |
| 역산 시 반올림(소숫점 둘째자리)으로 인해 "적용된 단가 × N"이 원래 입력한 총액과 정확히 일치하지 않을 수 있음 | Low | Medium | 모달에 "반올림으로 인해 실제 총액과 최대 N×0.01원 오차가 있을 수 있음" 안내 문구 표시 |
| 모달과 표 인라인 입력이 동시에 존재해 상태 불일치(모달 "적용" 후 저장을 누르지 않고 페이지를 벗어나 값이 유실됨) | Low | Low | 표에 적용된 값이 미저장 상태임을 시각적으로 표시(예: 저장 버튼 강조) — Design 단계에서 UI 확정 |
| (참고) 낱개 패키지를 실제 판매 단위로 스키마에 영속화하는 대안은 매입등록/상품수정/스토어프론트까지 연쇄적으로 손봐야 해 이번 계획에서 폐기함 — 향후 그 요구가 다시 나오면 별도 PDCA 사이클로 진행 필요 | - | - | §"Codex 2차 검토" 참고, 후속 계획 `mall-fresh-piece-pack-unit`으로 문서화 |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `mall/admin/products.php` | PHP 템플릿/JS | 신선상품 행에 "가격 설정" 버튼 + 모달 마크업 + 정방향/역방향 계산 JS 추가. 기존 표 input/저장 버튼은 무변경 |
| `mall/admin/ajax/save_fresh_curation.php` | AJAX 엔드포인트 | **변경 없음** — 기존 action=update 그대로 재사용(검증 대상으로만 포함) |
| `mall_fresh_products` 테이블 | DB 스키마 | **변경 없음** |
| 매입등록/상품수정 화면(`admin/add_fresh_purchase_item.php` 등) | - | **변경 없음**(2차 검토에서 확인된 잠재 충돌을 스키마 미변경으로 원천 차단) |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `save_fresh_curation.php` action=update | UPDATE | `mall/admin/products.php` 기존 `save-curated-btn` 클릭 핸들러 | None — 모달은 표의 input.value만 채우고, 저장은 기존 클릭 이벤트를 그대로 사용(신규 파라미터 없음) |
| `mall_fresh_products.cost_price_override`/`wholesale_reference_price_override`/`price_per_100g` | READ | 몰 프론트(`fresh_cart.php` 등), `admin/fresh_products.php`, 매입등록 화면 등 | None — 저장되는 값의 "의미"(1개당/100g당 단가)가 전혀 바뀌지 않음 |

### 6.3 Verification

- [ ] 모달 없이 기존 표 입력만으로도 여전히 저장 가능함을 확인(회귀 없음)
- [ ] 모달로 계산해 표에 적용 후 저장한 값이 `admin/fresh_products.php`, 몰 프론트 등 다른 화면에서 기존과 동일하게(1개당/100g당 단가로) 해석됨을 확인
- [ ] 매입 등록 화면에서 새 매입을 등록해도 이번 기능으로 인한 부작용이 없음을 확인(코드 미변경이므로 당연히 통과해야 함)
- [ ] 권한 없는 사용자는 버튼/모달 접근 시에도 기존과 동일하게 차단됨(서버 측 권한 체크는 변경 없음)

---

## 7. Architecture Considerations

> 이 프로젝트는 PHP 서버 렌더링 + 바닐라 JS 구조로 Next.js 템플릿 항목(7.1)은 해당 없음(N/A).

### 7.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 모달 구현 방식 | 신규 컴포넌트 라이브러리 도입 vs 기존 페이지 내 순수 HTML/CSS/JS 모달 | 기존 페이지 내 순수 HTML/CSS/JS 모달 | 프로젝트 전반이 PHP+바닐라 JS+TailwindCSS이며 별도 프레임워크 없음 (CLAUDE.md 아키텍처) |
| 계산 위치 | 서버 재계산(매 입력마다 AJAX) vs 클라이언트 JS 즉시 계산 | 클라이언트 JS 즉시 계산 | 반응성 필요, 계산식이 단순 사칙연산 + ceil 뿐이라 서버 왕복 불필요. 최종 저장은 여전히 `save_fresh_curation.php`의 기존 서버 검증을 거침 |
| 판매 단위 자체 변경 여부 | (A) 계산 후 기존 단가 필드에 적용 vs (B) 패키지/무게를 새 판매 단위로 스키마에 영속화 | **(A) 채택** | (B)는 매입등록 자동재계산 로직·상품수정 화면·스토어프론트 다수 파일과 충돌(§"Codex 2차 검토"). (A)는 스키마·기존 로직 무변경으로 안전하게 즉시 배포 가능 |
| 저장 방식 | 신규 AJAX action vs 기존 action=update 그대로 재사용 | 기존 action=update 그대로 재사용(파라미터 변경 없음) | 모달이 만드는 결과가 기존 표 input과 완전히 같은 종류의 값(1개당/100g당 단가)이므로 신규 엔드포인트가 필요 없음 |

---

## 8. Convention Prerequisites

### 8.1 Existing Project Conventions

- [x] `CLAUDE.md`에 코딩/데이터 표시 규칙 존재(원가/합계 소숫점 둘째자리, 한글 UI)
- [x] `docs/00-conventions/agent-orchestration.md` 존재(Codex/Claude 작업 분담)
- [ ] 별도 ESLint/Prettier 없음(PHP+바닐라 JS 프로젝트)

### 8.2 담당 파일 범위 (agent-orchestration.md 기준)

| 파일 | 작업 유형 | 담당 제안 |
|------|-----------|-----------|
| `mall/admin/products.php` (모달 마크업 + 정방향/역방향 계산 JS + "표에 적용" 배선) | 스펙이 명확한 UI/계산 로직 추가, 스키마·엔드포인트 변경 없음 | Codex — 계산 공식(§2.1)은 이 계획에서 이미 확정되어 있어 그대로 프롬프트에 명시 가능 |
| 계산식 최종 검증(마크업률 재사용, ceil 규칙, 반올림 정책) | 도메인 판단(마진 계산) | Claude Code가 Codex 구현 결과를 검토·확정 |

> 이번 기능은 신규 컬럼/신규 엔드포인트/기존 파일(매입등록·상품수정) 변경이 전혀 없어 `mall/admin/products.php` 한 파일 안에서 끝나는 순수 UI 추가 작업이다. 파일 충돌 우려가 낮아 Codex에게 전체 구현을 위임하고 Claude Code는 계산식 검증 + 최종 코드 리뷰만 수행하는 것을 권장한다.

---

## 9. Next Steps

1. [x] Codex와 2회 상의 → 위 "Codex 사전 상의 요약" 반영, 스키마 변경 없는 최종안으로 수렴
2. [ ] 사용자 최종 승인
3. [ ] Design 문서 작성(`/pdca design fresh-curation-price-modal`) — 모달 마크업 구조, JS 함수 분리안, "표에 적용" 시 미저장 상태 표시 UI 등 상세화
4. [ ] Codex에게 구현 위임(파일 범위: `mall/admin/products.php`만) → Claude Code가 `php -l` + 코드 리뷰로 검증
5. [ ] (선택, 별도 계획) 사용자가 "N개들이 패키지"를 실제 고객 노출 판매 단위로 원할 경우 `mall-fresh-piece-pack-unit` 계획을 새로 시작

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-12 | Initial draft (수량/무게 총액을 그대로 저장하는 방식) | whdans007 |
| 1.0 | 2026-09-12 | Codex 1차 검토 반영 — 낱개는 `piece_pack_size` 신규 컬럼으로 새 판매 단위 도입, 무게는 미리보기 전용으로 축소 | whdans007 |
| 1.1 | 2026-09-12 | Codex 2차 검토 반영 — 매입등록 자동재계산 로직과의 충돌 발견, 신규 컬럼 도입 폐기. 낱개/무게 모두 "계산 후 기존 단가 필드에 적용"하는 스키마 무변경 방식으로 최종 확정 | whdans007 |
