---
template: plan
version: 1.3
---

# homekmart-store-config Planning Document

> **Summary**: 점포별 POS 대수(기본 2, 확장 가능)와 3교대 근무시간을 `admin/my_store.php`에서 설정하고, 코드 전역에 하드코딩된 "POS 2대 + 전역 시프트 시간" 전제를 설정값 기반으로 전환한다
>
> **Sprint ID**: `homekmart-store-config`
> **Project**: HOME K MART 관리 프로그램
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-09-08
> **Status**: Pre-flight Gate 통과 — Do 단계 착수 가능 (§10 참조, 2026-09-08)
> **Master Plan**: [homekmart-store-config.master-plan.md](homekmart-store-config.master-plan.md)
> **PRD**: [homekmart-store-config.prd.md](homekmart-store-config.prd.md)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | POS 대수가 `sales_daily` 컬럼명·검증 함수·렌더 루프·리포트 헤더 등 최소 15곳에 "2"로 박혀 있고, 교대 근무시간은 8곳 이상에 문자열로 중복 선언되어 있으며 매출 계열(GY 12AM)과 스케줄 계열(GY 11PM)이 서로 다른 값을 쓴다 |
| **Solution** | `stores.pos_count` + `store_shift_settings` 테이블로 점포별 설정을 도입하고, `lib/store_config_helper.php` 단일 진입점을 통해 모든 소비자가 읽게 한다. `admin/my_store.php`에 설정 UI 2섹션 추가 |
| **Function/UX Effect** | 점장이 직접 POS 대수를 늘리면 정산 그리드에 열이 추가되고 리포트에 합산된다. 근무시간을 바꾸면 매출·스케줄·인쇄물의 시간 표기가 함께 갱신된다 |
| **Core Value** | 프로젝트 최초의 store-scoped config 패턴을 정립해, 이후 점포별 옵션이 같은 구조를 재사용할 수 있게 한다 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 점포마다 POS 대수·근무시간이 다른데 코드가 전역 고정. 시간값은 모듈 간 이미 불일치 상태 |
| **WHO** | 점장(LEVEL_BRANCH_MANAGER 이상, 설정 주체) / 캐셔·오피스 직원(소비) / super_admin(전 점포) |
| **RISK** | 실매출 금액 컬럼 · `supervisor_shift_time` ENUM 영속 데이터 · 시간 리터럴 기반 로직 분기 · 운영 DB 스키마 미확인 |
| **SUCCESS** | POS 3대 시나리오에서 리포트 5종 총액 일치 + 시간 변경이 5개 화면에 일관 반영 + 기존 점포 금액 회귀 0 |
| **SCOPE** | v1: 설정 저장소 + 헬퍼 + `my_store.php` UI + POS 대수 가변화 + 시간 단일 소스화. v2 보류: POS별 개별 명칭, 교대 개수 가변, 근태 급여 연동, 과거 데이터 소급 |

---

## 1. Overview

### 1.1 Purpose

두 개의 연관 기능(POS 대수 설정 / 근무시간 설정)을 하나의 스프린트로 묶어, 점포별 설정이라는 공통 인프라 위에 구현한다.

### 1.2 Background

사용자가 `admin/my_store.php`에 "포스 설정" 섹션 추가를 요청했고, 조사 과정에서 근무시간 역시 같은 성격의 점포별 설정임이 드러났다. 두 기능은 저장소·헬퍼·설정 UI를 공유하므로 분리하면 인프라가 중복된다.

### 1.3 Related Documents

- [Master Plan](homekmart-store-config.master-plan.md) — 스프린트 분할, 리스크, Pre-flight 체크리스트
- [PRD](homekmart-store-config.prd.md) — 현행 조사 결과 전문, Job Stories
- [Design](../../02-design/features/homekmart-store-config.design.md) — 스키마·API·마이그레이션 상세
- [agent-orchestration.md](../../00-conventions/agent-orchestration.md) — Codex/Claude 분담 규칙

---

## 2. Scope

### 2.1 In Scope

