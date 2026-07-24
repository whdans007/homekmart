# expiry-management Analysis Report

> **Analysis Type**: Gap Analysis (Design vs Implementation)
>
> **Project**: HOME K MART
> **Version**: 1.0.0
> **Analyst**: whdans007 (+ Claude Code, self-review — no gap-detector agent available in this environment)
> **Date**: 2026-07-22
> **Design Doc**: [expiry-management.design.md](../02-design/features/expiry-management.design.md)

### Pipeline References (for verification)

| Phase | Document | Verification Target |
|-------|----------|---------------------|
| Phase 4 | 본 문서 §2.1 (API Spec 대체) | admin/expiry_*.php + lib/expiry_helper.php 계약 일치 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 유통기한 임박/경과 상품의 폐기 손실을 추적하고, 사전 점검으로 손실을 줄이기 위함 |
| **WHO** | `product_management` 권한을 가진 매장 직원/매니저/관리자 |
| **RISK** | 잘못된 로트 차감 → 재고 오염 / FIFO 자동차감이 `updated_at`을 계속 갱신 → `registered_at` 분리 필요 |
| **SUCCESS** | 점검기록 등록→배지 반영, 폐기등록→재고 자동차감+이력, 폐기통계 월별 집계, 임계값 설정 가능 |
| **SCOPE** | 신규 페이지 3 + 헬퍼 1 + DB 변경 3건 + header.php 네비/배지 |

---

## Strategic Alignment Check

### Success Criteria Status (Plan §1.3)

| # | Criteria | Status | Evidence |
|---|----------|:------:|----------|
| SC-1 | 유통기한 로트를 등록하면 점검기록 화면에서 잔여일수와 함께 조회된다 | ✅ Met | `admin/expiry_inspection.php` 목록 렌더링, `get_expiry_status()` 사용 |
| SC-2 | 폐기 등록 시 해당 로트와 전체 재고(inventory) 수량이 자동으로 줄어든다 | ✅ Met | `lib/expiry_helper.php::register_disposal()` 트랜잭션 내 두 UPDATE |
| SC-3 | 임박(기본 30일 이내) 상품이 있으면 점검기록 메뉴에 배지 숫자로 표시된다 | ✅ Met | `admin/partials/header.php` `$expiry_alert_count` + PC/모바일 배지 |
| SC-4 | 월별 폐기 수량·추정 손실 금액을 통계 화면에서 확인할 수 있다 | ✅ Met | `admin/expiry_disposal_report.php` 월별 GROUP BY 집계 |

**Success Rate**: 4/4 criteria met (코드 기준. 브라우저 실행 확인은 §2.7 참고)

### Decision Record Verification

| Source | Decision | Followed? | Deviation |
|--------|----------|:---------:|-----------|
| [Plan] | 기존 `inventory_expirations` 재사용, 신규 스냅샷 테이블 없음 | ✅ | - |
| [Plan] | 폐기 시 재고 자동 차감 | ✅ | - |
| [Plan] | 권한은 기존 `product_management` 재사용 | ✅ | - |
| [Design] | Option C — 공유 로직만 `lib/expiry_helper.php`로 분리 | ✅ | - |
| [Design] | 원가는 폐기 시점 `inventory.cost_price` 스냅샷 | ✅ | `register_disposal()`에서 조회 후 `unit_cost`로 저장 |

---

## 1. Analysis Overview

### 1.1 Analysis Purpose

Design 문서(§2~§11)와 실제 구현 코드(module-1~5)를 비교해 구조적/기능적/계약 일치 여부를 확인하고, 코드 품질·보안 이슈를 찾아 Report 단계 전에 정리한다.

### 1.2 Analysis Scope

- **Design Document**: `docs/02-design/features/expiry-management.design.md`
- **Implementation Path**: `admin/expiry_*.php`, `lib/expiry_helper.php`, `admin/partials/header.php`, `create_expiry_management_tables.sql`
- **Analysis Date**: 2026-07-22
- **제약사항**: 이 환경에서는 운영 DB(및 브라우저)에 접속할 수 없어, 실제 실행 결과가 아닌 **정적 코드 비교**로만 분석함 (§2.7 참고).

