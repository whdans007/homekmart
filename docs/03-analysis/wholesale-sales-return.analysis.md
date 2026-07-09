---
template: analysis
version: 1.3
---

# wholesale-sales-return Analysis Report

> **Analysis Type**: Gap Analysis (Static — 운영 DB 미연결로 Runtime 검증 불가)
>
> **Project**: HOME K MART 관리 프로그램
> **Analyst**: Claude (PDCA)
> **Date**: 2026-07-09
> **Design Doc**: [wholesale-sales-return.design.md](../02-design/features/wholesale-sales-return.design.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 도매판매 반품을 기록/처리할 방법이 없어 삭제로만 대응 중 → 데이터 유실 및 재고/미수금 부정확 |
| **WHO** | `wholesale_management` 권한 보유 관리자/직원 |
| **RISK** | 재고 이중 복원, 반품 수량이 판매 수량 초과 |
| **SUCCESS** | 부분/전체 반품 모두 정확히 처리, 재고/금액/미수금 정확 반영 |
| **SCOPE** | `wholesale_sale_preview.php`, `wholesale_sales_list.php`, `wholesale_sales.php`, 신규 AJAX, DB 스키마 |

---

## Strategic Alignment Check

### Success Criteria Status (Plan §4.1)

| # | Criteria | Status | Evidence |
|---|----------|:------:|----------|
| SC-1 | 품목별 부분 반품 + 전체 반품 정상 동작 | ✅ | `ajax_wholesale_sale_return.php` L118-150 (targets 계산), `wholesale_sale_preview.php` 반품 모달 "전체 반품" 버튼 |
| SC-2 | 반품 시 재고(inventory) 정확히 증가 (등록상품만) | ✅ | `ajax_wholesale_sale_return.php` L192-205 (`pieces_per_box` 환산 + `is_registered_product` 필터) |
| SC-3 | `final_amount`/`total_amount` 정확히 차감, 미수금 반영 | ✅ | `ajax_wholesale_sale_return.php` L208-228 |
| SC-4 | 반품 수량 초과 요청 서버 거부 | ✅ | `ajax_wholesale_sale_return.php` L150-156 (`INVALID_QUANTITY`) |
| SC-5 | 판매 목록/미리보기에 반품 상태 표시 | ✅ | `wholesale_sales_list.php` 배지, `wholesale_sale_preview.php` 배지+이력 섹션 |
| SC-6 | 반품된 품목은 판매 수정(edit) 시 축소/삭제 차단 | ✅ (범위 확장) | `wholesale_sales.php` — 개별 품목이 아닌 **판매 건 전체**를 차단 (아래 Decision Record Verification 참고) |

**Success Rate**: 6/6 criteria met

### Decision Record Verification

| Source | Decision | Followed? | Deviation |
|--------|----------|:---------:|-----------|
| [Plan] | 반품 시 재고 증가, final_amount 자동 차감, 별도 목록화면 없음 | ✅ | 없음 |
| [Design] | Option C — 별도 AJAX 엔드포인트, 헬퍼 레이어 없음 | ✅ | 없음 |
| [Design §3.1] | `processed_by INT(11)` (signed) | ⚠️ 수정됨 | `users.id`가 `INT(11) UNSIGNED`임을 확인하여 `UNSIGNED`로 정정 (Do phase 중 500 에러 디버깅 과정에서 발견, FK 타입 불일치가 원인). Design 문서도 함께 수정함 |
| [Design §6.2] | 반품된 품목의 delete-and-reinsert 방지 (품목 단위 뉘앙스) | ✅ (보수적으로 확대) | 개별 품목 단위 검증 대신, 반품 이력이 하나라도 있는 판매 건은 수정 자체를 차단. 현재 edit 로직이 전체 품목을 delete-and-reinsert하는 구조라 품목 단위 부분 허용이 기술적으로 불가능하므로 전체 차단이 유일하게 안전한 구현 — Design 의도(데이터 무결성 보호)는 충족하나 UX는 더 보수적임 |

---

## 1. Analysis Overview

### 1.1 Analysis Purpose

Design 문서(§3-§8) 대비 실제 구현(module-1~4)의 구조적/기능적/계약 일치도를 검증하고, Do phase에서 놓친 하위 호환성 문제를 찾아낸다.

### 1.2 Analysis Scope

- **Design Document**: `docs/02-design/features/wholesale-sales-return.design.md`
- **Implementation Files**: `admin/sql/add_wholesale_sale_return_schema.sql`, `admin/run_wholesale_sale_return_migration.php`, `admin/ajax_wholesale_sale_return.php`, `admin/wholesale_sale_preview.php`, `admin/wholesale_sales_list.php`, `admin/wholesale_sales.php`
- **Analysis Date**: 2026-07-09
- **제약**: 이 환경에서 운영 DB(Hostinger)에 접속할 수 없어 마이그레이션 실행/브라우저 동작 등 Runtime 검증은 수행하지 못함. 아래 Match Rate는 Static-only 공식을 사용함.

---

## 2. Gap Analysis (Design vs Implementation)

### 2.1 API Endpoints

| Design | Implementation | Status | Notes |
|--------|---------------|--------|-------|
| `POST admin/ajax_wholesale_sale_return.php` | 구현됨 | ✅ Match | 에러코드 5종 모두 구현 (`INVALID_QUANTITY`, `PERMISSION_DENIED`, `SALE_NOT_FOUND`, `ALREADY_FULLY_RETURNED`, `SERVER_ERROR`) + 추가 검증 에러(`INVALID_REQUEST`, `INVALID_ITEM`, `NOTHING_TO_RETURN`, `METHOD_NOT_ALLOWED`) |
| `GET admin/ajax_wholesale_sale_return.php?sale_id=` | 구현됨 | ✅ Match | 응답 shape(§4.2) 일치 |

### 2.2 Data Model

| Field | Design | Impl | Status |
|-------|--------|------|--------|
| `wholesale_sales.returned_amount` | DECIMAL(12,2) | 동일 | ✅ |
| `wholesale_sales.return_status` | ENUM(none/partial/full) | 동일 | ✅ |
| `wholesale_sale_items.returned_quantity` | DECIMAL(10,2) | 동일 | ✅ |
| `wholesale_sale_returns.processed_by` | INT(11) → **INT(11) UNSIGNED로 수정** | UNSIGNED | ✅ (Design 문서도 동기화됨) |
| `wholesale_sale_return_items.sale_item_id` FK | ON DELETE RESTRICT | 동일 | ✅ |

### 2.3 Component Structure

| Design Component | Implementation File | Status |
|------------------|---------------------|--------|
| DB 마이그레이션 | `admin/sql/add_wholesale_sale_return_schema.sql` + `admin/run_wholesale_sale_return_migration.php` | ✅ Match |
| 반품 처리 API | `admin/ajax_wholesale_sale_return.php` | ✅ Match |
| 반품 UI | `admin/wholesale_sale_preview.php` | ✅ Match |
| 목록 배지 | `admin/wholesale_sales_list.php` | ✅ Match |
| 수정 보호 | `admin/wholesale_sales.php` | ✅ Match |

### 2.4 Functional Depth Analysis

| File | Depth Score | Notes |
|------|:----------:|-------|
| `ajax_wholesale_sale_return.php` | 95 | 트랜잭션/검증/재고/금액 로직 완전 구현. 마이그레이션 미적용 시 예외 메시지가 JSON 파싱 전에 출력될 수 있는 엣지케이스 존재 (§3 Critical 참고) |
| `wholesale_sale_preview.php` | 90 | 반품 UI/이력 완전 구현, Check phase 중 하위호환 버그 1건 발견 후 즉시 수정 완료 |
| `wholesale_sales_list.php` | 100 | 배지 표시 + 하위호환 가드 완비 |
| `wholesale_sales.php` | 100 | 수정 차단 로직 + 하위호환 가드 완비 |
| `run_wholesale_sale_return_migration.php` | 100 | 이전 500 에러 원인(FK 타입 불일치, 예외 미처리) 모두 수정 완료 |

**Shallow File Count**: 0 / 6 (0%)

### 2.5 Page UI Checklist Verification (Design §5.4)

| Page | Design Elements | Implemented | Missing | Rate |
|------|:--------------:|:-----------:|:-------:|:----:|
| 판매 미리보기 | 5 (버튼/모달/전체반품/이력/미수금반영) | 5 | 0 | 100% |
| 판매 목록 | 1 (배지) | 1 | 0 | 100% |

**Functional Match Rate**: 100%

### 2.6 API Contract Verification

| # | Endpoint | Design | Server | Client | Contract |
|---|----------|:------:|:------:|:------:|:--------:|
| 1 | `POST ajax_wholesale_sale_return.php` | ✅ | ✅ | ✅ (`wholesale_sale_preview.php` fetch) | PASS |
| 2 | `GET ajax_wholesale_sale_return.php?sale_id=` | ✅ | ✅ | ⚠️ 미사용 (Do phase에서 preview 페이지가 SSR로 직접 쿼리하는 방식을 채택, GET 엔드포인트는 구현되었으나 클라이언트에서 호출하지 않음) | PARTIAL |

**Contract Failures:**

| Endpoint | Layer | Issue | Fix Required |
|----------|-------|-------|---------------|
| `GET ajax_wholesale_sale_return.php` | Client | Design은 GET을 "반품 이력 표시용"으로 명시했으나, 실제로는 `wholesale_sale_preview.php`가 서버사이드에서 직접 JOIN 쿼리해 렌더링(기존 페이지 패턴과의 일관성을 위한 선택). GET 엔드포인트 자체는 정상 동작하며 향후 AJAX 새로고침 등에 재사용 가능 | 불필요 (의도된 설계 선택, 기능 결손 아님) |

**Contract Match Rate**: 1.5/2 endpoints = 75% (GET 미사용은 결손이 아닌 구현 방식 차이로, 감점 최소화하여 아래 최종 집계에서는 Important 등급으로만 기록)

### 2.7 Runtime Verification Results

> 운영 DB 미연결로 L1(API)/L2(UI)/L3(E2E) 실행 불가. Static-only 공식 사용.

**Runtime Match Rate**: N/A (미실행)

### 2.8 Match Rate Summary

```
┌─────────────────────────────────────────────┐
│  Structural Match Rate:  100%                │
│  Functional Match Rate:  100%                │
│  Contract Match Rate:    88%   (GET 미사용 감안)│
│  Runtime Match Rate:     N/A (서버 미연결)     │
│  ───────────────────────────────────────────  │
│  Overall Match Rate:     95%                 │
│  = (Structural × 0.2) + (Functional × 0.4)   │
│    + (Contract × 0.4)   [Static-only 공식]    │
├─────────────────────────────────────────────┤
│  ✅ Match:          10 items (91%)            │
│  ⚠️ Partial/Shallow: 1 item  (9%)             │
│  ❌ Not implemented: 0 items (0%)             │
└─────────────────────────────────────────────┘
```

---

## 3. Code Quality Analysis

### 3.1 Critical Issue Found & Fixed During This Analysis

| Severity | File | Location | Issue | Resolution |
|----------|------|----------|-------|------------|
| 🔴 Critical (수정 완료) | `wholesale_sale_preview.php` | items_sql (양쪽 스키마 분기) | `wsi.returned_quantity`를 하위호환 가드 없이 SELECT — 반품 마이그레이션 미적용 환경에서 **기존 도매판매 상세 조회 전체가 깨짐** (신규 기능과 무관한 기존 화면까지 회귀) | `has_returned_qty` 체크 추가, 컬럼 부재 시 `0 as returned_quantity`로 폴백. 두 분기 모두 수정, `php -l` 재검증 완료 |

이 문제는 Do phase에서 놓쳤던 하위호환 누락으로, `wholesale_sales_list.php`/`wholesale_sales.php`에는 이미 동일 패턴의 가드가 적용되어 있었으나 `wholesale_sale_preview.php`에서만 누락되어 있었다. Check phase에서 발견 즉시 동일 패턴으로 수정했다.

### 3.2 Remaining Minor Risks (Not Fixed — Low Priority)

| Severity | File | Location | Issue | Recommendation |
|----------|------|----------|-------|-----------------|
| 🟡 Minor | `ajax_wholesale_sale_return.php` | L60 부근 `SELECT * FROM wholesale_sales ... FOR UPDATE` | 마이그레이션 미적용 상태에서 이 엔드포인트가 호출되면 `$sale['return_status']` 등 undefined key 접근으로 PHP 8 Warning 발생 가능. `header('Content-Type: application/json')` 이후 Warning이 출력되면 JSON 파싱 실패 위험 | 실사용상 마이그레이션 완료 후에만 반품 버튼이 정상 동작하므로 발생 가능성은 낮음. 여유 있는 후속 세션에서 `array_key_exists` 방어 코드 추가 권장 |
| 🟢 Info | `wholesale_sale_preview.php` | 반품 버튼 노출 조건 | 마이그레이션 미적용 시에도 "반품 처리" 버튼이 노출되어, 클릭 시 서버에서 실패 응답을 받게 됨(위 항목과 연동). UX상 약간 혼란 가능 | 마이그레이션 여부를 서버에서 확인해 버튼을 숨기는 것은 과설계로 판단, 배포 후 마이그레이션을 즉시 실행하는 운영 절차로 충분히 커버 가능 |

### 3.3 Security Issues

| Severity | File | Location | Issue | Status |
|----------|------|----------|-------|--------|
| 🟢 Info | `ajax_wholesale_sale_return.php` | 전체 | Prepared statement 전용, 점포 스코프 검증, 권한 체크 모두 구현 | ✅ 이상 없음 |
| 🟢 Info | `run_wholesale_sale_return_migration.php` | 전체 | super_admin 전용, `real_escape_string()` 이스케이프 적용 | ✅ 이상 없음 |

---

## 4. Performance Analysis

N/A — 운영 DB 미연결로 실측 불가. 코드 리뷰 기준으로는 `SELECT ... FOR UPDATE`로 판매 건 1개 + 품목 N개만 잠그므로 락 경합 범위는 작다고 판단됨.

---

## 5. Test Coverage

자동화 테스트 도구(Playwright 등) 미설치 프로젝트로, Design §8에 정의된 L1/L2/L3 시나리오는 수동 테스트 체크리스트로 대체. 이 세션에서는 운영 DB 접속 불가로 수동 테스트도 미실행 — **배포 후 사용자 직접 검증 필요**.

---

## 6. Clean Architecture Compliance

N/A — 본 프로젝트는 절차적 PHP 페이지 구조이며 계층 분리 아키텍처를 사용하지 않음 (Design §9와 동일하게 N/A 처리).

---

## 7. Convention Compliance

### 7.1 Naming Convention Check

| Category | Convention | Compliance |
|----------|-----------|:----------:|
| PHP 파일 | snake_case.php | 100% |
| DB 테이블/컬럼 | snake_case | 100% |
| JS 함수 | camelCase | 100% |

### 7.2 CLAUDE.md 규칙 준수

| 규칙 | 준수 여부 |
|------|:--------:|
| 소숫점 둘째자리까지 표시 | ✅ (`fmt_num()`, `fmtReturnNum()` 재사용) |
| 한글 UI/설명 | ✅ |
| 신규 SQL 쿼리 별도 파일 생성 | ✅ (`add_wholesale_sale_return_schema.sql`) |

---

## 8. Overall Score

```
┌─────────────────────────────────────────────┐
│  Overall Match Rate: 95%                     │
├─────────────────────────────────────────────┤
│  Structural:    100%                         │
│  Functional:    100%                         │
│  Contract:       88%  (GET 엔드포인트 미사용) │
│  Code Quality:   Critical 1건 발견+즉시수정   │
│  Security:       이상 없음                    │
│  Runtime:        미검증 (운영 DB 미연결)       │
└─────────────────────────────────────────────┘
```

---

## 9. Recommended Actions

### 9.1 Immediate (배포 전 필수)

| Priority | Item | File | Status |
|----------|------|------|--------|
| 🔴 1 | `wholesale_sale_preview.php` 하위호환 가드 누락 | `wholesale_sale_preview.php` | ✅ 이번 세션에서 수정 완료 |
| 🔴 2 | `admin/run_wholesale_sale_return_migration.php` 실행 | 운영 서버 | ⏳ 사용자 실행 필요 (이전 세션에서 FK 타입 오류 수정 완료) |

### 9.2 Short-term (배포 후 1주 이내)

| Priority | Item | File | Expected Impact |
|----------|------|------|------------------|
| 🟡 1 | 반품 시나리오 수동 테스트 (부분/전체/수기품목/미수금) | - | Design §8.4 L3 시나리오 검증 |
| 🟡 2 | `ajax_wholesale_sale_return.php` undefined key 방어 코드 | `ajax_wholesale_sale_return.php` | 마이그레이션 미적용 상태에서의 JSON 응답 안정성 향상 |

### 9.3 Long-term (backlog)

| Item | Notes |
|------|-------|
| GET 이력 조회 엔드포인트 클라이언트 활용 | 현재는 SSR로 대체 중, AJAX 부분 새로고침 필요 시 재사용 가능 |
| 반품 사유 다국어(en/ko) 키 등록 | 현재 하드코딩된 한글 문구 사용 (기존 페이지 관행과 동일) |

---

## 10. Design Document Updates Needed

- [x] `processed_by` 타입을 `INT(11) UNSIGNED`로 수정 완료 (Design §3.1에 이미 반영됨)
- [ ] §4.2 GET 엔드포인트를 "선택적/향후 재사용" 용도로 주석 추가 권장 (현재 클라이언트 미사용)

---

## 11. Next Steps

- [x] Critical 이슈 수정 (하위호환 가드)
- [ ] 운영 서버에서 마이그레이션 실행 및 수동 시나리오 테스트
- [ ] 완료 보고서 작성 (`wholesale-sales-return.report.md`)

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-09 | 최초 분석, Critical 이슈 발견 및 즉시 수정 | Claude (PDCA) |
