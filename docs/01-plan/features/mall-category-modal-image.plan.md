# Plan: 카테고리 관리 모달 UI 개선 + 대분류 이미지 앱 메인화면 노출

**Feature**: mall-category-modal-image
**Date**: 2026-09-23
**Phase**: Plan

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | (1) `products.php`의 "카테고리 관리" 모달이 좁아서 편집하기 불편하고, 한글명 입력칸은 넓은데 영문명 입력칸은 너무 좁음(반대가 더 실용적). (2) 대분류 카테고리에 이미지를 지정할 방법이 없어서 앱 메인화면 카테고리 타일이 "이름 첫 글자 + 고정 색상 원"으로만 표시됨 |
| Solution | 모달 폭/높이 확대 + 한글/영문 입력칸 비율 반전(한글 좁게, 영문 넓게). `categories.image_url` 컬럼 추가 후 대분류 행에만 이미지 업로드 UI 추가, 앱 메인화면 카테고리 타일에서 이미지가 있으면 이미지를, 없으면 기존 첫글자+색상 폴백을 표시 |
| UX Effect | 관리자는 더 넓은 화면에서 영문명 위주로 편하게 입력, 대분류별로 실제 상품 이미지/아이콘을 골라 앱 메인화면에 노출 가능 |
| Core Value | 카테고리 관리 편의성 + 앱 메인화면 시각적 완성도 향상 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| WHY | 사용자가 직접 요청: 모달이 좁고 한글칸이 너무 넓다, 영문칸을 넓혀달라. 대분류에 이미지를 넣어서 앱 메인화면 카테고리 아이콘으로 쓰고 싶다 |
| WHO | 카테고리를 관리하는 admin (`category_management` 권한), 앱 메인화면을 보는 쇼핑몰 고객 |
| RISK | `categories` 테이블은 몰 전용이 아니라 구매/재고/도매 등 전체 시스템이 공유(`save_category.php` 상단 주석 참고) — 이미지 컬럼 추가는 안전(nullable, 다른 모듈 영향 없음)하지만 업로드 UI/로직은 몰(`mall/`) 영역에만 추가하고 다른 모듈(logistics 등) 카테고리 화면은 건드리지 않는다. 소분류에는 이미지 UI를 노출하지 않는다(요청 범위가 "대분류"로 명시됨) |
| SUCCESS | 모달이 커지고 입력칸 비율이 반전됨. 대분류 행에서 이미지를 업로드하면 앱 메인화면(`mall/partials/home_body.php`) 카테고리 타일에 그 이미지가 보인다. 이미지가 없는 대분류는 기존 첫글자+색상 폴백 그대로 유지 |
| SCOPE | DB: `categories.image_url` 컬럼 추가(마이그레이션 1개). 백엔드: `mall/admin/ajax/save_category.php`에 업로드/삭제 액션 추가. 프론트: `mall/admin/products.php` 모달 마크업/CSS/JS, `mall/partials/home_body.php` 타일 렌더링, `mall/css/mall.css` 타일 스타일 |

---

## 1. 요구사항

### 1.1 기능 요구사항

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| F-01 | `#category-manage-modal`의 폭/높이를 확대한다 (`max-w-lg` → 더 넓게, 필요 시 `max-h-[85vh]`도 재검토) | 필수 |
| F-02 | 모달 내 모든 "한글명/영문명" 입력 쌍(대분류 행, 소분류 행, 소분류 추가 폼, 대분류 추가 폼 — 총 4곳)에서 한글명 input은 고정폭으로 좁히고, 영문명 input은 넓힌다(가변/flex-1 등) | 필수 |
| F-03 | `categories` 테이블에 `image_url` 컬럼 추가 (nullable, varchar) — idempotent 마이그레이션 스크립트로 | 필수 |
| F-04 | 카테고리 관리 모달의 **대분류 행에만** 썸네일 미리보기 + "이미지 업로드" 버튼(파일 input)을 추가한다. 소분류 행에는 추가하지 않는다 | 필수 |
| F-05 | 이미지 업로드 시 `mall/admin/ajax/save_category.php`에 새 액션(예: `upload_image`)으로 저장 — `save_retail_product.php`의 `upload_image`와 동일한 검증 규칙(JPG/PNG/WEBP/AVIF, 5MB 이하, 랜덤 파일명, `mall/uploads/categories/`에 저장) | 필수 |
| F-06 | 이미지 삭제(제거) 버튼 제공 — `categories.image_url`을 NULL로 비우고 기존 파일은 그대로 둔다(상품 이미지 삭제 로직처럼 파일까지 지울 필요는 없음, 단순화) | 필수 |
| F-07 | 앱 메인화면(`mall/partials/home_body.php`)의 카테고리 타일에서 `image_url`이 있으면 `<img>`로 표시(`object-fit:cover`, 기존 56×56px 원형 유지), 없으면 기존 첫글자+팔레트 색상 폴백 그대로 표시 | 필수 |
| F-08 | 권한: 업로드/삭제 모두 기존과 동일하게 `mall_management` + `category_management` 둘 다 확인 | 필수 |

