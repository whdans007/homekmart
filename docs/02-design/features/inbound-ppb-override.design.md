# Design: 입고 행별 "박스당 PCS 수량(pieces_per_box)" 수정 가능 처리

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 동일 상품도 입고 건마다 포장 단위(박스당 PCS 수)가 다를 수 있고 시간에 따라 바뀔 수 있는데, 현재는 상품 마스터 값에 고정되어 있어 오류 발생 |
| **WHO** | 물류센터 스태프 (입고 처리 작업자) |
| **RISK** | (1) ppb 값이 1보다 작게 입력되어 원가 환산이 비정상화될 위험, (2) 두 페이지(`inbound_add.php`, `temp_inbound_add.php`)에서 계산 로직 분기/불일치, (3) 기존 행(상품 마스터 값 의존)과 신규 입력값 간의 혼동 |
| **SUCCESS** | 각 입고 행에서 ppb를 자유롭게 수정할 수 있고, 수정값이 BOX/PCS 원가 환산(`cost_price`, `cost_price_pcs`) 및 `lc_inbound.pieces_per_box`에 정확히 반영되며, `lc_products.pieces_per_box`는 변경되지 않음 |
| **SCOPE** | `logistics/inbound_add.php`, `logistics/temp_inbound_add.php` 수정 (HTML 입력 필드 추가, JS 재계산 로직, PHP POST 처리부 ppb 소스 변경). `lc_products` 테이블/마스터 관리 화면은 수정하지 않음. `lib/unit_helper.php`에 검증 헬퍼 함수 1개 추가 |

## 1. Overview

각 입고 행의 "박스당 PCS 수량(ppb)"을 **읍은 표시값**에서 **수정 가능한 숫자 입력 필드**(`pieces_per_box[]`)로 바꾼다.

- HTML/JS 수정은 `inbound_add.php`, `temp_inbound_add.php` 각각 독립적으로 적용 (두 파일은 이미 분기된 복제본이므로 패턴만 동일하게 따라가면 됨)
- PHP 서버 검증 로직만 `lib/unit_helper.php`에 공유 헬퍼 함수 `lc_resolve_row_ppb()`로 추출하여 두 파일에서 동일하게 호출 → 검증 규칙(최소 1, 정수) 일관성 보장(NFR-1)
- `lc_products`, 상품 마스터 관리 화면은 전혀 수정하지 않음

## 2. Architecture Options

### Option A — 단순 복제/각자 수정

- 두 파일의 HTML/JS/PHP를 모두 각자 작성. 검증 로직(`max(1, (int)$value)`)도 양쪽에 각각 작성.
- 장점: 파일 간 의존성 없음. 단점: 검증 규칙이 한쪽만 바뀌면 분기될 위험.

### Option B — 공유 레이아웃 (Clean)

- 공유 JS 파일(`logistics/assets/js/ppb-override.js`)과 공유 PHP 헬퍼를 만들고 두 페이지가 모두 include.
- 장점: 완전한 일관성. 단점: 새 파일 추가, 두 페이지의 `<script>` 구조/네이밍 컨벤션이 달라 통합 시 변경 범위가 커짐 — 이번 작업 규모에 비해 과함.

### Option C — 절충적 발전 ✅ 선택됨

- HTML/JS는 각 파일에 각자 작성(이미 두 파일이 거의 동일한 패턴을 공유하므로 동일 클래스명/구조만 맞추면 됨)
- **서버 검증 로직만** `lib/unit_helper.php`에 `lc_resolve_row_ppb(string|null $posted, ?int $fallback): int` 헬퍼로 추출하여 양쪽 POST 처리부에서 호출
- 신규 파일 없음, 기존 파일 2개 + 헬퍼 1개 함수만 추가

### 비교

