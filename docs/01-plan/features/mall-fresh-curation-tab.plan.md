# Plan: 몰 상품 검색에 신선상품 탭 추가 (카테고리 배정)

**Feature**: mall-fresh-curation-tab
**Date**: 2026-09-06
**Phase**: Plan

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | `mall/admin/products.php`의 "상품 검색 (전체 상품 대상)" 섹션은 일반상품(`products`/`mall_products`)만 검색·등록/이동할 수 있어, 몰 관리자가 신선상품(`mall_fresh_products`)을 몰 카테고리에 배정할 방법이 없음 |
| Solution | 검색 섹션에 "일반상품/신선상품" 탭을 추가. 신선상품 탭은 `admin/fresh_products.php`와 같은 소스(`mall_fresh_products`)를 검색하고, 선택한 좌측 카테고리에 등록/이동할 수 있게 한다 |
| UX Effect | 관리자가 한 화면(products.php)에서 일반상품과 신선상품 모두 검색해 원하는 몰 카테고리에 배정 가능 |
| Core Value | 신규 테이블/마이그레이션 없이 기존 미사용 컬럼(`mall_fresh_products.category_id`)을 재사용해 최소 변경으로 신선상품 카테고리 배정 기능 제공 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| WHY | 신선상품이 몰 카테고리 구조에 편입되어야 추후 몰 노출/정렬 관리가 가능한데, 현재는 신선상품 관리(`admin/fresh_products.php`)와 몰 카테고리 관리(`mall/admin/products.php`)가 완전히 분리되어 있음 |
| WHO | 몰 관리자 (`mall_management` 권한 보유자) |
| RISK | `mall_fresh_products.category_id`는 현재 어디서도 참조하지 않는 컬럼 — 재사용 의미를 명확히 문서화하지 않으면 향후 `mall-fresh-products.plan.md`(신선상품 100g 주문 기획, Draft) 구현 시 혼동 가능 |
| SUCCESS | 신선상품 탭에서 검색 → 좌측 선택 카테고리에 등록/이동 → `mall_fresh_products.category_id`에 반영 → 중앙 패널에서 배정 현황 확인/해제 가능 |
| SCOPE | `mall/admin/products.php` 검색 섹션 UI + 신규 AJAX 1개. `products`/`mall_products`/`inventory`/`purchase_items` 스키마 변경 없음. 고객 화면(몰 프론트) 변경 없음 |

---

## 0. 사전 조사 (완료)

- `mall/admin/products.php` 636~679행: 기존 "상품 검색 (전체 상품 대상)" 섹션. 일반상품 검색/등록(`add-btn`)/이동(`move-btn`) → `mall/admin/ajax/save_retail_product.php` (action=`add`/`move_category`).
- `admin/fresh_products.php`: `mall_fresh_products` 조회 (code/name_ko/name_en/fresh_category ENUM/sale_type/box 구성/최근 매입 원가/status).
- `mall_fresh_products.category_id` (FK → `categories`, nullable): 현재 **어떤 화면에서도 사용하지 않음** (grep 확인, `admin/fresh*.php` 무관련). 분류는 별도 `fresh_category` ENUM(`fruit`/`vegetable`/`meat`/`seafood`)이 담당.
- `categories` 테이블(`u622428657_homekmart.sql`)에는 `store_id`가 없음 — 전 점포 공용 글로벌 테이블. 일반상품도 `products.category_id`는 전역 값이며, 점포별로 다른 것은 `mall_products`(큐레이션 노출/가격)뿐. 따라서 `mall_fresh_products.category_id`를 전역 배정값으로 재사용해도 일반상품과 동일한 패턴이 유지됨 (Codex 자문에서 제기된 "카테고리가 점포별일 수 있다"는 우려는 스키마 확인 결과 해당 없음).
- `mall_fresh_products`는 `store_id` 개념이 없는 몰 전체 단일 카탈로그 — `mall_products`처럼 점포별 큐레이션 행이 필요 없음.
- 더 큰 초안 기획 `docs/01-plan/features/mall-fresh-products.plan.md`(고객 100g 주문/실측/COD 정산, Status: Draft, 미구현)와는 무관 — 이번 기능은 "관리자가 신선상품을 몰 카테고리에 배정"만 다룸.

