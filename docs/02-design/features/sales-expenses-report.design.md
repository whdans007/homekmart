---
template: design
version: 1.3
---

# sales-expenses-report Design Document

> **Summary**: main_office에 점포별 월간 매출(현금/카드)·지출(현금/수표)·손익을 일자별 그리드로 보여주는 신규 리포트 페이지.
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: whdans007
> **Date**: 2026-08-27
> **Status**: Draft
> **Planning Doc**: [sales-expenses-report.plan.md](../01-plan/features/sales-expenses-report.plan.md)

> **Note**: 이 프로젝트는 PHP 서버 렌더링 모놀리스(REST API/TypeScript/React 없음)이므로, 템플릿의
> API Specification·Clean Architecture(TS 레이어)·MongoDB 섹션은 이 기능에 적용되지 않아 N/A 처리했다.

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 매출/지출이 3개 화면(Sales Report, Cash Disbursement, Cheque Expense Report)에 흩어져 있어 월간 손익 파악이 번거로움 |
| **WHO** | main_office_admin (본사 관리자) |
| **RISK** | 일자별 반복 DB 쿼리로 인한 공유호스팅 커넥션 버스트 재발 (과거 사고 이력) |
| **SUCCESS** | 기존 3개 리포트와 숫자가 정확히 교차 검증되고, 월 손익이 한 화면에 표시됨 |
| **SCOPE** | 신규 페이지 1 + 헬퍼 1 + Excel export 1, 기존 파일은 nav/카드 링크 추가만 |

---

## 1. Overview

### 1.1 Design Goals

- 기존 3개 리포트(Sales Report / Cash Disbursement / Cheque Expense Report)가 이미 가진 데이터를 **재계산 없이 그대로 재사용**해 일자별 단순 그리드로 재구성한다.
- 월 1회 로드 시 DB 쿼리를 **상수 개(3회)**로 고정해 N+1 쿼리로 인한 커넥션 버스트를 원천 차단한다.
- 기존 main_office 5개 리포트와 동일한 페이지 구조/네비게이션 패턴을 따라 학습 비용 없이 자연스럽게 녹아들게 한다.

### 1.2 Design Principles

- **SSOT 재사용**: 새 계산식을 만들지 않고 `sales_pos_reconciliation`, `cd_saved_state`, `cer_saved_state`라는 기존 소스만 재집계한다.
- **기존 파일 무수정**: `print_cd.php`/`print_cer.php`/`sales_report.php` 등 운영 중인 파일의 내부 로직은 건드리지 않는다 (Option C).
- **N+1 금지**: 월 단위 배치 쿼리 1~2회로 31일치 데이터를 한 번에 가져온다.

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 페이지+export를 한 파일에서 분기 | GRAND TOTAL 계산을 공용 함수로 추출해 기존 파일까지 리팩터링 | 새 헬퍼가 자체적으로 재구현(중복 허용), 기존 파일 무수정 |
| **New Files** | 2 | 3 | 3 |
| **Modified Files** | 2 | 4 (print_cd.php, print_cer.php 포함) | 2 |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Medium | High | High |
| **Effort** | Low | High | Medium |
| **Risk** | Low | Medium (운영 파일 수정) | Low |
| **Recommendation** | 빠른 결과물 | 장기 중복 제거 | **선택됨** |

**Selected**: Option C — **Rationale**: Plan 단계에서 이미 "기존 파일 무수정으로 회귀 리스크 최소화"를 결정했고, GRAND TOTAL 합산은 몇 줄짜리 단순 배열 합계라 중복 허용 비용이 낮음.

### 2.1 Component Diagram

```
┌──────────────────────────────┐
│ main_office/                 │
│  sales_expenses_report.php   │──┐
│  (점포선택+월이동+그리드 UI)   │  │
└──────────────────────────────┘  │
                                   │ include
┌──────────────────────────────┐  │
│ office/lib/                  │◀─┘
│  sales_expenses_report_helper.php
│  get_monthly_sales_expenses_report()
└──────────────┬────────────────┘
               │ SELECT (3 queries, batched by month)
               ▼
┌──────────────────────────────────────────┐
│ MySQL                                     │
│  sales_pos_reconciliation (deposit_cash,  │
│    other_total) — GROUP BY DAY(sale_date) │
│  cd_saved_state  (state_json)             │
│  cer_saved_state (state_json)             │
└────────────────────────────────────────────┘

┌──────────────────────────────┐
│ main_office/                 │
│  export_sales_expenses_report.php  ──▶ 동일 헬퍼 호출 → SpreadsheetML(XML) 응답
└──────────────────────────────┘
```

