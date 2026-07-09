---
template: plan
version: 1.3
---

# wholesale-sales-return Planning Document

> **Summary**: `admin/wholesale_sales.php` 도매판매 화면에 반품(전체/부분) 기능을 신규로 추가하고, 관련 화면(판매 미리보기, 판매 목록)을 반품 상태 표시가 가능하도록 수정한다.
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: Claude (PDCA)
> **Date**: 2026-07-09
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 도매판매 등록 후 거래처의 반품 요청이 발생해도 이를 시스템에 기록할 방법이 없다. 현재는 판매 건 전체를 완전 삭제(hard delete)하는 방식만 존재해, 부분 반품이나 반품 이력 추적이 불가능하다. |
| **Solution** | 판매 미리보기 화면(`wholesale_sale_preview.php`)에 반품 처리 UI를 추가하여, 품목 단위로 수량을 지정해 반품 처리하거나 전체 반품을 처리할 수 있도록 한다. 반품 시 재고를 복원하고, 판매 금액/미수금을 자동 차감한다. |
| **Function/UX Effect** | 사용자는 판매 건 상세 화면에서 반품할 품목과 수량을 선택해 반품 처리할 수 있고, 판매 목록/미리보기에서 반품 여부(부분/전체)를 즉시 확인할 수 있다. |
| **Core Value** | 반품 이력이 데이터로 남아 매출/재고/미수금이 정확해지고, 실수로 인한 전체 삭제(데이터 유실)를 방지한다. |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 도매판매 반품을 기록/처리할 방법이 없어 삭제로만 대응 중 → 데이터 유실 및 재고/미수금 부정확 |
| **WHO** | 도매판매 권한(`wholesale_management`)을 가진 관리자/직원, 각 점포 스코프 사용자 |
| **RISK** | 재고 이중 복원, 반품 수량이 판매 수량을 초과하는 데이터 정합성 오류 |
| **SUCCESS** | 품목별 부분 반품과 전체 반품이 모두 정확히 처리되고, 재고/금액/미수금이 반품량만큼 정확히 반영됨 |
| **SCOPE** | `wholesale_sales.php`(신규 등록/수정 화면 자체는 변경 없음), `wholesale_sale_preview.php`(반품 처리 UI 신규), `wholesale_sales_list.php`(반품 상태 표시), 신규 AJAX 엔드포인트, DB 스키마 변경 |

---

## 1. Overview

### 1.1 Purpose

도매판매(`wholesale_sales`) 건에 대해 반품을 등록/처리할 수 있는 기능을 신규 구현한다. "신규 및 수정 모두"라는 요청에 따라:
- **신규**: 반품 처리 UI, 반품 기록 저장 로직, 재고 복원 로직을 새로 추가
- **수정**: 기존 판매 미리보기·목록 화면을 반품 상태를 표시하도록 수정, 이미 반품된 품목/수량은 재반품되지 않도록 기존 수정(edit) 로직도 보정

### 1.2 Background

- 현재 `wholesale_sales.status`에 `cancelled` enum 값이 존재하지만 실제로 이 값을 세팅하는 로직은 없다 (`wholesale_sales_list.php`에서 조회 시 필터링만 함). 실제 취소는 `wholesale_sale_preview.php`의 `action=delete`로 판매/품목 레코드를 완전 삭제하는 방식.
- 도매판매 등록/수정 로직(`wholesale_sales.php`)은 `inventory` 재고를 전혀 차감하지 않는다 (도매판매는 재고 연동 없이 별도 매출로만 기록됨). 사용자 확인 결과, **반품 시에는 재고를 증가시켜야 한다** — 즉, 반품은 판매 시점의 재고 차감 유무와 무관하게 독립적으로 재고를 복원하는 정책으로 결정됨.
- 거래처 미수금은 별도 원장(ledger) 테이블 없이 `wholesale_sales.final_amount` + `payment_status`(paid/unpaid)로 관리된다. 따라서 반품 금액은 해당 판매 건의 `final_amount`(및 `total_amount`)에서 직접 차감한다.

### 1.3 Related Documents

- 관련 파일: `admin/wholesale_sales.php`, `admin/wholesale_sale_preview.php`, `admin/wholesale_sales_list.php`
- DB 스키마 참고: `admin/sql/alter_wholesale_sale_items_quantity_decimal.sql`, `admin/sql/add_sort_order_to_wholesale_sale_items.sql`, `admin/run_wholesale_manual_entry_migration.php`

