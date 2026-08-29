---
template: plan-plus
version: 1.0
feature: mall-home-layout
date: 2026-08-13
author: whdans007
project: HOME K MART
version_project: 1.0.0
---

# mall-home-layout Planning Document

> **Summary**: 쇼핑몰 관리자가 배너·카테고리 바로가기·특정 상품 리스트 섹션을 드래그앤드롭으로 순서 배치해 홈 화면을 직접 꾸미고, 고객은 카테고리를 클릭하면 상단 가로 탭 내비게이션과 함께 해당 카테고리 상품을 볼 수 있게 한다.
>
> **Project**: HOME K MART
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-08-13
> **Status**: Draft
> **Method**: Plan Plus (Brainstorming-Enhanced PDCA)
> **Related Feature**: [shopping-mall](./shopping-mall.plan.md) — 본 기능은 shopping-mall의 `mall/index.php`(고객 홈)와 `mall/admin/`(관리자)을 확장한다.

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 현재 `mall/index.php`는 상품 그리드만 고정 출력하는 정적 카탈로그 화면이라, 관리자가 홈 화면 구성(배너, 추천 카테고리, MD 추천 상품 등)을 바꾸려면 코드를 직접 수정해야 한다. |
| **Solution** | 관리자가 배너/카테고리 바로가기/특정 상품 리스트 3종 섹션을 드래그앤드롭으로 추가·순서변경·노출토글하는 홈 화면 빌더(`mall/admin/home_layout.php`)를 만들고, 고객용 `mall/index.php`는 저장된 섹션을 그대로 순서대로 렌더링한다. |
| **Function/UX Effect** | 관리자는 코드 수정 없이 실시간 미리보기를 보며 홈 화면을 운영할 수 있고, 고객은 카테고리 섹션 클릭 시 상단 가로 탭 내비게이션이 있는 카테고리별 상품 화면(`mall/category.php`)으로 이동한다. |
| **Core Value** | 폐기된 이전 레이아웃 빌더(2026-08-12, `layout_rows`/`layout_columns`/`layout_presets`/`display_sections`/`product_displays` 5테이블 구조, 인증 없음, 실제 스키마와 불일치로 완전 작동불가)의 실패를 반복하지 않고, 단일 테이블 + JSON 설정이라는 단순한 구조로 같은 요구를 충족한다. |

---

## 1. User Intent Discovery

### 1.1 Core Problem

`mall/index.php`가 고정된 상품 그리드만 보여주는 정적 화면이라, 배너/카테고리 강조/MD 추천 상품 등 프로모션 목적의 홈 화면 구성을 바꾸려면 매번 코드를 수정해야 한다. 관리자가 드래그앤드롭으로 직접 섹션을 배치·관리할 수 있는 홈 화면 빌더가 필요하다.

### 1.2 Target Users

| User Type | Usage Context | Key Need |
|-----------|---------------|----------|
| 쇼핑몰 관리자 | `mall/admin/home_layout.php`에서 기존 admin 계정으로 로그인 | 코드 수정 없이 배너/카테고리/상품리스트 섹션을 드래그로 배치, 노출 ON/OFF, 실시간 미리보기 |
| 쇼핑몰 방문객(비회원 포함) | `mall/index.php` 및 `mall/category.php` | 로그인 여부와 무관하게 홈 화면과 카테고리별 상품 탐색 가능 (product.php의 기존 비회원 열람 정책과 동일) |

### 1.3 Success Criteria

- [ ] 관리자가 배너/카테고리 바로가기/특정 상품 리스트 섹션을 추가하고 드래그로 순서를 변경할 수 있다
- [ ] 섹션을 노출 ON/OFF로 토글할 수 있다 (삭제하지 않고 임시로 숨김)
- [ ] 배너 섹션에 이미지를 업로드하고 클릭 시 URL/카테고리/상품으로 이동하는 링크를 설정할 수 있다
- [ ] 특정 상품 리스트 섹션에서 상품을 검색해 여러 개 수동으로 담을 수 있다
- [ ] 관리자 화면에서 저장 없이 실제 고객 화면과 동일한 렌더링으로 미리보기를 볼 수 있다
- [ ] 고객이 홈 화면에서 카테고리 섹션을 클릭하면 상단 가로 탭 내비게이션이 있는 카테고리별 상품 화면으로 이동한다
- [ ] 비회원도 홈 화면과 카테고리별 상품 화면을 열람할 수 있다 (로그인은 장바구니/주문 시점에만 요구)
- [ ] **(v0.2)** 관리자가 섹션을 자유롭게 추가/수정해도 고객 화면은 바뀌지 않다가, "적용" 버튼을 눌러야만 실제로 반영된다
- [ ] **(v0.2)** 배너 섹션은 이미지 없이도 우선 추가할 수 있다 (이미지는 나중에 업로드 가능)
- [ ] **(v0.2)** 카테고리 바로가기 섹션의 "어느 카테고리를 가리킬지" 편집 기능이 관리자 화면에서 명확하게 눈에 띈다