### 2.2 Data Flow

```
GET sales_expenses_report.php?store_id&year&month
        │
        ▼
get_monthly_sales_expenses_report($store_id, $year, $month)
        │
        ├─ Query 1: sales_pos_reconciliation
        │     SELECT DAY(sale_date) d, SUM(deposit_cash) cash, SUM(other_total) credit
        │     FROM sales_pos_reconciliation
        │     WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?
        │     GROUP BY DAY(sale_date)
        │
        ├─ Query 2: cd_saved_state (월 전체 1회)
        │     SELECT save_date, state_json FROM cd_saved_state
        │     WHERE store_id=? AND YEAR(save_date)=? AND MONTH(save_date)=?
        │     → PHP 루프에서 day => grand total (4섹션 합계, print_cd.php와 동일 공식)
        │
        ├─ Query 3: cer_saved_state (월 전체 1회)
        │     SELECT save_date, state_json FROM cer_saved_state
        │     WHERE store_id=? AND YEAR(save_date)=? AND MONTH(save_date)=?
        │     → PHP 루프에서 day => grand total (returned 제외, print_cer.php와 동일 공식)
        │
        ▼
rows[1..N] = ['cash_sales','credit_sales','cash_expense','cheque_expense','sales_total','expense_total','net']
col_totals = 각 열 합계
profit_loss = col_totals['sales_total'] - col_totals['expense_total']
        │
        ▼
sales_expenses_report.php → HTML 그리드 렌더링
export_sales_expenses_report.php → 동일 데이터로 SpreadsheetML(.xls) 응답
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `sales_expenses_report.php` | `sales_expenses_report_helper.php`, `main_office/lib/auth.php`, `main_office/partials/header.php` | 화면 렌더링 |
| `export_sales_expenses_report.php` | `sales_expenses_report_helper.php`, `main_office/lib/auth.php` | Excel(XML) 응답 |
| `sales_expenses_report_helper.php` | `config/db_config.php` (`get_db_connection()`) | DB 집계 |

---

## 3. Data Model

### 3.1 반환 데이터 구조 (신규 테이블 없음 — 기존 3개 테이블 재집계)

```php
// get_monthly_sales_expenses_report(int $store_id, int $year, int $month): array
[
  'days'        => int,               // 해당 월 일수
  'rows'        => [                  // 1..days
      1 => [
        'cash_sales'     => float,    // sales_pos_reconciliation.deposit_cash 일 합계
        'credit_sales'   => float,    // sales_pos_reconciliation.other_total 일 합계
        'cash_expense'   => float,    // cd_saved_state 해당일 GRAND TOTAL
        'cheque_expense' => float,    // cer_saved_state 해당일 GRAND TOTAL (returned 제외)
        'sales_total'    => float,    // cash_sales + credit_sales
        'expense_total'  => float,    // cash_expense + cheque_expense
        'net'            => float,    // sales_total - expense_total
      ],
      // ...
  ],
  'col_totals'  => [
      'cash_sales' => float, 'credit_sales' => float,
      'cash_expense' => float, 'cheque_expense' => float,
      'sales_total' => float, 'expense_total' => float, 'net' => float,
  ],
  'profit_loss' => float,             // col_totals['net']와 동일 (템플릿 문구 그대로 노출용)
]
```

### 3.2 소스 테이블 (기존, 변경 없음)

```
sales_pos_reconciliation (store_id, sale_date, shift, pos_no, deposit_cash, other_total, ...)
cd_saved_state           (store_id, save_date, state_json)   -- {sections:{korean,local,fixed,others}}
cer_saved_state          (store_id, save_date, state_json)   -- {sections:{korean,local,fixed,others}}, item에 returned 플래그
```

### 3.3 Database Schema

N/A — 신규 테이블 없음. 기존 3개 테이블을 읽기 전용으로 재집계한다.

---

## 4. API Specification

N/A — 이 프로젝트는 서버 렌더링 PHP 페이지이며 별도 REST API 레이어가 없다. `sales_expenses_report.php`가 GET 파라미터(`store_id`, `year`, `month`)를 받아 즉시 HTML을 렌더링하고, `export_sales_expenses_report.php`가 동일 파라미터로 Excel(XML) 응답을 스트리밍한다.

| 파일 (엔드포인트 역할) | 파라미터 | 응답 |
|---|---|---|
| `main_office/sales_expenses_report.php` | `store_id`, `year`, `month` (GET) | HTML 그리드 |
| `main_office/export_sales_expenses_report.php` | `store_id`, `year`, `month` (GET) | SpreadsheetML(.xls) 다운로드 |

---

## 5. UI/UX Design

### 5.1 Screen Layout

```
┌──────────────────────────────────────────────────────────────┐
│ mo-topbar (기존 공통 헤더)                                     │
├──────────────────────────────────────────────────────────────┤
│ mo-nav: By Store | SALES REPORT | Expense Report |            │
│         Cash Disbursement | Cheque Expense | Daily Report |   │
│         ▶ Sales & Expenses Report (신규, active 강조)          │
├──────────────────────────────────────────────────────────────┤
│ [점포 선택 ▾]  [◀ 이전달] August 2026 [다음달 ▶]  [Excel 내보내기] │
├──────────────────────────────────────────────────────────────┤
│  DATE │ SALES CASH │ SALES CREDITS │ EXP CASH │ EXP CHEQUE │ TOTAL │ NET │
│   1   │            │               │          │            │       │     │
│  ...  │                                                                  │
│  31   │                                                                  │
├──────────────────────────────────────────────────────────────┤
│  TOTAL │  Σcash  │  Σcredits │  Σcash  │  Σcheque │  Σ  │  Σ  │
├──────────────────────────────────────────────────────────────┤
│  PROFIT/LOSS:  ₱ {profit_loss}                                 │
└──────────────────────────────────────────────────────────────┘
```

> 참고 이미지의 PARTICULAR 열과 정밀 A4 서식(오렌지 헤더 등)은 Plan §3.2 YAGNI에 따라 v1에서 제외 — 기존 main_office 리포트(`sales_report.php`)와 동일한 일반 웹 테이블 스타일 사용.

### 5.2 User Flow

```
main_office 로그인 → mo-nav "Sales & Expenses Report" 클릭
  → (store_id 없으면 첫 점포로 기본 선택) → 이번 달 그리드 표시
  → 점포 변경 / 월 이동 → 그리드 갱신 (GET 파라미터 기반 페이지 재로드)
  → "Excel 내보내기" 클릭 → 동일 데이터 .xls 다운로드
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| 점포 선택 드롭다운 | `sales_expenses_report.php` | `store_id` GET 파라미터로 페이지 재요청 (기존 `sales_report.php`와 동일 패턴) |
| 월 이동 네비게이션 | `sales_expenses_report.php` | prev/next 링크 (`year`, `month` 계산은 기존 리포트들과 동일한 `mktime` 패턴 재사용) |
| 일자별 그리드 테이블 | `sales_expenses_report.php` | `get_monthly_sales_expenses_report()` 결과 렌더링 |
| Excel 내보내기 버튼 | `sales_expenses_report.php` (링크) → `export_sales_expenses_report.php` | 동일 파라미터로 새 탭/다운로드 |
| `get_monthly_sales_expenses_report()` | `office/lib/sales_expenses_report_helper.php` | 3-쿼리 배치 집계 (Application 로직) |