---

## 2. Gap Analysis (Design vs Implementation)

### 2.1 파일/엔드포인트 (Design §4.1 대비)

| Design | Implementation | Status | Notes |
|--------|---------------|--------|-------|
| `admin/expiry_inspection.php` (GET/POST save,delete,save_settings) | 동일 파일, 동일 action 3종 구현 | ✅ Match | |
| `admin/expiry_disposal.php` (GET/POST register) | 동일 파일, `action=register` 구현 | ✅ Match | |
| `admin/expiry_disposal_report.php` (GET) | 동일 파일 | ✅ Match | |
| `lib/expiry_helper.php` 5개 함수 | 5개 함수 모두 Design §4.3 시그니처와 동일 | ✅ Match | |
| `admin/partials/header.php` 배지/섹션 | PC + 모바일 양쪽 반영 | ✅ Match | |
| - | `admin/expiry_run_migration.php` | ⚠️ Design에 없음 | 운영 500 에러 대응용으로 세션 중 추가한 마이그레이션 실행기. 기능 결함은 아니지만 §11.1 파일 구조에 없던 산출물 — Design 문서 업데이트 필요 |
| FR-01 "점검기록에서 `ajax_get_lot_inventory.php` 재사용" (Plan §5.1) | 점검기록은 상품 검색만 재사용, 기존 로트 목록 조회는 미구현 | ⚠️ Partial | 신규 로트 등록 흐름에는 필수 아님(별도 날짜 입력이 목적). 다만 "동일 상품의 기존 로트를 보면서 중복 등록 방지"용으로는 있으면 더 좋음. Low 심각도 |

### 2.2 Data Model (Design §3 대비)

| 항목 | Design | Implementation | Status |
|------|--------|-----------------|--------|
| `inventory_expirations.registered_by/at` | ALTER 2컬럼 | `create_expiry_management_tables.sql` 동일 | ✅ |
| `product_disposals` | 9컬럼 + 3 KEY | SQL 동일 | ✅ |
| `expiry_settings` | 단일행 id=1, 2컬럼+seed | SQL 동일 (CHECK 제약은 애플리케이션에서만 검증하기로 한 결정과 일치) | ✅ |

### 2.3 Component Structure

| Design Component | Implementation File | Status |
|------------------|---------------------|--------|
| 점검기록 화면 | `admin/expiry_inspection.php` | ✅ Match |
| 폐기등록 화면 | `admin/expiry_disposal.php` | ✅ Match |
| 폐기통계 화면 | `admin/expiry_disposal_report.php` | ✅ Match |
| 공유 로직 | `lib/expiry_helper.php` | ✅ Match |
| 네비/배지 | `admin/partials/header.php` | ✅ Match |

### 2.4 Functional Depth Analysis

| File | Depth Score | Placeholder Indicators | Missing Design Elements |
|------|:----------:|----------------------|------------------------|
| `lib/expiry_helper.php` | 100 | 없음 | 없음 |
| `admin/expiry_inspection.php` | 95 | 없음 | 기존 로트 미리보기(§2.1 참고, Low) |
| `admin/expiry_disposal.php` | 100 | 없음 | 없음 |
| `admin/expiry_disposal_report.php` | 100 | 없음 | 없음 |
| `admin/partials/header.php` (변경분) | 100 | 없음 | 없음 |

**Shallow File Count**: 0 / 5 files (0%)

### 2.5 Page UI Checklist Verification (Design §5.4 대비)

| Page | Design Elements | Implemented | Missing | Rate |
|------|:--------------:|:-----------:|:-------:|:----:|
| 점검기록 | 11 | 11 | 0 | 100% |
| 폐기등록 | 10 | 10 | 0 | 100% |
| 폐기통계 | 4 | 4 | 0 | 100% |
| 네비게이션 | 3 | 3 | 0 | 100% |

