# Design: 브랜드/카테고리 드롭다운 인라인 수정

**Feature**: brand-cat-inline-edit
**Date**: 2026-06-17
**Phase**: Design
**Architecture**: Option C — 실용적 균형 (인라인 편집 로직만 공통 헬퍼로 추출, 기존 위젯은 호출부만 추가)

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| WHY | 브랜드/카테고리 이름 오타·표기 변경 시 별도 관리 페이지 이동 → 입고 입력 흐름 단절 |
| WHO | 물류센터 직원 (inbound_add.php / temp_inbound_add.php / products.php) |
| RISK | 위젯 JS(`makeW`/`searchWidget`)가 3개 파일에 중복 → 공통 헬퍼로 중복 제거, 기존 위젯은 비파괴 |
| SUCCESS | 연필 클릭 → 인라인 입력 전환 → 저장 시 DB 반영 + 라벨 즉시 갱신, 새로고침 없음 |
| SCOPE | 커스텀 `<ul>` 드롭다운 위젯 + 신규 AJAX 수정 엔드포인트 1개. DB/등록 로직 변경 없음 |

---

## 1. 개요

기존 검증된 위젯(`makeW`, `searchWidget`)은 **건드리지 않고**, 리스트 항목별 인라인 편집을 담당하는 **공통 헬퍼 partial**(`partials/inline_edit_widget.php`)을 추가한다. 각 위젯의 리스트 렌더링 시 헬퍼의 `attach()`를 호출해 연필 버튼·편집 모드·저장을 위임한다. 저장은 신규 AJAX(`ajax/quick_update.php`)로 처리한다.

---

## 2. 아키텍처

```
[드롭다운 <li>]  ──render()──▶  LcInlineEdit.attach({li,item,type,csrf,onSaved})
                                      │
                          연필 클릭 ──┤ li 내용 → [en input][ko input][저장][취소]
                                      │
                          저장 클릭 ──▶ POST ajax/quick_update.php
                                      │        (type,id,name_en,name_ko,csrf)
                                      │◀── {success,id,name_en,name_ko}
                                      ▼
                          onSaved(updated) ─▶ 위젯이 로컬 data 갱신 + 선택라벨 동기화 + 재렌더
```

### 2.1 컴포넌트
| 컴포넌트 | 책임 |
|----------|------|
| `ajax/quick_update.php` | CSRF·권한·검증 후 `lc_brands`/`lc_categories` UPDATE |
| `partials/inline_edit_widget.php` | 전역 `window.LcInlineEdit.attach()` 제공 — 연필 버튼/편집모드 DOM·이벤트·AJAX 캡슐화 |
| 각 위젯 (`makeW`/`searchWidget`) | li 렌더 시 `attach()` 호출, `onSaved` 콜백에서 로컬 상태 갱신 |

---

## 3. 백엔드 설계 — `ajax/quick_update.php`

`quick_create.php`를 미러링한다.

```php
<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');
lc_require_staff();

$type    = $_POST['type'] ?? '';
$id      = (int)($_POST['id'] ?? 0);
$name_en = trim($_POST['name_en'] ?? '');
$name_ko = trim($_POST['name_ko'] ?? '') ?: null;
$token   = $_POST['csrf_token'] ?? '';

if (!hash_equals($_SESSION['lc_csrf'] ?? '', $token)) { /* 보안 오류 */ }
if (!in_array($type, ['brand','category'], true)) { /* invalid */ }
if ($id <= 0) { /* invalid id */ }
if ($name_en === '') { /* 영문 필수 */ }

$table = $type === 'brand' ? 'lc_brands' : 'lc_categories';
$st = $conn->prepare("UPDATE {$table} SET name_en = ?, name_ko = ? WHERE id = ?");
$st->bind_param('ssi', $name_en, $name_ko, $id);
$st->execute();
// 성공 응답
echo json_encode(['success'=>true,'id'=>$id,'name_en'=>$name_en,'name_ko'=>$name_ko]);
```

- `$table`은 화이트리스트 분기(`brand`/`category`)로만 결정 → SQL 인젝션 불가
- 응답 형식은 quick_create.php와 동일 → 클라이언트 재사용 용이