### 5.4 Page UI Checklist

#### Sales & Expenses Report 페이지 (`sales_expenses_report.php`)

- [ ] Dropdown: 점포 선택 (물류센터/킴스몰 창고 제외, 기존 점포 목록과 동일 정렬)
- [ ] Nav: 이전달 `◀` / 다음달 `▶` 링크, 현재 월 라벨(`F Y` 포맷)
- [ ] Button: "Excel 내보내기" (새 탭으로 `export_sales_expenses_report.php` 오픈)
- [ ] Table Header: `DATE`, `SALES CASH`, `SALES CREDITS`, `EXPENSES CASH`, `EXPENSES CHEQUE`, `TOTAL`, `NET`
- [ ] Table Rows: 1~말일, 값이 0이면 빈 칸(대시) 처리 (기존 `sales_report.php`의 `fmtC` 패턴과 동일)
- [ ] Total Row: 열별 합계 (강조 스타일)
- [ ] Summary: `PROFIT/LOSS` 금액 표시 (음수면 빨간색, 기존 daily_report의 net 색상 컨벤션 재사용)
- [ ] mo-nav에 "Sales & Expenses Report" 메뉴 항목, 현재 페이지일 때 `active` 클래스
- [ ] `main_office/index.php` 점포 카드에 진입 버튼 1개 추가 (기존 5개 버튼과 동일 스타일)

---

## 6. Error Handling

### 6.1 Error Case Definition

