# Design: office-management (오피스 관리 시스템)

## 메타정보

- **Feature**: office-management
- **Phase**: design
- **Architecture**: Option C — Pragmatic Balance
- **Created**: 2026-04-28
- **Plan Reference**: `docs/01-plan/features/office-management.plan.md`

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 오피스 업무(상품/비품 구매지출, 직원 휴무)를 수기 관리 → 디지털화 |
| **WHO** | `super_admin`, `office_staff` |
| **RISK** | 슈퍼바이저 교대 로직 복잡도, 휴무계획서 그리드 저장 시 대량 row INSERT |
| **SUCCESS** | 지출 현금/수표 CRUD + 반월 휴무계획서 작성 + 월별 통계 + PDF/Excel 출력 |
| **SCOPE** | `office/` 독립 폴더, admin 세션 공유, 5개 신규 DB 테이블 |

---

## 1. Overview

### 1.1 선택 아키텍처: Option C — Pragmatic Balance

- `office/partials/` — 오피스 전용 header/footer (admin 미참조)
- 상품구매지출·비품구매지출 — 표준 PHP POST CRUD (list/add/edit/delete)
- 휴무계획서 그리드 저장 — AJAX (JSON POST, 배치 upsert)
- `office/lib/office_helper.php` — 공통 DB 조회 함수 모음
- 기존 `config/db_config.php`, `lib/session_helper.php`, `lib/permission_helper.php` 공유

### 1.2 기술 스택

| 레이어 | 기술 |
|--------|------|
| Backend | PHP 8.2, MySQLi prepared statements |
| Frontend | TailwindCSS (admin/css/style.css 참조), Bootstrap 5 Modal CDN |
| Icons | FontAwesome 6.5.1 CDN |
| Excel | PhpSpreadsheet (기존 vendor/ 재사용) |
| PDF | HTML print CSS (dompdf는 v2 검토) |
| AJAX | vanilla fetch() / jQuery (기존 admin과 동일) |
| Charts | Chart.js CDN (대시보드 월별 통계) |

---

## 2. 파일 구조 (File Structure)

```
Z:/office/
├── index.php                          ← 대시보드 (월별 지출 통계 + 수표 미결 건수)
├── lib/
│   └── office_helper.php              ← 공통 DB 함수 (get_employees_by_role 등)
├── partials/
│   ├── header.php                     ← 오피스 전용 헤더 + 네비게이션
│   └── footer.php                     ← JS CDN + 공통 스크립트
│
├── product_purchase/
│   ├── list.php                       ← 상품구매지출 목록 (월 필터)
│   ├── add.php                        ← 현금/수표 탭 전환 등록
│   ├── edit.php                       ← 수정 (id GET 파라미터)
│   └── delete.php                     ← POST 삭제 처리 (redirect)
│
├── equipment_purchase/
│   ├── list.php                       ← 비품구매지출 목록
│   ├── add.php                        ← 등록 (현금만)
│   ├── edit.php
│   └── delete.php
│
├── schedule/
│   ├── employees.php                  ← 직원 목록 + 등록/수정/비활성화 모달
│   ├── schedule.php                   ← 반월 휴무계획서 그리드
│   ├── ajax_save_schedule.php         ← 그리드 배치 저장 AJAX 엔드포인트
│   └── ajax_get_employees.php         ← 직무별 직원 목록 조회 AJAX
│
├── exports/
│   ├── export_purchases_excel.php     ← 지출 Excel 다운로드
│   └── print_schedule.php             ← 휴무계획서 프린트 뷰 (print CSS)
│
└── sql/
    └── office_create_tables.sql       ← DB 테이블 생성 스크립트
```

---

## 3. 데이터베이스 설계

### 3.1 ERD 개요

```
stores (기존)
  │
  ├──< office_product_purchases   (상품구매지출)
  ├──< office_equipment_purchases (비품구매지출)
  ├──< office_employees           (직원)
  └──< office_schedules           (휴무계획서 헤더)
            │
            └──< office_schedule_items (휴무계획서 상세)
                    ├── employee_id → office_employees
                    └── replacement_employee_id → office_employees
```

