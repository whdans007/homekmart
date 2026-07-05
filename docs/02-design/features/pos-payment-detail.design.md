# Design: POS 결제수단 상세 입력 (pos-payment-detail)

> Feature: `pos-payment-detail`
> Phase: Design
> Created: 2026-06-24
> Plan: `docs/01-plan/features/pos-payment-detail.plan.md`
> Design Source: Claude Design — **POS Shift Entry v10**(입력 모달), **Daybook Report v2**(인쇄 보고서)
> Selected Architecture: **Option C — 실용균형 (모달 기반)**

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 결제수단별·현금 권종별 매출 내역이 필요해 정산 및 Daybook 인쇄 보고서를 작성하기 위함 |
| **WHO** | 매장 office 사용자(점장/캐셔)가 일일 POS 마감 시 입력 |
| **RISK** | 기존 `sales_daily` 단일 컬럼과 상세 합계의 불일치, 기존 월간/일일 보고서 회귀 |
| **SUCCESS** | 6개 칸 각각 모달로 결제수단·권종 입력/저장/재조회 가능, 칸 합계=상세 합계 일치, 준비금 ₱100 우선 배분, Daybook 인쇄, 기존 보고서 무회귀 |
| **SCOPE** | daily_entry.php 그리드 셀→버튼+모달, 신규 ajax 핸들러, 신규 테이블+마이그레이션, Daybook 인쇄 파일. 월간 보고서 신규 분석 컬럼은 차기 |

---

## 1. Overview

### 1.1 목표
`daily_entry.php`의 6개 셀(GY/Morning/Mid × POS1/POS2)을 **버튼**으로 바꾸고, 클릭 시 **POS Shift Entry 모달**을 열어 다음을 입력한다.

1. **Cash** — 권종별 매수(1000/500/100/50/20/10/5/1) → 현금총액 자동합계
2. **Other payments** — Credit Card / Debit Card / Gcash / PayMaya / PhQR (각 항목 확장형: 내용+금액 라인 리스트)
3. **Whole Sale** — admin이 입력한 그날치 내역(`wholesale_sales`, `sales_daily_items` delivery_k)을 **선택**
4. **Expenses** — 지출(DETAIL/PRICE) 입력
5. **Reconciliation** — 준비금(₱10,000, ₱100 우선) / 입금현금 / 마감 기대치 / 과부족(+/−) 서버 재계산

모달 저장 시 셀 Total이 그리드에 표시되고, `sales_daily.{shift}_pos{n}`에 동시 갱신된다. 별도 **Daybook 인쇄 보고서**(Report v2 양식)를 셀 단위로 출력한다.

### 1.2 선택 아키텍처 — Option C (모달 기반 실용균형)

| 항목 | 결정 |
|------|------|
| 진입 | 기존 `daily_entry.php` 그리드 유지, 각 셀을 `입력하기` 버튼화. 저장된 셀은 Total·정산 요약 배지 표시 |
| 입력 | **단일 모달 1개 재사용** + 현재 셀 컨텍스트(`shift`, `pos_no`) 동적 바인딩 |
| 데이터 | 6칸 상세를 페이지 로드 시 **전체 프리로드**(왕복 1회) 후 JS 주입 → 모달 오픈 시 즉시 표시 |
| 저장 | 셀 단위 **delete-then-insert**(현금 카운트/결제 라인/지출) + 정산 1행 upsert + `sales_daily` 칸 동시 갱신 |
| 계산 | **서버 권위적 재계산** — 클라 값 불신. 권종 qty로부터 현금총액/준비금/입금/과부족 산출 |
| 로직 공유 | 권종·배분·집계 로직을 `pos_recon_helper.php`로 분리(입력 저장 + Daybook 인쇄 양쪽 사용) |
| 보고서 | 별도 인쇄 파일 `print_daybook.php`(셀 컨텍스트 GET) |

**선택 이유:** 기존 그리드/월간 보고서가 소비하는 `sales_daily` 6컬럼을 그대로 유지(무회귀)하면서, 상세는 신규 테이블에 분리. 모달 단일 재사용으로 UI 중복을 피하고, 공유 헬퍼로 입력과 인쇄의 계산 일관성을 보장한다. A안(한 파일 집중)은 결합도가 높고, B안(완전 모듈 분리)은 이 규모에 과설계.

