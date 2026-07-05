# Receipt Status Tracking — Design Document

**Feature**: receipt-status-tracking  
**Architecture**: Option B — Clean (JSON 파싱 완전 제거, DB 컬럼 기반)  
**Date**: 2026-05-14

---

## Context Anchor

| | 내용 |
|--|------|
| **WHY** | JSON saved_state 파싱 의존으로 CER 이월 버그, Status 미반영 반복 → DB 컬럼이 source of truth |
| **WHO** | 점포 관리자 (영수증 등록·ER·CER·CD 처리 담당) |
| **RISK** | Backfill 실패 시 기존 처리 항목이 재등장할 수 있음 → Backfill 스크립트 검증 필수 |
| **SUCCESS** | SQL 쿼리만으로 영수증 상태 판단 가능, JSON 파싱 코드 0줄 |
| **SCOPE** | office_receipts 테이블 + ER/CER/CD 저장·로딩 + 영수증 UI |

---

## 1. 데이터 모델 변경

### 1.1 추가 컬럼 (office_receipts)

```sql
ALTER TABLE office_receipts
  ADD COLUMN payment_type  ENUM('cash','check') NOT NULL DEFAULT 'cash'
      COMMENT '결제수단 (영수증 등록 시 선택)',
  ADD COLUMN er_section    VARCHAR(50)  NULL
      COMMENT 'ER 배치 섹션명 (NULL=미배치): selling | not_selling | check_sup | other_exp_check | other_exp_cash',
  ADD COLUMN is_cer_placed TINYINT(1)   NOT NULL DEFAULT 0
      COMMENT 'Cheque Expense Report 처리 완료',
  ADD COLUMN is_cd_paid    TINYINT(1)   NOT NULL DEFAULT 0
      COMMENT 'Cash Disbursement 처리 완료';

ALTER TABLE office_receipts
  ADD INDEX idx_receipt_status (store_id, is_er_placed, er_section, receipt_date),
  ADD INDEX idx_cer_placed     (store_id, is_cer_placed),
  ADD INDEX idx_cd_paid        (store_id, is_cd_paid);
```

### 1.2 영수증 상태 매트릭스

| 상태 | payment_type | is_er_placed | er_section | is_cer_placed | is_cd_paid | 표시 |
|------|:---:|:---:|---|:---:|:---:|---|
| Pending | any | 0 | NULL | 0 | 0 | 🟡 Pending |
| ER 등록 (현금) | cash | 1 | selling / not_selling / other_exp_cash | 0 | 0 | 🔵 ER (Cash) |
| ER 등록 (수표) | check | 1 | check_sup / other_exp_check | 0 | 0 | 🟣 ER (Cheque) |
| CD 완료 | cash | 1 | selling 등 | 0 | 1 | ✅ CD 완료 |
| CER 완료 | check | 1 | check_sup 등 | 1 | 0 | ✅ CER 완료 |
| 구매 직결 | any | 0 | NULL | 0 | 0 | 🟢 상품구매 / 비품구매 (linked_purchase_id 기반) |

### 1.3 섹션-결제수단 매핑

```
현금(Cash) 섹션:
  selling, not_selling, other_exp_cash → CD로 처리

수표(Check) 섹션:
  check_sup, other_exp_check → CER로 처리
```

---

## 2. 마이그레이션 및 Backfill

### 2.1 마이그레이션 스크립트 (run_receipt_status_migration.php)

```
Step 1: 4개 컬럼 추가 (IF NOT EXISTS)
Step 2: er_saved_state JSON 파싱 → er_section, is_er_placed 업데이트
Step 3: cer_saved_state JSON 파싱 → is_cer_placed 업데이트
Step 4: cd_saved_state JSON 파싱 → is_cd_paid 업데이트
Step 5: 결과 검증 (업데이트 건수 출력)
```

### 2.2 Backfill 로직 상세

```php
// Step 2: ER → er_section 복원
foreach er_saved_state (모든 날짜, store_id) {
    foreach sections as section_name => rows {
        foreach rows as row {
            if (r_prefix && receipt_id > 0) {
                UPDATE office_receipts
                SET is_er_placed=1, er_section=section_name
                WHERE id=receipt_id AND store_id=store_id
            }
        }
    }
}

// Step 3: CER → is_cer_placed 복원
foreach cer_saved_state {
    foreach sections as rows {
        foreach rows as row {
            if (r_prefix) {
                UPDATE office_receipts SET is_cer_placed=1 WHERE id=receipt_id
            }
        }
    }
}

// Step 4: CD → is_cd_paid 복원
// CD는 batch_ids도 처리
foreach cd_saved_state {
    foreach sections as rows {
        foreach rows as row {
            if (r_prefix in item_id OR batch_ids) {
                UPDATE office_receipts SET is_cd_paid=1 WHERE id IN (ids)
            }
        }
    }
}
```

