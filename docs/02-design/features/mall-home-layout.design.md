---
template: design
version: 1.3
feature: mall-home-layout
date: 2026-08-13
author: whdans007
project: HOME K MART
version_project: 1.0.0
---

# mall-home-layout Design Document

> **Summary**: 쇼핑몰 홈 화면 드래그앤드롭 레이아웃 빌더의 상세 기술 설계. 섹션 데이터 모델, 관리자/고객 렌더링 공유 로직, 카테고리 탐색 화면, API(ajax) 스펙, 보안·테스트 계획을 정의한다.
>
> **Project**: HOME K MART
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-08-13
> **Status**: Draft
> **Planning Doc**: [mall-home-layout.plan.md](../01-plan/features/mall-home-layout.plan.md)
> **Related Feature**: [shopping-mall.design.md](./shopping-mall.design.md) — 본 설계는 shopping-mall v0.2의 `mall_management` 권한, CSRF 패턴, `pricing.php` 단일 가격경로 원칙을 그대로 재사용한다.

### Pipeline References (if applicable)

| Phase | Document | Status |
|-------|----------|--------|
| Phase 1 | Schema Definition | N/A (미사용) |
| Phase 2 | Coding Conventions | N/A (CLAUDE.md + shopping-mall.design.md §10 컨벤션으로 대체) |
| Phase 3 | Mockup | N/A |
| Phase 4 | API Spec | 본 문서 §4에서 직접 정의 |

> Plan 문서는 Plan Plus(브레인스토밍) 템플릿으로 작성되어 별도 Context Anchor 섹션이 없다. 대신 Plan §1.4 Constraints(특히 2026-08-12 폐기된 이전 레이아웃 빌더 이력), §7 Risks, Appendix Brainstorming Log를 전략적 컨텍스트로 참조한다.

---

## 1. Overview

### 1.1 Design Goals

- 관리자가 코드 수정 없이 홈 화면 섹션(배너/카테고리 바로가기/상품 리스트)을 드래그로 배치·관리할 수 있는 구조 구현
- 관리자 미리보기와 고객 실제 화면이 **항상 동일한 렌더링 함수**를 사용해 드리프트(설정과 실제 화면 불일치)를 구조적으로 차단
- 상품 리스트 섹션도 shopping-mall의 `mall/lib/pricing.php` 단일 가격 계산 경로를 그대로 따라 도매가 오노출 리스크를 재도입하지 않음
- 폐기된 이전 레이아웃 빌더(2026-08-12, `8a5d590`)의 실패 원인(테이블 5개 과분산, 인증 없음, 실제 스키마와 불일치)을 구조적으로 반복하지 않음

### 1.2 Design Principles

- **렌더링 로직 단일화**: 섹션을 화면에 그리는 코드는 `mall/lib/home_layout.php`에만 존재한다. admin 미리보기(`preview_home.php`)와 고객 홈(`index.php`)은 이 함수를 호출만 할 뿐 자체 렌더링 로직을 갖지 않는다.
- **가격은 항상 pricing.php 경유**: 상품 리스트 섹션이 상품을 표시할 때도 `mall_calculate_price()`만 호출한다. 섹션 렌더 함수가 `inventory`/`wholesale_products`를 직접 조회하지 않는다.
- **카탈로그 조회 재사용**: 노출 대상 상품을 찾는 UNION 쿼리(shopping-mall Check phase에서 카탈로그에 이미 구현됨)를 `mall/lib/catalog.php`로 추출해 `category.php`와 상품 리스트 섹션이 함께 재사용한다.
- **기존 컨벤션 재사용 우선**: CSRF 토큰(`mall/lib/csrf.php`), 권한 체크(`mall_management`), 이미지 업로드 보안(MIME 화이트리스트+파일명 난수화)은 shopping-mall에서 이미 구현된 것을 그대로 재사용하고 새로 만들지 않는다.
- **(v0.3) 초안과 발행은 항상 분리**: 관리자 화면은 `status='draft'` 행만 읽고 쓴다. 고객 화면은 `status='published'` 행만 읽는다. 두 상태를 잇는 유일한 통로는 `publish_home_layout.php`(전체 스냅샷 복제) 하나뿐이며, 그 외 어떤 경로도 draft를 published로 직접 바꾸지 않는다(Plan §2.4/2.5).

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | admin/index.php/category.php 각자 렌더링 로직 인라인 작성 | `HomeSectionRepository`/`HomeSectionRenderer` 클래스 기반 OOP 계층 | `mall/lib/home_layout.php` + `catalog.php` 함수형 헬퍼 공유 |
| **New Files** | ~6 | ~14 | ~9 |
| **Modified Files** | ~1 | ~2 | ~2 |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Low (미리보기·실제화면 드리프트 위험) | High (이 프로젝트 컨벤션과 이질적) | High |
| **Risk** | High (렌더 로직 중복) | Low (그러나 procedural 컨벤션 위반) | Low |
| **Recommendation** | 빠른 프로토타입 | 대규모 팀 | **Default choice** |

