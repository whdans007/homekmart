---
template: prd
version: 1.0
---

# 포스/근무시간 점포별 설정 — PRD

> **Sprint ID**: `homekmart-store-config`
> **Project**: HOME K MART 관리 프로그램
> **Master Plan**: [homekmart-store-config.master-plan.md](homekmart-store-config.master-plan.md)
> **Author**: whdans007
> **Date**: 2026-09-08
> **Status**: Draft

---

## 1. Context Anchor

Master Plan §1과 동일하며, 여기서는 요약만 둔다.

| Key | 요약 |
|-----|------|
| **WHY** | 점포마다 POS 대수·근무시간이 다른데 코드가 "POS 2대 + 전역 3교대" 고정. 게다가 그 전역 시간값이 모듈 간 이미 불일치 |
| **WHO** | 점장(설정 주체) / 캐셔·오피스 직원(설정 소비) / super_admin(전 점포 관리) |
| **RISK** | 실매출 금액 컬럼, 시간 문자열 ENUM, 시간 리터럴 기반 로직 분기, 운영 DB 스키마 미확인 |
| **SUCCESS** | 설정 변경이 매출·스케줄·인쇄물 전 화면에 일관 반영 + 기존 점포 금액 회귀 0 |
| **SCOPE** | 설정 저장소 + 헬퍼 + `my_store.php` UI + POS 대수 가변화 + 시간 단일 소스화 |

---

## 2. 문제 정의 (현행 조사 결과)

### 2.1 POS 대수 — "2대"가 코드 곳곳에 박혀 있다

| 위치 | 형태 | 비고 |
|------|------|------|
| `office/sales/sql/create_sales.sql:6-11` | `sales_daily` 6개 고정 컬럼 (`gy_pos1`…`mid_pos2`) | 3시프트 × 2포스를 **컬럼명에 인코딩** |
| `office/sales/lib/pos_recon_helper.php:19` | `pos_valid_pos(int $n): bool { return $n === 1 \|\| $n === 2; }` | 서버측 검증 상한 |
| `office/sales/lib/pos_recon_helper.php:212` | `for ($pos = 1; $pos <= 2; $pos++)` (`pos_preload_date`) | 프리로드 루프 |
| `office/sales/daily_entry.php:131` | `<th>POS 1</th><th>POS 2</th>` 고정 헤더 | |
| `office/sales/daily_entry.php:142` | `for ($pos = 1; $pos <= 2; $pos++)` | 그리드 셀 렌더 |
| `office/sales/daily_entry.php:157` | `6 cells` 하드코딩 라벨 | |
| `office/sales/pos2_entry.php:86` | `for ($pos = 1; $pos <= 2; $pos++)` | 직접 입력형 페이지 |
| `office/sales/ajax_save_sales.php:12-32` | 6개 필드 개별 수신 + 6열 UPSERT | |
| `office/sales/ajax_save_pos_cell.php:176` | `$col = "{$shift}_pos{$pos_no}"` 동적 컬럼 조립 | 화이트리스트 검증 후 사용(안전) |
| `office/lib/sales_report_helper.php:258,265-295` | `col_totals` 6키 고정 + retail_total 6항 합산 | |
| `office/lib/daily_report_helper.php:30` | `foreach ([1, 2] as $pos_no)` | Daily Report |
| `office/sales/monthly_report.php:156-181` | 6열 고정 `<td>` | |
| `office/sales/export_monthly.php:115-166` | 6열 고정 엑셀 헤더/값 | |
| `main_office/sales_report.php:171-195` | 6열 고정 `<td>` | **브리핑 미포함 — 신규 발견** |
| `main_office/export_sales_report.php:141-146` | 6열 고정 엑셀 셀 | **브리핑 미포함 — 신규 발견** |

### 2.2 핵심 발견 — `sales_daily`의 6개 컬럼은 이미 "레거시 캐시"다

브리핑에서 "정규화 필요 여부"가 최대 설계 이슈로 제기되었으나, **조사 결과 정규화가 필요 없다**:

- `sales_pos_reconciliation`은 이미 `(store_id, sale_date, shift ENUM, pos_no TINYINT)` **행 기반**이며 `total_amount`를 보유한다.
- `office/lib/sales_report_helper.php:29-39`는 월간 매출을 **`sales_pos_reconciliation`에서 읽는다**. `sales_daily`는 `delivery_k`만 읽어간다(:43-46).
- 즉 `$col_totals['gy_pos1']` 같은 6키는 **recon 행을 `"{shift}_pos{pos_no}"` 문자열로 재조립한 결과**(:39)일 뿐, `sales_daily` 컬럼이 아니다.
- `sales_daily`의 6개 POS 컬럼을 **실제로 SELECT 하는 곳은 `office/sales/pos2_entry.php:18` 한 곳뿐**이다(`SELECT * FROM sales_daily` → `$cell_val()` 프리필).
- 쓰기는 `ajax_save_pos_cell.php:176-183`(recon 미러링)과 `ajax_save_sales.php:26-32`(pos2_entry 저장 경로) 두 곳.