---

## 3. ER 저장/해제 로직 변경

### 3.1 ajax_save_er.php 수정

```php
// 기존: is_er_placed 업데이트만
// 추가: r_ 아이템에 er_section도 업데이트

function er_sync_receipt_section($conn, $store_id, array $new_by_section, array $prev_all) {
    // new_by_section: ['selling' => [5,7], 'other_exp_check' => [12], ...]
    // prev_all: [5, 7, 12, ...] (이전 r_ ID 전체)

    $new_all = [];
    foreach ($new_by_section as $section => $ids) {
        foreach ($ids as $id) {
            $new_all[$id] = $section;
        }
    }

    // 새로 추가된 것: is_er_placed=1, er_section=섹션명
    $to_place = array_diff_key($new_all, array_flip($prev_all));
    foreach (group_by_section($to_place) as $section => $ids) {
        UPDATE office_receipts SET is_er_placed=1, er_section=? WHERE id IN (...)
    }

    // 제거된 것: is_er_placed=0, er_section=NULL
    $to_free = array_diff($prev_all, array_keys($new_all));
    if ($to_free) {
        UPDATE office_receipts SET is_er_placed=0, er_section=NULL WHERE id IN (...)
    }
}
```

**섹션별 r_ ID 파싱 추가**:
```php
// 기존: $new_ids['r'] = [numeric IDs]
// 추가: $new_ids_by_section['r'] = ['selling'=>[5], 'other_exp_check'=>[12], ...]

foreach ($decoded['sections'] as $section_name => $rows) {
    foreach ($rows as $row) {
        $bid = $row['item_id'] ?? '';
        if (strncmp($bid, 'r_', 2) === 0) {
            $n = (int)substr($bid, 2);
            if ($n > 0) $new_r_by_section[$section_name][] = $n;
        }
    }
}
```

### 3.2 ajax_unplace_item.php 수정 (r_ 처리)

```php
// 기존:
UPDATE office_receipts SET is_er_placed=0 WHERE id=? AND store_id=?

// 추가: er_section도 NULL로 초기화
UPDATE office_receipts SET is_er_placed=0, er_section=NULL WHERE id=? AND store_id=?
```

---

## 4. CER 저장 로직 변경

### 4.1 ajax_save_cer.php 수정

```php
// 기존: p_ 아이템만 처리 (is_cer_placed)
// 추가: r_ 아이템 is_cer_placed 처리

function extract_placed_receipt_ids(array $sections): array {
    $ids = [];
    foreach ($sections as $rows) {
        foreach ($rows as $row) {
            $bid = $row['item_id'] ?? '';
            if (strncmp((string)$bid, 'r_', 2) === 0) {
                $n = (int)substr($bid, 2);
                if ($n > 0) $ids[$n] = $n;
            }
        }
    }
    return array_values($ids);
}

// 저장 시:
$new_rc_ids  = extract_placed_receipt_ids($decoded['sections']);
$prev_rc_ids = extract_placed_receipt_ids($prev['sections'] ?? []);

$to_cer_place = array_diff($new_rc_ids, $prev_rc_ids);
$to_cer_free  = array_diff($prev_rc_ids, $new_rc_ids);

if ($to_cer_place) {
    UPDATE office_receipts SET is_cer_placed=1 WHERE id IN (...)
}
if ($to_cer_free) {
    UPDATE office_receipts SET is_cer_placed=0 WHERE id IN (...)
}
```

---

## 5. CD 저장 로직 변경

### 5.1 ajax_save_cd.php 수정

```php
// 동일 패턴으로 r_ 아이템 is_cd_paid 처리

$new_rc_ids  = extract_placed_receipt_ids_cd($decoded['sections']); // batch_ids 포함
$prev_rc_ids = extract_placed_receipt_ids_cd($prev['sections'] ?? []);

if ($to_cd_place) {
    UPDATE office_receipts SET is_cd_paid=1 WHERE id IN (...)
}
if ($to_cd_free) {
    UPDATE office_receipts SET is_cd_paid=0 WHERE id IN (...)
}
```

---

## 6. CER 로딩 로직 완전 재작성

