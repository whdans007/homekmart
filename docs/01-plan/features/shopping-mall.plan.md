---
template: plan-plus
version: 1.0
feature: shopping-mall
date: 2026-08-12
author: whdans007
project: HOME K MART
version_project: 1.0.0
---

# shopping-mall Planning Document

> **Summary**: 기존 상품/재고 데이터를 재사용해 도매몰(사업자 회원)과 소매몰(일반/할인/우수 회원)을 한 앱으로 통합 구축하고, 전용 통합 관리자 페이지에서 상품 큐레이션·회원승인·할인규칙·주문을 관리한다.
>
> **Project**: HOME K MART
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-08-12
> **Status**: Draft
> **Method**: Plan Plus (Brainstorming-Enhanced PDCA)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 상품·재고·마진 데이터는 내부 관리용으로만 존재하고, 고객이 직접 가입해 도매가/소매가로 주문하는 온라인 쇼핑몰이 없다. 과거 시도(`shop/` 등 45개 파일)는 실제 DB 스키마와 맞지 않아 2026-08-12 전량 폐기되었다. |
| **Solution** | 신규 `mall/` 단일 앱에서 도매몰·소매몰을 회원 등급 기반 가격정책으로 분기하고, `mall/admin/`에 전용 통합 관리자 페이지를 두어 상품 큐레이션·회원 승인·할인 규칙·주문을 한 곳에서 관리한다. |
| **Function/UX Effect** | 소매 회원은 가입 즉시 등급별(일반/할인/우수) 할인가로 구매하고 누적 실적에 따라 월별로 등급이 상승한다. 도매 회원(사업자)은 관리자 승인 후에만 도매가가 노출되며, 주문금액 구간별 즉석할인과 월별 누적실적 등급 할인이 함께 적용된다. |
| **Core Value** | 기존 `products`/`inventory`/`wholesale_products` 인프라를 그대로 재사용하여 이중 입력·이중 관리 없이 고객향 판매 채널을 확장한다. |

---

## 1. User Intent Discovery

### 1.1 Core Problem

기존 상품정보(카탈로그·점포별 재고·원가·판매가·기존 도매상품 큐레이션)를 그대로 활용하되, 관리자가 원하는 상품만 선택해 사진을 등록하고, 도매(사업자 회원 · 즉석할인 · 누적실적 할인) / 소매(일반·할인·우수 회원 등급별 할인) 두 축의 고객향 쇼핑몰을 만들고 싶다. 회원가입/로그인은 기존 관리자 계정 체계와 별도로 운영하며, 전체 쇼핑몰 운영은 하나의 통합 관리자 페이지에서 처리하고 싶다.

### 1.2 Target Users

| User Type | Usage Context | Key Need |
|-----------|---------------|----------|
| 사업자(도매) 회원 | 가입 후 관리자 승인을 받아 도매몰에서 박스 단위로 주문 | 도매가 노출, 주문금액 구간별 즉석할인, 누적실적에 따른 추가 할인 |
| 일반/할인/우수 회원(소매) | 가입 즉시 소매몰에서 등급별 할인가로 주문 | 별도 절차 없이 바로 이용, 누적 구매실적에 따라 자동 등급 상승 |
| 쇼핑몰 관리자 | `mall/admin/`에서 기존 admin 계정으로 로그인 | 상품 큐레이션·사진등록, 도매 회원 승인, 할인규칙 설정, 주문 확인/처리를 한 화면 체계에서 처리 |

### 1.3 Success Criteria

- [ ] 도매/소매 회원이 각각 가입·로그인하여 등급에 맞는 가격을 확인할 수 있다
- [ ] 관리자가 기존 `products` 중 원하는 상품만 선택하고 사진을 등록해 소매몰에 노출할 수 있다
- [ ] 소매 판매가는 `inventory.selling_price`, 도매가는 `wholesale_products.wholesale_price`와 실시간 연동되어 별도 입력이 필요 없다
- [ ] 도매 주문금액 구간별 즉석할인과 월별 누적실적 등급 할인이 정확히 계산되어 적용된다
- [ ] 소매 등급별(일반/할인/우수) 할인율이 정확히 적용되고 월별 배치로 등급이 재산정된다
- [ ] 미승인 도매 회원에게는 어떤 화면에서도 도매가가 노출되지 않는다
- [ ] 관리자가 `mall/admin/` 한 곳에서 상품·회원·할인규칙·주문을 모두 관리할 수 있다