---

## 2. Scope

### 2.1 In Scope

- [ ] `wholesale_sale_returns` (반품 헤더), `wholesale_sale_return_items` (반품 품목) 테이블 신규 생성
- [ ] `wholesale_sale_items.returned_quantity` 컬럼 추가 (해당 품목 누적 반품 수량 추적, 판매 수량 초과 방지)
- [ ] `wholesale_sales.returned_amount`, `wholesale_sales.return_status`(`none`/`partial`/`full`) 컬럼 추가
- [ ] `wholesale_sale_preview.php`에 반품 처리 모달/폼 신규 추가 (품목별 반품 수량 입력, 반품 사유 입력, 전체 반품 버튼)
- [ ] 반품 처리 AJAX 엔드포인트(`ajax_wholesale_sale_return.php`) 신규 — 트랜잭션으로 반품 기록 저장 + `wholesale_sale_items.returned_quantity` 갱신 + `inventory.stock_quantity` 증가(등록 상품만, 수기 항목 제외) + `wholesale_sales.final_amount`/`total_amount`/`returned_amount`/`return_status` 갱신
- [ ] `wholesale_sale_preview.php` 화면에 반품 이력(품목/수량/사유/일시) 표시 섹션 추가
- [ ] `wholesale_sales_list.php`에 반품 상태 배지(부분반품/전체반품) 표시
- [ ] 권한: 기존 `wholesale_management` 권한 재사용 (신규 권한 추가 없음)
- [ ] 점포 스코프 검증: super_admin이 아닌 경우 본인 점포 판매 건만 반품 처리 가능 (기존 delete/payment 로직과 동일 패턴)

### 2.2 Out of Scope

- 반품 전용 목록/조회 화면 신설 (요청에 따라 기존 판매 목록에 상태 표시만 함)
- 거래처별 원장(ledger)/정산 시스템 신설 (final_amount 직접 차감으로 대체)
- 반품에 대한 승인 워크플로우(반품 요청 → 승인) — 즉시 처리 방식으로 구현
- 환불(실제 결제 취소/환급) 연동 — 결제 게이트웨이 연동 없음, 금액 조정만 기록

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 판매 미리보기 화면에서 품목별로 반품 수량을 입력해 부분 반품을 처리할 수 있다 | High | Pending |
| FR-02 | 판매 건 전체를 한 번에 반품 처리(전체 반품)할 수 있다 | High | Pending |
| FR-03 | 반품 수량은 (판매 수량 - 기존 반품 수량)을 초과할 수 없다 (서버 측 검증) | High | Pending |
| FR-04 | 반품 처리 시 등록 상품(product_id 존재)에 한해 해당 점포 `inventory.stock_quantity`가 반품 수량만큼 증가한다 (수기 입력 품목은 재고 대상 없음) | High | Pending |
| FR-05 | 반품 처리 시 판매 건의 `total_amount`/`final_amount`가 반품 금액만큼 자동 차감되고 `returned_amount`가 누적된다 | High | Pending |
| FR-06 | 판매 건의 `return_status`가 반품 상태(none/partial/full)에 따라 자동 갱신된다 | Medium | Pending |
| FR-07 | 판매 목록 화면에서 반품 상태를 배지로 표시한다 | Medium | Pending |
| FR-08 | 판매 미리보기 화면에서 기존 반품 이력(품목/수량/금액/사유/처리일시/처리자)을 조회할 수 있다 | Medium | Pending |
| FR-09 | `wholesale_sales.php` 수정(edit) 모드에서 이미 반품된 품목을 반품 수량 미만으로 수량을 줄이거나 삭제하지 못하도록 검증한다 | Medium | Pending |
| FR-10 | 반품 처리 권한은 `wholesale_management` 권한 + 점포 스코프(본인 점포/super_admin)로 제한한다 | High | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Data Integrity | 반품/재고/금액 갱신은 단일 트랜잭션(PDO `beginTransaction`/`commit`/`rollback`)으로 처리 | 코드 리뷰 + 동시성 시나리오 수동 테스트 |
| Consistency | 금액/수량은 소숫점 둘째자리까지 표시 (CLAUDE.md 규칙) | 코드 리뷰 |
| Security | 반품 AJAX는 prepared statement 사용, 점포 스코프 검증 필수 | 코드 리뷰 |
| i18n | 신규 UI 문구는 `lib/lang_helper.php`의 `t()` 함수를 통한 다국어(한/영) 키로 등록 | 코드 리뷰 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] 품목별 부분 반품, 전체 반품이 모두 정상 동작
- [ ] 반품 시 재고(inventory)가 정확히 증가 (등록 상품만, 수기 항목 제외)
- [ ] 반품 시 판매 건 `final_amount`/`total_amount`가 정확히 차감되고 `payment_status`가 있는 미수금 표시에 반영됨
- [ ] 반품 수량이 (판매 수량 - 기존 반품 수량)을 초과하는 요청은 서버에서 거부됨
- [ ] 판매 목록/미리보기에 반품 상태가 표시됨
- [ ] 코드 리뷰 완료

