---
template: plan
version: 1.3
---

# mall-home-product-instant-apply Planning Document

> **Summary**: 몰 홈 화면(오늘의특가/새상품/기획전상품)의 "상품 목록"은 저장 즉시 고객 화면에 반영되도록 하고, 배너/제목/노출여부 같은 "레이아웃"은 지금처럼 발행 버튼을 눌러야 반영되는 구조를 유지한다
>
> **Project**: HOME K MART 관리 프로그램
> **Version**: 1.1.0
> **Author**: whdans007
> **Date**: 2026-09-23
> **Status**: Draft (Codex 1회 상의 반영 완료)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | `mall/admin/products.php`의 "홈 노출"(오늘의특가/기획전/새상품) 메뉴에서 상품을 추가/제거해도, 관리자가 `mall/admin/home_layout.php`에서 별도로 "발행" 버튼을 누르지 않으면 고객 화면(homekmart.net)에 반영되지 않는다. 관리자가 이 절차를 모르거나 잊으면 "상품을 등록했는데 왜 안 보이냐"는 혼란이 생긴다 |
| **Solution** | `mall_home_sections`의 published 조회 시, 상품 목록 슬롯(오늘의특가/새상품/기획전상품)에 한해 title/subtitle/is_active/sort_order는 published 값을 그대로 쓰되 product_ids/fresh_product_ids만 최신 draft 값으로 교체해서 반환한다. 배너(promo_banner)는 대상에서 제외해 지금처럼 발행 절차 유지 |
| **Function/UX Effect** | 관리자가 products.php에서 상품을 진열에 추가/제거하면 저장 즉시 고객 화면에 반영된다. 단, 해당 슬롯이 한 번도 발행된 적 없으면(신규 슬롯) 최초 1회는 여전히 "발행"을 눌러야 슬롯 자체가 노출된다 |
| **Core Value** | 관리자가 매번 "발행"을 눌러야 한다는 사실을 몰라도 상품 진열 변경이 바로 반영되어 실수 여지가 줄고, 배너/제목 같은 디자인성 변경은 여전히 검토 후 발행하는 안전판이 유지됨 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 상품 진열 변경이 "발행" 버튼을 눌러야만 반영되는 절차를 관리자가 인지하지 못해 "등록했는데 반영이 안 된다"는 혼란 발생 |
| **WHO** | `mall/admin/products.php`/`home_layout.php`를 쓰는 관리자(`mall_management` 권한 보유자), 그리고 그 결과를 보는 몰 고객 |
| **RISK** | published config를 draft config로 통째로 덮어쓰면 향후 config에 레이아웃 성격 키가 추가될 때 미검토 값이 새어나갈 수 있음 → product_ids/fresh_product_ids 두 필드만 선택적으로 병합해 방지 |
| **SUCCESS** | products.php에서 상품 토글 후 새로고침 없이/발행 없이 홈 화면·기획전 화면에 즉시 반영. 배너/제목/노출순서는 발행 전까지 변경 없음. 한 번도 발행 안 된 신규 슬롯은 상품을 넣어도 안 보임 |
| **SCOPE** | v1: `mall_get_active_home_sections()`/`mall_get_home_slot()` 조회 로직 변경 + 발견된 연관 버그 2건 수정. DB 스키마 변경 없음, 신규 엔드포인트 없음 |

---

## Codex 사전 상의 요약

`codex exec --sandbox read-only`로 설계안(Option A: published 기준 조회에 draft의 product_ids만 병합)을 1회 검토받았다. 큰 방향은 그대로 채택하되 다음을 반영해 안전하게 다듬었다.

| Codex가 지적한 점 | 반영 내용 |
|---|---|
| draft의 `config` JSON 전체를 published에 덮어쓰면, 앞으로 config에 레이아웃성 키가 추가될 때 미검토 값이 새어나갈 위험 | config 전체가 아니라 `product_ids`/`fresh_product_ids` 두 필드만 PHP에서 병합 |
| "published 행이 없으면 안 보여야 함" 제약을 PHP 조건문으로만 보장하면 실수로 깨지기 쉬움 | published를 기준(LEFT) 테이블로, draft를 `LEFT JOIN`하는 SQL로 구조적으로 보장(published 없으면 결과 자체가 없음) |
| draft가 없거나 config JSON이 깨졌을 때의 fallback 정책 불명확 | draft가 없거나 파싱 실패 시 published의 기존 config 값으로 fallback(에러 로그만 남기고 조용히 안전한 값 사용) |
| (발견된 기존 버그, 이번 변경과 무관하게 존재) `mall_render_today_deals_section()`/`mall_render_new_arrivals_section()`이 `product_ids`가 비어 있으면 `fresh_product_ids`가 있어도 섹션 전체를 렌더링하지 않음(`home_layout.php:167`, `:230`) | 이번 스코프에 포함해 "두 배열이 모두 비었을 때만" 빈 문자열을 반환하도록 수정 |
| (발견된 기존 버그) `save_home_section.php`가 레이아웃(제목/노출여부)만 저장해도 config를 다시 쓰면서 `fresh_product_ids`를 보존하지 않아 유실될 수 있음(`save_home_section.php:85` 부근) | 이번 스코프에 포함해 기존 `fresh_product_ids`를 보존하도록 수정 |
| `toggle_home_section_product.php`의 read-modify-write(같은 슬롯 동시 편집 시 lost update 가능) | 기존부터 있던 문제이고 이번 변경으로 새로 생기는 문제가 아니므로 **이번 스코프에서는 제외**, §5 리스크에 기록만 남김 |

