---
template: plan-plus
version: 1.0
description: Brainstorming-enhanced PDCA Plan template with User Intent, Alternatives, and YAGNI sections
variables:
  - feature: daily-report
  - date: 2026-07-23
  - author: whdans007
  - project: HOME K MART (min)
  - version: 1.0.0
---

# daily-report Planning Document

> **Summary**: office/ Report 섹션에 지점별 "POSCO BRANCH DAILY REPORT" 서식을 그대로 재현하는 일일 통합 리포트 화면을 추가하고, 엑셀/인쇄로 내보낼 수 있게 한다.
>
> **Project**: HOME K MART (min)
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-07-23
> **Status**: Draft
> **Method**: Plan Plus (Brainstorming-Enhanced PDCA)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 매일 점포 마감 시 POS매출·매입·외상·도매판매·기타지출을 각기 다른 화면에서 확인해 수기로 취합해야 하며, 참고 서식(POSCO BRANCH DAILY REPORT)과 동일한 형태의 통합 리포트가 없다. |
| **Solution** | 기존 5개 데이터 소스(`sales_pos_reconciliation`/`sales_pos_payment`/`sales_pos_wholesale_pick`, `office_product_purchases`, `credit_transactions`/`credit_payments`, `sales_daily_items`)를 읽기 전용으로 자동 집계하고, 기타지출만 `expense_report`의 기배치 항목을 드래그로 12개 고정 카테고리에 재분류하는 신규 독립 모듈 `office/daily_report/`를 만든다. |
| **Function/UX Effect** | 날짜 하나를 선택하면 이미지와 동일한 레이아웃으로 자동 채워진 화면이 뜨고, 기타지출만 드래그로 분류한 뒤 엑셀/인쇄로 내보낼 수 있다. 수수료 코너는 서식만 유지된 채 공란으로 남는다. |
| **Core Value** | 마감 취합 시간을 줄이고, 참고 서식을 변형 없이 그대로 재현해 기존 수기 보고 관행과의 단절 없이 디지털화한다. |

---

## 1. User Intent Discovery

### 1.1 Core Problem

점포 마감 시 필요한 매출/매입/외상/도매/기타지출 데이터가 여러 화면(POS 정산, 매입 입력, 외상 관리, Delivery K, expense_report)에 흩어져 있어, 참고 이미지(`1. reference/daily report.png`)와 같은 단일 일일 보고서를 만들려면 매일 수작업 취합이 필요하다.

### 1.2 Target Users

| User Type | Usage Context | Key Need |
|-----------|---------------|----------|
| 점포 담당자(office 권한) | 매일 마감 후 리포트 확인/기타지출 분류 | 자동 집계 + 빠른 기타지출 분류 |
| 점장/센터장 | 결재·검토 | 참고 서식과 동일한 레이아웃으로 즉시 검토 |
| super_admin | 여러 지점 실적 비교 | 지점 선택 후 동일 화면 조회 |

### 1.3 Success Criteria

- [ ] 날짜 선택 시 포스매출·매입·크레딧세부·외상·도매판매가 기존 데이터에서 자동으로 채워진다
- [ ] 기타지출 12개 카테고리를 드래그앤드롭으로 분류하고 날짜별로 저장/재조회된다
- [ ] 엑셀 다운로드 결과가 참고 이미지와 셀 구성·병합·색상까지 동일하다
- [ ] 월별 일괄 엑셀(워크북 1개, 날짜별 시트)과 인쇄 뷰가 제공된다

### 1.4 Constraints

| Constraint | Details | Impact |
|------------|---------|--------|
| 서식 고정 | 참고 이미지의 레이아웃/카테고리 이름을 변경하지 않고 그대로 재현해야 함 | High |
| 데이터 정확성 의존 | 크레딧 세부(BDO 등)는 현장에서 결제 라벨을 일관되게 입력해야 정확히 집계됨 | Medium |
| 수수료 코너 데이터 부재 | 해당 데이터원이 시스템에 없어 1차 버전에서는 공란으로 유지 | Medium |

---

## 2. Alternatives Explored

