---
template: design
version: 1.3
---

# homekmart-store-config Design Document

> **Sprint ID**: `homekmart-store-config`
> **Project**: HOME K MART 관리 프로그램
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-09-08
> **Status**: Pre-flight Gate 통과 — Do 단계 착수 가능 (2026-09-08)
> **Plan**: [homekmart-store-config.plan.md](../../01-plan/features/homekmart-store-config.plan.md)
> **PRD**: [homekmart-store-config.prd.md](../../01-plan/features/homekmart-store-config.prd.md)
> **Master Plan**: [homekmart-store-config.master-plan.md](../../01-plan/features/homekmart-store-config.master-plan.md)

---

## ✅ 설계 전 확인 — 전부 해소 (2026-09-08)

> Pre-flight Gate 통과. 구현 착수 가능. 최종 확정값:

| # | 항목 | 확정 결과 |
|---|------|----------|
| **Q1** | 운영 DB `DESCRIBE stores` | `id, name, company_name, phone, address, bank_account, representative_user_id, created_at`. `pos_count` 없음 → `AFTER representative_user_id` 확정 |
| **Q2** | `office_schedule_items` 구조 + `supervisor_shift_time` 분포 | ENUM 7값 확인, 분포상 하이픈/물결표 두 형태 모두 기존 화이트리스트가 허용하는 정상값 — **데이터 정정 없이 VARCHAR(20) 완화만 수행**. `sales_pos_reconciliation.pos_no`는 `tinyint(4)`로 9까지 여유(A2 해소) |
| **Q3** | attendance 시간 하드코딩 | 계산 주체 미상(외부 단말 추정) — **범위 밖으로 확정**, 차단 아님 |
| **Q4** | `pos2_entry.php` 용도 | **삭제 확정**. `ajax_save_sales.php`도 이 파일 전용(타 참조 0건)이라 **함께 삭제**. `sales_daily` POS 6컬럼은 삭제 후 소비자 0 → §2.2/§3.4를 "조건부 스킵"에서 "미러 쓰기 코드 완전 제거"로 갱신 |
| **Q5** | GY/Mid 시간 정답 | **매출 계열 채택**: GY `00:00–08:00`, Morning `08:00–17:00`, Mid `17:00–00:00` |
| **Q6** | POS 3대+ 점포 실재 여부 | 미응답 — 비차단, 향후 확장 대비로 진행 |
| **Q7** | POS 대수 상한 | **기본 2, 상한 9** — `STORE_POS_COUNT_MAX = 9` |
| A1 | `office_schedule_items` 전체 구조 | 위 Q2에서 확인 완료 (schedule_id, schedule_date, shift ENUM, job_role ENUM, employee_id, is_off, replacement_employee_id, supervisor_shift_time, attendance ENUM) |
| A2 | `sales_pos_*` pos_no 타입 | `sales_pos_reconciliation.pos_no = tinyint(4)`, 상한 걸림 없음 (Q2에서 해소) |
| A3 | `main_office/` 잔존 하드코딩 | Codex 재검토로 `sales_report.php:122` 헤더 근무시간 하드코딩 1건 추가 발견 (§5.3에 반영) |
| A4 | `print_daily_combined.php` 의존 | 미조사 상태 유지 — 구현 중 그리드 가변화 작업 시 함께 확인 (낮은 리스크) |

---

## Context Anchor

Plan 문서와 동일. 이 설계가 직접 응답하는 항목:

- **RISK ①** 실매출 금액 컬럼 → §3.4에서 "정규화 불필요" 근거와 대안 비교
- **RISK ②** ENUM 영속 데이터 → §3.3 완화 마이그레이션
- **RISK ③** 시간 리터럴 로직 분기 → §5.3 shift_key 치환
- **RISK ④** 운영 DB 미확인 → 위 차단 항목 표

---

## 1. Overview

### 1.1 Design Goals

1. **단일 진입점** — 설정 읽기는 `lib/store_config_helper.php` 하나로. 소비자가 직접 SQL을 쓰지 않는다.
2. **회귀 0** — 기존 점포(POS 2대, 기존 시간)의 동작·금액이 바이트 단위로 동일해야 한다.
3. **최소 스키마 변경** — 신규 테이블 1개 + 컬럼 1개 + ENUM 완화 1건. 금액 데이터 테이블은 **건드리지 않는다**.
4. **패턴 정립** — 후속 점포별 옵션이 재사용할 수 있는 구조.

### 1.2 Design Principles

- 기존 헬퍼 관례 승계: `lib/margin_helper.php`의 "DB 오류 시 기본값 graceful fallback" 패턴
- 마이그레이션 관례 승계: `admin/migrate_add_representative_to_stores.php`의 가드 + 트랜잭션 + 결과 출력
- 계산식 불변: `pos_recalc_cell()`의 준비금/입금/Over-Short 로직은 이번 스프린트에서 **한 줄도 바꾸지 않는다**
- 시간은 **DB에 TIME으로 저장, 표시 문자열은 런타임 생성**. 문자열을 저장하지 않는다

---

## 2. Architecture Options

### 2.0 POS 대수 저장 방식 비교

| # | 안 | 장점 | 단점 | 판정 |
|---|-----|------|------|------|
| **A** | `stores.pos_count TINYINT` 스칼라 컬럼 | 기존 `stores` 확장 관례와 동일. `my_store.php` 폼과 1:1. 조회 비용 0(이미 `SELECT * FROM stores`) | POS별 개별 속성(명칭/비활성) 불가 | ✅ **채택** |
| B | `store_pos_terminals(store_id, pos_no, label, is_active)` 테이블 | POS별 이름·비활성화 가능 | 현재 요구에 없는 복잡도. 조인 추가 | v2 보류 |
| C | `stores.settings JSON` 범용 컬럼 | 후속 옵션 무제한 확장 | 프로젝트에 JSON 컬럼 선례 없음. 쿼리/검증 어려움. MySQL 버전 의존 | ❌ |

