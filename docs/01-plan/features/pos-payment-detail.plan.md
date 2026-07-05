# Plan: POS 결제수단 상세 입력 (pos-payment-detail)

> Feature: `pos-payment-detail`
> Phase: Plan
> Created: 2026-06-23
> Target file: `office/sales/daily_entry.php` 외

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 현재 POS 일일 입력은 각 (교대조 × POS) 칸에 **단일 금액**만 입력 가능해, 결제수단(현금/카드/Gcash/PayMaya)별 내역을 기록할 수 없고 상세 보고서를 작성할 수 없다. |
| **Solution** | 각 POS 칸에 버튼을 추가해 **모달 입력 폼**을 열고, Cash/Card/Gcash/PayMaya 기본 항목 + 수기 추가 항목을 입력. 항목 합계(Total)가 칸 값으로 반영되며, 상세 내역은 새 테이블 `sales_pos_details`에 저장한다. |
| **Function UX Effect** | 칸 클릭 → 모달 → 결제수단별 입력 → 자동 합계 → 저장. 기존 그리드/월간 보고서는 그대로 동작(기존 컬럼 유지). |
| **Core Value** | 결제수단별 매출 분석이 가능해져 정산·보고 정확도가 올라가고, 수기 항목으로 매장별 특수 케이스도 수용한다. |

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 결제수단별 매출 내역이 필요해 상세 보고서를 작성하기 위함 |
| **WHO** | 매장 office 사용자(점장/직원)가 일일 POS 마감 시 입력 |
| **RISK** | 기존 `sales_daily` 단일 컬럼과 상세 테이블 합계의 **불일치**, 기존 월간 보고서 회귀 |
| **SUCCESS** | 6개 칸 각각 모달로 결제수단별 입력/저장/재조회 가능, 칸 합계=상세 합계 일치, 기존 보고서 무회귀 |
| **SCOPE** | daily_entry.php 그리드 + 모달 UI, 신규 ajax 핸들러, 신규 테이블 + 마이그레이션. 월간 보고서 신규 분석 컬럼은 차기 |

## 1. 배경 및 현황

- `daily_entry.php`: 교대조 3개(GY / Morning / Mid) × POS 1·2 = **6칸**, 각 칸은 `<input type=number>` 단일 금액.
- 저장: `ajax_save_sales.php` → `sales_daily` 테이블 (`gy_pos1`, `gy_pos2`, `morning_pos1`, `morning_pos2`, `mid_pos1`, `mid_pos2` ...), `UNIQUE(store_id, sale_date)` upsert.
- 기존 라인아이템 패턴 존재: `sales_daily_items`(item_type=delivery_k/whole_sale, description+amount) + `ajax_save_items.php` — **본 기능의 참고 모델**.
- 월간 보고서(`monthly_report.php`, `export_monthly.php`, `print_daily_combined.php`)는 `sales_daily`의 6개 POS 컬럼을 직접 소비 → **이 컬럼은 반드시 유지**.

## 2. 요구사항 (확정)