| Case | Cause | Handling |
|------|-------|----------|
| 유효하지 않은 `store_id` (물류센터/창고 포함, 존재하지 않는 id) | 잘못된 GET 파라미터 | 첫 번째 유효 점포로 폴백 (기존 `sales_report.php`와 동일 로직) |
| `cd_saved_state`/`cer_saved_state` 테이블 미존재 (마이그레이션 전 환경) | 신규 설치 환경 | `SHOW TABLES LIKE` 체크 후 없으면 해당 열 전부 0으로 처리, 화면 하단에 안내 문구 (기존 `office_helper.php::get_saved_report_state()`가 이미 이 체크를 내장) |
| `state_json` 디코딩 실패 (손상 데이터) | 저장 데이터 이상 | `json_decode()` 실패 시 해당일 0 처리, `error_log`에 기록 (throw하지 않음 — 리포트 전체가 죽지 않도록) |
| 점포 목록이 비어있음 | stores 테이블 이상 | "등록된 점포가 없습니다" 안내 (기존 `main_office/index.php` 패턴과 동일) |

### 6.2 Error Response Format

REST API가 아니므로 HTML 내 인라인 안내 문구로 처리한다 (기존 main_office 리포트들의 `$mo_error` try/catch 패턴 재사용):

```php
try {
    // 조회 로직
} catch (Throwable $e) {
    $mo_error = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    error_log('main_office/sales_expenses_report.php error: ' . $mo_error);
}
// ... <div class="alert alert-danger"> 로 표시
```

---

## 7. Security Considerations

- [x] 인증/권한: `mo_require_admin()` (기존 `main_office/lib/auth.php`) 그대로 재사용 — main_office_admin 이상만 접근
- [x] SQL Injection 방지: 모든 쿼리 prepared statement (`bind_param`) 사용, 기존 컨벤션과 동일
- [x] XSS 방지: 출력 시 `htmlspecialchars()` 적용 (점포명, 날짜 라벨 등)
- [x] 입력 검증: `store_id`/`year`/`month`는 정수 캐스팅(`(int)`) 후 점포 목록/유효 범위와 대조
- N/A HTTPS 강제 / Rate Limiting — 기존 main_office 리포트들과 동일하게 별도 미적용 (내부 관리자 전용 도구, 기존 컨벤션 유지)

---

## 8. Test Plan

> 이 프로젝트는 자동화 테스트 프레임워크가 없는 PHP 레거시 구조이므로, L1/L2/L3를 "수동 검증 시나리오"로 정의한다. Do phase에서 실제 데이터로 하나씩 확인한다.

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: 문법/쿼리 검증 | 신규 PHP 파일 3개 | `php -l`, 코드 리뷰(N+1 여부) | Do |
| L2: 화면 동작 검증 | `sales_expenses_report.php` | 브라우저 수동 확인 | Do |
| L3: 데이터 정합성 시나리오 | 신규 리포트 vs 기존 3개 리포트 | 수동 교차 대조 | Do |

### 8.2 L1: 기본 검증

| # | 대상 | 검증 내용 | 기대 결과 |
|---|------|-----------|-----------|
| 1 | 3개 신규 파일 | `php -l` 문법 체크 | No syntax errors |
| 2 | `get_monthly_sales_expenses_report()` | 코드 내 루프 안에 `get_db_connection()`/쿼리 호출 없음 | 함수 전체에서 DB 쿼리 3회 이하 |

### 8.3 L2: 화면 동작 시나리오

| # | 페이지 | 액션 | 기대 결과 |
|---|------|--------|----------------|
| 1 | Sales & Expenses Report | 페이지 최초 로드 (store_id 없이 진입) | 첫 번째 점포, 이번 달 그리드가 §5.4 체크리스트 요소와 함께 표시됨 |
| 2 | 〃 | 점포 드롭다운 변경 | 해당 점포 데이터로 그리드 갱신 |
| 3 | 〃 | 이전달/다음달 클릭 | year/month 파라미터가 바뀌고 해당 월 데이터 표시 |
| 4 | 〃 | "Excel 내보내기" 클릭 | `.xls` 파일 다운로드, 화면과 동일한 숫자 포함 |

### 8.4 L3: 데이터 정합성 시나리오

| # | 시나리오 | 절차 | 성공 기준 |
|---|----------|------|-----------|
| 1 | 매출 교차검증 | 임의 점포/월 선택 → 신규 리포트의 SALES CASH+CREDITS 월 합계 vs 해당 월 `sales_report.php`에서 수동 합산한 현금+카드 합계 | 두 값이 일치 |
| 2 | 지출(현금) 교차검증 | 동일 점포/일자 → 신규 리포트 EXPENSES CASH vs `cash_disbursement.php`(해당일) GRAND TOTAL | 두 값이 일치 |
| 3 | 지출(수표) 교차검증 | 동일 점포/일자 → 신규 리포트 EXPENSES CHEQUE vs `cheque_expense_report.php`(해당일) TOTAL EXPENSES | 두 값이 일치 |
| 4 | 손익 계산 | 월 TOTAL 행 → PROFIT/LOSS = (SALES CASH+CREDITS 합계) − (EXPENSES CASH+CHEQUE 합계) | 수식대로 정확히 계산됨 |
| 5 | 빈 데이터 처리 | 데이터가 없는 미래 월 선택 (또는 신규 점포) | 에러 없이 전부 0/빈 칸으로 표시 |