| Feature | 항목 |
|---------|------|
| F0 | `stores.pos_count` 컬럼 추가 마이그레이션 |
| F0 | `store_shift_settings` 테이블 생성 + 기존 점포 시드 |
| F0 | `lib/store_config_helper.php` 신규 |
| F1 | `my_store.php` POS 설정 섹션 |
| F1 | `pos_valid_pos()` 점포별 상한 검증 전환 |
| F1 | `pos_preload_date()` 가변 루프 |
| F1 | `daily_entry.php` 그리드 가변 열 (PHP + JS) |
| F1 | `ajax_save_pos_cell.php` 검증 + `sales_daily` 미러 처리 |
| F1 | `ajax_save_sales.php` 가변 필드 수신 |
| F1 | `pos2_entry.php` 처리 (Q4 답변에 따라 전환 또는 폐기) |
| F1 | 리포트 5종 6열 고정 해제 |
| F2 | `my_store.php` 근무시간 설정 섹션 |
| F2 | 시간 문자열 8곳 → 헬퍼 단일 생성 |
| F2 | `supervisor_shift_time` ENUM 완화 마이그레이션 |
| F2 | `ajax_save_schedule.php` 검증 화이트리스트 설정 기반화 |
| F2 | `schedule.php` / `print_schedule.php` 시간 리터럴 분기 → shift_key 분기 |
| 공통 | `lang/ko.json` / `lang/en.json` 신규 키 |

### 2.2 Out of Scope (v2 보류)

- POS별 개별 명칭·활성화 토글 (`store_pos_terminals` 테이블) — 현재 요구 없음
- 교대(shift) **개수** 가변화 — `gy`/`morning`/`mid` 3개 고정 유지. `sales_pos_*` 테이블의 `shift ENUM` 변경은 영향 범위가 이번 스프린트를 넘어선다
- `office/attendance/` 급여·OT 산정 규칙 연동 — Q3 조사 결과에 따라 후속 스프린트
- 과거 데이터 소급 재계산 — 설정 변경은 **미래 입력분부터** 적용
- `sales_daily` 6개 POS 컬럼 물리 DROP — 안전 확인 후 별도 정리 스프린트

---

## 3. Requirements

> 각 항목의 `(Claude Code)` / `(Codex)` 태그는 [agent-orchestration.md §3](../../00-conventions/agent-orchestration.md) 기준 배정이다.

### 3.1 F0 — 공통 인프라 (선행 필수)

| ID | 요구사항 | 담당 | 수용 기준 |
|----|---------|------|----------|
| FR-F0-01 | `stores.pos_count TINYINT UNSIGNED NOT NULL DEFAULT 2` 컬럼 추가 마이그레이션 스크립트 | **(Claude Code)** | `SHOW COLUMNS LIKE` 가드로 재실행 안전. 트랜잭션 사용. 실행 후 `SHOW COLUMNS FROM stores` 출력 |
| FR-F0-02 | `store_shift_settings` 테이블 생성 + 전 점포 × 3교대 기본행 시드 | **(Claude Code)** | 재실행 시 중복 시드 없음. `UNIQUE (store_id, shift_key)` |
| FR-F0-03 | `lib/store_config_helper.php` — `get_store_pos_count()`, `get_store_shifts()`, `format_shift_time()`, `store_valid_pos_no()` | **(Claude Code)** | 요청당 정적 캐시. DB 오류 시 기본값 fallback (margin_helper 관례) |
| FR-F0-04 | 설정 쓰기 함수 `save_store_pos_count()`, `save_store_shifts()` | **(Claude Code)** | prepared statement, 트랜잭션, 범위 검증 |

### 3.2 F1 — POS 대수 점포별 설정

