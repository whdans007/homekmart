# Receipt Status Tracking — 영수증 DB 구조 개선

## Executive Summary

| 관점 | 내용 |
|------|------|
| **문제** | `office_receipts` 테이블에 결제수단(현금/수표), ER 배치 섹션, CER·CD 처리 여부를 저장하는 컬럼이 없어, 현재 모든 상태 판단을 `er_saved_state` JSON 파싱에 의존함. 이로 인해 CER·CD 로딩 로직이 복잡하고 버그가 반복 발생 |
| **솔루션** | `office_receipts`에 4개 컬럼 추가 (`payment_type`, `er_section`, `is_cer_placed`, `is_cd_paid`) 후 CER·CD·ER 저장 로직이 해당 컬럼을 업데이트하도록 수정. JSON 파싱 의존에서 DB 컬럼 기반으로 전환 |
| **UX 효과** | 영수증 목록에서 현금/수표 결제 구분 표시, CER·CD 처리 상태 정확히 표시, 날짜 이월 오류 근절 |
| **핵심 가치** | DB 컬럼이 진실의 원천(source of truth)이 되어 JSON saved_state 의존 제거. 쿼리 한 줄로 영수증 상태 파악 가능 |

---

## Context Anchor

| | 내용 |
|--|------|
| **WHY** | JSON 파싱 기반 상태 추적의 구조적 한계로 CER 이월 버그, Status 미반영 등 반복 발생 |
| **WHO** | 점포 관리자 (영수증 등록·CER·CD 처리 담당) |
| **RISK** | 기존 데이터 마이그레이션 필요, 여러 PHP 파일 동시 수정으로 누락 위험 |
| **SUCCESS** | 영수증의 모든 상태(결제수단, ER섹션, CER, CD)를 SQL 조회만으로 판단 가능 |
| **SCOPE** | office_receipts 테이블 + ER/CER/CD 저장·로딩 로직 |

---

## 1. 근본 원인

### 1.1 현재 office_receipts 테이블의 한계

```sql
-- 현재 상태 (실제)
office_receipts:
  linked_purchase_type  ENUM('product','equipment') NULL  ← 구매 직접 연결 시만
  linked_purchase_id    INT NULL                          ← 구매 직접 연결 시만
  is_er_placed          TINYINT(1) DEFAULT 0              ← migration 추가 (boolean만)
  is_dtr_placed         TINYINT(1) DEFAULT 0              ← migration 추가

-- 없는 것
  ❌ payment_type  — 현금인지 수표인지 모름
  ❌ er_section    — ER 어느 섹션(selling/check_sup/other_exp_check 등)에 배치됐는지 모름
  ❌ is_cer_placed — CER 처리 여부 모름
  ❌ is_cd_paid    — CD 처리 여부 모름
```

### 1.2 비교: product_purchases는 있는데 receipts에는 없음

| 컬럼 | product_purchases | equipment_purchases | receipts |
|------|:-:|:-:|:-:|
| payment_type (cash/check) | ✅ | ❌ | ❌ |
| is_er_placed | ✅ | ✅ | ✅ (migration) |
| is_cer_placed | ✅ | ❌ | ❌ **없음** |
| is_cd_paid | ✅ | ❌ | ❌ **없음** |
| er_section | ❌ (auto_section 고정) | ❌ | ❌ **없음** |

### 1.3 현재 방식의 문제

```
현재 CER/CD 로딩 방식 (비정상):
  er_saved_state JSON 파싱 → sections['other_exp_check'] 읽기
  → 60일치 JSON에서 item_id 추출 → 복잡한 이월 로직

정상 방식:
  SELECT id FROM office_receipts
  WHERE is_er_placed=1
    AND er_section IN ('check_sup','other_exp_check')
    AND is_cer_placed=0
```

---

## 2. 개선 범위

### 2.1 DB 변경

```sql
-- office_receipts에 추가할 컬럼 4개
ALTER TABLE office_receipts
  ADD COLUMN payment_type  ENUM('cash','check') NOT NULL DEFAULT 'cash'
      COMMENT '결제수단 (등록 시 사용자 선택)',
  ADD COLUMN er_section    VARCHAR(50) NULL
      COMMENT 'ER 배치 섹션: selling | not_selling | check_sup | other_exp_check | other_exp_cash | NULL(미배치)',
  ADD COLUMN is_cer_placed TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Cheque Expense Report 처리 여부',
  ADD COLUMN is_cd_paid    TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Cash Disbursement 처리 여부';

-- 인덱스 추가
ALTER TABLE office_receipts
  ADD INDEX idx_receipt_status (store_id, is_er_placed, er_section, receipt_date),
  ADD INDEX idx_cer_placed (store_id, is_cer_placed),
  ADD INDEX idx_cd_paid (store_id, is_cd_paid);
```

### 2.2 영수증 등록(add.php) 수정

- payment_type 선택 UI 추가 (Cash / Cheque 라디오/드롭다운)
- 저장 시 `payment_type` 컬럼에 저장

### 2.3 ER 저장(ajax_save_er.php) 수정

```
현재: is_er_placed=1 업데이트만
추가: er_section = 배치된 섹션명 업데이트

섹션 매핑:
  selling          → er_section='selling'
  not_selling      → er_section='not_selling'
  check_sup        → er_section='check_sup'
  other_exp_check  → er_section='other_exp_check'
  other_exp_cash   → er_section='other_exp_cash'

영수증 제거(ajax_unplace_item.php):
  is_er_placed=0, er_section=NULL 으로 초기화
```

### 2.4 CER 저장(ajax_save_cer.php) 수정

