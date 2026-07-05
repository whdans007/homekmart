# CD Batch Payment — Plan Document

**Feature**: cd-batch-payment
**Date**: 2026-05-12
**Method**: Plan Plus (Brainstorming-Enhanced)
**Architecture**: Option A — CD Source에 누적 카드 추가

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 매일 납품하는 업체를 날짜별 개별 등록 후 요청 시 한 번에 결제해야 하는데, 현재 CD는 하루치 데이터만 로드해 누적 합산 결제가 불가능함 |
| **Solution** | CD Source List에 "누적 미결제" 섹션 추가. 업체 카드를 드래그하면 해당 업체의 모든 미결제 Product Purchase가 1건으로 합산되어 CD에 등록됨 |
| **UX Effect** | 기존 워크플로우(매일 PP 등록 → CD 처리) 유지하면서 누적 결제 업체는 드래그 1번으로 일별 내역이 자동 정리된 1개 행으로 생성됨 |
| **Core Value** | 수작업 집계/계산 없이 5분 내 누적 거래처 결제 완료, 결제 완료 후 미결제 목록에서 자동 제외 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 매일 납품 업체의 주간/불정기 일괄 결제를 CD에서 자동 합산 처리 |
| **WHO** | 오피스 스태프 (Cash Disbursement 담당자) |
| **RISK** | is_cd_paid 플래그 관리 오류 시 이중 결제 또는 누락 가능 |
| **SUCCESS** | 누적 업체 카드 드래그 → 1건 합산 → 저장 → 미결제 목록 제거 완전 동작 |
| **SCOPE** | office/cash_disbursement/ 수정 (4파일) + DB 마이그레이션 1개 |

---

## 1. User Intent Discovery

### 1.1 핵심 문제
CD 등록 시 특정 업체의 여러 날 납품 내역을 1건으로 합산해서 등록해야 함.
예) A업체: May 1 ₱1,000 + May 2 ₱2,000 + May 3 ₱4,200 → 1건 ₱7,200으로 CD 등록

### 1.2 대상 사용자
오피스 스태프 (Cash Disbursement 화면 사용자)

### 1.3 성공 기준
- 누적 미결제 업체가 CD Source에 카드 형태로 표시됨
- 드래그 시 일별 내역이 details에 자동 생성됨 (`May 1: ₱1,000 / May 2: ₱2,000 ...`)
- CD 저장 시 해당 PP 항목들이 미결제 목록에서 사라짐
- CD에서 삭제하면 해당 PP 항목들이 다시 미결제로 복원됨

---

## 2. Alternatives Explored

| | Approach A (선택) | Approach B | Approach C |
|--|-----------------|-----------|-----------|
| **방식** | CD Source에 누적 카드 | PP 목록에 일괄결제 버튼 | 별도 거래처 잔액 페이지 |
| **변경 범위** | CD 화면 확장 | PP 화면 + CD 연동 | 신규 페이지 |
| **UX** | ★★★ (기존 흐름 유지) | ★★ (화면 분리) | ★ (화면 분산) |
| **선택 이유** | 기존 CD 드래그앤드롭 패턴 재사용, 학습 비용 없음 |

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| FR-01 | CD Source List에 "누적 미결제" 섹션 표시 | Must |
| FR-02 | 미결제 섹션에 업체별 카드 표시 (업체명, 총액, 건수, 날짜 범위) | Must |
| FR-03 | 업체 카드 드래그 시 모든 미결제 항목 1건으로 합산 | Must |
| FR-04 | details 필드에 일별 내역 자동 생성 (`May 1: ₱1,000 / ...`) | Must |
| FR-05 | CD 저장 시 batch_ids의 PP 항목 is_cd_paid=1 설정 | Must |
| FR-06 | CD에서 batch row 삭제 후 저장 시 is_cd_paid=0 복원 | Must |
| FR-07 | is_cd_paid=1 항목은 누적 미결제 목록에서 제외 | Must |

### 3.2 Non-Functional Requirements
- 기존 CD 기능 (오늘 납품 드래그앤드롭) 영향 없음
- 누적 목록 로드 성능: 1초 이내 (인덱스 활용)

---

## 4. YAGNI Review

### In Scope (v1)
- [x] 미결제 업체를 CD Source에 바로 표시
- [x] 드래그 시 details에 일별 내역 자동 생성
- [x] CD 저장 시 원본 PP 항목 is_cd_paid=1 변경
- [x] CD 삭제 시 is_cd_paid 복원 (undo)

### Out of Scope (v1 이후)
- 누적 결제 히스토리 조회 화면
- 업체별 결제 주기 설정
- 부분 결제 (일부 날짜만 선택)

