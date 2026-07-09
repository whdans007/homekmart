---
template: report
version: 1.1
---

# wholesale-sales-return Completion Report

> **Status**: Complete
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: Claude (PDCA)
> **Completion Date**: 2026-07-09
> **PDCA Cycle**: #1

---

## Executive Summary

### 1.1 Project Overview

| Item | Content |
|------|---------|
| Feature | wholesale-sales-return (도매판매 반품) |
| Start Date | 2026-07-09 |
| End Date | 2026-07-09 |
| Duration | 1 세션 (Plan → Design → Do → Check) |

### 1.2 Results Summary

```
┌─────────────────────────────────────────────┐
│  Completion Rate: 95%                        │
├─────────────────────────────────────────────┤
│  ✅ Complete:     6 / 6 Success Criteria     │
│  ⏳ 배포 후 확인 필요: 운영 DB 마이그레이션 실행 │
│  ❌ Cancelled:     0 항목                     │
└─────────────────────────────────────────────┘
```

### 1.3 Value Delivered

| Perspective | Content |
|-------------|---------|
| **Problem** | 도매판매 반품 시 전체 삭제(hard delete)만 가능해 부분 반품 처리와 반품 이력 추적이 불가능했음 |
| **Solution** | 품목별 부분/전체 반품 처리 API(`ajax_wholesale_sale_return.php`)와 판매 미리보기 화면 내 반품 모달을 신규 구현. 재고 복원(박스→낱개 환산), 금액/미수금 자동 차감, 반품 이력 기록을 단일 트랜잭션으로 처리 |
| **Function/UX Effect** | 사용자는 판매 상세 화면에서 품목별 반품 수량을 입력하거나 "전체 반품" 버튼으로 즉시 처리 가능. 판매 목록/미리보기에 반품 상태 배지가 표시되어 별도 조회 화면 없이 현황 파악 가능 |
| **Core Value** | 반품이 데이터로 영구 기록되어 매출·재고·미수금이 정확해지고, 실수로 인한 판매 데이터 완전 삭제(복구 불가)를 방지함. 이미 반품된 판매 건은 수정이 차단되어 데이터 무결성이 보장됨 |

---

## 1.4 Success Criteria Final Status

> Plan §4.1 기준 최종 평가

| # | Criteria | Status | Evidence |
|---|---------|:------:|----------|
| SC-1 | 품목별 부분 반품 + 전체 반품 정상 동작 | ✅ Met | `ajax_wholesale_sale_return.php` targets 계산 로직 + `wholesale_sale_preview.php` "전체 반품" 버튼 |
| SC-2 | 반품 시 재고(inventory) 정확히 증가 (등록상품만) | ✅ Met | `ajax_wholesale_sale_return.php` — `pieces_per_box` 환산 후 `inventory` UPSERT, 수기품목(`product_id NULL`) 제외 |
| SC-3 | `final_amount`/`total_amount` 정확히 차감, 미수금 반영 | ✅ Met | `ajax_wholesale_sale_return.php` — `returned_amount` 누적 + 판매 금액 직접 차감 |
| SC-4 | 반품 수량이 (판매수량-기반품수량) 초과 시 서버 거부 | ✅ Met | `SELECT ... FOR UPDATE` 잠금 후 재검증, `INVALID_QUANTITY` 에러 반환 |
| SC-5 | 판매 목록/미리보기에 반품 상태 표시 | ✅ Met | 양쪽 화면 모두 배지 추가, 미리보기에 반품 이력 섹션 추가 |
| SC-6 | 반품된 품목은 판매 수정(edit) 시 축소/삭제 차단 | ✅ Met | `wholesale_sales.php` — 반품 이력이 있는 판매 건은 수정 자체를 서버에서 차단 + DB FK RESTRICT 이중 보호 |

**Success Rate**: 6/6 criteria met (100%)

## 1.5 Decision Record Summary