### 1.4 Constraints

| Constraint | Details | Impact |
|------------|---------|--------|
| 폐기된 이전 빌더와 절대 겹치지 않을 것 | `layout_rows`/`layout_columns`/`layout_presets`/`display_sections`/`product_displays` 테이블명/구조를 재사용하지 않는다 (2026-08-12 `8a5d590` 커밋으로 45개 파일 전량 폐기됨) | High |
| 신규 테이블은 `mall_` 접두사 | shopping-mall 기존 컨벤션(design v0.2 §10.1)과 동일하게 유지 | High |
| 단일 점포 기준 유지 | shopping-mall과 동일하게 `MALL_STORE_ID` 상수 기준으로 섹션을 노출 (다점포 홈 화면 분기는 범위 밖) | Medium |
| 기존 pricing.php 단일 경로 원칙 준수 | 상품 리스트 섹션도 반드시 `mall_calculate_price()`를 거쳐 가격을 계산해야 하며, 도매가가 미승인/소매 회원에게 노출되면 안 된다 | High |
| 이미지 업로드 보안 재사용 | `save_retail_product.php`의 MIME 화이트리스트 + 파일명 난수화 로직을 배너 업로드에도 동일 적용 | Medium |
| **(v0.2)** 마이그레이션 시 기존 라이브 화면 무중단 | v0.1 배포 후 이미 만들어진 섹션(현재 `is_active=1`로 즉시 노출되던 것들)이 있으므로, `status` 컬럼 추가 마이그레이션 시점에 기존 행을 자동으로 1회 발행(bootstrap publish)해 고객 화면이 갑자기 비어 보이지 않게 한다 | High |

---

## 2. Alternatives Explored

### 2.1 Approach A: 단일 섹션 테이블 + JSON 설정 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | `mall_home_sections` 테이블 하나로 배너/카테고리 바로가기/상품 리스트 3종 섹션을 모두 관리하고, 타입별 세부 설정은 `config` JSON 컬럼에 저장한다 |
| **Pros** | 테이블 1개, 관리자 화면 1개로 단순함. 향후 섹션 타입이 늘어나도 JSON 스키마만 확장하면 됨 |
| **Cons** | JSON 내부 무결성(FK 등)은 DB가 아닌 애플리케이션 코드가 책임져야 함 |
| **Effort** | Low-Medium |
| **Best For** | 섹션 타입이 소수(3종)이고 빠르게 안정적으로 만들어야 하는 현재 상황 |

### 2.2 Approach B: 섹션 타입별 개별 테이블

| Aspect | Details |
|--------|---------|
| **Summary** | `mall_home_banners`, `mall_home_category_shortcuts`, `mall_home_product_lists` + 순서 관리용 `mall_home_layout` 테이블 |
| **Pros** | 각 테이블에 FK/컬럼 타입 제약을 걸 수 있어 데이터 무결성이 강함 |
| **Cons** | 테이블 4개, 관리자 화면 로직도 타입별 분기 필요 — 폐기된 이전 빌더(5테이블 구조)와 유사한 복잡도로 회귀할 위험 |
| **Effort** | Medium-High |
| **Best For** | 섹션 타입이 많고 각 타입마다 엄격한 스키마 검증이 필요한 대규모 팀 상황 |

### 2.3 Decision Rationale

**Selected**: Approach A
**Reason**: 폐기된 이전 레이아웃 빌더가 테이블을 과도하게 분산시켜 실패한 전례가 있어, 단일 테이블 + JSON 구조로 관리 포인트를 최소화하는 편이 안전하다. 섹션 타입이 3종으로 적어 JSON 무결성 리스크도 낮다.

