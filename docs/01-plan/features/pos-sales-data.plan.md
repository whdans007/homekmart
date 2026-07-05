# POS Sales Data — 판매 데이터 업로드 및 조회

## Executive Summary

| 관점 | 내용 |
|------|------|
| **문제** | POS 시스템에서 생성되는 엑셀 판매 데이터를 별도로 관리하는 시스템이 없어, 데이터를 체계적으로 축적·조회할 수 없음 |
| **솔루션** | office/ 시스템에 POS 판매 데이터 업로드·조회 기능 추가. 날짜당 최대 2개 파일, 22개 컬럼 고정 스키마로 DB 저장 |
| **UX 효과** | 엑셀 파일을 드래그&드롭으로 업로드 → 파싱 결과 미리보기 → 저장 → 날짜별 목록에서 데이터 확인 |
| **핵심 가치** | POS 매출 데이터를 DB에 누적 저장하여 추후 분석·리포트 기능의 데이터 기반 확보 |

---

## Context Anchor

| | 내용 |
|--|------|
| **WHY** | POS 판매 데이터를 시스템 내에서 관리해야 추후 분석·리포트 기능을 만들 수 있음 |
| **WHO** | office 시스템 사용자 (점포 관리자) |
| **RISK** | 엑셀 파일 구조 변경 시 파싱 실패 가능 → 헤더 검증으로 방어 |
| **SUCCESS** | 업로드 → DB 저장 → 목록/상세 조회 정상 동작 |
| **SCOPE** | office/sales/ 디렉토리, DB 2개 테이블, 네비게이션 항목 1개 추가 |

---

## 1. 기능 목적

### 핵심 문제
POS 엑셀 파일(`04-26-1.xlsx` 형태)로 생성되는 판매 데이터를 office 시스템 DB에 저장하여 중앙 관리.

### 대상 사용자
office 시스템 관리자 (점포 담당자)

### 성공 기준
1. 엑셀 파일(.xlsx) 업로드 → 22개 컬럼 파싱 → DB 저장 성공
2. 날짜당 최대 2개 파일 슬롯 관리
3. 업로드 목록에서 날짜/파일명/건수 확인 가능
4. 상세 조회에서 저장된 데이터 테이블 표시

---

## 2. 엑셀 파일 구조

| 행 | 내용 |
|----|------|
| Row 1 | 시스템 제목 (Alphine Web - Point of Sales) → 무시 |
| Row 2 | 컬럼 헤더 (22개) |
| Row 3+ | 데이터 행 |

### 22개 컬럼 매핑

| Excel 컬럼 | DB 컬럼 | 타입 |
|-----------|---------|------|
| DATE | sale_date | VARCHAR(20) |
| TIME | sale_time | VARCHAR(20) |
| STORE ID | pos_store_id | VARCHAR(50) |
| SI NO. | si_no | VARCHAR(50) |
| ITEMCODE | item_code | VARCHAR(50) |
| ITEMNAME | item_name | VARCHAR(200) |
| SUPPLIER | supplier | VARCHAR(100) |
| DEPARTMENT | department | VARCHAR(100) |
| BOX | box | DECIMAL(10,2) |
| PCS | pcs | DECIMAL(10,2) |
| UNIT COST | unit_cost | DECIMAL(10,2) |
| TOTAL COST | total_cost | DECIMAL(10,2) |
| SELLING PRICE | selling_price | DECIMAL(10,2) |
| DISCOUNT | discount | DECIMAL(10,2) |
| TOTAL SALES | total_sales | DECIMAL(10,2) |
| GROSS PROFIT | gross_profit | DECIMAL(10,2) |
| SC DISCOUNT | sc_discount | DECIMAL(10,2) |
| PWD DISCOUNT | pwd_discount | DECIMAL(10,2) |
| LESS VAT | less_vat | DECIMAL(10,2) |
| NET SALES | net_sales | DECIMAL(10,2) |
| CASHIER | cashier | VARCHAR(100) |
| PAYMENT FORM | payment_form | VARCHAR(50) |

---

## 3. DB 설계