### 1.4 Constraints

| Constraint | Details | Impact |
|------------|---------|--------|
| 단일 점포 기준 | 쇼핑몰이 노출하는 재고/가격은 `mall/config/mall_config.php`의 `store_id` 상수 하나로 고정 (다점포 선택 기능 없음) | Medium |
| 결제 미포함 | v1은 주문 접수까지만 처리하며 결제는 오프라인/COD로 별도 진행 (온라인 결제 게이트웨이 없음) | Medium |
| 기존 DB와의 충돌 회피 | 신규 테이블은 모두 `mall_` 접두사 사용. 2026-08-12에 폐기된 `shop_*`/레이아웃 빌더 관련 테이블명과 절대 겹치지 않아야 함 | High |
| 도매가 보안 | 승인되지 않은 사업자 회원 또는 소매 회원에게 도매가가 노출되면 안 됨. 모든 가격 계산은 `mall/lib/pricing.php` 단일 경로를 강제 | High |
| 기존 배달앱과 무관 | `delivery_*` 테이블/Flutter 앱과는 완전히 독립적인 신규 시스템으로 개발 (통합하지 않음) | Low |

---

## 2. Alternatives Explored

### 2.1 Approach A: 통합 단일 앱 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | `mall/` 하나의 앱/DB에서 회원 등급 기반 정책 계층으로 도매·소매를 분기하고, `mall/admin/`에 전용 통합 관리자 페이지를 둔다 |
| **Pros** | 회원가입/로그인/장바구니/주문 로직 중복 없음. 관리자 페이지 1개로 전체 운영. 오늘 폐기된 이전 구현처럼 구조가 흩어지지 않음 |
| **Cons** | 가격·할인 계산에 등급 분기 로직이 누적되면 복잡해질 수 있음 → `pricing.php`로 단일화하여 완화 |
| **Effort** | Medium-High |
| **Best For** | 기반 데이터(상품/재고/도매상품)가 이미 공유되어 있고, "등급별로 다르게 보이는 하나의 몰"을 원하는 현재 상황 |

### 2.2 Approach B: 완전 분리 앱

| Aspect | Details |
|--------|---------|
| **Summary** | `mall/wholesale/`, `mall/retail/` 두 개의 독립 앱 트리 + 공용 `lib/` 공유, 템플릿/라우팅 완전 분리 |
| **Pros** | 도매가 노출 로직이 소매 코드와 물리적으로 격리되어 오노출 위험이 구조적으로 낮음 |
| **Cons** | 화면/라우트 유지보수 포인트 2배. "공통 뼈대 동시 구축" 의도와 어긋남 |
| **Effort** | High |
| **Best For** | 도매/소매 UI·흐름이 완전히 다르고 팀이 커서 분업할 때 |

### 2.3 Decision Rationale

**Selected**: Approach A
**Reason**: 사용자가 "공통 뼈대 동시 구축"을 명시적으로 선택했고, 오늘 폐기된 이전 쇼핑몰 시도가 지나치게 흩어진 구조(존재하지 않는 테이블을 참조하는 레이아웃 빌더 등)였던 것을 감안하면 단순하고 검증하기 쉬운 통합 구조가 더 안전하다. 도매가 오노출 문제는 앱을 물리적으로 분리하는 대신 `pricing.php` 단일화 + 관리자 승인 플래그로 방지한다.

---

## 3. YAGNI Review

### 3.1 Included (v1 Must-Have)