### 2.4 (v0.2) Approach A: `status` 컬럼 추가 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | `mall_home_sections`에 `status ENUM('draft','published')` 추가. 관리자는 항상 `draft`만 편집하고, "적용" 버튼이 `draft` 전체를 `published`로 복제(트랜잭션: 기존 published 삭제 후 draft를 published로 재삽입) |
| **Pros** | 테이블 추가 없음, 기존 `preview_home.php`가 그대로 "초안 미리보기"로 재활용됨 |
| **Cons** | 발행 이력(누가 언제 무엇을 바꿔 발행했는지)은 남지 않음 — 마지막 발행 시각/발행자만 컬럼으로 기록 |
| **Effort** | Low |
| **Best For** | "지금 보이는 것 vs 준비 중인 것" 딱 두 상태만 필요한 현재 요구 |

### 2.5 (v0.2) Approach B: 별도 버전 이력 테이블

| Aspect | Details |
|--------|---------|
| **Summary** | `mall_home_layout_versions` 스냅샷 로그 테이블을 추가해 과거 발행본으로 롤백 가능하게 함 |
| **Pros** | 롤백/이력 조회 가능 |
| **Cons** | 테이블+로직 증가, 이번 요청 범위(단순 초안/적용 구분) 초과 — YAGNI 위반 |
| **Effort** | Medium |
| **Best For** | 발행 실수를 되돌려야 하는 빈도가 실제로 높아졌을 때 |

**Selected**: Approach A — **Reason**: 사용자가 명시적으로 "전체 레이아웃을 하나의 초안으로 관리"를 선택. 롤백 이력은 이번 요구사항에 없음(범위 밖으로 명시적으로 제외).

---

## 3. YAGNI Review

### 3.1 Included (v1 Must-Have)

- [ ] 섹션 추가/삭제 (배너/카테고리 바로가기/상품 리스트 3종)
- [ ] 드래그로 섹션 순서 변경 (네이티브 HTML5 Drag&Drop, 외부 라이브러리 없음)
- [ ] 섹션 노출 ON/OFF 토글
- [ ] 배너 이미지 업로드 + 클릭 링크(URL/카테고리/상품) 설정
- [ ] 특정 상품 리스트 섹션(관리자가 상품 검색해 수동으로 여러 개 선택)
- [ ] 관리자 실시간 미리보기 패널(실제 고객 렌더링 함수 재사용, 새 창)
- [ ] 섹션별 제목/부제 텍스트 편집
- [ ] 카테고리 바로가기 → 상단 가로 탭 내비게이션 + 카테고리별 상품 화면(`mall/category.php`)
- [ ] **(v0.2)** `status` 컬럼(draft/published) + "적용" 버튼(초안 → 발행 일괄 전환)
- [ ] **(v0.2)** 적용 버튼 클릭 시 확인 다이얼로그
- [ ] **(v0.2)** 관리자 화면에 "최근 발행: {시각} · {발행자}" 표시
- [ ] **(v0.2)** 이미지 없는 배너 허용 + 플레이스홀더 이미지 표시(관리자/미리보기 화면 한정)
- [ ] **(v0.2)** 발행 이력 간단 기록 (`published_at`, `published_by` 컬럼만, 별도 로그 테이블 없음)
- [ ] **(v0.2)** 카테고리 바로가기 편집 모달 UX 개선 (비활성 필드와 편집 가능 필드를 시각적으로 구분)

### 3.2 Deferred (v2+ Maybe)

