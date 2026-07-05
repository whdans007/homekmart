# Design: 지점출고 임시저장(Draft) 워크플로우

**Feature**: branch-outbound-draft-workflow
**Architecture**: Option C — Pragmatic Balance
**Status**: Design
**Created**: 2026-06-11
**Owner**: Logistics Team
**Plan Ref**: docs/01-plan/features/branch-outbound-draft-workflow.plan.md

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 즉시 출고 방식은 검토 단계가 없어 실수가 그대로 재고에 반영되고, 작성 중 데이터 유실 위험이 있음 |
| **WHO** | 물류센터 직원 (출고 작성/수정/최종 출고) |
| **RISK** | draft 저장 후 최종 출고 사이에 재고가 변동될 수 있음 → 최종 출고 시점 재고 기준으로 FEFO 차감 |
| **SUCCESS** | ✅ draft 저장/수정/삭제 ✅ 출고대기 목록 페이지 ✅ 최종 출고 시에만 재고 차감 |
| **SCOPE** | branch_outbound.php 수정 + 출고대기 목록 페이지 신규 + AJAX API 확장 |

---

## 1. 아키텍처 개요

기존 코드베이스 패턴(절차식 PHP 페이지 + AJAX action dispatch) 유지.
신규 클래스 없이 기존 헬퍼 함수(`lc_fifo_ship_allow_negative`)를 재사용한다.

```
┌──────────────────────────────────────────────────┐
│ UI Layer                                         │
│  - branch_outbound_list.php (신규: 출고대기 목록) │
│  - branch_outbound.php (수정: 저장 버튼 +        │
│      ?draft_id=N edit 모드 + 최종출고 버튼)       │
├──────────────────────────────────────────────────┤
│ AJAX Layer — ajax/branch_outbound.php            │
│  기존: get_stores / get_product_stock /          │
│        get_fefo_preview / submit(유지·미사용)     │
│  신규: save_draft / get_draft / update_draft /   │
│        delete_draft / ship_draft / list_drafts   │
├──────────────────────────────────────────────────┤
│ Data Layer (기존 테이블 재사용)                   │
│  - lc_orders (status='draft' 추가 활용)          │
│  - lc_order_items                                │
│  - lc_order_item_lots (최종 출고 시에만 기록)     │
│  - lc_inventory (최종 출고 시에만 차감)           │
└──────────────────────────────────────────────────┘
```

---

## 2. 데이터 모델

### 2.1 스키마 변경: 없음
기존 `lc_orders.status`(VARCHAR)에 `'draft'` 값을 추가로 사용한다.

### 2.2 상태 흐름

```
[저장]            [최종 출고]
  │                  │
  ▼                  ▼
draft ──수정/삭제──▶ shipped (이후 수정/삭제 불가, 기존 흐름과 동일)
```

### 2.3 데이터 기록 시점

| 시점 | lc_orders | lc_order_items | lc_order_item_lots | lc_inventory |
|------|-----------|----------------|--------------------|--------------|
| 저장(draft) | status='draft', total_amount=예상가 합계 | 기록 (unit_price=예상 평균원가) | ❌ 없음 | ❌ 차감 없음 |
| 수정(update) | notes/store_id 갱신 | 전체 삭제 후 재삽입 (단순·안전) | ❌ | ❌ |
| 삭제(delete) | 행 삭제 | CASCADE 또는 명시 삭제 | ❌ | ❌ |
| 최종출고(ship) | status='shipped', shipped_at=NOW(), total_amount 확정 | unit_price 재계산 갱신 | ✅ 기록 | ✅ FEFO 차감 |

---

## 3. 핵심 로직

### 3.1 save_draft (POST)
```
입력: store_id, notes, items[{product_id, quantity}]
검증: store_id > 0, items 1개 이상, qty > 0
처리:
  1. INSERT lc_orders (status='draft', order_date=today, created_by=uid)
  2. 각 item: 평균원가 조회(get_product_stock와 동일 쿼리) → unit_price 예상가로 INSERT lc_order_items
  3. total_amount = Σ(qty × 예상 unit_price)
출력: { success, draft_id }
```

