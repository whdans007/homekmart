# Plan: 브랜드/카테고리 드롭다운 인라인 수정

**Feature**: brand-cat-inline-edit
**Date**: 2026-06-17
**Phase**: Plan

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | 입고 등록 화면의 브랜드/카테고리 검색 드롭다운은 선택과 신규 등록만 가능하고, 오타·표기 변경 시 별도 관리 페이지(brand_manage/category_manage)로 이동해야 함 → 입력 흐름 단절 |
| Solution | 드롭다운 리스트 각 항목에 연필 아이콘을 두고, 클릭 시 그 자리에서 영문/한글 이름을 인라인 편집·저장 (AJAX) |
| UX Effect | 입고 입력 도중 페이지 이탈 없이 브랜드/카테고리 이름을 즉석 수정, 작성 중이던 폼 데이터 보존 |
| Core Value | 마스터 데이터 정정을 작업 흐름 안에서 인라인 완결 (기존 빠른 "등록" 패턴의 "수정" 확장) |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| WHY | 브랜드/카테고리 이름에 오타나 표기 변경이 필요해도 별도 관리 페이지로 이동해야 해서 입고 입력이 끊김. 기존엔 "추가"만 인라인 가능 |
| WHO | 물류센터 직원 (inbound_add.php / temp_inbound_add.php / products.php 사용자) |
| RISK | 위젯 JS(`makeW`/`searchWidget`)가 3개 파일에 중복 존재 → 동일 변경을 반복 적용해야 함. 수정 시 다른 곳에서 이미 선택된 라벨/캐시 불일치 가능 |
| SUCCESS | 드롭다운 항목 연필 클릭 → 인라인 입력란 전환 → 저장 시 DB 반영 + 리스트 라벨 즉시 갱신, 페이지 새로고침 없음 |
| SCOPE | 커스텀 `<ul>` 드롭다운을 쓰는 화면의 위젯 UI 확장 + 신규 AJAX 수정 엔드포인트 1개. DB 스키마/기존 등록 로직 변경 없음 |

---

## 1. 요구사항

### 1.1 기능 요구사항

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| F-01 | 브랜드/카테고리 드롭다운 리스트의 각 항목 우측에 수정(연필) 아이콘 표시 | 필수 |
| F-02 | 연필 클릭 시 해당 행이 인라인 편집 모드로 전환 (영문/한글 입력란 + 저장/취소) | 필수 |
| F-03 | 저장 시 AJAX로 DB 업데이트 후 로컬 목록(`_inbBrands`/`_inbCats` 등) 라벨 갱신 | 필수 |
| F-04 | 영문 이름 필수 검증 (빈 값 저장 차단) | 필수 |
| F-05 | 취소/ESC 시 편집 모드 종료, 기존 라벨 복원 | 필수 |
| F-06 | 편집 중에는 해당 항목 선택(mousedown) 동작 비활성화 — 편집/선택 충돌 방지 | 필수 |
| F-07 | 이미 선택돼 있던 항목을 수정하면 검색 입력칸 라벨도 동기화 | 선택 |

### 1.2 비기능 요구사항

- 삭제는 범위 제외 (사용자 확정: "수정만")
- 영문 필수, 한글 선택 (기존 quick_create 규칙과 동일)
- 저장 성공 시 페이지 새로고침 없이 인라인 처리
- CSRF 토큰 검증 (quick_create.php와 동일 방식)
- 권한: `lc_require_staff()` (기존 엔드포인트와 동일)

---

## 2. 범위

### In Scope
- `logistics/ajax/quick_update.php` — **신규** AJAX (브랜드/카테고리 이름 수정)
- `logistics/inbound_add.php` — `makeW` 위젯에 인라인 수정 UI 추가
- `logistics/temp_inbound_add.php` — 동일 `makeW` 위젯에 동일 적용
- `logistics/products.php` — 자체 `searchWidget`에 동일 패턴 적용

