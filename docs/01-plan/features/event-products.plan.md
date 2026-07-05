# Plan: 행사상품 등록 (Event Products)

**Feature**: event-products  
**Phase**: Plan  
**Created**: 2026-05-01  
**Author**: whdans007

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 점포별 행사/프로모션 상품을 별도로 관리할 방법이 없어 행사 할인가 적용 및 판매 이력 추적이 어렵다 |
| **Solution** | 점간이동 섹션에 행사상품 등록 기능을 추가해 원가·정가 참조 후 행사가를 등록하고 판매 이력을 관리한다 |
| **UX Effect** | 상품 검색 → 원가/판매가 자동 표시 → 행사가 입력 → 저장의 직관적인 플로우로 빠른 행사 운영 지원 |
| **Core Value** | 점포별 행사 가격 관리 자동화로 수기 관리 오류 제거 및 행사 매출 이력 추적 가능 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 행사/프로모션 상품을 점포별로 독립 관리하고 할인가 적용 이력을 추적하기 위함 |
| **WHO** | admin/super_admin 역할의 점포 관리자 |
| **RISK** | 기존 inventory/price_change_history 테이블 구조에 의존 — 가격 데이터 없는 신규 상품 처리 필요 |
| **SUCCESS** | 행사상품 등록, 원가/판매가 자동 조회, 행사가 저장, 행사 목록 조회 4개 기능 정상 동작 |
| **SCOPE** | admin/partials/header.php 네비게이션 + 신규 PHP 페이지 4개 + DB 테이블 1개 |

---

## 1. 배경 및 목적

현재 관리자 시스템에는 점포별 행사/프로모션 상품을 전용으로 관리하는 기능이 없다.
- 원가(`inventory.cost_price`)와 판매가(`price_change_history.new_selling_price`)는 DB에 있으나 행사가 관리 테이블이 없음
- 행사 기간, 할인가, 판매 수량 추적 불가

---

## 2. 요구사항

### 2.1 핵심 기능 (Must Have)

| ID | 요구사항 |
|----|----------|
| F-01 | 상품 검색 시 기존 원가(cost_price) + 판매가(price_change_history) 자동 표시 |
| F-02 | 행사 할인가 직접 입력 |
| F-03 | 행사 기간(시작일~종료일) 설정 |
| F-04 | 점포별 독립 행사상품 등록/조회 |
| F-05 | 행사상품 목록 조회 (진행 중 / 종료 필터) |
| F-06 | 행사상품 수정/삭제 |

### 2.2 부가 기능 (Nice to Have)

| ID | 요구사항 |
|----|----------|
| N-01 | 행사 비고/메모 입력 |
| N-02 | 할인율(%) 자동 계산 표시 |

---

## 3. 기술 스펙

### 3.1 신규 파일

| 파일 | 역할 |
|------|------|
| `admin/event_products.php` | 행사상품 등록 폼 |
| `admin/event_products_list.php` | 행사상품 목록/관리 |
| `admin/ajax_search_event_products.php` | 상품 검색 + 원가/판매가 조회 AJAX |
| `admin/ajax_save_event_product.php` | 행사상품 저장/수정/삭제 AJAX |

### 3.2 수정 파일

| 파일 | 변경 내용 |
|------|-----------|
| `admin/partials/header.php` | 점간이동 섹션에 행사상품 메뉴 2개 추가 |

### 3.3 신규 DB 테이블

```sql
CREATE TABLE event_products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    store_id        INT NOT NULL,
    product_id      INT NOT NULL,
    event_price     DECIMAL(10,2) NOT NULL,
    original_cost   DECIMAL(10,2) DEFAULT NULL,   -- 등록 시점 원가 스냅샷
    original_selling DECIMAL(10,2) DEFAULT NULL,  -- 등록 시점 판매가 스냅샷
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    remarks         VARCHAR(255) DEFAULT NULL,
    is_active       TINYINT(1) DEFAULT 1,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

### 3.4 권한

- 기존 `store_transfer_management` 권한 재사용 (점간이동 섹션과 동일)
- super_admin: 모든 점포 조회 가능
- admin: 자기 점포만 조회

---

## 4. 데이터 플로우

```
사용자 → 상품 검색
         ↓
    ajax_search_event_products.php
    (products JOIN inventory JOIN price_change_history)
         ↓
    원가 + 판매가 + 할인율 자동 표시
         ↓
    행사가 직접 입력 + 기간 선택
         ↓
    ajax_save_event_product.php
    → INSERT INTO event_products
```

---

## 5. UI 흐름

```
[event_products_list.php] ← 메인 화면 (행사상품 목록)
        ↓ "새 행사상품 등록" 버튼
[event_products.php] ← 등록 폼
  - 점포 선택 (super_admin만)
  - 상품 검색 (이름/SKU)
  - 원가: ₩XX,XXX (자동)
  - 판매가: ₩XX,XXX (자동)
  - 행사가: 직접 입력
  - 할인율: XX% (자동계산 표시)
  - 기간: YYYY-MM-DD ~ YYYY-MM-DD
  - 비고: 텍스트
```

---

## 6. 성공 기준

| 기준 | 측정 방법 |
|------|----------|
| 상품 검색 시 원가/판매가 1초 내 자동 표시 | 브라우저 직접 테스트 |
| 행사상품 등록 → DB 저장 정상 | event_products 테이블 레코드 확인 |
| 점포별 목록 필터링 정상 | admin vs super_admin 접속 비교 |
| 행사 수정/삭제 정상 | 목록에서 수정/삭제 후 반영 확인 |

---

## 7. 위험 요소

| 위험 | 대응 |
|------|------|
| 상품에 판매가 이력이 없는 경우 | price_change_history 없으면 NULL 표시 + 행사가만 입력 허용 |
| 동일 상품을 중복 등록하는 경우 | 등록 시 경고 표시 (강제 차단 안함) |
| 행사 기간 종료 후 목록 관리 | is_active 플래그 + 날짜 필터로 처리 |