**Selected**: Option C — **Rationale**: shopping-mall이 이미 Option C(함수형 헬퍼: `pricing.php`/`cart.php`/`order.php`)로 구축되어 있어 동일 패턴을 유지해야 팀 러닝커브가 없다. Option A는 Plan §7이 지적한 "미리보기-실제화면 불일치" 리스크를 그대로 안고, Option B는 이 프로젝트 어디에도 없는 클래스 구조라 이질적이다.

> 아래 상세 설계는 Option C 기준으로 작성됨.

### 2.1 Component Diagram

```
┌──────────────┐      ┌─────────────────────────────┐      ┌──────────────────┐
│   Browser    │─────▶│  mall/index.php (홈)          │─────▶│  MySQL (기존 DB)   │
│ (고객/관리자) │      │  mall/category.php            │      │  mall_home_sections│
└──────────────┘      │  mall/admin/home_layout.php   │      │  (신규), products, │
                       │  mall/admin/preview_home.php  │      │  categories,      │
                       └──────────────┬─────────────────┘      │  mall_products,   │
                                      │ require                │  wholesale_*      │
                                      ▼                        └──────────────────┘
                       ┌─────────────────────────────┐
                       │  mall/lib/home_layout.php    │
                       │  (섹션 조회 + 타입별 렌더)     │
                       │  mall/lib/catalog.php        │
                       │  (노출대상 상품 조회+카드생성) │
                       │  mall/lib/pricing.php (기존)  │
                       └──────────────┬─────────────────┘
                                      │ get_db_connection()
                                      ▼
                       ┌─────────────────────────────┐
                       │  config/db_config.php (기존)  │
                       └─────────────────────────────┘
```

### 2.2 Data Flow

```
[관리자 편집] admin/home_layout.php → ajax/save_home_section.php(추가/수정) →
              ajax/reorder_home_sections.php(순서) → ajax/delete_home_section.php(삭제) →
              mall_home_sections 갱신

[관리자 미리보기] preview_home.php → mall_get_active_home_sections() →
                  mall_render_home_section() 타입별 호출 → 고객 화면과 동일 HTML 출력

[고객 홈] index.php → mall_get_active_home_sections() → 섹션 순서대로 렌더
          → 상품 리스트 섹션은 catalog.php의 카드 생성 함수 + pricing.php 호출

[카테고리 탐색] category.php?id=X → 상단 가로 탭(최상위 카테고리) →
                mall_get_eligible_products(channel, category_id, search) → 카드 그리드
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `mall/lib/home_layout.php` | `mall/lib/catalog.php`, `config/db_config.php` | 섹션 조회 및 타입별 HTML 렌더 |
| `mall/lib/catalog.php` | `mall/lib/pricing.php`, `config/db_config.php` | 노출대상 상품 UNION 조회 + 카드 배열 생성 |
| `mall/admin/home_layout.php` | 기존 `lib/session_helper.php`, `lib/permission_helper.php`, `mall/lib/csrf.php` | 관리자 인증/권한/CSRF |
| `mall/admin/preview_home.php` | `mall/lib/home_layout.php` | 미리보기 (실제 렌더 함수 재사용) |
| `mall/index.php`, `mall/category.php` | `mall/lib/home_layout.php`, `mall/lib/catalog.php`, `mall/partials/header.php` | 고객 화면 |

---

## 3. Data Model

### 3.1 Entity Definition

```
mall_home_sections
  id, section_type('banner'|'category_shortcut'|'product_list'),
  title, subtitle, config(JSON), sort_order, is_active,
  status('draft'|'published'), published_at, published_by,   ← (v0.3)
  created_at, updated_at
