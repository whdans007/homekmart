# Plan: 월마감 REPORT (monthly-closing-report)

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 월마감 시 물품구매·재고이동·인건비·경비·전기세·월세를 각 화면에서 따로 확인해야 함 |
| **Solution** | 7개 항목의 월합계를 한 화면에 표시하는 월마감 요약 리포트 |
| **UX Effect** | 월 선택 → 7개 항목 합계 즉시 확인, 전체 합계 자동 계산 |
| **Core Value** | 월마감 체크리스트 역할, 여러 화면을 오가는 수고 제거 |

## 항목 및 데이터 소스

| 순서 | 항목 | 데이터 소스 |
|------|------|-------------|
| 1 | 총 물품구매액 | `office_product_purchases` (cash/check) + ER r_ items |
| 2 | 타 지점으로 물품 이동 (OUT) | `store_transfers` (from_store_id) |
| 3 | 타 지점으로부터 물품 이동 (IN) | `sales_transfers` (direction='in') |
| 4 | 현지 직원 인건비 | ER other_exp_* Salary 키워드 |
| 5 | 사무실 경비(부속품 일체) | `office_equipment_purchases` + ER not_selling |
| 6 | 전기세 | ER other_exp_* Electricity 키워드 |
| 7 | 월세 | ER other_exp_* Rent 키워드 |

## 파일 구조
- `office/product_purchase/monthly_closing.php` — 리포트 페이지
- `office/partials/header.php` — 메뉴 추가

## YAGNI
- ✅ 7개 항목 월합계 요약
- ✅ 월 네비게이션
- ❌ 엑셀 다운로드 (v2)
- ❌ 상세 내역 드릴다운 (v2)