| Feature | Reason for Deferral | Revisit When |
|---------|---------------------|--------------|
| 섹션별 다국어(한/영) 제목 | shopping-mall 전체가 아직 부분 i18n 상태(언어전환+상품명만 적용)라 일관성 있게 함께 미룸 | shopping-mall 전체 i18n 작업 시 |
| 섹션 노출 기간 예약(시작일~종료일) | v1 요구사항엔 없음, ON/OFF 토글로 충분 | 프로모션 자동 종료가 필요해지면 |
| 회원 등급별 섹션 노출 분기(A/B) | 범위 밖으로 명시적으로 확인됨 | 타겟팅 마케팅 필요성이 생기면 |
| 2차원 그리드 배치(행/열 자유배치) | 세로 섹션 순서만으로 충분하다고 확인됨. 폐기된 이전 빌더의 `layout_rows`/`layout_columns` 구조와 동일한 과설계 위험 | 실제 세로 배치로 부족하다는 피드백이 쌓이면 |
| **(v0.2)** 발행 버전 이력/롤백 기능 | Approach B로 검토했으나 이번 요구사항 범위 초과로 제외(2.5 참조) | 발행 실수 되돌리기 빈도가 실제로 높아지면 |
| **(v0.2)** 초안 상태에서 published를 부분적으로만 발행(섹션 단위 선택 발행) | "전체 레이아웃을 하나의 초안으로" 방식을 명시적으로 선택 — 부분 발행은 별도 상태 관리가 필요해 범위 초과 | 특정 섹션만 먼저 내보내야 하는 요구가 생기면 |

### 3.3 Removed (Won't Do)

| Feature | Reason for Removal |
|---------|-------------------|
| 로그인 필수 홈 화면 | 비회원도 열람 가능해야 한다고 결정, product.php의 기존 정책과 일관성 유지 |

---

## 4. Scope

### 4.1 In Scope

- [ ] `mall_home_sections` 신규 테이블 설계 및 생성 (`sql/mall_home_sections.sql`)
- [ ] 섹션 렌더링 공용 로직(`mall/lib/home_layout.php`) — admin 미리보기와 고객 화면이 동일 함수 재사용
- [ ] 카탈로그 조회 공용화(`mall/lib/catalog.php`) — 기존 `index.php`의 상품 UNION 조회 로직을 함수로 추출, `product_list` 섹션과 `category.php`가 재사용
- [ ] `mall/admin/home_layout.php`: 섹션 목록(드래그 정렬)/추가·편집 모달/노출토글/미리보기 버튼
- [ ] `mall/index.php` 역할 변경: 상품 그리드 고정 출력 → 섹션 순서 렌더링
- [ ] `mall/category.php` 신규: 상단 가로 카테고리 탭 + 선택 카테고리 상품 그리드 (기존 `index.php`의 카테고리 드롭다운 필터 로직 이전)
- [ ] `mall/admin/preview_home.php`: 저장 여부와 무관하게 현재 섹션 구성을 고객 화면과 동일하게 미리보기
- [ ] **(v0.2)** `mall_home_sections.status`(draft/published) + `published_at`/`published_by` 컬럼 추가 (ALTER, 기존 행 1회 bootstrap 발행 포함)
- [ ] **(v0.2)** `mall/admin/ajax/publish_home_layout.php`: draft → published 트랜잭션 복제
- [ ] **(v0.2)** 배너 저장 시 이미지 필수 검증 제거 + `mall_render_banner_section()` 플레이스홀더 렌더링
- [ ] **(v0.2)** 카테고리 바로가기 편집 모달 UX 개선(비활성 필드 시각적 구분)

### 4.2 Out of Scope

- 섹션별 다국어, 노출기간 예약, 회원등급별 분기, 2차원 그리드 배치 — (from YAGNI Review)
- 다점포별 홈 화면 분기 — shopping-mall과 동일하게 `MALL_STORE_ID` 단일 점포 기준 유지

---

## 5. Requirements