---

## 1. 요구사항

### 1.1 기능 요구사항

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| F-01 | 검색 섹션에 "일반상품" / "신선상품" 탭 표시, `search_tab` GET 파라미터로 전환 (기존 홈 노출 슬롯 전환과 동일하게 서버 렌더링 방식) | 필수 |
| F-02 | 신선상품 탭: `mall_fresh_products`에서 `status='active'` AND (code/name_ko/name_en LIKE 검색어) 검색, 코드+이름/분류·판매방식/단가/액션 컬럼으로 표시 | 필수 |
| F-03 | 좌측에서 카테고리 미선택 시 기존과 동일한 경고 배너 + 액션 비활성화 | 필수 |
| F-04 | 검색 결과 액션: 미배정→"몰에 등록", 다른 카테고리 배정됨→"이동등록", 현재 카테고리와 동일→"이미 등록됨" (일반상품과 동일 패턴) | 필수 |
| F-05 | 등록/이동 액션은 신규 AJAX `mall/admin/ajax/save_fresh_curation.php` (action=`assign_category`) 호출, `mall_fresh_products.category_id` UPDATE | 필수 |
| F-06 | 좌측 카테고리 선택 시 배정된 신선상품을 **기존 "큐레이션된 상품" 테이블에 같은 컬럼 구조로 통합 표시** (별도 섹션 아님) — `row_type` 구분자로 렌더링, 해당 없는 컬럼(원가/기준도매가/재고/할인/정렬순서)은 "—" 표시, **상태(active/inactive) 무관하게 전부 노출** | 필수 (변경: 2026-09-06 — 최초 계획의 "별도 미니 섹션"에서 통합 테이블로 변경) |
| F-07 | 통합 테이블 행에서 "저장" 버튼으로 표시명(name_ko/name_en)·판매단가(price_per_100g)·노출상태(status)를 인라인 수정 (`save_fresh_curation.php` action=`update`) | 필수 |
| F-08 | 통합 테이블 행에서 "카테고리 해제"(category_id = NULL) 액션 → `save_fresh_curation.php` (action=`unassign_category`). 신선상품 행은 드래그 정렬 대상에서 제외(display_order 없음) | 필수 |
| F-09 | 탭 전환 시 검색어(`q`)/선택 카테고리(`cat_id`/`sub_id`)/기준 점포(`store_id`) 쿼리 파라미터 보존 | 필수 |
| F-10 | 한국어/영어 번역 키 쌍 추가 (`lang/ko.json`, `lang/en.json`) | 필수 |

### 1.2 비기능 요구사항

- 권한: 기존 페이지와 동일하게 `mall_management` (신선상품 관리 화면의 `product_management`와는 별개 — 몰 관리자 기준)
- CSRF 토큰 검증: `mall/lib/csrf.php`의 `mall_csrf_verify()`, 기존 `save_retail_product.php`와 동일 패턴
- 카테고리 유효성 서버 재검증 (요청받은 `category_id`가 실제 `categories`에 존재하는지)
- 전부 prepared statement 사용

---

## 2. 범위

### In Scope
- `mall/admin/products.php` — 탭 UI, 신선상품 검색 쿼리/결과 렌더링, 카테고리별 배정 미니 목록
- `mall/admin/ajax/save_fresh_curation.php` — **신규**, `assign_category`/`unassign_category` 액션
- `lang/ko.json`, `lang/en.json` — 신규 키

### Out of Scope
- 고객 화면(몰 프론트) 노출/장바구니/100g 주문 — `mall-fresh-products.plan.md` 별도 범위
- 좌측 카테고리 사이드바 상품 개수(`product_count`)에 신선상품 포함 — 이번엔 미포함, 필요 시 후속 작업
- `기준 점포`(store_id) 필터 — 신선상품은 점포 구분이 없으므로 무시
- 신선상품 가격/원가/박스구성 수정 — 기존 `admin/edit_fresh_product.php`로 위임
- 검색 결과 페이지네이션 — 현재 신선상품 수 규모에서는 YAGNI, 필요 시 후속 작업
- `products`/`mall_products`/`inventory`/`purchase_items`/`mall_fresh_product_store_links`/`fresh_purchase_items` 스키마 변경

