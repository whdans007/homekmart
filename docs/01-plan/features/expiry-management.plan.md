---
template: plan-plus
version: 1.0
description: Brainstorming-enhanced PDCA Plan template with User Intent, Alternatives, and YAGNI sections
variables:
  - feature: expiry-management
  - date: 2026-07-22
  - author: whdans007
  - project: HOME K MART
  - version: 1.0.0
---

# expiry-management Planning Document

> **Summary**: 유통기한이 임박/경과한 상품을 점검·등록하고, 폐기 시 재고를 자동 차감하며, 임박 상품을 배지로 알려주는 "유통기한 관리" 섹션을 관리자 메뉴에 신설한다.
>
> **Project**: HOME K MART
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-07-22
> **Status**: Draft
> **Method**: Plan Plus (Brainstorming-Enhanced PDCA)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 유통기한이 임박하거나 지난 상품을 놓쳐 폐기 손실이 발생해도, 무엇을 얼마나 폐기했는지 기록이 남지 않고 임박 상품을 사전에 파악할 표준 화면도 없다. |
| **Solution** | 기존 `inventory_expirations`(유통기한별 로트 재고) 테이블을 그대로 활용해 "점검기록" 화면에서 임박 상품을 등록·추적하고, "폐기등록" 화면에서 폐기 시 재고를 자동 차감하며 이력을 남긴다. |
| **Function/UX Effect** | 관리자 메뉴에 "유통기한 관리" 섹션(점검기록/폐기등록/폐기통계)이 추가되고, 임박 건수가 메뉴 배지로 표시되어 로그인 시 바로 확인 가능해진다. |
| **Core Value** | 폐기 손실을 정량적으로 추적(수량·추정 금액)하고, 유통기한 임박 상품을 놓치기 전에 알려주어 폐기 손실을 줄인다. |

---

## 1. User Intent Discovery

### 1.1 Core Problem

매장 재고 중 유통기한이 지나 폐기되는 상품이 있지만, 얼마나/어떤 상품을, 왜 폐기했는지 기록되지 않는다. 또한 유통기한이 임박한 상품을 사전에 점검·등록해 추적하는 표준 프로세스가 없어, 폐기 시점이 되어서야 발견하는 경우가 많다.

### 1.2 Target Users

| User Type | Usage Context | Key Need |
|-----------|---------------|----------|
| 매장 직원/매니저 (product_management 권한 보유) | 수시로 매대를 점검하며 유통기한 임박 상품을 발견했을 때 | 발견한 상품의 유통기한을 빠르게 등록하고, 폐기 시 재고에 정확히 반영 |
| 관리자 (product_management 권한 보유) | 폐기 손실 현황을 파악하고 임계값 정책을 조정할 때 | 월별 폐기 통계 확인, 임박 기준일 조정 |

### 1.3 Success Criteria

- [ ] 유통기한 로트를 등록하면 "점검기록" 화면에서 잔여일수와 함께 조회된다.
- [ ] 폐기 등록 시 해당 로트와 전체 재고(`inventory`) 수량이 자동으로 줄어든다.
- [ ] 임박(기본 30일 이내) 상품이 있으면 "점검기록" 메뉴에 배지 숫자로 표시된다.
- [ ] 월별 폐기 수량·추정 손실 금액을 통계 화면에서 확인할 수 있다.

### 1.4 Constraints

| Constraint | Details | Impact |
|------------|---------|--------|
| 기존 테이블 재사용 | `inventory_expirations`는 이미 상품 편집 모달의 "로트 관리"와 판매 시 FIFO 차감(`deduct_inventory_by_expiration`)에서 쓰이고 있음 — 구조를 깨지 않아야 함 | High |
| 점포 스코프 | 다른 관리 화면과 동일하게 로그인한 사용자의 소속 점포 데이터만 조회 | Medium |
| 권한 체계 | 새 권한을 만들지 않고 기존 `product_management` 재사용 | Low |

---

## 2. Alternatives Explored