### 2.1 근무시간 저장 방식 비교

| # | 안 | 장점 | 단점 | 판정 |
|---|-----|------|------|------|
| **A** | `store_shift_settings(store_id, shift_key, label, start_time TIME, end_time TIME, sort_order)` | 정규화. 교대별 라벨/정렬 확장 가능. TIME 타입이라 비교·계산 가능 | 테이블 1개 추가 | ✅ **채택** |
| B | `stores`에 6개 TIME 컬럼(`gy_start`…`mid_end`) | 테이블 추가 없음 | 교대 개수 고정을 컬럼명에 재인코딩 — **지금 걷어내려는 안티패턴 반복** | ❌ |
| C | 시간 문자열 그대로 VARCHAR 저장 | 기존 표기와 즉시 호환 | 파싱/비교 불가. 오타 방지 불가. 현재 문제의 원인 | ❌ |

### 2.2 `sales_daily` 6개 POS 컬럼 처리 비교 — **핵심 설계 결정**

브리핑에서 최대 이슈로 제기된 항목이다. 조사 결과 전제가 바뀌었다.

**조사로 확인된 사실:**

| 사실 | 근거 |
|------|------|
| 권위 소스는 이미 행 기반이다 | `sales_pos_reconciliation`은 `(store_id, sale_date, shift ENUM, pos_no TINYINT)` 구조이며 `total_amount` 보유 |
| 월간 리포트는 `sales_daily`의 POS 컬럼을 읽지 않는다 | `office/lib/sales_report_helper.php:29-39` — `SELECT DAY(sale_date), shift, pos_no, total_amount FROM sales_pos_reconciliation`. `sales_daily`는 `delivery_k`만 읽음(:43-46) |
| `$col_totals['gy_pos1']`은 컬럼이 아니라 재조립 키다 | 같은 파일 :39 — `$sales_by_day[$d]["{$r['shift']}_pos{$r['pos_no']}"]` |
| Daily Report도 recon을 읽는다 | `office/lib/daily_report_helper.php:43-50` |
| `sales_daily` POS 컬럼의 유일한 SELECT 소비자 | `office/sales/pos2_entry.php:18` (`SELECT * FROM sales_daily`) → `$cell_val()` 프리필 |
| 쓰기는 2곳 | `ajax_save_pos_cell.php:176-183` (recon 미러링), `ajax_save_sales.php:26-32` (pos2_entry 저장) |

| # | 안 | 장점 | 단점 | 판정 |
|---|-----|------|------|------|
| **A** | **6개 컬럼을 레거시로 확정, 신규 정규화 없음.** Q4 해소(`pos2_entry.php` 삭제 확정)로 이 컬럼의 유일한 소비자가 사라짐 → `ajax_save_pos_cell.php:176`의 미러 쓰기 코드를 **조건부가 아니라 완전히 제거**. 컬럼은 DROP하지 않고 남긴다 | **금액 데이터 무이동 = 회귀 위험 최소.** 신규 테이블 0. 변경 파일 최소. 소비자 0이라 조건 분기조차 불필요해짐(단순화) | 6개 컬럼이 stale한 채로 남음(무해하나 혼란 소지) → 파일 주석으로 deprecated 명시 | ✅ **채택 + 확정** |
| B | `sales_daily_pos(store_id, sale_date, shift, pos_no, amount)` 신규 테이블로 정규화 | 개념적 정합성 | **`sales_pos_reconciliation`과 완전 중복.** 실매출 데이터 마이그레이션 필요 = 최대 위험. 얻는 것이 없음 | ❌ |
| C | `pos3`, `pos4` 컬럼을 미리 추가 | 즉시 동작 | 확장성 상한 존재. 컬럼 추가마다 15곳 수정. 안티패턴 심화 | ❌ |

> **Q4 확정 — "폐기 대상"**: `office/sales/pos2_entry.php`와 `office/sales/ajax_save_sales.php`를 **함께 삭제**한다 (grep 확인 결과 `ajax_save_sales.php`는 `pos2_entry.php` 전용, 타 참조 0건). `sales_daily` POS 컬럼 의존 완전 0. FR-F1-08/15는 "가변화"가 아니라 "삭제"로 완료. `office/partials/header.php:130,222`의 `$is_pos2` 메뉴 판별 참조도 함께 정리.

### 2.3 Component Diagram

```
                    ┌──────────────────────────────┐
                    │  admin/my_store.php          │  (Codex)
                    │  · POS 설정 섹션              │
                    │  · 근무시간 설정 섹션          │
                    └──────────────┬───────────────┘
                                   │ save_store_pos_count()
                                   │ save_store_shifts()
                                   ▼
                    ┌──────────────────────────────┐
                    │ lib/store_config_helper.php  │  (Claude Code)
                    │  get_store_pos_count()       │  ← 단일 진입점
                    │  get_store_shifts()          │
                    │  format_shift_time()         │
                    │  store_valid_pos_no()        │
                    └──────┬────────────────┬──────┘
                           │                │
              ┌────────────┘                └──────────────┐
              ▼                                            ▼
   ┌────────────────────┐                       ┌────────────────────┐
   │ stores.pos_count   │                       │store_shift_settings│
   └────────────────────┘                       └────────────────────┘
              │                                            │
   ┌──────────┴───────────────┐              ┌─────────────┴──────────────┐
   ▼                          ▼              ▼                            ▼
sales 모듈               report 모듈      schedule 모듈              print 모듈
· pos_recon_helper       · sales_report_  · schedule.php            · print_daybook
· daily_entry              helper         · ajax_save_schedule      · print_schedule
· ajax_save_pos_cell     · daily_report_
· (pos2_entry)             helper
                         · main_office/*
```

