# Plan: Office Menu Collapsible Sections

## Executive Summary

| Perspective | Summary |
|---|---|
| **Problem** | Office 사이드바 메뉴가 많은 항목을 가지고 있어 한 화면에 다 보이지 않음 |
| **Solution** | 메뉴 섹션별로 토글 기능(+/-) 추가하여 필요한 섹션만 표시 |
| **Function UX Effect** | 사용자가 자주 사용하는 섹션만 펼쳐두어 네비게이션 효율 증대 |
| **Core Value** | 사용자 경험 개선 및 화면 공간 활용도 증가 |

## Context Anchor

| Aspect | Details |
|---|---|
| **WHY** | Office 메뉴가 많은 항목(Sales, Finance, HR, Monthly Report, POS Data)으로 구성되어 한 화면에 표시되지 않음 → 스크롤 필요 |
| **WHO** | Office Management 시스템의 모든 사용자 |
| **RISK** | localStorage 저장 시 브라우저별/디바이스별 동기화 불가 (관리자 계정 변경 시 상태 초기화 필요) |
| **SUCCESS** | 메뉴 섹션 펼침/숨김 기능 동작, localStorage에 상태 저장, 기본값(Sales/Finance) 펼침 |
| **SCOPE** | office/partials/header.php의 메뉴 구조 수정 + JavaScript 토글 로직 |

## Requirements

### Functional Requirements
1. **섹션 토글 UI**
   - 각 섹션(Sales, Finance, HR, Monthly Report, POS Sales Data Upload) 제목 앞에 +/- 아이콘 추가
   - 아이콘 클릭 시 해당 섹션의 메뉴 항목들 펼침/숨김 처리

2. **기본 상태 설정**
   - Sales 섹션: 기본 펼침
   - Finance 섹션: 기본 펼침
   - HR, Monthly Report, POS Sales Data Upload: 기본 숨김

3. **상태 저장**
   - 사용자의 섹션 토글 상태를 localStorage에 `office_menu_state` 키로 저장
   - 브라우저 새로고침 후에도 상태 유지

### Non-Functional Requirements
1. **성능**: 토글 애니메이션은 부드러운 CSS transition 사용 (< 200ms)
2. **호환성**: 기존 admin 헤더와의 구조 호환성 유지
3. **접근성**: 토글 버튼에 title 속성으로 설명 추가

## Success Criteria

- [ ] SC1: 각 섹션 제목 앞에 +/- 아이콘 표시
- [ ] SC2: 아이콘 클릭 시 해당 섹션 메뉴 항목 펼침/숨김 처리
- [ ] SC3: 페이지 새로고침 후에도 토글 상태 유지
- [ ] SC4: Sales와 Finance 섹션은 기본값으로 펼침
- [ ] SC5: 토글 상태 변경 시 localStorage에 자동 저장
- [ ] SC6: 토글 에니메이션 (CSS transition) 적용

## Files to Create/Modify

| File | Action | Scope |
|---|---|---|
| `office/partials/header.php` | Modify | 섹션 제목에 토글 버튼 추가, data-section 속성 추가 |
| `office/partials/header.php` (script section) | Add | localStorage 읽기/쓰기 및 토글 로직 JavaScript 추가 |
| `admin/css/style.css` | Add | 섹션 메뉴 숨김 상태 CSS 추가 (.menu-collapsed) |

## Implementation Order

1. **Phase 1**: office/partials/header.php의 메뉴 섹션 구조 수정
   - 각 `<p class="...">` 제목을 토글 가능하도록 변경
   - data-section 속성 추가 (sales, finance, hr, monthly-report, pos-data)

2. **Phase 2**: CSS 스타일 추가
   - .menu-section-header 토글 버튼 스타일
   - .menu-collapsed 상태에서 다음 메뉴 항목들 숨김

3. **Phase 3**: JavaScript 토글 로직 추가
   - 토글 버튼 클릭 이벤트 리스너
   - localStorage 상태 저장/복원
   - 페이지 로드 시 저장된 상태 복원

## Risk & Mitigation

| Risk | Mitigation |
|---|---|
| localStorage 용량 초과 | 매우 작은 JSON (최소 100bytes) → 영향 없음 |
| 다중 탭 상태 동기화 | localStorage 변경 감지로 다른 탭에 반영 (선택사항) |
| 관리자 계정 변경 시 상태 혼동 | 사용자별 상태 저장 필요 시 향후 개선 |

## Notes

- 현재 간격 좁히기 작업(py-1.5→py-1) 이후 메뉴 화면 표시 개선됨
- 토글 기능 추가로 더욱 효율적인 메뉴 네비게이션 가능
- localStorage는 session이 아닌 persistent 저장으로 설정 (권장)