### 2.1 Approach A: 기존 `inventory_expirations` 재사용 + 신규 폐기 이력 테이블 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | 점검기록은 기존 로트 테이블을 그대로 조회/등록하는 전용 화면으로 만들고, 폐기등록만 신규 이력 테이블(`product_disposals`)을 추가해 감사 추적을 남긴다. |
| **Pros** | 기존 데이터 구조·헬퍼 함수(FIFO 차감, 로트 조회 AJAX)를 그대로 재사용 — 중복 테이블 없음, 개발 범위 최소화 |
| **Cons** | `inventory_expirations`에 등록자/등록일시 컬럼이 없어 컬럼 추가(ALTER)가 필요함 |
| **Effort** | Medium |
| **Best For** | 이미 로트 기반 재고 구조가 갖춰진 이 프로젝트에 가장 적합 |

### 2.2 Approach B: 별도의 점검 이력(append-only 로그) 테이블 신설

| Aspect | Details |
|--------|---------|
| **Summary** | `inventory_expirations`는 현재 스냅샷으로 두고, 점검할 때마다 별도 `expiry_inspections` 로그 테이블에 이력을 쌓는 방식 (누가 언제 어떤 상품을 확인했는지 이력 보존) |
| **Pros** | "몇 번, 언제 점검했는지"까지 완전한 감사 이력 확보 가능 |
| **Cons** | 테이블·동기화 로직이 늘어나고, 현재 요구사항(등록자/일시 "표시") 대비 과설계 (YAGNI 위반 소지) |
| **Effort** | High |
| **Best For** | 규제 준수 등으로 점검 이력 자체를 감사해야 하는 경우 |

### 2.3 Decision Rationale

**Selected**: Approach A
**Reason**: 사용자가 "기존 테이블 그대로 활용"을 명시적으로 선택했고, 요구된 "등록자/일시 표시"는 컬럼 2개 추가로 충분히 해결되어 Approach B의 이력 테이블까지는 필요하지 않음.

---

## 3. YAGNI Review

### 3.1 Included (v1 Must-Have)

- [ ] 점검기록 화면 — 내 점포의 `inventory_expirations` 조회/등록/수정, 잔여일수 색상 표시
- [ ] 점검기록 — 등록자/등록일시 표시 및 필터
- [ ] 폐기등록 화면 — 상품+로트 선택, 수량/사유 입력, 등록 시 재고 자동 차감
- [ ] 폐기등록 — 사유 드롭다운(유통기한경과/파손/기타)
- [ ] 폐기 이력 리스트 (폐기등록 화면 하단)
- [ ] 폐기통계 화면 — 월별 폐기 수량/추정 손실 금액
- [ ] 임계값(관찰 60일/알림 30일) 관리자 설정 화면
- [ ] "점검기록" 메뉴 배지 — 알림 임계값 이내 건수 표시
- [ ] 관리자 메뉴에 "유통기한 관리" 섹션(점검기록/폐기등록/폐기통계) 추가

### 3.2 Deferred (v2+ Maybe)

| Feature | Reason for Deferral | Revisit When |
|---------|---------------------|--------------|
| 관리자 전체 점포 통합 조회 | 1차는 점포 단위 조회로 충분하다고 확인됨 | 여러 점포를 한 화면에서 비교할 필요가 생기면 |
| 배지 외 알림 채널(이메일/푸시) | 메뉴 배지만으로 1차 요구 충족 | 담당자가 관리자 화면을 자주 안 열어 놓치는 사례가 생기면 |
| 폐기 등록 시 사진 첨부 | 이번 요청 범위 밖 | 폐기 근거 증빙이 필요해지면 |
| 폐기 승인 워크플로 | 이번 요청 범위 밖, 등록=확정으로 처리 | 결재선이 필요한 조직 정책이 생기면 |

### 3.3 Removed (Won't Do)

| Feature | Reason for Removal |
|---------|-------------------|
| 점검 이력 append-only 로그 테이블 (Approach B) | 등록자/일시 컬럼 추가로 요구사항 충족, 별도 이력 테이블은 과설계 |
| 점검기록 엑셀 업로드/다운로드 | 이번 요청에 없었고 YAGNI 검토에서 선택되지 않음 |

---

## 4. Scope

### 4.1 In Scope

