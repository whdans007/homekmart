---
template: plan-plus
version: 1.0
---

# sales-expenses-report Planning Document

> **Summary**: main_office에 점포별 월간 "일자 × 매출(현금/카드) · 지출(현금/수표) · 손익" 단순 요약 그리드 리포트를 신규 추가한다.
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: whdans007
> **Date**: 2026-08-27
> **Status**: Draft
> **Method**: Plan Plus (Brainstorming-Enhanced PDCA)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | main_office에는 매출을 상세 셀 단위로 보여주는 Sales Report와 지출을 카테고리별로 보여주는 여러 리포트(Cash Disbursement, Cheque Expense Report)가 각각 따로 존재해서, "이번 달에 현금/카드로 얼마 벌고 현금/수표로 얼마 썼고 손익이 얼마인지"를 한눈에 보려면 여러 화면을 오가야 한다. |
| **Solution** | 참고 서식(SALES AND EXPENSES REPORT) 그대로, 일자별로 SALES(CASH/CREDITS) + EXPENSES(CASH/CHEQUE) + TOTAL + PROFIT/LOSS를 한 그리드에 보여주는 신규 페이지를 추가한다. 데이터는 기존 sales_pos_reconciliation, cd_saved_state, cer_saved_state를 그대로 재사용해 자동 집계한다 (수기 입력 없음). |
| **Function/UX Effect** | main_office 메뉴에 "Sales & Expenses Report" 항목이 추가되고, 점포 선택 + 월 이동으로 월간 손익 요약을 즉시 확인 가능. Excel 다운로드 지원. |
| **Core Value** | 여러 리포트를 조합해서 봐야 했던 월간 손익 파악을 한 화면으로 단순화. 기존 데이터 소스를 그대로 재사용하므로 데이터 정합성 이슈 없이 낮은 리스크로 추가 가능. |

---

## 1. User Intent Discovery

### 1.1 Core Problem

main_office 관리자가 점포의 월간 현금흐름(매출 대비 지출, 손익)을 파악하려면 Sales Report(상세 POS 셀), Cash Disbursement(일별 현금지출 저장분), Cheque Expense Report(일별 수표지출 저장분) 3개 화면을 각각 열어 합산해야 했다. 참고 이미지 서식처럼 이 4가지 숫자를 일자별로 나란히 보여주는 단순 요약 뷰가 없었다.

### 1.2 Target Users

| User Type | Usage Context | Key Need |
|-----------|---------------|----------|
| main_office_admin (본사 관리자) | 월말/수시로 점포별 손익을 검토할 때 | 일자별 현금/카드 매출과 현금/수표 지출, 월 손익을 한 화면에서 확인 |

### 1.3 Success Criteria

- [ ] 특정 점포/월을 선택하면 1~말일까지 SALES(CASH/CREDITS), EXPENSES(CASH/CHEQUE) 금액이 정확히 표시된다 (기존 Cash Disbursement/Cheque Expense Report/Sales Report의 합계와 교차 검증 시 일치).
- [ ] TOTAL 행과 PROFIT/LOSS가 자동 계산되어 표시된다.
- [ ] Excel로 다운로드할 수 있다.
- [ ] main_office 상단 메뉴 및 점포별 카드에서 새 리포트로 진입할 수 있다.

### 1.4 Constraints

