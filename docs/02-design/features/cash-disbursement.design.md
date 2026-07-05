# Cash Disbursement — Design Document

**Feature**: cash-disbursement
**Date**: 2026-05-09
**Architecture**: Option C — SortableJS + localStorage

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 수작업 전사 → 드래그앤드롭 자동화로 업무 효율화 |
| **WHO** | 오피스 스태프 (일일 지출 보고서 담당자) |
| **RISK** | 공급업체 섹션 기억 DB 없으면 매일 재분류 필요 |
| **SUCCESS** | 당일 구매 전체를 5분 내에 서식 완성·출력 가능 |
| **SCOPE** | office/cash_disbursement/, 기존 purchase 테이블 읽기 전용 |

---

## 1. Architecture Overview

### Selected: Option C — SortableJS + localStorage

```
Browser
├── SortableJS (CDN)          드래그앤드롭 엔진
├── localStorage              작업 중 상태 자동 저장/복원
└── AJAX (fetch)
    ├── ajax_load_purchases   날짜별 구매 데이터 로드
    ├── ajax_save_mapping     공급업체→섹션 매핑 영구 저장
    └── ajax_get_mappings     자동 배치용 매핑 조회

Server (PHP)
├── index.php                 Shell HTML + 초기화
├── print_cd.php              서버사이드 프린트 HTML 생성
├── export_cd.php             SpreadsheetML 생성
└── ajax_*.php                JSON API 엔드포인트

Database
├── cd_supplier_section_map   공급업체→섹션 영구 기억
├── office_product_purchases  읽기 전용 소스
└── office_equipment_purchases 읽기 전용 소스
```

### 핵심 설계 결정
| 결정 | 이유 |
|------|------|
| SortableJS | 크로스-리스트 드래그, 섹션 내 정렬, 터치 지원 모두 제공 |
| localStorage | 새로고침 시 작업 복원, 서버 부하 없음 |
| Print/Export는 서버사이드 | 서식 정확도, SpreadsheetML 생성 용이 |
| 자동배치는 로드 시점 1회 | UX 단순화, 사용자 수동 조정 가능 |

---

## 2. File Structure

```
office/
└── cash_disbursement/
    ├── index.php                  # 메인 UI (드래그앤드롭)
    ├── print_cd.php               # 프린트 미리보기
    ├── export_cd.php              # Excel SpreadsheetML
    ├── ajax_load_purchases.php    # 구매 데이터 로드
    ├── ajax_save_mapping.php      # 매핑 저장/업데이트
    ├── ajax_get_mappings.php      # 매핑 조회
    └── sql/
        └── create_cd_map.sql      # DB 마이그레이션
```

---

## 3. Database Design