### 5.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 관리자는 배너/카테고리 바로가기/상품 리스트 3종 섹션을 추가할 수 있다 | High | Pending |
| FR-02 | 관리자는 섹션을 드래그해 순서를 변경할 수 있다 | High | Pending |
| FR-03 | 관리자는 섹션을 삭제하거나 노출 ON/OFF로 토글할 수 있다 | High | Pending |
| FR-04 | 배너 섹션은 이미지 업로드와 클릭 링크(URL/카테고리/상품) 설정을 지원한다 | High | Pending |
| FR-05 | 상품 리스트 섹션은 관리자가 검색해 선택한 특정 상품들을 수동으로 담는다 | High | Pending |
| FR-06 | 관리자는 저장 전 실제 고객 화면과 동일한 렌더링으로 미리보기를 볼 수 있다 | Medium | Pending |
| FR-07 | 고객 홈 화면(`mall/index.php`)은 노출중인 섹션을 저장된 순서대로 렌더링한다 | High | Pending |
| FR-08 | 카테고리 바로가기 섹션 클릭 시 상단 가로 탭 내비게이션이 있는 `mall/category.php`로 이동한다 | High | Pending |
| FR-09 | `mall/category.php`는 선택된 카테고리의 상품을 `mall_calculate_price()` 경유로 등급별 가격과 함께 표시한다 | High | Pending |
| FR-10 | 비회원도 홈 화면과 카테고리 화면을 열람할 수 있다 | Medium | Pending |
| FR-11 | 관리자는 배너 섹션을 이미지 없이도 우선 추가할 수 있다 | High | Pending |
| FR-12 | 관리자가 편집하는 섹션은 항상 "초안(draft)" 상태이며, 고객 화면은 "적용(published)" 상태만 반영한다 | High | Pending |
| FR-13 | 관리자가 "적용" 버튼을 누르면 확인 다이얼로그 후 초안 전체가 즉시 고객 화면에 반영된다 | High | Pending |
| FR-14 | 관리자 화면은 마지막 발행 시각과 발행자를 표시한다 | Medium | Pending |
| FR-15 | 이미지가 없는 배너는 관리자/미리보기 화면에서 플레이스홀더로 표시된다 | Medium | Pending |
| FR-16 | 카테고리 바로가기 섹션 편집 시 현재 연결된 카테고리를 명확하게 확인/변경할 수 있다 | High | Pending |

### 5.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Security | 상품 리스트 섹션도 `pricing.php` 단일 경로로만 가격을 계산하며, 도매가가 미승인/소매 회원에게 노출되지 않는다 | 코드 리뷰 + 승인 전/후 계정으로 수동 검증 |
| Security | 배너 이미지 업로드는 기존 `save_retail_product.php`와 동일한 MIME 화이트리스트 + 파일명 난수화 적용 | 코드 리뷰 |
| Security | 섹션 CRUD ajax는 CSRF 토큰 검증 + `mall_management` 권한 체크 적용 (shopping-mall Check phase에서 확립된 패턴 재사용) | 코드 리뷰 |
| Correctness | 원가/합계 금액은 소숫점 둘째자리까지 표시 (CLAUDE.md 규칙) | 화면 검수 |
| Regression Safety | 신규 테이블/파일이 폐기된 `layout_rows`/`layout_columns`/`layout_presets`/`display_sections`/`product_displays`/`shop_*`와 이름이 절대 겹치지 않음 | 적용 전 전체 테이블명 대조 |

---

## 6. Success Criteria

### 6.1 Definition of Done

- [ ] 모든 FR-01 ~ FR-10 구현 완료
- [ ] `sql/mall_home_sections.sql`이 실제 라이브 DB에 오류 없이 적용됨 (기존/폐기된 테이블명과 충돌 없음)
- [ ] 관리자 드래그 순서변경, 노출토글, 배너/상품리스트 섹션 저장이 실제로 동작 확인
- [ ] 고객 홈 화면과 카테고리 화면에서 비회원/소매/도매(승인·미승인) 각 계정으로 가격 노출 정책 검증 완료
- [ ] 코드 리뷰 완료

### 6.2 Quality Criteria

- [ ] `php -l`로 신규 파일 전체 문법 검증 통과
- [ ] 섹션 렌더링 함수가 admin 미리보기와 고객 화면에서 코드 중복 없이 공유됨
- [ ] 신규 admin 화면이 기존 `mall_management` 권한 체계를 따름

---

