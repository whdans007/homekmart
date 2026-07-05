# Plan: 업체별 월간 입고 보고서 (purchase-monthly-report)

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 매월 제출하는 보고서에 업체별 입고 내역(날짜·상품·금액)이 필요하지만, 현재 product_purchase 화면은 일별/월별 전체 리스트만 제공 |
| **Solution** | 업체별로 그룹핑된 월간 입고 보고서 페이지 + CSV 다운로드 |
| **UX Effect** | 월 선택 → 업체별 소계·총합계 한눈에 확인 → Excel 다운로드로 바로 제출 가능 |
| **Core Value** | 반복 수작업(복사·붙여넣기·합산) 제거, 월별 보고서 즉시 생성 |

---

## 1. 기능 개요

- **기능명**: 업체별 월간 입고 보고서
- **위치**: `office/product_purchase/monthly_report.php`
- **대상 사용자**: office_staff, admin, super_admin
- **데이터 소스**: `office_product_purchases` 테이블

## 2. User Intent Discovery

- **핵심 문제**: 월간 업체별 입고 현황을 보고서 형식으로 조회·제출해야 함
- **대상 사용자**: 오피스 스태프 (보고서 작성·제출 담당)
- **성공 기준**: 월 선택 → 업체별 소계·총합 자동 계산 → Excel 다운로드 1회 클릭으로 제출 가능

## 3. 범위

### In Scope (v1)
- 월 선택 네비게이션 (prev/next, year-month dropdown)
- 업체별 그룹 테이블 (날짜 | 내용 | 금액)
- 업체별 소계 행
- 월 전체 합계
- CSV(Excel) 다운로드

### Out of Scope (이후 버전)
- 현금/수표 구분 색상 표시
- 업체 필터 검색
- PDF 인쇄 뷰

## 4. Alternatives Explored

| 방식 | 결과 |
|------|------|
| A. 업체별 그룹화 (선택) | 보고서 제출에 최적, 소계 자동 |
| B. 날짜 순 리스트 | 조회엔 편하나 보고서 제출 부적합 |
| C. 피벗 매트릭스 | 상품명 표시 불가로 제외 |

## 5. 데이터 구조

### 사용 테이블: office_product_purchases
| 컬럼 | 설명 |
|------|------|
| supplier_name | 거래처명 (그룹핑 키) |
| delivery_content | 배달상품 내용 |
| amount | 금액 |
| payment_date | 현금: 결제일 (날짜 기준) |
| check_issued_date | 수표: 발행일 (날짜 기준) |
| payment_type | cash / check |

**날짜 기준**: 현금=payment_date, 수표=check_issued_date (기존 list.php 동일)

### 쿼리 전략
```sql
SELECT supplier_name, delivery_content, amount,
       CASE WHEN payment_type='cash' THEN payment_date ELSE check_issued_date END AS ref_date,
       payment_type
FROM office_product_purchases
WHERE store_id=?
  AND ((payment_type='cash' AND YEAR(payment_date)=? AND MONTH(payment_date)=?)
    OR (payment_type='check' AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=?))
ORDER BY supplier_name, ref_date
```

PHP에서 `supplier_name` 기준으로 그룹핑.

## 6. 파일 구조

```
office/product_purchase/
  monthly_report.php          ← 보고서 화면
  monthly_report_export.php   ← CSV 다운로드
```

## 7. UI 레이아웃

```
[← 2026-4월]  [2026-5월 입고 현황]  [2026-6월 →]  [Excel 다운로드]

┌──────────────────────────────────────────────────────────┐
│ 업체명: COCO COLA                                         │
├──────────┬──────────────────────────┬────────────────────┤
│ 날짜     │ 내용                      │ 금액               │
├──────────┼──────────────────────────┼────────────────────┤
│ 05/01    │ 콜라 100개               │ 50,000.00          │
│ 05/03    │ 사이다 200개             │ 80,000.00          │
├──────────┴──────────────────────────┼────────────────────┤
│                             소계    │ 130,000.00         │
├─────────────────────────────────────┴────────────────────┤
│ 업체명: 백세주                                            │
│ ...                                                      │
│                             소계    │ ...                │
├──────────────────────────────────────────────────────────┤
│                          총 합계    │ XXX,XXX.00         │
└──────────────────────────────────────────────────────────┘
```

## 8. YAGNI Review

| 항목 | 결정 |
|------|------|
| 월 선택 네비게이션 | ✅ 포함 |
| 업체 그룹 + 소계 | ✅ 포함 |
| 월 전체 합계 | ✅ 포함 |
| Excel 다운로드 | ✅ 포함 |
| 현금/수표 색상 구분 | ❌ 제외 (v2) |
| 업체 검색 필터 | ❌ 제외 (v2) |

## 9. 네비게이션 연결

기존 `list.php` 상단 또는 `office/index.php`에 "월간 보고서" 링크 추가 예정.
