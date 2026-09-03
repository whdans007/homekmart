---
template: design
version: 1.3
description: PDCA Design phase document template with Context Anchor, Session Guide, and Clean Architecture support
variables:
  - feature: daily-report
  - date: 2026-07-23
  - author: whdans007
  - project: HOME K MART (min)
  - version: 1.0.0
---

# daily-report Design Document

> **Summary**: office/ Report 섹션에 "POSCO BRANCH DAILY REPORT" 참고 서식을 그대로 재현하는 일일 통합 리포트를 추가하고, 공용 집계 헬퍼로 화면/엑셀/인쇄 4개 진입점의 숫자를 항상 일치시킨다.
>
> **Project**: HOME K MART (min)
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-07-23
> **Status**: Draft
> **Planning Doc**: [daily-report.plan.md](../../01-plan/features/daily-report.plan.md)

> **Note**: 본 프로젝트는 Next.js/TS가 아닌 PHP(MySQLi/PDO) procedural 스택이므로, 아래 섹션은 원본 템플릿의 구조를 유지하되 내용은 PHP/MySQL 관례로 대체했다 (예: TypeScript interface → PHP 함수 시그니처, REST API → 내부 AJAX 엔드포인트, MongoDB → MySQL DDL).

---

## Context Anchor

> Plan 문서(`daily-report.plan.md`)에 Context Anchor 표가 없어(Plan Plus 템플릿은 해당 섹션 미포함) 이 단계는 생략한다. 대신 Plan의 Executive Summary/Risks를 §1, §7에서 참조한다.

---

## 1. Overview

### 1.1 Design Goals

- 참고 이미지(`1. reference/daily report.png`)의 셀 구성·병합·색상·카테고리 명칭을 변경 없이 그대로 재현한다.
- 화면(index.php) / 일일 엑셀 / 월별 엑셀 / 인쇄 뷰 4개 진입점이 동일한 숫자를 보여주도록 집계 로직을 단일 진실 공급원(SSOT)으로 둔다.
- 기존 5개 모듈(POS 정산, 매입, 외상, Delivery K/Whole Sale, expense_report)의 데이터를 읽기 전용으로만 사용하고 원본 테이블/화면은 변경하지 않는다.
- 기타지출만 신규 매핑 테이블 1개로 이 리포트 전용 12개 카테고리 분류를 지원한다.

### 1.2 Design Principles

- **SSOT 집계**: 모든 섹션 집계 쿼리는 `office/lib/daily_report_helper.php`의 함수 1곳에만 존재한다.
- **읽기 전용 우선**: 기타지출 카테고리 배치를 제외한 모든 데이터는 원본 모듈에서 읽기만 하고 쓰지 않는다.
- **기존 관례 준수**: `get_office_store_id()`, `require_office_permission()`, prepared statement, store_id 스코프 등 기존 `office/` 모듈 패턴을 그대로 따른다.
- **서식 불변**: 참고 이미지의 레이아웃/문구/카테고리 순서를 절대 변경하지 않는다.

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 4개 진입점에 집계 SQL 직접 중복 작성 | query/format/render 완전 계층 분리 | 집계 함수만 공용 헬퍼로 분리 |
| **New Files** | 6 | 9 | 7 |
| **Modified Files** | 1 | 1 | 1 |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Low (SQL 4곳 중복) | High | High |
| **Effort** | Low | High | Medium |
| **Risk** | 화면/엑셀 숫자 불일치 위험 | 낮음, 프로젝트 규모 대비 과함 | 낮음, 기존 관례와 일치 |
| **Recommendation** | | | **Default choice** |

**Selected**: Option C — **Rationale**: `office_helper.php`처럼 이미 이 프로젝트가 procedural 헬퍼 함수 패턴을 쓰고 있어, 완전한 계층 분리(Option B)는 과잉이고, 중복 SQL(Option A)은 화면·엑셀 간 숫자 불일치 위험이 크다. 사용자 확인 완료.

> 아래 상세 설계는 Option C 기준.

### 2.1 Component Diagram