---

## 2. Data Model (신규)

### 2.1 권종 집합 / 우선순위 (확정)
- **권종 집합**: `1000 / 500 / 100 / 50 / 20 / 10 / 5 / 1` (₱200·₱0.25 미사용 — Daybook 레퍼런스 기준)
- **준비금 충당 우선순위**: `[100, 50, 20, 10, 5, 1, 500, 1000]`
  - ₱100을 **최우선** 보존(거스름돈용) → 입금(Deposit)에 ₱100이 가급적 포함되지 않음
  - 소액권을 먼저 준비금에 채우고, 부족 시 ₱500·₱1000으로 보충
  - 목표 준비금 **₱10,000**, 부족 시 경고 플래그(`shortage`)

### 2.2 테이블 DDL — `office/sales/sql/create_pos_details.sql`

```sql
-- ① 현금 권종 카운트 (셀 단위, 권종 1행)
CREATE TABLE IF NOT EXISTS sales_pos_cash_count (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  store_id     INT NOT NULL,
  sale_date    DATE NOT NULL,
  shift        ENUM('gy','morning','mid') NOT NULL,
  pos_no       TINYINT NOT NULL,
  denomination DECIMAL(7,2) NOT NULL,        -- 1000 ... 1
  qty          INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ② 기타결제 라인 (Credit/Debit Card, Gcash, PayMaya, PhQR + 수기) — 셀 단위 다중 행
CREATE TABLE IF NOT EXISTS sales_pos_payment (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  store_id    INT NOT NULL,
  sale_date   DATE NOT NULL,
  shift       ENUM('gy','morning','mid') NOT NULL,
  pos_no      TINYINT NOT NULL,
  method      VARCHAR(30) NOT NULL,          -- 'credit_card','debit_card','gcash','paymaya','phqr', 또는 수기 라벨
  description VARCHAR(255) NULL DEFAULT '',
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ③ 지출 (Expenses, DETAIL/PRICE) — 셀 단위 다중 행
CREATE TABLE IF NOT EXISTS sales_pos_expense (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  store_id    INT NOT NULL,
  sale_date   DATE NOT NULL,
  shift       ENUM('gy','morning','mid') NOT NULL,
  pos_no      TINYINT NOT NULL,
  detail      VARCHAR(255) NOT NULL DEFAULT '',
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ④ Whole Sale 선택 (admin 입력분 참조 — 직접 입력 아님)
CREATE TABLE IF NOT EXISTS sales_pos_wholesale_pick (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  store_id     INT NOT NULL,
  sale_date    DATE NOT NULL,
  shift        ENUM('gy','morning','mid') NOT NULL,
  pos_no       TINYINT NOT NULL,
  source_type  ENUM('wholesale','delivery_k','credit') NOT NULL,  -- REMARK 구분
  source_id    INT NOT NULL,                 -- wholesale_sales.id 또는 sales_daily_items.id
  client       VARCHAR(255) NULL DEFAULT '',
  remark       VARCHAR(255) NULL DEFAULT '',
  amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ⑤ 셀 정산 요약 (1셀 1행, upsert)
CREATE TABLE IF NOT EXISTS sales_pos_reconciliation (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  store_id       INT NOT NULL,
  sale_date      DATE NOT NULL,
  shift          ENUM('gy','morning','mid') NOT NULL,
  pos_no         TINYINT NOT NULL,
  cash_total     DECIMAL(12,2) NOT NULL DEFAULT 0,   -- 권종 합계
  other_total    DECIMAL(12,2) NOT NULL DEFAULT 0,   -- 기타결제 합계
  wholesale_total DECIMAL(12,2) NOT NULL DEFAULT 0,  -- Whole Sale 선택 합계
  expense_total  DECIMAL(12,2) NOT NULL DEFAULT 0,   -- 지출 합계
  starting_money DECIMAL(12,2) NOT NULL DEFAULT 0,   -- ₱100 우선 배분 (목표 10,000)
  deposit_cash   DECIMAL(12,2) NOT NULL DEFAULT 0,   -- cash_total - starting_money
  expected_cash  DECIMAL(12,2) NULL,                 -- 마감 현금 기대치
  over_short     DECIMAL(12,2) NULL,                 -- deposit_cash - expected_cash (+/-)
  shortage_flag  TINYINT(1) NOT NULL DEFAULT 0,      -- 준비금 부족 경고
  total_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,   -- 셀 Total (cash + other + wholesale)
  created_by     INT NULL,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> **호환성**: 기존 `sales_daily.{shift}_pos{n}` 6컬럼은 유지. 셀 `total_amount`(현금+기타+홀세일)를 동일 컬럼에 동시 upsert → 월간/일일 보고서 무회귀.
> **마이그레이션 PHP 동반**([[feedback_migration_php]]): `run_pos_details_migration.php`.

---

## 3. 준비금 배분 알고리즘 (서버 권위적)

`pos_recon_helper.php` 내 핵심 로직. 입력은 권종별 qty 맵, 출력은 정산 요약.

```
DENOMS  = [1000, 500, 100, 50, 20, 10, 5, 1]
PRIORITY = [100, 50, 20, 10, 5, 1, 500, 1000]   // 준비금 충당 순서 (₱100 최우선)
TARGET  = 10000

