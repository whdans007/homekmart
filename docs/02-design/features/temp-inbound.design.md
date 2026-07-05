# Design: 임시 입고(Temp Inbound) - PCS 단가 기반 재고 일괄 등록

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 기존 재고를 PCS 단가 기준으로 보유 중이며, 현재 입고 화면은 BOX 단가 입력을 전제로 함 |
| **WHO** | 물류센터 스태프 (재고 초기 등록 작업자) |
| **RISK** | 임시 페이지 잔존, BOX/PCS 환산 공식 오류, 기존 로직과의 분기 |
| **SUCCESS** | PCS 단가 입력 → `cost_price = pcs단가 × pieces_per_box` 정확 계산·저장, PCS 행은 변환 없음 |
| **SCOPE** | 신규 파일 `logistics/temp_inbound_add.php` 1개, 메뉴 1줄 추가. `inbound_add.php`/`unit_helper.php` 미수정 |

## 1. Overview

`inbound_add.php`를 1:1로 복제한 `temp_inbound_add.php`를 만들고, 다음 3가지만 변경한다:

1. **할인율 UI/로직 전체 제거** (discount_rate는 항상 0으로 전송)
2. **"Input Cost" 의미 변경**: BOX 행에서는 "PCS 단가"를 입력받아 `BOX 단가 = PCS단가 × pieces_per_box`를 자동 계산. PCS 행은 입력값을 그대로 사용 (변환 없음).
3. **사이드바에 "임시 입고(TEMP)" 메뉴 1줄 추가**, 파일 상단에 "임시 기능 — 삭제 예정" 주석 명시.

기존 `inbound_add.php`, `lib/unit_helper.php`, DB 스키마는 전혀 수정하지 않는다.

## 2. Architecture Options

### Option A — Minimal Changes (독립 풀 복제) ✅ 선택됨

- `inbound_add.php` 전체를 `temp_inbound_add.php`로 복사
- PHP POST 처리부의 원가 계산 로직만 교체 (할인율 분기 제거 + PCS→BOX 환산 추가)
- JS의 `updateRowDiscount`/`updateRowPcsCost`/할인율 일괄적용 블록을 단일 `updateRowFinalCost`로 교체
- 할인율 입력 영역(HTML) 제거, "Input Cost" 라벨을 "PCS Unit Cost"로 변경
- 사이드바에 메뉴 1줄 추가

### Option B — Shared Include with Mode Flag

- `inbound_add.php`를 리팩토링하여 `$mode`(normal/pcs_temp) 파라미터로 할인율 UI·원가계산 분기
- `temp_inbound_add.php`는 `require inbound_add.php`로 모드만 다르게 호출하는 thin wrapper

### Option C — Shared Calc Helper + 독립 복제

- Option A와 동일하게 독립 복제하되, PCS↔BOX 환산 공식을 `lib/unit_helper.php`에 `lc_box_cost_from_pcs()` 함수로 추가하여 공용화

### 비교

| 항목 | A. 독립 복제 | B. 공유 Include | C. 복제+공용 헬퍼 |
|------|:---:|:---:|:---:|
| 복잡도 | 낮음 | 높음 | 낮음~중간 |
| 유지보수성 | 낮음 (중복) — 단, 임시 기능이므로 무관 | 높음 | 중간 |
| 구현 노력 | 낮음 | 높음 | 낮음 |
| 기존 페이지 회귀 위험 | 없음 | **있음** (inbound_add.php 수정 필요) | 거의 없음 (헬퍼에 함수 추가만) |
| Plan NFR-1 준수 (기존 파일 미수정) | ✅ | ❌ | ⚠️ (헬퍼 파일에 함수 추가) |
| 작업 종료 후 삭제 용이성 | ✅ 파일 1개 + 메뉴 1줄만 제거 | ❌ 리팩토링 되돌리기 필요 | ✅ (헬퍼 함수는 dead code로 남되 무해) |