- [ ] `inventory_expirations` 테이블에 `registered_by`, `registered_at` 컬럼 추가
- [ ] `product_disposals` 신규 테이블 (폐기 이력)
- [ ] `expiry_settings` 신규 테이블 (임계값 설정, 단일 행)
- [ ] `admin/expiry_inspection.php` — 점검기록 화면
- [ ] `admin/expiry_disposal.php` — 폐기등록 화면 + 이력 리스트
- [ ] `admin/expiry_disposal_report.php` — 폐기통계 화면
- [ ] `admin/ajax_save_expiry_inspection.php`, `admin/ajax_delete_expiry_inspection.php`
- [ ] `admin/ajax_register_disposal.php`
- [ ] `admin/ajax_save_expiry_settings.php`
- [ ] `admin/partials/header.php` — "유통기한 관리" 섹션 + 배지 추가 (PC/모바일 메뉴 모두)

### 4.2 Out of Scope

- 전체 점포 통합 조회 (관리자용) — (YAGNI 3.2)
- 배지 외 알림 채널(이메일/푸시) — (YAGNI 3.2)
- 폐기 등록 사진 첨부 — (YAGNI 3.2)
- 폐기 승인 워크플로 — (YAGNI 3.2)
- 점검기록 엑셀 업/다운로드 — (YAGNI 3.3)

---

## 5. Requirements

### 5.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 점검기록 화면에서 상품을 검색해 유통기한+수량을 등록/수정할 수 있다 (기존 `ajax_search_products.php`, `ajax_get_lot_inventory.php` 재사용) | High | Pending |
| FR-02 | 점검기록 목록은 잔여일수 기준으로 정렬되고, 관찰(60일)/알림(30일) 임계값에 따라 색상이 다르게 표시된다 | High | Pending |
| FR-03 | 점검기록 등록/수정 시 `registered_by`, `registered_at`이 기록되고 목록에 표시·필터된다 | High | Pending |
| FR-04 | 폐기등록 화면에서 상품 선택 시 해당 상품의 로트(유통기한별 재고) 목록이 표시된다 | High | Pending |
| FR-05 | 폐기등록 저장 시 선택한 로트의 `inventory_expirations.quantity`와 `inventory.quantity`가 트랜잭션으로 함께 차감된다 | High | Pending |
| FR-06 | 폐기등록 시 사유(유통기한경과/파손/기타)를 선택하며, "기타" 선택 시 사유 텍스트를 입력할 수 있다 | Medium | Pending |
| FR-07 | 폐기 등록 시점의 원가(`unit_cost`)가 스냅샷으로 저장되어 이후 원가 변경과 무관하게 통계가 정확히 유지된다 | Medium | Pending |
| FR-08 | 폐기통계 화면에서 월별 폐기 수량 합계와 추정 손실 금액(수량×`unit_cost`)을 확인할 수 있다 | Medium | Pending |
| FR-09 | 관리자는 임계값(관찰일수/알림일수)을 설정 화면에서 변경할 수 있다 | Medium | Pending |
| FR-10 | "점검기록" 메뉴에 알림 임계값 이내 로트 건수가 배지로 표시된다 (기존 지점변경승인 배지와 동일한 방식) | High | Pending |
| FR-11 | 모든 신규 화면은 `product_management` 권한이 없으면 접근이 차단된다 | High | Pending |
| FR-12 | 모든 조회/등록은 로그인한 사용자의 소속 점포로 스코프된다 (super_admin 예외 없음, v1 기준) | High | Pending |

### 5.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Data Integrity | 폐기 등록 시 로트 차감과 이력 저장은 하나의 트랜잭션으로 처리되어 부분 실패가 없어야 함 | 코드 리뷰 + 강제 실패 케이스 수동 테스트 |
| Consistency | 배지/색상 표시에 쓰이는 임계값 로직은 대시보드(admin/index.php)의 기존 30일 임박 기준과 정합성 유지 (설정 미변경 시 동일 결과) | 코드 리뷰 |
| Security | 모든 신규 쿼리는 prepared statement 사용, 신규 화면은 `has_permission('product_management')` 체크 필수 | 코드 리뷰 |
| Korean UI | 모든 신규 화면 문구는 한글로 작성 | 코드 리뷰 |

---

## 6. Success Criteria

### 6.1 Definition of Done

- [ ] All functional requirements implemented
- [ ] 신규 PHP 파일 `php -l` 문법 검사 통과
- [ ] 브라우저에서 점검기록 등록 → 폐기등록 → 재고 차감 확인 → 폐기통계 반영까지 수동 시나리오 테스트 완료
- [ ] Documentation completed (Design 문서)