### 2.4 Data Flow — POS 대수 변경

```
점장 → my_store.php [POS 대수 = 3] 저장
  → save_store_pos_count(store_id, 3)
      → 축소 검증: SELECT COUNT(*) FROM sales_pos_reconciliation
                   WHERE store_id=? AND pos_no > 3   (0이어야 저장 허용)
      → UPDATE stores SET pos_count = 3 WHERE id = ?
  → daily_entry.php 재진입
      → get_store_pos_count(store_id) = 3
      → 그리드 3열 렌더 + JS 상수 POS_COUNT=3 주입
      → pos_preload_date() 가 3 × 3 = 9칸 프리로드
  → POS 3 셀 저장
      → ajax_save_pos_cell.php: store_valid_pos_no(store_id, 3) = true
      → sales_pos_* 5개 테이블에 pos_no=3 행 INSERT (구조 변경 불필요)
      → sales_daily 미러: pos_no=3 → 대응 컬럼 없음 → 스킵
  → monthly_report.php
      → sales_pos_reconciliation 에서 pos_no=3 행 자동 포함
      → col_totals 키 "gy_pos3" 등 동적 생성
```

### 2.5 Dependencies

| 의존 | 방향 | 비고 |
|------|------|------|
| `lib/store_config_helper.php` → `config/db_config.php` | 신규 | `get_db_connection()` |
| `office/sales/lib/pos_recon_helper.php` → `store_config_helper` | 신규 | `pos_valid_pos()` 교체용 |
| `office/lib/sales_report_helper.php` → `store_config_helper` | 신규 | 가변 키 생성 |
| `office/lib/daily_report_helper.php` → `store_config_helper` | 신규 | 대수 + 시간 라벨 |
| `admin/my_store.php` → `store_config_helper` | 신규 | 읽기/쓰기 |
| `office/schedule/*`, `office/exports/print_schedule.php` → `store_config_helper` | 신규 | 시간 라벨 |

> **순환 의존 주의**: `store_config_helper.php`는 `office_helper.php`나 세션 헬퍼에 의존하지 않는다. `store_id`를 인자로만 받는다.

---

## 3. Data Model

### 3.1 `stores.pos_count` 컬럼 추가

```sql
-- Q1 확인 완료: stores = id, name, company_name, phone, address, bank_account, representative_user_id, created_at
ALTER TABLE `stores`
  ADD COLUMN `pos_count` TINYINT UNSIGNED NOT NULL DEFAULT 2
  COMMENT '점포별 POS 단말 대수 (기본 2)'
  AFTER `representative_user_id`;
```

**마이그레이션 스크립트**: `admin/migrate_add_pos_count_to_stores.php` **(Claude Code)**

`admin/migrate_add_representative_to_stores.php` 구조를 그대로 승계:

1. `$conn->autocommit(false)`
2. `SHOW COLUMNS FROM stores LIKE 'pos_count'` → 이미 있으면 rollback 후 종료 (재실행 안전)
3. `ALTER TABLE` 실행, 실패 시 `throw`
4. `$conn->commit()`
5. `SHOW COLUMNS FROM stores` 전체 출력으로 결과 검증

### 3.2 `store_shift_settings` 테이블 신규

```sql
CREATE TABLE IF NOT EXISTS `store_shift_settings` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`   INT UNSIGNED NOT NULL,
  `shift_key`  ENUM('gy','morning','mid') NOT NULL COMMENT 'sales_pos_* 테이블의 shift ENUM과 동일 집합',
  `label`      VARCHAR(20)  NOT NULL COMMENT '화면 표시명 (GY / Morning / Mid)',
  `start_time` TIME         NOT NULL,
  `end_time`   TIME         NOT NULL COMMENT 'start > end 이면 자정 넘김(overnight)으로 해석',
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_shift` (`store_id`, `shift_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**시드 데이터 — Q5 확정: 매출 계열 채택**

| shift_key | label | start_time | end_time |
|-----------|-------|-----------|----------|
| `gy` | GY | `00:00:00` | `08:00:00` |
| `morning` | Morning | `08:00:00` | `17:00:00` |
| `mid` | Mid | `17:00:00` | `00:00:00` |

> 스케줄 계열(`schedule.php`의 GY `11PM~8AM`, Mid `3PM~12AM`)은 폐기하고 매출 계열로 통일한다. `schedule.php`도 이 시드값을 헬퍼로 읽도록 전환(§5.3).
>
> 시드 방법: `INSERT ... SELECT id FROM stores` 로 전 점포 × 3행 생성. `INSERT IGNORE` 또는 `NOT EXISTS` 가드로 재실행 안전.

**마이그레이션 스크립트**: `admin/migrate_create_store_shift_settings.php` **(Claude Code)**

### 3.3 `office_schedule_items.supervisor_shift_time` ENUM 완화

**현재 상태** (`office/migrate_shift_time_v2.php:41-42`):

```sql
MODIFY COLUMN supervisor_shift_time
  ENUM('8AM-8PM','8PM-8AM','11PM~8AM','3PM~12AM','8AM~5PM','8AM~8PM','8PM~8AM') NULL
```

**문제**: 점포별 시간이 달라지면 이 ENUM에 없는 값이 INSERT되어 MySQL 모드에 따라 **빈 문자열로 silent truncation**되거나 에러가 난다.