```
추가: r_ 아이템 처리
  is_cer_placed=1  (CER에 배치·저장 시)
  is_cer_placed=0  (CER에서 제거 시)
```

### 2.5 CD 저장(ajax_save_cd.php) 수정

```
추가: r_ 아이템 처리
  is_cd_paid=1  (CD에 배치·저장 시)
  is_cd_paid=0  (CD에서 제거 시)
```

### 2.6 CER 로딩(ajax_load_cheques.php) 단순화

```php
// 현재: 복잡한 JSON 파싱 + 이월 로직
// 개선 후:

// 당일 항목: receipt_date = 오늘 OR is_er_placed=1 AND er_section IN (check sections)
// 이월: er_section IN ('check_sup') AND is_cer_placed=0 (other_exp_check는 이월 없음)
```

### 2.7 CD 로딩(ajax_load_purchases.php) 단순화

```php
// 이월: er_section IN ('selling','not_selling','other_exp_cash') AND is_cd_paid=0
```

### 2.8 Receipt 목록(list.php) 개선

- `payment_type` 표시 (Cash / Cheque 배지)
- `er_section` 기반 Status 표시:
  - `er_section = NULL, is_er_placed=0` → Pending
  - `is_er_placed=1` → ER 등록 (섹션명 표시)
  - `is_cer_placed=1` → CER 완료
  - `is_cd_paid=1` → CD 완료

---

## 3. 수정 파일 목록

| 파일 | 변경 내용 |
|------|----------|
| `office/receipts/add.php` | payment_type 선택 UI + 저장 |
| `office/receipts/edit.php` | payment_type 수정 |
| `office/receipts/list.php` | payment_type·er_section 기반 Status 표시 개선 |
| `office/expense_report/ajax_save_er.php` | r_ 아이템 er_section 업데이트 추가 |
| `office/expense_report/ajax_unplace_item.php` | r_ 아이템 er_section=NULL 초기화 |
| `office/cheque_expense_report/ajax_save_cer.php` | r_ 아이템 is_cer_placed 업데이트 |
| `office/cheque_expense_report/ajax_load_cheques.php` | DB 컬럼 기반 로딩으로 단순화 |
| `office/cash_disbursement/ajax_save_cd.php` | r_ 아이템 is_cd_paid 업데이트 |
| `office/cash_disbursement/ajax_load_purchases.php` | DB 컬럼 기반 로딩으로 단순화 |
| `migration: run_receipt_status_migration.php` | 4개 컬럼 추가 마이그레이션 스크립트 |

---

## 4. 기존 데이터 처리

### 4.1 마이그레이션 시 기존 데이터 backfill

```sql
-- is_er_placed=1 인 영수증 중 er_saved_state에서 섹션 추출
-- (마이그레이션 스크립트에서 처리)

-- payment_type: 기존 영수증은 'cash' 기본값 유지
-- (수표 결제 영수증은 사용자가 edit.php에서 수정)

-- is_cer_placed, is_cd_paid: 기존 cer_saved_state, cd_saved_state 파싱하여 backfill
```

### 4.2 Backfill 전략

1. 마이그레이션 스크립트 실행 → 4개 컬럼 추가 (DEFAULT 값으로 초기화)
2. `er_saved_state` JSON 파싱 → `er_section` 업데이트
3. `cer_saved_state` JSON 파싱 → `is_cer_placed=1` 업데이트
4. `cd_saved_state` JSON 파싱 → `is_cd_paid=1` 업데이트

---

## 5. 구현 순서

1. **마이그레이션 스크립트** — 4개 컬럼 추가 + backfill
2. **ER 저장 로직** — `er_section` 업데이트 추가 (ajax_save_er.php, ajax_unplace_item.php)
3. **CER 저장 로직** — `is_cer_placed` 업데이트 추가 (ajax_save_cer.php)
4. **CD 저장 로직** — `is_cd_paid` 업데이트 추가 (ajax_save_cd.php)
5. **CER 로딩 단순화** — JSON 파싱 제거, DB 컬럼 기반으로 전환
6. **CD 로딩 단순화** — 동일
7. **영수증 등록/수정** — payment_type UI 추가
8. **영수증 목록** — 개선된 Status 표시

---

## 6. 완료 기준 (Success Criteria)

- [ ] `office_receipts`에 4개 컬럼 추가 및 기존 데이터 backfill 완료
- [ ] 영수증 등록 시 Cash/Cheque 선택 가능
- [ ] ER SAVE 시 r_ 영수증의 `er_section` 자동 업데이트
- [ ] ER에서 영수증 제거 시 `er_section=NULL` 복원
- [ ] CER SAVE 시 r_ 영수증 `is_cer_placed=1` 업데이트
- [ ] CD SAVE 시 r_ 영수증 `is_cd_paid=1` 업데이트
- [ ] CER 로딩: `er_section IN ('check_sup','other_exp_check')` 기반으로 동작
- [ ] CD 로딩: `er_section IN ('selling','not_selling','other_exp_cash')` 기반으로 동작
- [ ] `other_exp_check` 영수증이 다음날 CER에 이월되지 않음
- [ ] 영수증 목록 Status가 DB 컬럼 기반으로 정확히 표시
- [ ] 기존 데이터 영향 없음 (backfill로 상태 보존)

---

## 7. 제외 범위 (Out of Scope)

- 영수증과 purchase를 자동 매핑하는 AI 추천
- 영수증 OCR
- 영수증 유형 분류 (세금계산서/일반영수증 등)
- CER/CD 화면 UI 대규모 개편