| 항목 | A. 단순 복제 | B. 공유 레이아웃 | C. 절충적 발전 |
|------|:---:|:---:|:---:|
| 복잡도 | 낮음 | 높음 | 낮음~중간 |
| 검증 일관성(NFR-1) | 낮음 (양쪽 따로 관리) | 높음 | 높음 (헬퍼 공유) |
| 구현 노력 | 낮음 | 높음 | 낮음 |
| 신규 파일 | 0 | 1+ | 0 |
| 기존 페이지 회귀 위험 | 없음 | 있음 (구조 변경) | 거의 없음 (헬퍼 함수 추가만) |

**선택: Option C.** 핵심 위험(ppb 검증 불일치)만 공유 헬퍼로 해소하고, UI/JS는 각 파일의 기존 패턴을 그대로 따라가 회귀 위험과 작업량을 최소화한다.

## 3. 서버 측 변경 (PHP)

### 3.1 `lib/unit_helper.php` — 신규 헬퍼 함수 추가

```php
/**
 * 입고 행의 ppb(박스당 PCS 수량)를 결정한다.
 * 클라이언트가 전송한 값을 우선 사용하고, 비정상 값이면 상품 마스터 값으로 폴백한다.
 * 두 경우 모두 최소 1로 보정한다.
 */
function lc_resolve_row_ppb($posted, $fallback) {
    $fallback = max(1, (int)($fallback ?? 1));
    if ($posted === null || $posted === '') {
        return $fallback;
    }
    $val = (int)$posted;
    return $val >= 1 ? $val : $fallback;
}
```

### 3.2 `inbound_add.php` POST 처리부 (현재 ~125-138행)

기존:
```php
$ppb  = max(1, (int)($ppb_map[$pid] ?? 1)); // FR-11: ppb<1 보정
```

변경:
```php
// Design Ref: inbound-ppb-override §3.1 — 행별 ppb 오버라이드, 마스터값은 폴백
$ppb = lc_resolve_row_ppb($pieces_per_box_inputs[$i] ?? null, $ppb_map[$pid] ?? 1);
```

- `$pieces_per_box_inputs = $_POST['pieces_per_box'] ?? [];` 를 다른 입력 배열들과 함께 상단에서 읽음
- 이후 `cost_price_pcs = lc_pcs_cost($final_price, $ppb)`, `$item['pieces_per_box'] = $ppb` 등 나머지 로직은 그대로 유지 (이미 `$ppb` 변수를 사용)

### 3.3 `temp_inbound_add.php` POST 처리부 (현재 ~126-127행)

기존:
```php
$ppb  = max(1, (int)($ppb_map[$pid] ?? 1)); // FR-11: ppb<1 보정
```

변경 (3.2와 동일 패턴):
```php
$ppb = lc_resolve_row_ppb($pieces_per_box_inputs[$i] ?? null, $ppb_map[$pid] ?? 1);
```

이후 `cost_price_pcs`/`cost_price` 계산은 기존 로직 그대로 (이미 `$ppb` 변수 사용).

### 3.4 검증 오류 복원(restored-row) 처리

- `$has_restored` 블록에서도 `$r_ppb`를 다음처럼 변경:
```php
// 변경 전: $r_ppb = max(1, (int)($rp['pieces_per_box'] ?? 1));
$r_ppb = lc_resolve_row_ppb($pieces_per_box_inputs[$ri] ?? null, $rp['pieces_per_box'] ?? 1);
```
이렇게 하면 검증 실패로 폼이 다시 표시될 때, 사용자가 입력했던 ppb 값이 그대로 유지된다.

## 4. 클라이언트 측 변경 (HTML/JS)

두 파일 공통 패턴 (각자 파일 내에서 적용):

### 4.1 입력 필드 추가 위치

