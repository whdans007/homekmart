# Plan: 신선매입 등록 실시간 검색 키보드 선택
**Feature**: fresh-purchase-search-keyboard-nav
**Date**: 2026-09-06
**Phase**: Plan

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | `add_fresh_purchase_item.php`의 거래처/신선상품 실시간 검색 결과를 마우스 클릭으로만 선택 가능해 키보드만으로 입력을 이어갈 수 없음 |
| Solution | 검색 결과 목록에 방향키(↑/↓)로 하이라이트 이동, Enter로 선택, Esc로 닫기 기능을 공통 헬퍼로 추가 |
| Function/UX Effect | 타이핑 → 방향키 → Enter만으로 거래처/신선상품 선택이 끝나 마우스 이동 없이 입력 속도 향상 |
| Core Value | 반복적으로 매입을 등록하는 사용자의 입력 흐름을 끊지 않음 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| WHY | 실시간 검색 결과가 마우스 클릭 선택만 지원해 키보드 중심 입력 흐름이 끊김 |
| WHO | 신선매입 등록 화면(`add_fresh_purchase_item.php`)을 사용하는 관리자/스태프 |
| RISK | 기존 클릭 선택 로직(`renderSupplierResults`, `master-search` 렌더링)과 하이라이트 상태를 함께 관리해야 하며, 마우스 hover와 키보드 하이라이트가 충돌하지 않도록 처리 필요 |
| SUCCESS | 거래처 검색·신선상품 검색 두 곳 모두 ↑/↓/Enter/Esc로 마우스 없이 선택 가능 |
| SCOPE | `admin/add_fresh_purchase_item.php` 내 인라인 `<script>`만 수정. DB/AJAX 엔드포인트 변경 없음 |

---

## 1. 요구사항

### 1.1 기능 요구사항

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| F-01 | 거래처 검색(`#supplier-search`) 결과 목록에 키보드 방향키 탐색 추가 | 필수 |
| F-02 | 신선상품 검색(`#master-search`) 결과 목록에 키보드 방향키 탐색 추가 | 필수 |
| F-03 | ↓ 키: 다음 항목 하이라이트 (마지막 항목에서 ↓ 누르면 첫 항목으로 순환) | 필수 |
| F-04 | ↑ 키: 이전 항목 하이라이트 (첫 항목에서 ↑ 누르면 마지막 항목으로 순환) | 필수 |
| F-05 | Enter 키: 현재 하이라이트된 항목을 기존 클릭 선택과 동일하게 처리 | 필수 |
| F-06 | Esc 키: 결과 목록 닫기 | 필수 |
| F-07 | 마우스 hover 시에도 하이라이트 위치가 갱신되어야 함 (마우스/키보드 혼용 시 어색함 방지) | 선택 |
| F-08 | 점포상품(행별 `sp-search`) 검색창은 이번 범위에서 제외 | - |

### 1.2 비기능 요구사항

- 기존 `renderSupplierResults` / `master-search` input 이벤트의 AJAX·필터링 로직은 변경하지 않음
- 결과 목록 DOM 구조(`.client-result` 클래스, `data-idx`)를 그대로 유지해 기존 클릭 핸들러와 호환
- 신규 코드는 두 검색창에서 재사용 가능한 공통 헬퍼 함수로 작성 (중복 방지)

---

## 2. 범위

### In Scope
- `admin/add_fresh_purchase_item.php` — 인라인 `<script>` 내 `supplier-search`, `master-search` 키보드 이벤트 추가

### Out of Scope
- 행별 점포상품 검색(`spSearchInputEl`) — 사용자 확인 결과 이번 범위 제외
- 검색 API/필터링 로직 변경
- 다른 화면의 유사 검색 UI (필요 시 동일 헬퍼 재사용 가능하나 별도 작업)

---

## 3. 구현 계획

### Phase 1: 공통 키보드 탐색 헬퍼 작성
1. 결과 컨테이너(`supplierResults` / `masterResults`)와 항목 셀렉터(`.client-result`)를 받아 현재 하이라이트 인덱스를 관리하는 헬퍼 함수 작성 (예: `attachResultKeyboardNav(inputEl, resultsEl, onSelect)`)
2. 하이라이트 표시는 기존 `hover:bg-indigo-50`과 구분되는 클래스(예: `bg-indigo-100`) 토글로 처리
3. 렌더링 함수(`renderSupplierResults`, master-search의 결과 렌더링)가 실행된 직후 하이라이트 인덱스를 0으로 초기화

### Phase 2: 각 검색창에 적용
1. `supplierSearchInput`에 `keydown` 리스너 추가 → ↑/↓/Enter/Esc 처리, Enter 시 기존 `selectSupplier(list[idx])` 호출
2. `masterSearchInput`에 `keydown` 리스너 추가 → ↑/↓/Enter/Esc 처리, Enter 시 기존 `addMasterRow(matches[idx])` 호출
3. 결과 목록이 비어있거나(`hidden`) "검색 결과 없음" 안내만 있을 때는 Enter/방향키를 무시

### Phase 3: 마우스 병행 처리
1. `.client-result`에 `mouseenter` 리스너 추가해 hover 시 키보드 하이라이트 인덱스도 함께 갱신 (F-07)

---

## 4. 성공 기준

- [ ] 거래처 검색: 타이핑 → ↓/↑로 목록 이동 → Enter로 선택 시 클릭과 동일하게 거래처 칩 표시
- [ ] 신선상품 검색: 타이핑 → ↓/↑로 목록 이동 → Enter로 선택 시 클릭과 동일하게 행 추가
- [ ] Esc로 결과 목록이 닫힘
- [ ] 마우스 클릭 선택은 기존과 동일하게 계속 동작 (회귀 없음)
- [ ] 결과 없음 상태에서 방향키/Enter를 눌러도 오류 없이 무시됨