### 2.1 Approach A: 신규 독립 모듈 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | `office/daily_report/`를 신규 생성해 기존 5개 모듈을 건드리지 않고 별도 리포트로 구축 |
| **Pros** | 기존 모듈(expense_report, sales, purchase, credit) 무변경으로 회귀 위험 없음. 서식이 완전히 다른 리포트를 독립 구조로 깔끔하게 관리 |
| **Cons** | 날짜선택기/드래그드롭 UI를 새로 작성해야 함 (다만 expense_report의 패턴을 참고 가능) |
| **Effort** | Medium |
| **Best For** | 기존 리포트와 서식/데이터 구조가 근본적으로 다른 경우 |

### 2.2 Approach B: expense_report 모듈 확장

| Aspect | Details |
|--------|---------|
| **Summary** | 기존 `office/expense_report/index.php`에 "Daily Report 보기 모드"를 추가 |
| **Pros** | 날짜선택/드래그드롭 스캐폴딩 재사용 가능 |
| **Cons** | 두 리포트는 구조가 근본적으로 다름(5구간 그리드 vs 12카테고리+5개 외부 데이터 소스 집계). 이미 33KB인 index.php에 서로 다른 서식을 함께 관리하게 되어 유지보수 위험 증가 |
| **Effort** | Medium-High |
| **Best For** | 두 리포트가 데이터/레이아웃을 상당 부분 공유할 때 (해당 없음) |

### 2.3 Decision Rationale

**Selected**: Approach A
**Reason**: 두 리포트의 서식과 집계 로직이 근본적으로 다르고, 기존 expense_report는 이미 별도 목적(PARTICULARS 5구간)으로 안정 운영 중이므로 건드리지 않는 것이 안전. 사용자 확인 완료.

---

## 3. YAGNI Review

### 3.1 Included (v1 Must-Have)

- [ ] 일일 뷰: 날짜 선택 + 포스매출/크레딧세부/매입/외상/도매판매 자동 집계
- [ ] 기타지출 드래그앤드롭 재분류 (12개 고정 카테고리, expense_report 기배치 항목을 소스로 사용)
- [ ] 엑셀 다운로드 (일일, 참고 서식과 동일)
- [ ] 월별 일괄 엑셀 다운로드 (워크북 1개, 날짜별 시트)
- [ ] 인쇄용 뷰
- [ ] super_admin 지점 선택 조회
- [ ] Report 섹션 네비게이션에 "Daily Report" 메뉴 추가

### 3.2 Deferred (v2+ Maybe)

| Feature | Reason for Deferral | Revisit When |
|---------|---------------------|--------------|
| 수수료 코너 데이터 입력 | 시스템에 데이터 소스 자체가 없음, 신규 모듈 필요 | 입점업체 콘셉션 판매 관리 요구가 구체화될 때 |
| BDO 등 결제사 라벨 고정 카테고리화 | 현재는 자유텍스트 라벨을 문자열 매칭으로 분류; 매장에 라벨 표준화를 강제하지 않음 | 라벨 오분류 사례가 반복되면 `sales_pos_payment.method`에 전용 enum 추가 검토 |

### 3.3 Removed (Won't Do)

| Feature | Reason for Removal |
|---------|-------------------|
| expense_report 자동 소스 연동 확장(영수증/설비매입 등 신규 소스 추가) | 이번 리포트는 expense_report에 "이미 배치된" 항목만 재분류 대상으로 삼기로 확정, 소스 확장은 범위 밖 |

---

## 4. Scope

### 4.1 In Scope

- [ ] `office/daily_report/index.php` — 날짜 선택형 메인 뷰(자동 집계 + 기타지출 드래그드롭)
- [ ] `office/daily_report/ajax_load_expense_items.php` — `er_saved_state`에서 기타지출(OTHER EXP CHECK/CASH) 항목 로드
- [ ] `office/daily_report/ajax_save_categories.php` — 항목→12개 카테고리 배치 저장/삭제
- [ ] `office/daily_report/export_daily_report.php` — 일일 엑셀 (PhpSpreadsheet, 셀 병합/색상 복제)
- [ ] `office/daily_report/export_daily_report_monthly.php` — 월별 워크북(날짜별 시트)
- [ ] `office/daily_report/print_daily_report.php` — 인쇄용 HTML
- [ ] 신규 테이블 `daily_report_expense_category` (store_id, sale_date, source_item_id, category_key)
- [ ] `office/partials/header.php` Report 섹션에 nav 링크 추가
- [ ] super_admin 지점 선택 드롭다운 (`?store_id=` 파라미터, 권한 체크)