---

## 1. Overview

### 1.1 Purpose

몰 홈 화면의 "상품 목록"(오늘의특가/새상품/기획전상품에 담긴 상품)을 관리자가 products.php에서 추가/제거하면 발행 절차 없이 즉시 고객 화면에 반영되게 하고, 배너/제목/노출순서 같은 "레이아웃"은 지금처럼 발행 절차를 유지한다.

### 1.2 Background

- `mall_home_sections` 테이블은 slot_key(`promo_banner`/`today_deals`/`new_arrivals`, 그리고 홈이 아닌 기획전 페이지용 `promo_products`)별로 draft/published 두 버전을 갖는다(`mall/lib/home_layout.php:36-41`).
- 상품 목록 슬롯(`today_deals`/`new_arrivals`/`promo_products`)의 config는 `{product_ids, fresh_product_ids}`만 담고 있고, title/subtitle/is_active/sort_order는 행 자체 컬럼이라 config와 분리되어 있다.
- `mall/admin/products.php`의 "홈 노출" 토글은 `mall/admin/ajax/toggle_home_section_product.php`를 통해 **draft** 행의 config만 수정한다(`toggle_home_section_product.php:96`).
- 고객 화면(`mall/index.php:11`)과 기획전 화면(`mall/promo.php:21`)은 항상 **published** 상태만 조회한다.
- draft → published 반영은 `mall/admin/home_layout.php`의 "발행" 버튼 → `ajax/publish_home_layout.php`가 draft 전체를 스냅샷 복사하는 방식으로만 이뤄진다.
- 이미 존재하는 유사 패턴: `mall_products.promo_type/promo_value/promo_was`(상품별 1+1/할인)는 draft/publish 없이 저장 즉시 반영된다(`mall_render_today_deals_section()`이 직접 `mall_products`를 조회, `home_layout.php:178-199`) — 이번 변경은 이 패턴을 상품 목록에도 일관되게 적용하는 것.

### 1.3 Related Documents

- 관련 코드: `mall/lib/home_layout.php`, `mall/admin/ajax/toggle_home_section_product.php`, `mall/admin/ajax/publish_home_layout.php`, `mall/admin/ajax/save_home_section.php`, `mall/index.php`, `mall/promo.php`, `mall/admin/preview_home.php`

---

## 2. Scope

### 2.1 In Scope

- [ ] `mall_get_active_home_sections($customer_facing, $status)` — `$status === 'published'`일 때 `today_deals`/`new_arrivals` 슬롯에 한해 같은 store의 draft config에서 `product_ids`/`fresh_product_ids`만 가져와 병합. published 행이 없는 슬롯은 결과에 포함되지 않도록 SQL 구조로 보장(published 기준 LEFT JOIN draft)
- [ ] `mall_get_home_slot($slot_key, $status)` — 동일 로직을 `promo_products` 슬롯에 적용(`mall/promo.php`가 이 함수로 published를 조회하므로)
- [ ] draft 행이 없거나 config JSON 파싱 실패 시 published의 기존 config로 안전하게 fallback(에러 로그 기록)
- [ ] (연관 버그 수정) `mall_render_today_deals_section()`/`mall_render_new_arrivals_section()`이 `product_ids`와 `fresh_product_ids`가 **둘 다** 비어 있을 때만 빈 문자열을 반환하도록 수정
- [ ] (연관 버그 수정) `save_home_section.php`가 제목/노출여부만 저장할 때 기존 `fresh_product_ids`를 config에 보존하도록 수정
- [ ] `toggle_home_section_product.php`, `publish_home_layout.php`는 코드 변경 없음(그대로 동작하되, 읽기 단계에서 draft 값으로 덮어써지므로 결과적으로 무해)

### 2.2 Out of Scope (v1)