### 3.2 update_draft (POST)
```
입력: draft_id, store_id, notes, items[]
검증: 해당 order가 존재하고 status='draft'인지 확인 (아니면 거부)
처리(트랜잭션):
  1. UPDATE lc_orders SET store_id, notes, total_amount
  2. DELETE FROM lc_order_items WHERE order_id=?  → 재삽입
출력: { success }
```

### 3.3 delete_draft (POST)
```
검증: status='draft'만 삭제 허용 (Plan FR-4, shipped 삭제 불가)
처리(트랜잭션): DELETE lc_order_items → DELETE lc_orders
출력: { success }
```

### 3.4 ship_draft (POST) — 동시성 핵심
```
처리(트랜잭션):
  1. UPDATE lc_orders SET status='shipping_lock'... ❌ 대신:
     UPDATE lc_orders SET status='shipped', shipped_at=NOW()
     WHERE id=? AND status='draft'
     → affected_rows=0 이면 "이미 출고되었거나 존재하지 않음" 응답 (이중 출고 방지)
  2. lc_order_items 조회 → 각 품목 lc_fifo_ship_allow_negative() 호출 (FEFO, 음수 허용)
  3. 실제 차감 기준으로 unit_price 재계산 → lc_order_items UPDATE
  4. lc_order_item_lots INSERT
  5. total_amount 확정 UPDATE
출력: { success, order_id }
```

### 3.5 get_draft (GET)
```
입력: draft_id
출력: { success, draft: {id, store_id, notes, items:[{product_id, name, unit, quantity}]} }
용도: branch_outbound.php?draft_id=N 진입 시 장바구니 복원
```

---

## 4. API 명세 (ajax/branch_outbound.php)

| Action | Method | 입력 | 출력 | 비고 |
|--------|--------|------|------|------|
| save_draft | POST | csrf, store_id, notes, items(JSON) | {success, draft_id} | 재고 차감 없음 |
| get_draft | GET | draft_id | {success, draft} | draft만 조회 가능 |
| update_draft | POST | csrf, draft_id, store_id, notes, items(JSON) | {success} | status='draft' 검증 |
| delete_draft | POST | csrf, draft_id | {success} | status='draft' 검증 |
| ship_draft | POST | csrf, draft_id | {success, order_id} | 조건부 UPDATE로 이중출고 방지 |
| submit (기존) | POST | — | — | 하위호환 유지 (UI에서 미사용) |

---

## 5. 페이지 설계

### 5.1 branch_outbound_list.php (신규 — 출고대기 목록)

레이아웃: inbound.php 패턴 준용 (flex h-full, 카드형 테이블, 페이지네이션)

```
┌─────────────────────────────────────────────────┐
│ 출고대기 목록                      [+ 출고등록]    │
├─────────────────────────────────────────────────┤
│ [지점검색▼] [날짜] [검색] [초기화]                 │
├─────────────────────────────────────────────────┤
│ # | 작성일 | 지점 | 품목수 | 예상금액 | 작성자 | 액션 │
│ 12| 06-11 | 강남점 | 5     | 1,250.00| 홍길동 |     │
│   [수정] [최종 출고] [삭제]                        │
├─────────────────────────────────────────────────┤
│              ← Prev 1 2 3 Next →                │
└─────────────────────────────────────────────────┘
```

- 쿼리: `lc_orders WHERE status='draft'` + 지점/날짜 필터
- [수정] → `branch_outbound.php?draft_id=N`
- [최종 출고] → confirm 다이얼로그(지점명·품목수 표시) → ship_draft 호출 → 성공 시 order_detail.php 이동
- [삭제] → confirm → delete_draft 호출 → 목록 갱신
- 빈 목록: "출고 대기 건이 없습니다" + 출고등록 버튼 안내