### 4.2 Out of Scope

- 수수료 코너(BREAD DAWN, SOBOK 등) 데이터 입력 — (YAGNI Deferred)
- 기타지출 카테고리 목록 자체를 사용자가 추가/편집하는 기능 — 12개 고정
- expense_report의 소스 연동 범위 확장 — (YAGNI Removed)

---

## 5. Requirements

### 5.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 날짜 선택 시 포스매출(3교대×2POS, 현금/크레딧/거래명세표)을 `sales_pos_reconciliation`+`sales_pos_wholesale_pick`에서 자동 집계해 표시 | High | Pending |
| FR-02 | 크레딧(BDO/GCASH/MAYA/OTHERS) 세부를 `sales_pos_payment`에서 라벨 매칭으로 자동 집계 (gcash→GCASH, paymaya→MAYA, description에 'BDO' 포함→BDO, 그 외 전부→OTHERS) | High | Pending |
| FR-03 | 매입(현금/체크, 거래처별)을 `office_product_purchases`에서 자동 집계 | High | Pending |
| FR-04 | 외상판매/외상수금을 `credit_transactions`/`credit_payments`에서 자동 집계 | High | Pending |
| FR-05 | 도매판매(거래명세서)·Delivery K를 `sales_daily_items`(item_type: delivery_k, whole_sale)에서 자동 집계 | High | Pending |
| FR-06 | 수수료 코너 섹션은 서식만 유지하고 항상 공란/0으로 표시 | Medium | Pending |
| FR-07 | 기타지출: `er_saved_state`의 OTHER EXP CHECK/CASH 항목을 소스 카드로 표시, 드래그로 12개 고정 카테고리(반품/인건비/전기세·관리비·CDC·BIR/PLDT·LPG·방역/사무실비품·판매소품/농산축산수산키친/차량유지비/일반할인5%/한인회5%/MAINTENANCE/기타/포인트사용)에 배치 | High | Pending |
| FR-08 | 미분류 기타지출 항목은 합계에서 제외하고 화면에 "미분류 N건" 경고 표시 | Medium | Pending |
| FR-09 | 일일 엑셀 다운로드는 참고 이미지와 동일한 셀 구성/병합/색상으로 생성 | High | Pending |
| FR-10 | 월별 일괄 엑셀은 워크북 1개에 날짜별 시트로 생성 | Medium | Pending |
| FR-11 | 인쇄용 뷰 제공 (expense_report의 print_er.php 패턴 참고) | Medium | Pending |
| FR-12 | super_admin은 지점 선택 드롭다운으로 타 지점 리포트 조회 가능, 그 외 사용자는 본인 점포만 조회 | Medium | Pending |
| FR-13 | 회사명(제목 표시)은 `stores.company_name` 우선, 없으면 `stores.name` 사용 (print_er.php와 동일 패턴) | Low | Pending |

### 5.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Security | 모든 쿼리 prepared statement, `require_office_permission()`으로 접근 제어, store 스코프 강제 | 코드 리뷰 |
| Data Integrity | 기타지출 카테고리 테이블은 금액을 중복 저장하지 않고 `er_saved_state` 원본을 참조 | 코드 리뷰 |
| Consistency | 날짜/점포 스코프 조회 패턴은 기존 office 모듈(`get_office_store_id()`, prev/next 날짜 네비게이션)과 동일하게 유지 | 코드 리뷰 |

---

## 6. Success Criteria

### 6.1 Definition of Done