**변경** (`office/migrate_shift_time_v3.php` **(Claude Code)**):

```sql
MODIFY COLUMN supervisor_shift_time VARCHAR(20) NULL
  COMMENT '감독자 교대시간 표시 문자열. 점포별 store_shift_settings 기반으로 생성됨(v3부터 ENUM 해제)';
```

**안전장치 (필수)**:

1. 변환 **전**: `SELECT supervisor_shift_time, COUNT(*) FROM office_schedule_items GROUP BY 1` 출력 + 전체 행 수 기록
2. `autocommit(false)` 로 감싸기
3. 변환 **후**: 동일 쿼리 재실행 → 값 분포·행 수가 동일한지 대조 출력
4. 불일치 시 `rollback()`

> Q2 확인 완료: 운영 ENUM은 위와 동일 7값, 분포는 NULL 2933 / `8AM-8PM` 6 / `8PM-8AM` 6 / `11PM~8AM` 137 / `3PM~12AM` 360 / `8AM~5PM` 476 / `8AM~8PM` 26 / `8PM~8AM` 28. 하이픈·물결표 두 형태 모두 `ajax_save_schedule.php`의 기존 화이트리스트가 허용하는 정상값이므로 **값 정정(normalize) 없이 타입만 VARCHAR(20)로 변경**하면 된다 — ENUM이 허용하던 7개 문자열 그대로 저장 가능.

### 3.4 `sales_daily` — 변경 없음 (의도적)

§2.2 Option A 채택에 따라 **DDL 변경 없음**. 대신:

- `office/sales/sql/create_sales.sql`의 6개 컬럼 정의에 deprecated 주석 추가 (컬럼 자체는 DROP하지 않음)
- `office/sales/pos2_entry.php`, `office/sales/ajax_save_sales.php` **파일 삭제** (Q4 확정)
- `office/partials/header.php:130,222`의 `$is_pos2` 참조 제거
- `ajax_save_pos_cell.php:176` **미러 쓰기 코드 블록을 완전히 제거** (조건부 스킵이 아님 — 소비자가 0이므로 코드 자체가 불필요):

```php
// Design Ref: homekmart-store-config §3.4
// sales_daily 의 gy_pos1~mid_pos2 6개 컬럼은 pos2_entry.php(삭제됨)의 전용 미러였다.
// 권위 소스는 sales_pos_reconciliation 이며 모든 리포트가 그쪽을 읽는다.
// (office/lib/sales_report_helper.php:29-39, office/lib/daily_report_helper.php:43-50)
// 삭제된 미러 UPSERT 블록 — 더 이상 sales_daily POS 컬럼을 쓰지 않는다.
```

### 3.5 Entity Relationships

```
stores (1) ──< (3) store_shift_settings        [uk_store_shift]
stores.pos_count ──(논리적 상한)──> sales_pos_reconciliation.pos_no
                                    sales_pos_cash_count.pos_no
                                    sales_pos_payment.pos_no
                                    sales_pos_expense.pos_no
                                    sales_pos_wholesale_pick.pos_no

store_shift_settings.shift_key ≡ sales_pos_*.shift (ENUM 집합 동일: gy/morning/mid)
```

> FK 제약은 걸지 않는다 — 기존 프로젝트가 FK를 쓰지 않는 관례(`sales_daily`, `sales_transfers` 모두 FK 없음)를 따른다.

---

## 4. API Specification

### 4.1 `lib/store_config_helper.php` (신규, Claude Code)

```php
// Design Ref: homekmart-store-config §4.1 — 점포별 설정 단일 진입점

const STORE_DEFAULT_POS_COUNT = 2;
const STORE_POS_COUNT_MAX     = 9;   // Q7 확정
const STORE_SHIFT_KEYS        = ['gy', 'morning', 'mid'];

/** 점포 POS 대수. 미설정/오류 시 기본값 2. 요청당 정적 캐시. */
function get_store_pos_count(int $store_id): int;

/**
 * 점포 교대 설정.
 * @return array<string, array{label:string, start_time:string, end_time:string,
 *                             display:string, sort_order:int}>
 *         키는 shift_key. 행이 없으면 전역 기본값 fallback.
 */
function get_store_shifts(int $store_id): array;

/** POS 번호 유효성. pos_recon_helper.php:19 pos_valid_pos() 대체. */
function store_valid_pos_no(int $store_id, int $pos_no): bool;

/** TIME 2개 → 표시 문자열. 예: ('08:00:00','17:00:00') → '8AM~5PM' */
function format_shift_time(string $start, string $end, string $style = 'tilde'): string;

/** 저장. 축소 시 기존 데이터 검증 포함. @return array{ok:bool, error?:string} */
function save_store_pos_count(int $store_id, int $pos_count, ?int $updated_by = null): array;

/** 저장. 3교대 일괄. 트랜잭션. @return array{ok:bool, error?:string} */
function save_store_shifts(int $store_id, array $shifts, ?int $updated_by = null): array;
```

**동작 규칙**