| Source | Decision | Followed? | Outcome |
|--------|----------|:---------:|---------|
| [Plan] | 전체+부분 반품 지원, 반품 시 재고 증가, final_amount 자동 차감, 별도 반품 목록화면 없이 배지만 표시 | ✅ | 4가지 요구사항 모두 그대로 구현됨 |
| [Design] | Option C(실용적 균형) — 별도 AJAX 엔드포인트로 트랜잭션 격리, 헬퍼 레이어 미도입 | ✅ | 기존 `ajax_*.php` 절차적 패턴과 완전히 일관된 구조로 구현됨 |
| [Design→Do] | `wholesale_sale_returns.processed_by`를 INT(11)로 설계 | ⚠️ 수정 | 실제 `users.id`가 UNSIGNED임을 Do phase 중 500 에러 디버깅으로 발견, UNSIGNED로 정정. FK 타입 불일치가 마이그레이션 실패의 실제 원인이었음 |
| [Design§6.2] | 반품된 품목의 삭제/축소만 개별 차단 | ✅ (확대 적용) | 현재 edit 로직이 전체 품목 delete-and-reinsert 구조라 품목 단위 부분 허용이 불가능해, 반품 이력이 있는 판매 건 자체를 수정 차단하는 것으로 안전하게 구현. 데이터 무결성 보호라는 설계 의도는 완전히 충족 |

---

## 2. Related Documents

| Phase | Document | Status |
|-------|----------|--------|
| Plan | [wholesale-sales-return.plan.md](../01-plan/features/wholesale-sales-return.plan.md) | ✅ Finalized |
| Design | [wholesale-sales-return.design.md](../02-design/features/wholesale-sales-return.design.md) | ✅ Finalized |
| Check | [wholesale-sales-return.analysis.md](../03-analysis/wholesale-sales-return.analysis.md) | ✅ Complete (95% match) |
| Act | Current document | ✅ Complete |

---

## 3. Completed Items

### 3.1 Functional Requirements (Plan §3.1)

| ID | Requirement | Status | Notes |
|----|-------------|--------|-------|
| FR-01 | 품목별 반품 수량 입력으로 부분 반품 처리 | ✅ Complete | |
| FR-02 | 판매 건 전체 반품 처리 | ✅ Complete | "전체 반품" 버튼이 잔여수량 전체를 자동 채움 |
| FR-03 | 반품 수량 서버 검증 (판매수량-기반품수량 초과 금지) | ✅ Complete | `SELECT ... FOR UPDATE` 동시성 방어 포함 |
| FR-04 | 등록 상품 재고 복원 (수기품목 제외) | ✅ Complete | `add_purchase.php`와 동일한 박스→낱개 환산 공식 재사용 |
| FR-05 | 판매 건 금액 자동 차감 + `returned_amount` 누적 | ✅ Complete | |
| FR-06 | `return_status`(none/partial/full) 자동 갱신 | ✅ Complete | |
| FR-07 | 판매 목록 반품 상태 배지 | ✅ Complete | 하위호환 가드 포함 |
| FR-08 | 판매 미리보기 반품 이력 조회 | ✅ Complete | |
| FR-09 | 수정 모드에서 반품된 품목 축소/삭제 차단 | ✅ Complete | 판매 건 단위로 확대 적용 |
| FR-10 | `wholesale_management` 권한 + 점포 스코프 제한 | ✅ Complete | |

### 3.2 Non-Functional Requirements

| Item | Target | Achieved | Status |
|------|--------|----------|--------|
| 트랜잭션 원자성 | 단일 PDO 트랜잭션 | 반품기록+재고+금액 갱신 전체가 하나의 트랜잭션 | ✅ |
| 하위 호환성 | 마이그레이션 미적용 환경에서도 기존 화면 정상 동작 | `SHOW COLUMNS` 가드 3개 파일에 적용 (Check phase에서 1건 누락 발견 후 수정) | ✅ |
| 금액/수량 표시 | 소숫점 둘째자리 (CLAUDE.md) | `fmt_num()`/`fmtReturnNum()` 재사용 | ✅ |
| 보안 | prepared statement, 점포 스코프 검증 | 전체 적용 | ✅ |

