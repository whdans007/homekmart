# Design: 가격조정 (Price Adjustment)

**Feature**: price-adjustment  
**Architecture**: Option C — Pragmatic Balance  
**Status**: Design  

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 매입 건과 무관하게 원가/판매가를 직접 수정할 독립 화면이 없어 불편 |
| **WHO** | `purchase_management` 권한을 가진 관리자/스태프 |
| **RISK** | 잘못된 가격 입력 → inventory 데이터 오염. 이력 기록으로 추적 가능 |
| **SUCCESS** | 상품 검색 후 가격 수정 완료까지 3번 이내 클릭, 모든 변경 이력 기록 |
| **SCOPE** | `price_adjustment.php` 신규, 기존 AJAX 재사용, 메뉴 추가 |

---

## 1. Overview

상품을 검색하여 원가(cost_price)와 판매가(selling_price)를 인라인으로 수정한 후, "전체 저장" 버튼으로 한번에 반영하는 독립 페이지.

- 수정된 모든 가격은 `inventory` 테이블에 반영되고 `price_change_history`에 기록됨
- 마진율은 JS로 실시간 계산 표시
- DB 추가 테이블 없음

---

## 2. Architecture Decision

**선택: Option C — Pragmatic Balance**

| 항목 | 결정 |
|------|------|
| 신규 파일 | `price_adjustment.php` 1개 |
| 저장 UX | 여러 상품 편집 후 "전체 저장" 버튼 1번 |
| AJAX 저장 | `ajax_save_price_change.php` 재사용 (순차 호출) |
| AJAX 검색 | `ajax_search_products.php` 재사용 (로그인만 필요) |

---

## 3. File Map

### 신규 생성
```
admin/price_adjustment.php
```

### 수정 대상
```
admin/partials/header.php       — 사이드바 + 모바일 메뉴에 "가격조정" 링크 추가
lang/ko.json                    — 번역 키 추가
lang/en.json                    — 번역 키 추가
```

### 재사용 (수정 없음)
```
admin/ajax_search_products.php      — 검색 (GET ?term=, 로그인만 필요)
admin/ajax_save_price_change.php    — 저장 (POST JSON)
```

---

## 4. API Contract

### 검색: `ajax_search_products.php`
```
Request:  GET ?term={query}&limit=30
Response: Array of {
  id, sku, name_ko, name_en,
  cost_price, selling_price,
  pieces_per_box
}
```

### 저장: `ajax_save_price_change.php`
```
Request:  POST (JSON body) {
  product_id: int,
  new_cost_price: float,
  new_selling_price: float
}
Response: {
  success: bool,
  message: string,
  changes: { old_cost_price, new_cost_price, old_selling_price, new_selling_price }
}
```

---

## 5. UI 설계

### 페이지 레이아웃
```
┌─────────────────────────────────────────────────────────────────┐
│  [제목] 가격조정                                                  │
├─────────────────────────────────────────────────────────────────┤
│  검색: [상품명 또는 바코드 입력____________] [검색]               │
│                                   [전체 저장] ← 오른쪽 상단      │
├────┬────────────────┬──────────┬──────────┬──────────┬──────────┤
│SKU │ 상품명(한글)   │ 현재원가 │ 신원가   │ 현재판매가│신판매가  │마진율│
├────┼────────────────┼──────────┼──────────┼──────────┼──────────┤
│    │                │  0.00    │ [______] │    0     │[______] │  0%  │
│    │                │  0.00    │ [______] │    0     │[______] │  0%  │
└────┴────────────────┴──────────┴──────────┴──────────┴──────────┘

[검색 결과 없을 때]: "검색 결과가 없습니다."
[저장 완료]: 각 행 상태 표시 (성공/오류 아이콘)
```

### UI 규칙
- 신원가/신판매가 입력 시 현재값이 placeholder로 표시
- 입력값 변경 시 마진율 실시간 재계산: `((신판매가 - 신원가) / 신원가 * 100).toFixed(1)`
- "전체 저장" 클릭 시: 원래 값과 다른 행만 저장 API 호출 (변경 없는 행 스킵)
- 저장 진행 중: 버튼 비활성화, 스피너 표시
- 저장 완료: 성공 행은 녹색 체크, 실패 행은 빨간 X + 오류 메시지

---

## 6. 마진율 계산 (JS)

```javascript
function calcMargin(costPrice, sellingPrice) {
    if (!costPrice || costPrice <= 0) return '-';
    const margin = ((sellingPrice - costPrice) / costPrice * 100);
    return margin.toFixed(1) + '%';
}
```

입력 이벤트: `input` on 신원가/신판매가 필드 → 같은 행의 마진율 셀 업데이트

---

## 7. 저장 로직 (JS)

