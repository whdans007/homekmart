---
template: sprint-master-plan
version: 1.0
---

# 포스/근무시간 점포별 설정 — Sprint Master Plan

> **Sprint ID**: `homekmart-store-config`
> **Project**: HOME K MART 관리 프로그램
> **Author**: whdans007
> **Date**: 2026-09-08
> **Trust Level**: L3 (도메인 판단 + 금액 데이터 스키마 변경 포함)
> **Duration**: TBD — §5 스프린트 분할 참조
> **Status**: Draft v1.0 — pending review.

---

## §0 Executive Summary

### Mission

점포마다 다른 **POS 대수**와 **교대 근무시간**을 각 점포가 직접 설정할 수 있게 만들고, 지금 코드 전역에 흩어져 하드코딩된 "3교대 × POS 2대" 전제를 설정값 기반으로 걷어낸다.

### Anti-Mission (이번 스프린트에서 하지 않는 것)

- 매출 정산 **계산식** 변경 (`pos_recalc_cell()`의 준비금/입금/Over-Short 로직은 그대로 보존)
- 근태(`office/attendance/`) 급여·OT 산정 규칙의 재설계
- 스케줄 화면(`office/schedule/schedule.php`)의 UX 리디자인
- 교대(shift) **개수**를 3개 외의 값으로 가변화 (`gy`/`morning`/`mid` 3개는 이번 범위에서 고정)

### 4-Perspective Value

| Perspective | Content |
|-------------|---------|
| **Problem** | 점포마다 POS 대수와 근무시간이 다른데, 시스템은 "POS 2대 고정 + 전역 3교대 시간"을 전제로 만들어져 있다. 더 나쁜 것은 그 전역 시간값조차 모듈마다 서로 다르다 — 매출 모듈은 `GY 12:00AM–8:00AM`, 스케줄 모듈은 `GY 11PM~8AM`으로 이미 **불일치 상태**다. |
| **Solution** | `stores.pos_count` 스칼라 컬럼 + `store_shift_settings` 테이블로 점포별 설정을 도입하고, `lib/store_config_helper.php` 단일 진입점으로 모든 소비자가 읽게 한다. `admin/my_store.php`에 설정 UI 2개 섹션을 추가한다. |
| **Function/UX Effect** | 점장이 `내 지점 정보` 화면에서 POS 대수를 3대 이상으로 올리면 `daily_entry.php` 그리드에 POS 3 열이 즉시 생기고, 근무시간을 바꾸면 매출·스케줄·인쇄물의 시간 표기가 함께 따라온다. |
| **Core Value** | 이 프로젝트 **최초의 "점포별 설정(store-scoped config)" 패턴**을 정립한다. 이후 다른 점포별 옵션(영업시간, 통화, 준비금 목표액 등)이 같은 패턴을 재사용할 수 있는 기반이 된다. |

---