**Functional Match Rate**: 100% (Page UI Checklist 기준) — FR-01 nuance는 체크리스트 항목이 아니므로 별도 Low 이슈로만 기록 (§2.1)

### 2.6 API Contract Verification

| # | 엔드포인트/함수 | Design | 구현 | 호출부 | Contract |
|---|----------------|:------:|:------:|:------:|:--------:|
| 1 | `expiry_inspection.php?action=save` | ✅ | ✅ | 폼 자체 POST | PASS |
| 2 | `expiry_inspection.php?action=delete` | ✅ | ✅ | 폼 자체 POST | PASS |
| 3 | `expiry_inspection.php?action=save_settings` | ✅ | ✅ | 설정 모달 폼 | PASS |
| 4 | `expiry_disposal.php?action=register` | ✅ | ✅ | 등록 폼 | PASS |
| 5 | `get_expiry_settings($conn)` | ✅ | ✅ | inspection.php, header.php(간접) | PASS |
| 6 | `get_expiry_alert_count($conn,$store_id)` | ✅ | ✅ | header.php | PASS |
| 7 | `register_disposal($conn,$params)` | ✅ | ✅ | expiry_disposal.php | PASS |
| 8 | `save_expiry_settings($conn,...)` | ✅ | ✅ | expiry_inspection.php | PASS |
| 9 | `ajax_search_products.php` (기존 재사용) | ✅ | ✅ | inspection.php, disposal.php JS | PASS |
| 10 | `ajax_get_lot_inventory.php` (기존 재사용) | ✅ | ✅ | disposal.php JS | PASS |

**Contract Match Rate**: 10/10 = 100%

### 2.7 Runtime Verification Results

> **미실행**: 이 환경(로컬 개발 머신)은 운영 DB(`localhost` = 운영 서버 자체를 의미)에 접속할 수 없어, curl/브라우저 기반 L1~L3 테스트를 실행하지 못했습니다. `php -l` 문법 검사만 전 파일에 대해 통과 확인했습니다. 아래는 Design §8 기준 **사용자가 직접 수행해야 할 수동 테스트 체크리스트**입니다.

#### L1: 페이지/폼 테스트 (수동)

| # | 대상 | 확인 필요 | Pass |
|---|------|-----------|:----:|
| 1 | 3개 화면 모두 | `product_management` 권한 없는 계정 접근 시 차단 | ☐ |
| 2 | `expiry_inspection.php` | 신규 로트 등록 → 목록/배지 반영 | ☐ |
| 3 | `expiry_disposal.php` | 정상 폐기 → 로트/재고 차감, 이력 1건 생성 | ☐ |
| 4 | `expiry_disposal.php` | 잔여 수량 초과 입력 → 서버 거부 | ☐ |
| 5 | `expiry_inspection.php` | 알림일수 > 관찰일수로 저장 시도 → 거부 | ☐ |

#### L2/L3: UI/시나리오 (수동)

Design §8.3, §8.4 시나리오를 그대로 사용 — 별도 자동화 도구(Playwright 등)가 이 프로젝트에 없으므로 브라우저에서 직접 확인 필요.

**Runtime Match Rate**: N/A (미실행 — Overall Match Rate 계산에서 제외, 정적 분석만 반영)

### 2.8 Match Rate Summary

```
┌─────────────────────────────────────────────┐
│  Structural Match Rate:  100%                │
│  Functional Match Rate:  100% (Page UI 기준) │
│  Contract Match Rate:    100%                │
│  Runtime Match Rate:     N/A (미실행)        │
│  ─────────────────────────────────────────── │
│  Overall Match Rate (정적):  100%            │
│  = (Structural × 0.2) + (Functional × 0.4)   │
│    + (Contract × 0.4)  — 서버 미접속으로 정적 공식 적용 │
├─────────────────────────────────────────────┤
│  ✅ Match:           38 항목                  │
│  ⚠️ Partial/Info:     1 항목 (FR-01 nuance)   │
│  ❌ Not implemented:  0 항목                  │
└─────────────────────────────────────────────┘
```