### 1.2 비기능 요구사항

- 기존 카테고리 추가/수정/삭제(`add`/`bulk_edit`/`edit`/`delete`/`clear_and_delete`) 로직은 변경하지 않는다.
- 이미지 업로드는 즉시 반영(별도 "적용" 버튼 클릭 없이, 업로드 성공 시 바로 DB에 저장 — `save_retail_product.php`의 상품 이미지 업로드와 동일한 UX 패턴).
- 다국어 라벨(`lang/ko.json`/`lang/en.json`)에 새 문자열 키 추가.
- 마이그레이션 스크립트는 `sql/migrations/run_create_mall_reference_store_history_migration.php`와 동일한 패턴(웹 접속 + POST 확인 버튼 + OK/SKIP/ERROR 뱃지, 재실행해도 안전)으로 작성.

---

## 2. 범위

### In Scope
- `sql/migrations/run_add_categories_image_url_migration.php` — **신규** 마이그레이션 스크립트
- `mall/admin/products.php` — 카테고리 관리 모달 마크업(크기/입력칸 비율/이미지 업로드 UI) + JS(업로드/삭제 핸들러)
- `mall/admin/ajax/save_category.php` — `upload_image`/`remove_image` 액션 추가
- `mall/partials/home_body.php` — 카테고리 타일 렌더링에 이미지 분기 추가, SQL에 `image_url` 컬럼 SELECT 추가
- `mall/css/mall.css` — `.cat-tile .mark` 이미지 표시용 스타일 추가
- `lang/ko.json`, `lang/en.json` — 신규 라벨 키

### Out of Scope
- 소분류 카테고리 이미지 (요청 범위 아님)
- `mall/category.php`(카테고리 상세 페이지) 헤더 등 다른 화면에 이미지 노출 — 요청은 "앱 메인화면"으로 한정됨
- 다른 모듈(logistics 등)의 카테고리 관리 화면
- 이미지 크롭/리사이즈 UI (업로드 원본 그대로 저장, 표시는 CSS `object-fit:cover`로만 처리)

---

## 3. 현재 구조 분석

- `mall/admin/products.php:1073-1131` — 카테고리 관리 모달 전체 마크업. 대분류 행(1082-1092), 소분류 행(1096-1102), 소분류 추가 폼(1108-1112), 대분류 추가 폼(1123-1127) — 이름/영문명 input이 각각 존재하는 4곳
- `mall/admin/products.php:1653-1720`대 — `apply-category-edits-btn`(일괄 저장) JS, `ajax/save_category.php` 호출부 3곳(add-category-form, add-subcategory-form, apply-category-edits)
- `mall/admin/ajax/save_category.php` — `add`/`bulk_edit`/`edit`/`delete`/`clear_and_delete` 액션. 권한 체크(`mall_management`+`category_management`), CSRF 검증 패턴 확인됨
- `mall/admin/ajax/save_retail_product.php:236-282` — `upload_image` 액션 — mime 화이트리스트, 5MB 제한, `bin2hex(random_bytes(16))` 파일명, `move_uploaded_file()` — 그대로 재사용할 패턴
- `mall/partials/home_body.php:32-36` — 카테고리 그리드 주석 + SQL(`SELECT id, name, name_en FROM categories WHERE parent_id IS NULL ORDER BY sort_order, name`)
- `mall/partials/home_body.php:116-127` — `.cat-grid` 렌더링 루프, `$__tile_palette` 첫글자+색상 로직
- `mall/css/mall.css:119-133` — `.cat-grid`/`.cat-tile`/`.cat-tile .mark`(56×56px, `border-radius: var(--radius-xl)`) 스타일
- `categories` 테이블 현재 컬럼: `id, name, parent_id, sort_order, default_margin_rate, created_at, updated_at, name_en` — `image_url` 없음 (직접 DB 조회로 확인)
- 마이그레이션 스타일 참고: `sql/migrations/run_create_mall_reference_store_history_migration.php` (idempotent, 웹 UI 확인 버튼)