```sql
-- 업로드 메타 테이블
CREATE TABLE pos_sales_uploads (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED NOT NULL,
  upload_date  DATE NOT NULL            COMMENT '판매 데이터 날짜',
  file_slot    TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '슬롯 1 or 2',
  file_name    VARCHAR(255) NOT NULL,
  row_count    INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by  INT NULL,
  uploaded_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_slot (store_id, upload_date, file_slot),
  INDEX idx_date (store_id, upload_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 판매 데이터 테이블 (22개 컬럼 고정)
CREATE TABLE pos_sales_data (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  upload_id     INT UNSIGNED NOT NULL,
  row_no        SMALLINT UNSIGNED NOT NULL,
  sale_date     VARCHAR(20)    NULL,
  sale_time     VARCHAR(20)    NULL,
  pos_store_id  VARCHAR(50)    NULL,
  si_no         VARCHAR(50)    NULL,
  item_code     VARCHAR(50)    NULL,
  item_name     VARCHAR(200)   NULL,
  supplier      VARCHAR(100)   NULL,
  department    VARCHAR(100)   NULL,
  box           DECIMAL(10,2)  NULL,
  pcs           DECIMAL(10,2)  NULL,
  unit_cost     DECIMAL(10,2)  NULL,
  total_cost    DECIMAL(10,2)  NULL,
  selling_price DECIMAL(10,2)  NULL,
  discount      DECIMAL(10,2)  NULL,
  total_sales   DECIMAL(10,2)  NULL,
  gross_profit  DECIMAL(10,2)  NULL,
  sc_discount   DECIMAL(10,2)  NULL,
  pwd_discount  DECIMAL(10,2)  NULL,
  less_vat      DECIMAL(10,2)  NULL,
  net_sales     DECIMAL(10,2)  NULL,
  cashier       VARCHAR(100)   NULL,
  payment_form  VARCHAR(50)    NULL,
  INDEX idx_upload (upload_id),
  INDEX idx_item_code (item_code),
  INDEX idx_sale_date (sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 4. 파일 구조

```
office/pos_data/
├── index.php          # 업로드 목록 (날짜별)
├── upload.php         # 업로드 폼 + 파싱 + 저장
├── view.php           # 업로드 데이터 상세 조회
└── delete.php         # 업로드 삭제

office/sql/
└── create_pos_sales.sql       # DB 마이그레이션

office/partials/header.php     # 네비게이션 항목 추가 (POS Data)
```

---

## 5. 사용자 흐름

### 업로드 흐름
```
1. POS Sales Data 메뉴 클릭
2. pos_sales_upload.php 접속
3. 업로드 날짜 선택 (기본: 오늘)
4. 파일 슬롯 선택 (1 or 2)
5. 엑셀 파일 선택
6. 자동 파싱 → 헤더 검증 (Row 2 == 기대 컬럼명)
7. 미리보기 (첫 5행 테이블)
8. 저장 버튼 → DB INSERT
9. pos_sales_list.php 로 리다이렉트
```

### 조회 흐름
```
pos_sales_list.php (날짜별 카드/행)
  └── 클릭 → pos_sales_view.php?id=N
        └── 해당 업로드의 전체 데이터 테이블 표시
```

---

## 6. 네비게이션

`office/partials/header.php` Sales 섹션에 추가:

```php
// 기존 항목들 아래에 추가
$is_pos_sales = str_contains($uri, 'pos_sales');
nav_link('sales/pos_sales_list.php', 'fa-solid fa-file-arrow-up', 'POS Sales Data', $is_pos_sales);
```

---

## 7. 기술 스택

- **파싱**: PhpSpreadsheet (`/vendor/phpoffice/phpspreadsheet`) — 이미 설치됨
- **DB**: MySQL, 기존 `get_db_connection()` 사용
- **권한**: `require_office_permission()` 사용
- **UI**: 기존 Tailwind CSS + office UI 패턴 준수

---

## 8. 헤더 검증 로직

```php
$expected_headers = [
    'DATE','TIME','STORE ID','SI NO.','ITEMCODE','ITEMNAME',
    'SUPPLIER','DEPARTMENT','BOX','PCS','UNIT COST','TOTAL COST',
    'SELLING PRICE','DISCOUNT','TOTAL SALES','GROSS PROFIT',
    'SC DISCOUNT','PWD DISCOUNT','LESS VAT','NET SALES','CASHIER','PAYMENT FORM'
];

// Row 2에서 읽은 헤더와 비교
if ($actual_headers !== $expected_headers) {
    // 오류: 컬럼 구조가 다른 파일입니다.
}
```

---

## 9. 1차 범위 (In Scope)

- [x] `pos_sales_uploads` + `pos_sales_data` 테이블 생성 (마이그레이션)
- [x] 엑셀 업로드 → 파싱 → 미리보기 → DB 저장
- [x] 날짜별 업로드 목록 조회
- [x] 업로드별 데이터 상세 조회 (페이지네이션)
- [x] 날짜당 슬롯 1/2 관리 (중복 방지)
- [x] 네비게이션 항목 추가

## 10. 제외 범위 (Out of Scope — 추후)

- 집계/분석 리포트 (아이템별 매출 합계 등)
- 기간 검색 / 필터링
- 업로드 수정 기능
- 타 시스템 연동

---

## 11. 완료 기준

- [ ] 마이그레이션 실행 후 테이블 2개 생성
- [ ] .xlsx 파일 업로드 → 파싱 성공 → DB 저장
- [ ] 헤더 불일치 파일 업로드 시 오류 메시지 표시
- [ ] 같은 날짜+슬롯 중복 업로드 방지
- [ ] 목록 페이지에서 날짜/파일명/건수 표시
- [ ] 상세 조회 페이지에서 전체 데이터 테이블 표시
- [ ] 네비게이션에서 POS Sales Data 메뉴 이동 정상