| ID | 요구사항 | 담당 | 수용 기준 |
|----|---------|------|----------|
| FR-F1-01 | `my_store.php`에 "포스 설정" 섹션 — 대수 입력(number, min=1, max=Q7) + 저장 | **(Codex)** | 기존 폼과 동일한 Tailwind 스타일. 저장 후 flash 메시지 |
| FR-F1-02 | POS 대수 축소 시 경고 — 이미 데이터가 있는 POS 번호를 잘라내려 하면 저장 차단 | **(Claude Code)** | `sales_pos_reconciliation`에 `pos_no > 신규값` 행이 있으면 오류 반환 |
| FR-F1-03 | `pos_recon_helper.php:19` `pos_valid_pos()` → 점포별 상한 검증으로 교체 | **(Claude Code)** | 시그니처에 `$store_id` 추가 또는 신규 함수 도입. 호출부 전수 갱신 |
| FR-F1-04 | `pos_recon_helper.php:212` `pos_preload_date()` 루프를 대수 기반으로 | **(Claude Code)** | 반환 키 형식 `{shift}_pos{n}` 유지 |
| FR-F1-05 | `daily_entry.php` 그리드 헤더/셀 루프 가변화 (PHP:131,142) | **(Codex)** | 스펙 확정 후. `overflow-x-auto` 유지 |
| FR-F1-06 | `daily_entry.php` JS 집계 로직 및 `6 cells` 라벨 가변화 (:157, :362~) | **(Codex)** | POS 개수를 서버에서 JS 상수로 주입 |
| FR-F1-07 | `ajax_save_pos_cell.php:15` 검증 교체 + `:176` `sales_daily` 미러 쓰기 블록 **완전 제거** | **(Claude Code)** | Q4 해소로 `sales_daily` POS 컬럼의 소비자가 0이 됨 → 조건부 스킵이 아니라 미러 쓰기 코드 자체 삭제 (Design §3.4 갱신) |
| FR-F1-08 | ~~`ajax_save_sales.php` 가변 수신~~ → **Q4 해소로 삭제 확정**: `ajax_save_sales.php` 파일 자체를 삭제 | **(Claude Code)** | 삭제 후 다른 파일에서 참조 없음(grep 확인 완료) |
| FR-F1-09 | `sales_report_helper.php:258,265-295` `col_totals` 6키 → 가변 키 | **(Claude Code)** | `retail_total` 합산 누락 없음. 계산식 자체는 불변 |
| FR-F1-10 | `daily_report_helper.php:30` `foreach ([1,2])` → 대수 기반 | **(Claude Code)** | |
| FR-F1-11 | `monthly_report.php:156-181` 6열 고정 → 가변 열 | **(Codex)** | `colspan="2"` 헤더도 대수에 맞춰 조정 |
| FR-F1-12 | `export_monthly.php:115-166` 엑셀 6열 → 가변 열 | **(Codex)** | 열 너비 배열도 함께 조정 |
| FR-F1-13 | `main_office/sales_report.php:171-195` 6열 고정 → 가변 열 | **(Codex)** | 전 점포 화면 — 점포별 대수가 다름에 유의 |
| FR-F1-14 | `main_office/export_sales_report.php:141-146` 엑셀 6열 → 가변 열 | **(Codex)** | |
| FR-F1-15 | `pos2_entry.php` **삭제** + `office/partials/header.php:130,222`의 `$is_pos2` 잔여 참조 정리 | **(Claude Code)** | Q4 해소 완료. 삭제 후 메뉴/헤더에서 깨진 링크 없는지 확인 |

### 3.3 F2 — 근무시간 점포별 설정

| ID | 요구사항 | 담당 | 수용 기준 |
|----|---------|------|----------|
| FR-F2-01 | 시간값 정답 통일 — 매출 계열 vs 스케줄 계열 결정 후 기본 시드값 확정 | **(사용자 확인 → Claude Code)** | Q5 해소 필요. 결정 근거를 Design에 기록 |
| FR-F2-02 | `my_store.php`에 "근무시간 설정" 섹션 — 3교대 × (라벨, 시작, 종료) 입력 | **(Codex)** | `<input type="time">` 사용. 자정 넘김(overnight) 허용 |
| FR-F2-03 | `format_shift_time()` — TIME 2개 → 표시 문자열 생성 | **(Claude Code)** | 기존 표기 관례(`8AM~5PM`)와 동일 포맷 재현 |
| FR-F2-04 | `daily_entry.php:46-48`(PHP) + `:363`(JS `SHIFT_TIME`) 헬퍼 기반 전환 | **(Codex)** | 아이콘/색상은 shift_key 기반 유지 |
| FR-F2-05 | ~~`pos2_entry.php:30-32` 전환~~ → 파일 삭제로 대상 없음 (FR-F1-15 참조) | — | 삭제 확정으로 작업 소멸 |
| FR-F2-06 | `print_daybook.php:62-64` 전환 | **(Codex)** | |
| FR-F2-07 | `monthly_report.php:103-105` 헤더 시간 전환 | **(Codex)** | |
| FR-F2-08 | `daily_report_helper.php:27` `$shift_labels` 전환 | **(Claude Code)** | `"포스{n}({label})"` 조합 문자열 영향 확인 |
| FR-F2-09 | `schedule.php:96` `$shift_times` 전환 | **(Codex)** | |
| FR-F2-10 | `print_schedule.php:67` `$shift_times` 전환 | **(Codex)** | |
| FR-F2-11 | **`print_schedule.php:112-113` 색상 맵 키를 시간 리터럴 → shift_key로 치환** | **(Claude Code)** | 시간 변경 후에도 색상이 유지되어야 함. R-03 완화 |
| FR-F2-12 | **`schedule.php:362` `$sup_time === '3PM~12AM'` 분기를 shift_key 기반으로 치환** | **(Claude Code)** | `$is_morning_cover` 의미를 보존한 채 조건 재정의 |
| FR-F2-13 | `office_schedule_items.supervisor_shift_time` ENUM → `VARCHAR(20)` 완화 마이그레이션 | **(Claude Code)** | 변환 전/후 행 수 + DISTINCT 값 출력. 트랜잭션 |
| FR-F2-14 | `ajax_save_schedule.php:35` `$valid_sup_times` 하드코딩 → 설정 기반 검증 | **(Claude Code)** | 기존 값(`8AM~8PM` 등 보조 시간)도 계속 허용 |