> 위 100%는 **"Design 문서와 코드가 얼마나 일치하는가"** 만 반영합니다. 실제 브라우저 동작 확인(§2.7)은 아직 사용자 쪽에서 완료되지 않았으므로, 이 수치를 "기능이 실제로 작동한다"는 증거로 오인하지 마세요.

---

## 3. Code Quality Analysis

### 3.1 보안 이슈 (Critical 발견)

| Severity | File | Location | Issue | Recommendation | Status |
|----------|------|----------|-------|----------------|--------|
| 🔴 Critical | `admin/expiry_inspection.php` | 상품 검색결과 렌더링 JS (`productSearchResults.innerHTML = data.map(...)`) | `${p.name_ko}` 를 이스케이프 없이 `innerHTML`에 삽입 — 상품명에 `<script>`/`<img onerror>` 등이 들어있으면 XSS 발생 가능 | HTML 이스케이프 헬퍼 적용 후 innerHTML에 삽입 | ✅ Fixed — `escapeHtml()` 헬퍼 추가, `name_ko`/`sku` 출력부에 적용 |
| 🔴 Critical | `admin/expiry_disposal.php` | 동일한 상품 검색결과 렌더링 JS | 위와 동일한 패턴 | 위와 동일 | ✅ Fixed — 동일 패치 적용 |

이 외 보안 항목은 이상 없음: 전 쿼리 prepared statement, 3개 페이지 모두 `has_permission('product_management')` 체크, `register_disposal()` 내부에서 `store_id/product_id` 서버 재검증, `save_expiry_settings()`에서 `alert_days > warning_days` 애플리케이션 레벨 검증.

### 3.2 Code Smells

| Type | File | Location | Description | Severity |
|------|------|----------|-------------|----------|
| 코드 중복 | `expiry_inspection.php`, `expiry_disposal.php` | 상품 검색 JS 블록 | 거의 동일한 상품검색 fetch/렌더링 로직이 두 파일에 중복 | 🟢 (2곳뿐이라 별도 JS 파일 분리는 과함 — Design Option C 원칙상 허용 범위) |

### 3.3 Security Issues 요약

| Severity | File | Location | Issue | Recommendation |
|----------|------|----------|-------|----------------|
| 🔴 Critical | expiry_inspection.php, expiry_disposal.php | 상품검색 렌더링 | XSS (위 3.1) | 즉시 수정 |
| 🟢 Info | 전체 | - | 나머지 입력은 모두 이스케이프/바인딩 처리됨 | - |

---

## 6. Clean Architecture Compliance (Design §9 기준, 프로젝트 실제 레이어로 재해석)

### 6.1 Layer Dependency Verification

| Layer | Expected Dependencies | Actual Dependencies | Status |
|-------|----------------------|---------------------|--------|
| Presentation (`admin/expiry_*.php`) | `lib/expiry_helper.php`, `config/db_config.php` | 동일 | ✅ |
| Shared Logic (`lib/expiry_helper.php`) | `config/db_config.php`만 | 동일, `admin/*` 미참조 | ✅ |

### 6.3 Layer Assignment Verification

| Component | Designed Layer | Actual Location | Status |
|-----------|---------------|-----------------|--------|
| 점검기록/폐기등록/폐기통계 | Presentation | `admin/expiry_*.php` | ✅ |
| 공유 로직 5함수 | Shared Logic | `lib/expiry_helper.php` | ✅ |

### 6.4 Architecture Score

```
┌─────────────────────────────────────────────┐
│  Architecture Compliance: 100%               │
├─────────────────────────────────────────────┤
│  ✅ 올바른 레이어 배치: 5/5 파일              │
│  ⚠️ 의존성 위반:        0                    │
└─────────────────────────────────────────────┘
```

---

## 7. Convention Compliance (Design §10 기준)

