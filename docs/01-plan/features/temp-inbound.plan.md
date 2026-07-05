# Plan: 임시 입고(Temp Inbound) - PCS 단가 기반 재고 일괄 등록

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 기존 보유 재고를 시스템에 입력해야 하는데, 보유한 단가 데이터가 전부 PCS(낱개) 단가이며, 기존 `inbound_add.php`는 BOX 입고 시 BOX 단가를 입력받아 PCS 단가를 역산하는 구조라 그대로 사용할 수 없다. |
| **Solution** | `inbound_add.php`를 복사한 독립 페이지 `logistics/temp_inbound_add.php`를 만들어, BOX 행에서는 PCS 단가를 입력받아 `BOX 단가 = PCS 단가 × pieces_per_box`로 자동 환산 후 동일한 `lc_inbound`/`lc_inventory` 등록 로직을 그대로 수행한다. |
| **Function/UX Effect** | "Input Cost" 필드의 의미가 "PCS 단가"로 바뀌고, 할인율 UI는 제거된다. BOX 행은 PCS 단가 → BOX 단가 환산 결과를 "Final Cost (BOX)"로 보여주고, PCS 행은 입력값을 그대로 사용한다. |
| **Core Value** | 기존 재고 데이터를 보유한 단가 형식(PCS 단가) 그대로 빠르고 정확하게 일괄 등록할 수 있다. 작업 완료 후 페이지/메뉴 항목을 삭제하여 잔존 위험을 없앤다. |

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 기존 재고를 PCS 단가 기준으로 보유 중이며, 현재 입고 화면은 BOX 단가 입력을 전제로 함 — 단가 기준 불일치로 일반 입고 화면 사용 시 오류 발생 |
| **WHO** | 물류센터 스태프 (재고 초기 등록 작업자) |
| **RISK** | (1) 임시 페이지가 삭제되지 않고 남아 혼란 야기, (2) BOX/PCS 환산 공식 오류로 단가 데이터 오염, (3) 기존 inbound_add.php와 로직이 분기되어 유지보수 시 불일치 |
| **SUCCESS** | PCS 단가 입력 → BOX 단가가 `pieces_per_box` 기준으로 정확히 자동 계산되어 `lc_inbound`에 저장되고, 재고 등록(`lc_inventory`) 결과가 일반 입고와 동일하게 동작 |
| **SCOPE** | 신규 파일 `logistics/temp_inbound_add.php` 1개 생성(복사 기반), 사이드바/메뉴에 임시 항목 추가. 기존 `inbound_add.php`는 수정하지 않음 |

## 1. 요구사항

### 1.1 기능 요구사항

- FR-1: `inbound_add.php`를 베이스로 `logistics/temp_inbound_add.php`를 생성한다.
- FR-2: "Input Cost" 입력 필드는 **PCS 단가**를 의미하도록 라벨/안내 문구를 변경한다.
- FR-3: 행의 단위(`unit`)가 **BOX**인 경우:
  - 사용자가 입력한 PCS 단가를 `cost_price_pcs`로 저장
  - `cost_price (BOX 단가) = PCS 단가 × pieces_per_box` 를 자동 계산하여 저장
  - "Final Cost" 표시 영역에 계산된 BOX 단가와 함께, 참고용으로 입력한 PCS 단가를 함께 표시
- FR-4: 행의 단위가 **PCS**인 경우:
  - 입력값을 그대로 `cost_price` 및 `cost_price_pcs`로 저장 (변환 없음) — 기존 inbound_add.php의 PCS 처리와 동일
- FR-5: 할인율(Discount Rate) 입력 UI는 제거한다. `regular_price`/`discount_rate`는 0 또는 `cost_price`와 동일 값으로 저장(기존 컬럼 호환 유지).
- FR-6: 등록 로직(배치/품목 INSERT, lc_inventory 생성, FEFO 등)은 `inbound_add.php`와 동일하게 유지한다.
- FR-7: 사이드바(또는 적절한 네비게이션)에 "임시 입고" 메뉴 항목을 임시로 추가한다. (작업 종료 후 제거 예정임을 코드 주석으로 명시)

### 1.2 비기능 요구사항

- NFR-1: 기존 `inbound_add.php`, 공용 헬퍼(`unit_helper.php`)는 수정하지 않는다 (격리된 복사본으로 운영, 회귀 위험 최소화).
- NFR-2: 코드 상단에 "임시 기능 — 재고 초기 등록 완료 후 삭제 예정" 주석을 명시한다.

### 1.3 제외 범위 (Out of Scope)

- 기존 `inbound_add.php`/`inbound_edit.php` 동작 변경
- 할인율 관련 DB 스키마 변경
- 임시 페이지의 영구 유지 (작업 완료 후 삭제 대상)

## 2. 계산 로직 정의

```
BOX 단위 입력 시:
  pcs_unit_price = 사용자 입력값
  ppb            = products.pieces_per_box (최소 1)
  cost_price_pcs = round(pcs_unit_price, 4)
  cost_price     = round(pcs_unit_price * ppb, 2)   // BOX 단가

PCS 단위 입력 시 (기존과 동일):
  cost_price     = 사용자 입력값
  cost_price_pcs = round(cost_price, 4)
```

## 3. 위험 및 대응

| 위험 | 대응 |
|------|------|
| ppb(박스당 수량)가 1로 잘못 설정된 상품 | 기존 inbound_edit.php에서 추가한 "1 BOX = N PCS" 표시를 이 페이지에도 노출하여 작업자가 사전 확인 가능하게 함 |
| 임시 페이지 잔존 | 코드/메뉴에 "TEMP — 삭제 예정" 표기, 완료 후 별도 정리 작업 항목으로 추적 |

## 4. Success Criteria

- [ ] BOX 행에서 PCS 단가 입력 시 `cost_price = pcs단가 × pieces_per_box`로 정확히 계산되어 DB에 저장된다
- [ ] PCS 행은 변환 없이 입력값 그대로 저장된다
- [ ] 할인율 UI가 제거되고 등록 로직은 정상 동작한다
- [ ] 등록 후 `lc_inventory`/FEFO 동작이 기존 입고와 동일하게 작동한다
- [ ] 메뉴에 "임시 입고" 항목이 노출되고 정상 접근 가능하다