- [ ] 회원가입/로그인 (소매 즉시가입, 도매 승인 대기)
- [ ] 비밀번호 찾기 / 회원정보 수정
- [ ] 관리자의 회원 등급 수동 변경
- [ ] 도매 회원 관리자 승인 플로우 (승인 전 도매가 비노출)
- [ ] 소매 등급별 할인율(일반/할인/우수) 적용
- [ ] 도매 즉석할인 (주문금액 구간별)
- [ ] 도매 누적실적 등급 할인 (월별 재산정 배치)
- [ ] 상품 카테고리/검색/필터
- [ ] 재고연동 품절표시
- [ ] 상품당 다중 이미지 업로드
- [ ] 리뷰/평점
- [ ] 장바구니 / 주문 접수 (결제 없음, COD/오프라인)
- [ ] 마이페이지 주문내역 조회
- [ ] 위시리스트 / 장바구니 보관
- [ ] 영어 다국어 지원
- [ ] 주문 확정 시 재고(`inventory.quantity`) 즉시 차감
- [ ] `mall/admin/`: 상품 큐레이션, 회원 관리, 할인규칙 설정, 주문 관리 통합 페이지

### 3.2 Deferred (v2+ Maybe)

| Feature | Reason for Deferral | Revisit When |
|---------|---------------------|--------------|
| 쿠폰/프로모션 코드 | 등급 할인 + 즉석/누적 할인만으로 v1 요구사항 충분, 코드 발급/검증 로직은 별도 설계 필요 | v1 운영 후 프로모션 필요성이 확인되면 |
| 온라인 결제 연동(카드/GCash 등) | v1은 주문 접수(오프라인 결제)까지만 | 결제 대행사 계약/연동 필요성이 생기면 |
| 기존 배달앱(`delivery_*`)과의 통합 | 완전히 별도 시스템으로 개발하기로 확정 | 두 시스템 운영 부담이 커지면 재검토 |

### 3.3 Removed (Won't Do)

| Feature | Reason for Removal |
|---------|-------------------|
| 비회원(게스트) 주문 | 도매는 승인 절차상 회원제가 필수이며, 소매도 회원제로 일관성 있게 운영하기로 결정 |
| 다점포 선택/전환 노출 | 단일 점포(store_id 고정) 기준으로 v1 운영하기로 결정 |

---

## 4. Scope

### 4.1 In Scope

- [ ] `mall_members` 등 신규 회원/주문/할인 관련 테이블 설계 및 생성 (`sql/mall_schema.sql`)
- [ ] 고객용 쇼핑몰(`mall/`): 카탈로그, 상품상세, 장바구니, 주문접수, 마이페이지, 회원가입/로그인
- [ ] 가격/할인 계산 단일 모듈(`mall/lib/pricing.php`)
- [ ] 도매가는 기존 `wholesale_products` 재사용, 소매 상품은 신규 `mall_products`로 큐레이션
- [ ] `mall/admin/`: 통합 관리자 페이지(상품/회원/할인규칙/주문)
- [ ] 월별 등급 재산정 배치(`mall/batch/recalc_tiers.php`)

### 4.2 Out of Scope

- 쿠폰/프로모션 코드 — (from YAGNI Review)
- 온라인 결제 게이트웨이 연동 — (from YAGNI Review)
- 기존 `delivery_*` 배달앱과의 통합 — (from YAGNI Review)
- 비회원 주문, 다점포 선택 — (from YAGNI Review)

---

## 5. Requirements