| 함수 | 규칙 |
|------|------|
| `get_store_pos_count` | `SELECT pos_count FROM stores WHERE id=?`. 컬럼 없음/조회 실패 → `STORE_DEFAULT_POS_COUNT` 반환 + `error_log()`. `margin_helper.php`의 graceful fallback 관례 |
| `get_store_shifts` | 정적 배열 캐시(`static $cache = []`) → 요청당 store당 1회 쿼리. 행 부족 시 누락 shift만 기본값 채움 |
| `store_valid_pos_no` | `$pos_no >= 1 && $pos_no <= get_store_pos_count($store_id)` |
| `format_shift_time` | `style='tilde'` → `8AM~5PM` (스케줄 관례), `style='dash'` → `8:00AM–5:00PM` (매출 관례). **두 표기를 유지해 기존 화면 외관을 보존한다** |
| `save_store_pos_count` | 범위 검증(1 ~ `STORE_POS_COUNT_MAX`) → 축소 시 `SELECT COUNT(*) FROM sales_pos_reconciliation WHERE store_id=? AND pos_no>?` 가 0인지 확인 → UPDATE. 실패 시 한글 오류 메시지 |
| `save_store_shifts` | `shift_key` 화이트리스트 검증 + `HH:MM` 형식 검증 → 트랜잭션으로 3행 UPSERT |

### 4.2 `office/sales/lib/pos_recon_helper.php` 변경

| 현재 | 변경 후 |
|------|--------|
| `function pos_valid_pos(int $n): bool { return $n === 1 \|\| $n === 2; }` (:19) | `store_config_helper.php`의 `store_valid_pos_no($store_id, $n)` 호출로 대체. **기존 함수는 하위 호환을 위해 남기되 `@deprecated` 표기** |
| `pos_preload_date()` 내부 `for ($pos = 1; $pos <= 2; $pos++)` (:212) | `$max = get_store_pos_count($store_id); for ($pos = 1; $pos <= $max; $pos++)` — `$store_id`가 이미 인자로 있음 |

> 반환 키 형식 `"{$shift}_pos{$pos}"` 는 **유지**한다. `daily_entry.php` JS와 `sales_report_helper.php`가 이 형식을 공유하므로 바꾸면 파급이 커진다.

### 4.3 `admin/my_store.php` POST 처리 (Codex)

기존 POST 블록(:61~)에 두 섹션을 추가한다. 기존 `$store['name']` 등 처리와 **같은 트랜잭션 흐름에 섞지 말고 별도 처리**한다 (설정 저장 실패가 지점 정보 저장을 막지 않도록).

| 필드 | 검증 | 저장 |
|------|------|------|
| `pos_count` | `filter_var(..., FILTER_VALIDATE_INT)`, 1 ~ `STORE_POS_COUNT_MAX` | `save_store_pos_count()` |
| `shift_{key}_label` | 최대 20자, 빈값 불가 | `save_store_shifts()` |
| `shift_{key}_start`, `shift_{key}_end` | `/^\d{2}:\d{2}$/` | 동일 |

권한은 파일 상단의 기존 게이트(`current_user_level() < LEVEL_BRANCH_MANAGER` → redirect)를 그대로 적용받는다. **추가 게이트 불필요.**

---

## 5. UI/UX Design

### 5.1 `admin/my_store.php` — 신규 섹션 2개 (Codex)

기존 지점 정보 폼 아래에 카드 2개를 추가한다. 기존 Tailwind 스타일 승계.

**섹션 1: 포스 설정**

```
┌─ 포스 설정 ─────────────────────────────────┐
│ POS 대수   [  2  ] 대   (1 ~ 9)             │
│ ⓘ 대수를 늘리면 매출 정산 화면에 POS 칸이   │
│   추가됩니다. 이미 입력된 POS는 줄일 수      │
│   없습니다.                                  │
└─────────────────────────────────────────────┘
```

**섹션 2: 근무시간 설정**

```
┌─ 근무시간 설정 ─────────────────────────────┐
│ 교대      표시명      시작        종료       │
│ GY       [GY      ]  [00:00]  [08:00]       │
│ Morning  [Morning ]  [08:00]  [17:00]       │
│ Mid      [Mid     ]  [17:00]  [00:00]       │
│ ⓘ 종료가 시작보다 이르면 자정을 넘기는       │
│   근무로 처리됩니다.                         │
└─────────────────────────────────────────────┘
```

- `<input type="time">` 사용
- 저장 버튼은 기존 폼 저장 버튼과 통합 (한 번의 POST)
- 성공/실패는 기존 `$_SESSION['flash']` 패턴

### 5.2 POS 대수 가변 렌더 — 영향 화면

| 화면 | 현재 | 변경 후 | 담당 |
|------|------|--------|------|
| `daily_entry.php:131` | `<th>POS 1</th><th>POS 2</th>` | `for ($p=1;$p<=$pos_count;$p++) echo "<th>POS {$p}</th>"` | (Codex) |
| `daily_entry.php:142` | `for ($pos=1;$pos<=2;$pos++)` | `$pos_count` 기반 | (Codex) |
| `daily_entry.php:157` | `6 cells` 라벨 | `(3 × $pos_count) cells` | (Codex) |
| `daily_entry.php:362-363` JS | 상수 하드코딩 | `const POS_COUNT = <?= $pos_count ?>;` 주입 | (Codex) |
| `monthly_report.php:103-105` | `colspan="2"` × 3 | `colspan="$pos_count"` | (Codex) |
| `monthly_report.php:156-181` | 6개 `<td>` | 루프 | (Codex) |
| `export_monthly.php:103-166` | 6열 고정 | 루프 + 열 너비 배열 조정 | (Codex) |
| `main_office/sales_report.php:171-195` | 6열 고정 | **R-09 규칙 적용** (아래) | (Codex) |
| `main_office/export_sales_report.php:141-146` | 6열 고정 | 동일 | (Codex) |
| `daily_report_helper.php:30` | `foreach([1,2])` | `$pos_count` 기반 | (Claude Code) |