- [ ] 모든 기능 요구사항(FR-01~13) 구현
- [ ] 실제 하루치 데이터로 화면·엑셀 값이 참고 이미지와 일치하는지 수동 검증
- [ ] 기타지출 드래그드롭 저장/재조회 동작 확인
- [ ] 문서화 완료 (Design 문서)

### 6.2 Quality Criteria

- [ ] PHP 구문 오류 없음 (`php -l`)
- [ ] 기존 office 모듈 권한/스코프 패턴 준수
- [ ] 엑셀 출력 레이아웃 육안 비교 통과

---

## 7. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| BDO 등 결제 라벨이 매장마다 다르게 입력되어 자동집계가 부정확할 수 있음 | Medium | Medium | description 문자열 매칭 규칙을 명확히 문서화하고, OTHERS로 빠진 항목을 화면에서 눈에 띄게 표시해 검증 가능하게 함 |
| 기타지출 소스(`er_saved_state`)가 없는 날짜(과거 데이터 미저장)일 경우 소스 카드가 비어 분류 불가 | Low | Medium | 소스 없음 상태를 명확히 안내, expense_report에서 먼저 저장하도록 UX 안내 |
| 엑셀 서식 재현이 100% 일치하지 않을 위험(병합/색상 diff) | Medium | Medium | 참고 이미지를 기준으로 셀 단위 체크리스트 작성 후 육안 비교 |
| 수수료 코너 공란이 "버그"로 오인될 수 있음 | Low | Low | 화면에 "수수료 코너 데이터는 별도 입력 기능이 아직 없습니다" 안내 문구 표시 |

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
| 구현 방식 | 신규 독립 모듈 vs expense_report 확장 | 신규 독립 모듈 | 서식/집계 로직이 근본적으로 다름, 기존 모듈 회귀 위험 회피 |
| 수수료 코너 | 수동입력/드래그드롭/범위제외 | 범위 제외(서식만 유지) | 데이터 소스 자체가 없어 신규 모듈이 필요, 1차 범위 밖 |
| 크레딧 세부 집계 | 자동집계 vs 수동입력 | 자동집계 (`sales_pos_payment` 라벨 매칭) | 이미 데이터가 존재하므로 재입력 방지 |
| 기타지출 방식 | 완전 수동 입력 vs expense_report 데이터 재사용 | expense_report 데이터 재사용 + 신규 카테고리 드래그드롭 | 기존 입력 노동 재사용, 12개 카테고리는 이 리포트 전용이라 별도 매핑 테이블 필요 |
| 도매판매/거래명세표 | wholesale_sales 모듈 vs 기존 Delivery K/Whole Sale 데이터 | `sales_daily_items` 사용 | 현재 운영 중인 입력 경로와 일치 |
| BDO 판별 | method 고정값 vs description 문자열 매칭 | description에 'BDO' 포함 시 분류 | 현재 스키마에 BDO 전용 컬럼 없음, 최소 변경으로 매칭 |
| 월별 엑셀 구조 | 요약 1행/일 vs 날짜별 시트 | 워크북 1개, 날짜별 시트 | 서식 그대로 유지 요구사항과 부합 |
| 미분류 기타지출 처리 | 자동 '기타' 편입 vs 합계 제외+경고 | 합계 제외 + 화면 경고 | 사용자가 분류 누락을 인지하고 직접 처리하도록 유도 |

### 8.3 Component Overview

```
office/daily_report/
├── index.php                        # 메인 뷰 (날짜 선택 + 자동 집계 + 기타지출 드래그드롭)
├── ajax_load_expense_items.php      # er_saved_state → 기타지출 소스 카드 로드
├── ajax_save_categories.php         # 카테고리 배치 저장/삭제
├── export_daily_report.php          # 일일 엑셀 (PhpSpreadsheet)
├── export_daily_report_monthly.php  # 월별 워크북 (날짜별 시트)
├── print_daily_report.php           # 인쇄용 뷰
└── sql/
    └── create_daily_report_expense_category.sql

office/partials/header.php           # Report 섹션에 nav 링크 추가 (기존 파일 수정)
```

### 8.4 Data Flow