## 7. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 폐기된 이전 레이아웃 빌더와 동일한 실패(존재하지 않는 테이블 참조, 인증 없음, 과도한 테이블 분산)를 반복 | High | Low | Design phase에서 실제 라이브 스키마 대조 필수, 처음부터 `mall_management` 권한+CSRF 적용, 단일 테이블 구조 채택으로 관리 포인트 최소화 |
| 상품 리스트 섹션이 `pricing.php`를 우회해 가격을 직접 조회 | High | Low | Design에서 `mall/lib/home_layout.php`의 상품 리스트 렌더 함수가 `mall_calculate_price()`만 호출하도록 명시 |
| `mall/index.php` 역할 변경으로 기존 카테고리 드롭다운 필터 사용자 흐름이 깨짐 | Medium | Medium | `category.php`로 동일 기능을 이전하고, 홈 화면에 카테고리 바로가기 섹션을 기본 제공해 탐색 경로 유지 |
| 배너 이미지 업로드가 새로운 업로드 경로를 열어 보안 취약점 추가 | Medium | Low | 기존 `save_retail_product.php`의 검증된 업로드 보안 로직(화이트리스트+난수화+웹루트 내 격리)을 그대로 재사용 |
| 드래그 순서 변경 중 동시 편집(관리자 2명)으로 sort_order 충돌 | Low | Low | v1은 단일 관리자 순차 운영을 가정, 저장 시 서버가 전체 순서를 통째로 재기록(낙관적 락 없음, 필요시 v2에서 검토) |
| **(v0.2)** `status` 컬럼 추가 마이그레이션 시점에 기존 라이브 섹션이 순간적으로 사라짐 | High | Medium | 마이그레이션 스크립트가 ALTER 직후 기존 행을 자동 복제해 `published` 상태로도 만들어 무중단 전환 |
| **(v0.2)** "적용" 버튼을 실수로 눌러 원치 않는 초안이 바로 발행됨 | Medium | Medium | 확인 다이얼로그 필수 적용(FR-13). 버전 이력/롤백은 범위 밖(2.5 참조)이므로 실수 시 관리자가 다시 원래 상태로 초안을 수정해 재발행해야 함 — 이 한계를 관리자에게 안내 |

---

## 8. Architecture Considerations

### 8.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure | Static sites, portfolios | |
| **Dynamic** | Feature-based modules, 기존 PHP/MySQL 구조 확장 | 백엔드 있는 웹앱 | ✅ |
| **Enterprise** | 마이크로서비스, 엄격한 계층분리 | 대규모 시스템 | |

### 8.2 Key Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 데이터 모델 | 단일 테이블+JSON / 타입별 개별 테이블 | 단일 테이블(`mall_home_sections`)+JSON | 폐기된 이전 빌더의 5테이블 과설계 반복 회피 |
| 배치 방식 | 세로 섹션 순서 / 2차원 그리드 | 세로 섹션 순서 | 실제 이커머스 홈(쿠팡/11번가 등)과 동일한 단순 패턴, 구현/테스트 범위 축소 |
| 드래그 라이브러리 | 외부 라이브러리(SortableJS 등) / 네이티브 HTML5 Drag&Drop | 네이티브 | 프로젝트 전체가 번들러 없는 vanilla JS 구조라 일관성 유지, 신규 의존성 추가 없음 |
| 미리보기 방식 | 별도 JS 렌더링 중복 / 실제 렌더 함수 공유 | 실제 렌더 함수 공유 (`mall/lib/home_layout.php`) | 미리보기와 실제 화면의 불일치(드리프트) 방지 |
| index.php 역할 | 그대로 유지 / 홈 화면으로 전환 | 홈 화면으로 전환, 카테고리 필터는 `category.php`로 이전 | 사용자가 명시적으로 "메인화면 레이아웃"을 요청, 카테고리 탐색은 별도 화면이 자연스러움 |
| 대상 열람자 | 로그인 필수 / 비회원 포함 전체 | 비회원 포함 전체 | 기존 `product.php` 열람 정책과 일관성 유지 |
| **(v0.2)** 초안/발행 구분 방식 | `status` 컬럼 / 별도 버전 이력 테이블 | `status` 컬럼(draft/published) | 기존 `preview_home.php`를 그대로 "초안 미리보기"로 재활용 가능, 테이블 추가 없음 |
| **(v0.2)** 발행 단위 | 섹션별 개별 발행 / 레이아웃 전체 일괄 발행 | 전체 일괄 발행 | 사용자가 "전체 레이아웃을 하나의 초안으로" 명시적으로 선택 |
| **(v0.2)** 발행 이력 | 상세 로그 테이블 / 컬럼 2개(published_at, published_by)만 | 컬럼만 | 롤백 기능은 범위 밖, "마지막 발행 시각/발행자" 표시만 필요 |

### 8.3 Component Overview