### 4.2 Quality Criteria

- [ ] `php -l` 문법 검사 통과
- [ ] 트랜잭션 롤백 시나리오(예: 재고 UPDATE 실패) 수동 검증
- [ ] 점포 스코프 미검증으로 인한 타점포 데이터 반품 방지 확인

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 반품 수량 검증 누락으로 판매 수량을 초과하는 반품 발생 | High | Medium | 서버 측에서 `quantity - returned_quantity` 기준 검증, DB 레벨에서도 CHECK 불가 시 트랜잭션 내 재확인(SELECT ... FOR UPDATE) |
| 재고 이중 복원(같은 반품을 여러 번 요청) | Medium | Low | 반품 처리 후 `returned_quantity` 즉시 갱신 + 프론트에서 처리 중 버튼 비활성화 |
| 수기 입력 품목(product_id NULL)에 대해 재고 복원 시도로 오류 발생 | Medium | Medium | `product_id IS NOT NULL`인 품목만 재고 갱신 대상으로 필터링 |
| `wholesale_sales.php` 수정 로직이 기존 품목을 delete-후-insert 방식으로 재작성하므로, 반품 이력(`sale_item_id` 참조)이 깨질 위험 | High | Medium | 반품 처리된 판매 건은 품목 수량 축소/삭제를 서버에서 차단(FR-09), 또는 수정 시 반품 이력의 `sale_item_id` FK를 `ON DELETE CASCADE` 대신 별도 검증으로 보호 |
| 재고 테이블에 해당 점포+상품 조합의 `inventory` 행이 없는 경우 반품 재고 증가가 무시됨 | Medium | Low | UPSERT(INSERT ... ON DUPLICATE KEY UPDATE) 또는 사전 존재 확인 후 없으면 0에서 생성 |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `wholesale_sales` | DB Table | `returned_amount`, `return_status` 컬럼 추가 |
| `wholesale_sale_items` | DB Table | `returned_quantity` 컬럼 추가 |
| `wholesale_sale_returns` | DB Table (신규) | 반품 헤더 (sale_id, reason, total_returned_amount, processed_by, created_at 등) |
| `wholesale_sale_return_items` | DB Table (신규) | 반품 품목 상세 (return_id, sale_item_id, quantity, amount) |
| `inventory` | DB Table | 반품 처리 시 `stock_quantity` UPDATE (기존 구매/조정 로직과 동일 패턴 재사용) |
| `admin/wholesale_sale_preview.php` | PHP Page | 반품 처리 UI/폼, 반품 이력 표시 섹션 추가 |
| `admin/wholesale_sales_list.php` | PHP Page | 반품 상태 배지 표시 |
| `admin/wholesale_sales.php` | PHP Page | 수정(edit) 모드에서 반품된 품목 축소/삭제 방지 검증 추가 |
| `admin/ajax_wholesale_sale_return.php` | PHP Page (신규) | 반품 처리 AJAX 엔드포인트 |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `wholesale_sales.final_amount` | READ | `wholesale_sales_list.php` (목록 합계), `wholesale_sale_preview.php` (상세/인쇄) | 반품 후 차감된 금액이 표시되므로 기존 합계 로직과 자연스럽게 호환됨 (Breaking 없음) |
| `wholesale_sales.status` | READ | `wholesale_sales_list.php` WHERE 절 (`!= 'cancelled'`) | 반품은 새 `return_status` 필드를 쓰므로 기존 `status` 로직과 충돌 없음 (Needs verification: 전체 반품 시 `status`를 `cancelled`로 바꿀지 별도 유지할지 결정 필요 — 본 설계에서는 `status`는 그대로 두고 `return_status='full'`만 사용) |
| `wholesale_sale_items` | UPDATE/DELETE | `wholesale_sales.php` 수정 모드 (전체 delete 후 재삽입) | Breaking 가능 — FR-09로 방지 필요, Design 단계에서 구체적 검증 로직 확정 |
| `inventory.stock_quantity` | UPDATE | 매입(`add_purchase.php`) 등 기존 재고 증감 로직 | 반품으로 인한 증가가 기존 매입/판매 재고 흐름과 별도 원인(반품)으로 기록되므로 원인 추적 컬럼/로그 필요 여부 Design 단계에서 검토 |