### 3.4 Non-Functional Requirements

| ID | 항목 | 기준 | 담당 |
|----|------|------|------|
| NFR-01 | 하위 호환 | 마이그레이션 후 기존 리포트 금액 완전 동일 | (Claude Code) |
| NFR-02 | 성능 | 설정 조회 추가 쿼리 요청당 ≤ 2 (정적 캐시) | (Claude Code) |
| NFR-03 | 보안 | `LEVEL_BRANCH_MANAGER` 이상 + store 스코프. prepared statement 필수. 동적 컬럼명은 화이트리스트 통과분만 | (Claude Code) |
| NFR-04 | 문자셋 | 신규 테이블 `utf8mb4` | (Claude Code) |
| NFR-05 | 다국어 | 신규 UI 문자열 ko/en 동시 등록 | (Codex) |
| NFR-06 | 표시 규칙 | 금액 소수점 둘째자리 유지 | (Codex) |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] §10 Pre-flight 항목 Q1~Q7 전부 해소
- [ ] 마이그레이션 3종이 **실행 가능한 PHP 스크립트**로 작성 및 로컬 실행 검증
- [ ] `my_store.php`에 POS 설정 · 근무시간 설정 2개 섹션 동작
- [ ] POS 대수 3으로 설정 후 `daily_entry.php`에서 3번 셀 저장 → 정산값 정상
- [ ] POS 3 데이터가 리포트 5종(`monthly_report`, `export_monthly`, `main_office/sales_report`, `main_office/export_sales_report`, `daily_report`)에 모두 합산
- [ ] 근무시간 변경 후 시간 표기 화면 5곳 일치
- [ ] 코드 내 시프트 시간 문자열 리터럴 잔존 0건 (grep 검증)
- [ ] 기존 2대 점포 임의 3개월 월간 리포트 금액 diff = 0
- [ ] `/code-review` 통과 후 Claude Code 병합

### 4.2 Quality Criteria

| 항목 | 기준 |
|------|------|
| 회귀 | 금액 diff = 0 |
| 커버리지 | 영향 파일 목록(§6.1) 전수 수정 확인 |
| 보안 | 동적 SQL 식별자 100% 화이트리스트 경유 |
| 컨벤션 | 한글 주석/UI, prepared statement, 파일 상단 `Design Ref:` 주석 |

---

## 5. Risks and Mitigation

Master Plan §6 리스크 등록부(R-01 ~ R-07)를 그대로 승계한다. Plan 관점의 추가 항목:

| ID | 리스크 | 완화책 | 담당 |
|----|--------|--------|------|
| R-08 | Codex가 화면 수정 중 `pos_recon_helper.php` 등 도메인 파일을 함께 손댐 | 워크트리 분리 + Plan §6.2 담당 파일 목록 명시 | (Claude Code, 병합 게이트) |
| R-09 | ~~점포별 대수가 다른 상태에서 `main_office` 전점포 리포트의 열 개수 결정 로직이 애매~~ → **Codex 검토로 전제 정정(§9.1 Q-B)**: 두 화면은 점포 1개 선택형이라 "선택 점포의 pos_count 기준 3N열" 로 충분. 단 엑셀 고정 16열/`MergeAcross=15`(`export_sales_report.php:89`) 가변화, 헤더 근무시간 하드코딩(`sales_report.php:122`) 헬퍼 적용 필요 | FR-F1-13/14에 엑셀 열너비·MergeAcross 가변화 및 F2 헬퍼 적용 추가 | (Codex, Claude Code 검토) |
| R-10 | `store_shift_settings` 시드 실패한 점포가 남아 화면이 빈 시간으로 렌더 | 헬퍼에 "행 없으면 기본값 반환" fallback 내장 | (Claude Code) |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| 파일 | 변경 유형 | Feature | 담당 |
|------|----------|---------|------|
| `admin/migrate_add_pos_count_to_stores.php` | 신규 | F0 | **(Claude Code)** |
| `admin/migrate_create_store_shift_settings.php` | 신규 | F0 | **(Claude Code)** |
| `office/migrate_shift_time_v3.php` | 신규 | F2 | **(Claude Code)** |
| `lib/store_config_helper.php` | 신규 | F0 | **(Claude Code)** |
| `admin/my_store.php` | 수정 (UI 2섹션 + POST 처리) | F1, F2 | **(Codex)** |
| `office/sales/lib/pos_recon_helper.php` | 수정 (:19, :212) | F1 | **(Claude Code)** |
| `office/sales/ajax_save_pos_cell.php` | 수정 (:15, :176) | F1 | **(Claude Code)** |
| `office/sales/ajax_save_sales.php` | 수정 (:12-32) | F1 | **(Claude Code)** |
| `office/lib/sales_report_helper.php` | 수정 (:258, :265-295) | F1 | **(Claude Code)** |
| `office/lib/daily_report_helper.php` | 수정 (:27, :30) | F1, F2 | **(Claude Code)** |
| `office/schedule/ajax_save_schedule.php` | 수정 (:35) | F2 | **(Claude Code)** |
| `office/schedule/schedule.php` | 수정 (:96, :362) | F2 | **(Claude Code)** :362 / **(Codex)** :96 |
| `office/exports/print_schedule.php` | 수정 (:67, :112-113) | F2 | **(Claude Code)** :112-113 / **(Codex)** :67 |
| `office/sales/daily_entry.php` | 수정 (:46-48, :131, :142, :157, :362-363) | F1, F2 | **(Codex)** |
| `office/sales/pos2_entry.php` | 수정 또는 삭제 (Q4) | F1, F2 | **(Claude Code)** 판단 후 **(Codex)** 실행 |
| `office/sales/print_daybook.php` | 수정 (:62-64) | F2 | **(Codex)** |
| `office/sales/monthly_report.php` | 수정 (:103-105, :156-181) | F1, F2 | **(Codex)** |
| `office/sales/export_monthly.php` | 수정 (:103-166) | F1 | **(Codex)** |
| `main_office/sales_report.php` | 수정 (:171-195) | F1 | **(Codex)** |
| `main_office/export_sales_report.php` | 수정 (:141-146) | F1 | **(Codex)** |
| `lang/ko.json`, `lang/en.json` | 수정 (신규 키) | F1, F2 | **(Codex)** |

> **파일 충돌 주의** (agent-orchestration.md §4): `admin/my_store.php`는 Codex 단독. `lib/store_config_helper.php`·`office/sales/lib/pos_recon_helper.php`는 Claude Code 단독. `schedule.php`와 `print_schedule.php`는 **한 파일 내에서 담당이 갈리므로 순차 처리**(Claude Code 먼저 → Codex 이어받기).

### 6.2 Current Consumers

| 소비 지점 | 현재 의존 | 변경 후 |
|----------|----------|--------|
| 매출 정산 입력 | `pos_valid_pos()` 2대 고정 | 점포별 상한 |
| 월간 리포트(점포) | `sales_pos_reconciliation` + 6키 조립 | 가변 키 조립 |
| 월간 리포트(본사) | 동일 헬퍼 + 6열 고정 렌더 | 가변 열 렌더 |
| Daily Report | `foreach([1,2])` | 대수 기반 |
| 스케줄 화면/인쇄 | 시간 문자열 하드코딩 + ENUM | 설정 + VARCHAR |
| `pos2_entry.php` | `sales_daily` 6개 컬럼 **유일 읽기 소비자** | Q4에 따라 결정 |

### 6.3 Verification

