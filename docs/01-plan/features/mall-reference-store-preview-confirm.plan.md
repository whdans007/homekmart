# Plan: 쇼핑몰 상품관리 "기준 점포" 임시조회 오조작 방지

**Feature**: mall-reference-store-preview-confirm
**Date**: 2026-09-23
**Phase**: Plan

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | `mall/admin/products.php`의 "기준 점포" 드롭다운을 바꾸면 저장 버튼 없이 즉시 페이지 전체가 새로고침되어 다른 점포의 큐레이션 목록으로 바뀐다. 관리자가 이를 "저장됨"으로 착각하고, 아직 큐레이션 안 된 점포로 전환되면 상품/이미지가 통째로 사라진 것처럼 보인다 |
| Solution | 드롭다운 `change` 이벤트에서 자동 네비게이션을 제거하고, 별도의 "이 점포로 조회" 버튼을 눌러야 임시 조회(쿼리파라미터 `store_id`)가 적용되도록 분리한다. 기존 "Reference Store 저장" 버튼(영구 저장)은 그대로 둔다 |
| UX Effect | 드롭다운 선택 자체는 아무 부작용이 없어지고, "조회"와 "저장"이 명확히 분리된 두 번의 명시적 클릭으로만 동작이 발생한다 |
| Core Value | 오조작으로 인한 "상품이 사라졌다"는 혼란 제거, 임시조회/영구저장 구분 명확화 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| WHY | 실사용자가 드롭다운만 바꾸고 저장 버튼을 누르지 않았는데도 "저장된 것 같다", "이미지가 안 보인다"고 혼동함. 실제로는 (1) change 이벤트가 즉시 페이지를 새로고침해 임시 조회로 들어가고 (2) 그 점포에 아직 큐레이션(mall_products)된 상품이 없어 목록이 비어 보였던 것. 백엔드 저장 로직(`lib/reference_store_service.php`)은 직접 검증 결과 정상 |
| WHO | 쇼핑몰 상품 큐레이션을 관리하는 admin (mall_management 권한) |
| RISK | 드롭다운 change 리스너를 건드리면서 기존 "임시 조회" 자체 기능(다른 점포 카테고리 큐레이션 보기)을 깨뜨리면 안 됨 — 그대로 동작은 유지하고 트리거 방식만 바꾼다 |
| SUCCESS | 드롭다운만 바꿔서는 아무 일도 안 일어나고, "조회" 버튼을 눌러야 `?store_id=` 임시 조회로 이동한다. 기존 "저장" 버튼 동작(영구 저장)은 그대로 유지된다 |
| SCOPE | `mall/admin/products.php`의 드롭다운/버튼 마크업 + JS 이벤트 핸들러만 수정. 서버 로직(`ajax/save_reference_store.php`, `lib/reference_store_service.php`) 변경 없음 |

---

## 1. 요구사항

### 1.1 기능 요구사항

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| F-01 | `#store-select`의 `change` 이벤트에서 자동 `window.location.href` 네비게이션을 제거한다 | 필수 |
| F-02 | 드롭다운 옆에 "이 점포로 조회" 버튼을 추가하고, 클릭 시에만 기존처럼 `products.php?store_id=X`(현재 쿼리 유지)로 이동한다 (`mall/admin/products.php:1239-1244`의 기존 로직을 버튼 클릭 핸들러로 이동) | 필수 |
| F-03 | 드롭다운 선택값이 현재 조회 중인 점포(`$selected_store_id`)와 같으면 "조회" 버튼을 비활성화한다 (불필요한 새로고침 방지) | 선택 |
| F-04 | 기존 "Reference Store 저장" 버튼과 그 옆 안내 텍스트("현재 저장값" / "임시 조회 중")는 그대로 유지한다 | 필수 |
| F-05 | 저장 버튼 클릭 시 저장 성공 후 이동하는 기존 로직(`products.php:1266-1269`)은 변경하지 않는다 | 필수 |