---

## 5. Technical Design

### 5.1 DB 변경

```sql
-- office_product_purchases에 컬럼 추가
ALTER TABLE office_product_purchases
  ADD COLUMN is_cd_paid TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'CD 일괄결제 처리 여부',
  ADD INDEX idx_cd_paid (store_id, is_cd_paid);
```

### 5.2 CD Row 데이터 구조 확장

```json
{
  "id": "row_5",
  "is_batch": true,
  "batch_ids": ["p_3", "p_7", "p_12"],
  "or_si_no": "",
  "date": "2026-05-08",
  "supplier": "A업체",
  "details": "May 1: ₱1,000 / May 2: ₱2,000 / May 3: ₱4,200",
  "amount": 7200,
  "manual": false
}
```

### 5.3 파일별 변경 내용

| 파일 | 변경 유형 | 주요 변경 |
|------|---------|---------|
| `sql/add_cd_paid_col.sql` | 신규 | ALTER TABLE 마이그레이션 |
| `ajax_load_purchases.php` | 수정 | 누적 미결제 업체 조회 + 응답에 `accumulated` 배열 추가 |
| `ajax_save_cd.php` | 수정 | batch_ids 추출 → is_cd_paid 동기화 |
| `index.php` | 수정 | 누적 섹션 UI, 업체 카드, 드래그 핸들러 |

### 5.4 ajax_load_purchases.php 추가 쿼리

```sql
-- 미결제 누적 업체 조회 (store_id 기준, is_cd_paid=0)
SELECT supplier_name,
       COUNT(*) AS cnt,
       SUM(amount) AS total,
       MIN(payment_date) AS from_date,
       MAX(payment_date) AS to_date,
       GROUP_CONCAT(id ORDER BY payment_date) AS ids,
       GROUP_CONCAT(
         CONCAT(DATE_FORMAT(payment_date,'%b %e'),': ₱',FORMAT(amount,2))
         ORDER BY payment_date SEPARATOR ' / '
       ) AS details_str
FROM office_product_purchases
WHERE store_id = ? AND is_cd_paid = 0
GROUP BY supplier_name
HAVING cnt >= 2   -- 2건 이상 누적된 업체만 표시
ORDER BY total DESC
```

### 5.5 ajax_save_cd.php 동기화 로직

```
1. 이전 state_json에서 all_prev_batch_ids 추출
2. 새 state에서 all_new_batch_ids 추출
3. 제거된 IDs = prev - new  → is_cd_paid = 0
4. 추가된 IDs = new - prev  → is_cd_paid = 1
5. cd_saved_state 저장 (기존)
```

---

## 6. UI 설계

```
CD Source List
┌─────────────────────────────┐
│ ─ 오늘 납품 (2026-05-08) ─   │
│ [드래그 카드] 업체X ₱1,000   │
│                             │
│ ─ 누적 미결제 ─              │  ← 신규 섹션
│ [A업체] 7건 · ₱52,000 🔴    │  ← 드래그 가능 카드
│  May 1~May 7                │
│ [B업체] 3건 · ₱8,500 🔴     │
│  May 5~May 7                │
└─────────────────────────────┘
```

드래그 결과 → CD 섹션 행:
```
┌────┬──────────┬──────────┬─────────────────────────────────────┬─────────┐
│ ≡  │ (OR/SI)  │ 2026-05-08 │ A업체                             │ ₱52,000 │
│    │          │          │ May 1: ₱8,000 / May 2: ₱7,500 / ... │         │
└────┴──────────┴──────────┴─────────────────────────────────────┴─────────┘
```

---

## 7. 구현 순서

1. `sql/add_cd_paid_col.sql` 작성 및 실행
2. `ajax_load_purchases.php` — accumulated 배열 추가
3. `index.php` — 누적 섹션 UI + 드래그 핸들러
4. `ajax_save_cd.php` — is_cd_paid 동기화

---

## 8. Brainstorming Log

| 결정 | 이유 |
|------|------|
| CD Source에 누적 섹션 추가 | 기존 드래그앤드롭 패턴 재사용, 학습 비용 없음 |
| is_cd_paid 플래그 방식 | 별도 테이블 불필요, 단순하고 성능 좋음 |
| HAVING cnt >= 2 조건 | 1건짜리는 기존 방식(오늘 날짜 드래그)으로 충분 |
| batch_ids 배열을 row에 내장 | state_json에 포함되어 저장/복원이 자연스러움 |
| 전체 조정(full reconciliation) | 저장 시 이전 상태와 비교해 정확한 is_cd_paid 관리 |