> **R-09 — 확정**: Codex 검토로 `main_office/sales_report.php`, `export_sales_report.php`가 여러 점포를 동시에 늘어놓는 화면이 아니라 **점포 1개를 선택하는 화면**(표의 행=날짜)임을 확인. 따라서 **안 3 채택**: 선택 점포의 `pos_count` 기준 3N열 렌더, 안 1/안 2는 불필요. 단, 엑셀은 현재 16열/`MergeAcross=15` 고정(`export_sales_report.php:89`)이라 가변 처리 필요, 화면 헤더의 근무시간도 하드코딩(`sales_report.php:122`)이라 §5.3 헬퍼 적용 대상에 포함. 향후 다점포 동시비교 화면을 만들 경우엔 "조회 대상 최대 대수 + 미보유 POS는 N/A" 방식을 권장(Codex 의견, 안 1 변형).

### 5.3 시간 표시 단일화 — 영향 지점 전수

| # | 위치 | 현재 | 변경 | 담당 |
|---|------|------|------|------|
| 1 | `daily_entry.php:46-48` | `'time'=>'12:00AM–8:00AM'` 등 3건 | `format_shift_time(..., 'dash')` | (Codex) |
| 2 | `daily_entry.php:363` JS | `const SHIFT_TIME = {gy:'12AM–8AM',...}` | 서버에서 JSON 주입 | (Codex) |
| 3 | ~~`pos2_entry.php:30-32`~~ | — | **파일 삭제로 대상 소멸** (Q4 확정) | — |
| 4 | `print_daybook.php:62-64` | `'morning'=>['MORNING','8:00AM-5:00PM']` 등 | 헬퍼 기반 | (Codex) |
| 5 | `monthly_report.php:103-105` | 헤더 `<span>12:00AM-8:00AM</span>` | 헬퍼 기반 | (Codex) |
| 6 | `daily_report_helper.php:27` | `$shift_labels = ['gy'=>'12AM-8AM',...]` | 헬퍼 기반. `"포스{n}({label})"` 조합 확인 | (Claude Code) |
| 7 | `schedule.php:96` | `$shift_times = ['morning'=>'8AM~5PM',...]` | `format_shift_time(..., 'tilde')` | (Codex) |
| 8 | `print_schedule.php:67` | 동일 | 동일 | (Codex) |

**시간 리터럴이 로직에 묶인 지점 — 반드시 shift_key 기반으로 치환 (Claude Code)**

| # | 위치 | 현재 코드 | 문제 | 변경 방향 |
|---|------|----------|------|----------|
| L1 | `schedule.php:362` | `$is_morning_cover = ($shift_key === 'morning') && ($sup_time === '3PM~12AM');` | Mid 시간이 바뀌면 이 분기가 영원히 false → "morning 커버" 표시 기능이 조용히 죽음 | `$sup_time`을 해당 점포의 `mid` 표시 문자열과 비교하거나, 의미가 "Mid 시간대를 대신 커버"라면 `supervisor_shift_time`에 **shift_key를 저장**하도록 데이터 모델을 바꾸는 것이 근본 해결. **의미 확인 필요** |
| L2 | `print_schedule.php:112-113` | 색상 맵 `'3PM~12AM' => [...]`, `'11PM~8AM' => [...]` | 시간 변경 시 매치 실패 → 색상 소실 | 맵 키를 `'mid'`, `'gy'` 등 shift_key로 변경. `:223`의 `$display_time` 조회 경로도 함께 수정 |
| L3 | `ajax_save_schedule.php:35` | `$valid_sup_times = ['8AM~5PM','3PM~12AM','11PM~8AM','8AM~8PM','8PM~8AM','8AM-8PM','8PM-8AM'];` | 점포별 시간이 이 목록에 없으면 저장 거부 | 점포의 `get_store_shifts()` 표시값 3개 + **기존 보조 시간(`8AM~8PM`, `8PM~8AM`, `8AM-8PM`, `8PM-8AM`)은 계속 허용**하도록 병합 |

> ⚠️ L1은 **의미 파악이 필요한 유일한 항목**이다. `$is_morning_cover`가 "morning 근무자가 mid 시간대를 커버한다"는 뜻인지, "3PM 출근 특수 케이스"인지에 따라 해법이 달라진다. 구현 전 `schedule.php:355-400` 전체 맥락 정독 필수.

### 5.4 Page UI Checklist

**`my_store.php` 포스 설정 섹션**
- [ ] 대수 입력이 `min=1 max=<STORE_POS_COUNT_MAX>` 로 제한
- [ ] 축소 차단 시 한글 오류 메시지 표시 ("POS N번에 이미 매출 데이터가 있어 줄일 수 없습니다")
- [ ] 저장 후 flash 성공 메시지
- [ ] super_admin이 `?id=` 로 타 지점 조회 시에도 정상 동작

**`my_store.php` 근무시간 섹션**
- [ ] 3교대 × 3필드(라벨/시작/종료) 표시
- [ ] `type="time"` 위젯 동작
- [ ] 자정 넘김 안내 문구 표시
- [ ] 빈 라벨 저장 차단

**`daily_entry.php`**
- [ ] POS 대수만큼 열 렌더, 가로 스크롤 유지
- [ ] SUBTOTAL 열이 가변 열 합계를 정확히 계산
- [ ] DAY TOTAL / `N cells` 라벨이 대수에 맞게 갱신
- [ ] 모달 제목 `POS 3 · Mid (…)` 정상 표기

---

## 6. Error Handling