- **`temp_inbound_add.php`**: 상품명 셀에 정적으로 표시되던 `1 BOX = N PCS` 텍스트(③399행 기존행, 446행 restored-row, 1381행 JS `ppbLine`)를 다음으로 교체:
  ```html
  <span class="block text-xs text-gray-400 mt-0.5">
      <i class="fas fa-box mr-1"></i>1 BOX =
      <input type="number" name="pieces_per_box[]" class="row-ppb-input inline-block w-14 border border-gray-200 rounded px-1 py-0.5 text-xs text-center focus:outline-none focus:ring-1 focus:ring-teal-500"
             min="1" step="1" value="<?php echo (int)$item['pieces_per_box']; ?>" oninput="onRowPpbInput(this)">
      PCS
  </span>
  ```
  (restored-row, rowTpl/JS 버전도 동일 패턴, 값만 `$r_ppb` / `product.pieces_per_box`로 대체)

- **`inbound_add.php`**: 현재 ppb 표시가 없으므로, 단위(Unit) 셀의 `ppb-warn-badge` 옆/아래에 동일한 입력 필드를 새로 추가:
  ```html
  <span class="block text-xs text-gray-400 mt-0.5">
      <i class="fas fa-box mr-1"></i>1 BOX =
      <input type="number" name="pieces_per_box[]" class="row-ppb-input inline-block w-14 border border-gray-200 rounded px-1 py-0.5 text-xs text-center focus:outline-none focus:ring-1 focus:ring-teal-500"
             min="1" step="1" value="<?php echo $r_ppb; ?>" oninput="onRowPpbInput(this)">
      PCS
  </span>
  ```
  (rowTpl 버전은 `value` 속성 생략, JS `addProductRow`에서 `product.pieces_per_box`로 채움)

### 4.2 `data-ppb` 속성 처리

- `data-ppb`는 **상품 선택 시 입력 필드의 기본값을 채우는 용도로만** 유지 (`addProductRow`에서 `hiddenInp.setAttribute('data-ppb', ...)` + `row-ppb-input`의 `value`도 동일하게 설정)
- 실제 계산에서는 `data-ppb`를 더 이상 직접 읽지 않고, **항상 `.row-ppb-input`의 현재 값**을 사용

### 4.3 재계산 함수 변경

양쪽 파일의 ppb 참조 로직:
```js
// 변경 전 (temp_inbound_add.php updateRowFinalCost, inbound_add.php updateRowPcsCost 등)
var ppb = hidden ? Math.max(1, parseInt(hidden.getAttribute('data-ppb') || '1', 10)) : 1;

// 변경 후
var ppbInput = row.querySelector('.row-ppb-input');
var ppb = ppbInput ? Math.max(1, parseInt(ppbInput.value || '1', 10)) : 1;
```

### 4.4 신규 핸들러 `onRowPpbInput`

ppb 입력값이 바뀌면 같은 행의 원가 재계산을 트리거한다. 두 파일 각각에 추가:

```js
window.onRowPpbInput = function(inp) {
    // 1 미만/빈 값 보정
    var v = parseInt(inp.value, 10);
    if (!v || v < 1) { inp.value = '1'; }
    var row = inp.closest('tr.item-row');
    if (!row) return;
    var costInp = row.querySelector('input[name="cost_price[]"]');
    if (costInp) updateRowFinalCost(costInp); // temp_inbound_add.php
    // inbound_add.php에서는 동일 위치의 재계산 함수명(updateRowDiscount 등)을 호출
};
```

- `temp_inbound_add.php`: 기존 `updateRowFinalCost(inp)`가 이미 ppb를 사용해 BOX 단가를 재계산하므로 그대로 재사용
- `inbound_add.php`: 기존 PCS 환산 원가 표시 갱신 함수(현재 1321행 부근, `row-pcs-cost` 갱신 로직)를 호출하도록 연결 (정확한 함수명은 구현 시 해당 파일의 함수를 확인하여 연결)

## 5. 데이터 흐름 요약