## §1 Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 점포마다 POS 대수·근무시간이 다른데 코드가 "POS 2대 + 전역 3교대" 고정이라 신규/대형 점포를 수용할 수 없다. 게다가 시간값이 매출/스케줄 모듈 간 이미 불일치라 인쇄물·리포트가 서로 다른 시간을 표기한다. |
| **WHO** | **1차 사용자**: 점장(LEVEL_BRANCH_MANAGER 이상) — `admin/my_store.php`에서 설정. **2차 사용자**: 캐셔/오피스 직원 — `office/sales/daily_entry.php`, `office/schedule/schedule.php`에서 설정 결과를 소비. **관리자**: super_admin — 전 점포 설정 조회/수정. |
| **RISK** | ① `sales_daily`의 6개 고정 금액 컬럼(`gy_pos1`…`mid_pos2`)은 **실매출 금액 데이터**라 잘못 건드리면 회계 손상. ② `office_schedule_items.supervisor_shift_time`이 시간 문자열 **ENUM**이라 시간이 점포별로 달라지면 기존 데이터/검증 화이트리스트가 깨진다. ③ 시간 문자열 리터럴이 **비즈니스 로직 분기 조건**(`schedule.php`의 `$sup_time === '3PM~12AM'`)과 **인쇄 색상 맵 키**(`print_schedule.php`)로 쓰이고 있다. ④ 운영 DB 스키마를 라이브로 확인하지 못했다. |
| **SUCCESS** | ① 점장이 `my_store.php`에서 POS 대수를 3으로 저장하면 `daily_entry.php`가 POS 1/2/3 3열 그리드를 렌더하고 3번 셀 저장/정산이 정상 동작. ② 근무시간을 저장하면 `daily_entry.php`·`monthly_report.php`·`print_daybook.php`·`schedule.php`·`print_schedule.php` 5개 화면의 시간 표기가 모두 동일하게 갱신. ③ 기존 2대/기존 시간 점포는 마이그레이션 후 **화면·리포트 금액이 1원도 바뀌지 않음**(회귀 0). |
| **SCOPE** | **v1 포함**: `stores.pos_count`, `store_shift_settings` 테이블, `lib/store_config_helper.php`, `my_store.php` 설정 UI 2섹션, POS 대수 가변 렌더/저장/검증, 시간값 단일 소스화. **v1 제외**: POS별 개별 이름/활성화 토글, 교대 개수 가변, 근태 급여 규칙 연동, 과거 데이터 소급 재계산. |
| **OUT-OF-SCOPE** | 매출 계산식 변경, 스케줄 UX 재설계, `office/attendance/` 급여 로직 수정, 다국어 신규 키를 넘어서는 i18n 리팩터링. |

---

## §2 Features

| # | Feature ID | 설명 | 우선순위 | 상태 | 담당 |
|---|-----------|------|---------|------|------|
| F0 | `store-config-infra` | 공통 인프라: `lib/store_config_helper.php` + `stores.pos_count` + `store_shift_settings` 마이그레이션 | **P0 (선행 필수)** | Not Started | Claude Code |
| F1 | `pos-terminal-config` | POS 대수 점포별 설정 (기본 2, 3대 이상 확장) | P1 | Not Started | Claude Code (도메인/스키마) + Codex (화면) |
| F2 | `store-shift-config` | 근무시간 점포별 설정 (3교대 시작/종료 시각) | P1 | Not Started | Claude Code (ENUM 완화/로직) + Codex (화면) |

> F0은 별도 feature로 분리했다. F1/F2가 모두 같은 헬퍼·같은 설정 테이블 패턴에 의존하므로, 두 feature가 각자 헬퍼를 만들면 중복·불일치가 생긴다.

---

## §3 Sprint Phase Roadmap

| Phase | 산출물 | 게이트 | 담당 |
|-------|--------|--------|------|
| 1. **PRD** | `homekmart-store-config.prd.md` | Context Anchor 5키 완성 | sprint-master-planner |
| 2. **Plan** | `homekmart-store-config.plan.md` | 요구사항 ID화 + 담당자 태깅 | sprint-master-planner |
| 3. **Design** | `homekmart-store-config.design.md` | designCompleteness ≥ 85, **"설계 전 확인 필요" 항목 전부 해소** | sprint-master-planner → Claude Code 검증 |
| 3.5. **Pre-flight** | 운영 DB `DESCRIBE stores` 결과 + 미확인 3건 조사 결과 | **차단 게이트** — 미해소 시 Do 진입 금지 | Claude Code |
| 4. **Do (F0)** | 마이그레이션 PHP + 헬퍼 | 헬퍼 단위 동작 확인 | Claude Code |
| 5. **Do (F2)** | 시간 단일 소스화 + 설정 UI | 5개 화면 시간 표기 일치 | Claude Code(로직) → Codex(화면) |
| 6. **Do (F1)** | POS 대수 가변화 + 설정 UI | POS 3대 저장/정산 정상 | Claude Code(로직) → Codex(화면) |
| 7. **QA** | 회귀 검증 (금액 불변) | 기존 2대 점포 리포트 금액 diff = 0 | Claude Code |
| 8. **Report** | `docs/04-report/` 완료 보고 | 병합 게이트 통과 | Claude Code |

---

## §4 Quality Gates 활성화 매트릭스

