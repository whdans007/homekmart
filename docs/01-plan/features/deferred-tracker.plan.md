# Deferred Payment Tracker — Plan Document

**Feature**: deferred-tracker
**Date**: 2026-05-13
**Method**: Plan Plus (Brainstorming-Enhanced)

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 후불 결제 업체의 매일 입고 금액을 날짜별·업체별로 한눈에 확인하고 관리하는 기능이 없음 |
| **Solution** | Product Purchase 항목을 드래그하면 업체별 컬럼이 자동 생성되는 월별 그리드 표로 입고 현황을 추적 |
| **UX Effect** | 왼쪽 소스 리스트에서 항목을 드래그 → 오른쪽 표에 업체명 컬럼 + 날짜별 금액 즉시 표시. 여러 업체 드래그 시 컬럼 추가 |
| **Core Value** | 후불 업체별 월 누적 입고금액을 5분 내에 정리, DB 저장으로 재방문 시 복원 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 후불 결제 업체 납품을 날짜별·업체별로 추적하여 결제 시점 파악 |
| **WHO** | 오피스 스태프 |
| **RISK** | 같은 날짜/업체 중복 드래그 시 항목 누락 없이 모두 표시 필요 |
| **SUCCESS** | 드래그만으로 업체별 월 납품 현황표 완성, Print/Excel 출력 |
| **SCOPE** | office/deferred_tracker/ 신규 모듈 (6파일) + header.php 수정 |

---

## 1. 화면 구성

```
┌─────────────────┐  ┌─────────────────────────────────────────────────┐
│  Source List     │  │  May 2026          [← ▶]  [SAVE] [Print] [Excel]│
│  ─────────────  │  ├──────────┬───────────┬───────────┬──────────────┤
│ A업체 May1 3,000│  │  DATE    │  A업체    │  B업체    │  C업체       │
│ B업체 May1 2,000│  ├──────────┼───────────┼───────────┼──────────────┤
│ A업체 May2 5,500│  │  May 1   │ 3,000     │ 2,000     │              │
│ C업체 May2 1,200│  │          │ (항목1)   │ (항목A)   │              │
│ (placed ✓)      │  │  May 2   │ 5,500     │           │ 1,200        │
│ ...             │  │  May 3   │           │           │              │
│                 │  │  ...     │           │           │              │
│                 │  ├──────────┼───────────┼───────────┼──────────────┤
│                 │  │  TOTAL   │ 8,500     │ 2,000     │ 1,200        │
└─────────────────┘  └──────────┴───────────┴───────────┴──────────────┘
```

---

## 2. 데이터 구조 (State JSON)

```json
{
  "year": 2026,
  "month": 5,
  "suppliers": ["A업체", "B업체", "C업체"],
  "rows": {
    "p_5":  { "supplier": "A업체", "date": "2026-05-01", "amount": 3000, "details": "..." },
    "p_12": { "supplier": "B업체", "date": "2026-05-01", "amount": 2000, "details": "..." },
    "p_18": { "supplier": "A업체", "date": "2026-05-02", "amount": 5500, "details": "..." }
  }
}
```

---

## 3. Requirements

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| FR-01 | 소스 리스트: 선택 월의 미배치 Product Purchase 표시 | Must |
| FR-02 | 드래그 → 해당 업체 컬럼 자동 생성 (없으면 신규) | Must |
| FR-03 | 같은 날짜/업체 여러 항목 → 각 항목 세부 표시 + 합계 | Must |
| FR-04 | 월 네비게이션 (이전/다음 월) | Must |
| FR-05 | DB SAVE (dtr_saved_state 테이블) | Must |
| FR-06 | TOTAL 행 (업체별 월 합계) | Must |
| FR-07 | Print (A4 가로) | Must |
| FR-08 | Excel 내보내기 | Must |
| FR-09 | 네비게이션: Equipment Purchase 다음에 추가 | Must |

---

## 4. 파일 구조

```
office/deferred_tracker/
├── index.php              # 메인 UI (드래그앤드롭 + 그리드)
├── ajax_load_items.php    # 월별 미배치 PP 로드 + 저장 상태
├── ajax_save_dtr.php      # 상태 JSON 저장
├── print_dtr.php          # A4 가로 인쇄
├── export_dtr.php         # SpreadsheetML Excel
└── sql/
    └── create_dtr.sql
```

---

## 5. DB 테이블

```sql
CREATE TABLE IF NOT EXISTS dtr_saved_state (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  year       SMALLINT NOT NULL,
  month      TINYINT NOT NULL,
  state_json MEDIUMTEXT NOT NULL,
  saved_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dtr (store_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 6. YAGNI Review

### In Scope (v1)
- [x] 드래그로 업체 컬럼 자동 생성 + 날짜행 금액 표시
- [x] 업체별 TOTAL 행
- [x] DB SAVE
- [x] Print / Excel

### Out of Scope (v1 이후)
- 후불 결제 완료 처리 (paid 표시)
- 업체별 결제 예정일 관리
- 컬럼 순서 변경 (드래그)
