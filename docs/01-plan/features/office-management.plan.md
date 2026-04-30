# Plan: office-management (오피스 관리 시스템)

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 오피스 업무(상품/비품 구매지출, 직원 휴무 편성)를 체계적으로 기록·관리할 도구가 없어 수기 관리에 의존 |
| **Solution** | 기존 admin 세션을 공유하는 독립 `office/` 모듈로 3대 업무(지출관리 2종 + 휴무계획서)를 디지털화 |
| **Functional UX Effect** | 상품/비품 구매 지출 등록 → 월별 통계 확인 → PDF/Excel 출력 / 직원 등록 → 반월별 휴무 계획서 작성 → 슈퍼바이저 교대 자동 처리 |
| **Core Value** | 오피스 실무자(office_staff)가 어드민 없이 독립적으로 지출과 인원 관리 가능 |

---

## 1. 사용자 의도 발견 (Intent Discovery)

### 핵심 목적
기존 HOME K MART admin 시스템과 별도로, 오피스 실무자가 사용하는 3가지 업무 기능을 하나의 독립 모듈로 구축.

### 대상 사용자
- `super_admin` — 전체 조회/관리
- `office_staff` — 오피스 실무 입력/조회

### 성공 기준
1. 상품/비품 구매지출을 현금/수표 구분해서 날짜별로 정확히 기록
2. 반월 단위(1~15일, 16~말일) 휴무 계획서를 화면에서 작성 완료
3. 슈퍼바이저 휴무 시 8AM~8PM / 8PM~8AM 교대 시간이 정확히 표시
4. 월별 지출 통계 + PDF/Excel 출력 가능

---

## 2. 탐색한 대안 (Alternatives Explored)

| 방식 | 설명 | 결정 |
|------|------|------|
| **A. 통합 오피스 모듈** | `office/` 독립 폴더, admin 세션 공유, 모듈별 서브폴더 | **선택** |
| B. admin 파셜 재활용 | admin/partials 그대로 사용, 빠른 개발 | 미선택 (경로 의존성) |
| C. API 분리 SPA-lite | JSON API + JS 프론트, 향후 모바일 가능 | 미선택 (기존 패턴과 불일치) |

---

## 3. YAGNI 검토 결과

### v1 포함 기능
- [ ] 상품구매지출 — 현금 CRUD
- [ ] 상품구매지출 — 수표 CRUD
- [ ] 비품구매지출 — 현금 CRUD
- [ ] 직원등록 (직무 선택, 활성/비활성)
- [ ] 휴무계획서 작성 (1~15일 / 16~말일 탭)
- [ ] 슈퍼바이저 교대 시간 (8AM~8PM / 8PM~8AM) 선택
- [ ] 대체인원 직접 지정
- [ ] 월별 지출 통계 대시보드
- [ ] PDF/Excel 출력

### v2 이후 (Deferred)
- 수표 결제예정일 알림/리마인더
- 지출 고급 필터링/검색
- 다점포 지출 비교 차트

---

## 4. 아키텍처 설계

### 4.1 폴더 구조

```
Z:/office/
├── index.php                         ← 오피스 대시보드 (월별 지출 통계)
├── partials/
│   ├── header.php                    ← admin 세션 공유, 오피스 전용 네비게이션
│   └── footer.php
├── product_purchase/
│   ├── list.php                      ← 상품구매지출 목록 + 검색
│   ├── add.php                       ← 현금/수표 탭 전환 등록 폼
│   ├── edit.php
│   └── delete.php
├── equipment_purchase/
│   ├── list.php                      ← 비품구매지출 목록
│   ├── add.php
│   ├── edit.php
│   └── delete.php
└── schedule/
    ├── employees.php                 ← 직원 목록 + 직무별 등록/수정/비활성화
    └── schedule.php                  ← 반월 휴무계획서 (연월 선택 + 1~15/16~말일 탭)
```

### 4.2 기존 시스템 연계

```
office/*.php
  └─ require_once '../config/db_config.php'    ← DB 연결 공유
  └─ require_once '../lib/session_helper.php'  ← 세션 공유
  └─ require_once '../lib/permission_helper.php' ← 권한 체크
  └─ ensure_logged_in()                        ← admin 로그인 그대로 사용
  └─ has_permission('accounting_management')   ← office_staff 권한 매핑
```