### 1.2 비기능 요구사항

- 서버 쿼리(`WHERE mp.store_id = ?` 등 큐레이션 필터링)는 이번 범위에서 변경하지 않는다 — "조회 중인 점포에 큐레이션된 상품만 보인다"는 기존 동작 자체는 그대로 둔다.
- 다국어(`t('mall_admin.products.reference_store')` 등) 라벨 패턴을 따라 새 버튼 텍스트도 `lang/ko.json`/`lang/en.json`에 키 추가.
- 기존 코드 스타일(주석 없이 작성, 필요한 경우만 "왜"를 한 줄 주석) 유지.

---

## 2. 범위

### In Scope
- `mall/admin/products.php` — 상단 "기준 점포" 영역 마크업 + JS(`storeSelect` change/조회 버튼 핸들러)
- `lang/ko.json`, `lang/en.json` — 신규 버튼 라벨 키 1개 추가

### Out of Scope
- `ajax/save_reference_store.php`, `lib/reference_store_service.php` — 검증 결과 정상, 변경 불필요
- `mall_products.store_id` 기준 큐레이션 필터링 로직 자체 (여러 점포가 각자 다른 상품을 큐레이션하는 기존 설계는 유지)
- 이미지 저장/조회 구조 (`mall_product_images`는 이미 점포 무관 공용 — 변경 불필요)

---

## 3. 현재 구조 분석

- `mall/admin/products.php:562-577` — 기준 점포 select + 저장 버튼 + 상태 텍스트 마크업
- `mall/admin/products.php:1235-1275` — JS: `storeSelect` change 리스너가 즉시 네비게이션(문제 지점), `saveReferenceStoreButton` click 리스너가 실제 저장(`ajax/save_reference_store.php` POST) 후 이동
- `mall/admin/products.php:406-408, 441` — 중앙 큐레이션 목록이 `mp.store_id = 선택한 점포` 로 필터링됨 (설계상 의도된 동작, 변경 안 함)
- `lib/reference_store_service.php::reference_store_set()` — CLI로 직접 호출해 정상 동작 확인 완료 (system_settings 갱신 + history 기록)

---

## 4. Success Criteria

- SC-1: 드롭다운에서 다른 점포를 선택해도 페이지가 바뀌지 않는다 (URL 그대로).
- SC-2: "이 점포로 조회" 버튼을 눌러야 `products.php?store_id=X`로 이동하고, 그 점포 기준 큐레이션 목록이 보인다.
- SC-3: 드롭다운 선택값을 저장된 값(`$reference_store_id`)으로 되돌리면 조회 버튼이 비활성화된다(F-03).
- SC-4: "Reference Store 저장" 버튼 클릭 시 기존과 동일하게 영구 저장되고, 저장 후 그 점포로 이동한다.
- SC-5: 새로고침/다른 화면 이동 후 돌아오면 실제 저장된 기준 점포가 기본으로 표시된다 (기존 동작 유지).

---

## 5. 리스크 & 대응

| 리스크 | 대응 |
|--------|------|
| 조회 버튼 추가로 기존 "임시 조회 중" 안내 문구/로직과 충돌 | 기존 `$selected_store_id !== $reference_store_id` PHP 조건 그대로 재사용, JS만 트리거 방식 변경 |
| 카테고리/홈노출 등 다른 필터가 걸린 상태에서 조회 버튼 클릭 시 쿼리파라미터 유실 | 기존 change 핸들러처럼 `URLSearchParams(window.location.search)`로 현재 쿼리 유지한 채 `store_id`만 교체 |

---

## 6. 다음 단계

Codex가 이 Plan 문서를 스펙으로 구현 (`docs/00-conventions/agent-orchestration.md` 절차: 워크트리 `codex/mall-reference-store-preview-confirm`에서 작업 → Claude Code가 `/code-review` 후 병합).