```

> **(v0.3) status 컬럼 의미**: 관리자가 편집하는 모든 행은 `status='draft'`로 생성된다. "적용" 버튼(`publish_home_layout.php`)을 누르면 현재 `status='published'`인 행 전체가 삭제되고, 그 시점의 `draft` 행 전체가 새 `id`로 복제되어 `status='published'`, `published_at=NOW()`, `published_by={관리자 users.id}`로 삽입된다. `draft` 행 자체는 그대로 남아 계속 편집할 수 있다(Plan §2.4).

> **config JSON 형태(타입별)**:
> - `banner`: `{"image_path": "uploads/banners/xxx.jpg", "link_type": "url"|"category"|"product", "link_value": "https://..."|category_id|product_id}`
> - `category_shortcut`: `{"category_id": 1}`
> - `product_list`: `{"product_ids": [101, 205, 309]}`
>
> config는 애플리케이션 코드(`mall/lib/home_layout.php`)가 타입별로 파싱/검증한다. DB 레벨 스키마 검증은 하지 않는다(Design Ref §2.0 Option C 선택 근거).

### 3.2 Entity Relationships

```
[stores](기존) 1───N [mall_home_sections] (store_id로 MALL_STORE_ID 스코프)
[mall_home_sections] N···1 [categories](기존, config.category_id — 애플리케이션 레벨 참조, DB FK 없음)
[mall_home_sections] N···N [products](기존, config.product_ids — 애플리케이션 레벨 참조, DB FK 없음)
```

> JSON 내부의 `category_id`/`product_ids`는 DB FK로 강제하지 않는다(§2.0 Option C 트레이드오프). 대신 `save_home_section.php`가 저장 시점에 대상 category_id/product_id가 실제 존재하는지 검증한다(§7 Security).

### 3.3 Database Schema

```sql
CREATE TABLE `mall_home_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_id` int(11) UNSIGNED NOT NULL,
  `section_type` enum('banner','category_shortcut','product_list') NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`),
  KEY `store_sort` (`store_id`, `sort_order`),
  CONSTRAINT `mall_home_sections_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 홈 화면 섹션(드래그앤드롭 레이아웃)';
```

> `json_valid()` CHECK 제약은 기존 `wholesale_products.wholesale_skus` 컬럼과 동일한 관행(§3.1 shopping-mall.design.md)을 따른 것이며, 라이브 DB(MariaDB 10.2+)가 이미 이 문법을 지원함을 `wholesale_products` 테이블에서 확인함.

**(v0.3) 추가 마이그레이션** (`sql/mall_home_sections_v2_status.sql`):

```sql
ALTER TABLE `mall_home_sections`
  ADD COLUMN `status` enum('draft','published') NOT NULL DEFAULT 'draft' AFTER `is_active`,
  ADD COLUMN `published_at` timestamp NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN `published_by` int(11) DEFAULT NULL AFTER `published_at`,
  ADD KEY `store_status` (`store_id`, `status`),
  ADD CONSTRAINT `mall_home_sections_ibfk_2` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 부트스트랩 발행: 이미 만들어져 있던 기존 행(v0.1 배포 후 관리자가 만든 섹션)을 그대로 최초 published로도
-- 복제해, 마이그레이션 직후 고객 화면이 갑자기 비어 보이지 않게 한다(Plan §1.4, §7 무중단 요구사항).
UPDATE `mall_home_sections` SET `status` = 'draft' WHERE `status` IS NULL;

INSERT INTO `mall_home_sections`
  (store_id, section_type, title, subtitle, config, sort_order, is_active, status, published_at, published_by)
SELECT store_id, section_type, title, subtitle, config, sort_order, is_active, 'published', NOW(), NULL
FROM `mall_home_sections` WHERE `status` = 'draft';
```

> 부트스트랩 INSERT는 마이그레이션 스크립트(`sql/run_mall_home_sections_v2_status_migration.php`)가 **딱 1회만** 실행하도록 `SHOW COLUMNS ... LIKE 'status'` 사전 점검으로 감싼다(이미 `status` 컬럼이 있으면 SKIP) — 여러 번 실행해도 중복 발행 데이터가 쌓이지 않도록.

---

## 4. API Specification

> shopping-mall과 동일하게 PHP AJAX 엔드포인트 패턴(`ajax_*.php` → JSON)을 사용한다. 응답은 `{success, data}` 또는 `{success:false, error:{code,message,details}}` 형태(shopping-mall v0.2에서 확정한 통일 포맷).

### 4.1 Endpoint List

| Endpoint | Description | Auth |
|--------|------|------|
| `mall/admin/ajax/save_home_section.php` | 섹션 추가/수정(타입별 config 저장, 배너는 이미지 업로드 포함) | admin + `mall_management` + CSRF |
| `mall/admin/ajax/delete_home_section.php` | 섹션 삭제 | admin + `mall_management` + CSRF |
| `mall/admin/ajax/reorder_home_sections.php` | 드래그 순서 일괄 저장 | admin + `mall_management` + CSRF |
| `mall/admin/ajax/search_products.php` | 상품 리스트 섹션 편집 모달용 실시간 상품 검색(읽기 전용) | admin + `mall_management` (GET 조회 전용이라 CSRF 불필요) |
| `mall/admin/ajax/publish_home_layout.php` | **(v0.3)** 초안(draft) 전체를 발행(published)으로 트랜잭션 복제 | admin + `mall_management` + CSRF |

> `search_products.php`는 Do phase에서 상품 검색 모달의 실무적 필요로 추가됨(Check phase에서 문서 반영).

### 4.2 Detailed Specification

#### `POST mall/admin/ajax/save_home_section.php`

**Request (form-encoded 또는 multipart, 배너 업로드 시):**
```
section_id (선택, 없으면 신규 생성)
section_type = banner | category_shortcut | product_list
title, subtitle (선택)
is_active = 0 | 1
csrf_token