```
[index.php] 날짜/점포 선택
   ├─▶ sales_pos_reconciliation + sales_pos_wholesale_pick  → 포스매출(현금/크레딧/거래명세표) by shift×pos_no
   ├─▶ sales_pos_payment (method/description 매칭)          → 크레딧 세부 BDO/GCASH/MAYA/OTHERS
   ├─▶ office_product_purchases                              → 매입(현금/체크) by 거래처
   ├─▶ credit_transactions / credit_payments                 → 외상판매 / 외상수금
   ├─▶ sales_daily_items (delivery_k, whole_sale)             → 도매판매(거래명세서)
   ├─▶ (없음 — 서식만 유지)                                    → 수수료 코너
   └─▶ er_saved_state(OTHER EXP) ⋈ daily_report_expense_category
           ├─ 배치됨 → 12개 카테고리별 합계
           └─ 미배치 → 화면 경고, 합계 제외

[export_daily_report.php] index.php와 동일 집계 로직 재사용 → PhpSpreadsheet 렌더링
[export_daily_report_monthly.php] 월 전체 날짜 루프 → 시트별 동일 렌더링
```

---

## 9. Convention Prerequisites

### 9.1 Applicable Conventions

- [x] 기존 office 모듈 컨벤션 확인 (`get_office_store_id()`, `require_office_permission()`, prev/next 날짜 네비게이션, `print_er.php`의 회사명 조회 패턴)
- [x] 네이밍 규칙 확인 (`office_*` 테이블명 컨벤션, `ajax_*.php` 엔드포인트 컨벤션)
- [x] 폴더 구조 규칙 확인 (`office/{module}/index.php` + `sql/` 하위 폴더)

---

## 10. Next Steps

1. [ ] Write design document (`/pdca design daily-report`)
2. [ ] Team review and approval
3. [ ] Start implementation (`/pdca do daily-report`)

---

## Appendix: Brainstorming Log

> Key decisions from Plan Plus Phases 1-4.

| Phase | Question | Answer | Decision |
|-------|----------|--------|----------|
| Intent | Report 섹션에 일일보고 기능 추가, 참고 서식(daily report.png) 그대로 재현, 엑셀 다운로드, 현재 로그인 점포 사용 | 사용자 최초 요청 | 신규 Daily Report 화면 기획 확정 |
| Context | 각 섹션 데이터 소스 조사 (POS/매입/도매/외상/수수료) | POS·매입·외상·도매는 기존 테이블 존재, 수수료 코너는 부재, Delivery K는 wholesale_sales가 아닌 sales_daily_items에 있음 | 기존 테이블 재사용 + 수수료 코너 범위 제외 |
| Intent Q1 | 수수료 코너 입력 방식 | 1차 범위 제외 | YAGNI Deferred로 이동 |
| Intent Q2 | 크레딧(BDO/GCASH/MAYA/OTHERS) 세부 방식 | 기존 데이터 자동 집계 | sales_pos_payment 라벨 매칭 채택 |
| Intent Q3 | 기타지출 등록 방식 | expense_report 데이터 재사용 | er_saved_state 기배치 항목을 드래그 소스로 사용 |
| Intent Q4 | 도매판매/거래명세표 데이터 출처 | 기존 Delivery K/Whole Sale 데이터(sales_daily_items) 사용 | wholesale_sales 모듈 대신 채택 |
| Alternatives | 신규 독립 모듈 vs expense_report 확장 | 신규 독립 모듈 | 서식/로직 이질성으로 Approach A 선택 |
| YAGNI | Print 뷰 / 월별 일괄 엑셀 / super_admin 타 지점 조회 | 3개 모두 포함 | 전부 v1 In Scope로 편입 |
| Design Validation | BDO 매핑 규칙 | description에 'BDO' 포함 시 분류 | FR-02 확정 |
| Design Validation | 월별 엑셀 구조 | 워크북 1개, 날짜별 시트 | FR-10 확정 |
| Design Validation | 기타지출 미분류 처리 | 합계 제외 + 화면 경고 | FR-08 확정 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-23 | Initial draft (Plan Plus) | whdans007 |
