---
template: plan
version: 1.3
---

# fresh-margin-management Planning Document

> **Summary**: 신선식품(과일/채소/정육/수산) 카테고리별 마진율을 관리하고, 매입 등록 시 박스판매가/낱개판매가를 자동 계산해 저장하는 기능
>
> **Project**: HOME K MART 관리 프로그램
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-09-06
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 신선식품(`mall_fresh_products`)은 매입 시 원가(박스원가/단위원가)만 기록될 뿐 판매가 개념이 없어, 관리자가 매입할 때마다 카테고리 마진율을 암산해 판매가를 수동으로 별도 관리해야 함 |
| **Solution** | 카테고리별 마진율(기본 과일/채소/정육/수산 각 40%)을 관리하는 신규 화면과 `fresh_margin_rules` 테이블을 만들고, 매입 항목 저장 시점에 마진율을 적용해 박스판매가/낱개판매가를 자동 계산 후 `mall_fresh_products`에 저장 |
| **Function/UX Effect** | 매입 등록만 하면 판매가가 자동으로 갱신되어 별도 계산 작업이 사라지고, `fresh_products.php` 목록에서 박스판매가/낱개판매가를 바로 확인 가능. 필요 시 상품 수정 화면에서 수동 보정도 가능 |
| **Core Value** | 마진 관리 체계를 신선식품에도 일관되게 적용해 가격 정책 실수를 줄이고, 매입-판매가 연동을 자동화해 운영 부담을 낮춤 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 신선식품 매입 시 판매가를 수동 계산/관리해야 하는 번거로움과 실수 가능성 |
| **WHO** | 신선식품 매입을 등록하는 관리자/직원, 마진율을 설정하는 관리자 |
| **RISK** | 기존 `margin_rules`(정가상품용)와 명칭·개념이 혼동될 수 있음 → 별도 테이블/헬퍼로 명확히 분리 |
| **SUCCESS** | 매입 등록 후 `fresh_products.php`에서 박스판매가/낱개판매가가 자동으로 표시됨, 마진율 화면에서 4개 카테고리 값을 저장할 수 있음 |
| **SCOPE** | v1: 마진율 CRUD 화면 + 매입 연동 자동계산 + 목록 컬럼 추가 + 수동 override. v2 보류: 마진율 변경 시 일괄 재계산 |

---

## Codex 사전 상의 요약

> 실제 구현 지시 전, 이 계획 초안을 Codex(`codex exec --sandbox read-only`)에게 공유해 검토받았다 (사용자 요청: 계획 확정 전 Codex와 상의). 아래는 그 결과 반영한 주요 변경점.

| Codex가 지적한 문제 | 원래 초안 | 반영한 결정 |
|---|---|---|
| `mall_fresh_products.price_per_100g`가 이미 낱개/100g당 판매가로 존재하고 몰 주문금액 계산 설계의 기준 필드임 (신규 `unit_sale_price` 컬럼을 만들면 판매가 소스가 두 개가 됨) | `box_sale_price`, `unit_sale_price` 두 컬럼 신규 생성 | `box_sale_price`만 신규 생성, "낱개판매가"는 기존 `price_per_100g` 재사용 |
| 과거 날짜로 매입 등록 시 "방금 입력한 값" 기준으로 갱신하면 최신 판매가를 잘못 덮어씀 | 입력 행 값을 그대로 UPDATE에 사용 | 트랜잭션 내에서 `purchase_date DESC, id DESC LIMIT 1`로 재조회한 실제 최신 매입 기록 기준으로 계산 (FR-03) |
| 같은 배치에 동일 상품 중복 입력 시 계산 기준 모호 | 별도 처리 없음 | 배치 내 동일 상품 중복 입력을 서버에서 거부 (FR-09) |
| `edit_fresh_product.php`는 이미 `get_margin_rate_by_category(0, null)`(사실상 항상 기본 마진율만 반환하는 임시 코드)로 제안가를 계산 중이며, override 필드 추가가 단순 폼 작업이 아니라 도메인 로직 교체를 동반함 | Codex 위임 가능한 "단순 필드 추가"로 분류 | Claude Code 담당으로 재분류, 제안가 계산 로직을 `fresh_margin_helper.php`로 교체 |
| 신규 마진율 화면의 권한 범위가 기존 `margin_management.php`(admin/super_admin 전용)보다 넓게 설계됨 | `product_management` 권한도 허용 | `admin`/`super_admin` 역할만 허용으로 좁힘 (FR-08) |
| `mall_fresh_products`가 여러 점포 매입을 하나의 전역가로 통합하는 기존 설계라 점포별 가격 정책과 상충 가능 | 별도 언급 없음 | 신규 위험이 아니라 [[mall-fresh-products]] 최초 설계의 기존 전제임을 명시, v1은 이 전제를 유지 |
| 기존 `fresh_products.php` 목록의 "매입원가" 컬럼이 실제로는 `total_cost`를 표시해 "박스 1개 원가"로 오인될 수 있음 | 인지하지 못함 | 별도 버그로 기록(리스크 섹션), 신규 `box_sale_price` 계산은 `box_cost` 기준임을 명확히 함 |