| Constraint | Details | Impact |
|------------|---------|--------|
| 커넥션 버스트 이슈 | 과거 main_office/index.php에서 점포별 반복 쿼리로 공유호스팅 DB 커넥션 제한에 걸린 사고 이력 있음 (해당 파일 주석 참고) | High — 일자별 반복 쿼리(N+1) 금지, 월 단위 배치 쿼리로 설계 필수 |
| 기존 SSOT 패턴 준수 | 집계 로직은 office/lib/*.php의 공용 헬퍼 함수로 분리해 office(점포 세션)/main_office(전점포 열람)가 공유하는 기존 컨벤션을 따라야 함 | Medium |

---

## 2. Alternatives Explored

### 2.1 Approach A: 신규 페이지 + 공용 집계 헬퍼 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | `main_office/sales_expenses_report.php` 신규 페이지 + `office/lib/sales_expenses_report_helper.php`의 `get_monthly_sales_expenses_report()` 공용 함수 + `export_sales_expenses_report.php` Excel 내보내기 |
| **Pros** | 기존 아키텍처(읽기 전용 집계 헬퍼, main_office 진입점) 패턴 그대로 따름. 다른 리포트에 전혀 영향 없음. 독립적으로 테스트 가능 |
| **Cons** | 신규 파일 2~3개 추가 필요 |
| **Effort** | Medium |
| **Best For** | 기존 리포트 구조를 건드리지 않고 안전하게 신규 기능을 얹어야 할 때 |

### 2.2 Approach B: 기존 Sales Report에 탭으로 통합

| Aspect | Details |
|--------|---------|
| **Summary** | `sales_report.php`에 탭 UI를 추가해 상세 뷰/요약 뷰를 전환 |
| **Pros** | 신규 파일이 적음 |
| **Cons** | 기존 상세 리포트(GY/Morning/Mid × POS1/POS2 세분화)와 이 단순 요약 리포트가 한 페이지 안에서 상태를 공유하게 되어 파라미터/네비게이션 로직이 복잡해짐. 기존 Sales Report 회귀 위험 |
| **Effort** | Medium-High (기존 코드 수정 리스크 포함) |
| **Best For** | 두 리포트가 데이터/네비게이션을 강하게 공유해야 하는 경우 (해당 없음) |

### 2.3 Decision Rationale

**Selected**: Approach A
**Reason**: 기존 5개 main_office 리포트가 모두 "독립 파일 + 공용 헬퍼" 패턴을 따르고 있고, 이 리포트는 기존 3개 리포트(Sales/Cash Disbursement/Cheque Expense)의 숫자를 재조합해 보여주는 성격이라 기존 파일을 건드릴 필요가 없다. 회귀 리스크를 최소화하는 방향을 선택.

---

## 3. YAGNI Review

### 3.1 Included (v1 Must-Have)

- [ ] 일자별(1~말일) SALES CASH / SALES CREDITS / EXPENSES CASH / EXPENSES CHEQUE 4개 열
- [ ] TOTAL 행 (열별 합계)
- [ ] PROFIT/LOSS (= 매출 합계 − 지출 합계)
- [ ] 점포 선택 드롭다운 (기존 main_office 리포트와 동일하게 물류센터/킴스몰 창고 제외)
- [ ] 월 이동(이전/다음 달) 네비게이션
- [ ] Excel 다운로드
- [ ] main_office 상단 메뉴 + 점포 카드에 진입 링크 추가

### 3.2 Deferred (v2+ Maybe)

| Feature | Reason for Deferral | Revisit When |
|---------|---------------------|--------------|
| 참고 이미지와 동일한 인쇄용 A4 서식(오렌지 헤더 등) | 이번 요청의 핵심은 "화면에서 숫자를 한눈에 보는 것" — 인쇄 서식은 별도 요구사항으로 취급 | 실제 인쇄/제출용 문서가 필요하다는 요청이 들어올 때 |
| PARTICULAR(일자별 메모) 열 | 자동집계 리포트에 수기 메모 입력 UI를 추가하면 범위가 커짐 | 특정 날짜에 메모를 남기고 싶다는 요청이 들어올 때 |

### 3.3 Removed (Won't Do)

| Feature | Reason for Removal |
|---------|-------------------|
| 전 점포 합산(consolidated) 뷰 | 요청 범위 밖 — 기존 리포트들도 점포 단위 조회만 지원하며 일관성 유지 |

---

## 4. Scope

### 4.1 In Scope

- [ ] `office/lib/sales_expenses_report_helper.php`: `get_monthly_sales_expenses_report(int $store_id, int $year, int $month): array`
  - SALES.CASH/CREDITS: `sales_pos_reconciliation`을 `DAY(sale_date)`로 그룹핑해 `SUM(deposit_cash)`, `SUM(other_total)` 1회 쿼리
  - EXPENSES.CASH: `cd_saved_state`에서 해당 월 전체 행을 1회 쿼리로 가져와 PHP에서 일자별 4섹션(korean/local/fixed/others) 합계 계산 (`print_cd.php`의 `$grand` 로직과 동일)
  - EXPENSES.CHEQUE: `cer_saved_state`도 동일하게 월 전체 1회 쿼리, `returned` 항목 제외 후 합산 (`print_cer.php`의 `$sumSec` 로직과 동일)
  - 일자별 TOTAL, 월 전체 합계(`col_totals`), `profit_loss` 반환
- [ ] `main_office/sales_expenses_report.php`: 점포 선택 + 월 이동 UI + 그리드 테이블 (기존 `sales_report.php`와 동일한 페이지 구조/스타일)
- [ ] `main_office/export_sales_expenses_report.php`: PhpSpreadsheet 기반 Excel 내보내기 (기존 `export_sales_report.php` 패턴 재사용)
- [ ] `main_office/partials/header.php` nav에 메뉴 항목 추가
- [ ] `main_office/index.php` 점포 카드에 버튼 추가

### 4.2 Out of Scope

- 인쇄용 A4 정밀 서식 재현 — (YAGNI Review 3.2)
- PARTICULAR 메모 입력 — (YAGNI Review 3.2)
- 전 점포 합산 뷰 — (YAGNI Review 3.3)

---

## 5. Requirements

### 5.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 점포+연월 선택 시 1~말일 SALES CASH/CREDITS, EXPENSES CASH/CHEQUE를 정확히 집계해 표시한다 | High | Pending |
| FR-02 | TOTAL 행과 PROFIT/LOSS를 자동 계산해 표시한다 | High | Pending |
| FR-03 | Excel 다운로드 버튼으로 동일 데이터를 내려받을 수 있다 | Medium | Pending |
| FR-04 | 물류센터/킴스몰 창고는 점포 목록에서 제외한다 (기존 리포트 컨벤션과 동일) | High | Pending |
| FR-05 | main_office 상단 메뉴 및 점포 카드에서 새 리포트로 진입 가능하다 | Medium | Pending |

### 5.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|--------------------|
| Performance / DB 커넥션 | 페이지 1회 로드 시 DB 쿼리는 상수 개(3~4회) 이내 — 일자 수(최대 31)에 비례한 반복 쿼리 금지 | 코드 리뷰 — 루프 내 `get_db_connection()`/쿼리 호출 없는지 확인 |
| 데이터 정합성 | 신규 리포트의 월 합계가 기존 Cash Disbursement/Cheque Expense Report/Sales Report 각 화면에서 수동으로 합산한 값과 일치 | 임의 점포/월 선택 후 교차 검증 |

---

## 6. Success Criteria

### 6.1 Definition of Done

- [ ] `office/lib/sales_expenses_report_helper.php` 구현 완료
- [ ] `main_office/sales_expenses_report.php` 구현 완료 (점포 선택/월 이동/그리드/TOTAL/PROFIT-LOSS)
- [ ] `main_office/export_sales_expenses_report.php` Excel 내보내기 구현 완료
- [ ] 메뉴 진입점 2곳(nav, 점포 카드) 추가 완료
- [ ] 기존 3개 리포트와 숫자 교차 검증 완료

### 6.2 Quality Criteria

- [ ] `php -l`로 신규 파일 문법 검증
- [ ] 루프 내 반복 DB 쿼리(N+1) 없음 확인

---

## 7. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 일자별 반복 쿼리로 인한 DB 커넥션 버스트 재발 | High | Medium (설계 실수 시) | 월 단위 배치 쿼리(2~3회)로 설계, 코드 리뷰에서 루프 내 쿼리 여부 확인 |
| CASH/CREDITS·CASH/CHEQUE 정의가 실제 회계 관행과 다를 가능성 | Medium | Low (기존 화면과 동일 소스 재사용) | 배포 후 실제 관리자가 기존 3개 리포트와 대조 확인 |

---

## 8. Architecture Considerations

### 8.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure | Static sites | |
| **Dynamic** | Feature-based modules, DB-backed web app | Web apps with backend | ✅ |
| **Enterprise** | Strict layer separation, microservices | High-traffic systems | |

### 8.2 Key Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 데이터 소스 | 자동 집계 vs 수기 입력 vs 혼합 | 자동 집계 | 기존 3개 리포트의 데이터를 재사용하면 이중 입력/정합성 이슈 없이 안전 |
| 페이지 구조 | 신규 페이지 vs 기존 페이지에 탭 통합 | 신규 페이지 | 기존 Sales Report 회귀 위험 회피, 컨벤션 일관성 |
| 지출 집계 쿼리 방식 | 일자별 반복 조회 vs 월 단위 배치 조회 | 월 단위 배치 조회 | 과거 커넥션 버스트 사고 재발 방지 |

### 8.3 Component Overview

```
main_office/
  sales_expenses_report.php      (신규 — 점포선택+월이동+그리드)
  export_sales_expenses_report.php (신규 — Excel 내보내기)
  partials/header.php            (수정 — nav 메뉴 항목 추가)
  index.php                      (수정 — 점포 카드 버튼 추가)

office/lib/
  sales_expenses_report_helper.php (신규 — get_monthly_sales_expenses_report())
```

### 8.4 Data Flow

```
[main_office/sales_expenses_report.php]
        │  store_id, year, month
        ▼
[get_monthly_sales_expenses_report()]
        │
        ├─▶ SELECT DAY(sale_date), SUM(deposit_cash), SUM(other_total)
        │     FROM sales_pos_reconciliation WHERE store_id=? AND YEAR=? AND MONTH=?
        │     GROUP BY DAY(sale_date)                         → SALES.CASH / SALES.CREDITS
        │
        ├─▶ SELECT save_date, state_json FROM cd_saved_state
        │     WHERE store_id=? AND YEAR(save_date)=? AND MONTH(save_date)=?
        │     → PHP에서 일자별 4섹션 합산                       → EXPENSES.CASH
        │
        └─▶ SELECT save_date, state_json FROM cer_saved_state
              WHERE store_id=? AND YEAR(save_date)=? AND MONTH(save_date)=?
              → PHP에서 일자별 4섹션 합산 (returned 제외)         → EXPENSES.CHEQUE
        │
        ▼
  rows[1..N] = {cash_sales, credit_sales, cash_expense, cheque_expense, total, ...}
  col_totals, profit_loss
        │
        ▼
  그리드 렌더링 + Excel export
```

---

## 9. Convention Prerequisites

### 9.1 Applicable Conventions

- [x] 기존 main_office 리포트 패턴(점포 목록 제외 조건, nav 구조, 헤더/푸터) 확인 완료
- [x] 명명 규칙 확인 — kebab-case 기능명 `sales-expenses-report`, 파일명은 기존 컨벤션(snake_case PHP 파일) 준수
- [x] 폴더 구조 규칙 확인 — `main_office/`, `office/lib/` 기존 구조 그대로 사용

---

## 10. Next Steps

1. [ ] 설계 문서 작성 (`/pdca design sales-expenses-report`)
2. [ ] 리뷰 및 승인
3. [ ] 구현 시작 (`/pdca do sales-expenses-report`)

---

## Appendix: Brainstorming Log

| Phase | Question | Answer | Decision |
|-------|----------|--------|----------|
| Intent | 데이터가 어디서 오는가? | 기존 데이터 자동 집계 | sales_pos_reconciliation + cd_saved_state + cer_saved_state 재사용 |
| Intent | 적용 범위 / PARTICULAR 열 | 모든 점포 / PARTICULAR는 생략 | v1 스코프 확정 |
| Alternatives | 신규 페이지 vs 기존 페이지 탭 통합 | 신규 페이지 | Approach A 선택 — 회귀 리스크 최소화 |
| YAGNI | Excel 내보내기 / 인쇄용 A4 서식 | Excel만 포함, A4 서식은 보류 | 3.1/3.2 반영 |
| Design | 신규 페이지+헬퍼+배치쿼리 최적화 설계 | 승인 | 커넥션 버스트 리스크 명시적으로 설계에 반영 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-27 | Initial draft (Plan Plus) | whdans007 |