> **결론**: `sales_daily` POS 컬럼은 실질적 소비자가 거의 없는 denormalized mirror다. `pos2_entry.php`를 recon 기반으로 전환하면 의존이 0이 되고, 신규 정규화 테이블을 만들 필요가 없다. 상세 트레이드오프는 Design §3.

### 2.3 근무시간 — 같은 개념이 6곳 이상에 서로 다른 값으로 중복

| 위치 | GY | Morning | Mid |
|------|-----|---------|-----|
| `office/sales/daily_entry.php:46-48` (PHP) | `12:00AM–8:00AM` | `8:00AM–5:00PM` | `5:00PM–12:00AM` |
| `office/sales/daily_entry.php:363` (JS `SHIFT_TIME`) | `12AM–8AM` | `8AM–5PM` | `5PM–12AM` |
| `office/sales/pos2_entry.php:30-32` | `12:00AM–8:00AM` | `8:00AM–5:00PM` | `5:00PM–12:00AM` |
| `office/sales/print_daybook.php:62-64` | `12:00 AM -8:00 AM` | `8:00AM-5:00PM` | (mid) |
| `office/sales/monthly_report.php:103-105` | `12:00AM-8:00AM` | `8:00AM-5:00PM` | `5:00PM-12:00AM` |
| `office/lib/daily_report_helper.php:27` | `12AM-8AM` | `8AM-5PM` | `5PM-12AM` |
| `office/schedule/schedule.php:96` | **`11PM~8AM`** | `8AM~5PM` | **`3PM~12AM`** |
| `office/exports/print_schedule.php:67` | **`11PM~8AM`** | `8AM~5PM` | **`3PM~12AM`** |

**두 계열이 존재한다.** 매출 계열(GY 12AM 시작 / Mid 5PM 시작)과 스케줄 계열(GY 11PM 시작 / Mid 3PM 시작). 어느 쪽이 실제 근무 시간인지는 사용자 확인 필요 (Master Plan §7 Q5).

### 2.4 시간 문자열이 로직·데이터에 묶여 있다 (최대 위험)

- **DB ENUM**: `office_schedule_items.supervisor_shift_time ENUM('8AM-8PM','8PM-8AM','11PM~8AM','3PM~12AM','8AM~5PM','8AM~8PM','8PM~8AM')` — `office/migrate_shift_time_v2.php:41-42` 확인. 시간 문자열이 **영속 데이터**다.
- **검증 화이트리스트 중복**: `office/schedule/ajax_save_schedule.php:35` `$valid_sup_times = ['8AM~5PM','3PM~12AM','11PM~8AM','8AM~8PM','8PM~8AM','8AM-8PM','8PM-8AM'];`
- **비즈니스 로직 분기**: `office/schedule/schedule.php:362` `$is_morning_cover = ($shift_key === 'morning') && ($sup_time === '3PM~12AM');`
- **인쇄 색상 맵 키**: `office/exports/print_schedule.php:112-113` — `'3PM~12AM' => [...]`, `'11PM~8AM' => [...]`

> 시간을 점포별로 바꾸는 순간 이 4가지가 모두 조용히 깨진다. Design §5에서 shift_key 기반으로 전면 치환하는 것이 F2의 실질적 본체다.

### 2.5 점포별 설정 선례 부재

- `stores` 테이블에 JSON 설정 컬럼이나 유사 선례 없음. 지금까지의 확장은 전부 스칼라 컬럼 추가(`company_name`, `phone`, `address`, `bank_account`, `representative_user_id`, `is_active`).
- `margin_rules`는 `category_id` 기준이라 참고 불가.
- **마이그레이션 선례는 존재**: `admin/migrate_add_representative_to_stores.php` — `SHOW COLUMNS LIKE` 가드 + `autocommit(false)` + commit/rollback + 완료 후 `SHOW COLUMNS` 출력. 이 패턴을 그대로 승계한다.
- 저장소의 `homekmart_backup_2026-03-12_15-43-18.sql:87066`의 `stores`는 `id/name/created_at` **3개 컬럼뿐** — 이후 마이그레이션 5건이 반영되지 않은 낡은 스냅샷임이 확인됨. **운영 DB 확인 없이 착수 금지.**

---

## 3. Job Stories