### 6.2 Quality Criteria

- [ ] 기존 `product_management.php` 로트 관리 기능과 데이터 정합성 유지 (동일 상품을 양쪽에서 조회 시 같은 결과)
- [ ] Zero PHP syntax errors
- [ ] 신규 SQL은 별도 `.sql` 파일로 관리 (CLAUDE.md 컨벤션 준수)

---

## 7. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 폐기등록이 특정 로트가 아닌 전체 상품에 적용되어 다른 로트 재고까지 잘못 차감 | High | Low | 로트를 `inventory_expirations.id` 단위로 명시적으로 선택하게 하고, 서버에서도 해당 id·store_id·product_id 일치 여부를 재검증 |
| `registered_at`이 판매 시 자동 FIFO 차감(`deduct_inventory_by_expiration`)에 의해 갱신되어 실제 점검 시점과 어긋남 | Medium | Medium | `registered_at`은 점검기록 화면의 저장 액션에서만 갱신하고, FIFO 차감 로직은 건드리지 않음 (기존 `updated_at`과 별도 컬럼으로 분리) |
| 폐기 대상 로트가 시스템에 아직 등록되지 않은 경우(로트 미등록 재고) | Medium | Medium | 폐기등록 화면에서 해당 상품의 로트가 없으면 먼저 점검기록에서 등록하도록 안내 메시지 표시 |
| 임계값 변경이 기존 대시보드(admin/index.php) 위젯과 표시 기준이 달라져 혼란 | Low | Medium | 배지/점검기록 색상 로직에 `expiry_settings` 값을 사용하되, 기본값(60/30일)은 기존 대시보드의 30일 기준과 동일하게 유지 |

---

## 8. Architecture Considerations

### 8.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure (`components/`, `lib/`, `types/`) | Static sites, portfolios, landing pages | |
| **Dynamic** | Feature-based modules, BaaS integration (bkend.ai) | Web apps with backend, SaaS MVPs, fullstack apps | ✅ |
| **Enterprise** | Strict layer separation, DI, microservices | High-traffic systems, complex architectures | |

### 8.2 Key Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 재고 데이터 소스 | 기존 `inventory_expirations` 재사용 / 신규 테이블 | 기존 재사용 | 이미 로트 관리·FIFO 차감에 쓰이고 있어 데이터 이원화 방지 |
| 폐기 시 재고 반영 | 자동 차감 / 기록만 | 자동 차감 | 재고 수치 정확성이 더 중요하다고 확인됨 |
| 권한 | 신규 권한 / 기존 재사용 | `product_management` 재사용 | 권한 체계 변경 최소화 |
| 알림 방식 | 메뉴 배지 / 대시보드 전용 | 메뉴 배지 | 로그인 시 바로 인지 가능하도록 |
| 조회 범위 | 내 점포만 / 관리자 전체 점포 | 내 점포만 (v1) | 기존 화면들과 일관성, 범위 최소화 |

### 8.3 Component Overview

```
admin/partials/header.php
  └─ "유통기한 관리" 섹션 (product_management 권한)
       ├─ 점검기록 → admin/expiry_inspection.php   [배지: 알림 임계값 이내 건수]
       ├─ 폐기등록 → admin/expiry_disposal.php
       └─ 폐기통계 → admin/expiry_disposal_report.php

admin/expiry_inspection.php
  ├─ 상품 검색:      ajax_search_products.php (기존, 재사용)
  ├─ 로트 조회:      ajax_get_lot_inventory.php (기존, 재사용)
  ├─ 등록/수정 저장:  admin/ajax_save_expiry_inspection.php (신규)
  ├─ 삭제:           admin/ajax_delete_expiry_inspection.php (신규)
  └─ 임계값 설정 모달: admin/ajax_save_expiry_settings.php (신규)

admin/expiry_disposal.php
  ├─ 상품+로트 선택:  ajax_search_products.php, ajax_get_lot_inventory.php (기존, 재사용)
  ├─ 폐기 등록:      admin/ajax_register_disposal.php (신규)
  └─ 폐기 이력 리스트 (페이지 내 직접 쿼리)

admin/expiry_disposal_report.php
  └─ 월별 집계 쿼리 (페이지 내 직접 쿼리, 신규 AJAX 없음)

lib/inventory_helper.php (기존, 변경 없음)
  └─ deduct_inventory_by_expiration() — 판매 시 FIFO 차감에서 계속 사용 (폐기등록과는 별도 경로)
```