cash_total = Σ denom * qty[denom]
remaining  = TARGET
start_qty  = {}                 // 권종별 준비금 매수
for d in PRIORITY:
    take = min(qty[d], floor(remaining / d))
    start_qty[d] = take
    remaining   -= take * d
// 준비금은 ₱10,000 영속 float '가정' — POS×교대조별 최초 1회만 가정, 이후 물리적 이월 → 항상 고정
starting_money = TARGET                    // 항상 10,000 (가정/이월)
shortage_flag  = (remaining > 0)          // 현금이 10,000 미만 → float 미달 경고
deposit_qty    = { d: qty[d] - start_qty[d] }   // 입금 권종 = 전체 - 준비금 충당분
deposit_cash   = cash_total - TARGET       // = 센 현금 − 10,000
over_short     = (expected_cash != null) ? total_amount - expected_cash : null  // 셀 Total 기준
```

- 클라이언트 표시값은 참고용. 저장 시 서버가 qty로부터 전부 재계산하여 `sales_pos_reconciliation`에 기록.
- Daybook 인쇄도 동일 헬퍼로 `start_qty` / `deposit_qty`를 산출 → STARTING MONEY / TODAY TOTAL ACCOUNT 표 일치 보장.

---

## 4. 변경/신규 파일

| 파일 | 동작 | 비고 |
|------|------|------|
| `office/sales/sql/create_pos_details.sql` | 신규 | §2.2 5개 테이블 DDL |
| `office/sales/run_pos_details_migration.php` | 신규 | 마이그레이션 실행(기존 `run_items_migration.php` 패턴, 실행 후 삭제 안내) |
| `office/sales/lib/pos_recon_helper.php` | 신규 | 권종 상수, 배분 알고리즘, 셀 집계/재계산, 프리로드 SELECT |
| `office/sales/daily_entry.php` | 수정 | 셀→버튼화, 모달 마크업/JS, 6칸 상세 프리로드 주입, 셀 배지 |
| `office/sales/ajax_save_pos_cell.php` | 신규 | 셀 단위 저장(현금/결제/지출/홀세일 delete-insert + 정산 upsert + sales_daily 갱신) |
| `office/sales/ajax_wholesale_picklist.php` | 신규 | 그날치 Whole Sale·Delivery K 후보 조회(선택용) |
| `office/sales/print_daybook.php` | 신규 | Daybook Report v2 인쇄(셀 컨텍스트 GET, 공유 헬퍼 사용) |

기존 `ajax_save_sales.php`는 **보존**(그리드 직접 입력 호환). 모달 저장 경로(`ajax_save_pos_cell.php`)가 동일 셀 컬럼을 갱신하므로 충돌 없음.

---

## 5. API 계약

### 5.1 `POST ajax_save_pos_cell.php`
**Request** (form-data)
| 필드 | 타입 | 설명 |
|------|------|------|
| `sale_date` | date | YYYY-MM-DD |
| `shift` | enum | gy/morning/mid |
| `pos_no` | int | 1/2 |
| `cash[1000]`…`cash[1]` | int | 권종별 매수 |
| `pay[i][method]`,`pay[i][description]`,`pay[i][amount]` | array | 기타결제 라인 |
| `exp[i][detail]`,`exp[i][amount]` | array | 지출 라인 |
| `ws[i][source_type]`,`ws[i][source_id]`,`ws[i][amount]`,`ws[i][client]`,`ws[i][remark]` | array | 선택한 Whole Sale |
| `expected_cash` | float | 마감 현금 기대치 |

**처리**: 권한 확인 → 트랜잭션 시작 → 4개 상세 테이블 셀 delete-then-insert → 헬퍼로 정산 재계산 → `sales_pos_reconciliation` upsert → `sales_daily.{shift}_pos{n} = total_amount` upsert → commit.

**Response**
```json
{ "success": true, "cash_total": 0, "other_total": 0, "wholesale_total": 0,
  "expense_total": 0, "starting_money": 0, "deposit_cash": 0,
  "over_short": 0, "shortage": false, "total_amount": 0 }