| ID | Job Story | 대상 Feature |
|----|-----------|-------------|
| **JS-01** | 점포에 POS를 한 대 더 들였을 때, 개발자에게 요청하지 않고 내가 직접 대수를 늘려서, 새 POS의 매출도 당일부터 정산 화면에 잡히게 하고 싶다 | F1 |
| **JS-02** | 매출 정산 화면을 열었을 때, 우리 점포에 실제로 있는 POS 개수만큼만 칸이 보여서, 쓰지 않는 빈 칸 때문에 헷갈리지 않게 하고 싶다 | F1 |
| **JS-03** | 월말 리포트를 뽑을 때, 3번 POS 매출이 누락 없이 합산되어, 총액이 실제 입금액과 맞게 하고 싶다 | F1 |
| **JS-04** | 우리 점포 교대 시간이 표준과 다를 때, 설정에서 시작/종료 시각을 바꿔서, 스케줄표와 매출 장부의 시간 표기가 실제 근무와 맞게 하고 싶다 | F2 |
| **JS-05** | 스케줄 인쇄물을 직원에게 나눠줄 때, 화면에서 본 시간과 인쇄물의 시간이 같아서, 직원이 혼란스러워하지 않게 하고 싶다 | F2 |
| **JS-06** | 본사에서 여러 점포를 볼 때, 점포마다 다른 POS 대수/근무시간이 각자 올바르게 표시되어, 점포 간 비교가 왜곡되지 않게 하고 싶다 | F1, F2 |
| **JS-07** | 이번 변경을 배포했을 때, 기존 2대 점포의 지난달 매출 숫자가 그대로여서, 회계를 다시 검산하지 않아도 되게 하고 싶다 | F0, F1 |

---

## 4. 기능 요구사항 요약

> 상세 ID·수용 기준은 [Plan §3](homekmart-store-config.plan.md) 참조.

### F0 — 공통 인프라
- 점포별 설정 저장소(`stores.pos_count` + `store_shift_settings`) 구축
- `lib/store_config_helper.php` 단일 읽기 진입점 제공 (요청당 캐시)
- 실행 가능한 PHP 마이그레이션 스크립트 (기존 `admin/migrate_*.php` 패턴)

### F1 — POS 대수 점포별 설정
- `my_store.php`에서 POS 대수 입력/저장 (기본 2, 하한 1, 상한 미정 — Q7)
- `pos_valid_pos()` → 점포별 상한 검증으로 교체
- `daily_entry.php` 그리드 열 수 · 프리로드 루프 · JS 집계를 대수 기반으로 가변화
- 리포트 5종(`monthly_report`, `export_monthly`, `main_office/sales_report`, `main_office/export_sales_report`, `daily_report`)의 6열 고정 해제

### F2 — 근무시간 점포별 설정
- `my_store.php`에서 3교대 시작/종료 시각 입력/저장
- 시간 표시 문자열을 헬퍼 단일 생성으로 통일 (PHP + JS 양쪽)
- `supervisor_shift_time` ENUM 완화 + 검증 화이트리스트 설정 기반화
- 시간 리터럴 기반 분기/색상 맵을 shift_key 기반으로 치환

---

## 5. 비기능 요구사항

| ID | 항목 | 기준 |
|----|------|------|
| NFR-01 | 하위 호환 | 마이그레이션 후 기존 점포의 모든 리포트 금액이 변경 전과 **완전 동일** |
| NFR-02 | 성능 | 설정 조회로 인한 추가 쿼리는 요청당 최대 2회 (정적 캐시) |
| NFR-03 | 보안 | 설정 수정은 `LEVEL_BRANCH_MANAGER` 이상 + 본인 점포만 (super_admin 예외). 모든 쿼리 prepared statement, 동적 컬럼명은 화이트리스트 통과분만 |
| NFR-04 | 문자셋 | 신규 테이블 `utf8mb4` |
| NFR-05 | 다국어 | 신규 UI 문자열은 `lang/ko.json` / `lang/en.json` 동시 등록 |
| NFR-06 | 표시 규칙 | 금액은 소수점 둘째자리 (기존 규칙 유지) |

---

## 6. Pre-mortem

Master Plan §6과 동일. 요약:

1. **리포트 총액 불일치** — `main_office/*` 2개 파일과 `daily_report_helper.php:30`을 놓쳐 POS 3 매출 누락
2. **인쇄물 색상 붕괴** — `print_schedule.php` 색상 맵이 시간 리터럴 키라 매치 실패
3. **아무도 안 쓰는 설정** — 3대 이상 필요한 점포 실재 여부를 확인하지 않음 (Q6)
4. **스케줄 데이터 유실** — ENUM → VARCHAR 변환 시 기존 값 손실

---

## 7. 성공 지표

| 지표 | 측정 방법 | 목표 |
|------|----------|------|
| 회귀 안전성 | 마이그레이션 전/후 임의 3개월 월간 리포트 총액 비교 | diff = 0 |
| 기능 완결성 | POS 3대 시나리오에서 5개 리포트 총액 일치 | 5/5 |
| 시간 일관성 | 근무시간 변경 후 시간 표기 화면 5곳 확인 | 5/5 동일 |
| 코드 부채 감소 | 코드 내 시간 문자열 리터럴 잔존 수 | 0 |
| 설정 패턴 재사용성 | 후속 점포별 옵션 추가 시 신규 테이블 없이 확장 가능 | 정성 평가 |

---

> **Next Phase**: [Plan](homekmart-store-config.plan.md)
>
> **Status**: Draft v1.0 — pending review.