```
┌──────────────┐   GET/POST    ┌───────────────────────────┐
│   Browser    │──────────────▶│ office/daily_report/*.php │
│ (office 사용자)│               │  index / ajax_* / export_* │
└──────────────┘               │  / print_daily_report.php │
                                └─────────────┬──────────────┘
                                              │ calls
                                              ▼
                                ┌───────────────────────────┐
                                │ office/lib/                │
                                │  daily_report_helper.php   │  ← SSOT 집계 함수 6개
                                └─────────────┬──────────────┘
                                              │ SELECT (읽기전용)
                    ┌─────────────────────────┼─────────────────────────┐
                    ▼                         ▼                         ▼
        sales_pos_reconciliation   office_product_purchases   credit_transactions
        sales_pos_payment                                     credit_payments
        sales_pos_wholesale_pick                               sales_daily_items
                                              │
                                              ▼ (기타지출만 쓰기)
                                  er_saved_state (읽기)
                                  daily_report_expense_category (읽기/쓰기, 신규)
```

### 2.2 Data Flow

```
날짜/점포 선택 (index.php?date=&store_id=)
  → daily_report_helper.php의 6개 get_daily_*() 호출
  → 각 섹션 배열 반환 (금액은 항상 float, 표시 시점에만 number_format)
  → index.php가 참고 이미지와 동일한 HTML 테이블로 렌더링
  → (기타지출만) 사용자가 드래그 → ajax_save_categories.php → daily_report_expense_category upsert
  → export_daily_report.php / export_daily_report_monthly.php / print_daily_report.php는
    동일한 daily_report_helper.php 함수를 호출해 동일 숫자 보장
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `index.php` | `daily_report_helper.php`, `office/lib/office_helper.php` | 화면 렌더링 + 권한/점포 확인 |
| `export_daily_report.php` | `daily_report_helper.php`, PhpSpreadsheet (`vendor/`) | 일일 엑셀 생성 |
| `export_daily_report_monthly.php` | `daily_report_helper.php`, PhpSpreadsheet | 월별 워크북(날짜별 시트) 생성 |
| `print_daily_report.php` | `daily_report_helper.php` | 인쇄용 HTML (print_er.php 패턴 참고) |
| `ajax_save_categories.php` | `daily_report_helper.php` (검증용), MySQLi | 기타지출 카테고리 upsert |
| `daily_report_helper.php` | MySQLi/PDO (`get_db_connection()`) | 6개 섹션 집계 |

---

## 3. Data Model

### 3.1 신규 테이블 정의

```sql
CREATE TABLE IF NOT EXISTS daily_report_expense_category (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  store_id       INT NOT NULL,
  sale_date      DATE NOT NULL,
  source_item_id VARCHAR(20) NOT NULL,   -- er_saved_state item_id 원본 형식 그대로 (예: 'p_123','pc_45','e_9','r_7')
  category_key   ENUM(
                   'return','payroll','utilities','pldt_lpg','office_supply',
                   'produce','vehicle','discount5','koreanchamber5',
                   'maintenance','other','points'
                 ) NOT NULL,
  created_by     INT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_item (store_id, sale_date, source_item_id),
  INDEX idx_date (store_id, sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- 금액/내역/날짜는 저장하지 않는다 — 표시 시점에 `er_saved_state`의 `state_json`에서 `source_item_id`로 원본 항목(금액/내역)을 조회해 조인한다 (expense_report와 동일하게 중복 저장 방지).
- 재배치는 `INSERT ... ON DUPLICATE KEY UPDATE category_key=VALUES(category_key)`로 처리.
- 배치 해제는 `DELETE WHERE store_id=? AND sale_date=? AND source_item_id=?`.

### 3.2 참조하는 기존 테이블 (읽기 전용, 변경 없음)

| 테이블 | 용도 | 핵심 컬럼 |
|---|---|---|
| `sales_pos_reconciliation` | 포스매출 현금/크레딧 (shift×pos_no) | store_id, sale_date, shift, pos_no, cash_total, other_total |
| `sales_pos_wholesale_pick` | 포스매출 거래명세표 열 | store_id, sale_date, shift, pos_no, amount, source_type |
| `sales_pos_payment` | 크레딧 세부 (BDO/GCASH/MAYA/OTHERS 매핑 원천) | store_id, sale_date, method, description, amount |
| `office_product_purchases` | 매입 현금/체크 | store_id, supplier_name, payment_type, amount, payment_date, check_issued_date |
| `credit_transactions` | 외상판매 | store_id, customer_id→`credit_customers.name`, transaction_date, final_amount |
| `credit_payments` | 외상수금 | store_id, customer_id, payment_date, amount |
| `sales_daily_items` | 도매판매/Delivery K (레거시 수동입력) | store_id, sale_date, item_type('delivery_k','whole_sale'), description, amount |
| `wholesale_sales` | 도매판매 (Admin 빠른등록/Add Sale로 등록된 어드민 관리 도매판매. `sales_pos_wholesale_pick`에 이미 pick된 건은 제외해 중복집계 방지) | store_id, customer_id→`wholesale_customers.name`, sale_date, final_amount, status(≠'cancelled') |
| `er_saved_state` | 기타지출 소스 항목(이미 expense_report에서 배치된 CASH NOT SELLING + OTHER EXP CHECK/CASH 행. CASH SELLING/PAY THRU CHECK는 제외 — 매입은 office_product_purchases로 별도 자동집계) | store_id, save_date, state_json |

### 3.3 Entity Relationships

```
stores (1) ── (N) daily_report_expense_category
er_saved_state.state_json[].sections.not_selling[] / other_exp_check[] / other_exp_cash[]
   └── item_id ──(매칭)── daily_report_expense_category.source_item_id
```

---

## 4. API Specification (내부 AJAX — REST 아님)

### 4.1 엔드포인트 목록

| Method | Path | 설명 | 권한 |
|--------|------|------|------|
| GET | `office/daily_report/index.php?date=&store_id=` | 메인 뷰 (서버 렌더링) | office 권한 |
| GET | `office/daily_report/ajax_load_expense_items.php?date=` | 기타지출 소스 항목 + 현재 배치 상태 JSON | office 권한 |
| POST | `office/daily_report/ajax_save_categories.php` | 기타지출 항목→카테고리 배치 저장/삭제 | office 권한 |
| GET | `office/daily_report/export_daily_report.php?date=&store_id=` | 일일 엑셀 다운로드 (.xlsx) | office 권한 |
| GET | `office/daily_report/export_daily_report_monthly.php?year=&month=&store_id=` | 월별 워크북 다운로드 (.xlsx, 날짜별 시트) | office 권한 |
| GET | `office/daily_report/print_daily_report.php?date=&store_id=` | 인쇄용 HTML | office 권한 |

### 4.2 상세 스펙

#### `GET ajax_load_expense_items.php?date=YYYY-MM-DD`

**Response (200):**
```json
{
  "success": true,
  "items": [
    {"source_item_id": "p_123", "supplier": "NAMGWANGHO", "details": "반품", "amount": 3044.00, "category_key": null},
    {"source_item_id": "pc_45", "supplier": "WOLF FRANK GARCIA", "details": "MAINTENANCE", "amount": 228000.00, "category_key": "maintenance"}
  ],
  "unplaced_count": 1
}
```

#### `POST ajax_save_categories.php`

**Request:**
```json
{
  "date": "2026-07-22",
  "placements": [{"source_item_id": "p_123", "category_key": "return"}],
  "removals": ["pc_45"]
}
```

**Response (200):**
```json
{"success": true, "saved": 1, "removed": 1}
```

**Error Responses:**
- `400`: 날짜 형식 오류, 유효하지 않은 `category_key`
- `401`: office 권한 없음 (`require_office_permission()`이 자체 리다이렉트/차단)
- `403`: 다른 점포 데이터에 대한 쓰기 시도 (super_admin이 아닌 사용자가 `store_id` 위조)

---

## 5. UI/UX Design

### 5.1 Screen Layout (index.php — 참고 이미지 구조 그대로)

```
┌──────────────────────────────────────────────────────────────────────┐
│  Home K Mart  /  {점포명} DAILY REPORT           [◀ date ▶] [Excel]  │
│                                                    [Monthly][Print]   │
├──────────────────────────────────────────────────────────────────────┤
│ 날짜 | 매출(포스+메뉴얼 / 외상거래처 / 수수료코너) | 매입(현금/체크)     │
│      | 기타지출 | 거래처수금(현금) | 수익                              │
├───────────────────────┬───────────────┬───────────────────────────────┤
│ 포스매출 (표 6행:      │ 수수료 코너    │ 기타지출 (12개 고정 카테고리   │
│ 포스1/2 × 3교대,       │ (공란·서식만  │  드래그앤드롭 드롭존 12개 +    │
│ 현금/크레딧/거래명세표) │  유지)        │  좌측 미배치 소스 카드 목록)    │
├───────────────────────┴───────────────┴───────────────────────────────┤
│ 매입 (거래처별 현금/체크)   │ 크레딧(BDO/GCASH/MAYA/OTHERS) │ 외상수금  │
├──────────────────────────────┴────────────────────────────┴──────────┤
│ 외상 판매 (거래처/포스등록/거래명세서)  │  도매 판매(거래명세서: DK 등) │
└──────────────────────────────────────────────────────────────────────┘
```

### 5.2 User Flow

```
Report 메뉴 → Daily Report 진입 (오늘 날짜 기본)
  → 자동 집계 섹션 확인 (읽기 전용)
  → 좌측 미배치 기타지출 카드를 12개 카테고리 드롭존으로 드래그
  → (선택) 이전/다음 날짜 이동, super_admin은 점포 변경
  → Excel 버튼 → 일일 엑셀 다운로드 / Monthly 버튼 → 월별 워크북 다운로드 / Print 버튼 → 인쇄 뷰
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| 날짜 네비게이터 | `office/daily_report/index.php` 상단 | prev/next/date input (기존 expense_report 패턴 재사용) |
| 점포 선택 드롭다운 | `index.php` 상단 (super_admin만 렌더) | `?store_id=` 전환 |
| 포스매출 테이블 | `index.php` 섹션1 | `get_daily_pos_summary()` 결과 렌더 |
| 수수료 코너 플레이스홀더 | `index.php` 섹션2 | 서식 유지, 항상 공란 |
| 기타지출 드래그보드 | `index.php` 섹션3 + `daily-report.js` | Sortable.js (expense_report와 동일 CDN) 기반 12 드롭존 |
| 매입 테이블 | `index.php` 섹션4 | `get_daily_purchase_summary()` |
| 크레딧 세부 테이블 | `index.php` 섹션5 | `get_daily_credit_breakdown()` |
| 외상수금/외상판매/도매판매 테이블 | `index.php` 섹션6-8 | `get_daily_ar_summary()`, `get_daily_wholesale_summary()` |
| 미분류 경고 배지 | 기타지출 섹션 상단 | `unplaced_count > 0`일 때만 표시 |

### 5.4 Page UI Checklist

#### Daily Report 메인 화면 (`index.php`)

- [ ] 날짜 네비게이터: ◀/▶ 버튼 + `<input type="date">`, 오늘 이후 날짜 이동 비활성화
- [ ] 점포 선택 드롭다운 (super_admin 전용, 미선택 시 본인 점포)
- [ ] 버튼: Excel (일일 다운로드), Monthly Excel, Print
- [ ] 헤더 요약행: 날짜 / 매출(포스+메뉴얼, 외상거래처, 수수료코너) / 매입(현금, 체크) / 기타지출 / 거래처수금(현금) / 수익
- [ ] 포스매출 표: 6행(포스1/포스2 × 12AM-8AM/8AM-5PM/5PM-12AM) × 열(현금, 크레딧, 거래명세표, 합계) + 합계행
- [ ] 수수료 코너 표: 업체명/매출 2열, 데이터 없이 서식만, "수수료 코너 데이터는 별도 입력 기능이 아직 없습니다" 안내문
- [ ] 기타지출: 좌측 미배치 소스 카드 목록(드래그 가능) + 우측 12개 고정 카테고리 드롭존(반품/인건비/전기세·관리비·CDC·BIR/PLDT·LPG·방역/사무실비품·판매소품/농산축산수산키친/차량유지비/일반할인5%/한인회5%/MAINTENANCE/기타/포인트사용)
- [ ] 미분류 경고 배지: "미분류 N건" (N>0일 때만, 합계 미포함 안내)
- [ ] 매입 표: 거래처명 / 현금매입 / 체크매입 / 합계 + 합계행
- [ ] 크레딧(CARD, E-MONEY) 표: BDO / GCASH / MAYA / OTHERS 4행 + 합계행
- [ ] 외상수금 표: 거래처명 / 금액
- [ ] 외상 판매 표: 거래처명 / 포스등록 / 거래명세서
- [ ] 도매 판매(거래명세서) 표: 거래처명(예: DK DELIVERY) / 금액

---

## 6. Error Handling

### 6.1 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| 400 | Invalid date | `date`/`year`/`month` 파라미터 형식 오류 | 오늘 날짜로 폴백 (기존 expense_report 관례) |
| 400 | Invalid category | `category_key`가 12개 고정값 외 | ajax 응답에서 `success:false` + 목록 미갱신 |
| 401/redirect | 권한 없음 | `require_office_permission()` 실패 | 로그인 페이지로 리다이렉트 (기존 관례) |
| 403 | Store mismatch | super_admin이 아닌 사용자가 타 점포 `store_id` 접근 시도 | `get_office_store_id()`로 강제 override, 경고 로그 |
| 500 | DB error | 쿼리 실패 | `error_log()` 기록, 화면에 "일시적 오류" 안내 |

### 6.2 AJAX 에러 응답 포맷 (기존 expense_report ajax_*.php와 동일)

```json
{"success": false, "error": "Invalid date"}
```

---

## 7. Security Considerations

- [ ] 모든 쿼리 prepared statement (MySQLi `bind_param`)
- [ ] `require_office_permission()`으로 접근 제어 (`office/lib/office_helper.php` 기존 함수)
- [ ] store 스코프 강제: `store_id` 파라미터는 super_admin만 override 가능, 그 외는 `get_office_store_id()` 값으로 고정
- [ ] `category_key`는 화이트리스트(12개 ENUM) 검증 후 DB 반영
- [ ] 출력 시 `htmlspecialchars()` 적용 (기존 `esc()` 헬퍼 패턴, `print_er.php` 참고)
- [ ] Rate Limiting: 기존 프로젝트에 미적용, 이 기능도 별도 도입하지 않음 (범위 밖)

---

## 8. Test Plan

> 이 프로젝트는 자동화 테스트 프레임워크가 없고(CLAUDE.md: "No specific PHP linting/testing commands defined"), `php -l` 구문 검사와 수동 브라우저 검증이 표준이다. 아래는 원본 템플릿의 L1-L3 구조를 이 프로젝트의 실제 검증 방식(수동 QA + 구문 검사)으로 대체한 것이다.

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| 구문 검사 | 신규/수정 PHP 파일 전체 | `php -l` | Do |
| 데이터 검증 | 6개 집계 함수 vs 참고 이미지 실제 수치 | 수동 대조 (동일 날짜 실데이터) | Do/Check |
| UI 동작 검증 | §5.4 체크리스트 전 항목 | 브라우저 수동 확인 | Do/Check |
| 서식 검증 | 엑셀/인쇄 출력 vs 참고 이미지 | 육안 셀 단위 비교 | Do/Check |

### 8.2 데이터 검증 시나리오

| # | 대상 | 검증 방법 | 기대 결과 |
|---|------|-----------|----------|
| 1 | `get_daily_pos_summary()` | 실제 하루치 `sales_pos_reconciliation`/`sales_pos_wholesale_pick` 데이터로 호출 | 6행 현금/크레딧/거래명세표 합계가 화면 "합계"행과 일치 |
| 2 | `get_daily_credit_breakdown()` | 'BDO' 라벨 포함 항목 1건 이상 있는 날짜로 호출 | BDO 버킷에 정확히 집계, 나머지는 OTHERS |
| 3 | `get_daily_purchase_summary()` | `office_product_purchases`에 현금+체크 혼재 날짜 | 거래처별 현금/체크/합계 정확 |
| 4 | `get_daily_ar_summary()` | `credit_transactions`+`credit_payments` 혼재 날짜 | 외상판매/외상수금 분리 정확 |
| 5 | `get_daily_wholesale_summary()` | `sales_daily_items`에 delivery_k, whole_sale 혼재 + `wholesale_sales`에 Admin 빠른등록 건 포함 | 도매판매 표에 거래처명+금액 정확 (세 소스 합산, cancelled 제외) |
| 6 | 기타지출 배치 | 항목 드래그 → 새로고침 | 배치 상태 유지, 미분류 카운트 갱신 |

### 8.3 UI 동작 검증

| # | 화면 | 액션 | 기대 결과 |
|---|------|------|----------|
| 1 | index.php | 날짜 이동 | 전체 섹션 데이터 갱신, URL의 `date` 파라미터 변경 |
| 2 | index.php | 기타지출 카드 드래그 → 드롭존 | 카드가 이동, ajax 저장 후 재조회 시 유지 |
| 3 | index.php | 미분류 항목 있는 상태로 로드 | "미분류 N건" 배지 표시, 합계에서 제외 확인 |
| 4 | index.php | super_admin으로 타 점포 선택 | 해당 점포 데이터로 전환 |

### 8.4 서식 검증 (엑셀/인쇄)

| # | 시나리오 | 단계 | 성공 기준 |
|---|----------|------|----------|
| 1 | 일일 엑셀 다운로드 | index.php → Excel 버튼 | 참고 이미지와 셀 구성/병합/색상/숫자 일치 |
| 2 | 월별 워크북 다운로드 | Monthly Excel 버튼 | 시트 수 = 해당 월 일수, 각 시트가 일일 서식과 동일 |
| 3 | 인쇄 뷰 | Print 버튼 | `print_er.php`와 동일한 회사명 표시 규칙(`company_name` 우선) 적용 확인 |

### 8.5 Seed Data Requirements

| Entity | 최소 건수 | 필수 필드 |
|--------|:------------:|---------------------|
| `sales_pos_reconciliation` | 6행 (포스1/2 × 3교대) | cash_total, other_total |
| `sales_pos_payment` | 4건 (gcash 1, paymaya 1, description에 'BDO' 포함 1, 기타 1) | method, description, amount |
| `office_product_purchases` | 2건 (cash 1, check 1) | supplier_name, payment_type, amount |
| `credit_transactions` / `credit_payments` | 각 1건 | customer_id, final_amount / amount |
| `sales_daily_items` | 2건 (delivery_k 1, whole_sale 1) | item_type, description, amount |
| `er_saved_state` | 1건 (other_exp_check/cash 항목 포함) | state_json |

---

## 9. Clean Architecture (PHP Procedural 관례로 대체)

### 9.1 Layer Structure

| Layer | Responsibility | Location |
|-------|---------------|----------|
| **Presentation** | 화면 렌더링, 드래그드롭 JS | `office/daily_report/index.php`, `print_daily_report.php` |
| **Application** | 엑셀 생성, ajax 처리 | `office/daily_report/export_*.php`, `ajax_*.php` |
| **Domain/집계** | 6개 섹션 집계 함수 (SSOT) | `office/lib/daily_report_helper.php` |
| **Infrastructure** | DB 연결 | `config/db_config.php` (`get_db_connection()`) |

### 9.2 Dependency Rules

```
Presentation(index.php) ──▶ Application(export/ajax) ──▶ Domain(daily_report_helper.php)
                                                              │
                                                              ▼
                                                    Infrastructure(MySQLi/PDO)

규칙: index.php/export/print/ajax는 SQL을 직접 작성하지 않고
      반드시 daily_report_helper.php의 함수를 통해서만 데이터에 접근한다.
```

### 9.3 File Import Rules

| From | Can Import | Cannot Import |
|------|-----------|---------------|
| `index.php`/`export_*.php`/`print_*.php`/`ajax_*.php` | `daily_report_helper.php`, `office/lib/office_helper.php` | 원본 모듈 테이블에 직접 SQL 작성 금지 |
| `daily_report_helper.php` | `config/db_config.php` | Presentation 레이어 함수 호출 금지 |

### 9.4 This Feature's Layer Assignment

| Component | Layer | Location |
|-----------|-------|----------|
| `get_daily_pos_summary()` 외 6개 함수 | Domain | `office/lib/daily_report_helper.php` |
| `index.php` | Presentation | `office/daily_report/index.php` |
| `export_daily_report.php`, `export_daily_report_monthly.php` | Application | `office/daily_report/` |
| `ajax_load_expense_items.php`, `ajax_save_categories.php` | Application | `office/daily_report/` |

---

## 10. Coding Convention Reference

### 10.1 Naming Conventions (기존 프로젝트 관례)

| Target | Rule | Example |
|--------|------|---------|
| PHP 함수 | snake_case, `get_daily_*` 접두사 | `get_daily_pos_summary()` |
| 테이블/컬럼 | snake_case | `daily_report_expense_category`, `source_item_id` |
| 파일 | snake_case.php | `export_daily_report.php`, `ajax_save_categories.php` |
| 카테고리 키(ENUM) | snake_case | `pldt_lpg`, `koreanchamber5` |

### 10.2 파일 헤더 관례

```php
<?php
$page_title      = 'Daily Report';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id(); // super_admin이 ?store_id= 지정 시 해당 값으로 override
$date     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
```

### 10.3 Environment/설정

| 항목 | 규칙 |
|--------|---------|
| DB 접속 | `config/db_config.php`의 `get_db_connection()` 사용 |
| 문자셋 | 전 쿼리 `utf8mb4` |
| PhpSpreadsheet | `vendor/` 기존 Composer 의존성 재사용 (신규 설치 불필요) |

### 10.4 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| 집계 함수 시그니처 | `function get_daily_XXX(mysqli $conn, int $store_id, string $date): array` |
| 에러 응답 | `{"success": false, "error": "..."}` (기존 expense_report ajax_*.php와 동일) |
| 날짜 검증 | `preg_match('/^\d{4}-\d{2}-\d{2}$/', ...)` (기존 관례 그대로) |

---

## 11. Implementation Guide

### 11.1 File Structure

```
office/
├── daily_report/
│   ├── index.php
│   ├── ajax_load_expense_items.php
│   ├── ajax_save_categories.php
│   ├── export_daily_report.php
│   ├── export_daily_report_monthly.php
│   ├── print_daily_report.php
│   ├── daily-report.js            # 드래그드롭 클라이언트 로직
│   └── sql/
│       └── create_daily_report_expense_category.sql
├── lib/
│   └── daily_report_helper.php    # 신규 — 6개 집계 함수
└── partials/
    └── header.php                 # 수정 — Report 섹션에 nav 링크 추가
```

### 11.2 Implementation Order

1. [ ] `office/daily_report/sql/create_daily_report_expense_category.sql` 작성 및 테이블 생성
2. [ ] `office/lib/daily_report_helper.php`에 6개 집계 함수 구현 (`get_daily_pos_summary`, `get_daily_credit_breakdown`, `get_daily_purchase_summary`, `get_daily_ar_summary`, `get_daily_wholesale_summary`, `get_daily_other_expense_categories`)
3. [ ] `index.php` 읽기 전용 섹션(포스매출/수수료코너 플레이스홀더/매입/크레딧/외상/도매판매) 렌더링
4. [ ] 기타지출 드래그드롭: `ajax_load_expense_items.php`, `ajax_save_categories.php`, `daily-report.js`, index.php 섹션 연결
5. [ ] `export_daily_report.php` — PhpSpreadsheet로 일일 엑셀, 참고 이미지 셀 단위 대조
6. [ ] `print_daily_report.php` — 인쇄 뷰 (`print_er.php` 패턴 참고)
7. [ ] `export_daily_report_monthly.php` — 월별 워크북(날짜별 시트), 3단계 export 로직 재사용
8. [ ] `office/partials/header.php`의 Report 섹션에 "Daily Report" nav 링크 추가
9. [ ] super_admin 점포 선택 드롭다운 + `?store_id=` 처리

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| DB + 집계 헬퍼 | `module-1` | 신규 테이블 + `daily_report_helper.php` 6개 함수 | 15-20 |
| 메인 화면(읽기전용) | `module-2` | index.php 6개 자동집계 섹션 + nav 추가 | 20-25 |
| 기타지출 드래그드롭 | `module-3` | ajax 2개 + JS + index.php 섹션 연결 | 15-20 |
| 일일 엑셀 | `module-4` | export_daily_report.php (PhpSpreadsheet) | 20-25 |
| 인쇄 + 월별 엑셀 | `module-5` | print_daily_report.php + export_daily_report_monthly.php | 15-20 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 (완료) | - |
| Session 2 | Do | `--scope module-1,module-2` | 35-45 |
| Session 3 | Do | `--scope module-3` | 15-20 |
| Session 4 | Do | `--scope module-4,module-5` | 35-45 |
| Session 5 | Check + Report | 전체 | 30-40 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-23 | Initial draft (Option C selected) | whdans007 |