---

## 1. Overview

### 1.1 Purpose

신선식품 카테고리(과일/채소/정육/수산)별 마진율을 관리자가 설정할 수 있게 하고, 그 마진율을 신선식품 매입 등록 로직에 연동해 박스판매가/낱개판매가를 자동으로 계산·저장한다.

### 1.2 Background

- 기존 정가상품은 `margin_rules`(카테고리 FK) + `lib/margin_helper.php`(`get_margin_rate_by_category`, `calculate_suggested_price`)로 마진 기반 판매가 제안 체계가 이미 존재함.
- 신선식품은 별도 레이어(`mall_fresh_products`, `fresh_purchase_items`, `fresh_purchase_batches`)로 완전히 분리되어 있어([[mall-fresh-products]] 참고) 기존 `margin_rules`/`categories`를 그대로 재사용할 수 없음 (신선식품은 `fresh_category` ENUM 고정값 4종만 사용하고 `categories` 테이블과 무관).
- 따라서 신선식품 전용 마진 테이블/헬퍼가 필요하며, 계산 공식은 기존 `calculate_suggested_price`와 동일한 개념(원가 × (1 + 마진율/100))을 따르되 반올림 정책은 아래 결정에 따라 다르게 적용한다.

### 1.3 Related Documents

- 관련 계획 문서: `docs/01-plan/features/mall-fresh-products.plan.md` (신선식품 레이어 최초 설계)
- 참고 코드: `lib/margin_helper.php`, `admin/margin_management.php`, `admin/fresh_product_common.php`, `admin/add_fresh_purchase_item.php`

---

## 2. Scope

### 2.1 In Scope

- [ ] `fresh_margin_rules` 테이블 신설 (과일/채소/정육/수산 4종, 기본값 40%)
- [ ] 신선식품 마진율 관리 화면 (`admin/fresh_margin_management.php`) — 조회/수정/저장
- [ ] `lib/fresh_margin_helper.php` 신규 — 카테고리별 마진율 조회 + 판매가 계산 함수 (기존 `edit_fresh_product.php`의 `get_margin_rate_by_category(0, null)` 임시 호출을 대체)
- [ ] `mall_fresh_products`에 `box_sale_price` 컬럼만 신규 추가 (마이그레이션 PHP 스크립트). **"낱개판매가"는 신규 컬럼을 만들지 않고 기존 `price_per_100g` 컬럼을 그대로 재사용한다** — 이미 낱개/저울 상품의 판매단가로 쓰이고 있고 `mall_order_items.estimated_price` 계산 설계의 기준 필드이므로, 별도 컬럼을 새로 만들면 두 개의 판매가 소스가 생겨 몰 프론트 연동 시 혼란을 유발함 (Codex 리뷰에서 지적)
- [ ] `admin/add_fresh_purchase_item.php` 매입 저장 트랜잭션에 판매가 자동계산·저장 로직 연동 — 단, "방금 입력한 매입행" 값을 그대로 쓰지 않고, 커밋 전 같은 트랜잭션 안에서 `ORDER BY purchase_date DESC, id DESC LIMIT 1`로 해당 마스터 상품의 실제 최신 매입 기록을 재조회해 그 값을 기준으로 계산한다 (과거 날짜 매입을 입력해도 현재가를 잘못 덮어쓰지 않도록 — Codex 리뷰에서 지적)
- [ ] `admin/fresh_products.php` 목록에 박스판매가(`box_sale_price`, 신규)/낱개판매가(`price_per_100g`, 기존 재사용) 컬럼 추가
- [ ] `admin/edit_fresh_product.php`에서 박스판매가 override 입력 필드 추가, 기존 `price_per_100g` 수동 입력 UX(제안가 적용 버튼 포함)는 유지하되 제안가 계산 로직만 신규 `fresh_margin_helper.php`로 교체
- [ ] `lang/ko.json`, `lang/en.json` 신규 라벨/메시지 키 추가
- [ ] 신규 SQL/PHP 마이그레이션 파일 (프로젝트 컨벤션에 따라 실행 가능한 PHP 스크립트로 작성)