### 8.4 Data Flow

```
[점검기록 등록]
사용자 입력(상품, 유통기한, 수량)
  → ajax_save_expiry_inspection.php
      → INSERT/UPDATE inventory_expirations (registered_by, registered_at 포함)
      → inventory.quantity 동기화 (기존 ajax_update_lot_inventory.php와 동일한 동기화 패턴)
  → 화면 목록 갱신, 메뉴 배지 재계산

[폐기등록]
사용자 입력(상품, 로트 선택, 수량, 사유)
  → ajax_register_disposal.php
      → BEGIN TRANSACTION
      → inventory_expirations.quantity -= 수량 (선택 로트, store_id/product_id 재검증)
      → inventory.quantity -= 수량 (동일 상품/점포)
      → INSERT product_disposals (reason, unit_cost 스냅샷, disposed_by, disposed_at, inventory_expiration_id)
      → COMMIT (실패 시 ROLLBACK)
  → 폐기 이력 리스트 갱신

[메뉴 배지 / 점검기록 색상]
admin/partials/header.php, admin/expiry_inspection.php
  → SELECT COUNT(*) FROM inventory_expirations
     WHERE store_id = 내점포 AND expiration_date <= CURDATE() + expiry_settings.alert_days
  → 배지 숫자로 표시 (0이면 배지 숨김)

[폐기통계]
admin/expiry_disposal_report.php
  → SELECT DATE_FORMAT(disposed_at,'%Y-%m'), SUM(quantity), SUM(quantity*unit_cost)
     FROM product_disposals WHERE store_id = 내점포 GROUP BY 월
```

---

## 9. Convention Prerequisites

### 9.1 Applicable Conventions

- [x] Existing project conventions verified — `admin/partials/header.php` 섹션 패턴(`has_permission()` 게이트 + PC/모바일 메뉴 이중 정의), `check_products.php` 스타일의 신규 SQL은 별도 파일 관리 컨벤션 확인
- [x] Naming rules confirmed — `admin/{기능}.php`, `admin/ajax_{동작}.php` 패턴 준수, 파일명이 `debug|test|check`로 시작하면 `.htaccess`에서 403 차단되므로 회피
- [x] Folder structure rules confirmed — 신규 SQL은 프로젝트 루트 관례(`create_inventory_expirations.sql`)를 따라 `create_expiry_management_tables.sql`로 생성

---

## 10. Next Steps

1. [ ] Write design document (`/pdca design expiry-management`)
2. [ ] Team review and approval
3. [ ] Start implementation (`/pdca do expiry-management`)

---

## Appendix: Brainstorming Log

> Key decisions from Plan Plus Phases 1-4.

| Phase | Question | Answer | Decision |
|-------|----------|--------|----------|
| Intent | 기존 `inventory_expirations`(로트) 데이터와 신규 "점검기록"을 어떻게 연결할지 | 기존 테이블 그대로 활용 | 신규 스냅샷 테이블 없이 `inventory_expirations` 재사용 + 컬럼 추가 |
| Alternatives | 폐기등록 시 재고 차감 방식 | 자동 차감 | 선택 로트 + `inventory` 동시 차감, 트랜잭션 처리 |
| Alternatives | 접근 권한 | 기존 `product_management` 재사용 | 신규 권한 미생성 |
| Alternatives | 임박 알림 표시 위치 | 메뉴에 배지 숫자 표시 | 기존 지점변경승인 배지 패턴 재사용 |
| Alternatives | 조회 범위 | 내 점포만 | 관리자 전체 점포 조회는 Out of Scope (v2 후보) |
| YAGNI | 부가 기능 4종(통계, 사유 드롭다운, 임계값 설정, 등록자/일시 표시) | 4종 모두 포함 | v1 In Scope로 승격 |
| Design Validation | 메뉴 구조/데이터 모델/데이터 흐름 전체 | 전체 승인 | 수정 없이 확정 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-22 | Initial draft (Plan Plus) | whdans007 |