---

## 3. 신규 AJAX 계약 (`save_fresh_curation.php`)

**공통**: `Content-Type: application/json`, `mall_management` 권한 체크, `mall_csrf_verify()` 실패 시 403.

### action=`assign_category`
```
POST mall_fresh_product_id : int
POST category_id           : int
POST csrf_token             : string
```
- 검증: `mall_fresh_product_id>0`, `category_id>0`, `categories`에 해당 id 존재
- `UPDATE mall_fresh_products SET category_id = ? WHERE id = ?`
- 응답: `{ "success": true }` / `{ "success": false, "error": {code, message} }`

### action=`update`
```
POST mall_fresh_product_id : int
POST name_ko                : string (필수)
POST name_en                : string
POST price_per_100g         : number (0 이상)
POST is_active               : '1' | '0'
POST csrf_token              : string
```
- `mall_products`와 달리 신선상품은 점포별 오버라이드 개념이 없어, 통합 테이블의 "저장"은 `mall_fresh_products` 원본(name_ko/name_en/price_per_100g/status)을 직접 수정한다 — 즉 여기서 이름을 바꾸면 `admin/fresh_products.php` 등 다른 화면에도 그대로 반영된다.
- 응답 포맷은 다른 action과 동일

### action=`unassign_category`
```
POST mall_fresh_product_id : int
POST csrf_token             : string
```
- `UPDATE mall_fresh_products SET category_id = NULL WHERE id = ?`
- 응답: 위와 동일 포맷

---

## 4. Success Criteria

- SC-1: "신선상품" 탭 클릭 시 `mall_fresh_products` 검색 결과가 표시되고, 검색어/카테고리 선택이 유지된다.
- SC-2: 카테고리를 선택한 상태에서 검색 결과의 "몰에 등록"을 누르면 `mall_fresh_products.category_id`가 갱신되고, 버튼이 "이미 등록됨"으로 바뀐다.
- SC-3: 다른 카테고리로 "이동등록"하면 category_id가 새 값으로 갱신된다.
- SC-4: 좌측에서 카테고리를 선택하면 중앙 패널에 배정된 신선상품 목록(비활성 포함)이 보이고, "카테고리 해제"로 category_id를 NULL로 되돌릴 수 있다.
- SC-5: 카테고리 미선택 상태에서는 등록 액션이 비활성화되고 안내 배너가 보인다.
- SC-6: 신규 키가 `lang/ko.json`/`lang/en.json`에 쌍으로 존재한다.

---

## 5. 리스크 & 대응

| 리스크 | 대응 |
|--------|------|
| `category_id` 재사용 의미가 향후 `mall-fresh-products.plan.md` 구현과 충돌 | 컬럼 코멘트/코드 주석에 "몰 카테고리 배정(전역, 점포 무관)" 의미 명시. 향후 점포별 큐레이션이 필요해지면 별도 매핑 테이블로 마이그레이션 (이번 스코프 아님) |
| 카테고리 삭제 시 FK 제약(`mall_fresh_products_ibfk_1`, ON DELETE 미지정=RESTRICT)으로 삭제 실패 | `ajax/save_category.php` 삭제 로직에서 FK 에러를 캐치해 사용자 친화적 메시지로 표시 (구현 시 확인) |
| 검색 결과에서 `status='active'`만 보여주면 비활성 배정 상품을 해제할 수 없음 | 검색 탭은 active만, **배정 미니 목록은 상태 무관 전부 노출**로 분리 처리 (F-06 반영) |
| 동시 요청으로 다른 관리자가 먼저 category_id를 바꾼 경우 | AJAX는 항상 최신 값으로 UPDATE (덮어쓰기) — 낙관적 잠금은 이번 스코프 제외, 필요 시 후속 |

---

## 6. 다음 단계

바로 구현 진행 (`mall/admin/products.php`, `mall/admin/ajax/save_fresh_curation.php`, `lang/*.json`).

---