### 2.2 Out of Scope (v2 보류)

- 마진율 변경 시 기존 등록 상품들의 판매가 일괄 재계산 기능 (사용자 확인 결과 v1 불필요, 다음 매입 등록 시점에 자연스럽게 갱신되는 것으로 충분)
- 신선식품 판매가를 몰(`mall/`) 프론트 주문 금액 계산에 실제로 반영하는 것 — `price_per_100g`는 이미 그 용도로 설계된 필드이므로 본 계획에서 "정확한 값을 자동으로 채워두는 것"까지는 하되, 몰 프론트 주문 흐름 자체의 구현/연동은 [[mall-fresh-products]] 계획의 후속 범위로 남겨둠
- `categories`/`margin_rules`(정가상품용) 통합 — 신선식품은 계속 별도 4종 고정 카테고리로 관리
- `fresh_category`/`sale_type` 변경 시 기존 판매가 자동 재계산/무효화 — v1은 이 케이스를 리스크로만 기록하고 별도 처리는 하지 않음 (드물게 발생, 발생 시 다음 매입으로 자연 교정됨)

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 관리자는 `admin/fresh_margin_management.php`에서 과일/채소/정육/수산 4개 카테고리별 마진율(%)을 조회하고 수정 후 저장할 수 있다 | High | Pending |
| FR-02 | `fresh_margin_rules`에 카테고리 행이 없으면 기본값 40%로 동작한다 (신규 설치/최초 실행 시에도 안전하게 동작) | High | Pending |
| FR-03 | 신선식품 매입 항목(`fresh_purchase_items`) 저장 시, 해당 마스터 상품(`mall_fresh_products`)의 `fresh_category` 마진율을 조회해 박스판매가(`box_sale_price`)와 낱개판매가(`price_per_100g`)를 계산하고 `mall_fresh_products`에 즉시 반영한다. 계산 기준은 "방금 입력한 값"이 아니라 트랜잭션 내에서 재조회한 해당 상품의 실제 최신 매입 기록(`purchase_date DESC, id DESC`)이다 | High | Pending |
| FR-04 | 박스판매가(`box_sale_price`, 신규 컬럼) = 최신 매입행의 박스당 원가(`box_cost`) × (1 + 마진율/100), 정수 원 단위로 올림(ceil) 처리한다 | High | Pending |
| FR-05 | 낱개판매가(`price_per_100g`, 기존 컬럼 재사용) = 최신 매입행의 단위원가(`unit_cost_per_piece` 또는 `unit_cost_per_100g`, `sale_type`에 따라 분기) × (1 + 마진율/100), 정수 원 단위로 올림(ceil) 처리한다 | High | Pending |
| FR-06 | `admin/fresh_products.php` 목록에 "박스판매가", "낱개판매가" 컬럼을 추가해 `mall_fresh_products.box_sale_price` / `price_per_100g`를 표시한다 (값 없으면 '-') | High | Pending |
| FR-07 | `admin/edit_fresh_product.php`에서 관리자가 박스판매가를 직접 입력해 자동계산 값을 덮어쓸 수 있다 (수동 override). 낱개판매가(`price_per_100g`)는 기존과 동일하게 이미 수동 입력 필드로 존재하므로 별도 override 필드를 새로 만들 필요 없음 — "제안가 적용" 버튼의 계산 로직만 신규 마진 헬퍼로 교체 | Medium | Pending |
| FR-08 | `admin/fresh_margin_management.php`(마진율 변경 화면)는 가격 정책 변경에 해당하므로 기존 `admin/margin_management.php`와 동일하게 **`admin`/`super_admin` 역할만** 접근 가능하도록 제한한다 (기존 `fresh_products.php`의 `product_management` 권한 체크보다 좁은 범위 — Codex 리뷰에서 기존 마진 관리 화면과의 권한 일관성 문제 지적) | High | Pending |
| FR-09 | 동일 매입 배치(`fresh_purchase_batches`) 안에 같은 신선상품이 두 줄 이상 입력되는 것을 서버에서 검증해 거부한다 (판매가 계산 기준이 모호해지는 것을 방지 — Codex 리뷰에서 지적) | Medium | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|--------------------|
| Data Integrity | 마진율/판매가 계산은 매입 저장과 같은 트랜잭션 내에서 처리되어 부분 실패가 없어야 함 | `add_fresh_purchase_item.php`의 `begin_transaction`/`commit`/`rollback` 범위 확인 |
| Consistency | 판매가 계산 공식은 `lib/margin_helper.php`의 기존 개념(원가×(1+마진율/100))과 동일한 패턴을 유지하되, 반올림 정책만 명시적으로 다르게 문서화 | 코드 리뷰 |
| i18n | 신규 UI 텍스트는 `lang/ko.json`/`lang/en.json` 키로 관리 (하드코딩 금지) | 코드 리뷰 |
| Security | 모든 DB 쿼리는 prepared statement 사용 | 코드 리뷰 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] `fresh_margin_rules` 테이블 및 기본 4개 행(40%)이 마이그레이션 스크립트 실행 후 존재
- [ ] 마진율 화면에서 값을 수정 후 저장하면 DB에 반영되고, 재조회 시 반영된 값이 보임
- [ ] 신선식품 매입을 신규 등록하면 해당 마스터 상품의 `box_sale_price`/`price_per_100g`가 자동 갱신됨
- [ ] `fresh_products.php` 목록에서 박스판매가/낱개판매가가 소숫점 없이 정수(올림) 원 단위로 표시됨
- [ ] `edit_fresh_product.php`에서 판매가를 수동 수정 후 저장하면 값이 유지되고, 이후 새 매입이 들어오면 다시 자동계산 값으로 갱신됨 (override는 "다음 매입 전까지"만 유효)
- [ ] 코드 리뷰(`/code-review`) 통과, `php -l` 린트 통과

