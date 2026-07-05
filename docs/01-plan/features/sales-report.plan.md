# Sales Report — 월간 매출 보고서 시스템

## Executive Summary

| 관점 | 내용 |
|------|------|
| **문제** | 3교대 × POS 2대의 일별 매출을 수기로 집계하고 있어 월간 합산이 어렵고 오류가 발생함 |
| **솔루션** | 일별 매출 입력 → 자동 합산 → 이미지(HOME K MART SUNSET SALES REPORT) 서식의 월간 보고서 자동 생성 |
| **UX 효과** | 매일 숫자만 입력하면 월간 보고서가 즉시 생성. 기존 지출 데이터와 자동 연동으로 이중 입력 제거 |
| **핵심 가치** | 영업 데이터 → 지출 데이터 → 재고이동을 한 보고서에 통합. Excel 내보내기로 실무 활용 |

---

## 1. 기능 목적

- **핵심 문제**: 일별 매출 집계 → 월간 보고서 자동 생성 없음
- **대상 사용자**: office 시스템 사용자 (점포 관리자)
- **성공 기준**:
  1. 매일 6 POS + DELIVERY + WHOLESALE 입력 가능
  2. 재고이동(받은 것/보낸 것) 등록 가능 (점포 선택 + 금액)
  3. 월간 보고서가 이미지 서식과 동일하게 자동 생성
  4. 들품대금/점지출이 기존 office 지출 데이터에서 자동 연동
  5. Excel 내보내기 가능

---

## 2. 탐색한 대안

| 방식 | 결정 |
|------|------|
| **A: 일별 입력 + 월간 보고서** | ✅ 채택 |
| B: 엑셀 업로드 방식 | 실시간 확인 불가 |
| C: 풀 대시보드 | 과도한 복잡도 |

---

## 3. YAGNI 검토 (1차 버전 범위)

### 포함
- 일별 매출 입력 (GY/MORNING/MID × POS1,2 + DELIVERY K + WHOLE SALE)
- 재고이동 등록 (방향: 받음/보냄, 점포명, 금액)
- 월간 보고서 (이미지 서식: DATE, 6 POS, DELIVERY, WHOLESALE, 매출합계, 들품대금, 점지출, 재고이동, 매출-지출)
- Excel 내보내기 (SpreadsheetML)

### 제외 (Out of Scope)
- 트렌드 차트/분석
- 점포간 비교
- 모바일 앱

---

## 4. 기술 설계

### 4.1 데이터베이스

```sql
CREATE TABLE sales_daily (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  store_id    INT NOT NULL,
  sale_date   DATE NOT NULL,
  -- GY shift (12:00AM - 8:00AM)
  gy_pos1     DECIMAL(12,2) DEFAULT 0,
  gy_pos2     DECIMAL(12,2) DEFAULT 0,
  -- MORNING shift (8:00AM - 5:00PM)
  morning_pos1 DECIMAL(12,2) DEFAULT 0,
  morning_pos2 DECIMAL(12,2) DEFAULT 0,
  -- MID shift (5:00PM - 12:00AM)
  mid_pos1    DECIMAL(12,2) DEFAULT 0,
  mid_pos2    DECIMAL(12,2) DEFAULT 0,
  -- Additional channels
  delivery_k  DECIMAL(12,2) DEFAULT 0,
  whole_sale  DECIMAL(12,2) DEFAULT 0,
  notes       TEXT NULL,
  created_by  INT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_store_date (store_id, sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

### 4.2 월간 보고서 계산식

```
매출합계   = gy_pos1 + gy_pos2 + morning_pos1 + morning_pos2
           + mid_pos1 + mid_pos2 + delivery_k + whole_sale

들품대금   = SUM(office_product_purchases.amount)
           WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?

점지출     = SUM(office_equipment_purchases.amount)
           WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?

재고이동   = SUM(IN) - SUM(OUT) from sales_transfers
           (양수 = 순 입고, 음수 = 순 출고)

매출-지출  = 매출합계 - 들품대금 - 점지출
```

### 4.3 파일 구조

```
office/sales/
├── daily_entry.php      일별 매출 입력 (날짜 선택 + 8개 필드)
├── monthly_report.php   월간 보고서 (이미지 서식)
├── transfer.php         재고이동 목록 + 등록
├── ajax_save_sales.php  매출 AJAX 저장 (upsert)
├── export_monthly.php   Excel(SpreadsheetML) 내보내기
└── sql/
    └── create_sales.sql DB 마이그레이션
```

### 4.4 월간 보고서 서식 (이미지 기준)

```
[HOME K MART SUNSET SALES REPORT]

DATE | POS1 GY | POS2 GY | POS1 MOR | POS2 MOR | POS1 MID | POS2 MID
   | DELIVERY K | WHOLE SALE | 매출합계(빨간배경) | 들품대금(매입)
   | 점지출 | 재고이동 | 매출-지출

행: 월 1일 ~ 말일 (31행 고정)
합계: 맨 아래 행 (빨간 배경으로 강조)
```

---

## 5. 구현 순서

1. DB 마이그레이션 (`sql/create_sales.sql`)
2. 일별 매출 입력 (`daily_entry.php` + `ajax_save_sales.php`)
3. 재고이동 등록 (`transfer.php`)
4. 월간 보고서 (`monthly_report.php`)
5. Excel 내보내기 (`export_monthly.php`)
6. 네비게이션 (office header에 'Sales Report' 추가)

---

## 6. 완료 기준

- [ ] 일별 매출 입력 (8개 필드, upsert 방식)
- [ ] 재고이동 등록 (IN/OUT, 점포명, 금액)
- [ ] 월간 보고서 이미지 서식과 동일
- [ ] 들품대금/점지출 자동 연동 (office 지출 테이블)
- [ ] 재고이동 열 자동 계산
- [ ] 매출-지출 자동 계산
- [ ] 월 합계 행 맨 아래 표시
- [ ] Excel 내보내기
