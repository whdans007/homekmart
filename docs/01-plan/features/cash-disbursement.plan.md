# Cash Disbursement — Plan Document

**Feature**: cash-disbursement
**Date**: 2026-05-09
**Status**: Plan Complete

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 일별 구매 내역(Product/Equipment)을 Cash Disbursement 서식에 수작업으로 옮겨 적는 번거로움과 오류 발생 |
| **Solution** | 날짜별 구매 목록에서 4개 섹션(Korean/Local/Fixed/Others)으로 드래그앤드롭하여 서식을 자동 완성, 공급업체 섹션을 기억해 다음번 자동 배치 |
| **Functional UX Effect** | 드래그 한 번으로 행 배치 → 즉시 합계 계산 → 프린트/Excel 원클릭 출력 |
| **Core Value** | 일일 지출 보고서 작성 시간 단축 및 섹션 오분류 오류 제거 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 수작업 전사 → 드래그앤드롭 자동화로 업무 효율화 |
| **WHO** | 오피스 스태프 (일일 지출 보고서 담당자) |
| **RISK** | 공급업체 섹션 기억 DB 없으면 매일 재분류 필요 |
| **SUCCESS** | 당일 구매 전체를 5분 내에 서식 완성·출력 가능 |
| **SCOPE** | office/ 서브시스템, 기존 Product/Equipment Purchase 데이터 재사용 |

---

## 1. Feature Overview

### 1.1 기능 설명
Cash Disbursement는 Home Plus Sunset Corporation의 일별 지출 결의서 작성 도구입니다.
당일 Product Purchase 및 Equipment Purchase 데이터를 SOURCE LIST로 불러와,
4개의 섹션(KOREAN / LOCAL / FIXED EXPENSES / OTHERS)에 드래그앤드롭으로 배치하고
공식 서식에 맞게 프린트·Excel 출력합니다.

### 1.2 서식 구조 (Cash Disbursement.png 기준)

**헤더:** YEAR / MONTH / DAY / PREPARED BY / APPROVED

**컬럼:** NO. | OR/SI NO. (CV No.) | DATE OF PURCHASE | COMPANY (SUPPLIER NAME) | DETAILS | AMOUNT

**4개 섹션:**
1. **KOREAN** — 한국계 공급업체 구매
2. **LOCAL** — 현지 공급업체 구매
3. **FIXED EXPENSES** — 고정비 (임대료·전기·수도 등)
4. **OTHERS** — 기타 잡비

**하단 합계:**
- SUPPLIER TOTAL = KOREAN + LOCAL
- OTHER EXPENSES = FIXED EXPENSES + OTHERS
- GRAND TOTAL = SUPPLIER TOTAL + OTHER EXPENSES

---

## 2. User Intent Discovery

| 항목 | 내용 |
|------|------|
| **Core Problem** | 구매 내역 → Cash Disbursement 서식 수작업 전사 제거 |
| **Target User** | 오피스 스태프 (일일 보고서 작성자) |
| **Success Criteria** | ① 당일 구매 전체 로드 ② 5분 내 섹션 배치 완료 ③ 프린트 즉시 출력 |
| **Constraint** | 기존 office_product_purchases / office_equipment_purchases 테이블 활용 |

---

## 3. Alternatives Explored

### Approach A — 드래그앤드롭 (선택됨)
- **Pros**: 직관적, 빠른 배치, 섹션 기억으로 자동화 가능
- **Cons**: JS 구현 복잡도 있음
- **Best for**: 반복 작업 자동화

### Approach B — 클릭 선택
- **Pros**: 구현 단순
- **Cons**: 배치 단계가 많음

### Approach C — 모달 팝업
- **Pros**: 모바일 친화적
- **Cons**: UX 흐름 단절

---

## 4. YAGNI Review

### v1 포함 항목 (All)
- [x] 날짜 선택 + 구매 데이터 로드
- [x] 좌우 드래그앤드롭 (SOURCE LIST → 4섹션)
- [x] 공급업체 섹션 기억 + 자동 배치
- [x] CV No. → OR/SI NO. 자동 입력
- [x] 섹션 내 항목 삭제
- [x] 항목 직접 추가 (구매 목록 외 수동 입력)
- [x] 프린트 미리보기
- [x] Excel 저장 (SpreadsheetML)
- [x] 섹션 내 행 순서 변경 (드래그)

---

## 5. Requirements