### 4.2 Quality Criteria

- [ ] 신규/수정 파일 모두 prepared statement 사용
- [ ] 신규 테이블 마이그레이션은 실행 가능한 PHP 스크립트로 제공 (raw `.sql`만 두지 않음)
- [ ] 기존 `fresh_products.php`, `add_fresh_purchase_item.php`의 무관한 로직/스타일 변경 없음

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 매입 저장 로직에 판매가 계산을 끼워 넣다가 기존 매입 저장 트랜잭션을 깨뜨림 | High | Medium | 기존 `add_fresh_purchase_item.php` 트랜잭션 흐름을 최대한 건드리지 않고, 커밋 직전에 추가 UPDATE 구문만 삽입. 실패 시 전체 롤백되도록 같은 트랜잭션 안에 포함 |
| 과거 날짜로 매입을 등록하면 최근 실제 매입 기준 판매가를 잘못 덮어씀 (Codex 리뷰 지적) | High | Medium | "방금 입력한 값"이 아니라 트랜잭션 내에서 `ORDER BY purchase_date DESC, id DESC LIMIT 1`로 재조회한 실제 최신 매입 기록을 기준으로 계산 (FR-03) |
| 같은 매입 배치에 같은 상품이 중복 입력되면 어느 행 기준으로 계산할지 모호함 (Codex 리뷰 지적) | Medium | Low | 서버에서 배치 내 동일 상품 중복 입력을 거부 (FR-09) |
| 수동 override 값이 다음 매입 시 예고 없이 자동값으로 덮어써져 관리자가 당황 | Medium | Medium | `fresh_products.php`/`edit_fresh_product.php`에 "최근 매입 기준 자동계산됨" 안내 문구 및 최종 갱신 매입일자 표시 고려 |
| 정수 올림(ceil) 처리로 원가 대비 마진율이 설정값보다 미세하게 커짐 | Low | High(항상 발생) | 의도된 정책(사용자 확정)이므로 별도 조치 없음, 계획 문서에 반올림 정책 명시 |
| `fresh_category`에 매핑되는 마진율 행이 없는 초기 상태에서 계산 오류 | Medium | Low | `fresh_margin_helper.php`에서 행이 없으면 기본값 40%로 폴백 (기존 `margin_helper.php` 패턴과 동일) |
| `mall_fresh_products`는 여러 점포의 매입을 하나의 전역 가격으로 통합하는 기존 설계(대표코드 체계)라서, 한 점포의 매입이 전체 판매가를 바꿈 (Codex 리뷰 지적) | Medium | Low(기존 설계로 이미 수용된 위험) | 신규 위험이 아니라 [[mall-fresh-products]] 최초 설계부터 존재하던 "몰 대표코드 = 전역 단일가" 전제. 본 기능은 이 전제를 바꾸지 않고 그대로 따름 — 점포별 가격이 필요해지면 별도 계획으로 분리 |
| `fresh_category`/`sale_type`을 수정하면 이전 매입 기준으로 계산된 판매가의 의미가 달라짐(예: 저울→낱개 전환 시 100g 단가가 개당 단가처럼 보임) | Low | Low | v1은 별도 처리하지 않고 리스크로만 기록 (Out of Scope 2.2). 다음 매입 등록 시 새 `sale_type` 기준으로 재계산되며 자연 교정됨 |
| `fresh_products.php` 기존 목록의 "매입원가" 컬럼이 실제로는 `total_cost`(수량×박스원가)를 표시하고 있어 "박스당 원가"로 오인될 수 있음 (Codex 리뷰 지적, 기존 버그) | Low | 확정 | 본 기능 범위에서 직접 고치지는 않되, 신규 "박스판매가" 컬럼 라벨/계산 시 `box_cost`(박스 1개 원가)를 명확히 기준으로 삼아 혼동 확산을 막음. 기존 컬럼 라벨 수정은 별도 이슈로 분리 제안 |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `fresh_margin_rules` | DB Table (신규) | 카테고리별 마진율 저장 |
| `mall_fresh_products` | DB Table (컬럼 추가) | `box_sale_price DECIMAL(10,2) NULL` 신규 추가. 기존 `price_per_100g` 컬럼은 스키마 변경 없이 "낱개판매가/100g당 판매가"로 그대로 재사용 |
| `lib/fresh_margin_helper.php` | PHP 헬퍼 (신규) | 마진율 조회 + 판매가 계산 |
| `admin/fresh_margin_management.php` | Admin 화면 (신규) | 마진율 CRUD (`admin`/`super_admin` 전용) |
| `admin/add_fresh_purchase_item.php` | Admin 화면 (수정) | 매입 저장 트랜잭션에 판매가 자동계산 추가 (최신 매입 재조회 기준), 배치 내 동일 상품 중복 검증 추가 |
| `admin/fresh_products.php` | Admin 화면 (수정) | 목록 컬럼 추가 |
| `admin/edit_fresh_product.php` | Admin 화면 (수정) | 박스판매가 override 입력 필드 추가, 기존 `price_per_100g` 제안가 계산 로직을 `get_margin_rate_by_category(0, null)`에서 `fresh_margin_helper.php`로 교체 |
| `lang/ko.json`, `lang/en.json` | 다국어 리소스 (수정) | 신규 라벨/메시지 키 추가 |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `mall_fresh_products` | READ | `admin/fresh_products.php` 목록 쿼리 | None — 컬럼 추가만, 기존 SELECT 목록에 영향 없음 |
| `mall_fresh_products` | READ | `admin/add_fresh_purchase_item.php` 마스터 조회(`SELECT id, code, name_ko, ... FROM mall_fresh_products`) | None — 기존 컬럼 유지, 신규 컬럼은 SELECT * 아님 |
| `mall_fresh_products` | UPDATE | (신규) 매입 저장 시 판매가 UPDATE | Needs verification — 새로운 쓰기 경로이므로 트랜잭션/락 범위 검증 필요 |
| `mall_fresh_products` | READ/UPDATE | `admin/edit_fresh_product.php` | Needs verification — 기존 수정 폼에 필드 추가 시 기존 필드 처리 로직 훼손 여부 확인 |
| `fresh_purchase_items` | INSERT | `admin/add_fresh_purchase_item.php` | None — 기존 INSERT 문 변경 없음, 이후 단계에서 판매가 계산만 추가 |

