# Plan: Deferred Payment Processing (결제진행 기능)

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | Deferred 미결 항목 결제 시 Receipt 수동 등록 + 완료 표시 이중 작업 필요 |
| Solution | 업체 헤더의 결제진행 버튼 → 날짜 선택 → Receipt 자동 등록 + 일괄 완료 처리 |
| UX Effect | 버튼 1번으로 선택 날짜까지의 미결 전체를 1트랜잭션으로 결제 완결 |
| Core Value | Deferred → Receipt 연동 자동화, 결제 이력 추적 가능 |

## Context Anchor

| | |
|---|---|
| **WHY** | 미결 항목 결제 시 Receipt 등록과 완료 표시를 수동으로 이중 작업해야 하는 비효율 제거 |
| **WHO** | 매장 오피스 직원 (Deferred Tracker 사용자) |
| **RISK** | 중복 결제 방지, Receipt 등록 실패 시 롤백 필요 |
| **SUCCESS** | 결제 버튼 클릭 → Receipt 등록 + 항목 완료 처리가 1트랜잭션으로 완결 |
| **SCOPE** | `deferred_tracker/index.php`, `ajax_pay_dtr.php` 신규, DB 컬럼 2개 추가 |

## 1. 기능 흐름

```
[업체 열 헤더 "결제진행" 버튼 클릭]
        ↓
[결제 확인 모달]
  · 업체명 (고정)
  · 결제날짜 (기본: 오늘, 달력에서 변경 가능)
  · 미결 항목 목록 (선택날짜 이전 모든 pending, 이전 달 포함)
      예) 5월1일 ₱2,000 / 5월2일 ₱3,000
  · 합산금액 실시간 표시
        ↓
[결제 확인 클릭] → ajax_pay_dtr.php (POST)
        ↓ DB 트랜잭션
  ① office_receipts INSERT
     · receipt_date: 선택날짜
     · supplier_name: 업체명
     · amount: 합산금액
     · description: "5월1일 ₱2,000, 5월2일 ₱3,000, ..."
  ② deferred_entries UPDATE (해당 항목들)
     · status = 'paid'
     · paid_date = 선택날짜
     · receipt_id = 생성된 receipt ID
        ↓
[페이지 갱신 — 해당 셀 녹색(paid)으로 변경]
```

## 2. 요구사항

| # | 요구사항 | 비고 |
|---|----------|------|
| FR-01 | 업체 열 헤더에 "결제" 버튼 추가 | 미결 금액 있을 때만 활성화 |
| FR-02 | 결제 모달: 업체명·날짜선택·항목목록·합산액 | 날짜 변경 시 목록 실시간 갱신 |
| FR-03 | `office_receipts` INSERT (1건) | 트랜잭션 내 처리 |
| FR-04 | Description: `"5월1일 ₱2,000, 5월2일 ₱3,000"` | 한국어 월일 형식 |
| FR-05 | `deferred_entries` paid 처리 | status·paid_date·receipt_id 업데이트 |
| FR-06 | 결제 후 셀 녹색 갱신 | 페이지 리로드 |
| NFR-01 | 미결 항목 없으면 버튼 비활성화 | UX 보호 |
| NFR-02 | 이미 paid 항목 재결제 불가 | WHERE status='pending' 조건 |

## 3. DB 변경

```sql
-- deferred_entries에 결제 추적 컬럼 2개 추가 (자동 마이그레이션)
ALTER TABLE deferred_entries
  ADD COLUMN paid_date  DATE         DEFAULT NULL,
  ADD COLUMN receipt_id INT UNSIGNED DEFAULT NULL;
```

## 4. 구현 파일

| 파일 | 작업 |
|------|------|
| `deferred_tracker/index.php` | 업체헤더 결제버튼 + 결제모달 HTML + JS(날짜연동) |
| `deferred_tracker/ajax_pay_dtr.php` | 신규 — 트랜잭션(Receipt INSERT + entries UPDATE) |

## 5. Description 생성 로직

```php
// 항목별: "5월1일 ₱2,000"
$parts = [];
foreach ($pending_entries as $e) {
    $ts    = strtotime($e['entry_date']);
    $label = (int)date('n',$ts) . '월' . (int)date('j',$ts) . '일';
    $parts[] = $label . ' ₱' . number_format($e['amount'], 0);
}
$description = implode(', ', $parts);
```