---

## 4. 공통 헬퍼 설계 — `partials/inline_edit_widget.php`

전역 네임스페이스 `window.LcInlineEdit` 제공.

```js
window.LcInlineEdit = {
  // li: 대상 <li> element, item: {id,name_en,name_ko}, type:'brand'|'category'
  // csrf: 토큰, onSaved(updatedItem): 저장 성공 콜백
  attach: function (opts) {
    var li = opts.li, item = opts.item;
    // 1) 라벨 span + 우측 연필 버튼(.lc-ie-edit) 렌더
    // 2) 연필 mousedown: e.preventDefault()+e.stopPropagation() → 항목 선택(sel) 차단
    //    → li 내용을 편집 폼으로 치환: [en input][ko input][저장][취소]
    // 3) 저장: name_en 비면 차단/표시. fetch(LC_BASE+'/ajax/quick_update.php')
    //    성공 → item.name_en/ko 갱신 → opts.onSaved(item) 호출
    // 4) 취소/ESC: 편집 폼 제거, 원래 라벨 span 복원
  }
};
```

### 4.1 이벤트 충돌 방지 (F-06)
- 연필 버튼과 편집 폼 내부 요소는 모두 `mousedown`에서 `preventDefault()`+`stopPropagation()` → 위젯의 li 선택 핸들러 및 검색창 `blur` 닫힘 타이머가 트리거되지 않음.
- 편집 폼이 떠 있는 동안 드롭다운은 닫히지 않아야 함(blur 가드는 기존 위젯의 150ms 타이머를 stopPropagation으로 회피).

### 4.2 LC_BASE / CSRF
- `LC_BASE`는 각 페이지 전역에 존재. 헬퍼는 `attach` 시 `csrf`를 인자로 받음(페이지의 `input[name=csrf_token]` 값).

---

## 5. 위젯 통합 (호출부 변경)

### 5.1 inbound_add.php — `makeW.render()`
기존 forEach에서 li 생성 직후:
```js
_filt.forEach(function (i) {
  var li = document.createElement('li');
  ... // 기존 li 구성 (textContent → 라벨 span으로 분리)
  li.addEventListener('mousedown', function(e){ e.preventDefault(); sel(i.id, lb(i)); });
  LcInlineEdit.attach({
    li: li, item: i, type: cfg.type, csrf: csrfToken,
    onSaved: function (u) {
      // 로컬 data 항목은 동일 참조(i)이므로 이미 갱신됨
      if (_sid === String(u.id)) { sEl.value = lb(u); hEl.value = u.id; } // 선택중이면 라벨 동기화
      render(sEl.value); // 재렌더
    }
  });
  lEl.appendChild(li);
});
```
- `makeW` 호출에 `type:'brand'|'category'` 추가 (cfg에 type 필드 신설).

### 5.2 temp_inbound_add.php — 동일 `makeW`
inbound_add.php와 동일 변경 적용 (코드 동일).

### 5.3 products.php — `searchWidget`
같은 패턴으로 li 렌더 시 `attach()` 호출, `onSaved`에서 로컬 배열 항목 갱신 + 재렌더.

### 5.3b product_add.php — `makeSearchWidget`
products.php와 동일 구조의 커스텀 위젯(`brandList`/`catList`). 동일 패턴 적용.
단, 위젯 IIFE에 csrf JS 변수가 없어 `document.querySelector('input[name="csrf_token"]')`로 CSRF를 읽어 사용.
※ (정정) 초기 조사에서 네이티브 `<select>`로 분류했으나 실제 커스텀 위젯이므로 In Scope에 포함.

### 5.5 product_edit.php — 네이티브 `<select>` → 커스텀 위젯 교체
유일하게 네이티브 `<select>`(brandSelect/catSelect)를 쓰던 화면. 인라인 수정을 위해
product_add.php와 동일한 커스텀 검색 위젯(hidden + text + `<ul>`)으로 마크업·JS 교체.
- 기존 `+` 추가 버튼은 공통 모달(`modal_brand_cat.php`)의 `openQuickCreate` 유지 → `onQuickCreateSuccess`로 위젯에 반영
- 현재 저장된 brand/category는 JS `select()`로 미리 선택
- form 레벨 Enter 핸들러와 충돌 방지 위해 위젯 Enter 처리에 `stopPropagation` 추가
- name 속성(brand_id/category_id) 유지 → 기존 POST 호환