### Out of Scope
- 삭제 기능
- 네이티브 `<select>` 기반 화면(`product_edit.php`, `product_add.php`) — 드롭다운 인라인 편집 구조가 아니라 기존 관리 페이지/모달 영역
- 관리 페이지(`brand_manage.php`/`category_manage.php`) UI 변경 (이미 폼 기반 수정 존재)
- DB 스키마 변경

---

## 3. 현재 구조 분석

### 3.1 위젯 종류
| 화면 | 위젯 | 리스트 DOM | 데이터 소스 | 등록 트리거 |
|------|------|-----------|-------------|------------|
| inbound_add.php | `makeW` (line ~2176) | `<ul id="inbRegBrandList">` / `inbRegCatList` | `_inbBrands` / `_inbCats` | `openInbRegQuick` → quick_create.php |
| temp_inbound_add.php | `makeW` (line ~1572) | 동일 | 동일 | 동일 |
| products.php | `searchWidget` (line ~778) | `<ul id="regBrandList">` / `regCatList` | 자체 배열 | 자체 모달 |
| product_edit.php / product_add.php | 네이티브 `<select>` + `partials/modal_brand_cat.php` | `<select id="brandSelect">` | DOM options | `openQuickCreate` |

### 3.2 백엔드
- `logistics/ajax/quick_create.php` — INSERT `lc_brands`/`lc_categories` (`type`,`name_en`,`name_ko`,`csrf_token`). 수정용 엔드포인트는 **없음**.
- `brand_manage.php` / `category_manage.php` — 폼 POST `action=edit` → `UPDATE lc_brands/lc_categories SET name_en=?, name_ko=? WHERE id=?` (AJAX 아님, 재사용 불가)

### 3.3 핵심 제약
- 위젯 JS가 파일별로 **중복 복제**되어 있음 → 공통 적용 시 동일 변경을 3곳에 반영하거나 공통 JS로 추출 필요 (Design 단계에서 결정)

---

## 4. 신규 AJAX 계약 (quick_update.php)

**Request** (POST):
```
type        : 'brand' | 'category'
id          : int (수정 대상 id)
name_en     : string (필수)
name_ko     : string (선택, 빈값이면 NULL)
csrf_token  : string
```

**Response**:
```json
{ "success": true, "id": 12, "name_en": "NONGSHIM", "name_ko": "농심" }
{ "success": false, "message": "..." }
```

- 검증: CSRF, `type` 화이트리스트, `id>0`, `name_en` 비어있지 않음
- 권한: `lc_require_staff()`

---

## 5. Success Criteria

- SC-1: 드롭다운 항목 연필 클릭 → 인라인 입력란 전환 → 저장 시 `quick_update.php` 호출되어 DB의 `name_en`/`name_ko`가 갱신된다.
- SC-2: 저장 성공 후 드롭다운 라벨과 (선택돼 있던 경우) 검색 입력칸이 새 이름으로 즉시 갱신된다 (새로고침 없음).
- SC-3: 영문 이름이 빈 값이면 저장이 차단되고 오류가 표시된다.
- SC-4: 취소/ESC 시 편집이 취소되고 원래 라벨이 복원되며, 편집 중 항목 선택이 트리거되지 않는다.
- SC-5: 위젯을 쓰는 3개 화면(inbound_add, temp_inbound_add, products)에서 동일하게 동작한다.

---

## 6. 리스크 & 대응

| 리스크 | 대응 |
|--------|------|
| 위젯 JS 3곳 중복 → 변경 누락 | Design에서 공통 JS 추출안 vs 개별 반영안 비교 후 결정 |
| 편집 아이콘 클릭이 항목 선택(blur/mousedown)으로 오인 | 편집 진입 시 mousedown preventDefault + 위젯 blur 타이머 가드 |
| 수정한 이름이 다른 행에 이미 선택된 상태 | 저장 후 로컬 데이터 갱신 + 해당 위젯 라벨 재동기화 |
| CSRF/권한 누락 | quick_create.php 패턴 그대로 적용 |

---

## 7. 다음 단계

`/pdca design brand-cat-inline-edit` — 위젯 공통화 여부(3안 비교) 및 인라인 편집 DOM/이벤트 설계