### 6.3 Verification

- [ ] 반품 후 판매 목록 합계 금액이 정확히 감소하는지 확인
- [ ] `wholesale_sales.php` 수정 모드에서 반품된 품목 축소 시도가 차단되는지 확인
- [ ] 재고 반영이 기존 매입/조정 로직과 충돌하지 않는지 확인 (음수 재고 대시보드 등 연계 화면 영향 검토)

---

## 7. Architecture Considerations

> 본 프로젝트는 PHP + MySQL 기반 서버 렌더링 애플리케이션으로, Next.js/React 템플릿 항목(프레임워크/상태관리/API 클라이언트 선택 등)은 해당 없음(N/A) 처리함.

### 7.1 Project Level

기존 프로젝트 아키텍처(PHP 절차적 페이지 + `lib/` 헬퍼 + AJAX 엔드포인트 패턴)를 그대로 따른다. 신규 레벨 선택 불필요.

### 7.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 반품 데이터 저장 방식 | (A) `wholesale_sales.status`만 `cancelled`로 변경 / (B) 별도 반품 테이블 신설 | (B) 별도 테이블(`wholesale_sale_returns`, `wholesale_sale_return_items`) | 부분 반품 지원과 품목별 반품 이력 추적을 위해 상태값만으로는 불충분 |
| 재고 반영 방식 | (A) 즉시 UPDATE inventory / (B) 별도 재고조정 요청 큐 | (A) 즉시 UPDATE | 기존 매입 로직과 동일 패턴, 별도 승인 워크플로우 불필요(Out of Scope) |
| 반품 처리 위치 | (A) `wholesale_sales.php` 자체에 반품 폼 통합 / (B) `wholesale_sale_preview.php`에 반품 UI 추가 | (B) 미리보기 화면 | 반품은 "이미 등록된 판매 건"에 대한 사후 처리이므로, 등록/수정 폼보다는 상세 확인 화면이 자연스러움 |
| 금액 차감 방식 | (A) `final_amount`를 직접 감소 / (B) 원본 금액 유지 + 별도 `returned_amount`만 표시 | 혼합: `final_amount`/`total_amount` 직접 차감 + `returned_amount` 별도 누적 컬럼으로 감사 추적 | 미수금 표시(payment_status 기반)가 `final_amount`를 참조하므로 직접 차감 필요, 동시에 반품 총액 추적을 위해 별도 컬럼 유지 |

---

## 8. Convention Prerequisites

### 8.1 Existing Project Conventions

- [x] `CLAUDE.md`에 코딩 규칙 존재 (한글 UI, 소숫점 둘째자리, PDO/MySQLi 이중 접근 패턴, 권한 체크 패턴)
- [ ] 별도 `CONVENTIONS.md` 없음 — `CLAUDE.md` 규칙을 그대로 따름

### 8.2 Conventions to Define/Verify

| Category | Current State | To Define | Priority |
|----------|---------------|-----------|:--------:|
| 반품 사유 입력 필수 여부 | 없음 | Design 단계에서 필수/선택 결정 | Medium |
| 반품 배지 문구/색상 | 없음 | `wholesale_sales_list.php` 기존 상태 배지 스타일(Tailwind) 재사용 | Low |
| 다국어 키 네이밍 | `wholesale.*`, `wholesale_sale_preview.*` 네임스페이스 존재 | 반품 관련 키는 `wholesale.return_*` 또는 `wholesale_sale_preview.return_*` 패턴으로 추가 | Medium |

### 8.3 Environment Variables Needed

해당 없음 (기존 `config/db_config.php` 재사용)

---

## 9. Next Steps

1. [ ] Design 문서 작성 (`wholesale-sales-return.design.md`) — 3가지 아키텍처 옵션 비교, DB 스키마 상세 설계(컬럼 타입/제약), AJAX API 명세, UI 와이어프레임
2. [ ] 사용자 검토 및 승인
3. [ ] 구현 시작 (`/pdca do wholesale-sales-return`)

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-09 | 최초 작성 | Claude (PDCA) |