### 6.3 Verification

- [ ] 매입 저장 성공/실패 케이스 모두 `mall_fresh_products` 판매가 UPDATE가 트랜잭션과 함께 커밋/롤백되는지 확인
- [ ] 마진율 미설정 카테고리에서도 매입 저장이 에러 없이 기본값(40%)으로 처리되는지 확인
- [ ] `fresh_products.php` 기존 검색/필터 기능이 컬럼 추가 후에도 정상 동작하는지 확인

---

## 7. Architecture Considerations

> 이 프로젝트는 PHP(MySQLi/PDO) + TailwindCSS 기반 서버 렌더링 admin 애플리케이션으로, 템플릿의 Next.js/프론트엔드 프레임워크 선택 항목은 해당 없음. 대신 이 프로젝트의 기존 컨벤션을 그대로 따른다.

### 7.1 기존 컨벤션 준수 사항

| 항목 | 결정 | 근거 |
|------|------|------|
| DB 접근 | 매입 저장은 기존과 동일하게 MySQLi + `begin_transaction`/`commit`/`rollback` 사용 | `add_fresh_purchase_item.php` 기존 패턴 유지 |
| 헬퍼 구조 | `lib/fresh_margin_helper.php` 신규 — `lib/margin_helper.php`와 동일한 함수 시그니처 패턴(`get_margin_rate_by_*`, `calculate_*`)을 재사용하되 신선식품 전용으로 분리 | 기존 정가상품 마진 로직과 혼동 방지, `fresh_category` ENUM 특성상 카테고리 FK가 아닌 문자열 키 기반 조회 필요 |
| 화면 구조 | `admin/fresh_margin_management.php`는 기존 `admin/margin_management.php`(정가상품용) 화면 구조를 참고해 UI 패턴 통일 | 기존 관리자 UX 일관성 |
| 마이그레이션 | 실행 가능한 PHP 스크립트 (`admin/migrate_*.php` 네이밍 컨벤션) | 프로젝트/사용자 컨벤션(피드백 메모리: DB 마이그레이션은 항상 실행 가능한 PHP 스크립트로 작성) |