1. 마이그레이션 실행 전 `office/sales/monthly_report.php` 3개월치 총액 캡처
2. 마이그레이션 실행 → 동일 3개월 총액 재캡처 → diff 0 확인
3. 테스트 점포 `pos_count = 3` 설정 → `daily_entry.php` POS 3 셀에 데이터 입력
4. 리포트 5종에서 해당 금액 합산 확인
5. 근무시간 변경 → 시간 표기 5개 화면 + 스케줄 인쇄 색상 확인
6. `grep -rn "12:00AM\|8:00AM\|3PM~12AM\|11PM~8AM" --include=*.php office/ main_office/` → 잔존 0건

---

## 7. Implementation Order

| # | 작업 | Feature | 담당 | 선행 |
|---|------|---------|------|------|
| 0 | **Pre-flight** — Q1~Q7 해소 | — | **(Claude Code + 사용자)** | — |
| 1 | `stores.pos_count` 마이그레이션 | F0 | **(Claude Code)** | 0 |
| 2 | `store_shift_settings` 마이그레이션 + 시드 | F0 | **(Claude Code)** | 0, Q5 |
| 3 | `lib/store_config_helper.php` | F0 | **(Claude Code)** | 1, 2 |
| 4 | `supervisor_shift_time` ENUM 완화 마이그레이션 | F2 | **(Claude Code)** | 0, Q2 |
| 5 | 시간 리터럴 분기/색상 맵 shift_key 치환 (FR-F2-11, -12, -14) | F2 | **(Claude Code)** | 3, 4 |
| 6 | 시간 표기 헬퍼 전환 — 8개 화면 (FR-F2-04~10) | F2 | **(Codex)** | 5 |
| 7 | `my_store.php` 근무시간 섹션 | F2 | **(Codex)** | 3 |
| 8 | POS 대수 도메인 로직 (FR-F1-02, -03, -04, -07, -08, -09, -10, -15) | F1 | **(Claude Code)** | 3 |
| 9 | `my_store.php` POS 섹션 | F1 | **(Codex)** | 8 |
| 10 | `daily_entry.php` 가변 그리드 (FR-F1-05, -06) | F1 | **(Codex)** | 8 |
| 11 | 리포트 5종 가변 열 (FR-F1-11~14) | F1 | **(Codex)** | 8 |
| 12 | 다국어 키 등록 | 공통 | **(Codex)** | 7, 9 |
| 13 | 회귀 검증 (§6.3) | — | **(Claude Code)** | 1~12 |
| 14 | `/code-review` + 병합 | — | **(Claude Code)** | 13 |

> 6~7(F2 화면)과 10~11(F1 화면)은 각각 8이 끝난 뒤 Codex 워크트리에서 병렬 가능.

---

## 8. Convention Prerequisites

### 8.1 Existing Project Conventions

- 마이그레이션은 **실행 가능한 PHP 스크립트** (`admin/migrate_*.php`, `office/migrate_*.php`) — raw `.sql`만 두지 않는다
- `SHOW COLUMNS ... LIKE` 가드로 재실행 안전성 확보 (`admin/migrate_add_representative_to_stores.php` 패턴)
- `$conn->autocommit(false)` + `commit()` / `rollback()`
- 모든 쿼리 prepared statement, 동적 식별자는 화이트리스트 검증 후에만 조립
- 파일 상단에 `// Design Ref: ...` 주석
- 한글 주석 / 한글 UI 텍스트, 신규 문자열은 `t()` 다국어 키

### 8.2 담당 파일 범위 (Codex/Claude 분담)

**Claude Code 단독**: `lib/store_config_helper.php`, `office/sales/lib/pos_recon_helper.php`, `office/sales/ajax_save_pos_cell.php`, `office/sales/ajax_save_sales.php`, `office/lib/sales_report_helper.php`, `office/lib/daily_report_helper.php`, `office/schedule/ajax_save_schedule.php`, 마이그레이션 3종

**Codex 단독**: `admin/my_store.php`, `office/sales/daily_entry.php`, `office/sales/pos2_entry.php`, `office/sales/print_daybook.php`, `office/sales/monthly_report.php`, `office/sales/export_monthly.php`, `main_office/sales_report.php`, `main_office/export_sales_report.php`, `lang/ko.json`, `lang/en.json`

**순차 공유(Claude → Codex)**: `office/schedule/schedule.php`, `office/exports/print_schedule.php`

**브랜치**: `claude/store-config-core`, `codex/store-config-ui`

### 8.3 Environment

- 개발 URL: http://main.homekmart.net/
- DB: `u622428657_homekmart`, `utf8mb4`
- PHP 문법 검사: `PATH="/c/xampp/php:$PATH" php -l <file>`