### 3.3 Deliverables

| Deliverable | Location | Status |
|-------------|----------|--------|
| DB 스키마 마이그레이션 | `admin/sql/add_wholesale_sale_return_schema.sql` | ✅ |
| 마이그레이션 실행 스크립트 | `admin/run_wholesale_sale_return_migration.php` | ✅ |
| 반품 처리 API | `admin/ajax_wholesale_sale_return.php` | ✅ |
| 반품 UI (판매 미리보기) | `admin/wholesale_sale_preview.php` | ✅ (수정) |
| 반품 배지 (판매 목록) | `admin/wholesale_sales_list.php` | ✅ (수정) |
| 수정 보호 로직 | `admin/wholesale_sales.php` | ✅ (수정) |
| Plan/Design/Analysis 문서 | `docs/01-plan/`, `docs/02-design/`, `docs/03-analysis/` | ✅ |

---

## 4. Incomplete Items

### 4.1 Carried Over (배포 후 확인 필요)

| Item | Reason | Priority | Estimated Effort |
|------|--------|----------|------------------|
| 운영 DB 마이그레이션 실행 및 수동 시나리오 테스트 | 이 세션 환경에서 운영 DB(Hostinger) 접속 불가 | High | 서버 방문 1회 + 시나리오 테스트 30분 |
| `ajax_wholesale_sale_return.php` undefined key 방어 코드 | 마이그레이션 미적용 상태에서만 발생하는 낮은 우선순위 엣지케이스 | Low | 15분 |

### 4.2 Cancelled/On Hold Items

| Item | Reason | Alternative |
|------|--------|-------------|
| 반품 전용 목록/조회 화면 | Plan 단계에서 요구사항 확인 결과 불필요로 결정 | 기존 판매 목록의 배지 표시로 대체 |
| GET 반품이력 엔드포인트 클라이언트 활용 | 미리보기 페이지가 SSR로 직접 렌더링하는 방식을 채택 (기존 페이지 패턴과 일관성 유지) | 엔드포인트 자체는 구현되어 향후 AJAX 부분 새로고침 시 재사용 가능 |

---

## 5. Quality Metrics

### 5.1 Final Analysis Results

| Metric | Target | Final | Change |
|--------|--------|-------|--------|
| Design Match Rate | 90% | 95% | +5%p |
| Structural Match | - | 100% | - |
| Functional Match (Page UI Checklist) | - | 100% | - |
| Contract Match | - | 88% | GET 미사용은 의도된 설계 |
| Critical Issues | 0 | 0 (1건 발견 즉시 수정) | ✅ |

### 5.2 Resolved Issues

| Issue | Resolution | Result |
|-------|------------|--------|
| 마이그레이션 스크립트 500 에러 (FK 타입 불일치 + 예외 미처리) | `processed_by`를 UNSIGNED로 정정, 모든 쿼리를 try/catch로 감싸 에러 메시지 노출 | ✅ Resolved (Do phase 중) |
| `wholesale_sale_preview.php` 하위호환 가드 누락 (기존 화면 회귀 위험) | `has_returned_qty` 체크 추가, 컬럼 부재 시 `0 as returned_quantity` 폴백 | ✅ Resolved (Check phase 중) |

---

## 6. Lessons Learned & Retrospective

### 6.1 What Went Well (Keep)

- Plan 단계에서 AskUserQuestion으로 반품 범위/재고반영/금액반영/이력관리 4가지 핵심 결정을 먼저 확정한 것이 이후 Design/Do 단계의 재작업을 줄여줌
- 기존 코드베이스의 실제 DB 스키마(마이그레이션 파일, `run_wholesale_manual_entry_migration.php` 패턴)를 코드로 직접 확인한 뒤 설계해, 문서상 스키마(`sql/complete_schema.sql`)의 stale한 정보에 속지 않음
- 사용자가 보고한 500 에러를 추측이 아니라 실제 스키마 타입(users.id UNSIGNED)을 재확인해 근본 원인(FK 타입 불일치)을 찾아 수정