### 5.2 branch_outbound.php (수정 — 작성/수정 겸용)

```
모드 분기: $draft_id = (int)($_GET['draft_id'] ?? 0)
  - 신규 모드: 기존과 동일 (장바구니 비어있음)
  - 수정 모드: 페이지 로드 후 JS가 get_draft 호출 → 장바구니/지점/비고 복원

헤더 버튼 변경:
  AS-IS: [출고 등록] [취소]
  TO-BE: [저장] [최종 출고] [취소]
    - 저장: 신규 → save_draft / 수정 → update_draft → 성공 시 목록으로 이동
    - 최종 출고: 미저장 변경분 먼저 저장(save/update) 후 ship_draft 연속 호출
                → 성공 시 order_detail.php 이동
  뒤로가기 링크: orders.php → branch_outbound_list.php
```

### 5.3 기존 페이지 영향

| 파일 | 변경 |
|------|------|
| orders.php | status='all' 조회 시 `o.status <> 'draft'` 조건 추가 (SC-7) |
| order_detail.php | 변경 없음 (shipped 건만 진입) |
| 네비게이션(header) | 출고대기 목록 메뉴 링크 추가 검토 |

---

## 6. 권한

| 작업 | 권한 |
|------|------|
| draft 작성/수정/삭제/최종출고 | lc_require_staff() (기존 출고 권한과 동일) |

---

## 7. 에러 처리

| 시나리오 | 처리 |
|----------|------|
| 이미 출고된 draft를 다시 출고 | 조건부 UPDATE affected_rows=0 → "이미 출고 처리된 건입니다" |
| shipped 건 수정/삭제 시도 | status 검증 후 거부 메시지 |
| draft 품목의 상품이 비활성화됨 | get_draft 시 상품명 조회는 LEFT JOIN으로 유지, 출고는 정상 진행 |
| 네트워크 오류 | 버튼 재활성화 + 에러 배너 표시 (기존 패턴) |

---

## 8. Test Plan

### L1 — API
1. save_draft → lc_orders(status='draft') 생성 + 재고 미차감 확인 (SC-1)
2. update_draft → 품목 변경 반영 (SC-3)
3. delete_draft → draft 삭제 / shipped 삭제 거부 (SC-4)
4. ship_draft → 재고 차감 + status 전환 (SC-5)
5. ship_draft 2회 연속 호출 → 두 번째는 거부 (이중 출고 방지)

### L2 — UI
1. 작성 → 저장 → 목록에 표시 (SC-2)
2. 목록 [수정] → 장바구니 복원 확인
3. 목록/수정 화면 양쪽에서 최종 출고 (SC-6)

### L3 — E2E
1. 전체 흐름: 작성 → 저장 → 수정 → 최종 출고 → order_detail 확인 → orders.php에 shipped로 표시 + draft 미노출 (SC-7)

---

## 11. Implementation Guide

### 11.1 구현 순서
1. AJAX 액션 6개 (save/get/update/delete/ship/list)
2. branch_outbound_list.php 목록 페이지
3. branch_outbound.php 버튼/모드 수정
4. orders.php draft 필터

### 11.3 Session Guide

| Module | Scope Key | 내용 | 파일 | 예상 규모 |
|--------|-----------|------|------|----------|
| Module 1 | backend | AJAX 액션 추가 (save/get/update/delete/ship_draft) | ajax/branch_outbound.php | ~250줄 |
| Module 2 | list-page | 출고대기 목록 페이지 신규 | branch_outbound_list.php | ~250줄 |
| Module 3 | edit-mode | 작성 페이지 저장/수정/최종출고 버튼 + draft 복원 | branch_outbound.php | ~120줄 수정 |
| Module 4 | integration | orders.php draft 필터 + 동선 연결 | orders.php 외 | ~20줄 |

**권장 세션**: Module 1+2 (Session 1) → Module 3+4 (Session 2), 또는 한 세션에 전체 가능 (~650줄)