| 코드 | 상황 | 처리 |
|------|------|------|
| `E-CFG-01` | `pos_count` 범위 초과 | 저장 거부, "POS 대수는 1 ~ N 사이여야 합니다" |
| `E-CFG-02` | 축소 시 기존 데이터 존재 | 저장 거부, 해당 POS 번호 명시 |
| `E-CFG-03` | 시간 형식 오류 | 저장 거부, "시간은 HH:MM 형식이어야 합니다" |
| `E-CFG-04` | 설정 테이블 조회 실패 | `error_log()` + 기본값 fallback (화면은 계속 동작) |
| `E-POS-01` | `pos_no`가 점포 상한 초과 (AJAX) | `{"success":false,"error":"Invalid parameters"}` — 기존 `ajax_save_pos_cell.php:15` 응답 형식 유지 |
| `E-SCH-01` | `supervisor_shift_time` 검증 실패 | 기존 `ajax_save_schedule.php` 응답 형식 유지 |

모든 DB 오류는 `error_log()`로 기록하고 사용자에게는 한글 메시지만 노출한다 (CLAUDE.md 컨벤션).

---

## 7. Security Considerations

| 항목 | 설계 |
|------|------|
| 권한 | 설정 수정은 `my_store.php`의 기존 게이트(`current_user_level() >= LEVEL_BRANCH_MANAGER`)를 승계 |
| Store 스코프 | super_admin이 아니면 `$store_id = $current_store_id` 강제 (기존 :19-23 로직 그대로). URL `?id=` 조작 방어 |
| SQL Injection | 모든 값은 prepared statement 바인딩 |
| **동적 식별자** | `ajax_save_pos_cell.php:176`의 `"{$shift}_pos{$pos_no}"` 는 **화이트리스트(`pos_valid_shift` + `store_valid_pos_no`) 통과 후에만** 조립. `pos_no <= 2` 조건이 추가되어 범위가 더 좁아짐 |
| 설정 우회 | 클라이언트가 `pos_no=99`를 POST해도 서버 `store_valid_pos_no()`에서 차단 |
| 데이터 손실 방지 | POS 대수 축소 시 기존 데이터 존재 검사 (E-CFG-02) |

---

## 8. Test Plan

### 8.1 Test Scope

| Level | 대상 | 방식 |
|-------|------|------|
| L1 | `format_shift_time()`, `store_valid_pos_no()` | PHP 스크립트로 케이스별 출력 검증 |
| L2 | 헬퍼 ↔ DB (`get_store_pos_count`, `save_store_shifts`) | 로컬 DB 대상 수동 실행 |
| L3 | AJAX 엔드포인트 (`ajax_save_pos_cell`, `ajax_save_schedule`) | 브라우저 네트워크 탭 |
| L4 | 화면 통합 (설정 → 정산 → 리포트) | 수동 시나리오 |
| L5 | 회귀 (마이그레이션 전후 금액 비교) | 리포트 캡처 대조 |

### 8.2 Test Plan Matrix

| ID | Level | 시나리오 | 기대 결과 | Feature |
|----|-------|---------|----------|---------|
| T-01 | L1 | `format_shift_time('08:00:00','17:00:00','tilde')` | `8AM~5PM` | F0 |
| T-02 | L1 | `format_shift_time('23:00:00','08:00:00','tilde')` | `11PM~8AM` (자정 넘김) | F0 |
| T-03 | L1 | `format_shift_time('00:00:00','08:00:00','dash')` | `12:00AM–8:00AM` | F0 |
| T-04 | L1 | `store_valid_pos_no($sid, 3)` — pos_count=2 | `false` | F1 |
| T-05 | L2 | `pos_count` 컬럼 없는 DB에서 `get_store_pos_count()` | `2` 반환 + error_log 기록, 예외 없음 | F0 |
| T-06 | L2 | `store_shift_settings` 행 없는 점포 | 기본값 3교대 반환 | F0 |
| T-07 | L2 | `save_store_pos_count($sid, 1)` — POS 2에 데이터 존재 | `ok=false`, 한글 오류 | F1 |
| T-08 | L2 | 마이그레이션 재실행 | 중복 컬럼/중복 시드 없음, 정상 종료 | F0 |
| T-09 | L2 | ENUM→VARCHAR 마이그레이션 전후 값 분포 | 완전 동일 | F2 |
| T-10 | L3 | `ajax_save_pos_cell` with `pos_no=3`, pos_count=2 | `{"success":false}` | F1 |
| T-11 | L3 | `ajax_save_pos_cell` with `pos_no=3`, pos_count=3 | 성공. `sales_pos_*` 5개 테이블에 행 생성. `sales_daily` 미러 **미수행** | F1 |
| T-12 | L3 | `ajax_save_pos_cell` with `pos_no=2` | 성공 + `sales_daily.{shift}_pos2` 갱신 (기존 동작 보존) | F1 |
| T-13 | L3 | `ajax_save_schedule`에 점포 커스텀 시간 저장 | 성공. VARCHAR에 온전히 저장 | F2 |
| T-14 | L4 | pos_count=3 설정 → `daily_entry.php` | POS 1/2/3 3열 + SUBTOTAL 정확 | F1 |
| T-15 | L4 | POS 3에 매출 입력 → `monthly_report.php` | 해당 금액이 합계에 포함 | F1 |
| T-16 | L4 | 동일 → `export_monthly.php` | 엑셀에 POS 3 열 존재 + 값 일치 | F1 |
| T-17 | L4 | 동일 → `main_office/sales_report.php` | R-09 규칙대로 표시, 총액 일치 | F1 |
| T-18 | L4 | 동일 → `office/daily_report/` | POS 3 셀 표시 | F1 |
| T-19 | L4 | 근무시간 변경 → 5개 화면 | 시간 표기 5곳 동일 | F2 |
| T-20 | L4 | 근무시간 변경 → `print_schedule.php` 인쇄 | **색상이 유지됨** (L2 회귀 검증) | F2 |
| T-21 | L4 | 근무시간 변경 → `schedule.php` morning cover 표시 | L1 로직이 정상 동작 | F2 |
| T-22 | L5 | 마이그레이션 전후 임의 3개월 월간 리포트 | 총액 diff = 0 | 전체 |
| T-23 | L5 | `grep -rn "12:00AM\|8:00AM\|3PM~12AM\|11PM~8AM" office/ main_office/` | 잔존 0건 | F2 |
| T-24 | L5 | 기존 2대 점포 `daily_entry.php` | 화면·동작이 변경 전과 동일 | 전체 |