### cd_supplier_section_map
```sql
CREATE TABLE IF NOT EXISTS cd_supplier_section_map (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  store_id      INT NOT NULL,
  supplier_name VARCHAR(255) NOT NULL,
  section       ENUM('korean','local','fixed','others') NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_store_supplier (store_id, supplier_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 기존 테이블 사용 컬럼
| 테이블 | 컬럼 | 용도 |
|--------|------|------|
| office_product_purchases | id, supplier_name, delivery_content, amount, payment_date, cv_no | SOURCE LIST 항목 |
| office_equipment_purchases | id, supplier_name, delivery_content, amount, payment_date | SOURCE LIST 항목 |

---

## 4. API Design

### GET ajax_load_purchases.php
```
?date=YYYY-MM-DD
Response: {
  success: true,
  items: [
    {
      id: "p_123",           // p_=product, e_=equipment
      type: "product",
      supplier_name: "NAMBURANGIAO",
      details: "RICE CARE",
      amount: 462.00,
      date: "2026-05-09",
      cv_no: "INV-001",
      auto_section: "korean"  // null if no mapping
    }, ...
  ]
}
```

### POST ajax_save_mapping.php
```
Body: { supplier_name, section }
Response: { success: true }
```

### GET ajax_get_mappings.php
```
Response: {
  success: true,
  mappings: { "NAMBURANGIAO": "korean", "EGG CO.": "local", ... }
}
```

### POST print_cd.php / export_cd.php
```
Body (JSON): {
  date: "2026-05-09",
  sections: {
    korean: [ { or_si_no, date, supplier, details, amount }, ... ],
    local:  [ ... ],
    fixed:  [ ... ],
    others: [ ... ]
  }
}
```

---

## 5. UI Component Design

### 5.1 메인 레이아웃
```
┌─────────────────────────────────────────────────────────────┐
│ 💰 Cash Disbursement   [날짜선택]  [🖨 Print] [📊 Excel]    │
├─────────────────────────┬───────────────────────────────────┤
│ 📋 SOURCE LIST          │ 📄 FORM                           │
│ (SortableJS group=cd)   │                                   │
│                         │ YEAR:__ MONTH:__ DAY:__           │
│ ┌─────────────────────┐ │ PREPARED: LIZA  APPROVED: SIR MIN│
│ │ NAMBURANGIAO        │ │                                   │
│ │ RICE CARE   ₱462    │ │ ▼ 1. KOREAN          [+수동추가] │
│ │ 🟢 auto: KOREAN     │ │ ╔═════════════════════════════╗  │
│ └─────────────────────┘ │ ║ ← 드롭존 (SortableJS)       ║  │
│                         │ ║ [드래그한 항목 표시]          ║  │
│ ┌─────────────────────┐ │ ╚═════════════════════════════╝  │
│ │ EGG CO. SUPPLY      │ │                                   │
│ │ LARGE EGGS  ₱31,250 │ │ ▼ 2. LOCAL           [+수동추가] │
│ │ ⚪ 미배치            │ │ ╔═════════════════════════════╗  │
│ └─────────────────────┘ │ ║ ← 드롭존                    ║  │
│                         │ ╚═════════════════════════════╝  │
│ ┌─────────────────────┐ │                                   │
│ │ HOME PLUS           │ │ ▼ 3. FIXED EXPENSES  [+수동추가] │
│ │ SCISSORS    ₱253    │ │ ╔═════════════════════════════╗  │
│ │ ⚪ 미배치            │ │ ║ ← 드롭존                    ║  │
│ └─────────────────────┘ │ ╚═════════════════════════════╝  │
│                         │                                   │
│ [전체 자동배치 버튼]     │ ▼ 4. OTHERS          [+수동추가] │
│                         │ ╔═════════════════════════════╗  │
│                         │ ║ ← 드롭존                    ║  │
│                         │ ╚═════════════════════════════╝  │
│                         │                                   │
│                         │ SUPPLIER:    ₱ 0.00              │
│                         │ OTHER EXP:   ₱ 0.00              │
│                         │ GRAND TOTAL: ₱ 0.00              │
└─────────────────────────┴───────────────────────────────────┘
```

### 5.2 SOURCE LIST 카드 상태
| 상태 | 배경색 | 뱃지 |
|------|--------|------|
| 미배치 | 흰색 | — |
| 자동배치 예정 (기억 있음) | 연초록 | `🟢 auto: KOREAN` |
| 섹션에 배치 완료 | 회색 흐림 | `✅ KOREAN` |

### 5.3 섹션 내 행 컬럼
```
[≡drag] NO. | OR/SI NO. | DATE | COMPANY | DETAILS | AMOUNT | [×]
```

---

## 6. State Management

### localStorage 키: `cd_state_{store_id}_{date}`
```json
{
  "date": "2026-05-09",
  "sections": {
    "korean": [
      {
        "item_id": "p_123",
        "or_si_no": "INV-001",
        "date": "2026-05-09",
        "supplier": "NAMBURANGIAO",
        "details": "RICE CARE",
        "amount": 462.00,
        "manual": false
      }
    ],
    "local": [],
    "fixed": [],
    "others": []
  },
  "placed_ids": ["p_123"]
}
```

### 상태 흐름
```
날짜 선택
  → ajax_load_purchases (구매 목록)
  → ajax_get_mappings (저장된 매핑)
  → 자동배치 적용
  → localStorage 복원 (이전 작업 있으면)
  → UI 렌더링

드래그 완료
  → SOURCE LIST에서 제거 / 섹션에 추가
  → ajax_save_mapping (신규 공급업체면)
  → localStorage 저장
  → 합계 재계산

Print / Export 클릭
  → 현재 sections 상태 JSON으로 수집
  → POST → print_cd.php / export_cd.php
  → 새 탭 출력