### 6.1 ajax_load_cheques.php — 핵심 변경

```php
// ── 제거: parse_check_ids(), parse_carryover_ids() 함수
// ── 제거: er_saved_state JSON 파싱
// ── 제거: 복잡한 이월(carryover) 로직

// ── 추가: DB 컬럼 기반 직접 쿼리

// [당일 CER 항목] — 오늘 날짜 영수증 중 check 섹션 배치된 것
$sql_today = "
    SELECT id, supplier_name, description AS particular, amount,
           receipt_date AS date, COALESCE(cv_no,'') AS sales_invoice,
           er_section, payment_type
    FROM office_receipts
    WHERE store_id=?
      AND is_er_placed=1
      AND er_section IN ('check_sup','other_exp_check')
      AND is_cer_placed=0
      AND receipt_date=?
";

// [이월 항목] — 이전 날짜 check_sup 영수증 중 CER 미처리
// (other_exp_check는 이월 없음 — 날짜 고정)
$sql_carryover = "
    SELECT id, supplier_name, description AS particular, amount,
           receipt_date AS date, COALESCE(cv_no,'') AS sales_invoice,
           er_section, payment_type
    FROM office_receipts
    WHERE store_id=?
      AND is_er_placed=1
      AND er_section = 'check_sup'
      AND is_cer_placed=0
      AND receipt_date < ?
      AND receipt_date >= DATE_SUB(?, INTERVAL 60 DAY)
";

// product_purchases (기존 p_/pc_ 로직은 그대로 유지)
```

### 6.2 기존 product_purchases 로직 유지

```
기존 check_sup의 pc_ 아이템 (office_product_purchases payment_type='check')은
기존 로직 그대로 유지 (변경 없음).
r_ 아이템만 새 DB 컬럼 방식으로 전환.
```

---

## 7. CD 로딩 로직 완전 재작성

### 7.1 ajax_load_purchases.php — 핵심 변경

```php
// ── 제거: parse_er_ids() JSON 파싱
// ── 제거: 복잡한 이월 로직 (r_ 부분만)

// [당일 CD 항목 - 영수증]
$sql_today = "
    SELECT id, supplier_name, description AS details, amount,
           receipt_date AS date, COALESCE(cv_no,'') AS cv_no,
           er_section
    FROM office_receipts
    WHERE store_id=?
      AND is_er_placed=1
      AND er_section IN ('selling','not_selling','other_exp_cash')
      AND is_cd_paid=0
      AND receipt_date=?
";

// [이월 항목 - 영수증] — 이전 날짜 미결제
$sql_carryover = "
    SELECT ... FROM office_receipts
    WHERE store_id=?
      AND is_er_placed=1
      AND er_section IN ('selling','not_selling','other_exp_cash')
      AND is_cd_paid=0
      AND receipt_date < ?
      AND receipt_date >= DATE_SUB(?, INTERVAL 60 DAY)
";

// p_ (product_purchases) 및 e_ (equipment_purchases) 로직은 기존 유지
```

---

## 8. 영수증 UI 변경

### 8.1 add.php — payment_type 선택 추가

```html
<!-- 결제수단 선택 (신규 필드) -->
<div class="mb-4">
  <label class="block text-sm font-medium text-gray-700 mb-1">
    결제수단 <span class="text-red-500">*</span>
  </label>
  <div class="flex gap-4">
    <label class="flex items-center gap-2 cursor-pointer">
      <input type="radio" name="payment_type" value="cash" checked>
      <span class="text-sm">Cash (현금)</span>
    </label>
    <label class="flex items-center gap-2 cursor-pointer">
      <input type="radio" name="payment_type" value="check">
      <span class="text-sm">Cheque (수표)</span>
    </label>
  </div>
</div>
```

### 8.2 edit.php — payment_type 수정 가능

기존 폼에 payment_type 라디오 버튼 추가 (DB 값 pre-fill).

### 8.3 list.php — Status 표시 완전 개선

```php
// 기존: linked_purchase_id + is_er_placed 기반 (2가지)
// 개선: 6가지 상태

function get_receipt_status($r): array {
    if ($r['linked_purchase_id'] !== null) {
        return ['label' => $r['linked_purchase_type']==='product' ? '상품구매' : '비품구매',
                'color' => 'blue'];
    }
    if ($r['is_cd_paid'])    return ['label' => 'CD 완료',    'color' => 'green'];
    if ($r['is_cer_placed']) return ['label' => 'CER 완료',   'color' => 'green'];
    if ($r['is_er_placed'])  return ['label' => 'ER 등록 · ' . label_section($r['er_section']),
                                     'color' => $r['payment_type']==='check' ? 'purple' : 'blue'];
    return ['label' => 'Pending', 'color' => 'amber'];
}

function label_section($s): string {
    return match($s) {
        'selling'          => 'Cash',
        'not_selling'      => 'Cash',
        'check_sup'        => 'Cheque',
        'other_exp_check'  => 'Cheque (기타)',
        'other_exp_cash'   => 'Cash (기타)',
        default            => $s ?? '',
    };
}
```