| # | 요구사항 | 확정 내용 |
|---|----------|-----------|
| FR-1 | 입력 단위 | 각 (교대조 × POS) 칸별 — 6칸 각각 버튼 → 모달 입력 폼 |
| FR-2 | 기본 결제수단 | **Cash, Card, Gcash, PayMaya** (4종 고정 행) |
| FR-3 | 수기 추가 | 라벨 + 금액 행을 임의 추가/삭제 가능 |
| FR-4 | Total 계산 | Total = 모든 항목(기본 4종 + 수기) **자동 합계** (입력 불가, 표시만) |
| FR-5 | 칸 반영 | 모달 저장 시 해당 칸의 Total이 그리드 셀 값으로 표시 |
| FR-6 | 저장 | 상세 내역을 신규 테이블 `sales_pos_details`에 저장, 칸 합계는 기존 `sales_daily` 컬럼에 동시 갱신 |
| FR-7 | 재조회 | 날짜 재방문 시 저장된 상세가 모달에 복원 |
| FR-8 | 호환성 | 기존 `sales_daily` POS 컬럼 유지 → 월간 보고서 무회귀 |
| FR-9 | 현금 권종 카운트 | 현금은 단일 금액이 아니라 **권종별 매수**(1000/500/200/100/50/20/10/5/1/0.25)로 입력 → 현금총액 자동합계 |
| FR-10 | 준비금 분리 | 준비금(Starting money) **₱10,000**을 **소액권 우선**(0.25→1→5→…)으로 구성. 부족 시 경고. = 다음 교대 잔돈 |
| FR-11 | 입금현금 | 입금할 현금 = 현금총액 − 준비금(10,000). 권종별 분해(준비금/입금) 표시 |
| FR-12 | 과부족(+/-) | 마감 시 **현금 매출 기대치** 입력 → (입금현금) − (기대치) = Over(+)/Short(−). 현금 기준 |
| FR-13 | 정산 단위 | 준비금/과부족 정산은 **POS×교대조** 칸 단위(폼과 동일) |
| FR-14 | Whole Sale 선택 | `2. Other payments` 다음에 Whole Sale 섹션. **Delivery K 포함**. admin이 실시간 입력한 내역(`wholesale_sales`, `sales_daily_items` item_type=delivery_k)을 그날치로 불러와 **선택**(직접 입력 아님). 선택분 subtotal → Total Amount에 합산 |
| FR-15 | 데이브룩 보고서 | 입력 데이터를 바탕으로 인쇄용 보고서(Daybook). 타이틀 HOME K MART SUNSET DAYBOOK / DATE·POS·SHIFT / Starting money·Expenses / Today Total Account(Deposit)·Other payments·Whole Sales / Total Amount·Over-Short / 서명(Cashier·Admin·Manager) |
| FR-16 | Expenses | 보고서 Expenses 영역(DETAIL/PRICE) → 입력폼에 지출 입력 섹션 추가, 합계 표시 |
| FR-17 | 보고서 양식 | 레퍼런스 `1. reference/daybook.png` 양식 그대로. 타이틀 **POS {n} {점포명} DAYBOOK**(점포명 동적, store에서). 좌: STARTING MONEY(권종 100~1), TODAY TOTAL ACCOUNT(권종 1000~1). 우: EXPENSES, WHOLE SALES. 하단 TOTAL AMOUNT + CASHIER/ADMIN/MANAGER 서명 |
| FR-18 | Credits 통합 | 별도 CREDITS 섹션 없음. **Whole Sale에 통합**(CLIENT/REMARK/AMOUNT, REMARK로 Whole Sale·Delivery K·Credit 구분) |
| FR-19 | 기타결제 보고서 제외 | Card/Debit/Gcash/PayMaya/PhQR는 입력폼에서 정산용으로 받되 **종이 보고서에는 표시 안 함**(현금 중심 양식) |

### Non-Goals (차기)
- 월간 보고서에 결제수단별 분석 컬럼/차트 추가 (별도 기능)
- delivery_k / whole_sale 항목의 결제수단 분해

## 3. 데이터 모델 (신규)