```
상품 선택 (addProductRow)
  └─ data-ppb = product.pieces_per_box (마스터값)
  └─ .row-ppb-input.value = product.pieces_per_box (기본값, 수정 가능)

사용자가 .row-ppb-input 값을 20으로 변경
  └─ onRowPpbInput → 같은 행의 cost_price 재계산 함수 호출
  └─ Final Cost / PCS Cost 표시 즉시 갱신 (ppb=20 기준)

제출 (submit)
  └─ pieces_per_box[] = [.., 20, ..] 로 전송

서버 (POST 처리부)
  └─ $ppb = lc_resolve_row_ppb($pieces_per_box_inputs[$i], $ppb_map[$pid] ?? 1)
  └─ cost_price / cost_price_pcs 계산에 $ppb=20 사용
  └─ lc_inbound.pieces_per_box = 20  (lc_products.pieces_per_box는 변경 없음)
```

## 6. 위험 및 대응

| 위험 | 대응 |
|------|------|
| ppb 입력값이 0/음수/빈값/비정수 | 클라이언트(`min="1"`, `onRowPpbInput` 보정) + 서버(`lc_resolve_row_ppb` 폴백) 이중 방어 |
| 두 페이지 간 검증 규칙 불일치 | `lc_resolve_row_ppb()` 공유 헬퍼로 단일화 |
| 검증 실패 후 폼 재표시 시 입력값 손실 | restored-row 블록에서도 `lc_resolve_row_ppb($pieces_per_box_inputs[$ri] ?? null, ...)`로 사용자가 입력한 값을 우선 복원 |
| `inbound_add.php`의 PCS 환산 원가 재계산 함수명이 설계 문서와 다를 수 있음 | Do 단계에서 해당 파일을 다시 읽어 정확한 함수명 확인 후 연결 (저위험, 단순 호출 연결) |

## 7. Test Plan (요약)

- L1: `temp_inbound_add.php` BOX 행에서 ppb를 기본값(예: 10)에서 20으로 변경 → Final Cost(BOX 단가)가 즉시 `PCS단가 × 20`으로 재계산되어 표시
- L1: `inbound_add.php` BOX 행에서 ppb를 20으로 변경 → PCS 환산 원가 표시가 `cost_price / 20`으로 즉시 재계산
- L1: ppb 입력란을 빈 값/0/음수로 만들면 1로 자동 보정됨
- L2: 두 페이지 모두 제출 후 DB(`lc_inbound.pieces_per_box`)에 사용자가 입력한 ppb 값이 저장되고, `lc_products.pieces_per_box`는 변경되지 않음 확인
- L2: 검증 오류(예: 필수값 누락)로 폼이 재표시될 때, 사용자가 입력했던 ppb 값이 유지되는지 확인
- L3: 등록 후 `lc_inventory`/FEFO 등 기존 입고 흐름이 정상 동작하는지 확인 (변경 없음 회귀 확인)

## 8. Implementation Guide (Session Guide)

| Module | 내용 | 파일 |
|--------|------|------|
| M1 | `lib/unit_helper.php`에 `lc_resolve_row_ppb()` 헬퍼 추가 | `logistics/lib/unit_helper.php` |
| M2 | `temp_inbound_add.php`: HTML(ppb 입력 필드 3곳: 기존행/restored-row/rowTpl), JS(`onRowPpbInput`, `updateRowFinalCost` ppb 참조 변경), PHP(POST 처리부 + restored-row `$r_ppb`) | `logistics/temp_inbound_add.php` |
| M3 | `inbound_add.php`: HTML(ppb 입력 필드 3곳 신규 추가), JS(`onRowPpbInput`, 기존 PCS 환산 재계산 함수 ppb 참조 변경), PHP(POST 처리부 + restored-row `$r_ppb`) | `logistics/inbound_add.php` |

권장 진행: M1 → M2 → M3 순서로 한 세션에서 처리 (M1은 양쪽이 공유하므로 먼저 완료, M2/M3는 패턴 동일하므로 M2 완료 후 M3에 그대로 적용).