**SELECT에 추가**: `payment_type`, `er_section`, `is_cer_placed`, `is_cd_paid`

**필터 개선**:
```php
// unused 필터
AND linked_purchase_id IS NULL AND is_er_placed=0

// used 필터
AND (linked_purchase_id IS NOT NULL OR is_er_placed=1 OR is_cer_placed=1 OR is_cd_paid=1)
```

---

## 9. 파일별 변경 목록

| 파일 | 변경 유형 | 내용 |
|------|----------|------|
| `run_receipt_status_migration.php` | 신규 | 4컬럼 추가 + 3-step backfill |
| `expense_report/ajax_save_er.php` | 수정 | r_ 아이템 er_section 업데이트 |
| `expense_report/ajax_unplace_item.php` | 수정 | r_ er_section=NULL 초기화 |
| `cheque_expense_report/ajax_save_cer.php` | 수정 | r_ is_cer_placed 업데이트 |
| `cheque_expense_report/ajax_load_cheques.php` | 수정 | r_ 로딩 → DB 컬럼 기반 재작성 |
| `cash_disbursement/ajax_save_cd.php` | 수정 | r_ is_cd_paid 업데이트 |
| `cash_disbursement/ajax_load_purchases.php` | 수정 | r_ 로딩 → DB 컬럼 기반 재작성 |
| `receipts/add.php` | 수정 | payment_type UI + 저장 |
| `receipts/edit.php` | 수정 | payment_type 수정 |
| `receipts/list.php` | 수정 | 6가지 Status 표시 + 필터 개선 |

---

## 10. 구현 순서 (의존성 기반)

```
Phase 1 — DB 기반 구축
  1-1. run_receipt_status_migration.php (컬럼 추가 + backfill)
  ↓
Phase 2 — 저장 로직 (쓰기)
  2-1. ajax_save_er.php (er_section 업데이트)
  2-2. ajax_unplace_item.php (er_section NULL)
  2-3. ajax_save_cer.php (is_cer_placed)
  2-4. ajax_save_cd.php (is_cd_paid)
  ↓
Phase 3 — 로딩 로직 (읽기)
  3-1. ajax_load_cheques.php (r_ → DB 쿼리)
  3-2. ajax_load_purchases.php (r_ → DB 쿼리)
  ↓
Phase 4 — UI
  4-1. receipts/add.php (payment_type)
  4-2. receipts/edit.php (payment_type)
  4-3. receipts/list.php (Status 개선)
```

### 11.3 Session Guide

| 모듈 | 파일 수 | 권장 세션 |
|------|:-------:|----------|
| module-1: DB + Backfill | 1 | 세션 1 |
| module-2: ER 저장 로직 | 2 | 세션 2 |
| module-3: CER/CD 저장 로직 | 2 | 세션 2 |
| module-4: CER/CD 로딩 재작성 | 2 | 세션 3 |
| module-5: 영수증 UI 개선 | 3 | 세션 4 |

---

## 11. 검증 포인트

### 기능 검증
1. 마이그레이션 실행 후 기존 `is_er_placed=1` 영수증에 `er_section` 값이 채워지는가
2. ER SAVE → 영수증 `er_section` 컬럼 업데이트되는가
3. ER × 버튼 → `er_section=NULL` 복원되는가
4. CER SAVE → `is_cer_placed=1` 업데이트되는가
5. CD SAVE → `is_cd_paid=1` 업데이트되는가
6. May 5일 CER 로딩 시 May 4일 `other_exp_check` 영수증 미표시
7. May 5일 CD 로딩 시 May 4일 현금 영수증 이월 정상 표시
8. 영수증 목록 Status 6가지 정확히 표시

### 회귀 검증 (기존 기능)
- product_purchases (p_/pc_) ER/CER/CD 동작 변화 없음
- equipment_purchases (e_) ER/CD 동작 변화 없음
- 영수증 → 구매 직결 (linked_purchase_id) 동작 변화 없음
