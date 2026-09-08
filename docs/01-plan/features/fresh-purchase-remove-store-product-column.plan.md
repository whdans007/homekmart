# Plan: 신선매입 등록 목록에서 Store Product 컬럼 제거
**Feature**: fresh-purchase-remove-store-product-column
**Date**: 2026-09-06
**Phase**: Plan

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | `add_fresh_purchase_item.php` 품목 표에 있는 "Store Product"(점포상품) 컬럼이 대부분 자동 매칭되어 항상 노출될 필요가 없음 |
| Solution | 해당 컬럼을 표에서 제거하되, 자동 매칭 실패 시에만 경고 + 수동 선택 검색창을 신선상품 컬럼 아래에 노출 |
| Function/UX Effect | 정상 매칭된 항목은 표가 더 간결해지고, 매칭 실패한 항목만 즉시 눈에 띔 |
| Core Value | 화면을 단순화하면서도 매칭 실패 시 수동 보정 기능(데이터 정합성)은 그대로 유지 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| WHY | Store Product 컬럼이 거의 항상 자동 매칭되어 표시할 필요가 없는데 표 공간을 차지함 |
| WHO | 신선매입 등록 화면(`add_fresh_purchase_item.php`) 사용자 |
| RISK | `item.storeProductId`는 저장 시 필수값(submit 검증 및 서버측 `fresh_find_store_product` 검증) — 컬럼을 없애면서 수동 선택 기능까지 없애면 매칭 실패 항목을 저장할 방법이 사라짐 → 반드시 매칭 실패 시 UI(검색창)는 유지 |
| SUCCESS | 자동 매칭된 항목은 컬럼 없이 표시되고, 매칭 실패 항목은 신선상품 컬럼 안에 경고+검색창이 노출되어 기존과 동일하게 수동 선택 가능 |
| SCOPE | `admin/add_fresh_purchase_item.php`만 수정 (표 헤더, 행 렌더링 JS). DB/AJAX 변경 없음 |

---

## 1. 요구사항

### 1.1 기능 요구사항

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| F-01 | 품목 표 헤더에서 "Store Product"(`t('mall_fresh_products.store_product')`) `<th>` 제거 | 필수 |
| F-02 | 행 렌더링(`appendItemRow`)에서 별도 `tdStoreProduct` 컬럼(`<td>`) 제거 | 필수 |
| F-03 | 자동 매칭 성공 시(`item.storeProductId` 있음): 신선상품 컬럼에 추가 표시 없음 (표 간결화) | 필수 |
| F-04 | 자동 매칭 실패 시(`item.storeProductId` 없음): 신선상품 컬럼 하단에 기존과 동일한 경고 문구 + 점포상품 검색 입력창 노출, 선택 시 매칭 완료 처리 | 필수 |
| F-05 | colspan 등 표 레이아웃(빈 상태 행 `no-items-row`의 `colspan="8"` 등) 컬럼 수 변경에 맞게 조정 | 필수 |
| F-06 | 기존 `refreshStoreProductCell()` 로직(검색/선택/변경 버튼)은 위치만 신선상품 셀 내부로 이동, 동작은 그대로 유지 | 필수 |

### 1.2 비기능 요구사항

- 서버측 검증(`fresh_find_store_product`, POST 처리)은 변경하지 않음 — 프론트 UI 배치만 변경
- 기존 SKU 표시, "변경" 버튼 문구/동작 그대로 유지

---

## 2. 범위

### In Scope
- `admin/add_fresh_purchase_item.php` — 품목 표 헤더 `<th>` 목록, `appendItemRow()`의 셀 구성

### Out of Scope
- 서버측 저장/검증 로직
- 다른 화면의 유사 표 UI

---

## 3. 구현 계획

1. `<thead>`의 "Store Product" `<th>` 삭제, `no-items-row`의 `colspan` 값을 1 줄여 조정
2. `appendItemRow()`에서 `tdStoreProduct` 관련 코드(검색 input, 결과 목록, `refreshStoreProductCell` 호출부)를 별도 `<td>`로 만들지 않고, 신선상품 `<td>`(`tdMaster`) 내부의 하위 요소로 이동
3. `refreshStoreProductCell()`는 매칭 성공 시 아무것도 렌더링하지 않거나 최소 정보만 표시, 매칭 실패 시에만 경고+검색 UI를 신선상품 셀 안에 표시하도록 조건 분기
4. 컬럼 수가 하나 줄어드므로 관련 `colspan`, 표 헤더 배열을 함께 확인

---

## 4. 성공 기준

- [ ] 품목 표에 Store Product 전용 컬럼이 더 이상 보이지 않음
- [ ] 자동 매칭된 항목: 신선상품 컬럼에 경고/검색창 없이 깔끔하게 표시
- [ ] 자동 매칭 실패 항목: 신선상품 컬럼 안에 경고 문구 + 검색창이 노출되고, 선택 시 매칭 완료
- [ ] "변경" 버튼으로 매칭된 점포상품을 다시 검색해 바꿀 수 있음 (기존 동작 유지)
- [ ] 저장 시 매칭되지 않은 항목이 있으면 기존과 동일하게 검증 오류 발생
