# Plan: 가격조정 (Price Adjustment)

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 관리자가 상품의 원가/판매가를 직접 수정할 독립된 화면이 없어, 매입 건과 무관하게 가격을 조정하려면 복잡한 우회 경로를 거쳐야 함 |
| **Solution** | 상품 검색 → 인라인 편집 → 저장 흐름의 독립 페이지(`price_adjustment.php`) 신규 생성 |
| **Functional UX Effect** | 상품명/바코드로 즉시 검색 후, 테이블에서 원가·판매가를 직접 입력하고 마진율을 실시간 확인하며 저장 |
| **Core Value** | 매입관리 권한을 가진 사용자가 가격조정을 빠르게 완료하고, 모든 변경이 `price_change_history`에 자동 기록됨 |

---

## 1. User Intent Discovery

- **핵심 문제**: 매입 건과 무관하게 특정 상품의 원가/판매가를 수동으로 수정할 수 없음
- **대상 사용자**: `purchase_management` 권한을 가진 관리자/스태프
- **성공 기준**: 상품 검색 후 가격 수정 완료까지 3번 이내 클릭, 모든 변경이 이력에 기록됨

---

## 2. Alternatives Explored

### Approach A (선택): 독립 페이지 + 인라인 테이블 편집
- 상품 검색 → 결과 테이블에 원가/판매가 입력란 직접 표시 → 저장
- 기존 `ajax_save_price_change.php` 재사용으로 개발량 최소화
- **선택 이유**: 기존 패턴과 일치, 추가 인터랙션 없이 직관적

### Approach B (기각): 모달 편집 방식
- 검색 → 목록 클릭 → 모달 팝업에서 수정
- 클릭 수 증가, 연속 작업 시 불편

---

## 3. YAGNI Review

### In Scope (첫 버전에 포함)
- 상품명/바코드 검색
- 원가(cost_price) 수정 입력란
- 판매가(selling_price) 수정 입력란
- 변경 후 마진율 실시간 계산 표시
- 저장 시 `inventory` 업데이트 + `price_change_history` 기록
- 내비게이션에 "가격조정" 메뉴 추가

### Out of Scope (제외)
- 변경 이유 메모 필드
- 전체 점포 동시 수정 (super_admin 전용)
- 일괄 Excel 업로드

---

## 4. Architecture

### 신규 파일
```
admin/price_adjustment.php          ← 메인 가격조정 페이지
```

### 재사용 파일
```
admin/ajax_save_price_change.php    ← 가격 저장 (product_id, new_cost_price, new_selling_price)
admin/ajax_search_products_simple.php ← 상품 검색 AJAX
```

### 수정 파일
```
admin/partials/header.php           ← 내비게이션에 "가격조정" 링크 추가 (가격변경 이력 아래)
lang/ko.json                        ← 번역 키 추가: navigation.price_adjustment
lang/en.json                        ← 번역 키 추가: navigation.price_adjustment
```

### 데이터 흐름
```
[사용자 검색] 
    → ajax_search_products_simple.php 
    → 결과 테이블 렌더링 (현재 원가/판매가, 입력란, 마진율)

[가격 수정 후 저장 버튼]
    → ajax_save_price_change.php (POST: product_id, new_cost_price, new_selling_price)
    → inventory UPDATE (cost_price, selling_price)
    → price_change_history INSERT
    → 성공 메시지 표시
```

---

## 5. UI Layout

```
[가격조정] 페이지
┌─────────────────────────────────────────────────────┐
│ 상품 검색: [입력란___________] [검색 버튼]           │
└─────────────────────────────────────────────────────┘

┌──────┬────────┬──────────┬──────────┬──────────┬──────────┬──────┐
│ SKU  │ 상품명 │ 현재원가 │ 신원가   │ 현재판매가│ 신판매가 │ 마진율│ 저장│
├──────┼────────┼──────────┼──────────┼──────────┼──────────┼──────┤
│      │        │  0.00    │ [____]   │   0      │ [____]  │  0%  │[저장]│
└──────┴────────┴──────────┴──────────┴──────────┴──────────┴──────┘
```

- 마진율: `((신판매가 - 신원가) / 신원가) * 100`
- 신원가/신판매가 변경 시 마진율 실시간 재계산 (JS)

---

## 6. Database

추가 테이블 불필요. 기존 테이블만 사용:

| 테이블 | 작업 |
|--------|------|
| `inventory` | UPDATE cost_price, selling_price WHERE product_id = ? AND store_id = ? |
| `price_change_history` | INSERT (product_id, store_id, old/new cost_price, old/new selling_price, changed_by_user_id, changed_at) |

---

## 7. Permissions

- 접근 권한: `purchase_management` (기존 권한 재사용)
- 점포 스코핑: `$_SESSION['store_id']` 기준 (super_admin은 기본 점포 1번)

---

## 8. Language Keys

### ko.json 추가
```json
"navigation": {
  "price_adjustment": "가격조정"
},
"price_adjustment": {
  "title": "가격조정",
  "search_placeholder": "상품명 또는 바코드 입력",
  "search_button": "검색",
  "current_cost": "현재원가",
  "new_cost": "신원가",
  "current_selling": "현재판매가",
  "new_selling": "신판매가",
  "margin_rate": "마진율",
  "save": "저장",
  "save_success": "가격이 성공적으로 변경되었습니다.",
  "no_change": "변경된 가격이 없습니다.",
  "search_required": "검색어를 입력해주세요.",
  "no_results": "검색 결과가 없습니다."
}
```

---

## 9. Implementation Steps

1. **언어 키 추가** (`lang/ko.json`, `lang/en.json`)
2. **내비게이션 추가** (`admin/partials/header.php`) — 사이드바 + 모바일 메뉴 모두
3. **메인 페이지 생성** (`admin/price_adjustment.php`)
   - 헤더 포함, 권한 확인
   - 상품 검색 UI
   - 결과 테이블 (인라인 입력 + 마진율 JS 계산)
   - 저장 AJAX 호출
4. **테스트** — 검색, 가격 수정, 이력 기록 확인

---

## 10. Brainstorming Log

| 결정 | 내용 |
|------|------|
| 페이지 위치 | 독립 신규 페이지 (매입과 무관하게 사용) |
| 상품 찾기 방식 | 검색 후 선택 (기존 ajax_search_products_simple.php 재사용) |
| 메뉴 위치 | 가격변경 이력 아래 추가 |
| 구현 방식 | 인라인 테이블 편집 (모달 아님) |
| AJAX 핸들러 | ajax_save_price_change.php 재사용 (신규 작성 불필요) |
| 이유 필드 | 제외 (불필요) |
| 마진율 표시 | 포함 (JS 실시간 계산) |