# banner인 경우
image (파일, 선택 — 미첨부 시 기존 이미지 유지)
link_type = url | category | product
link_value = string

# category_shortcut인 경우
category_id = int

# product_list인 경우
product_ids = "101,205,309" (콤마 구분 문자열)
```

**Response (200):**
```json
{ "success": true, "data": { "section_id": 12 } }
```

**Error Responses:**
- `error.code:"VALIDATION_ERROR"` — 필수값 누락, 존재하지 않는 category_id/product_id 포함
- `error.code:"UNAUTHORIZED"` — 미로그인 또는 `mall_management` 권한 없음
- `error.code:"CSRF_INVALID"` — 토큰 불일치

#### `POST mall/admin/ajax/reorder_home_sections.php`

**Request:** `order = "12,7,9,3"` (섹션 id를 원하는 순서로 콤마 구분)

**Response:** `{success:true, data:{updated:4}}`

#### `POST mall/admin/ajax/publish_home_layout.php` (v0.3)

**Request:** `csrf_token`만 (별도 파라미터 없음 — 현재 `status='draft'` 전체를 그대로 발행)

**동작 (트랜잭션):**
```sql
DELETE FROM mall_home_sections WHERE store_id = ? AND status = 'published';
INSERT INTO mall_home_sections (store_id, section_type, title, subtitle, config, sort_order, is_active, status, published_at, published_by)
SELECT store_id, section_type, title, subtitle, config, sort_order, is_active, 'published', NOW(), ?
FROM mall_home_sections WHERE store_id = ? AND status = 'draft';
```

**Response (200):**
```json
{ "success": true, "data": { "published_count": 4, "published_at": "2026-08-13 10:00:00" } }
```

**Error Responses:**
- `error.code:"EMPTY_DRAFT"` — 초안 섹션이 하나도 없을 때(발행할 것이 없음)
- `error.code:"UNAUTHORIZED"` / `"CSRF_INVALID"` — 다른 엔드포인트와 동일

---

## 5. UI/UX Design

### 5.1 Screen Layout (관리자 빌더)

```
┌────────────────────────────────────────┐
│  쇼핑몰 관리 사이드바 │  최근 발행: 2026-08-13 10:00 · 관리자A │
│                     │  섹션 목록(드래그 가능 카드, 초안)     │
│                     │  [배너] [카테고리] [상품리스트]        │
│                     │  + 섹션 추가 버튼                     │
│                     │  [미리보기 버튼] [적용 버튼]           │
└────────────────────────────────────────┘
```

### 5.2 User Flow

```
[관리자] home_layout.php 진입 → 섹션 목록 확인 → "+섹션 추가" → 타입 선택 모달 →
         타입별 입력(배너 이미지/링크, 카테고리 선택, 상품 검색+담기) → 저장 →
         카드 드래그로 순서 변경 → 노출 토글 → "미리보기"로 확인 → 완료

[고객] index.php(홈) → 섹션 순서대로 열람 → 배너/카테고리 클릭 → category.php 이동
       → 카테고리 탭 전환 → 상품 클릭 → product.php 상세
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| 섹션 카드(드래그) | `mall/admin/partials/home_section_card.php` | 섹션 요약 표시, 드래그 핸들, 편집/삭제/토글 버튼 |
| 섹션 편집 모달 | `mall/admin/home_layout.php` 내 인라인 | 타입별 입력 폼(배너/카테고리/상품리스트) |
| 배너 렌더 | `mall/lib/home_layout.php::mall_render_banner_section()` | 이미지+링크 출력 |
| 카테고리 바로가기 렌더 | `mall/lib/home_layout.php::mall_render_category_shortcut_section()` | 카테고리 카드 출력, `category.php?id=X` 링크 |
| 상품 리스트 렌더 | `mall/lib/home_layout.php::mall_render_product_list_section()` | `mall/partials/product_card.php` 재사용해 그리드 출력 |
| 카테고리 탭 바 | `mall/partials/category_tabs.php` | 최상위 카테고리 가로 탭, 현재 선택 강조 |
| **(v0.3)** 발행 상태 배너 | `mall/admin/home_layout.php` 상단 인라인 | "최근 발행: {published_at} · {published_by 이름}" 또는 "발행 전" 표시 |
| **(v0.3)** 적용 버튼 | `mall/admin/home_layout.php` 상단 인라인 | 클릭 시 `confirm()` 다이얼로그 → `publish_home_layout.php` 호출 |

### 5.4 Page UI Checklist