---

## 4. DB 마이그레이션 스펙

```sql
ALTER TABLE categories ADD COLUMN image_url VARCHAR(500) NULL DEFAULT NULL AFTER name_en;
```
- `SHOW COLUMNS FROM categories LIKE 'image_url'`로 이미 존재하면 SKIP 처리 (idempotent)
- 시드/백필 없음 (기존 행은 전부 NULL = 기존 폴백 그대로 유지)

---

## 5. 신규 AJAX 액션 (save_category.php에 추가)

**`upload_image`** (multipart/form-data POST):
```
action      : 'upload_image'
category_id : int (대분류 id, parent_id IS NULL인지 서버에서 검증)
image       : file (jpg/png/webp/avif, 5MB 이하)
csrf_token  : string
```
Response: `{ "success": true, "data": { "image_url": "uploads/categories/xxxx.jpg" } }`

- 검증: `category_id`가 실제로 존재하고 `parent_id IS NULL`인지 확인(소분류에 잘못 업로드 방지)
- 저장 경로: `mall/uploads/categories/` (신규 디렉터리, `save_retail_product.php`와 동일하게 `mkdir(..., 0755, true)`)
- 기존 이미지가 있으면 DB 값만 새 경로로 덮어씀 (이전 파일 삭제는 하지 않음 — 단순화, 디스크 정리는 범위 밖)

**`remove_image`**:
```
action      : 'remove_image'
category_id : int
csrf_token  : string
```
Response: `{ "success": true }` — `categories.image_url`을 NULL로 UPDATE

---

## 6. Success Criteria

- SC-1: 모달이 이전보다 눈에 띄게 넓어지고(예: `max-w-lg` → `max-w-2xl` 이상), 4곳의 입력칸 모두 한글명이 좁고 영문명이 넓게 바뀐다.
- SC-2: 대분류 행에만 이미지 업로드 버튼/미리보기가 보이고, 소분류 행에는 없다.
- SC-3: 대분류에 이미지를 업로드하면 즉시 모달 내 썸네일이 갱신되고, `products.php`를 새로고침해도 유지된다.
- SC-4: 이미지가 있는 대분류는 앱 메인화면(`homekmart.test/mall/`) 카테고리 타일에서 그 이미지가 보이고, 없는 대분류는 기존 첫글자+색상 원 그대로 보인다.
- SC-5: 이미지 제거 버튼을 누르면 DB의 `image_url`이 비워지고 타일이 다시 폴백 표시로 돌아간다.
- SC-6: 기존 카테고리 추가/수정/삭제 기능은 그대로 정상 동작한다(회귀 없음).

---

## 7. 리스크 & 대응

| 리스크 | 대응 |
|--------|------|
| `categories`가 다른 모듈과 공유 테이블이라 스키마 변경이 다른 곳에 영향 줄 수 있음 | nullable 컬럼 추가만 하고 기존 쿼리는 전혀 건드리지 않음 — `SELECT *`를 쓰는 다른 화면이 있다면 컬럼이 하나 늘어날 뿐 깨지지 않음 |
| 소분류에도 실수로 업로드 UI가 노출될 위험 | 서버에서 `parent_id IS NULL` 검증 + 프론트에서도 대분류 행 템플릿에만 마크업 추가 |
| 업로드 파일이 이미지가 아닌 파일일 위험 | `finfo`로 실제 mime 검사(확장자만 보지 않음) — 기존 상품 이미지 업로드와 동일 |
| 모달 확대로 작은 화면(모바일 admin)에서 레이아웃 깨짐 | `max-w-2xl` 등 관리자 데스크톱 기준으로 넓히되 `w-full`은 유지해서 작은 화면에서는 기존처럼 축소되게 함 |

---

## 8. 다음 단계

Codex가 이 Plan 문서를 스펙으로 구현 (`docs/00-conventions/agent-orchestration.md` 절차: 워크트리 `codex/mall-category-modal-image`에서 작업 → Claude Code가 로컬 DB에서 마이그레이션 실행 검증 + `/code-review` 후 병합).