### 5.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 소매 회원은 가입 즉시 로그인하여 등급별(일반/할인/우수) 할인가를 볼 수 있다 | High | Pending |
| FR-02 | 도매(사업자) 회원은 가입 후 관리자 승인이 완료되어야 도매가가 노출된다 | High | Pending |
| FR-03 | 관리자는 `mall/admin/products.php`에서 기존 `products` 중 원하는 상품을 선택하고 사진을 업로드해 소매몰에 노출할 수 있다 | High | Pending |
| FR-04 | 소매 판매가는 `inventory.selling_price`(대상 store_id 기준)를 실시간 조회하여 표시한다 | High | Pending |
| FR-05 | 도매가는 `wholesale_products.wholesale_price`를 실시간 조회하여 표시한다 | High | Pending |
| FR-06 | 도매 주문은 현재 장바구니 금액 구간에 따라 관리자가 설정한 즉석할인율이 자동 적용된다 | High | Pending |
| FR-07 | 도매/소매 회원의 누적 구매실적은 월 1회 배치로 재계산되어 등급(및 도매 누적할인)에 반영된다 | High | Pending |
| FR-08 | 회원은 장바구니에 상품을 담고 결제 없이 주문을 접수할 수 있다(COD/오프라인) | High | Pending |
| FR-09 | 주문 확정 시 해당 상품의 `inventory.quantity`가 즉시 차감된다 | High | Pending |
| FR-10 | 회원은 마이페이지에서 본인의 주문내역과 위시리스트를 조회/관리할 수 있다 | Medium | Pending |
| FR-11 | 관리자는 `mall/admin/members.php`에서 도매 회원을 승인/반려하고 회원 등급을 수동 변경할 수 있다 | High | Pending |
| FR-12 | 관리자는 `mall/admin/discount_rules.php`에서 소매 등급 할인율, 도매 즉석할인 구간, 도매 누적등급 기준을 설정/수정할 수 있다 | High | Pending |
| FR-13 | 상품 목록은 카테고리/키워드로 검색·필터링할 수 있으며, 재고 0인 상품은 품절로 표시되고 주문이 불가하다 | Medium | Pending |
| FR-14 | 구매 회원은 상품에 리뷰/평점을 남길 수 있다 | Low | Pending |
| FR-15 | 쇼핑몰 UI는 한국어/영어 다국어를 지원한다 | Medium | Pending |

### 5.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Security | 미승인 도매 회원 및 소매 회원에게 도매가가 어떤 API/화면에서도 노출되지 않음 (모든 가격 계산은 `pricing.php` 경유) | 코드 리뷰 + 승인 전/후 계정으로 수동 검증 |
| Security | 모든 DB 쿼리는 prepared statement 사용, 회원 비밀번호는 `password_hash()` 저장 | 코드 리뷰 |
| Data Consistency | 판매가/도매가는 원본(`inventory`/`wholesale_products`) 변경 시 별도 동기화 작업 없이 즉시 반영 | 원본 가격 변경 후 쇼핑몰 화면 확인 |
| Correctness | 원가/합계 금액은 소숫점 둘째자리까지 표시 (CLAUDE.md 규칙) | 화면 검수 |

---

## 6. Success Criteria

### 6.1 Definition of Done

- [ ] 모든 FR-01 ~ FR-15 구현 완료
- [ ] `sql/mall_schema.sql`이 실제 라이브 DB에 오류 없이 적용됨 (기존 테이블과 명칭 충돌 없음)
- [ ] 도매/소매 각각 테스트 계정으로 가격 노출·할인 계산 시나리오 검증 완료
- [ ] 관리자 승인 전 도매가 비노출 시나리오 검증 완료
- [ ] 코드 리뷰 완료

### 6.2 Quality Criteria

- [ ] `php -l`로 신규 파일 전체 문법 검증 통과
- [ ] 가격 계산 함수(`pricing.php`)에 대한 수동 테스트 케이스(등급별×할인구간별) 문서화
- [ ] 신규 admin 화면이 기존 `has_permission()` 권한 체계를 따름

---

## 7. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 도매가가 미승인/소매 회원에게 노출됨 | High | Medium | 모든 가격 조회를 `pricing.php` 단일 함수로 강제하고, 서버사이드에서 회원 승인상태를 매 요청마다 재검증 |
| `wholesale_products` 재사용 시 "내부 직원용 데이터"와 "고객 노출용 데이터"의 의미가 섞임 | Medium | Medium | 신규 컬럼 추가 없이 읽기 전용으로만 참조(`wholesale_price`, `wholesale_name_ko/en`), 고객 노출 여부는 `mall_wholesale_visibility`(신규, 상품별 노출 ON/OFF)로 별도 제어 |
| 신규 테이블명이 오늘 폐기된 `shop_*`/레이아웃 빌더 잔재와 충돌 | High | Low | 전체 신규 테이블에 `mall_` 접두사 강제, 스키마 적용 전 기존 테이블명 전수 대조 |
| 주문 시 재고 차감과 기존 관리자 발주/판매 프로세스 간 동시성 문제 | Medium | Low | 주문 확정 트랜잭션 내에서 `inventory.quantity` 차감 처리(`autocommit(false)` + `commit()`/`rollback()`) |
| 검증되지 않은 스키마로 설계해 다시 폐기되는 반복 | High | Low | 본 계획은 실제 라이브 DB 덤프(`u622428657_homekmart.sql`) 기준으로 작성됨, 설계 단계에서 재확인 필수 |