## 7. 확장: 큐레이션된 상품과 완전 동일한 기능 (2026-09-06 추가)

사용자 피드백("순서 바꾸는 기능, 원가/기준도매가 입력, 노출, 재고/품절, 할인, 사진추가/사진검색 기능 등 너무 달라") 반영 —
신선상품 행을 일반상품 행과 **동일한 템플릿/키 구조**로 렌더링하도록 확장.

### 7.1 스키마 추가 (마이그레이션: `sql/migrations/run_add_mall_fresh_products_curation_parity.php`)

`mall_fresh_products`에 컬럼 6개 추가 (기존 `products`/`mall_products`/`inventory` 무변경):

| 컬럼 | 타입 | 기본값 | 대응하는 일반상품 개념 |
|------|------|--------|----------------------|
| `display_order` | INT | 0 | `mall_products.display_order` — 드래그 정렬 |
| `cost_price_override` | DECIMAL(10,2) NULL | NULL | `mall_products.cost_price_override` — 오리지널은 최근 매입원가(`fresh_purchase_items`) |
| `wholesale_reference_price_override` | DECIMAL(10,2) NULL | NULL | `mall_products.wholesale_reference_price` — 오리지널은 원가 x 마진율 |
| `is_sold_out` | TINYINT(1) | 0 | `mall_products.is_sold_out` — 강제 품절 |
| `retail_discount_allowed` | TINYINT(1) | 1 | `mall_products.retail_discount_allowed` |
| `wholesale_discount_allowed` | TINYINT(1) | 1 | `mall_products.wholesale_discount_allowed` |

`image_url`(기존 컬럼)은 그대로 재사용 — 단, `mall_product_images`처럼 다중 테이블이 아니라 단일 URL 1개만 지원(새로 올리면 교체).

### 7.2 구현 방식 — 완전 통합 템플릿

`products.php`의 큐레이션 렌더링 루프를 신선상품/일반상품 공용으로 재사용하기 위해, 신선상품 행을 `$curated` 배열에 넣을 때 **일반상품과 같은 키 이름으로 정규화**한다(예: `code`→`sku`, `name_ko`→`display_name`). 그 결과 원가/기준도매가/판매가/노출/재고·품절/할인 계산 로직과 마크업은 행 종류와 무관하게 그대로 재사용되고, 다른 부분(행 종류 뱃지, 이미지 영역, 삭제/카테고리해제 버튼, 이미지 업로드 대상)만 최소 분기(`$__row_type`)한다.

- **표시명 저장**: 신선상품은 점포별 오버라이드 개념이 없어 "표시명" 저장이 `mall_fresh_products.name_ko`/`name_en` 원본을 직접 수정한다 → 다른 화면(`admin/fresh_products.php` 등)에도 반영됨.
- **판매가**: override 없이 `price_per_100g`을 직접 수정.
- **정렬**: 신선상품은 자기들끼리만 드래그 정렬(별도 정렬 공간). 일반상품 드래그 영역과 섞이지 않도록 종류가 다르면 드롭 무시.
- **이미지**: 단일 `image_url` — 업로드하면 기존 이미지 교체, 다중 추가/순서변경/삭제는 지원 안 함(일반상품과 다른 유일한 지점, `mall_product_images` 같은 다중 테이블이 없기 때문).

### 7.3 신규 AJAX 액션 (`save_fresh_curation.php`)

- `update` — 표시명/판매가/노출상태/원가·기준도매가 오버라이드/강제품절/할인허용을 한 번에 저장 (일반상품 `save_retail_product.php`의 `update`와 동일한 오리지널-대비-오버라이드 계산 로직 재사용)
- `reorder` — 신선상품 행끼리의 순서를 `display_order`에 반영 (일반상품 `reorder_curated_products.php`와 동일 패턴, 페이지네이션 없음)
- `upload_image` — 이미지 업로드 후 `image_url` 교체

### 7.4 스코프 밖 (그대로 유지)

- 다중 이미지(추가/순서변경/개별삭제) — `image_url` 단일 컬럼 한계로 미지원
- 몰 프론트(고객 화면) 노출 — 섹션 8 참고, 별도 논의 필요