---

## 9. Codex 사전 상의

> 사용자 지침: 계획 확정 후 바로 구현 지시하지 말고 Codex와 먼저 상의할 것.

이 Plan 문서를 `codex exec --sandbox read-only`로 공유해 아래 3가지 판단을 우선 검토받는다:

1. **`sales_daily` 6개 컬럼 처리안** — PRD §2.2의 "레거시 캐시로 확정, 정규화 불필요" 결론이 타당한가
2. **`main_office` 전점포 리포트의 열 개수 규칙** — 점포별 대수가 다를 때 어떻게 렌더할 것인가 (R-09)
3. **`pos2_entry.php` 처리** — Q4 답변 전 대안 시나리오 준비

검토 결과는 이 문서 §9에 표로 반영한 뒤 Design 착수.

### 9.1 Codex 검토 결과 (2026-09-08, workspace-write + --approve-for-me, 파일 미수정 확인됨)

| 질문 | 결론 | 계획에 반영할 수정 |
|------|------|-------------------|
| Q-A 6개 POS 컬럼 처리안 | **조건부 찬성 + 보정 필요** | `ajax_save_sales.php:25`가 recon 갱신 없이 6개 컬럼을 직접 덮어쓰는 **별도 저장 경로**임을 발견 — "재생성 가능한 캐시"로 단정 불가. FR-F1-08 범위를 "가변 수신"에서 "recon과의 쓰기 경로 통합"으로 확장 필요. `sales_report_helper.php:265`의 일별 합계/행 구조도 가변화해야 POS 3 매출 누락 없음 (FR-F1-09 수용 기준에 추가) |
| Q-B main_office 열 개수 규칙 | **전제 오류 정정** | `main_office/sales_report.php`, `export_sales_report.php`는 다점포 비교표가 아니라 **점포 1개 선택형** 화면(행=날짜). R-09를 "선택 점포의 pos_count 기준 3N열 렌더"로 정정, "최대 대수 기준" 로직은 불필요. 단, 엑셀은 현재 16열/`MergeAcross=15` 고정(`export_sales_report.php:89`)이라 가변 처리 필요, 헤더의 근무시간도 하드코딩(`sales_report.php:122`)이라 F2 헬퍼 적용 대상에 추가 |
| Q-C pos2_entry.php 처리 | **삭제 후보 근거 보강, 최종은 Q4 대기** | 외부 진입 링크/include/AJAX 없음 확인. 다만 `office/partials/header.php:130,222`에 `$is_pos2` 메뉴 판별 참조가 남아있어 폐기 시 함께 정리 필요. 유지할 경우 프리필뿐 아니라 **저장 경로까지 recon과 통합**해야 화면·리포트 금액 불일치 해소 (`pos2_entry.php:86` 참조) |

> 원문 전체: `C:\Users\whdan\AppData\Local\Temp\claude\C--laragon-www-homekmart\b20dcc11-e287-4f3e-a33e-35e095da32dc\scratchpad\codex_reply_store_config2.md`

---

## 10. Pre-flight Gate — 설계 전 확인 필요

> **이 표의 항목이 전부 해소되기 전에는 Do 단계 진입 금지.**