| Gate | 기준 | F0 | F1 | F2 | 비고 |
|------|------|:--:|:--:|:--:|:--:|
| G1 스키마 사전 확인 | 운영 DB `DESCRIBE` 결과 첨부 | ✅ | ✅ | ✅ | 저장소 SQL 백업은 **2026-03-12 스냅샷으로 이미 낡음**(stores에 컬럼 3개뿐) |
| G2 Prepared Statement | 동적 컬럼명 제외 전 파라미터 바인딩 | ✅ | ✅ | ✅ | CLAUDE.md 필수 컨벤션 |
| G3 동적 컬럼명 화이트리스트 | 조립되는 식별자는 서버 화이트리스트 검증 후 사용 | — | ✅ | — | `ajax_save_pos_cell.php:176` 패턴 |
| G4 금액 회귀 0 | 마이그레이션 전/후 월간 리포트 금액 동일 | ✅ | ✅ | — | 실 회계 데이터 |
| G5 기존 데이터 호환 | 기존 ENUM 값이 계속 유효 | — | — | ✅ | `supervisor_shift_time` |
| G6 트랜잭션 | `autocommit(false)` + commit/rollback | ✅ | ✅ | ✅ | CLAUDE.md 컨벤션 |
| G7 권한 | `LEVEL_BRANCH_MANAGER` 이상 + store 스코프 | — | ✅ | ✅ | `my_store.php` 기존 패턴 승계 |
| G8 다국어 | 신규 UI 문자열 ko/en 키 등록 | — | ✅ | ✅ | `lang/ko.json`, `lang/en.json` |

---

## §5 Sprint Split Recommendation

**결론: 단일 스프린트, 3단계 순차 진행.**

두 feature의 총 변경 표면은 파일 약 20개 · 신규 테이블 1개 · 컬럼 1개 수준으로, 분할 시 공통 인프라(F0)가 두 스프린트에 걸쳐 반쯤 완성된 채로 남는 손해가 더 크다.

### 의존관계

```
F0 (store-config-infra)
 ├──> F2 (store-shift-config)     ← F1과 상호 독립
 └──> F1 (pos-terminal-config)    ← F2와 상호 독립
```

- **F1과 F2 사이에는 코드 의존이 없다.** 서로 다른 컬럼/테이블, 서로 다른 소비 지점을 건드린다.
- 단, **둘 다 F0(헬퍼 + 마이그레이션 러너 패턴)에 의존**한다. F0 없이 병렬 착수하면 헬퍼가 두 벌 생긴다.
- **권장 순서: F0 → F2 → F1.** 이유는 아래.

### F2를 F1보다 먼저 두는 이유

1. **F2의 선행 조사가 F1보다 무겁다.** 시간값 불일치(어느 쪽이 정답인가)는 **사용자 확인이 필요한 도메인 질문**이며, 답을 기다리는 동안 F1 코딩을 병렬로 돌릴 수 있다.
2. **F2가 스키마 위험을 먼저 노출한다.** `supervisor_shift_time` ENUM 완화는 기존 스케줄 데이터에 닿으므로, 이 마이그레이션을 먼저 통과시켜야 F0 패턴이 검증된다.
3. **F1은 위험이 낮다** — §6 R-01 참조. 조사 결과 `sales_daily`의 6개 POS 컬럼은 **사실상 레거시 캐시**이며 권위 소스(`sales_pos_reconciliation`)는 이미 행 기반이다. 대규모 정규화가 필요 없다.

### 병렬 진행이 가능한 지점

| 스레드 | 작업 | 담당 |
|--------|------|------|
| A (선행) | F0 마이그레이션 + 헬퍼 | Claude Code |
| B (A 이후, 병렬) | F2 로직 — 시간 단일 소스화, ENUM 완화 | Claude Code |
| C (A 이후, 병렬) | F1 로직 — POS 대수 검증/렌더 루프 | Claude Code |
| D (B/C 스펙 확정 후) | `my_store.php` 설정 폼 2섹션 + 화면 반복 수정 | Codex |