#### 관리자 — 홈 레이아웃 (`mall/admin/home_layout.php`)
- [ ] 섹션 목록: 타입 아이콘/제목/노출상태 표시된 카드 리스트, `sort_order` 순 (`status='draft'` 행만 표시)
- [ ] 드래그 핸들: 네이티브 HTML5 Drag&Drop으로 카드 순서 변경 → 즉시 `reorder_home_sections.php` 호출
- [ ] "+섹션 추가" 버튼 → 타입 선택(배너/카테고리 바로가기/상품 리스트) 모달
- [ ] 배너 폼: 이미지 업로드는 **선택**(미첨부 시 저장 가능, 관리자/미리보기 화면에서 플레이스홀더 표시), 링크 타입 드롭다운(URL/카테고리/상품), 링크 값 입력
- [ ] 카테고리 바로가기 폼: 카테고리 드롭다운(`categories` 테이블), **현재 연결된 카테고리 값이 명확히 채워져 표시됨** — "섹션 타입" 필드가 비활성화되어도 이 드롭다운은 뚜렷하게 활성 상태로 보이도록 시각적으로 구분(예: 비활성 필드는 흐린 배경, 편집 가능 필드는 흰 배경+테두리 강조)
- [ ] 상품 리스트 폼: 상품 검색(바코드/이름) → 담기 버튼 → 담긴 상품 썸네일 목록(삭제 가능)
- [ ] 섹션별 제목/부제 입력 필드
- [ ] 노출 ON/OFF 토글 스위치
- [ ] 삭제 버튼(확인 다이얼로그)
- [ ] "미리보기" 버튼 → `preview_home.php` 새 창(초안 미리보기)
- [ ] **(v0.3)** 상단 "최근 발행: {시각} · {발행자}" 표시 (발행 이력 없으면 "발행 전")
- [ ] **(v0.3)** "적용" 버튼 → `confirm()` 다이얼로그("초안을 고객 화면에 바로 반영합니다. 계속하시겠습니까?") → `publish_home_layout.php` 호출 → 성공 시 발행 배너 갱신

#### 관리자 — 미리보기 (`mall/admin/preview_home.php`)
- [ ] `status='draft'` 섹션을 실제 고객 화면과 동일한 HTML/CSS로 렌더링 (비활성 섹션도 관리자에게는 흐리게 표시해 확인 가능하도록 옵션 파라미터 지원)
- [ ] **(v0.3)** 화면 상단에 "이것은 초안 미리보기입니다 — 적용 전까지 고객에게 보이지 않습니다" 안내

#### 고객 — 홈 (`mall/index.php`)
- [ ] **(v0.3)** `status='published'` AND 노출중(`is_active=1`)인 섹션만 `sort_order` 순으로 렌더
- [ ] 배너 섹션: 전체 폭 이미지, 클릭 시 링크 이동. **(v0.3)** 발행된 배너에 이미지가 없으면 렌더링을 생략(빈 배너를 고객에게 보이지 않음 — 플레이스홀더는 관리자/미리보기 전용)
- [ ] 카테고리 바로가기 섹션: 카테고리명 카드, 클릭 시 `category.php?id=X`
- [ ] 상품 리스트 섹션: 섹션 제목/부제 + 상품 카드 그리드(기존 `product_card.php` 재사용, 가격은 `pricing.php` 경유)
- [ ] 섹션이 하나도 없을 때: 안내 문구("등록된 섹션이 없습니다") — 빈 화면 방지

#### 고객 — 카테고리 탐색 (`mall/category.php`)
- [ ] 상단 가로 탭: 최상위 카테고리(`parent_id IS NULL`) 목록, 현재 선택 카테고리 강조
- [ ] 선택된 카테고리의 상품 그리드(기존 `mall/index.php`의 카테고리 필터 로직 이전, 검색창 포함)
- [ ] 품절/가격 표시는 기존 `mall/index.php`와 동일 정책 유지

---

## 6. Error Handling

### 6.1 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| UNAUTHORIZED | 로그인이 필요합니다 / 권한이 없습니다 | 세션 없음/`mall_management` 권한 없음 | 로그인 페이지 이동 또는 403 |
| CSRF_INVALID | 요청이 만료되었습니다 | 토큰 불일치 | 새로고침 안내 |
| VALIDATION_ERROR | 입력값을 확인해주세요 | 필수값 누락, 존재하지 않는 category_id/product_id | 필드별 오류 메시지 |
| SERVER_ERROR | 처리 중 오류가 발생했습니다 | DB/파일시스템 오류 | 로그 기록 후 일반 메시지 |

### 6.2 Error Response Format

```json
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "...", "details": {} } }
```

---

## 7. Security Considerations

