# Sales Report — Design Document

**Feature**: sales-report
**Plan**: docs/01-plan/features/sales-report.plan.md
**Architecture**: Option B — Clean Architecture (완전 분리)
**Date**: 2026-05-08

---

## Context Anchor

| | |
|--|--|
| **WHY** | 3교대 × POS 2대 일별 매출 수기 집계 → 월간 보고서 오류 발생 |
| **WHO** | office 시스템 사용자 (점포 관리자) |
| **RISK** | 들품대금/점지출 자동 연동 오류 시 보고서 수치 오류 |
| **SUCCESS** | 이미지 서식과 동일한 월간 보고서 자동 생성 + Excel 내보내기 |
| **SCOPE** | office/sales/ 신규 모듈 (5파일 + SQL) + office header 수정 |

---

## 1. 아키텍처: Option B — Clean Architecture

모든 기능을 완전히 독립된 파일로 분리. 각 파일의 책임이 명확하고 유지보수 용이.

```
office/sales/
├── daily_entry.php      일별 매출 입력 (날짜 선택 + 8개 필드)
├── monthly_report.php   월간 보고서 (읽기 전용 표 + Excel 버튼)
├── transfer.php         재고이동 목록 + 등록 폼
├── ajax_save_sales.php  매출 AJAX 저장 (upsert)
├── ajax_save_transfer.php  재고이동 AJAX 저장
├── export_monthly.php   Excel(SpreadsheetML) 내보내기
└── sql/
    └── create_sales.sql DB 마이그레이션
```

---

## 2. 데이터베이스

### 2.1 sales_daily