- `promo_banner`(배너 이미지/문구/링크) — 순수 레이아웃이라 발행 절차 그대로 유지, 이번 변경 대상 아님
- `toggle_home_section_product.php`의 동시 편집 lost-update 개선(트랜잭션/`FOR UPDATE`) — 기존부터 있던 문제이며 이번 요구사항과 무관, 필요 시 별도 계획
- `mall/admin/home_layout.php`, `mall/admin/products.php` 화면의 UI 문구/안내 변경(예: "상품 목록은 즉시 반영됩니다" 안내) — 필요하면 사용자 확인 후 별도로 반영
- DB 스키마 변경, 신규 AJAX 엔드포인트 추가

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | products.php에서 오늘의특가/새상품/기획전상품에 상품을 추가하면, 이미 발행된 적 있는 슬롯이라면 새로고침 시 홈/기획전 화면에 즉시 반영된다 | High | Pending |
| FR-02 | 같은 방식으로 상품을 제거하면 즉시 화면에서 사라진다 | High | Pending |
| FR-03 | 배너 이미지/문구, 섹션 제목, 섹션 노출여부/순서는 "발행" 버튼을 누르기 전까지 변경되지 않는다(기존 동작 유지) | High | Pending |
| FR-04 | 슬롯이 한 번도 발행된 적 없으면(published 행 없음), 상품을 추가해도 고객 화면에 보이지 않는다 | High | Pending |
| FR-05 | 신선상품만 담긴 슬롯도(일반상품 없이) 정상적으로 렌더링된다 | Medium | Pending |
| FR-06 | 홈 레이아웃 화면에서 제목/노출여부만 저장해도 기존에 담겨 있던 신선상품 목록이 유실되지 않는다 | Medium | Pending |
| FR-07 | 관리자 미리보기(`preview_home.php`)의 published 뷰도 실제 고객 화면과 동일하게 최신 상품 목록을 보여준다 | Medium | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| 데이터 정합성 | 병합은 `product_ids`/`fresh_product_ids` 두 필드로 한정, 다른 config 키는 손대지 않음 | 코드 리뷰 |
| 안전성 | draft 조회 실패/JSON 오류 시 published 값으로 안전하게 fallback, 예외로 화면이 깨지지 않음 | 코드 리뷰 + 수동 테스트 |
| 회귀 방지 | 배너/제목/노출순서의 발행 절차는 기존과 동일하게 동작 | 수동 테스트 |
| 성능 | 슬롯 3개 수준이라 추가 쿼리 1회로도 문제 없음 | 코드 리뷰 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] `mall_get_active_home_sections()`/`mall_get_home_slot()` 수정 및 draft/published 병합 로직 구현
- [ ] 연관 버그 2건(신선상품 단독 슬롯 미노출, save_home_section의 fresh_product_ids 유실) 수정
- [ ] `php -l` 린트 통과
- [ ] 코드 리뷰 완료(Claude Code)
- [ ] 수동 테스트: 테스트서버에서 상품 토글 → 발행 없이 홈/기획전 화면 반영 확인, 배너/제목은 발행 전 미반영 확인, 미발행 신규 슬롯은 상품 추가해도 미노출 확인

### 4.2 Quality Criteria

- [ ] 신규 스키마/엔드포인트 없이 기존 구조로 구현됨
- [ ] `promo_banner` 슬롯 동작에 변화 없음(회귀 없음)
- [ ] `products.php`의 draft 기준 "홈 노출 여부" 판단 로직에 영향 없음(status 기본값 draft라 이번 변경과 무관함을 코드 리뷰로 확인)

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| draft config가 깨져 있거나(JSON 파싱 실패) 없을 때 화면이 깨짐 | Medium | Low | published 기존 값으로 fallback + `error_log()` 기록 |
| 두 관리자가 같은 슬롯을 동시에 토글하면 나중 저장이 먼저 저장을 덮어쓸 수 있음(기존 문제) | Low | Low | 이번 스코프에서는 개선하지 않음, 필요 시 별도 계획(트랜잭션 + `SELECT ... FOR UPDATE`)으로 분리 |
| 관리자가 "상품은 즉시 반영, 레이아웃은 발행 필요"라는 이원화된 규칙을 헷갈려할 수 있음 | Low | Medium | UI 안내 문구 추가는 out-of-scope로 남기되, 필요성이 확인되면 후속으로 반영 |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `mall/lib/home_layout.php` | PHP 라이브러리 | `mall_get_active_home_sections()`, `mall_get_home_slot()`에 published+draft 병합 로직 추가. `mall_render_today_deals_section()`/`mall_render_new_arrivals_section()`의 빈 값 판단 조건 수정 |
| `mall/admin/ajax/save_home_section.php` | AJAX 엔드포인트 | 제목/노출여부 저장 시 기존 `fresh_product_ids` 보존하도록 수정 |
| `mall/admin/ajax/toggle_home_section_product.php` | AJAX 엔드포인트 | **변경 없음** |
| `mall/admin/ajax/publish_home_layout.php` | AJAX 엔드포인트 | **변경 없음** |
| `mall_home_sections` 테이블 | DB 스키마 | **변경 없음** |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `mall_get_active_home_sections(true, 'published')` | READ | `mall/index.php:11` | 상품 목록만 즉시 최신화, title/subtitle/is_active는 기존과 동일(published 기준) |
| `mall_get_active_home_sections($is_customer_facing, $view)` | READ | `mall/admin/preview_home.php:16` | `$view === 'published'`일 때 동일 병합 적용 — 미리보기와 실제 화면 일치 |
| `mall_get_home_slot('promo_products', 'published')` / `mall_get_home_slot('promo_banner', 'published')` | READ | `mall/promo.php:21,31` | `promo_products`만 병합 대상, `promo_banner`는 영향 없음 |
| `mall_get_home_slot($slot_key)`(기본값 draft) | READ | `mall/admin/products.php:77`, `mall/admin/home_layout.php:30-33`, `save_home_section.php:54,89` | status가 draft라 이번 변경(=`status==='published'`일 때만 병합)과 무관, 영향 없음 |