| 항목 | 컨벤션 | 확인 결과 | 상태 |
|------|--------|-----------|------|
| 함수명 | snake_case | `get_expiry_settings()` 등 5개 전부 준수 | ✅ |
| 파일명 | `admin/{feature}.php`, `lib/{domain}_helper.php` | 전부 준수 | ✅ |
| **금지 접두사** | `debug\|test\|check`로 시작 시 `.htaccess` 403 | 신규 파일 5개 모두 해당 없음 (`expiry_run_migration.php`도 안전) | ✅ |
| 폼 처리 | 자기 자신 POST + `action` 히든필드 | 3개 페이지 모두 준수 | ✅ |
| 에러 처리 | `$_SESSION['flash']` | `expiry_inspection.php`, `expiry_disposal.php`는 자체 `$flash_message` 변수로 화면 내 표시(세션 미사용) | ⚠️ Design 문서는 "$_SESSION['flash'] 패턴 재사용"이라 명시했으나, 실제로는 리다이렉트 없이 같은 페이지에서 바로 렌더링하는 방식이라 세션 플래시 대신 로컬 변수를 씀. 기능상 문제는 없으나 Design 문서 §6.2와 문구가 다름 — 문서 업데이트 필요 |

### 7.5 Convention Score

```
┌─────────────────────────────────────────────┐
│  Convention Compliance: 96%                  │
├─────────────────────────────────────────────┤
│  Naming:            100%                     │
│  파일명 금지패턴 회피: 100%                    │
│  에러처리 패턴 문서 일치: 80% (문서 표현 차이) │
└─────────────────────────────────────────────┘
```

---

## 8. Overall Score

```
┌─────────────────────────────────────────────┐
│  Overall Score (정적 분석, XSS 수정 반영): 99/100 │
├─────────────────────────────────────────────┤
│  Design Match:        100 points             │
│  Code Quality:        100 points (XSS 2건 수정 완료) │
│  Security:            100 points (XSS 2건 수정 완료) │
│  Architecture:        100 points             │
│  Convention:           96 points             │
│  Runtime 검증:          미실행 (감점 아님, 별도 관리) │
└─────────────────────────────────────────────┘
```

---

## 9. Recommended Actions

### 9.1 즉시 수정 (Critical)

| Priority | Item | File | 비고 |
|----------|------|------|------|
| 🔴 1 | 상품 검색 결과 XSS 이스케이프 추가 | `admin/expiry_inspection.php`, `admin/expiry_disposal.php` | innerHTML 삽입 전 HTML 이스케이프 |

### 9.2 단기 (문서 정합화, 선택)

| Priority | Item | 비고 |
|----------|------|------|
| 🟡 1 | Design §11.1에 `admin/expiry_run_migration.php` 추가 | 운영 대응으로 추가된 산출물 반영 |
| 🟡 2 | Design §6.2 에러 처리 설명을 실제 구현(페이지 내 즉시 표시)에 맞게 수정 | 세션 플래시가 아닌 로컬 변수 사용 명시 |

### 9.3 백로그

| Item | 비고 |
|------|------|
| 상품 검색 JS 중복 제거 | 3번째 유사 화면이 추가될 때 공용 JS로 분리 고려 (YAGNI 상 지금은 보류) |
| 점검기록에서 기존 로트 미리보기 | FR-01 nuance, Low 우선순위 |

---

## 10. Design Document Updates Needed

- [ ] §11.1 File Structure에 `admin/expiry_run_migration.php` 추가
- [ ] §6.2 에러 표시 방식을 "페이지 내 즉시 표시(로컬 변수)"로 수정

---

## 11. Next Steps

- [ ] Critical(XSS) 수정 — 아래 Checkpoint 5 결정에 따라 진행
- [ ] 사용자가 §2.7 수동 테스트 체크리스트 실행 (마이그레이션 완료 + 화면 3개 정상 동작 확인)
- [ ] 완료 보고서 작성 (`/pdca report expiry-management`)

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-22 | Initial analysis (정적 분석, gap-detector 에이전트 미사용) | whdans007 |