> 워크트리 분리 시 `codex/store-config-ui` / `claude/store-config-core`. 파일 충돌 주의: `admin/my_store.php`는 Codex 단독, `office/sales/lib/pos_recon_helper.php`·`lib/store_config_helper.php`는 Claude Code 단독.

---

## §6 Risks + Pre-mortem

### 리스크 등록부

| ID | 리스크 | 영향 | 확률 | 완화책 |
|----|--------|------|------|--------|
| R-01 | `sales_daily` 6개 금액 컬럼 정규화 시 실매출 데이터 손상 | 치명 | **낮음(재평가)** | 조사 결과 이 컬럼들의 **유일한 읽기 소비자는 `office/sales/pos2_entry.php`** 뿐이다. 월간/전점포 리포트는 이미 `sales_pos_reconciliation`(행 기반)을 읽는다. → **정규화 불필요.** pos2_entry를 recon 기반으로 전환하면 의존이 0이 되고, 컬럼은 DROP 없이 남겨도 무해하다. Design §3 참조 |
| R-02 | `office_schedule_items.supervisor_shift_time` ENUM에 없는 시간값이 저장 시도되어 silent truncation | 높음 | 높음 | ENUM → `VARCHAR(20)` 완화 마이그레이션을 F2 선행 작업으로 배치. `ajax_save_schedule.php:35`의 `$valid_sup_times` 하드코딩 화이트리스트도 설정 기반으로 교체 |
| R-03 | 시간 문자열이 **로직 분기 조건**으로 쓰여 시간 변경 시 기능이 조용히 죽음 | 높음 | 확실 | 확인된 지점: `schedule.php:362` `$is_morning_cover = ... && ($sup_time === '3PM~12AM')`, `print_schedule.php:112-113` 색상 맵이 시간 리터럴을 키로 사용. → **shift_key 기반 비교로 전면 치환** (Design §5) |
| R-04 | 운영 DB `stores` 실제 컬럼을 모른 채 `pos_count` 추가 → 컬럼 충돌/AFTER 절 실패 | 중간 | 중간 | 착수 전 `DESCRIBE stores` 필수(§7 체크리스트). 마이그레이션에 `SHOW COLUMNS ... LIKE` 가드 삽입 — `admin/migrate_add_representative_to_stores.php` 패턴 그대로 |
| R-05 | POS 3대 이상 시 `daily_entry.php` 그리드가 가로로 넘쳐 모바일에서 깨짐 | 중간 | 중간 | 기존 `overflow-x-auto` 컨테이너 활용 + POS 열 최소폭 지정. 4대 이상은 가로 스크롤 허용으로 합의 |
| R-06 | `main_office/sales_report.php`·`export_sales_report.php`가 6열 고정 테이블이라 POS 3대 매출이 리포트에서 누락 | **높음** | **높음** | **브리핑에 없던 신규 발견.** HQ 전점포 리포트도 `gy_pos1`…`mid_pos2` 6개 키를 고정 렌더한다. F1 범위에 반드시 포함 |
| R-07 | Codex와 Claude가 `my_store.php`를 동시 수정 | 중간 | 중간 | agent-orchestration.md §4 — `my_store.php`는 Codex 단독 배정 |

### Pre-mortem — "6개월 뒤 이 스프린트가 실패로 판명된다면"

1. **"POS 3대를 켰더니 월말 리포트 총액이 안 맞았다."**
   → 원인: `main_office/sales_report.php`(R-06)와 `office/lib/daily_report_helper.php:30`의 `foreach ([1, 2] as $pos_no)`를 놓쳤다.
   → 예방: 회귀 검증 체크리스트에 **"POS 3 데이터를 넣고 5개 리포트 총액이 일치하는가"**를 명시적 항목으로 넣는다.

2. **"근무시간을 바꿨더니 스케줄 인쇄물의 색상이 전부 회색이 됐다."**
   → 원인: `print_schedule.php`의 색상 맵이 `'3PM~12AM'` 같은 시간 리터럴을 키로 쓰는데 시간이 바뀌어 매치 실패(R-03).
   → 예방: 색상/분기 로직을 shift_key 기반으로 치환하는 것을 F2의 **DoD 항목**으로 승격.

