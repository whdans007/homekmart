# Analysis (Check): POS 결제수단 상세 입력 (pos-payment-detail)

> Phase: Check (Gap Analysis) · 정적 분석 (서버/Playwright 미가용 → static-only)
> Date: 2026-06-24
> Plan: `docs/01-plan/features/pos-payment-detail.plan.md`
> Design: `docs/02-design/features/pos-payment-detail.design.md`

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 결제수단·현금 권종별 매출 정산 + Daybook 인쇄 |
| **WHO** | 매장 office 사용자(점장/캐셔) |
| **RISK** | 칸 합계 ↔ 상세 합계 불일치, 기존 보고서 회귀 |
| **SUCCESS** | 모달 입력/저장/복원, ₱100 우선 배분, Daybook 인쇄, 무회귀 |
| **SCOPE** | daily_entry 모달, ajax 핸들러, 5개 신규 테이블, 인쇄 |

---

## 1. Match Rate 요약

| 축 | 점수 | 가중치 | 비고 |
|----|------|--------|------|
| Structural (파일/구조) | 100% | 0.2 | 7개 파일 모두 존재, 마이그레이션 러너는 실행 후 의도적 삭제 |
| Functional (로직 깊이) | 92% | 0.4 | 핵심 로직 완성, 미결 설계판단 1건 + 동작 변경 1건 |
| Contract (API 계약) | 98% | 0.4 | 입력/응답 필드 일치 |
| **Overall** | **96%** | — | static-only: 0.2·S + 0.4·F + 0.4·C |

> 런타임 검증(L1/L2/L3)은 로컬 PHP/서버 부재로 미실행. 브라우저 수동 확인 권장(아래 §4).

---

## 2. Structural Match (100%)

| Design §4 파일 | 상태 | 증거 |
|----------------|------|------|
| `sql/create_pos_details.sql` | ✅ | 5개 테이블 DDL 존재, 마이그레이션 성공 확인됨 |
| `run_pos_details_migration.php` | ✅(삭제) | 실행 성공 후 보안상 삭제 (정상) |
| `lib/pos_recon_helper.php` | ✅ | 상수/배분/집계/프리로드 |
| `daily_entry.php` | ✅ | 셀 버튼 + 모달 + 프리로드 |
| `ajax_save_pos_cell.php` | ✅ | 트랜잭션 저장 |
| `ajax_wholesale_picklist.php` | ✅ | 후보 조회 |
| `print_daybook.php` | ✅ | Report v2 양식 |

DB 검증: 5개 테이블 생성 확인 (`sales_pos_cash_count/payment/expense/wholesale_pick/reconciliation`).

---

## 3. Functional Depth (92%)

### 구현 완료
- ₱100 우선 준비금 배분 알고리즘 (`pos_allocate_starting_money`) — 서버 권위 + JS 미러(프리뷰)
- 셀 단위 delete-then-insert + 정산 upsert + `sales_daily` 동시 갱신 (단일 트랜잭션, rollback)
- 모달 5개 섹션(Cash/Other/Whole Sale/Expenses/Reconciliation) + 실시간 재계산
- 프리로드 복원, Whole Sale 후보 ajax, Daybook 권종표 = 헬퍼 산출(일치)
- placeholder/TODO 없음

### Gap / 관찰

| # | 심각도 | 항목 | 내용 | 권고 |
|---|--------|------|------|------|
| G1 | Important | Daybook TOTAL AMOUNT 구성 (FR-19) | `total_amount`=현금+기타결제+홀세일. 그러나 종이엔 기타결제 미표시 → 화면상 합이 안 맞아 보일 수 있음 | **사용자 결정 대기**: (A) TOTAL=입금현금+홀세일(종이 표시분만) vs (B) 현재 셀 전체 Total. 결정 후 `print_daybook.php` 또는 정산 표시 조정 |
| G2 | Minor | 직접 금액 입력 제거 | 기존 daily_entry는 칸에 단일 금액 직접 입력 가능했으나, 이제 모달(권종/결제 상세)만 가능 | 의도된 재설계(Plan FR-1). 빠른 입력이 필요하면 차기 보완 |
| G3 | Minor | `ajax_save_sales.php` 유휴화 | 모달 경로로 대체되어 미사용(보존됨). 동일 `sales_daily` 컬럼 갱신이라 충돌 없음 | 차기 정리 대상(삭제는 회귀 확인 후) |
| G4 | Info | 금액 정밀도 | float 합산 후 round(2). 페소 단위 거래라 실무 영향 미미 | 유지 |

---

## 4. API Contract (98%)

| 계약 | Design §5 | 구현 | 일치 |
|------|-----------|------|------|
| `ajax_save_pos_cell` 입력 | sale_date, shift, pos_no, cash[], pay[i][], exp[i][], ws[i][], expected_cash | daily_entry `saveCell()` 동일 전송 | ✅ |
| `ajax_save_pos_cell` 응답 | 정산 요약(total_amount, over_short, shortage, …) | 동일 키 반환 → `renderGrid` 소비 | ✅ |
| `ajax_wholesale_picklist` | `{items:[{source_type,source_id,client,remark,amount}]}` | 동일 구조, `loadWholesale` 소비 | ✅ |
| `print_daybook` GET | date, shift, pos_no | `printCell()` 동일 전달 | ✅ |

---

## 5. Plan Success Criteria 평가

| SC | 상태 | 증거 |
|----|------|------|
| SC-1 6칸 모달 오픈 | ✅ Met | `daily_entry.php` openCell() / 셀 버튼 |
| SC-2 자동 합계 | ✅ Met | recalc() 실시간, 서버 pos_recalc_cell |
| SC-3 저장·복원 | ✅ Met | ajax 저장 + pos_preload_date 복원 |
| SC-4 칸=상세 합계 | ✅ Met | 서버가 total_amount 재계산 후 sales_daily 갱신 |
| SC-5 ₱100 우선+부족경고 | ✅ Met | pos_allocate_starting_money, shortage_flag |
| SC-6 Daybook 양식·정산 일치 | ⚠️ Partial | 양식 구현 완료, **G1(TOTAL 구성) 미결** |
| SC-7 기존 보고서 무회귀 | ✅ Met (정적) | sales_daily 스키마 무변경, 동일 컬럼만 갱신. 런타임 확인 권장 |
| SC-8 마이그레이션 | ✅ Met | 5개 테이블 생성 확인 |

**Met 7 / Partial 1 / Not Met 0 (8개 중)**

---

## 6. 런타임 확인 체크리스트 (수동)

브라우저 `http://main.homekmart.net/office/sales/daily_entry.php`:
- [ ] 셀 클릭 → 모달, 권종 입력 시 준비금/입금 실시간 분해(₱100 우선)
- [ ] 현금 < ₱10,000 시 부족 경고 표시
- [ ] Whole Sale 후보 로드 + 선택 → subtotal
- [ ] 저장 → 칸 배지(Total·과부족) + DAY TOTAL 갱신
- [ ] 재방문 시 모달 복원
- [ ] Daybook 인쇄 → STARTING/DEPOSIT 권종표 = 정산값 일치
- [ ] 월간/일일 기존 보고서 출력 무변화(회귀)

---

## 7. 결론

전체 정합도 **96%** (≥90% 통과 기준 충족). 구조·계약은 거의 완전 일치, 기능도 핵심 완성.
유일한 **Important 갭은 G1(Daybook TOTAL AMOUNT 구성)** — 레퍼런스 의도에 대한 사용자 결정이 필요하며, 결정되면 소규모 수정으로 SC-6를 Met로 전환 가능.
