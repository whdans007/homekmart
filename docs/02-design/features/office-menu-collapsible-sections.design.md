# Design: Office Menu Collapsible Sections

## Context Anchor

| Aspect | Details |
|---|---|
| **WHY** | Office 메뉴가 많은 항목으로 구성되어 한 화면에 표시되지 않음 → 스크롤 필요 → 사용성 저하 |
| **WHO** | Office Management 시스템의 모든 사용자 (직원 일정 관리, 재무/영수증 처리 등) |
| **RISK** | localStorage 저장 시 디바이스/계정 변경 시 상태 초기화 가능 |
| **SUCCESS** | 토글 기능 동작, localStorage 상태 저장, 기본값 적용 |
| **SCOPE** | office/partials/header.php + admin/css/style.css + inline JavaScript |

## 1. Overview

**Objective**: Office 사이드바 메뉴의 섹션별 토글 기능 구현으로 사용자 경험 개선

**Key Features**:
- 섹션 제목에 +/- 토글 버튼 추가
- localStorage를 통한 상태 저장
- 기본 상태: Sales, Finance 펼침 / HR, Monthly Report, POS Data 숨김
- 부드러운 CSS transition 애니메이션

## 2. Architecture Options

### Option A: Vanilla JavaScript + localStorage (Minimal Changes)
**Approach**: 현재 header.php 구조를 최소한 변경하고, 순수 JavaScript로 토글 로직 구현

**Pros**:
- 기존 코드 변경 최소화
- 의존성 없음
- 빠른 구현

**Cons**:
- JavaScript가 많아짐
- 유지보수 시 찾기 어려움

---

### Option B: PHP로 섹션 래핑 (Clean Architecture)
**Approach**: 각 섹션을 PHP 함수로 래핑하여 섹션 단위로 관리

```php
function render_menu_section($title, $section_key, $is_expanded = false, $items = []) {
    // 섹션 헤더 + 메뉴 항목 배열
}
```

**Pros**:
- 코드 재사용성 높음
- 섹션 추가/수정이 쉬움
- 로직 일관성 유지

**Cons**:
- header.php 리팩토링 필요
- 테스트 필요

---

### Option C: Pragmatic Balance (Recommended) ✅
**Approach**: 최소한의 PHP 수정 + data 속성 추가 + vanilla JavaScript

**Structure**:
- 각 섹션 `<p>` 태그에 data-section 속성 추가
- 바로 다음의 nav_link들을 자동으로 그룹화 (DOM 구조 활용)
- 토글 클릭 시 `data-section` 값으로 관련 항목들 찾아 숨김

**Pros**:
- 기존 코드 구조 유지
- 간단한 수정만 필요
- 유지보수 용이

**Cons**:
- DOM 구조에 의존

---

## 3. Selected Architecture: Option C (Pragmatic Balance)

이유:
- 기존 header.php의 구조를 최대한 유지
- 새로운 함수나 클래스 불필요
- 간단한 data 속성과 JavaScript로 구현 가능

## 4. API Contract & Data Structure

### localStorage 상태 포맷
```json
{
  "office_menu_state": {
    "sales": true,        // 펼침
    "finance": true,      // 펼침
    "hr": false,          // 숨김
    "monthly-report": false,
    "pos-data": false
  }
}
```

### Default State (초기값)
```javascript
const DEFAULT_MENU_STATE = {
  "sales": true,
  "finance": true,
  "hr": false,
  "monthly-report": false,
  "pos-data": false
};
```

## 5. Component Design

### Markup Changes
```php
<!-- Before -->
<p class="px-3 pt-1 pb-0 text-xs font-semibold text-gray-400 uppercase tracking-wider">Sales</p>

<!-- After -->
<p class="px-3 pt-1 pb-0 text-xs font-semibold text-gray-400 uppercase tracking-wider 
      cursor-pointer hover:bg-gray-100 rounded menu-section-header" 
   data-section="sales" 
   title="Click to toggle">
  <i class="fa-solid fa-chevron-down inline mr-2 transition-transform menu-toggle-icon"></i>Sales
</p>
```

### CSS Styles
```css
/* Toggle button styling */
.menu-section-header {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  cursor: pointer;
  padding: 0.25rem 0.75rem !important;
  border-radius: 0.375rem;
  transition: background-color 200ms ease;
}

.menu-section-header:hover {
  background-color: rgba(156, 163, 175, 0.15);
}

/* Icon rotation when collapsed */
.menu-section-header[data-collapsed="true"] .menu-toggle-icon {
  transform: rotate(-90deg);
}

/* Menu items visibility */
.menu-item {
  transition: max-height 200ms ease, opacity 200ms ease, padding 200ms ease;
}

.menu-item[data-section-collapsed="true"] {
  max-height: 0;
  opacity: 0;
  padding: 0 !important;
  overflow: hidden;
}
```

## 6. State Management

### Initialization Flow
```
1. 페이지 로드
   ↓
2. localStorage에서 office_menu_state 읽기
   ↓
3. 없으면 DEFAULT_MENU_STATE 사용
   ↓
4. 각 섹션에 data-collapsed 속성 설정
   ↓
5. data-section-collapsed 속성으로 메뉴 항목 숨김 처리
```

### Toggle Flow
```
1. 섹션 헤더 클릭
   ↓
2. data-section 값으로 상태 토글 (true ↔ false)
   ↓
3. localStorage 업데이트
   ↓
4. DOM 업데이트 (아이콘 회전 + 메뉴 항목 숨김)
```