### 7.2 핵심 설계 결정 (Q&A로 확정)

| 결정 | 옵션 | 선택 | 근거 |
|------|------|------|------|
| 판매가 수동 수정 허용 여부 | 자동값만 vs 수동 override 허용 | **수동 override 허용** | 사용자 확정 — `edit_fresh_product.php`에서 관리자가 직접 값을 고칠 수 있어야 함 |
| 마진율 변경 시 기존 상품 일괄 재계산 | 즉시 일괄 재계산 vs 다음 매입 때만 갱신 | **다음 매입 때만 갱신 (v1 범위 제외)** | 사용자 확정 — 당장 필요하지 않음, v2 후보로 보류 |
| 판매가 반올림 정책 | 소숫점 둘째자리 유지 vs 정수 원 단위 반올림 vs 정수 원 단위 올림 | **정수 원 단위 올림(ceil)** | 사용자 확정 — 원가는 소숫점 둘째자리까지 유지하되, 최종 판매가는 정수 원 단위로 올림 처리 |

---

## 8. Convention Prerequisites

### 8.1 Existing Project Conventions

- [x] `CLAUDE.md`에 코딩 컨벤션 섹션 존재 (한글 UI, 소숫점 둘째자리 표시, prepared statement 등)
- [x] `docs/00-conventions/agent-orchestration.md` 존재 — Codex/Claude Code 역할 분담 규칙
- [ ] 별도 ESLint/Prettier/TypeScript 설정 — 해당 없음 (PHP 프로젝트)