- [ ] 모든 쿼리는 prepared statement 사용
- [ ] 관리자 화면/ajax는 기존 `require_permission('mall_management', ...)` 패턴 재사용
- [ ] 3개 ajax 엔드포인트 전부 `mall/lib/csrf.php`(shopping-mall Check phase에서 구현됨)로 토큰 검증
- [ ] `save_home_section.php`는 저장 전 `config.category_id`/`config.product_ids`가 실제 존재하는 레코드인지 서버에서 검증(존재하지 않는 ID 저장 방지)
- [ ] 배너 이미지 업로드는 `save_retail_product.php`와 동일한 보안 로직(MIME 화이트리스트 jpg/png/webp, `finfo` 실콘텐츠 검사, 파일명 난수화, 5MB 제한, `mall/uploads/banners/`로 격리) 재사용
- [ ] 상품 리스트 섹션은 반드시 `mall_calculate_price()`를 경유해 가격을 계산 — 섹션 렌더 함수가 `inventory`/`wholesale_products`를 직접 조회하지 않음(shopping-mall §1.2 원칙 계승)
- [ ] `config` JSON은 저장 시 `json_encode()`로 생성하고, 렌더 시 `json_decode($config, true)`로만 파싱 — 사용자 입력 HTML을 config에 직접 저장하지 않음(배너 title/subtitle은 렌더 시 `htmlspecialchars()` 필수)
- [ ] **(v0.3)** `publish_home_layout.php`는 `mall_management` 권한 + CSRF 검증(다른 상태변경 ajax와 동일 수준), 발행 대상은 항상 `WHERE store_id = MALL_STORE_ID`로 스코프 제한
- [ ] **(v0.3)** 고객 화면(`index.php`)과 관리자 화면(`home_layout.php`/`preview_home.php`)은 각각 `status='published'`/`status='draft'`를 하드코딩된 조건으로 조회 — 사용자 입력으로 status 값을 바꿀 수 있는 경로가 없어야 함(`save_home_section.php`가 status를 절대 받지 않고 항상 'draft'로 고정)

---

## 8. Test Plan

> shopping-mall과 동일하게 Playwright 미구성 환경. L1은 curl, L2/L3는 수동 브라우저 체크리스트로 대체.

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: AJAX 엔드포인트 테스트 | `mall/admin/ajax/*.php` (신규 6개, v0.3 포함) | curl | Do |
| L2: UI 동작 테스트 | §5.4 체크리스트 요소별 동작 | 수동 브라우저 체크리스트 | Do/Check |
| L3: 시나리오 테스트 | 섹션 생성→순서변경→미리보기→고객화면 반영 확인 | 수동 브라우저 시나리오 | Check |

### 8.2 L1: API Test Scenarios

| # | Endpoint | Test Description | Expected |
|---|----------|-------------------|----------|
| 1 | `save_home_section.php` | 배너 섹션 신규 생성(이미지 포함) | success:true, section_id 발급 |
| 2 | `save_home_section.php` | 존재하지 않는 category_id로 카테고리 섹션 생성 시도 | success:false, VALIDATION_ERROR |
| 3 | `save_home_section.php` | 비관리자 세션으로 호출 | success:false, UNAUTHORIZED |
| 4 | `reorder_home_sections.php` | 섹션 3개 순서 변경 | success:true, sort_order 반영 확인 |
| 5 | `delete_home_section.php` | 섹션 삭제 후 고객 화면에서 즉시 사라짐 확인 | success:true |
| 6 | `save_home_section.php` | CSRF 토큰 없이 호출 | success:false, CSRF_INVALID |
| 7 | `save_home_section.php` | **(v0.3)** 이미지 없이 배너 섹션 생성 | success:true (더 이상 검증 오류 아님) |
| 8 | `publish_home_layout.php` | **(v0.3)** draft 섹션 2개 상태에서 발행 | success:true, published_count:2, 이후 `index.php` 응답에 해당 섹션 반영 확인 |
| 9 | `publish_home_layout.php` | **(v0.3)** draft가 하나도 없는 상태에서 발행 시도 | success:false, EMPTY_DRAFT |

### 8.3 L2: UI Action Test Scenarios

| # | Page | Action | Expected Result |
|---|------|--------|----------------|
| 1 | home_layout.php | 섹션 카드 드래그로 순서 변경 | 새로고침 없이(또는 즉시) 순서 반영, DB `sort_order` 갱신 |
| 2 | home_layout.php | 노출 토글 OFF | 고객 홈에서 해당 섹션 즉시 미노출 |
| 3 | home_layout.php | 미리보기 버튼 클릭 | 새 창에 실제 고객 화면과 동일한 렌더링 표시 |
| 4 | category.php | 카테고리 탭 클릭 | 상품 그리드가 해당 카테고리로 필터링, URL의 id 파라미터 변경 |
| 5 | index.php | 상품 리스트 섹션 표시 | 승인/미승인 도매 계정 각각으로 가격 정책 정상 확인 |
| 6 | **(v0.3)** home_layout.php | 초안만 수정하고 "적용"을 누르지 않음 | 고객 화면(`index.php`)은 이전 published 상태 그대로 유지, 변경 미반영 |
| 7 | **(v0.3)** home_layout.php | "적용" 버튼 클릭 | `confirm()` 다이얼로그 노출 → 확인 시에만 발행 진행, 취소 시 아무 변화 없음 |
| 8 | **(v0.3)** home_layout.php | 카테고리 바로가기 섹션 편집 모달 열기 | 카테고리 드롭다운에 현재 값이 채워져 있고, 다른 카테고리로 변경 후 저장하면 반영됨 |