```

---

## 7. Print / Export Design

### 7.1 print_cd.php 서식 구조
```
┌─────────────────────────────────────────────────┐
│        Cash Disbursement                        │
│   (Home Plus Sunset Corporation)                │
│                          PREPARED  APPROVED     │
│                          LIZA      SIR MIN      │
├──────┬─────────┬──────────┬──────────┬──────────┤
│ YEAR │  2026   │  MONTH   │    5     │  DAY  9  │
├──┬───┴─┬───────┴──┬───────┴──┬───────┴──┬───────┤
│NO│OR/SI│DATE OF   │COMPANY   │DETAILS   │AMOUNT │
│  │ NO. │PURCHASE  │(SUPPLIER)│          │       │
├──┴─────┴──────────┴──────────┴──────────┴───────┤
│ 1. KOREAN                                       │
│  1 │ INV-001 │ 2026-05-09 │ NAMBURANGIAO │ RICE CARE │ 462.00 │
├─────────────────────────────────────────────────┤
│ 2. LOCAL                                        │
│  ...                                            │
├─────────────────────────────────────────────────┤
│ 3. FIXED EXPENSES                               │
│  ...                                            │
├─────────────────────────────────────────────────┤
│ 4. OTHERS                                       │
│  ...                                            │
├─────────────────────────────────────────────────┤
│ TOTAL:          ₱ 75,692.00                     │
│ SUPPLIER:       ₱ 60,080.00                     │
│ OTHER EXPENSES: ₱ 15,612.00                     │
└─────────────────────────────────────────────────┘
```

### 7.2 export_cd.php (SpreadsheetML)
- 동일 구조를 SpreadsheetML XML로 생성
- `ob_start()` → `ob_end_clean()` 패턴 (BOM 방지)
- Content-Type: application/vnd.ms-excel

---

## 8. Security

| 항목 | 처리 |
|------|------|
| 접근 제어 | `require_office_permission()` 모든 파일 |
| SQL Injection | MySQLi prepared statements |
| XSS | `htmlspecialchars()` 모든 출력 |
| 날짜 검증 | `preg_match('/^\d{4}-\d{2}-\d{2}$/')` |
| 섹션 값 검증 | ENUM whitelist 체크 |

---

## 9. Navigation

`office/partials/header.php` 수정:
```php
$is_cd = str_contains($uri, '/cash_disbursement/');
// $is_dash 조건에 $is_cd 추가
```
메뉴 추가 위치: Sales Report 다음
```html
<a href="{base}cash_disbursement/index.php"
   class="... <?php echo $is_cd ? 'bg-amber-100 text-amber-700' : '...'; ?>">
  <i class="fa-solid fa-money-bill-wave mr-1"></i>Cash Disbursement
</a>
```

---

## 10. Implementation Guide

### 10.1 Module Map
| Module | 파일 | 예상 라인 | 난이도 |
|--------|------|-----------|--------|
| M1 | sql/create_cd_map.sql + run_migration.php | 30 | ★ |
| M2 | ajax_load_purchases.php | 80 | ★★ |
| M3 | ajax_save_mapping.php + ajax_get_mappings.php | 60 | ★★ |
| M4 | index.php (HTML + SortableJS + localStorage) | 400 | ★★★★ |
| M5 | print_cd.php | 150 | ★★★ |
| M6 | export_cd.php (SpreadsheetML) | 200 | ★★★ |
| M7 | header.php 네비게이션 추가 | 10 | ★ |

### 10.2 구현 순서 (의존성 기준)
```
M1 (DB) → M2 (데이터 로드) → M3 (매핑) → M4 (UI) → M5+M6 (출력) → M7 (네비)
```

### 10.3 Session Guide
| 세션 | 모듈 | 주요 작업 |
|------|------|---------|
| Session 1 | M1 + M2 + M3 | DB + AJAX API 완성 |
| Session 2 | M4 | SortableJS UI + localStorage + 합계 |
| Session 3 | M5 + M6 + M7 | 프린트 + Excel + 네비게이션 |

---

## 11. SortableJS 설정

```javascript
// SOURCE LIST → 섹션으로 드래그 (원본 제거)
Sortable.create(document.getElementById('source_list'), {
    group: { name: 'cd', pull: 'clone', put: false },
    sort: false,
    animation: 150,
    ghostClass: 'opacity-40'
});

// 섹션 내 정렬 + SOURCE LIST에서 받기
['korean','local','fixed','others'].forEach(sec => {
    Sortable.create(document.getElementById('section_' + sec), {
        group: { name: 'cd', pull: false, put: true },
        animation: 150,
        handle: '.drag-handle',
        onAdd: (evt) => handleDrop(evt, sec),
        onUpdate: () => saveState()
    });
});
```