---

## 8. Architecture Considerations

### 8.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure | Static sites, portfolios | |
| **Dynamic** | Feature-based modules, 기존 PHP/MySQL 구조 확장 | 백엔드 있는 웹앱 | ✅ |
| **Enterprise** | 마이크로서비스, 엄격한 계층분리 | 대규모 시스템 | |

### 8.2 Key Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 아키텍처 | 통합 단일 앱 / 완전 분리 앱 / 라우트만 분리 | 통합 단일 앱 (`mall/`) | 공통 뼈대 동시 구축 요청, 단순하고 검증 쉬운 구조 선호 |
| 회원 테이블 | 기존 `users` 확장 / 신규 `mall_members` | 신규 `mall_members` | 회원가입/로그인을 관리자 계정과 완전히 별도로 운영 요청 |
| 관리자 UI 위치 | 기존 `admin/`에 통합 / `mall/admin/` 독립 | `mall/admin/` 독립, 로그인은 기존 admin 계정 재사용 | "쇼핑몰 관리프로그램을 별도로 만들어 한 페이지로 관리" 요청 |
| 판매 대상 점포 | 다점포 선택 / 단일 점포 고정 | 단일 점포 고정 (`mall_config.php`) | 단일 점포 기준으로 결정 |
| 도매가 소스 | 신규 도매가 테이블 / 기존 `wholesale_products` 재사용 | 기존 `wholesale_products` 재사용 | 이미 직원이 큐레이션한 도매상품·가격을 이중 관리하지 않기 위함 |
| 결제 | 온라인 결제 포함 / 주문 접수까지만 | 주문 접수까지만 (COD/오프라인) | 결제는 v1 범위 외로 결정 |
| 등급 재산정 주기 | 실시간 / 월별 배치 | 월별 배치 | 정기 배치로 재산정하기로 결정 |
| 도매 승인 | 자동 승인 / 관리자 승인 필요 | 관리자 승인 필요 | 도매가는 민감 정보이므로 승인 절차 필수 |

### 8.3 Component Overview

```
W:\sunset\
├── mall/                          ← 신규: 쇼핑몰 전체 (고객용 + 전용 관리자)
│   ├── index.php / product.php / cart.php / checkout.php
│   ├── login.php / signup.php     회원가입·로그인 (일반/사업자 선택)
│   ├── mypage/                    주문내역, 위시리스트, 회원정보수정
│   ├── lib/
│   │   ├── auth.php               mall_members 전용 세션 관리
│   │   ├── pricing.php            회원×상품 → 최종 판매가 계산 (단일 경로)
│   │   ├── cart.php               장바구니 CRUD
│   │   └── order.php              주문 생성 + 재고 차감
│   ├── batch/recalc_tiers.php     월 1회 등급/누적할인 재산정 배치
│   ├── config/mall_config.php     대상 점포(store_id) 설정
│   │
│   └── admin/                     ← 쇼핑몰 전용 통합 관리자 페이지
│       ├── login.php               기존 admin 계정 재사용 (mall_management 권한)
│       ├── dashboard.php           주문현황·승인대기·매출 요약
│       ├── products.php            소매몰 상품 큐레이션(선택+사진 업로드)
│       ├── wholesale_products.php  도매몰 상품 노출관리(wholesale_products 연동)
│       ├── members.php             회원 목록·도매 승인·등급 수동변경
│       ├── discount_rules.php      즉석할인/누적등급/소매 등급 할인율 설정
│       └── orders.php              주문 접수 확인·상태 변경
│
└── sql/mall_schema.sql            신규 테이블 (mall_ 접두사)
```

### 8.4 Data Flow