```
W:\sunset\mall\
├── index.php                      ← 변경: 섹션 순서 렌더링(홈 화면)으로 역할 전환
├── category.php                   ← 신규: 가로 카테고리 탭 + 카테고리별 상품 그리드
├── lib/
│   ├── home_layout.php            ← 신규: 섹션 조회 + 타입별 렌더 함수(admin/고객 공유)
│   └── catalog.php                ← 신규: 노출대상 상품 UNION 조회 + 카드 배열 생성(기존 index.php 로직 추출)
├── uploads/banners/                ← 신규: 배너 이미지 저장 디렉토리(실행권한 제거)
│
└── admin/
    ├── home_layout.php            ← 신규: 섹션 목록(드래그)/추가·편집 모달/노출토글/미리보기 버튼
    ├── preview_home.php           ← 신규: 저장 전 실제 렌더 함수로 미리보기(새 창)
    └── ajax/
        ├── save_home_section.php      신규: 섹션 추가/수정(타입별 config JSON), 배너 이미지 업로드 포함
        ├── delete_home_section.php    신규: 섹션 삭제
        ├── reorder_home_sections.php  신규: 드래그 순서 일괄 저장
        └── publish_home_layout.php    (v0.2) 신규: draft → published 트랜잭션 복제

sql/mall_home_sections.sql         ← 신규 테이블 (mall_ 접두사)
sql/mall_home_sections_v2_status.sql ← (v0.2) 신규: status/published_at/published_by 컬럼 ALTER + bootstrap 발행
```

### 8.4 Data Flow

```
[관리자]
home_layout.php 접속 → mall_home_sections 목록 로드(sort_order순)
  → 드래그로 순서 변경 → ajax/reorder_home_sections.php (sort_order 일괄 갱신)
  → "섹션 추가/편집" 모달 → ajax/save_home_section.php (타입별 config JSON 저장, 배너는 이미지 업로드 포함)
  → 노출 ON/OFF 토글 → 같은 save 엔드포인트로 is_active만 갱신
  → "미리보기" 버튼 → preview_home.php 새 창 (mall_get_active_home_sections() 그대로 렌더링, 실제 고객 화면과 동일 코드)

[고객 홈]
index.php 접속 → mall_get_active_home_sections() 조회 → 섹션 순서대로 렌더
  - 배너 섹션 → 클릭 시 link_type에 따라 category.php?id=X / product.php?id=X / 외부 URL
  - 카테고리 바로가기 섹션 → 클릭 시 category.php?id=X 이동
  - 상품 리스트 섹션 → config.product_ids로 mall_calculate_price() 호출해 카드 그리드 표시

[카테고리 탐색]
category.php?id=X 접속 → 상단 가로 탭(전체 카테고리, 현재 선택 강조)
  → mall_get_eligible_products(channel, category_id, search)로 조회 → 카드 그리드

[(v0.2) 발행]
관리자가 초안(status=draft)을 원하는 만큼 수정 → "적용" 버튼 → 확인 다이얼로그 →
  ajax/publish_home_layout.php:
    BEGIN TRANSACTION
    DELETE FROM mall_home_sections WHERE store_id=? AND status='published'
    INSERT ... SELECT (draft 전체를 published로 복제, published_at=NOW(), published_by=관리자ID)
    COMMIT
  → 고객 홈(index.php)이 다음 요청부터 새 published 세트를 즉시 반영
  → 관리자 화면 상단에 "최근 발행: {published_at} · {published_by}" 갱신
```

---

## 9. Convention Prerequisites

### 9.1 Applicable Conventions

- [x] 기존 프로젝트 컨벤션 확인됨 (CLAUDE.md: PHP + MySQLi/PDO, TailwindCSS, 한국어 UI, 원가/합계 소숫점 둘째자리 표시, 새 SQL 쿼리는 별도 파일)
- [x] shopping-mall 컨벤션 확인됨 (`mall_` 접두사, `pricing.php` 단일 가격경로, CSRF 토큰, `mall_management` 권한)
- [x] 명명 규칙 확인됨 (신규 테이블 `mall_home_sections`, 폐기된 `layout_*`/`display_sections`/`product_displays`와 이름 겹치지 않음)
- [x] 폴더 구조 규칙 확인됨 (`mall/`, `mall/admin/` 기존 구조 확장)

---

## 10. Next Steps