```sql
CREATE TABLE IF NOT EXISTS sales_pos_details (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  store_id    INT NOT NULL,
  sale_date   DATE NOT NULL,
  shift       ENUM('gy','morning','mid') NOT NULL,
  pos_no      TINYINT NOT NULL,          -- 1 or 2
  method      VARCHAR(50) NOT NULL,      -- 'cash','card','gcash','paymaya' 또는 수기 라벨
  is_custom   TINYINT(1) NOT NULL DEFAULT 0,
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- 저장 전략: 셀 단위 **delete-then-insert** (기존 `ajax_save_items.php` 패턴과 동일).
- `sales_daily.{shift}_pos{n}` = 해당 셀 Total Amount(현금총액+기타결제) 합계로 동시 upsert → 칸 합계 = 상세 합계 보장.

### 3.2 현금 권종 카운트 + 정산 (신규)

```sql
-- 셀(POS×교대조) 단위 권종별 현금 카운트
CREATE TABLE IF NOT EXISTS sales_pos_cash_count (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  store_id    INT NOT NULL,
  sale_date   DATE NOT NULL,
  shift       ENUM('gy','morning','mid') NOT NULL,
  pos_no      TINYINT NOT NULL,
  denomination DECIMAL(7,2) NOT NULL,   -- 1000.00 ... 0.25
  qty         INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 셀 단위 정산 요약 (1셀 1행)
CREATE TABLE IF NOT EXISTS sales_pos_reconciliation (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  store_id      INT NOT NULL,
  sale_date     DATE NOT NULL,
  shift         ENUM('gy','morning','mid') NOT NULL,
  pos_no        TINYINT NOT NULL,
  cash_total    DECIMAL(12,2) NOT NULL DEFAULT 0,  -- 권종 합계
  starting_money DECIMAL(12,2) NOT NULL DEFAULT 0, -- 소액권 우선 구성 (목표 10,000)
  deposit_cash  DECIMAL(12,2) NOT NULL DEFAULT 0,  -- cash_total - starting_money
  expected_cash DECIMAL(12,2) NULL,                -- 현금 매출 기대치
  over_short    DECIMAL(12,2) NULL,                -- deposit_cash - expected_cash (+/-)
  created_by    INT NULL,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- **권종 집합**: 1000 / 500 / 100 / 50 / 20 / 10 / 5 / 1 (₱0.25·₱200 미사용·제외 — 레퍼런스 daybook.png 기준).
- **우선순위**(₱200 제외 반영): `[100, 50, 20, 10, 5, 1, 500, 1000]`. 준비금은 소액권 우선이나, 부족 시 ₱500·₱1000도 사용 → STARTING MONEY 표는 전체 권종(1000~1) 행을 가짐.
- **준비금 알고리즘(서버에서 권위적으로 재계산)**: 충당 **우선순위** = `[100, 50, 20, 10, 5, 1, 200, 500, 1000]`. 우선순위 순으로 `take = min(qty[d], floor(remaining/d))`, `remaining -= take*d`. 최종 `starting_money = 10000 - remaining`. `remaining>0`이면 현금 부족 → 경고 플래그.
  - **₱100 최우선 보존**: ₱100은 거스름돈용으로 가장 먼저 준비금에 배정 → 입금(Deposit)에 가급적 포함되지 않음. 입금은 ₱1000/500/200 등 고액권 위주로 구성됨.
- 클라이언트 계산값은 신뢰하지 않고 서버가 권종 qty로부터 cash_total / starting_money / deposit / over_short를 모두 재계산.

## 4. 변경/신규 파일

| 파일 | 동작 | 비고 |
|------|------|------|
| `office/sales/sql/create_pos_details.sql` | 신규 | 위 테이블 DDL |
| `office/sales/run_pos_details_migration.php` | 신규 | 마이그레이션 실행 스크립트 (기존 run_items_migration.php 패턴) |
| `office/sales/daily_entry.php` | 수정 | 각 칸을 버튼화, 모달 마크업/JS 추가, 저장된 상세 프리로드 |
| `office/sales/ajax_save_pos_detail.php` | 신규 | 셀 단위 상세 저장 + sales_daily 칸 합계 동시 갱신 |
| `office/sales/ajax_get_pos_detail.php` | 신규(선택) | 셀 상세 조회 (또는 daily_entry에서 전체 프리로드) |

> 메모리 규칙([[feedback_migration_php]]): DB 마이그레이션 시 PHP 실행 스크립트 동반 — `run_pos_details_migration.php` 포함.

## 5. 설계 고려사항 / 결정 필요(Design 단계)

- 프리로드 방식: daily_entry.php에서 6칸 상세를 한 번에 SELECT해 JS에 주입(왕복 1회) vs 모달 오픈 시 ajax 조회. → **전체 프리로드 권장** (입력 화면 특성상 데이터 소량).
- 모달 1개 재사용 + 현재 셀 컨텍스트(shift, pos_no) 동적 바인딩.
- CSS: 메모리 규칙([[project_logistics_css]])에 따라 임의 Tailwind 유틸 누락 가능 → 모달 핵심 레이아웃은 인라인 style 또는 기존 `s-card`/`pos-inp` 클래스 재사용.

## 6. Success Criteria

- [ ] SC-1: 6칸 각각 버튼 클릭 시 모달이 해당 셀 컨텍스트로 열린다.
- [ ] SC-2: Cash/Card/Gcash/PayMaya + 수기 항목 입력 시 Total이 자동 합계된다.
- [ ] SC-3: 저장 후 그리드 칸에 Total이 표시되고, 새로고침/날짜 재방문 시 상세가 복원된다.
- [ ] SC-4: `sales_daily` 칸 값 = `sales_pos_details` 셀 합계가 일치한다.
- [ ] SC-5: 기존 `monthly_report.php` / `export_monthly.php` / `print_daily_combined.php` 출력이 변하지 않는다(무회귀).
- [ ] SC-6: 마이그레이션 스크립트 실행으로 테이블이 생성된다.

## 7. 리스크 & 완화

| 리스크 | 완화 |
|--------|------|
| 칸 합계 ↔ 상세 합계 불일치 | 저장 트랜잭션에서 합계를 서버가 재계산해 `sales_daily`에 기록 (클라 값 신뢰 안 함) |
| 기존 보고서 회귀 | 기존 컬럼/핸들러 유지, ajax_save_sales.php는 보존(병행 또는 통합 여부 Design 결정) |
| 멀티 store 격리 | 모든 쿼리에 `store_id` 바인딩 (기존 패턴 준수) |
| 권한 | `require_office_permission()` 동일 적용 |

## 8. 다음 단계

`/pdca design pos-payment-detail` — 3가지 아키텍처안(최소수정 / 클린 / 실용균형) 비교 후 모달·핸들러·저장 흐름 상세 설계.