```
[회원가입]
  소매(일반/할인/우수) ─→ 즉시 활성화, 등급은 "일반"부터 시작
  도매(사업자)        ─→ "승인대기" 상태로 가입 ─→ 관리자 승인 ─→ 도매가 노출 시작

[로그인 → 카탈로그 조회]
  회원 유형 판별 → pricing.php 호출
    소매회원: inventory.selling_price → 등급 할인율 차감 → 화면 표시
    도매회원(승인됨): wholesale_products.wholesale_price → 즉석할인(장바구니 금액 구간) 차감 → 화면 표시
    도매회원(미승인): 도매가 비노출, "승인 대기중" 안내

[장바구니 → 주문 접수]
  mall_cart_items → checkout → mall_orders/mall_order_items 생성(가격 스냅샷)
  → inventory.quantity 즉시 차감(트랜잭션) → 결제 없음, 상태 'pending'으로 접수 완료
  → 고객: mypage에서 주문내역 확인 / 관리자: mall/admin/orders.php에서 상태 변경

[월 1회 배치]
  완료된 mall_orders 누적 합계 계산
  → 소매: 구간 기준 등급 재산정(일반/할인/우수)
  → 도매: 누적 기준 다음 달 적용 누적할인 등급 재산정
```

---

## 9. Convention Prerequisites

### 9.1 Applicable Conventions

- [x] 기존 프로젝트 컨벤션 확인됨 (CLAUDE.md: PHP + MySQLi/PDO, TailwindCSS, 한국어 UI, 원가/합계 소숫점 둘째자리 표시, 새 SQL 쿼리는 별도 파일)
- [x] 명명 규칙 확인됨 (신규 테이블 `mall_` 접두사, 기존 `wholesale_*`/`inventory`/`products`와 컬럼명 충돌 없음)
- [x] 폴더 구조 규칙 확인됨 (`admin/`, `order/`, `kimsmall_wherehouse/`와 병렬되는 최상위 `mall/` 신설)

---

## 10. Next Steps

1. [ ] 설계 문서 작성 (`/pdca design shopping-mall`)
2. [ ] `sql/mall_schema.sql` 스키마를 실제 라이브 DB와 대조 검증
3. [ ] 구현 시작 (`/pdca do shopping-mall`)

---

## Appendix: Brainstorming Log

| Phase | Question | Answer | Decision |
|-------|----------|--------|----------|
| Intent | 1차 개발 범위(MVP) | 공통 뼈대 동시 구축 | 도매/소매를 회원등급 정책으로 분기하는 통합 구조 채택 |
| Intent | 배달앱과의 관계 | 완전히 별도 신규 시스템 | delivery_* 와 통합하지 않음 |
| Intent | 노출 재고/가격 기준 | 특정 점포 1곳 기준 | store_id 상수 고정 |
| Intent | 결제 방식 | 주문 접수까지만(오프라인/COD) | 온라인 결제 게이트웨이 Out of Scope |
| Intent | 도매 승인 방식 | 관리자 승인 후에만 도매가 노출 | 승인 플로우 필수, 보안 리스크 1순위로 관리 |
| Intent | 할인규칙 관리 방식 | 관리화면에서 설정 가능해야 함 | discount_rules.php로 동적 규칙 관리 |
| Intent | 등급 재산정 주기 | 정기 배치(월별) | recalc_tiers.php 월 1회 배치 |
| Alternatives | 아키텍처 A/B/C 비교 | A(통합 단일 앱) 선택 | 단순·검증용이성 우선, pricing.php로 보안 리스크 완화 |
| YAGNI | 회원/할인/카탈로그/주문 기능 선택 | 게스트주문·쿠폰코드만 제외, 나머지 전부 포함 | 리뷰/평점, 위시리스트, 다국어까지 v1 포함 |
| Design | 관리자 페이지 위치 | 기존 admin/에 통합 → mall/admin/ 독립으로 수정 | 사용자가 "쇼핑몰 관리프로그램을 별도로" 요청과 일치하도록 재조정 |
| Design | 핵심 컴포넌트 구성 | 승인 | pricing.php 단일화로 도매가 보안 확보 |
| Design | 데이터 흐름 | 승인 | 가입→승인/즉시활성화→가격조회→주문→월배치 흐름 확정 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-12 | Initial draft (Plan Plus) | whdans007 |