```

### 5.2 `GET ajax_wholesale_picklist.php?date=YYYY-MM-DD`
그날치 후보 반환: `wholesale_sales`(final_amount, customer_name) + `sales_daily_items`(delivery_k). `[{source_type, source_id, client, remark, amount}]`.

### 5.3 `GET print_daybook.php?date=&shift=&pos_no=`
셀 정산/권종/지출/홀세일을 읽어 Report v2 양식 HTML 출력(@page A4, 인쇄 CSS).

---

## 6. UI 설계 (모달)

`daily_entry.php` 그리드 셀 = 버튼. 미저장 셀은 `입력하기`, 저장 셀은 `₱ Total + 過/不 배지 + 🖨` 표시.

모달 섹션 순서(Entry v10):
1. **헤더** — `POS{n} · {SHIFT} · {date}`
2. **① Cash** — 권종 표(권종 / 매수 / 금액) → 현금총액
3. **② Other payments** — Credit Card·Debit Card·Gcash·PayMaya·PhQR 확장형(내용+금액 라인 추가/삭제) + 수기 추가
4. **③ Whole Sale** — 후보 리스트 체크 선택(Delivery K·Whole Sale·Credit, REMARK 구분) → subtotal
5. **④ Expenses** — DETAIL/PRICE 라인 추가/삭제 → 합계
6. **⑤ Reconciliation** — 준비금(₱100 우선, 권종별 매수 표시) / 입금현금 / 마감 기대치 입력 / 과부족(+/−) / 부족 경고
7. **푸터** — 저장, Daybook 인쇄

> CSS([[project_logistics_css]]): 모달 핵심 레이아웃은 인라인 style 또는 기존 `s-card`/`s-table`/`pos-inp` 재사용(임의 Tailwind 유틸 누락 대비).

---

## 7. Daybook 인쇄 보고서 (Report v2)

| 영역 | 내용 |
|------|------|
| 타이틀 | `POS {n} {store_name} DAYBOOK` (store_name 동적) |
| 상단 | 3셀 SHIFT(MORNING/MIDSHIFT/GY) + DATE |
| 좌 | (STARTING MONEY) UNIT/PRICE 권종 1000~1 = 10,000 / (TODAY TOTAL ACCOUNT=Deposit) 권종 1000~1 / TOTAL AMOUNT + OVER·SHORT(+/−) |
| 우 | (EXPENSES) DETAIL/PRICE / (WHOLE SALES) CLIENT·REMARK·AMOUNT |
| 하단 | `.sigbox` 3열 서명 CASHIER / ADMIN / MANAGER |

- 기타결제(Card/Gcash/PayMaya/PhQR)는 **종이 보고서 미표시**(FR-19) — 정산 입력만.
- 권종 표는 §3 헬퍼의 `start_qty`/`deposit_qty`로 렌더.

---

## 8. Test Plan

| ID | 레벨 | 시나리오 | 기대 |
|----|------|----------|------|
| T1 | L1 | `ajax_save_pos_cell` 정상 저장 | success=true, total_amount=현금+기타+홀세일 |
| T2 | L1 | 권종 합계 9,000(부족) | shortage=true, starting_money=9000, deposit=0 |
| T3 | L1 | ₱100 우선 검증(qty 충분) | start_qty[100] 우선 소진, deposit에 ₱100 최소 |
| T4 | L2 | 셀 버튼→모달→입력→저장 | 그리드 배지 갱신, sales_daily 칸=total |
| T5 | L2 | 날짜 재방문 | 모달에 상세 복원 |
| T6 | L3 | 저장 후 Daybook 인쇄 | STARTING/DEPOSIT 권종표 = 정산값 일치 |
| T7 | 회귀 | 월간/일일 보고서 | 출력 무변화 |

---

## 9. 리스크 & 완화

| 리스크 | 완화 |
|--------|------|
| 칸 합계 ↔ 상세 합계 불일치 | 서버 재계산 후 `sales_daily` 기록(클라 불신) |
| 기존 보고서 회귀 | 6컬럼·기존 핸들러 보존, 모달은 동일 컬럼 갱신 |
| 부분 저장(트랜잭션 중단) | 셀 저장을 단일 트랜잭션으로 묶음(BEGIN/COMMIT/ROLLBACK) |
| Whole Sale 중복 선택 | (source_type, source_id) 셀 내 유니크 체크 |
| 멀티 store 격리 | 모든 쿼리 `store_id` 바인딩 |
| 권한 | `require_office_permission()` 동일 적용 |

---

## 10. Success Criteria (Plan 연계)

- [ ] SC-1: 6칸 버튼 클릭 시 모달이 해당 셀 컨텍스트로 열림
- [ ] SC-2: Cash 권종/기타결제/홀세일/지출 입력 시 Total·정산 자동 계산
- [ ] SC-3: 저장 후 배지 표시, 날짜 재방문 시 복원
- [ ] SC-4: `sales_daily` 칸 = 셀 total_amount 일치
- [ ] SC-5: 준비금 ₱100 우선 배분 + 부족 경고 동작
- [ ] SC-6: Daybook 인쇄가 Report v2 양식·정산값과 일치
- [ ] SC-7: 기존 monthly/daily 보고서 무회귀
- [ ] SC-8: 마이그레이션 스크립트로 5개 테이블 생성

---

## 11. Implementation Guide

### 11.1 구현 순서
1. **DB** — `create_pos_details.sql` + `run_pos_details_migration.php` 작성 → 실행 → 테이블 확인
2. **헬퍼** — `pos_recon_helper.php`(권종 상수, 배분 알고리즘, 셀 집계, 프리로드 SELECT) + 단위 검증
3. **저장 핸들러** — `ajax_save_pos_cell.php`(트랜잭션, delete-insert, 정산 upsert, sales_daily 갱신)
4. **후보 조회** — `ajax_wholesale_picklist.php`
5. **입력 UI** — `daily_entry.php` 셀 버튼화 + 모달 + 프리로드 주입 + 저장 연동
6. **인쇄** — `print_daybook.php`(Report v2 양식, 공유 헬퍼)
7. **회귀 확인** — 월간/일일 보고서 출력 비교

### 11.2 Module Map
| scope key | 모듈 | 파일 |
|-----------|------|------|
| `module-1` | DB·마이그레이션 | create_pos_details.sql, run_pos_details_migration.php |
| `module-2` | 공유 계산 헬퍼 | lib/pos_recon_helper.php |
| `module-3` | 저장/조회 핸들러 | ajax_save_pos_cell.php, ajax_wholesale_picklist.php |
| `module-4` | 입력 UI(모달) | daily_entry.php |
| `module-5` | Daybook 인쇄 | print_daybook.php |

### 11.3 Session Guide (권장 분할)
- **Session A** — module-1 + module-2 (스키마·계산 기반)
- **Session B** — module-3 + module-4 (저장·입력 UI)
- **Session C** — module-5 + 회귀 검증

`/pdca do pos-payment-detail --scope module-1,module-2` 형태로 단계 구현.

---

## 12. 다음 단계
`/pdca do pos-payment-detail --scope module-1,module-2` — DB·헬퍼부터 구현 시작.
