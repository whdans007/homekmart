# Plan: 입고 행별 "박스당 PCS 수량(pieces_per_box)" 수정 가능 처리

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 상품 마스터(`lc_products.pieces_per_box`)에 박스당 PCS 수량이 고정되어 있지만, 실제로는 동일 상품이라도 입고 시점/거래처에 따라 포장 단위(예: 10개입 vs 20개입)가 다를 수 있고, 시간이 지나며 포장 방식이 변경되기도 한다. 현재는 BOX↔PCS 원가 환산이 항상 상품 마스터 값으로 고정되어 있어 이런 경우 잘못된 단가가 계산/저장된다. |
| **Solution** | `inbound_add.php`와 `temp_inbound_add.php`의 각 입고 행에 "1 BOX = [N] PCS" 입력 필드를 추가하여, 작업자가 해당 입고 건의 실제 포장 수량을 직접 입력/수정할 수 있게 한다. 기본값은 상품 마스터의 `pieces_per_box`이며, 수정 시 그 값이 BOX↔PCS 원가 환산 및 `lc_inbound.pieces_per_box` 저장에 즉시 사용된다. 상품 마스터(`lc_products`)는 일체 수정하지 않는다. |
| **Function/UX Effect** | 기존에 읍은 텍스트로만 표시되던 "1 BOX = N PCS" 안내가 숫자 입력 필드(최소값 1, 정수)로 바뀌며, 값 변경 시 같은 행의 Final Cost/PCS Cost 표시가 즉시 재계산된다. 제출 시 이 값이 그 입고 건의 `lc_inbound.pieces_per_box`로 저장된다. |
| **Core Value** | 포장 단위가 다른 입고를 정확한 단가로 기록할 수 있게 되어, 향후 재고 가치 계산/단가 분석의 정확도가 향상되고, 상품 마스터 데이터 오염 없이 건별 예외를 처리할 수 있다. |

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 동일 상품도 입고 건마다 포장 단위(박스당 PCS 수)가 다를 수 있고 시간에 따라 변경될 수 있는데, 현재는 상품 마스터 값에 고정되어 있어 오류 발생 |
| **WHO** | 물류센터 스태프 (입고 처리 작업자) |
| **RISK** | (1) ppb 값이 1보다 작게 입력되어 원가 환산이 비정상화될 위험, (2) 두 페이지(`inbound_add.php`, `temp_inbound_add.php`)에서 계산 로직 분기/불일치, (3) 기존 행(상품 마스터 값 의존)과 신규 입력값 간의 혼동 |
| **SUCCESS** | 각 입고 행에서 ppb를 자유롭게 수정할 수 있고, 수정값이 BOX/PCS 원가 환산(`cost_price`, `cost_price_pcs`) 및 `lc_inbound.pieces_per_box`에 정확히 반영되며, `lc_products.pieces_per_box`는 변경되지 않음 |
| **SCOPE** | `logistics/inbound_add.php`, `logistics/temp_inbound_add.php` 수정 (HTML 입력 필드 추가, JS 재계산 로직, PHP POST 처리부 ppb 소스 변경). `lc_products` 테이블/마스터 관리 화면, `lib/unit_helper.php`는 수정하지 않음 (단, 필요 시 검토) |

## 1. 요구사항

### 1.1 기능 요구사항

- FR-1: `inbound_add.php`와 `temp_inbound_add.php`의 각 입고 행에 "1 BOX = [입력] PCS" 형태의 숫자 입력 필드를 추가한다. 현재 정적 텍스트로 표시되는 위치를 대체한다.
- FR-2: 입력 필드의 기본값은 상품 선택 시 상품 마스터의 `pieces_per_box`로 자동 채워진다 (기존 `data-ppb` 속성/표시 로직 재사용).
- FR-3: 사용자가 이 값을 변경하면 최소 1 이상의 정수로 제한된다 (`min="1"`, `step="1"`, 1 미만/빈 값은 1로 보정).
- FR-4: 값 변경 시 같은 행의 원가 계산이 **즉시(클라이언트 측)** 재계산되어 표시된다.
  - `temp_inbound_add.php`: BOX 행의 `Final Cost (BOX)` = PCS 단가 × 변경된 ppb
  - `inbound_add.php`: BOX 행의 `cost_price_pcs`(PCS 환산 단가) 표시 = `cost_price / 변경된 ppb`