```javascript
async function saveAll() {
    const rows = document.querySelectorAll('.product-row[data-changed="true"]');
    for (const row of rows) {
        const productId = row.dataset.productId;
        const newCost = parseFloat(row.querySelector('.new-cost').value) || 0;
        const newSelling = parseFloat(row.querySelector('.new-selling').value) || 0;
        
        const resp = await fetch('ajax_save_price_change.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                product_id: productId, 
                new_cost_price: newCost, 
                new_selling_price: newSelling 
            })
        });
        // 결과를 행에 표시
    }
}
```

변경 감지: 각 입력 필드의 `data-original` 속성과 비교, 다르면 `data-changed="true"` 설정

---

## 8. 내비게이션 추가 위치

`admin/partials/header.php` 두 곳 수정:

### 사이드바 (데스크탑)
```php
// price_change_history.php 링크 바로 아래에 추가
<a href="price_adjustment.php" class="...">
    <i class="fas fa-sliders-h mr-2 text-green-500 text-xs"></i>
    <?php echo t('navigation.price_adjustment'); ?>
</a>
```

### 모바일 메뉴
```php
// 동일한 위치에 동일 패턴으로 추가
<a href="price_adjustment.php" class="...">
    <i class="fas fa-sliders-h mr-2 text-xs"></i>
    <?php echo t('navigation.price_adjustment'); ?>
</a>
```

active 클래스 조건: `($current_page == 'price_adjustment.php')`

---

## 9. 언어 키

### ko.json 추가

```json
// navigation 섹션에 추가
"price_adjustment": "가격조정"

// 최상위 레벨에 추가
"price_adjustment": {
  "title": "가격조정",
  "page_title": "가격조정 - HOME K MART",
  "search_placeholder": "상품명 또는 바코드를 입력하세요",
  "search_button": "검색",
  "save_all_button": "전체 저장",
  "saving": "저장 중...",
  "col_sku": "SKU",
  "col_product_name": "상품명",
  "col_current_cost": "현재원가",
  "col_new_cost": "신원가",
  "col_current_selling": "현재판매가",
  "col_new_selling": "신판매가",
  "col_margin": "마진율",
  "no_results": "검색 결과가 없습니다.",
  "search_hint": "상품을 검색하면 결과가 표시됩니다.",
  "no_changes": "변경된 항목이 없습니다.",
  "save_success_count": "{n}개 상품의 가격이 변경되었습니다.",
  "save_partial_fail": "일부 항목 저장 실패: {errors}",
  "permission_denied": "가격조정 권한이 없습니다."
}
```

### en.json 추가

```json
"price_adjustment": "Price Adjustment"

"price_adjustment": {
  "title": "Price Adjustment",
  "page_title": "Price Adjustment - HOME K MART",
  "search_placeholder": "Enter product name or barcode",
  "search_button": "Search",
  "save_all_button": "Save All",
  "saving": "Saving...",
  "col_sku": "SKU",
  "col_product_name": "Product Name",
  "col_current_cost": "Current Cost",
  "col_new_cost": "New Cost",
  "col_current_selling": "Current Price",
  "col_new_selling": "New Price",
  "col_margin": "Margin",
  "no_results": "No results found.",
  "search_hint": "Search for a product to display results.",
  "no_changes": "No changes detected.",
  "save_success_count": "{n} product(s) updated successfully.",
  "save_partial_fail": "Some items failed: {errors}",
  "permission_denied": "You do not have permission to adjust prices."
}
```

---

## 10. 보안 / 유효성 검증

| 레이어 | 규칙 |
|--------|------|
| PHP 권한 | `require_permission('purchase_management')` |
| 입력 검증 | 신원가/신판매가 >= 0 (음수 불허) |
| SQL 보안 | ajax_save_price_change.php의 기존 prepared statement 재사용 |
| 점포 스코핑 | `$_SESSION['store_id']` 기반, super_admin은 store_id=1 |

---

## 11. Implementation Guide

### 11.1 Module Map

| 모듈 | 파일 | 작업 |
|------|------|------|
| M1 | `lang/ko.json`, `lang/en.json` | 번역 키 추가 |
| M2 | `admin/partials/header.php` | 사이드바 + 모바일 메뉴 링크 추가 |
| M3 | `admin/price_adjustment.php` | 메인 페이지 (PHP + HTML + JS) |

### 11.2 구현 순서

1. **M1: 언어 키 추가** — ko.json, en.json에 키 삽입
2. **M2: 내비게이션** — header.php 사이드바(~line 100) + 모바일 메뉴 두 곳 수정
3. **M3: 메인 페이지** — price_adjustment.php 생성
   - PHP: 헤더 include, 권한 확인
   - HTML: 검색 폼 + 결과 테이블 (초기 비어있음)
   - JS: 검색 AJAX, 마진율 계산, 저장 AJAX, UI 피드백

### 11.3 Session Guide

**Single session**: 3개 모듈 모두 1세션에서 완료 가능 (예상 1-2시간)
```
Session 1: M1 → M2 → M3 순서로 완료
```