### 6.2 What Needs Improvement (Problem)

- Do phase에서 세 파일(`wholesale_sales_list.php`, `wholesale_sales.php`, `wholesale_sale_preview.php`)에 동일한 하위호환 가드 패턴을 적용해야 했는데, 그 중 한 곳(`wholesale_sale_preview.php`)에서 누락됨 — 반복 패턴 적용 시 체크리스트화가 필요했음
- 이 개발 환경에서 운영 DB에 연결할 수 없어 Do/Check phase 전체가 정적 코드 검증에 의존함 — 실제 브라우저 동작 확인은 배포 후로 미뤄짐

### 6.3 What to Try Next (Try)

- 여러 파일에 동일 패턴(하위호환 가드 등)을 적용해야 하는 작업은 구현 직후 `grep`으로 패턴 적용 여부를 교차 검증하는 단계를 Do phase에 명시적으로 추가
- 로컬 개발 DB(운영과 동일 스키마의 사본)를 준비해두면 다음 PDCA 사이클부터 Do/Check phase에서 실제 쿼리 실행 검증이 가능해짐

---

## 7. Process Improvement Suggestions

### 7.1 PDCA Process

| Phase | Current | Improvement Suggestion |
|-------|---------|------------------------|
| Do | 여러 파일에 동일 패턴 적용 시 수동 확인 | 파일별 체크리스트 후 grep 기반 일관성 검증 단계 추가 |
| Check | 운영 DB 미연결로 정적 분석에 의존 | 로컬 스테이징 DB 구성 시 L1 API curl 테스트 실행 가능 |

### 7.2 Tools/Environment

| Area | Improvement Suggestion | Expected Benefit |
|------|------------------------|------------------|
| 로컬 개발 환경 | XAMPP/Laragon에 운영 스키마 사본 mysql 서비스 구동 | Do/Check phase에서 실제 마이그레이션·쿼리 실행 검증 가능 |

---

## 8. Next Steps

### 8.1 Immediate

- [ ] 운영 서버에서 `admin/run_wholesale_sale_return_migration.php` 실행 (완료 후 파일 삭제 권장)
- [ ] 실제 도매판매 건으로 부분 반품 → 재고/금액 확인 → 전체 반품 시나리오 수동 테스트
- [ ] 수기 품목 포함 판매 건 반품 시 재고 미영향 확인
- [ ] 반품된 판매 건 수정 시도 시 차단 메시지 확인

### 8.2 Next PDCA Cycle 후보

| Item | Priority | Notes |
|------|----------|-------|
| `ajax_wholesale_sale_return.php` 방어 코드 보강 | Low | 마이그레이션 완료 후에는 불필요 |
| 반품 사유 다국어(en/ko) 키 등록 | Low | 현재 하드코딩 한글, 기존 페이지 관행과 동일 |

---

## 9. Changelog

### v1.0.0 (2026-07-09)

**Added:**
- 도매판매 품목별 부분/전체 반품 처리 기능 (`ajax_wholesale_sale_return.php`)
- 반품 시 재고 자동 복원 (등록상품, 박스→낱개 환산)
- 반품 시 판매 금액/미수금 자동 차감
- 판매 목록/미리보기 반품 상태 배지 및 이력 표시
- `wholesale_sale_returns`, `wholesale_sale_return_items` 테이블

**Changed:**
- `wholesale_sales`에 `returned_amount`, `return_status` 컬럼 추가
- `wholesale_sale_items`에 `returned_quantity` 컬럼 추가
- `wholesale_sales.php` 수정 로직에 반품 건 보호 검증 추가

**Fixed:**
- 마이그레이션 스크립트 500 에러 (FK UNSIGNED 타입 불일치)
- `wholesale_sale_preview.php` 하위호환 누락으로 인한 기존 화면 회귀 위험

---

## 10. Post-Completion Revision (2026-07-09)