1. [x] 설계 문서 작성 (`/pdca design mall-home-layout`) — v0.1 완료
2. [x] `sql/mall_home_sections.sql` 스키마를 실제 라이브 DB와 대조 검증 — v0.1 완료
3. [x] 구현 시작 (`/pdca do mall-home-layout`) — v0.1 module-1~3 완료, Check phase 완료
4. [ ] **(v0.2)** 설계 문서 갱신 (`/pdca design mall-home-layout`) — status 컬럼, publish_home_layout.php, 배너 플레이스홀더, 카테고리편집 UX 반영
5. [ ] **(v0.2)** 구현 (`/pdca do mall-home-layout`)

---

## Appendix: Brainstorming Log

| Phase | Question | Answer | Decision |
|-------|----------|--------|----------|
| Context | 폐기된 이전 레이아웃 빌더 존재 확인 | `8a5d590` 커밋에서 45개 파일 전량 폐기(존재하지 않는 테이블 참조, 인증 없음) | 동일 실패를 반복하지 않는 것을 최우선 제약으로 설정 |
| Intent | 핵심 목적 | 관리자가 첫 화면을 자유롭게 꾸미는 것 | 홈 화면 큐레이션 빌더로 범위 확정, 카테고리 탐색은 하위 흐름 |
| Intent | 대상 열람자 | 비회원 포함 누구나 | 로그인은 장바구니/주문 시점에만 요구(기존 product.php 정책과 동일) |
| Intent | 드래그 배치 범위 | 세로 섹션 순서만 | 2차원 그리드(폐기된 빌더의 layout_rows/columns와 유사) 배제 |
| Alternatives | 데이터 모델 A/B 비교 | A(단일 테이블+JSON) 선택 | 관리 포인트 최소화, 폐기된 빌더의 5테이블 과설계 회피 |
| YAGNI | 추가 기능 선택 | 노출토글, 상품리스트 수동선택, 실시간 미리보기, 섹션별 제목/부제 편집 전부 포함 | 다국어/예약노출/등급별분기/2D그리드만 제외 |
| Design | 아키텍처 개요 | 승인 | 파일 구성, 단일 테이블, 미리보기 로직 공유 확정 |
| Design | 컴포넌트 구성 | 승인 | catalog.php 분리, index.php 역할 변경, 네이티브 Drag&Drop 확정 |
| Design | 데이터 흐름 | 승인 | 관리자/고객/카테고리 탐색 3개 흐름 확정 |
| **(v0.2)** Context | 실사용 후 피드백 3건 접수 | 이미지없는배너 허용, 초안/적용 구분, 카테고리편집 못찾음 | 세 요구를 하나의 Plan Plus 라운드로 묶어 진행 |
| **(v0.2)** Context | 카테고리 편집 "안 보임" 원인 조사 | 실제로는 동작하나, 편집 모달의 비활성화된 섹션타입 필드 때문에 전체 폼이 잠긴 것처럼 보이는 UX 문제로 확인 | 기능 추가가 아닌 UX 개선(시각적 구분)으로 범위 확정 |
| **(v0.2)** Intent | 카테고리 편집 의미 확인 | "이 섹션이 가리키는 카테고리를 바꾸기" | 카테고리 마스터 데이터(이름 등) 관리는 범위 밖, `admin/category_management.php`가 이미 담당 |
| **(v0.2)** Intent | 초안/적용 동작 방식 | "전체 레이아웃을 하나의 초안으로 관리" | 섹션별 개별 상태가 아닌 레이아웃 전체 일괄 발행으로 확정 |
| **(v0.2)** Alternatives | 초안/발행 구현 A/B 비교 | A(status 컬럼) 선택 | 테이블 추가 없이 기존 미리보기 인프라 재활용 |
| **(v0.2)** YAGNI | 추가 항목 선택 | 확인 다이얼로그, 마지막 발행 시각 표시, 배너 플레이스홀더, 발행 이력 컬럼(published_at/by) 전부 포함 | 버전 이력/롤백, 섹션별 부분발행은 제외 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-13 | Initial draft (Plan Plus) | whdans007 |
| 0.2 | 2026-08-13 | 실사용 피드백 반영(Plan Plus 재실행): FR-11~16 추가(이미지없는배너, 초안/발행 status 컬럼, 적용버튼+확인다이얼로그, 마지막발행시각표시, 배너플레이스홀더, 카테고리편집 UX개선), §2.4/2.5 초안발행 아키텍처 옵션 비교, §7 마이그레이션 무중단/실수발행 리스크 추가 | whdans007 |
