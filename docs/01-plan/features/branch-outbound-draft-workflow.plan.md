# Plan: 지점출고 임시저장(Draft) 워크플로우

**Feature**: branch-outbound-draft-workflow
**Status**: Plan
**Created**: 2026-06-11
**Owner**: Logistics Team

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 현재 지점출고는 작성 즉시 출고(재고 차감)되어 중간 저장·검토·수정이 불가능하다. 작성 중 새로고침하면 입력 내용이 전부 유실된다. |
| **Solution** | 출고 등록을 "저장(출고대기)" → "수정" → "최종 출고" 2단계 워크플로우로 분리한다. 저장 시점에는 재고를 차감하지 않고, 최종 출고 시점에 FEFO 차감을 실행한다. |
| **Function UX Effect** | 별도 출고대기 목록 페이지에서 저장된 출고 건을 확인·수정·삭제할 수 있고, 목록과 수정 화면 양쪽에서 최종 출고를 실행할 수 있다. |
| **Core Value** | 출고 실수 방지(검토 단계 확보) + 작성 데이터 유실 방지 + 출고 작업의 분업 가능(작성자/승인자 분리) |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 즉시 출고 방식은 검토 단계가 없어 실수가 그대로 재고에 반영되고, 작성 중 데이터 유실 위험이 있음 |
| **WHO** | 물류센터 직원 (출고 작성/수정/최종 출고) |
| **RISK** | draft 저장 후 최종 출고 사이에 재고가 변동될 수 있음 → 최종 출고 시점 재고 기준으로 FEFO 차감 |
| **SUCCESS** | ✅ draft 저장/수정/삭제 ✅ 출고대기 목록 페이지 ✅ 최종 출고 시에만 재고 차감 |
| **SCOPE** | branch_outbound.php 수정 + 출고대기 목록 페이지 신규 + AJAX API 확장 (기존 즉시출고 데이터/주문내역 구조는 유지) |

---

## 1. 문제 정의

### 1.1 현재 방식 (AS-IS)
```
지점 선택 → 상품 스캔/검색 (브라우저 메모리에만 존재) → [출고 등록]
→ 즉시 lc_orders(status='shipped') 생성 + FEFO 재고 차감
```

### 1.2 문제점
- 작성 중간 저장 불가 → 새로고침/이탈 시 입력 내용 전부 유실
- 출고 전 검토/수정 단계 없음 → 실수가 그대로 재고에 반영
- 출고 취소/롤백 기능 없음 (잘못 출고하면 수작업 복구)

### 1.3 변경 방식 (TO-BE)
```
[출고등록] → 지점 선택 + 상품 스캔/검색 → [저장]
→ lc_orders(status='draft') 저장, 재고 차감 없음
→ 출고대기 목록에서 확인 / 수정 / 삭제
→ [최종 출고] (목록 또는 수정 화면) → 그 시점에 FEFO 차감 + status='shipped'
```

---

## 2. 기능 요구사항

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| FR-1 | 출고 작성 후 [저장] 시 `lc_orders`에 status='draft'로 저장 (재고 차감 없음) | Must |
| FR-2 | 출고대기 목록 페이지 신규 (`branch_outbound_list.php`) — draft 건 목록/검색 | Must |
| FR-3 | draft 건 수정 가능 — 지점, 품목 추가/제거, 수량 변경 (branch_outbound.php 재사용, edit 모드) | Must |
| FR-4 | draft 건 삭제 가능 (확인 후 삭제, shipped 건은 삭제 불가) | Must |
| FR-5 | [최종 출고] — 목록과 수정 화면 양쪽에서 실행 가능. 실행 시점에 FEFO 차감(음수 허용) + status='shipped' + unit_price/total_amount 확정 | Must |
| FR-6 | draft 저장 시 unit_price는 예상가(평균원가)로 기록, 최종 출고 시 실제 차감 기준으로 재계산 | Must |
| FR-7 | 최종 출고된 건은 수정/삭제 불가 (기존 order_detail.php로 연결) | Must |
| FR-8 | 최종 출고 전 확인 다이얼로그 (목록에서 바로 출고할 때도 품목 수 표시) | Should |

---

## 3. 제약사항

- 기존 `lc_orders` / `lc_order_items` / `lc_order_item_lots` 테이블 구조 재사용 (status 값만 'draft' 추가)
- 기존 orders.php(주문내역)와 order_detail.php는 shipped 건 기준으로 동작 유지 — draft 건이 기존 목록에 섞이지 않도록 status 필터 확인 필요
- FEFO 차감 로직(`lc_fifo_ship_allow_negative`)은 변경 없이 재사용
- 음수 재고 허용 정책 유지

---

## 4. Success Criteria

| ID | 기준 | 측정 방법 |
|----|------|----------|
| SC-1 | 저장 시 재고가 차감되지 않는다 | draft 저장 후 lc_inventory.quantity_out 변동 없음 확인 |
| SC-2 | 출고대기 목록에서 draft 건 조회 가능 | branch_outbound_list.php에서 목록 표시 |
| SC-3 | draft 수정 시 품목/수량/지점 변경이 저장된다 | 수정 후 재조회 일치 |
| SC-4 | draft 삭제 가능, shipped 삭제 불가 | 삭제 후 목록에서 제거 / shipped 삭제 시 거부 |
| SC-5 | 최종 출고 시 FEFO 차감 + status='shipped' 전환 | lc_order_item_lots 기록 + 재고 차감 확인 |
| SC-6 | 최종 출고는 목록/수정 화면 양쪽에서 실행 가능 | 두 경로 모두 동작 확인 |
| SC-7 | 기존 주문내역(orders.php)에 draft 건이 섞이지 않는다 | status 필터 확인 |

---

## 5. 리스크 관리

| 리스크 | 영향 | 대응 |
|--------|------|------|
| draft 저장~최종 출고 사이 재고 변동 | 예상가/피킹 정보와 실제 차감 결과가 달라질 수 있음 | 최종 출고 시점 기준으로 FEFO 재계산, 수정 화면에서 최신 재고 표시 |
| 동시 최종 출고 (중복 클릭/두 사용자) | 이중 차감 | 최종 출고 시 `status='draft'` 조건부 UPDATE로 1회만 처리 보장 |
| 기존 orders.php에 draft 노출 | 주문내역 오염 | orders.php 쿼리에 status 필터 점검·보강 |
| draft 방치 누적 | 목록 비대 | 목록에서 생성일 표시 + 삭제 기능으로 정리 가능 |

---

## 6. 구현 전략 (개요)

1. **Backend**: `ajax/branch_outbound.php`에 action 추가 — `save_draft`, `update_draft`, `delete_draft`, `ship_draft`, `get_draft`
2. **목록 페이지**: `branch_outbound_list.php` 신규 — draft 목록 + [수정] [최종 출고] [삭제] 버튼
3. **작성/수정 페이지**: `branch_outbound.php` 확장 — `?draft_id=N` 파라미터로 edit 모드, 버튼을 [저장] + [최종 출고]로 변경
4. **기존 영향 점검**: orders.php의 status 필터 확인