- FR-5: 폼 제출 시 각 행의 (수정 가능성이 있는) ppb 값이 `pieces_per_box[]` 배열로 함께 전송된다.
- FR-6: 서버 측(`POST` 처리부)은 더 이상 `$ppb_map`(상품 마스터 조회 결과)을 직접 사용하지 않고, 클라이언트에서 전송된 `pieces_per_box[]` 값을 사용한다. 단, 최소 1로 보정(`max(1, (int)$value)`)하는 기존 방어 로직은 유지한다.
- FR-7: 저장 시 이 값이 `lc_inbound.pieces_per_box`에 그 입고 건의 스냅샷으로 저장된다 (컬럼은 이미 존재).
- FR-8: `lc_products.pieces_per_box`(상품 마스터)는 어떤 경우에도 수정하지 않는다.

### 1.2 비기능 요구사항

- NFR-1: 두 페이지(`inbound_add.php`, `temp_inbound_add.php`)에서 ppb 입력 UI와 재계산 로직의 동작 방식(필드 위치, 검증 규칙, 최소값 처리)을 동일하게 유지하여 사용자 혼란을 줄인다.
- NFR-2: 기존에 저장된 입고 데이터(이미 `lc_inbound.pieces_per_box`에 상품 마스터 값으로 저장된 행들)에는 영향이 없다 — 이 기능은 신규 입고 등록 시점에만 적용된다.

### 1.3 제외 범위 (Out of Scope)

- 상품 마스터(`lc_products.pieces_per_box`) 화면 및 데이터 변경
- 기존에 등록된 입고/재고 데이터의 ppb 값 재계산/마이그레이션
- `inbound_edit.php`(입고 수정 화면)의 ppb 수정 기능 추가 (이번 요청은 신규 입고 등록 화면 2개에 한정)

## 2. 계산 로직 정의

```
공통:
  ppb = max(1, 사용자 입력값(정수))   // 기본값 = 상품 마스터 pieces_per_box, 사용자가 수정 가능

inbound_add.php (BOX 단위 입력):
  cost_price      = 사용자 입력 BOX 단가
  cost_price_pcs  = round(cost_price / ppb, 4)   // ppb 변경 시 즉시 재계산

inbound_add.php (PCS 단위 입력):
  cost_price      = 사용자 입력 PCS 단가  (ppb 영향 없음)
  cost_price_pcs  = round(cost_price, 4)

temp_inbound_add.php (BOX 단위 입력, 입력값=PCS 단가):
  cost_price_pcs  = round(pcs_price, 4)
  cost_price      = round(pcs_price * ppb, 2)    // ppb 변경 시 즉시 재계산

temp_inbound_add.php (PCS 단위 입력):
  cost_price_pcs  = round(pcs_price, 4)
  cost_price      = round(pcs_price, 4)

저장:
  lc_inbound.pieces_per_box = ppb   (행별 스냅샷, 상품 마스터 미변경)
```

## 3. 위험 및 대응

| 위험 | 대응 |
|------|------|
| ppb 입력값이 0/음수/빈값 | 클라이언트(`min="1"`)와 서버(`max(1, (int)$value)`) 양쪽에서 1로 보정 |
| 두 페이지 간 로직 불일치 | 동일한 입력 필드 마크업과 재계산 함수 패턴을 양쪽에 동일하게 적용 |
| 기존 `data-ppb` 속성 기반 로직과의 충돌 | `data-ppb`는 "기본값 채우기" 용도로만 유지하고, 실제 계산은 입력 필드의 현재 값을 우선 사용하도록 변경 |

## 4. Success Criteria

- [ ] `inbound_add.php`, `temp_inbound_add.php` 양쪽 모두 각 입고 행에 "1 BOX = [입력] PCS" 수정 가능 필드가 표시된다
- [ ] 필드 기본값은 상품 마스터 `pieces_per_box` 값이며, 1 이상 정수로만 입력 가능하다
- [ ] 값 변경 시 해당 행의 원가 표시(Final Cost / PCS 환산가)가 즉시 재계산된다
- [ ] 제출 후 `lc_inbound.pieces_per_box`에 사용자가 입력한 값이 저장된다 (상품 마스터 값과 달라도 됨)
- [ ] `lc_products.pieces_per_box`는 변경되지 않는다
- [ ] 기존 입고 등록 흐름(FEFO, `lc_inventory` 생성 등)은 정상 동작한다