### 8.4 L3: E2E Scenario Test Scenarios

| # | Scenario | Steps | Success Criteria |
|---|----------|-------|-----------------|
| 1 | 홈 화면 구성 전체 흐름 | 배너 추가(이미지 없이) → 카테고리 바로가기 추가 → 상품 리스트 추가 → 순서 변경 → 미리보기(초안) 확인 → **"적용" 클릭** → 고객 화면에서 동일하게 확인 | 적용 전에는 고객 화면 미반영, 적용 후에는 관리자 설정과 100% 일치 |
| 2 | 카테고리 탐색 흐름 | 홈에서 카테고리 섹션 클릭 → category.php 진입 → 다른 카테고리 탭 클릭 → 상품 상세 진입 | 각 단계 데이터 정상 표시 |
| 3 | 도매가 비노출 재확인 | 미승인 도매 계정으로 상품 리스트 섹션이 포함된 홈 화면 열람 | 도매가 노출 없이 소매가로 대체 표시(shopping-mall §7 정책 유지) |

### 8.5 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| mall_home_sections | 3+ | 타입별(banner/category_shortcut/product_list) 각 1개 이상, is_active 혼합, **(v0.3)** status='draft'/'published' 각각 존재하는 상태 포함 |
| categories | 기존 데이터 재사용 | parent_id IS NULL(최상위) 3개 이상 |

---

## 9. Clean Architecture (PHP Procedural 변형)

> shopping-mall.design.md §9와 동일한 계층 개념(procedural PHP + 함수형 헬퍼)을 적용.

### 9.1 Layer Structure

| Layer | Responsibility | Location |
|-------|---------------|----------|
| **Presentation** | 화면 렌더링, 요청 처리 | `mall/index.php`, `mall/category.php`, `mall/admin/home_layout.php`, `mall/admin/preview_home.php` |
| **Application** | 섹션 CRUD 유스케이스 | `mall/admin/ajax/save_home_section.php`, `delete_home_section.php`, `reorder_home_sections.php`, `publish_home_layout.php`(v0.3) |
| **Domain** | 섹션 렌더링·카탈로그 조회 핵심 로직 | `mall/lib/home_layout.php`, `mall/lib/catalog.php` |
| **Infrastructure** | DB 연결, 이미지 업로드, CSRF, 권한(기존 재사용) | `config/db_config.php`, `mall/lib/csrf.php`, `lib/permission_helper.php` |

### 9.2 Dependency Rules

```
Presentation ──require──▶ Domain(home_layout.php, catalog.php) ──▶ Infrastructure(get_db_connection())
Domain(home_layout.php) ──▶ catalog.php, pricing.php(기존)만 의존
규칙: 섹션 렌더 함수는 pricing.php를 거치지 않고 상품 가격을 직접 조회하지 않는다.
```

### 9.3 This Feature's Layer Assignment

| Component | Layer | Location |
|-----------|-------|----------|
| 홈/카테고리 화면 | Presentation | `mall/index.php`, `mall/category.php` |
| 관리자 빌더/미리보기 | Presentation | `mall/admin/home_layout.php`, `mall/admin/preview_home.php` |
| 섹션 CRUD + 발행 | Application | `mall/admin/ajax/*.php` (v0.3: `publish_home_layout.php` 포함) |
| 섹션 렌더링/카탈로그 조회 | Domain | `mall/lib/home_layout.php`, `mall/lib/catalog.php` |

---

## 10. Coding Convention Reference

> CLAUDE.md + shopping-mall.design.md §10 컨벤션을 그대로 적용.

### 10.1 Naming Conventions

| Target | Rule | Example |
|--------|------|---------|
| 함수 | snake_case | `mall_get_active_home_sections()`, `mall_render_banner_section()` |
| 파일 | snake_case.php | `home_layout.php`, `catalog.php` |
| DB 테이블/컬럼 | snake_case, `mall_` 접두사 | `mall_home_sections`, `section_type` |