**추천: Option A.** 임시·단발성 도구이고, Plan에서 기존 파일 미수정을 명시했으며, 작업 종료 후 "파일 1개 + 메뉴 1줄" 제거로 완전히 정리 가능하다는 점이 핵심 요구사항(추후 삭제)과 가장 잘 맞는다.

## 3. 계산 로직 (PHP, POST 처리부)

기존(`inbound_add.php` 발췌):
```php
$final_price   = (float)($cost_prices[$i]    ?? 0);
$regular_price = (float)($regular_prices[$i] ?? $final_price);
...
$cost_price_pcs = ($unit === LC_UNIT_BOX) ? lc_pcs_cost($final_price, $ppb) : round($final_price, 4);
...
'cost_price'    => $final_price,
'cost_price_pcs'=> $cost_price_pcs,
'regular_price' => $regular_price,
'discount_rate' => $form['discount_rate'],   // 항상 0
```

변경 (`temp_inbound_add.php`):
```php
$pcs_price = (float)($cost_prices[$i] ?? 0);   // 사용자가 입력한 값 = PCS 단가
$ppb = max(1, (int)($ppb_map[$pid] ?? 1));

if ($unit === LC_UNIT_BOX) {
    $cost_price_pcs = round($pcs_price, 4);          // 입력한 PCS 단가 그대로
    $cost_price     = round($pcs_price * $ppb, 2);   // BOX 단가 = PCS단가 × ppb
} else { // PCS — 변환 없음
    $cost_price_pcs = round($pcs_price, 4);
    $cost_price     = round($pcs_price, 4);
}

$valid_items[] = [
    ...
    'cost_price'     => $cost_price,
    'cost_price_pcs' => $cost_price_pcs,
    'regular_price'  => $cost_price,   // 할인 없음 → regular = final
    'discount_rate'  => 0,
    ...
];
```

`lc_inbound`/`lc_inventory` INSERT 쿼리, 컬럼, FEFO 로직은 `inbound_add.php`와 100% 동일하게 유지한다 (NFR-1, FR-6).

## 4. UI 변경 (HTML)

- **상단 안내 배너 추가**: "⚠ 임시 입고 — 재고 초기 등록용. PCS(낱개) 단가를 입력하면 BOX 단가가 자동 계산됩니다."
- **할인율 입력 블록 제거**: 헤더 영역의 "Discount Rate (%)" `<div>` 전체 삭제. `discountRateHidden` input은 값 `0`으로 고정된 hidden input만 유지(서버에서 항상 0으로 받음, 또는 완전 제거 후 서버 기본값 0 사용).
- **"Input Cost" 컬럼 헤더** → **"PCS Unit Cost"**로 변경
- **"Final Cost" 컬럼**:
  - BOX 행: 계산된 BOX 단가 표시 (`PCS단가 × ppb`), 보조 텍스트로 `PCS {입력값}` 표시
  - PCS 행: 입력값 그대로 표시 (변환 없음)
- **할인 컬럼("Discount")**: 제거하거나 항상 "-" 표시(테이블 컬럼 수 유지 위해 `-`로 대체 권장 — colspan 등 부수 영향 최소화)

## 5. JS 변경

`updateRowDiscount` + `updateRowPcsCost` + `applyDiscountToAllRows` + 제출 시 할인 치환 로직을 다음 단일 함수로 대체:

```js
window.updateRowFinalCost = function(inp) {
    var row = inp.closest('tr.item-row');
    if (!row) return;
    var unitSel  = row.querySelector('.row-unit-select');
    var hidden   = row.querySelector('input[name="product_id[]"]');
    var ppb      = hidden ? Math.max(1, parseInt(hidden.getAttribute('data-ppb') || '1', 10)) : 1;
    var pcsPrice = parseFloat(inp.value) || 0;
    var finalSpan = row.querySelector('.row-final-cost');
    var pcsSpan   = row.querySelector('.row-pcs-cost');

    if (unitSel && unitSel.value === 'BOX') {
        var boxPrice = pcsPrice * ppb;
        finalSpan.textContent = boxPrice > 0 ? boxPrice.toFixed(2) : '-';
        pcsSpan.classList.remove('hidden');
        pcsSpan.textContent = pcsPrice > 0 ? 'PCS ' + pcsPrice.toFixed(2) : 'PCS -';
    } else {
        finalSpan.textContent = pcsPrice > 0 ? pcsPrice.toFixed(2) : '-';
        pcsSpan.classList.add('hidden');
    }
};

window.onRowUnitChange = function(sel) {
    styleUnitSelect(sel);
    var row = sel.closest('tr.item-row');
    var costInp = row && row.querySelector('input[name="cost_price[]"]');
    if (costInp) updateRowFinalCost(costInp);
};
```

- `cost_price[]` input의 `oninput`을 `updateRowDiscount(this)` → `updateRowFinalCost(this)`로 변경
- `addProductRow` 내 `updateRowPcsCost(lastRow)` 호출 → `updateRowFinalCost(costInput)`로 변경
- 할인율 관련 이벤트 리스너, `discountRateInput`/`discountHint`/`applyDiscountToAllRows`, 제출 시 `regular_price[]` 치환 로직 전부 제거
- `regular_price[]` hidden input: 제출 시 `cost_price[]`와 동일 값으로 채워 전송 (서버에서도 `regular_price = cost_price`로 처리하므로 사실상 dead, 컬럼 호환 위해 유지)

## 6. 메뉴 추가

`logistics/partials/sidebar.php`(또는 동일 역할 파일)에 1줄 추가:

```php
<a href="<?php echo LC_BASE; ?>/temp_inbound_add.php" class="...">
    <i class="fas fa-clock mr-2"></i>임시 입고 <span class="text-xs text-amber-500">(TEMP)</span>
</a>
```

파일 최상단에 주석:
```php
// ⚠ TEMP FEATURE — 기존 재고(PCS 단가 기준) 일괄 등록용. 작업 완료 후 이 파일과
//   sidebar.php의 "임시 입고" 메뉴 항목을 함께 제거할 것.
```

## 7. Implementation Guide (Session Guide)

| Module | 내용 | 파일 |
|--------|------|------|
| M1 | `inbound_add.php` → `temp_inbound_add.php` 복제, 페이지 타이틀/배너/주석 추가 | `logistics/temp_inbound_add.php` |
| M2 | 할인율 UI(HTML) 제거, "Input Cost"→"PCS Unit Cost" 라벨 변경, Discount 컬럼 처리 | 동일 |
| M3 | PHP POST 계산 로직 교체 (PCS→BOX 환산, discount_rate=0 고정) | 동일 |
| M4 | JS 계산 함수 교체 (`updateRowFinalCost`, 할인 로직 제거) | 동일 |
| M5 | 사이드바 "임시 입고" 메뉴 추가 | `logistics/partials/sidebar.php` (또는 header) |

권장 진행: M1→M2→M3→M4를 한 세션에서 처리(상호 의존적), M5는 독립적으로 마지막에 처리.

## 8. Test Plan (요약)

- L1: BOX 행에 PCS 단가 10.00, ppb=18 입력 → `cost_price=180.00`, `cost_price_pcs=10.00` 저장 확인
- L1: PCS 행에 5.50 입력 → `cost_price=5.50`, `cost_price_pcs=5.50` 저장 확인 (변환 없음)
- L2: 화면에서 PCS 단가 입력 시 Final Cost(BOX 단가)와 보조 PCS 텍스트가 즉시 갱신되는지 확인
- L2: 할인율 입력 UI가 노출되지 않는지 확인
- L3: 등록 후 `lc_inventory`에 정상적으로 lot이 생성되고 일반 입고와 동일하게 재고에 반영되는지 확인
- L3: 사이드바 "임시 입고" 메뉴 클릭 → 페이지 정상 진입