### 8.3 Seed Data Requirements

- 테스트 점포 1개(`pos_count = 3`)와 대조 점포 1개(`pos_count = 2`)
- 테스트 점포에 3교대 × POS 3 = 9칸 매출 데이터 (최소 1일치)
- 근무시간이 기본값과 다른 점포 1개 (예: GY 22:00–07:00)
- 기존 `office_schedule_items` 데이터 (마이그레이션 회귀 검증용)

---

## 9. Clean Architecture (PHP Procedural 관례)

### 9.1 Layer Assignment

| Layer | 파일 | 역할 |
|-------|------|------|
| Config | `config/db_config.php` | 연결 |
| **Helper (신규)** | `lib/store_config_helper.php` | 점포별 설정 도메인 — **이 스프린트의 신규 레이어 구성원** |
| Domain Helper | `office/sales/lib/pos_recon_helper.php` | 정산 계산 (설정 헬퍼를 소비) |
| Aggregation Helper | `office/lib/sales_report_helper.php`, `office/lib/daily_report_helper.php` | 리포트 집계 (설정 헬퍼를 소비) |
| Endpoint | `office/sales/ajax_*.php`, `office/schedule/ajax_*.php` | 요청 처리 |
| View | `admin/my_store.php`, `office/sales/*.php`, `main_office/*.php`, `office/exports/*.php` | 렌더 |
| Migration | `admin/migrate_*.php`, `office/migrate_*.php` | 일회성 스키마 변경 |

### 9.2 Dependency Rules

- View → Helper (O), Helper → View (X)
- `store_config_helper.php`는 **다른 프로젝트 헬퍼에 의존하지 않는다** (`config/db_config.php`만). 순환 방지
- `pos_recon_helper.php` → `store_config_helper.php` (단방향)
- 각 파일은 `require_once __DIR__ . '/...'` 상대 경로 사용 (기존 관례)

---

## 10. Coding Convention Reference

| 항목 | 규칙 |
|------|------|
| 파일 헤더 | `// Design Ref: homekmart-store-config §N — <설명>` |
| 주석 | 한글 |
| UI 문자열 | 한글, 신규는 `t()` 다국어 키 (`lang/ko.json` + `lang/en.json`) |
| 함수명 | `snake_case`, 접두어 `store_` 또는 `get_store_` / `save_store_` |
| 상수 | `UPPER_SNAKE`, 접두어 `STORE_` |
| DB | prepared statement 필수, `utf8mb4`, 트랜잭션은 `autocommit(false)` + `commit`/`rollback` |
| 금액 표시 | 소수점 둘째자리 |
| 마이그레이션 | 실행 가능한 PHP 스크립트, `SHOW COLUMNS LIKE` 가드, 완료 후 구조 출력 |

---

## 11. Open Design Decisions (Codex 사전 상의 대상)

| # | 결정 사항 | 결과 | 비고 |
|---|----------|------|------|
| D1 | `sales_daily` 6개 POS 컬럼 처리 | ✅ **확정** — §2.2 Option A + Q4(삭제)로 미러 쓰기 코드까지 완전 제거 | Codex 검토: 리포트는 이미 recon만 읽음. 단 `ajax_save_sales.php`가 recon 갱신 없이 직접 썼다는 비대칭을 발견했으나, 해당 파일 자체를 삭제하므로 문제 소멸 |
| D2 | `main_office` 전점포 리포트 열 개수 규칙 | ✅ **확정** — Codex 재검토로 전제 정정: 두 화면은 **점포 1개 선택형**(다점포 비교 아님). 선택 점포의 `pos_count` 기준 3N열 렌더. 엑셀 고정 16열/`MergeAcross=15`(`export_sales_report.php:89`)와 헤더 근무시간 하드코딩(`sales_report.php:122`)도 함께 가변화 | 향후 다점포 비교 화면 추가 시엔 "최대 대수 + 빈칸" 방식 권장(Codex 의견) |
| D3 | `pos2_entry.php` 처리 | ✅ **확정 — 삭제**. Codex 검토: 외부 진입 링크/include/AJAX 없음, `header.php`의 메뉴 판별 참조만 존재 | `ajax_save_sales.php` 동반 삭제 |
| D4 | `schedule.php:362` `$is_morning_cover` 의미 | ⏳ **구현 착수 시 확인** — 코드 정독으로 의미 파악 후 shift_key 기반 치환 | 비차단(단일 파일 내부 로직, 구현 중 처리 가능) |
| D5 | `supervisor_shift_time`에 표시 문자열 대신 shift_key 저장 여부 | ❌ **채택 안 함** — 표시 문자열 유지 + VARCHAR(20) 완화로 충분 (Q2 해소: 데이터 정정 불필요 확인됨) | v2 보류 |

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-09-08 | whdans007 | 최초 작성 (sprint-master-planner) |

---

> **Next Phase**: Pre-flight Gate (Plan §10) 해소 → Codex 사전 상의 (§11) → Do 단계 (Plan §7 Implementation Order)
>
> **Status**: Draft v1.0 — pending review.