### 10.2 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| 가격 계산 | 반드시 `pricing.php` 경유, 직접 계산 금지 |
| CSRF | 기존 `mall/lib/csrf.php` 재사용 |
| 이미지 업로드 | 기존 `save_retail_product.php` 보안 로직 재사용 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
mall/
├── index.php                       (변경: 홈 화면 섹션 렌더링으로 역할 전환)
├── category.php                    (신규: 가로 카테고리 탭 + 상품 그리드)
├── lib/
│   ├── home_layout.php             (신규)
│   └── catalog.php                 (신규)
├── partials/
│   └── category_tabs.php           (신규)
├── uploads/banners/                (신규 디렉토리, 실행권한 제거)
│
└── admin/
    ├── home_layout.php             (신규)
    ├── preview_home.php            (신규)
    ├── partials/
    │   └── home_section_card.php   (신규)
    └── ajax/
        ├── save_home_section.php       (신규)
        ├── delete_home_section.php     (신규)
        ├── reorder_home_sections.php   (신규)
        ├── search_products.php         (신규, Do phase 추가분)
        └── publish_home_layout.php     (v0.3 신규)

sql/
├── mall_home_sections.sql               (신규)
├── mall_home_sections_v2_status.sql     (v0.3 신규: status/published_at/published_by ALTER)
└── run_mall_home_sections_v2_status_migration.php  (v0.3 신규: 브라우저 실행 마이그레이션 스크립트)
```

### 11.2 Implementation Order

1. [ ] `sql/mall_home_sections.sql` 작성 및 실제 DB 적용(라이브 스키마 대조, 폐기된 `layout_*`/`display_sections` 테이블명과 충돌 없는지 재확인)
2. [ ] `mall/lib/catalog.php` — 기존 `index.php`의 UNION 조회 로직 추출
3. [ ] `mall/lib/home_layout.php` — 섹션 조회 + 타입별 렌더 함수
4. [ ] `mall/admin/home_layout.php` + 3개 ajax — 섹션 CRUD/드래그순서/노출토글
5. [ ] `mall/admin/preview_home.php` — 미리보기
6. [ ] `mall/index.php` 역할 전환(섹션 렌더링) + `mall/category.php` 신규(카테고리 탭+상품그리드)
7. [x] 전체 통합 테스트(§8) — v0.1 Check phase 완료
8. [ ] **(v0.3)** `sql/mall_home_sections_v2_status.sql` + 마이그레이션 스크립트(부트스트랩 발행 포함) 작성/적용
9. [ ] **(v0.3)** `save_home_section.php` 배너 이미지 필수 검증 제거 + `mall_render_banner_section()` 플레이스홀더(관리자/미리보기 전용)
10. [ ] **(v0.3)** `mall_get_active_home_sections()`에 `status` 파라미터 추가, `index.php`/`admin/home_layout.php`/`preview_home.php` 각각 올바른 status로 호출하도록 수정
11. [ ] **(v0.3)** `publish_home_layout.php` 신규 작성 + `home_layout.php`에 적용 버튼/확인다이얼로그/최근발행표시 추가
12. [ ] **(v0.3)** 카테고리 바로가기 편집 모달 UX 개선(비활성 필드 시각적 구분)
13. [ ] **(v0.3)** 통합 테스트(§8 v0.3 시나리오)

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|--------------|:---------------:|
| DB 스키마 + 카탈로그 공용화 | `module-1` | mall_home_sections.sql, lib/catalog.php(기존 로직 추출) | 15-20 |
| 섹션 렌더링 + 관리자 CRUD | `module-2` | lib/home_layout.php, admin/home_layout.php, 3개 ajax | 30-35 |
| 미리보기 + 고객 화면 전환 | `module-3` | admin/preview_home.php, index.php 역할변경, category.php 신설 | 25-30 |
| **(v0.3)** 초안/발행 워크플로우 | `module-4` | status 컬럼 마이그레이션, publish_home_layout.php, 배너 이미지선택/플레이스홀더, 카테고리편집 UX개선 | 30-35 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 (완료) | - |
| Session 2 | Do | `--scope module-1,module-2` | 40-50 |
| Session 3 | Do | `--scope module-3` | 30-40 |
| Session 4 | Check + Report | 전체 (완료, Match Rate 92%) | - |
| Session 5 | Plan v0.2 + Design v0.3 | 전체 (완료) | - |
| Session 6 | Do | `--scope module-4` | 30-35 |
| Session 7 | Check + Report | 전체 | 20-30 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-13 | Initial draft | whdans007 |
| 0.2 | 2026-08-13 | Check phase 결과 반영: §4.1에 search_products.php 추가(Do phase 실무 추가분 문서화) | whdans007 |
| 0.3 | 2026-08-13 | Plan v0.2(실사용 피드백) 반영: status/published_at/published_by 컬럼 + `publish_home_layout.php`(초안/발행 워크플로우), 배너 이미지 선택사항화+플레이스홀더, 카테고리 바로가기 편집 UX 개선. §3/§4/§5/§7/§8/§9/§11 갱신, module-4 세션 추가 | whdans007 |