### 3.2 office_product_purchases

```sql
CREATE TABLE office_product_purchases (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id          INT UNSIGNED NOT NULL,
  payment_type      ENUM('cash','check') NOT NULL,
  supplier_name     VARCHAR(200) NOT NULL,   -- 거래처명
  delivery_content  TEXT NOT NULL,           -- 배달상품 내용
  amount            DECIMAL(15,2) NOT NULL,  -- 금액
  payment_date      DATE NOT NULL,           -- 현금:결제일 / 수표:결제예정일
  check_issued_date DATE NULL,               -- 수표 발행일 (cash는 NULL)
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, payment_date),
  INDEX idx_type (payment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.3 office_equipment_purchases

```sql
CREATE TABLE office_equipment_purchases (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id          INT UNSIGNED NOT NULL,
  supplier_name     VARCHAR(200) NOT NULL,
  delivery_content  TEXT NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,
  payment_date      DATE NOT NULL,           -- 결제일
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.4 office_employees

```sql
CREATE TABLE office_employees (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  name       VARCHAR(100) NOT NULL,
  job_role   ENUM('cashier','patcher','butcher','driver',
                  'merchandiser','supervisor','admin') NOT NULL,
  status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_role (store_id, job_role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**직무 한국어 매핑**

| ENUM 값 | 표시명 |
|---------|--------|
| cashier | 캐쉬어 |
| patcher | 파처 |
| butcher | 부처 |
| driver | 드라이버 |
| merchandiser | 머천다이져 |
| supervisor | 슈퍼바이저 |
| admin | 어드민 |

### 3.5 office_schedules

```sql
CREATE TABLE office_schedules (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  year       SMALLINT UNSIGNED NOT NULL,
  month      TINYINT UNSIGNED NOT NULL,
  period     ENUM('first','second') NOT NULL,  -- first=1~15일, second=16~말일
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_schedule (store_id, year, month, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.6 office_schedule_items

```sql
CREATE TABLE office_schedule_items (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  schedule_id             INT UNSIGNED NOT NULL,
  schedule_date           DATE NOT NULL,
  shift                   ENUM('morning','mid','gy') NOT NULL,
  job_role                ENUM('cashier','patcher','butcher','driver',
                               'merchandiser','supervisor','admin') NOT NULL,
  employee_id             INT UNSIGNED NULL,              -- 배정 직원
  is_off                  TINYINT(1) NOT NULL DEFAULT 0,  -- 1=휴무
  replacement_employee_id INT UNSIGNED NULL,              -- 대체인원
  supervisor_shift_time   ENUM('8AM-8PM','8PM-8AM') NULL, -- 슈퍼바이저 전용
  INDEX idx_schedule_date (schedule_id, schedule_date),
  FOREIGN KEY (schedule_id) REFERENCES office_schedules(id) ON DELETE CASCADE,
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE SET NULL,
  FOREIGN KEY (replacement_employee_id) REFERENCES office_employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 4. 페이지별 상세 설계

### 4.1 대시보드 (index.php)

**표시 항목**
- 당월 상품구매지출 합계 (현금 / 수표 분리)
- 당월 비품구매지출 합계
- 수표 미결 건수 (payment_date >= 오늘)
- 최근 6개월 지출 추이 — Chart.js Bar Chart

**쿼리 패턴**
```php
// 당월 합계
SELECT payment_type, SUM(amount) as total
FROM office_product_purchases
WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?
GROUP BY payment_type
```

**월 선택**: GET 파라미터 `?year=2026&month=04`, 기본값 = 현재 월

---

### 4.2 상품구매지출 목록 (product_purchase/list.php)

**컬럼**: 번호 / 거래처명 / 배달상품 내용 / 금액 / 결제방식 / 결제일(예정일) / 수표발행일 / 등록일 / 작업

**필터**: 월 선택 드롭다운 + 결제방식(전체/현금/수표) 셀렉트

**정렬**: payment_date DESC

---

### 4.3 상품구매지출 등록 (product_purchase/add.php)

```
┌───────────────────────────────────────────────────────┐
│  상품구매지출 등록                                       │
│                                                       │
│  결제방식:  ● 현금    ○ 수표   ← radio 버튼           │
│  ─────────────────────────────────────────────────    │
│  [현금 섹션] (수표 선택 시 숨김)                         │
│    거래처명:      [                         ]          │
│    배달상품 내용: [                         ]          │
│    금액:         [              ] 원                   │
│    결제일:       [2026-04-28    ] (date picker)        │
│  ─────────────────────────────────────────────────    │
│  [수표 섹션] (현금 선택 시 숨김)                         │
│    거래처명:        [                       ]          │
│    배달상품 내용:   [                       ]          │
│    금액:           [            ] 원                   │
│    수표 결제예정일: [            ] (date picker)        │
│    수표 발행일:    [            ] (date picker)        │
│  ─────────────────────────────────────────────────    │
│                   [저장]     [취소]                    │
└───────────────────────────────────────────────────────┘
```

**폼 처리**: POST → 유효성 검사 → INSERT → list.php 리다이렉트

---

### 4.4 비품구매지출 (equipment_purchase/)

상품구매지출(현금) 탭과 동일 구조. `delivery_content` 라벨만 "비품 내용"으로 변경.

---

### 4.5 직원등록 (schedule/employees.php)

**레이아웃**: 직무 필터 탭 + 직원 카드 그리드

```
[전체] [캐쉬어] [파처] [부처] [드라이버] [머천다이져] [슈퍼바이저] [어드민]
                                                          [+ 직원 등록]
┌─────────┐ ┌─────────┐ ┌─────────┐
│ 홍길동   │ │ 김철수   │ │ 이영희   │
│ 캐쉬어   │ │ 파처     │ │ 슈퍼바이저│
│ [수정]   │ │ [수정]   │ │ [수정]   │
│ [비활성] │ │ [비활성] │ │ [비활성] │
└─────────┘ └─────────┘ └─────────┘
```

**등록 모달**: 이름 입력 + 직무 셀렉트 → POST → 새로고침

**비활성화**: status = 'inactive' 토글 (삭제 대신 soft delete)

---

### 4.6 휴무계획서 (schedule/schedule.php)

#### 헤더 컨트롤

```
[◀ 이전달]  2026년 04월  [다음달 ▶]      [1~15일] [16~말일]  [저장] [프린트]
```

#### 그리드 구조

각 **근무(shift)** 섹션이 독립 테이블:

```
━━━━ MORNING 근무 ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       │  1(화) │  2(수) │  3(목) │ ... │ 15(수) │
─────────────────────────────────────────────────────────────
캐쉬어 │[직원▾] │ 홍길동  │ 이영희  │ ... │ [휴무] │
파  처 │[직원▾] │ 김철수  │ ...    │ ... │ ...   │
부  처 │[직원▾] │ ...    │ ...    │ ... │ ...   │
드라이버│[직원▾] │ ...    │ ...    │ ... │ ...   │
머천다이져│[직원▾]│ ...   │ ...    │ ... │ ...   │
슈퍼바이저│[직원▾]│[8AM▾] │ ...    │ ... │ ...   │  ← 휴무시 교대시간 선택
어드민  │[직원▾] │ ...    │ ...    │ ... │ ...   │
─────────────────────────────────────────────────────────────

━━━━ MID 근무 ━━━━━ (동일 구조)

━━━━ GY 근무 ━━━━━ (동일 구조)
```

#### 셀 동작 규칙

| 셀 상태 | 표시 | 동작 |
|---------|------|------|
| 미배정 | `[직원 선택▾]` 드롭다운 | 해당 직무의 active 직원 목록 |
| 배정됨 | 직원명 텍스트 | 클릭 시 드롭다운 |
| 휴무 | `🔴 휴무` 배지 + 대체인원 드롭다운 | is_off=1, 대체인원 별도 선택 |
| 슈퍼바이저 + 휴무 | 교대시간 드롭다운 표시 | `[8AM-8PM▾]` 또는 `[8PM-8AM▾]` |

#### 저장 AJAX 스펙 (ajax_save_schedule.php)

```
POST /office/schedule/ajax_save_schedule.php
Content-Type: application/json

Request:
{
  "store_id": 1,
  "year": 2026,
  "month": 4,
  "period": "first",
  "items": [
    {
      "date": "2026-04-01",
      "shift": "morning",
      "job_role": "cashier",
      "employee_id": 3,
      "is_off": 0,
      "replacement_employee_id": null,
      "supervisor_shift_time": null
    },
    {
      "date": "2026-04-02",
      "shift": "morning",
      "job_role": "supervisor",
      "employee_id": 5,
      "is_off": 1,
      "replacement_employee_id": 7,
      "supervisor_shift_time": "8AM-8PM"
    }
    // ...
  ]
}

Response:
{
  "success": true,
  "schedule_id": 12,
  "saved_count": 63
}
```

**저장 로직**: schedule 헤더 upsert → 기존 items 삭제 → 새 items 배치 INSERT

#### 직원 목록 AJAX 스펙 (ajax_get_employees.php)

```
GET /office/schedule/ajax_get_employees.php?job_role=cashier&store_id=1

Response:
{
  "employees": [
    {"id": 3, "name": "홍길동"},
    {"id": 4, "name": "이영희"}
  ]
}
```

---

### 4.7 Excel 출력 (exports/export_purchases_excel.php)

**파라미터**: `?type=product|equipment&year=2026&month=4`

**컬럼**:
- 상품구매지출: 번호 / 거래처명 / 배달상품 내용 / 금액 / 결제방식 / 결제일/예정일 / 수표발행일
- 비품구매지출: 번호 / 거래처명 / 비품 내용 / 금액 / 결제일

**라이브러리**: PhpSpreadsheet (`vendor/autoload.php`)

---

### 4.8 휴무계획서 프린트 (exports/print_schedule.php)

- GET 파라미터로 schedule_id 수신
- `@media print` CSS로 헤더/버튼 숨김
- 그리드 테이블 A4 가로 출력 최적화
- `window.print()` 자동 실행

---

## 5. office_helper.php 함수 목록

```php
// 직원 조회
get_office_employees($store_id, $job_role = null, $status = 'active')

// 지출 월별 합계
get_purchase_monthly_total($store_id, $year, $month, $type = 'product|equipment')

// 수표 미결 건수
get_pending_checks_count($store_id)

// 휴무계획서 헤더 조회/생성
get_or_create_schedule($store_id, $year, $month, $period)

// 직무 한국어 변환
get_job_role_label($role)  // 'cashier' → '캐쉬어'

// 권한 체크 (office 전용)
require_office_permission()  // accounting_management 또는 super_admin
```

---

## 6. 네비게이션 설계 (partials/header.php)

```
┌─────────────────────────────────────────────────────────────┐
│  HOME K MART — 오피스 관리                    [사용자] [로그아웃]│
├─────────────────────────────────────────────────────────────┤
│  [대시보드] [상품구매지출] [비품구매지출] [직원휴무관리]  [← 관리자]│
└─────────────────────────────────────────────────────────────┘
```

- `[← 관리자]` 버튼: `../admin/index.php` 링크 (admin 접근 권한 있을 때만 표시)
- 현재 페이지 탭 active 하이라이트

---

## 7. 권한 체크 패턴

모든 office/ 페이지 상단:

```php
require_once '../config/db_config.php';
require_once '../lib/session_helper.php';
require_once '../lib/permission_helper.php';
require_once '../lib/office_helper.php';

ensure_logged_in();
require_office_permission();  // office_helper.php 내 정의
```

`require_office_permission()` 구현:

```php
function require_office_permission() {
    if ($_SESSION['role'] === 'super_admin') return;
    if (has_permission('accounting_management')) return;
    header('Location: ../admin/index.php?error=permission');
    exit;
}
```

---

## 8. 테스트 계획

### L1 — 기능 테스트 (수동)

| 시나리오 | 검증 항목 |
|---------|---------|
| 상품구매지출 현금 등록 | DB INSERT 확인, 목록 노출 |
| 상품구매지출 수표 등록 | check_issued_date 저장, 수표 미결 카운트 반영 |
| 비품구매지출 등록 | equipment_purchases 테이블 확인 |
| 직원 등록 — 직무 필터 | 해당 직무만 드롭다운에 노출 |
| 휴무계획서 저장 | AJAX 200, DB items 저장 확인 |
| 슈퍼바이저 휴무 | supervisor_shift_time 저장 + UI 드롭다운 표시 |
| 대체인원 지정 | replacement_employee_id 저장 |
| Excel 다운로드 | .xlsx 파일 생성 + 데이터 일치 |
| 프린트 뷰 | print_schedule.php 레이아웃 확인 |
| 권한 차단 | admin/staff 역할로 접근 시 리다이렉트 |

### L2 — 경계값 테스트

- 금액: 0원, 소수점, 최대값(99999999.99)
- 당월 말일 계산 (28/29/30/31일)
- 동일 schedule_id에 중복 저장 시 upsert 동작
- 비활성 직원은 드롭다운에 미노출

---

## 9. 보안 체크리스트

- [ ] 모든 INSERT/UPDATE/SELECT → MySQLi Prepared Statements
- [ ] POST 데이터 `intval()`, `htmlspecialchars()` 처리
- [ ] AJAX 엔드포인트도 `require_office_permission()` 체크
- [ ] `store_id` 필터링 — 세션 기반, 파라미터 조작 방지
- [ ] Excel 다운로드 — Content-Disposition 헤더 설정

---

## 10. 성능 고려사항

- 휴무계획서 배치 INSERT: 최대 ~7 직무 × 3 근무 × 15일 = 315 rows → 트랜잭션 단위 처리
- Chart.js 데이터는 PHP 배열 → JSON encoding → JS `data` 속성 인라인 주입
- 직원 드롭다운: 페이지 로드 시 JS 변수로 전달 (AJAX 최소화)

---

## 11. 구현 가이드

### 11.1 구현 순서

| 순서 | 모듈 | 파일 |
|------|------|------|
| 1 | DB 생성 | `office/sql/office_create_tables.sql` |
| 2 | 기반 구조 | `office/lib/office_helper.php`, `office/partials/header.php`, `footer.php` |
| 3 | 상품구매지출 | `product_purchase/list.php`, `add.php`, `edit.php`, `delete.php` |
| 4 | 비품구매지출 | `equipment_purchase/list.php`, `add.php`, `edit.php`, `delete.php` |
| 5 | 직원등록 | `schedule/employees.php` |
| 6 | 휴무계획서 | `schedule/schedule.php`, `ajax_save_schedule.php`, `ajax_get_employees.php` |
| 7 | 대시보드 | `office/index.php` |
| 8 | 출력 기능 | `exports/export_purchases_excel.php`, `exports/print_schedule.php` |

### 11.2 의존성

- PhpSpreadsheet: `vendor/autoload.php` (기존 설치 확인)
- Chart.js: CDN `https://cdn.jsdelivr.net/npm/chart.js`
- Bootstrap 5 Modal: CDN (기존 admin과 동일)

### 11.3 Session Guide — 모듈 맵

| 모듈 | 세션 | 예상 파일 수 | 예상 라인 |
|------|------|:-----------:|:--------:|
| M1: DB + 기반 구조 | Session 1 | 4 | ~200 |
| M2: 상품구매지출 | Session 2 | 4 | ~300 |
| M3: 비품구매지출 | Session 2 | 4 | ~200 |
| M4: 직원등록 | Session 3 | 1 | ~200 |
| M5: 휴무계획서 | Session 3~4 | 3 | ~500 |
| M6: 대시보드 | Session 4 | 1 | ~200 |
| M7: 출력 기능 | Session 5 | 2 | ~200 |

**권장 세션 플랜**:
```
Session 1:  /pdca do office-management --scope M1
Session 2:  /pdca do office-management --scope M2,M3
Session 3:  /pdca do office-management --scope M4,M5
Session 4:  /pdca do office-management --scope M5,M6
Session 5:  /pdca do office-management --scope M7
```