```sql
CREATE TABLE sales_daily (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  store_id     INT NOT NULL,
  sale_date    DATE NOT NULL,
  gy_pos1      DECIMAL(12,2) DEFAULT 0,
  gy_pos2      DECIMAL(12,2) DEFAULT 0,
  morning_pos1 DECIMAL(12,2) DEFAULT 0,
  morning_pos2 DECIMAL(12,2) DEFAULT 0,
  mid_pos1     DECIMAL(12,2) DEFAULT 0,
  mid_pos2     DECIMAL(12,2) DEFAULT 0,
  delivery_k   DECIMAL(12,2) DEFAULT 0,
  whole_sale   DECIMAL(12,2) DEFAULT 0,
  notes        TEXT NULL,
  created_by   INT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_store_date (store_id, sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2.2 sales_transfers

```sql
CREATE TABLE sales_transfers (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  store_id         INT NOT NULL,
  transfer_date    DATE NOT NULL,
  direction        ENUM('in','out') NOT NULL,
  other_store_id   INT NULL,
  other_store_name VARCHAR(255) NOT NULL,
  amount           DECIMAL(12,2) NOT NULL,
  notes            TEXT NULL,
  created_by       INT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, transfer_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 3. 계산식

```
매출합계  = gy_pos1+gy_pos2 + morning_pos1+morning_pos2
          + mid_pos1+mid_pos2 + delivery_k + whole_sale

들품대금  = SUM(office_product_purchases.amount)
           WHERE store_id=? AND YEAR(payment_date)=Y AND MONTH(payment_date)=M

점지출    = SUM(office_equipment_purchases.amount)
           WHERE store_id=? AND YEAR(payment_date)=Y AND MONTH(payment_date)=M

재고이동  = SUM(amount WHERE direction='in') - SUM(amount WHERE direction='out')
           FROM sales_transfers
           WHERE store_id=? AND YEAR=Y AND MONTH=M AND DAY=D  ← 일별

매출-지출 = 매출합계 - 들품대금 - 점지출
```

---

## 4. API / AJAX 명세

### 4.1 ajax_save_sales.php

```
POST ajax_save_sales.php
  sale_date   : YYYY-MM-DD
  gy_pos1     : float
  gy_pos2     : float
  morning_pos1: float
  morning_pos2: float
  mid_pos1    : float
  mid_pos2    : float
  delivery_k  : float
  whole_sale  : float

응답: {"success": true, "total": 152148.00}
SQL: INSERT ... ON DUPLICATE KEY UPDATE  (upsert)
```

### 4.2 ajax_save_transfer.php

```
POST ajax_save_transfer.php
  transfer_date    : YYYY-MM-DD
  direction        : 'in' | 'out'
  other_store_name : string
  amount           : float
  notes            : string (optional)

응답: {"success": true, "id": 5}
```

---

## 5. 화면 설계

### 5.1 daily_entry.php

```
[← 보고서]  Daily Sales Entry          [2026-05-08 ▼]

┌─────────────────────────────────────────────────┐
│  GY Shift (12:00AM – 8:00AM)                   │
│  POS 1: [______]   POS 2: [______]             │
├─────────────────────────────────────────────────┤
│  Morning Shift (8:00AM – 5:00PM)               │
│  POS 1: [______]   POS 2: [______]             │
├─────────────────────────────────────────────────┤
│  Mid Shift (5:00PM – 12:00AM)                  │
│  POS 1: [______]   POS 2: [______]             │
├─────────────────────────────────────────────────┤
│  Delivery K: [______]   Whole Sale: [______]   │
├─────────────────────────────────────────────────┤
│  Total Sales: ₱ 152,148.00                     │
├─────────────────────────────────────────────────┤
│                              [Save]             │
└─────────────────────────────────────────────────┘
```

- 날짜 선택 시 기존 데이터 AJAX 로드 (수정 모드)
- 저장 시 합계 즉시 표시
- 이미 저장된 날짜는 "Saved" 배지 표시

### 5.2 monthly_report.php

```
HOME K MART SUNSET SALES REPORT          [May 2026 ◀ ▶]
                                    [Excel] [Print] [+ Entry] [+ Transfer]

DATE    | POS1 GY | POS2 GY | POS1 MOR | POS2 MOR | POS1 MID | POS2 MID
       | DEL K | WHOLE | 매출합계(🔴) | 들품대금 | 점지출 | 재고이동 | 매출-지출
───────────────────────────────────────────────────────────────────────────
May 1   | 6,968 |        | 19,937 | 28,371 | 54,373 | 42,499 |
       |       |       | 152,148 | 37,351 | 25,629 | 160,052 | 70,884
...
───────────────────────────────────────────────────────────────────────────
[TOTAL] |   |   |   |   |   |   |   |   | 1,116,061🔴|352,785|408,404|459,349| 104,477
```

- 월 전체 31행 고정 (데이터 없는 날은 빈 행)
- 매출합계 열: 빨간 배경
- 합계 행: 맨 아래, 굵은 폰트 + 빨간 배경 강조
- 이미지와 동일한 컬럼 순서

### 5.3 transfer.php

```
[← 보고서]  Stock Transfer              [+ Add Transfer]

Filter: [May 2026 ▼]  [All ▼]

DATE       | DIRECTION | STORE      | AMOUNT      |
2026-05-01 | IN ↓      | Sunrise    | ₱160,052   | [Delete]
2026-05-01 | OUT ↑     | Main Store | ₱  5,000   | [Delete]
```

등록 모달:
```
Direction: (●) Received from  ( ) Sent to
Store: [____________________]
Date:  [2026-05-08]
Amount: [____________]
Notes:  [____________]
[Save]  [Cancel]
```

---

## 6. 파일별 상세 명세

### 6.1 daily_entry.php
- GET `?date=YYYY-MM-DD` → 기존 데이터 로드
- 저장: POST → `ajax_save_sales.php` → JSON 응답
- 합계 즉시 계산 (JS)
- `$css_base = '../../admin/'`, `$office_nav_base = '../'`

### 6.2 monthly_report.php
- GET `?year=Y&month=M` (기본: 이번 달)
- 월 1~말일 루프로 31행 생성
- 들품대금/점지출 GROUP BY DAY 서브쿼리
- 재고이동 IN-OUT 일별 계산
- Excel/Print 버튼 → export_monthly.php

### 6.3 transfer.php
- 목록 조회 + 월 필터
- 등록 모달 (Bootstrap Modal)
- 삭제: POST → ajax 또는 form submit

### 6.4 ajax_save_sales.php
- `INSERT ... ON DUPLICATE KEY UPDATE`
- 응답: `{success, total, date}`

### 6.5 ajax_save_transfer.php
- INSERT INTO sales_transfers
- 응답: `{success, id}`

### 6.6 export_monthly.php
- SpreadsheetML (이미지 서식과 동일한 컬럼)
- 빨간 배경 (매출합계 열), 굵은 합계 행
- 파일명: `SalesReport_2026_05.xls`

---

## 7. 보안

- `require_office_permission()` 모든 파일
- `store_id = get_office_store_id()` 쿼리 조건
- POST 값: `post_float()`, `post_str()`, `post_date()` 사용
- AJAX: JSON 응답, HTTP 405 for non-POST

---

## 8. 네비게이션 수정

`office/partials/header.php` 수정:
- `$is_sales = str_contains($uri, '/sales/')` 추가
- `Sales Report` 메뉴 링크 추가 (monthly_report.php)
- `$is_dash` 조건에 `!$is_sales` 추가

---

## 9. 구현 순서 (모듈 맵)

| 모듈 | 파일 | 우선순위 |
|------|------|----------|
| M1: DB | sql/create_sales.sql | 1 |
| M2: 일별 입력 | daily_entry.php + ajax_save_sales.php | 2 |
| M3: 재고이동 | transfer.php + ajax_save_transfer.php | 3 |
| M4: 월간 보고서 | monthly_report.php | 4 |
| M5: Excel 내보내기 | export_monthly.php | 5 |
| M6: 네비게이션 | partials/header.php 수정 | 6 |

### 세션 플랜
```
Session 1: M1 + M2 (DB + 일별 입력)
Session 2: M3 + M4 (재고이동 + 월간 보고서)
Session 3: M5 + M6 (Excel + 네비게이션)
```

---

## 10. 완료 기준

| # | 기준 | 구현 위치 |
|---|------|-----------|
| 1 | 일별 매출 입력 8개 필드 upsert | daily_entry.php |
| 2 | 재고이동 IN/OUT 등록 | transfer.php |
| 3 | 월간 보고서 이미지 서식과 동일 | monthly_report.php |
| 4 | 들품대금/점지출 자동 연동 | monthly_report.php SQL |
| 5 | 재고이동 열 일별 계산 | monthly_report.php SQL |
| 6 | 매출-지출 자동 계산 | monthly_report.php |
| 7 | 월 합계 행 맨 아래 강조 | monthly_report.php |
| 8 | Excel 내보내기 | export_monthly.php |