### 8.2 담당 파일 범위 (Codex/Claude 분담 판단용)

| 파일 | 성격 | 권장 담당 | 근거 |
|------|------|-----------|------|
| `admin/migrate_add_fresh_margin_rules.php` (신규 마이그레이션, `box_sale_price` 컬럼 포함) | DB 스키마 설계 | **Claude Code** | 도메인 판단 필요 (agent-orchestration.md 표) |
| `lib/fresh_margin_helper.php` (신규) | 마진 계산 로직 | **Claude Code** | 도메인 판단 필요 — 매입-몰 연동 로직 |
| `admin/add_fresh_purchase_item.php` (기존 파일 수정, 판매가 계산 연동 + 배치 내 중복 검증 부분) | 매입-몰 연동 로직 | **Claude Code** | 도메인 판단 필요, 기존 트랜잭션 로직과의 결합도 높음, 최신 매입 판정 기준(날짜 역전 방지) 설계 포함 |
| `admin/edit_fresh_product.php` (기존 `price_per_100g` 제안가 계산 로직 교체 + `box_sale_price` override 필드 추가) | 도메인 로직 + 폼 수정 | **Claude Code** | 재분류 — Codex 리뷰 결과 단순 필드 추가가 아니라 "기존 판매가 필드의 의미/제안가 계산 방식"을 함께 다뤄야 하는 도메인 변경으로 판명됨 |
| `admin/fresh_margin_management.php` (신규 CRUD 화면) | 정형화된 CRUD 폼 | Codex 위임 가능 | 스펙이 명확한 반복 작업(기존 `margin_management.php` 패턴 참고), 단 권한은 `admin`/`super_admin` 전용으로 명시 전달 |
| `admin/fresh_products.php` (목록 컬럼 추가) | 단순 표시 컬럼 추가 | Codex 위임 가능 | 스펙이 명확한 반복 작업 — `box_sale_price`, `price_per_100g` 두 컬럼 표시만 추가 |
| `lang/ko.json`, `lang/en.json` | 공용 파일 | **Claude Code (병합 게이트 시 최종 반영)** | agent-orchestration.md 4절 — 공용 파일은 한 번에 한 에이전트만 수정 |

> 실제 작업 지시 시에는 위 담당 구분에 따라 Claude Code가 DB 스키마/마진 계산/매입 연동 로직/`edit_fresh_product.php`를 직접 작성하고, CRUD 화면 2종(`fresh_margin_management.php`, `fresh_products.php` 목록)만 Codex에게 파일 범위를 제한해 위임하는 것을 권장한다 (최초 초안 대비 Codex 상의 후 위임 범위를 축소함).

### 8.3 Environment Variables Needed

- 해당 없음 (기존 `config/db_config.php` 상수 재사용)

### 8.4 Pipeline Integration

- 해당 없음 (9-phase Development Pipeline 미적용 — 기존 admin 기능 확장 작업)

---

## 9. Next Steps

1. [ ] 설계 문서 작성 (`fresh-margin-management.design.md`) — 테이블 DDL, 헬퍼 함수 시그니처, UPDATE 쿼리 위치를 구체화
2. [ ] Claude Code가 DB 마이그레이션 + `lib/fresh_margin_helper.php` + `add_fresh_purchase_item.php` 연동 로직 직접 구현
3. [ ] Codex에게 `fresh_margin_management.php`, `fresh_products.php` 컬럼 추가, `edit_fresh_product.php` override 필드를 파일 범위 제한하여 위임
4. [ ] Claude Code가 `/code-review`로 병합 전 검토 및 스타일 통일

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-06 | Initial draft | whdans007 |