### 4.3 CSS / 스타일

- `admin/css/style.css` (TailwindCSS 빌드 결과) 상대경로로 참조
- Bootstrap 5 모달 CDN (기존 admin과 동일)
- FontAwesome 6.5.1 CDN

---

## 5. 데이터베이스 스키마

### 5.1 상품구매지출

```sql
CREATE TABLE office_product_purchases (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id        INT UNSIGNED NOT NULL,
  payment_type    ENUM('cash','check') NOT NULL,
  supplier_name   VARCHAR(200) NOT NULL COMMENT '거래처명',
  delivery_content TEXT NOT NULL COMMENT '배달상품 내용',
  amount          DECIMAL(15,2) NOT NULL COMMENT '금액',
  payment_date    DATE NOT NULL COMMENT '현금:결제일 / 수표:결제예정일',
  check_issued_date DATE NULL COMMENT '수표 발행일 (cash인 경우 NULL)',
  created_by      INT UNSIGNED,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, payment_date),
  INDEX idx_type (payment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.2 비품구매지출

```sql
CREATE TABLE office_equipment_purchases (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id        INT UNSIGNED NOT NULL,
  supplier_name   VARCHAR(200) NOT NULL COMMENT '거래처명',
  delivery_content TEXT NOT NULL COMMENT '비품 내용',
  amount          DECIMAL(15,2) NOT NULL,
  payment_date    DATE NOT NULL COMMENT '결제일',
  created_by      INT UNSIGNED,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.3 직원 등록

```sql
CREATE TABLE office_employees (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id  INT UNSIGNED NOT NULL,
  name      VARCHAR(100) NOT NULL COMMENT '직원명',
  job_role  ENUM('cashier','patcher','butcher','driver',
                 'merchandiser','supervisor','admin') NOT NULL,
  -- 한국어 직무명: 캐쉬어, 파처, 부처, 드라이버, 머천다이져, 슈퍼바이저, 어드민
  status    ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_role (store_id, job_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.4 휴무 계획서 헤더

```sql
CREATE TABLE office_schedules (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  year       SMALLINT NOT NULL,
  month      TINYINT NOT NULL,
  period     ENUM('first','second') NOT NULL,
  -- first: 1~15일, second: 16~말일
  created_by INT UNSIGNED,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_schedule (store_id, year, month, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.5 휴무 계획서 상세

```sql
CREATE TABLE office_schedule_items (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  schedule_id             INT UNSIGNED NOT NULL,
  schedule_date           DATE NOT NULL COMMENT '해당 날짜',
  shift                   ENUM('morning','mid','gy') NOT NULL,
  job_role                ENUM('cashier','patcher','butcher','driver',
                               'merchandiser','supervisor','admin') NOT NULL,
  employee_id             INT UNSIGNED NULL COMMENT '배정 직원',
  is_off                  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '휴무 여부',
  replacement_employee_id INT UNSIGNED NULL COMMENT '대체인원 (is_off=1)',
  supervisor_shift_time   ENUM('8AM-8PM','8PM-8AM') NULL
                          COMMENT '슈퍼바이저 전용 교대시간',
  INDEX idx_schedule_date (schedule_id, schedule_date),
  FOREIGN KEY (schedule_id) REFERENCES office_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 6. 주요 화면 설계

### 6.1 상품구매지출 등록 (add.php)

```
┌──────────────────────────────────────────────┐
│  상품구매지출 등록                             │
│  결제방식: [현금] [수표]  ← 탭 전환           │
│                                              │
│  현금 탭:                                     │
│    거래처명: [____________]                    │
│    배달상품 내용: [___________]               │
│    금액: [__________]                        │
│    결제일: [날짜선택]                         │
│                                              │
│  수표 탭:                                     │
│    거래처명: [____________]                    │
│    배달상품 내용: [___________]               │
│    금액: [__________]                        │
│    수표 결제예정일: [날짜선택]                 │
│    수표 발행일: [날짜선택]                    │
│                                              │
│  [저장]  [취소]                               │
└──────────────────────────────────────────────┘
```

### 6.2 휴무계획서 화면 (schedule.php)

```
┌──────────────────────────────────────────────────────────┐
│  휴무계획서  [2026년 04월]  [1~15일 탭] [16~말일 탭]       │
│                                                          │
│  MORNING 근무                                             │
│  ┌──────┬──────┬──────┬──────┬──────┐                   │
│  │ 직무  │  1일 │  2일 │ ...  │ 15일 │                   │
│  ├──────┼──────┼──────┼──────┼──────┤                   │
│  │캐쉬어 │[직원▾]│ 홍길동│ ...  │ 휴무✗│                   │
│  │파 처  │[직원▾]│  ...  │ ...  │ ...  │                   │
│  │슈퍼   │[직원▾]│  ...  │ ...  │[8AM▾]│ ← 슈퍼바이저     │
│  └──────┴──────┴──────┴──────┴──────┘                   │
│                                                          │
│  MID 근무 / GY 근무 (동일 구조)                            │
│                                                          │
│  [저장]  [PDF출력]  [Excel다운로드]                        │
└──────────────────────────────────────────────────────────┘
```

### 6.3 대시보드 (index.php)

```
┌─────────────────────────────────────────────┐
│  오피스 관리  [2026년 04월]                   │
│                                             │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐    │
│  │상품구매   │ │비품구매   │ │수표 미결  │    │
│  │₱ 1,250,000│ │₱ 320,000 │ │  3건     │    │
│  └──────────┘ └──────────┘ └──────────┘    │
│                                             │
│  월별 지출 추이 차트                          │
│  [Jan][Feb][Mar][Apr]...                    │
└─────────────────────────────────────────────┘
```

---

## 7. 권한 매핑

| 기능 | super_admin | office_staff | admin | staff |
|------|:-----------:|:------------:|:-----:|:-----:|
| 오피스 대시보드 | ✅ | ✅ | ❌ | ❌ |
| 상품구매지출 CRUD | ✅ | ✅ | ❌ | ❌ |
| 비품구매지출 CRUD | ✅ | ✅ | ❌ | ❌ |
| 직원등록 | ✅ | ✅ | ❌ | ❌ |
| 휴무계획서 작성 | ✅ | ✅ | ❌ | ❌ |

기존 `accounting_management` 권한을 office_staff 접근 체크에 활용.

---

## 8. 구현 순서 (Do Phase)

### Step 1 — DB 테이블 생성 SQL
- `office_create_tables.sql` 파일 생성

### Step 2 — office/ 기반 구조
- `office/partials/header.php`, `footer.php`
- CSS/JS 경로 설정

### Step 3 — 상품구매지출 모듈
- `product_purchase/list.php`, `add.php`, `edit.php`, `delete.php`

### Step 4 — 비품구매지출 모듈
- `equipment_purchase/list.php`, `add.php`, `edit.php`, `delete.php`

### Step 5 — 직원등록 기능
- `schedule/employees.php` — CRUD + 직무 필터

### Step 6 — 휴무계획서 기능
- `schedule/schedule.php` — 반월 탭 그리드 UI
- 슈퍼바이저 교대시간 드롭다운
- 대체인원 지정 모달

### Step 7 — 대시보드 + 통계
- `index.php` — 월별 지출 통계 카드 + 차트

### Step 8 — PDF/Excel 출력
- PhpSpreadsheet (기존 vendor/ 활용)
- PDF는 HTML print CSS 또는 dompdf

---

## 9. 브레인스토밍 결정 로그

| 질문 | 결정 | 이유 |
|------|------|------|
| 시스템 연결 방식 | 공유 세션 | 별도 로그인 불필요, 기존 권한 재사용 |
| 접근 권한 | super_admin + office_staff | admin은 재고/구매 담당, 오피스 업무와 분리 |
| 구현 방식 | Approach A (독립 모듈) | 향후 확장성, admin 영향 없음 |
| 대체인원 처리 | 직접 지정 | 자동 배정보다 실무자 판단 우선 |
| 슈퍼바이저 교대 | ENUM('8AM-8PM','8PM-8AM') | 두 가지 고정 옵션으로 단순화 |
| v1 추가 기능 | PDF/Excel + 통계 대시보드 | 실무 필수 출력물 |

---

## 메타정보

- **Feature**: office-management
- **Phase**: plan
- **Created**: 2026-04-28
- **Author**: Plan Plus Brainstorming
- **Next Step**: `/pdca design office-management`