### 8.5 Seed Data Requirements

| Entity | 최소 개수 | 필요 필드 |
|--------|:------:|---------------------|
| `sales_pos_reconciliation` | 해당 월 1일 이상 | `deposit_cash`, `other_total` 값 있는 행 |
| `cd_saved_state` | 해당 월 1일 이상 | `state_json`에 최소 1개 섹션 항목 |
| `cer_saved_state` | 해당 월 1일 이상 | `state_json`에 `returned=true` 항목 1개 포함(제외 로직 검증용) |

---

## 9. Clean Architecture

N/A — PHP 절차적 스크립트 구조(레이어드 아키텍처 미적용). 대신 이 프로젝트의 기존 컨벤션인 "페이지(뷰) / lib 헬퍼(집계 로직) / config(DB 연결)" 3단 구조를 따른다.

| 역할 | 대응 위치 |
|------|-----------|
| 뷰/컨트롤러 | `main_office/sales_expenses_report.php`, `main_office/export_sales_expenses_report.php` |
| 집계 로직 (SSOT) | `office/lib/sales_expenses_report_helper.php` |
| DB 연결 | `config/db_config.php` (`get_db_connection()`) |

---

## 10. Coding Convention Reference

### 10.1 Naming Conventions (이 프로젝트 기존 컨벤션)

| Target | Rule | Example |
|--------|------|---------|
| PHP 함수 | snake_case | `get_monthly_sales_expenses_report()` |
| 파일명 | snake_case.php | `sales_expenses_report.php` |
| 변수 | snake_case (지역), `$_mo_*` 프리픽스 (main_office 헤더 전역) | `$store_id`, `$_mo_carry_store` |
| DB 컬럼/테이블 | snake_case | `deposit_cash`, `cd_saved_state` |

### 10.2 이 기능의 컨벤션 적용

| Item | Convention Applied |
|------|-------------------|
| 파일 구조 | `main_office/{feature}.php` + `office/lib/{feature}_helper.php` (기존 `sales_report.php` + `sales_report_helper.php` 패턴 그대로) |
| 점포 목록 조회 | `WHERE name NOT IN ('CENTER (물류센터)', 'KIMS MALL WHEREHOUSE (킴스몰 창고)')` 동일 조건 재사용 |
| 에러 처리 | try/catch + `$mo_error` 변수 + `error_log()` (기존 5개 main_office 리포트와 동일) |
| Excel export | PhpSpreadsheet 아님 — `export_sales_report.php`와 동일하게 **SpreadsheetML(XML) 직접 생성** 방식 사용 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
main_office/
├── sales_expenses_report.php          (신규)
├── export_sales_expenses_report.php   (신규)
├── index.php                          (수정 — 점포 카드에 버튼 1개 추가)
└── partials/
    └── header.php                    (수정 — mo-nav에 메뉴 항목 1개 추가)

office/
└── lib/
    └── sales_expenses_report_helper.php  (신규)
```

### 11.2 Implementation Order

1. [ ] `office/lib/sales_expenses_report_helper.php`: `get_monthly_sales_expenses_report()` 구현 (3-쿼리 배치)
2. [ ] `main_office/sales_expenses_report.php`: UI 구현 (`sales_report.php` 뼈대 복사 후 헬퍼 교체)
3. [ ] `main_office/export_sales_expenses_report.php`: `export_sales_report.php` 뼈대 복사 후 헬퍼/컬럼 교체
4. [ ] `main_office/partials/header.php`, `main_office/index.php`: 메뉴 진입점 2곳 추가
5. [ ] §8.4 데이터 정합성 시나리오로 수동 교차 검증

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|:---:|:---:|
| 집계 헬퍼 | `module-1` | `sales_expenses_report_helper.php` (3-쿼리 배치 집계 함수) | 10-15 |
| 화면 + 메뉴 | `module-2` | `sales_expenses_report.php` + nav/카드 진입점 추가 | 10-15 |
| Excel export | `module-3` | `export_sales_expenses_report.php` | 5-10 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 (완료됨) | - |
| Session 2 | Do | `--scope module-1,module-2` | 20-30 |
| Session 3 | Do | `--scope module-3` + 검증 | 15-20 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-27 | Initial draft | whdans007 |