| # | 확인 항목 | 왜 필요한가 | 확인 방법 | 담당 | 상태 |
|---|----------|------------|----------|------|------|
| **Q1** | 운영 DB `stores` 실제 컬럼 목록 | `pos_count` 추가 시 `AFTER` 절 대상과 컬럼 충돌 여부 판단 불가 | `DESCRIBE stores;` on main.homekmart.net | Claude Code | ✅ **해소** — `id, name, company_name, phone, address, bank_account, representative_user_id, created_at`. `pos_count` 없음 → `AFTER representative_user_id`로 추가 확정 |
| **Q2** | `office_schedule_items` 실제 구조 + `supervisor_shift_time` 현재 ENUM 값·데이터 분포 | ENUM → VARCHAR 완화 마이그레이션의 안전성 판단 | `SHOW CREATE TABLE office_schedule_items;` + `SELECT supervisor_shift_time, COUNT(*) ... GROUP BY 1;` | Claude Code | ✅ **해소** — 실제 `ENUM('8AM-8PM','8PM-8AM','11PM~8AM','3PM~12AM','8AM~5PM','8AM~8PM','8PM~8AM')`, 분포: NULL 2933, `8AM-8PM` 6, `8PM-8AM` 6, `11PM~8AM` 137, `3PM~12AM` 360, `8AM~5PM` 476, `8AM~8PM` 26, `8PM~8AM` 28. 하이픈/물결표 두 형태 모두 `ajax_save_schedule.php`의 기존 화이트리스트가 이미 허용하는 값이라 **데이터 정정 불필요**, VARCHAR(20) 단순 완화로 충분. `sales_pos_reconciliation.pos_no`도 `tinyint(4)`로 확인되어 9까지 여유 (A2 겸해서 해소) |
| **Q3** | `office/attendance/`의 급여·야간수당 계산에 시프트 시간 하드코딩이 있는가 | 있다면 F2 범위가 확장되고, 시간 변경이 급여에 영향을 준다 | `office/attendance/api/punch.php`, `report.php`의 `overtime_minutes` 산출 경로 추적 + 해당 컬럼을 쓰는 테이블 확인 | Claude Code | ⚠️ **범위 밖으로 확정** — 계산 주체를 코드베이스에서 못 찾음(외부 단말/프로세스 가능성). §2.2 Out of Scope에 따라 이번 스프린트에서 다루지 않음. 차단 아님 |
| **Q4** | `office/sales/pos2_entry.php`의 실제 용도 | `sales_daily` 6개 컬럼의 **유일한 읽기 소비자**. 폐기 대상이면 F1 작업량이 크게 줄고, 사용 중이면 recon 기반 전환이 필요 | 사용자 확인 | 사용자 | ✅ **해소 — 삭제**. 추가 조사로 `ajax_save_sales.php`가 `pos2_entry.php` 전용(프로젝트 내 다른 참조 0건)임을 확인 → **두 파일 함께 삭제**. `sales_daily`의 6개 POS 컬럼은 삭제 후 읽기/쓰기 소비자가 완전히 0이 되므로, `ajax_save_pos_cell.php`의 미러 쓰기는 조건부 스킵이 아니라 **코드 블록 자체를 제거** (Design §2.2/§3.4 갱신) |
| **Q5** | GY/Morning/Mid 시간의 **정답**은? | `store_shift_settings` 시드 기본값이 결정되지 않음 | 사용자 확인 | 사용자 | ✅ **해소 — 매출 계열 채택**: GY `00:00–08:00`, Morning `08:00–17:00`, Mid `17:00–00:00`. `store_shift_settings` 시드값 확정 |
| **Q6** | 현재 POS 3대 이상인 점포가 실재하는가 | 우선순위 참고용, 비차단 | 사용자 확인 | 사용자 | ⏭️ **미응답, 비차단으로 진행** — 향후 확장 대비로 간주 |
| **Q7** | POS 대수 상한 | 입력 검증 `max` 값과 리포트 열 레이아웃 한계 결정 | 사용자 확인 | 사용자 | ✅ **해소 — 기본 2대, 상한 9대**. `STORE_POS_COUNT_MAX = 9` |

**Pre-flight Gate 통과 — Do 단계 진입 가능 (2026-09-08)**

### 추가 조사 필요 (Design 착수 후 병행 가능)

| # | 항목 | 담당 |
|---|------|------|
| A1 | `office/schedule/`의 실제 테이블 구조 전체(`office_schedules`, `office_schedule_items`) | Claude Code |
| A2 | `sales_pos_*` 5개 상세 테이블의 `pos_no` 컬럼 타입/인덱스 확인 (TINYINT 범위가 3대 이상 수용하는지) | Claude Code |
| A3 | `main_office/` 하위 파일 전체에서 POS/시프트 하드코딩 추가 잔존 여부 | Claude Code |
| A4 | `office/sales/print_daily_combined.php`의 POS/시프트 의존 (이번 grep에서 미검출, 재확인) | Claude Code |

---

## 11. Next Steps

1. **Q1~Q7 해소** — 사용자 확인 4건 + DB 조회 3건
2. Codex 사전 상의 (§9)
3. [Design 문서](../../02-design/features/homekmart-store-config.design.md) 확정 — 스키마 DDL, 헬퍼 API, 마이그레이션 상세, Test Plan
4. Implementation Order §7에 따라 착수

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-09-08 | whdans007 | 최초 작성 (sprint-master-planner) |

---

> **Next Phase**: [Design](../../02-design/features/homekmart-store-config.design.md)
>
> **Status**: Draft v1.0 — pending review.