### 6.3 Verification

- [ ] 이미 발행된 적 있는 슬롯에서 상품 추가/제거가 발행 없이 홈/기획전 화면에 반영됨을 확인
- [ ] 배너 이미지/문구, 섹션 제목/노출순서는 발행 전까지 변경되지 않음을 확인(회귀 없음)
- [ ] 한 번도 발행되지 않은 신규 슬롯은 상품을 추가해도 고객 화면에 보이지 않음을 확인
- [ ] 신선상품만 담긴 슬롯이 정상 렌더링됨을 확인
- [ ] 레이아웃(제목/노출여부)만 저장해도 기존 신선상품 목록이 유실되지 않음을 확인

---

## 7. Architecture Considerations

> 이 프로젝트는 PHP 서버 렌더링 + MySQLi 구조로 Next.js 템플릿 항목(7.1)은 해당 없음(N/A).

### 7.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| published 없는 슬롯의 미노출 보장 방식 | PHP 조건문으로 체크 vs SQL 구조(LEFT JOIN 기준 테이블)로 보장 | SQL 구조(published 기준 + draft LEFT JOIN) | Codex 권고 — PHP 조건문 실수에 의존하지 않고 쿼리 자체가 제약을 강제 |
| draft→published 병합 범위 | config 전체 교체 vs product_ids/fresh_product_ids 필드만 교체 | 필드만 교체 | Codex 권고 — 향후 config에 레이아웃 키가 추가돼도 미검토 값이 새지 않음 |
| draft 조회 실패 시 동작 | 예외 발생 vs published 값으로 fallback | fallback + 로그 | 고객 화면이 죽지 않는 것이 우선, 문제는 로그로 추적 |

---

## 8. Convention Prerequisites

### 8.1 담당 파일 범위 (agent-orchestration.md 기준)

| 파일 | 작업 유형 | 담당 제안 |
|------|-----------|-----------|
| `mall/lib/home_layout.php`, `mall/admin/ajax/save_home_section.php` | 스펙이 명확한 쿼리/조건 로직 수정, 스키마 변경 없음 | Codex — 이 계획에서 SQL 구조와 병합 규칙이 이미 확정됨 |
| 최종 검증(발행 안 됨/발행됨 슬롯 동작 차이, fallback 동작) | 도메인 판단 | Claude Code가 Codex 구현 결과를 리뷰 + 테스트서버 수동 확인 |

> 신규 컬럼/엔드포인트 없이 `mall/lib/home_layout.php` 한 파일 + `save_home_section.php` 부수 수정으로 끝나는 범위라 Codex에게 구현을 위임하고, Claude Code는 `php -l` + 코드 리뷰 + 테스트서버 수동 확인을 담당하는 것을 권장한다.

---

## 9. Next Steps

1. [x] Codex와 1회 상의 → Option A 채택, 병합 범위/SQL 구조/fallback 정책 확정
2. [ ] 사용자 최종 승인
3. [ ] Codex에게 구현 위임(파일 범위: `mall/lib/home_layout.php`, `mall/admin/ajax/save_home_section.php`) → Claude Code가 `php -l` + 코드 리뷰 + 테스트서버 수동 확인으로 검증
4. [ ] 검증 완료 후 운영서버(homekmart.net) 반영 여부 사용자와 확인

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-23 | Initial draft — Codex 1차 검토 반영(필드 단위 병합, SQL 구조 보장, fallback 정책, 연관 버그 2건 스코프 포함) | whdans007 |
