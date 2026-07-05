# box-pcs-unit Gap Analysis Report

> **Summary**: BOX·PCS 단위 분리 재고 관리 — Design 대비 구현 정합성 분석 (Check 단계)
>
> **Project**: sunset (Logistics Center)
> **Analyzer**: gap-detector + Claude (수동 보강 검증)
> **Date**: 2026-06-11
> **Design Doc**: [box-pcs-unit.design.md](../02-design/features/box-pcs-unit.design.md)
> **Plan Doc**: [box-pcs-unit.plan.md](../01-plan/features/box-pcs-unit.plan.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | BOX/PCS 혼합 입출고 시 단위 구분이 없어 재고·원가 왜곡 + 박스 일부 파손 시 낱개 전환 수단 부재 |
| **WHO** | 물류센터 직원(입고/지점출고/박스 개봉), 지점 담당자(지점오더) |
| **RISK** | BOX lot과 PCS lot 분리로 출고·FEFO·재고집계 로직 전반이 단위별 분기 — 차감 단위 불일치 버그가 최대 리스크 |
| **SUCCESS** | BOX 5개 입고 → BOX 재고 5 / 1박스 개봉(1개 파손) → BOX 4 + PCS 19, 파손 이력 1건 / PCS 10개 출고 → PCS 9 |
| **SCOPE** | ① DB 마이그레이션(v18) ② 입고 화면/저장 ③ 입고 리스트 ④ 지점출고 ⑤ 지점오더 ⑥ 박스 개봉 페이지 ⑦ 재고 화면 분리 표시 |

---

## 1. Match Rate

| Axis | Score | Basis |
|------|:-----:|-------|
| **Structural** | 100% | Design §11.1 파일 18종(신규 6 + 수정 12) 전부 존재, 누락 0 |
| **Functional** | 98% | §5.4 UI 체크리스트 전 페이지 실로직 구현, §3.2 유효단가 CASE식 일관 적용, §6 에러 처리·§7 보안 충족. Minor 갭 3건 1차 분석 후 즉시 해소 (아래 §5) |
| **Contract** | 96% | §4 API 7개 엔드포인트 서버↔클라이언트 3-way 정합 |
| **Runtime** | 100% | L1 테스트 **33/33 PASS** (§8.2 시나리오 #1~#10 전부 커버) + 마이그레이션 수량 무변경 검증 통과 + 신규 쿼리 실 DB 검증 |

### **Overall Match Rate: 98%** ✅ (목표 90% 이상)

**런타임 증거**:
- 마이그레이션 v18 실행 완료 — lot 34건 수량 무변경 검증 통과 (in 25,901 / out 12 동일), BOX lot 32 / PCS lot 2 라벨링, cost_price_pcs=0 행 0건
- `test_box_pcs_unit.php` **33/33 PASS** — 정규화·PCS원가·단위별 재고·개봉 트랜잭션(실경로 `lc_execute_box_break`)·개봉 검증 오류·전량 파손·단위별 FEFO·유효단가·suggest_break (트랜잭션 롤백 방식)
- inventory/order_new 신규 집계 쿼리 + `lc_order_items` INSERT 실 DB 검증 통과

---

## 2. Strategic Alignment (PRD/Plan WHY 검증)

- ✅ 핵심 문제 해결: 단위 분리 lot 모델로 BOX/PCS 혼합 입출고 시 재고·원가 정확성 확보
- ✅ 파손 시나리오: 박스 개봉 → 파손 제외 PCS 전환 + `lc_box_breaks` 손실 이력
- ✅ 최대 리스크(차감 단위 불일치) 구조적 차단: 단위 분기 로직을 `lib/unit_helper.php` 한 곳에 집중, FEFO 쿼리 `WHERE i.unit = ?` 필수화 (`unit_helper.php:110`)
- ✅ 사용자 확정 결정 준수: 수동 토글(자동 전환 없음), 수동 개봉(자동 개봉 없음), 수량 무변경 라벨링 마이그레이션

---

## 3. Plan Success Criteria (SC-1 ~ SC-9)

| SC | 내용 | 상태 | 증거 |
|----|------|:----:|------|
| SC-1 | BOX 토글 스캔 → BOX lot 등록 | ✅ | `inbound_add.php:197-208` + L1 Test 3 |
| SC-2 | PCS 토글 스캔 → PCS lot 등록 | ✅ | 동일 경로, `lc_valid_unit` 분기 `:119` |
| SC-3 | 리스트 BOX원가+PCS원가 동시 노출 | ✅ | `inbound_helper.php:106-107`, `inbound_add.php:399-404` |
| SC-4 | 개봉: BOX5→1박스(파손1)→BOX4+PCS19+이력 | ✅ | `ajax/box_break.php` 트랜잭션 + L1 Test 4 (damage_cost=PCS단가×1) |
| SC-5 | 단위별 FEFO: BOX→BOX lot만, PCS→PCS lot만 | ✅ | `unit_helper.php:110` + L1 Test 6/7 |
| SC-6 | PCS 부족+BOX 보유 시 개봉 안내 | ✅ | `unit_helper.php:246-248` + L1 Test 8 (suggest_break=true) |
| SC-7 | 지점오더 단위 선택 + order_unit 기록 | ✅ | `order_new.php` (단위 select, 단위별 max, ppb 스냅샷 저장) |
| SC-8 | 마이그레이션 수량 무변경 | ✅ | `run_migration_v18.php` 전후 합계 비교 + 실행 결과 통과 |
| SC-9 | SQL + PHP 스크립트 쌍 | ✅ | `lc_migration_v18.sql` + `run_migration_v18.php` |

**Success Rate: 9/9** ✅

## 4. Functional Requirements (FR-01 ~ FR-13)

| FR | 상태 | 증거 |
|----|:----:|------|
| FR-01 입고 토글+행별 단위 | ✅ | `inbound_add.php:307-321, 716-724` |
| FR-02 PCS원가 자동계산 | ✅ | `inbound_add.php:121`(서버 재계산), `:738-739`(표시) |
| FR-03 저장 시 단위 기록 | ✅ | `inbound_add.php:181-208` |
| FR-04 리스트 2단 원가+단위 병기 | ✅ | `inbound_helper.php:106-107` |
| FR-05 지점출고 단위별 FEFO | ✅ | `branch_outbound.php:64-74`, `unit_helper.php:101-110` |
| FR-06 자동개봉 없이 안내만 | ✅ | `branch_outbound.php:594-603` |
| FR-07 개봉 트랜잭션 (승계+원자성) | ✅ | `ajax/box_break.php` (FOR UPDATE + 전체 롤백) |
| FR-08 파손 이력 저장+조회 | ✅ | `lc_box_breaks` INSERT + `box_break.php` 이력 테이블 |
| FR-09 지점오더 단위 기록 | ✅ | `order_new.php` order_unit + ppb 스냅샷 |
| FR-10 수량 무변경 라벨링 | ✅ | `lc_migration_v18.sql` + 검증 리포트 |
| FR-11 ppb≤1 경고+보정 | ✅ | `unit_helper.php:37`, UI 경고 배지 |
| FR-12 재고 분리 집계+개봉 링크 | ✅ | `inventory.php` (목록 행 + lot 모달) |
| FR-13 입고수정 단위 일관 갱신 | ✅ | `inbound_edit.php:74-78`(서버 재검증·재계산), `:114-118`(lot unit 갱신) |

**FR Rate: 13/13** ✅

---

## 5. Gap 목록 (확신도 ≥80%)

Critical: **0건** / Important: **0건** / Minor: **0건** (1차 분석 시 3건 → 사용자 결정에 따라 전부 해소)

| # | 1차 분석 갭 (Minor) | 해소 내역 |
|---|------|------|
| 1 | L1 테스트가 §8.2 #5(개봉 검증 오류)·#10(전량 파손) 미커버 | ✅ 개봉 트랜잭션을 `lib/unit_helper.php`의 `lc_execute_box_break()`로 추출 (Design §9 import 규칙 준수), ajax와 테스트가 동일 경로 사용. Test 9·10 추가 — 33/33 PASS |
| 2 | order_new Category 컬럼 항상 '-' (기존 버그) | ✅ 쿼리에 `c.name_en AS category` 추가 — 실 DB 검증 통과 |
| 3 | Design §5.4 "리스트 3종" vs 구현 범위 문서 불일치 | ✅ Design §5.4에 `inbound.php` 의도적 제외 사유 명시 (배치 요약 — 합계는 단위 그대로 유효) |

**기각된 갭 (검증 후 무효)**:
- ~~box_break.php에서 header.php를 auth 이전에 require~~ → `partials/header.php:4-5`가 자체적으로 `lc_require_login()` 수행. header → `lc_require_staff()` 순서는 `inbound.php`·`inventory.php` 등 프로젝트 전체 기존 컨벤션으로, 본 기능이 도입한 갭 아님.

---

## 6. Runtime Verification Plan (잔여)

| Level | 항목 | 상태 |
|-------|------|:----:|
| L1 | unit_helper / 개봉(정상·오류·전량파손) / FEFO / 유효단가 | ✅ 33/33 PASS (서버 실행 완료, §8.2 #1~#10 전부) |
| L2 | 토글·행 단위·PCS원가 실시간 계산·재고 배지 (수동 체크리스트, Design §8.3) | ⏳ 사용자 수동 확인 필요 |
| L3 | 혼합 입고 → 개봉 → 출고 E2E 4 시나리오 (Design §8.4) | ⏳ 사용자 수동 확인 필요 |

> PHP 멀티페이지 앱 + Playwright 미사용 환경 — Design §8 결정대로 L2/L3는 수동 수행.

---

## 7. 권장 조치

1. ~~L1 테스트 §8.2 #5·#10 추가~~ ✅ 완료 (33/33 PASS)
2. ~~order_new Category 표시 수정~~ ✅ 완료
3. **잔여**: L3 수동 시나리오 4건(Design §8.4) 실행 후 `/pdca report box-pcs-unit` 진행

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 1.0 | 2026-06-11 | 초기 갭 분석 — Match Rate 96%, Critical/Important 0건, Minor 3건 | gap-detector + Claude |
| 1.1 | 2026-06-11 | Minor 3건 전부 해소 (개봉 로직 lib 추출 + 테스트 보강 33/33, category 수정, 문서 정리) — **Match Rate 98%** | Claude |