### 5.4 include
각 화면 하단(또는 modal_brand_cat.php 인근)에 1회:
```php
<?php require __DIR__ . '/partials/inline_edit_widget.php'; ?>
```
- inbound_add.php / temp_inbound_add.php / products.php 3곳 include. 중복 include 방지 가드(`if (!defined(...))`)는 헬퍼 내부에서 `window.LcInlineEdit` 존재 검사로 처리.

---

## 6. 데이터 모델

변경 없음. 기존 컬럼만 사용.
```
lc_brands     (id, name_en, name_ko, ...)
lc_categories (id, name_en, name_ko, ...)
```

---

## 7. 엣지 케이스

| 케이스 | 처리 |
|--------|------|
| 영문 빈 값 저장 | 차단 + 인라인 오류 표시, 포커스 이동 |
| 저장 중 네트워크 오류 | 오류 표시, 편집 모드 유지 |
| 수정한 항목이 현재 선택된 항목 | onSaved에서 검색 입력칸 라벨 동기화 |
| 편집 중 연필/입력 클릭이 항목 선택으로 오인 | mousedown preventDefault+stopPropagation |
| 같은 위젯에서 여러 li 동시 편집 | attach는 li 단위, 새 편집 진입 시 기존 편집 폼은 그대로(단순화) 또는 진입 시 취소 — 진입 시 기존 열림 폼 닫기 권장 |

---

## 8. 테스트 계획

- **L1 (API)**: `quick_update.php` — 정상 수정 200/`success:true`; 빈 name_en → `success:false`; 잘못된 type → 실패; 잘못된 CSRF → 보안 오류.
- **L2 (UI)**: 드롭다운 연필 클릭 → 편집 폼 표시 → 이름 변경·저장 → 라벨 갱신 확인. 취소/ESC → 원복.
- **L3 (E2E)**: inbound_add 입력 도중 브랜드 이름 수정 → 폼 데이터 유지 + 수정 반영. 3개 화면 동일 동작.

---

## 9. Success Criteria 매핑

| SC | 구현 위치 |
|----|----------|
| SC-1 | §3 quick_update.php + §4 attach 저장 |
| SC-2 | §5 onSaved 라벨/검색칸 동기화 |
| SC-3 | §4 영문 필수 검증 + §3 서버 검증 |
| SC-4 | §4.1 이벤트 충돌 방지 + 취소/ESC |
| SC-5 | §5.1~5.3 3개 화면 동일 적용 |

---

## 10. 구현 순서

1. `ajax/quick_update.php` 작성 (백엔드 먼저, 단독 테스트 가능)
2. `partials/inline_edit_widget.php` 공통 헬퍼 작성
3. `inbound_add.php` 통합 (헬퍼 include + makeW 호출부)
4. `temp_inbound_add.php` 동일 적용
5. `products.php` searchWidget 적용
6. 3개 화면 수동 검증

---

## 11. Implementation Guide

### 11.1 신규 파일
- `logistics/ajax/quick_update.php`
- `logistics/partials/inline_edit_widget.php`

### 11.2 수정 파일
- `logistics/inbound_add.php` (include 1줄 + makeW render/cfg)
- `logistics/temp_inbound_add.php` (동일)
- `logistics/products.php` (searchWidget render)

### 11.3 Session Guide

| Module | 범위 | 파일 |
|--------|------|------|
| module-1 (backend) | AJAX 수정 엔드포인트 | quick_update.php |
| module-2 (helper) | 공통 인라인 편집 헬퍼 | inline_edit_widget.php |
| module-3 (integrate) | 3개 위젯 호출부 통합 | inbound_add / temp_inbound_add / products |

권장 세션: module-1+2 (1세션), module-3 (1세션).

---

## 12. 다음 단계

`/pdca do brand-cat-inline-edit` — 구현 시작 (또는 `--scope module-1,module-2` 로 백엔드+헬퍼 먼저)