### 5.1 기능 요구사항
| ID | 요구사항 |
|----|---------|
| F-01 | 날짜 선택 시 해당일 Product/Equipment Purchase 로드 |
| F-02 | SOURCE LIST에서 4개 섹션으로 드래그앤드롭 배치 |
| F-03 | 공급업체 섹션 매핑 저장 (cd_supplier_section_map) |
| F-04 | 다음 사용 시 저장된 매핑으로 자동 배치 |
| F-05 | OR/SI NO. = CV No. 자동 입력 |
| F-06 | 섹션 내 행 삭제 (SOURCE LIST로 복원) |
| F-07 | 수동 행 추가 (섹션별 + 버튼) |
| F-08 | 섹션 내 행 순서 드래그 변경 |
| F-09 | SUPPLIER TOTAL / OTHER EXPENSES / GRAND TOTAL 실시간 계산 |
| F-10 | 프린트 미리보기 (Cash Disbursement 서식) |
| F-11 | Excel 다운로드 (SpreadsheetML) |

### 5.2 비기능 요구사항
- 기존 office/ 시스템 디자인 언어 통일
- 마이그레이션 없이도 기본 동작 (섹션 기억 없이 수동 배치)

---

## 6. Data Model

### 신규 테이블: `cd_supplier_section_map`
```sql
CREATE TABLE IF NOT EXISTS cd_supplier_section_map (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  store_id     INT NOT NULL,
  supplier_name VARCHAR(255) NOT NULL,
  section      ENUM('korean','local','fixed','others') NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_store_supplier (store_id, supplier_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 기존 테이블 (읽기 전용)
- `office_product_purchases` — OR/SI NO.(cv_no), supplier_name, delivery_content, amount, payment_date
- `office_equipment_purchases` — supplier_name, delivery_content, amount, payment_date

---

## 7. File Structure

```
office/
└── cash_disbursement/
    ├── index.php                  # 메인 페이지 (드래그앤드롭 UI)
    ├── print_cd.php               # 프린트 미리보기
    ├── export_cd.php              # Excel SpreadsheetML 다운로드
    ├── ajax_load_purchases.php    # 날짜별 구매 데이터 로드
    ├── ajax_save_mapping.php      # 공급업체→섹션 매핑 저장
    ├── ajax_get_mappings.php      # 저장된 매핑 조회
    └── sql/
        └── create_cd_map.sql      # 마이그레이션 SQL