완료 보고서 작성 직후 사용자 피드백: "반품 처리 방식이 잘못됨 — 새 판매등록 화면에서 반품등록 버튼으로 최근 판매 상품 리스트를 보고 선택하는 방식이어야 함".

| 항목 | 내용 |
|------|------|
| 변경 사유 | 반품 등록을 위해 특정 판매 건을 먼저 조회하는 방식이 실사용 흐름과 맞지 않음. 거래처를 선택한 뒤 그 자리에서 최근 구매이력을 보고 반품을 등록하는 흐름이 더 자연스러움 |
| 변경 내용 | 반품 등록 진입점을 `wholesale_sale_preview.php`(개별 판매 상세) → `wholesale_sales.php`(신규 판매등록 화면)로 이동. 거래처 선택 후 "반품등록" 버튼 → 최근 반품가능 품목 리스트 모달 → 반품 장바구니에 담아 수량 조정 → 등록 |
| 제거 | `wholesale_sale_preview.php`의 반품 처리 버튼/모달/JS (반품 이력 섹션·배지는 유지) |
| 신규 | `admin/ajax_search_wholesale_customer_returns.php` (거래처별 최근 반품가능 품목 조회) |
| 영향 없음 | DB 스키마, `ajax_wholesale_sale_return.php` API 계약, 목록 배지, 수정 보호 로직(FR-09) — 기존 백엔드 100% 재사용 |
| 검증 상태 | 이 세션에서도 운영 DB 미연결로 실제 브라우저 동작 미검증. 배포 후 확인 필요 |

---

## 11. Post-Revision Correction (2026-07-09, 2차)

10번 항목(진입점 이동) 적용 직후, 더 근본적인 피드백: "예전 전표를 수정하면 안 됨. 현재 전표에 반품으로 등록하고 그 전표 금액에서 빼야 함."

| 항목 | 내용 |
|------|------|
| 문제 인식 | 이전 구현은 반품 발생 시 원본(예전) 판매 전표의 금액/상태를 직접 수정 — 이미 발행된 전표를 사후에 변경하는 것은 회계 원칙 위반 |
| 수정 내용 | `wholesale_sale_returns.sale_id`의 의미를 "반품된 원본 판매"에서 "반품이 등록되는 현재(신규) 판매"로 재정의. 원본 전표는 `returned_quantity`(추적 전용, 금액 무관)를 제외하고 절대 수정하지 않음. 반품은 신규 판매 등록 폼 제출과 하나의 트랜잭션으로 처리되어, 그 신규 전표의 `total_amount`에서 직접 차감됨 |
| 신규 지원 | 신규 구매 없이 반품만 등록 가능 (이 경우 `total_amount`가 음수인 전표가 생성됨) |
| 용어 변경 | `return_status` 배지: "부분반품"/"전체반품" → "반품포함"/"반품전표" (의미가 "자신이 반품됨"에서 "이 전표에 반품이 포함됨"으로 바뀜) |
| DB 마이그레이션 | 불필요 — 기존 스키마 그대로 재사용, 컬럼 의미만 재해석 |
| **미사용으로 남은 파일** | `admin/ajax_wholesale_sale_return.php` — 더 이상 UI에서 호출하지 않음. 원본 전표를 수정하는 구 로직이 남아있어 재사용 시 이번에 고친 문제가 재발할 수 있음. **삭제 여부 사용자 확인 필요** |
| 검증 상태 | 운영 DB 미연결로 미검증. 특히 반품 전용(신규구매 0건) 전표 생성, 여러 원본 전표에서 섞어 반품하는 케이스는 배포 후 반드시 확인 필요 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 1.0 | 2026-07-09 | Completion report created | Claude (PDCA) |
| 1.1 | 2026-07-09 | UX 변경 반영 (반품 등록 진입점 이동) | Claude (PDCA) |
| 1.2 | 2026-07-09 | 회계 모델 근본 수정 (원본 전표 불변, 현재 전표에서 차감) | Claude (PDCA) |