## 7. Implementation Details

### File: office/partials/header.php

**Changes**:
1. 각 섹션 `<p>` 태그:
   - `class` 추가: `cursor-pointer hover:bg-gray-100 rounded menu-section-header`
   - `data-section` 속성 추가 (sales, finance, hr, monthly-report, pos-data)
   - `title` 속성 추가: "Click to toggle"
   - 제목 앞에 `<i class="menu-toggle-icon">` 아이콘 추가

2. 각 `nav_link` 호출:
   - `data-section` 속성 추가 (부모 섹션 정보)
   - `class menu-item` 추가

3. 스크립트 섹션:
   ```javascript
   <script>
   (function() {
     const DEFAULT_STATE = {
       sales: true,
       finance: true,
       hr: false,
       "monthly-report": false,
       "pos-data": false
     };
     
     // localStorage에서 상태 읽기
     function getMenuState() {
       const saved = localStorage.getItem('office_menu_state');
       return saved ? JSON.parse(saved) : DEFAULT_STATE;
     }
     
     // localStorage에 상태 저장
     function saveMenuState(state) {
       localStorage.setItem('office_menu_state', JSON.stringify(state));
     }
     
     // DOM 초기화
     function initializeMenu() {
       const state = getMenuState();
       Object.entries(state).forEach(([section, isExpanded]) => {
         const header = document.querySelector(`[data-section="${section}"]`);
         if (!header) return;
         
         header.setAttribute('data-collapsed', !isExpanded);
         updateMenuItems(section, isExpanded);
       });
     }
     
     // 메뉴 항목 표시/숨김
     function updateMenuItems(section, isExpanded) {
       const items = document.querySelectorAll(`[data-section-parent="${section}"]`);
       items.forEach(item => {
         item.setAttribute('data-section-collapsed', !isExpanded);
       });
     }
     
     // 토글 클릭 이벤트
     document.addEventListener('click', (e) => {
       const header = e.target.closest('.menu-section-header');
       if (!header) return;
       
       const section = header.getAttribute('data-section');
       const state = getMenuState();
       state[section] = !state[section];
       
       saveMenuState(state);
       header.setAttribute('data-collapsed', !state[section]);
       updateMenuItems(section, state[section]);
     });
     
     // 페이지 로드 시 초기화
     if (document.readyState === 'loading') {
       document.addEventListener('DOMContentLoaded', initializeMenu);
     } else {
       initializeMenu();
     }
   })();
   </script>
   ```

### File: admin/css/style.css

**Additions**:
```css
/* 섹션 헤더 */
.menu-section-header {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  cursor: pointer;
  padding: 0.75rem;
  border-radius: 0.375rem;
  transition: background-color 200ms ease;
}

.menu-section-header:hover {
  background-color: rgba(156, 163, 175, 0.15);
}

/* 토글 아이콘 회전 */
.menu-toggle-icon {
  display: inline-block;
  width: 1rem;
  height: 1rem;
  transition: transform 200ms ease;
  margin-right: 0.25rem;
}

.menu-section-header[data-collapsed="true"] .menu-toggle-icon {
  transform: rotate(-90deg);
}

/* 메뉴 항목 숨김 */
.menu-item {
  transition: max-height 200ms ease, opacity 200ms ease, padding 200ms ease;
  max-height: 2.5rem;
  opacity: 1;
}

.menu-item[data-section-collapsed="true"] {
  max-height: 0;
  opacity: 0;
  padding: 0 !important;
  margin: 0 !important;
  overflow: hidden;
  pointer-events: none;
}
```

## 8. Test Scenarios

### L1: 토글 버튼 기능
- [ ] 각 섹션 헤더 클릭 시 아이콘 회전
- [ ] 각 섹션 헤더 클릭 시 메뉴 항목 펼침/숨김
- [ ] 아이콘 방향: 펼침 (↓), 숨김 (→)

### L2: 상태 저장
- [ ] localStorage에 office_menu_state 저장 확인
- [ ] 페이지 새로고침 후 이전 상태 유지 확인
- [ ] 기본값 적용 확인 (Sales, Finance 펼침)

### L3: 사용성
- [ ] 부드러운 애니메이션 확인 (transition)
- [ ] 호버 상태에서 배경색 변경 확인
- [ ] 마우스 커서가 pointer로 변경 확인

## 9. Browser Compatibility

- Chrome/Edge 90+: ✅ 완전 지원 (localStorage, CSS transitions)
- Firefox 88+: ✅ 완전 지원
- Safari 14+: ✅ 완전 지원

## 10. Rollback Plan

- localStorage의 `office_menu_state` 삭제하면 기본값으로 복원
- 기존 header.php 구조 유지 → data 속성만 무시되면 정상 작동

## 11. Implementation Guide

### Session 1: HTML 마크업 수정
1. office/partials/header.php 오픈
2. 각 섹션 `<p>` 태그 수정
3. 각 nav_link에 data-section-parent 속성 추가
4. 토글 아이콘 HTML 추가

### Session 2: CSS & JavaScript 추가
1. admin/css/style.css에 스타일 추가
2. header.php의 `</body>` 앞에 JavaScript 스크립트 추가
3. localStorage 기본값 설정

### Session 3: 테스트 & 검증
1. 각 섹션 토글 기능 테스트
2. localStorage 상태 확인
3. 브라우저 호환성 테스트