```

---

## 8. Screen Design

### 8.1 메인 페이지 레이아웃
```
┌─────────────────────────────────────────────────────────┐
│ 💰 Cash Disbursement    [날짜] [🖨 Print] [📊 Excel]    │
├──────────────────────┬──────────────────────────────────┤
│ 📋 SOURCE LIST       │ 📄 CASH DISBURSEMENT             │
│ [날짜] 구매 목록      │ Year/Month/Day  Prepared/Approved│
│                      │                                  │
│ ┌──────────────────┐ │ ▼ 1. KOREAN          [+Add]     │
│ │ 🟡 NAMBURANGIAO  │ │ ┌──────────────────────────────┐ │
│ │ RICE CARE  ₱462  │ │ │ (드롭존 - 항목 표시)          │ │
│ │ [배치됨: KOREAN] │ │ └──────────────────────────────┘ │
│ └──────────────────┘ │                                  │
│                      │ ▼ 2. LOCAL           [+Add]     │
│ ┌──────────────────┐ │ ┌──────────────────────────────┐ │
│ │ EGG CO. SUPPLY   │ │ │ (드롭존)                      │ │
│ │ LARGE EGGS ₱31K  │ │ └──────────────────────────────┘ │
│ └──────────────────┘ │                                  │
│                      │ ▼ 3. FIXED EXPENSES  [+Add]     │
│ [미배치 항목]         │ ▼ 4. OTHERS          [+Add]     │
│                      │                                  │
│                      │ SUPPLIER:     ₱ 0.00            │
│                      │ OTHER EXP:    ₱ 0.00            │
│                      │ GRAND TOTAL:  ₱ 0.00            │
└──────────────────────┴──────────────────────────────────┘
```

### 8.2 SOURCE LIST 카드 상태
- **미배치**: 흰 배경, 드래그 가능
- **배치됨 (기억 있음)**: 연한 녹색, 자동배치 뱃지 표시
- **배치됨 (수동)**: 연한 파랑
- **섹션에 드롭 후**: SOURCE LIST에서 회색 처리 (배치 완료)

---

## 9. Navigation Integration

`office/partials/header.php`에 Cash Disbursement 메뉴 추가:
- 위치: Sales Report 다음
- 아이콘: `fa-money-bill-wave`
- 색상: amber (노란색)
- URL: `office/cash_disbursement/index.php`

---

## 10. Success Criteria

| 기준 | 검증 방법 |
|------|---------|
| SC-01 | 날짜 선택 시 구매 데이터 2초 내 로드 |
| SC-02 | 드래그앤드롭으로 섹션 배치 정상 동작 |
| SC-03 | 동일 공급업체 다음날 자동 배치 확인 |
| SC-04 | 합계 실시간 정확 계산 |
| SC-05 | 프린트 서식이 Cash Disbursement.png와 일치 |
| SC-06 | Excel 다운로드 정상 열림 |

---

## 11. Implementation Guide

### Module Map
| Module | 파일 | 난이도 |
|--------|------|--------|
| M1 | SQL 마이그레이션 | ★ |
| M2 | ajax_load_purchases.php | ★★ |
| M3 | ajax_save/get_mappings.php | ★★ |
| M4 | index.php (UI + 드래그앤드롭 JS) | ★★★★ |
| M5 | print_cd.php | ★★★ |
| M6 | export_cd.php (SpreadsheetML) | ★★★ |
| M7 | Header 네비게이션 추가 | ★ |

### 권장 세션 플랜
- **Session 1**: M1 + M2 + M3 (데이터 레이어)
- **Session 2**: M4 (드래그앤드롭 UI — 핵심)
- **Session 3**: M5 + M6 + M7 (출력 + 네비게이션)

---

## 12. Addendum — 이월(carryover) 항목 표시 개선 (2026-09-23)

### 12.1 배경
v1 이후 "미처리 항목 60일 이월" 기능이 추가됨 (`ajax_load_purchases.php` — 전일 이전 미결제(`is_cd_paid=0`) 영수증/구매 건을 SOURCE LIST에 "↩ 날짜" 뱃지로 표시, 드래그해 당일 폼에 배치 가능).

**증상 보고**: 사용자가 특정 날짜의 CD를 열었을 때, 우측 저장 폼(KOREAN/LOCAL/FIXED/OTHERS)에 배치된 행들의 DATE 컬럼이 폼의 날짜와 다른 경우가 있어 "다른 날짜 데이터가 섞여 보인다"는 오해 발생.

**원인**: 버그 아님. 예: 9/21 ER은 저장됐지만 9/21 CD 자체는 저장된 적이 없어, 9/21 항목들이 이월되어 9/22 CD 폼에 배치·저장된 것 (`cd_saved_state.save_date=2026-09-22`인데 각 row의 `date=2026-09-21`). SOURCE LIST에서는 이월 항목에 "↩" 뱃지가 붙지만, 일단 폼 섹션(우측)에 드롭되고 나면 구분 표시가 사라져 원래 날짜와 폼 날짜가 다르다는 걸 알아채기 어려움.

### 12.2 결정
이월 기능 자체는 유지. **폼 섹션(우측)에 배치된 행도 이월 항목이면 시각적으로 구분 표시**한다.

### 12.3 요구사항
| ID | 요구사항 |
|----|---------|
| F-12 | `renderSection()`에서 각 row의 `date`가 현재 선택된 CD 날짜(`cd_date` input value)보다 이전이면 "이월 항목"으로 간주 |
| F-13 | 이월 행은 DATE 셀에 SOURCE LIST와 동일한 방식(주황색 텍스트 + `↩`)으로 표시 |
| F-14 | 이월 행 전체에 은은한 배경/좌측 보더 등 톤을 주어 같은 날짜 항목과 구분 (기존 `.auto-placed` 스타일과 색 충돌 없게 별도 클래스 사용) |
| F-15 | 순수 UI 표시 변경 — 저장 데이터 구조(state_json), 서버 로직(ajax_*.php) 변경 없음 |

### 12.4 영향 파일
- `office/cash_disbursement/index.php` — `renderSection()` 함수 및 `<style>` 블록만 수정 (JS/CSS only, PHP 서버 로직 변경 없음)

### 12.5 Success Criteria
- SC-07: 이월 항목이 포함된 날짜의 CD를 열었을 때, 해당 행이 같은 날짜의 다른 행과 시각적으로 구분됨
- SC-08: 이월이 아닌 일반 행은 기존과 동일하게 보임 (회귀 없음)
- SC-09: print_cd.php / export_cd.php 등 다른 출력물에는 영향 없음 (화면 표시 전용)