3. **"설정은 만들었는데 아무도 안 쓴다 — 어차피 전 점포가 2대라서."**
   → 원인: 실제로 3대 이상 필요한 점포가 있는지 확인하지 않고 만들었다.
   → 예방: PRD 착수 전 사용자에게 **"현재 POS 3대 이상인 점포가 실재하는가, 어느 점포인가"** 확인 (§7 체크리스트).

4. **"마이그레이션을 돌렸더니 기존 스케줄 데이터의 supervisor_shift_time이 전부 빈 값이 됐다."**
   → 원인: ENUM → VARCHAR 변환 시 기본값/NULL 처리를 잘못했다.
   → 예방: 마이그레이션에 **변환 전 행 수 + 변환 후 행 수 + DISTINCT 값 목록 출력**을 강제하고, 트랜잭션으로 감싼다.

---

## §7 Final Checklist

### 설계 전 확인 필요 (Pre-flight Gate — 미해소 시 Do 진입 금지)

| # | 확인 항목 | 방법 | 담당 | 상태 |
|---|----------|------|------|------|
| Q1 | 운영 DB `stores` 실제 컬럼 목록 | `DESCRIBE stores;` (main.homekmart.net) | Claude Code | ❌ 미확인 — 이번 조사에서 DB 접속 실패 |
| Q2 | `office_schedule_items` 실제 구조 + `supervisor_shift_time` 현재 ENUM 값 | `SHOW CREATE TABLE office_schedule_items;` | Claude Code | ❌ 미확인 |
| Q3 | `office/attendance/` 급여·야간수당에 시간 하드코딩 존재 여부 | `office/attendance/api/punch.php`, `report.php`의 `overtime_minutes` 산출 경로 추적 | Claude Code | ⚠️ 부분 확인 — `overtime_minutes`가 **DB에 이미 계산되어 저장된 값**으로 보임. 계산 주체(단말/트리거/외부)를 못 찾음 |
| Q4 | `office/sales/pos2_entry.php` 용도 | 사용자 확인 | 사용자 | ⚠️ 부분 확인 — `sales_nav.php` 탭 목록에 **없음**(직접 URL만 접근 가능). `sales_daily` 6개 컬럼의 **유일한 읽기 소비자**. 사용 중인지 폐기 대상인지 확답 필요 |
| Q5 | GY/Morning/Mid 시간의 **정답**은 매출 모듈인가 스케줄 모듈인가 | 사용자 확인 | 사용자 | ❌ 미확인 — 두 모듈이 다른 값 사용 중 |
| Q6 | 현재 POS 3대 이상인 점포가 실재하는가 | 사용자 확인 | 사용자 | ❌ 미확인 |
| Q7 | POS 대수 상한선 (UI/스키마 검증용) | 사용자 확인 | 사용자 | ❌ 미확인 — 잠정 4대 제안 |

### Definition of Done

- [ ] Q1~Q7 전부 해소되고 Design 문서에 반영됨
- [ ] `store_shift_settings` + `stores.pos_count` 마이그레이션이 **실행 가능한 PHP 스크립트**로 작성됨 (프로젝트 컨벤션)
- [ ] `lib/store_config_helper.php`가 유일한 설정 읽기 진입점이며, 시간 문자열 리터럴이 코드에 남아있지 않음
- [ ] `my_store.php`에 POS 대수 · 근무시간 2개 섹션이 추가되고 `LEVEL_BRANCH_MANAGER` 게이트를 통과
- [ ] POS 3대 시나리오에서 `daily_entry.php` / `monthly_report.php` / `export_monthly.php` / `main_office/sales_report.php` / `office/daily_report/` 총액이 모두 일치
- [ ] 기존 2대 점포에서 마이그레이션 전후 월간 리포트 금액 diff = 0
- [ ] `/code-review` 통과 후 Claude Code 병합 게이트 승인

---

> **Next Phase**: [PRD](homekmart-store-config.prd.md) → [Plan](homekmart-store-config.plan.md) → [Design](../../02-design/features/homekmart-store-config.design.md)
>
> **Status**: Draft v1.0 — pending review.
