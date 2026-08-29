---
template: design
version: 1.3
feature: shopping-mall
date: 2026-08-12
author: whdans007
project: HOME K MART
version_project: 1.0.0
---

# shopping-mall Design Document

> **Summary**: 도매몰·소매몰 통합 쇼핑몰(`mall/`)의 상세 기술 설계. 회원/가격/할인/주문/관리자 페이지의 데이터 모델, API(ajax) 스펙, 화면 구성, 보안·테스트 계획을 정의한다.
>
> **Project**: HOME K MART
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-08-12
> **Status**: Draft
> **Planning Doc**: [shopping-mall.plan.md](../01-plan/features/shopping-mall.plan.md)

### Pipeline References (if applicable)

| Phase | Document | Status |
|-------|----------|--------|
| Phase 1 | Schema Definition | N/A (미사용) |
| Phase 2 | Coding Conventions | N/A (CLAUDE.md 컨벤션으로 대체) |
| Phase 3 | Mockup | N/A |
| Phase 4 | API Spec | 본 문서 §4에서 직접 정의 |

> Plan 문서는 Plan Plus(브레인스토밍) 템플릿으로 작성되어 별도 Context Anchor 섹션이 없다. 대신 Plan §1.4 Constraints, §7 Risks, Appendix Brainstorming Log를 전략적 컨텍스트로 참조한다.

---

## 1. Overview

### 1.1 Design Goals

- 기존 `products`/`inventory`/`wholesale_products` 데이터를 **복사 없이 실시간 조회**로 재사용하는 가격 연동 구조 구현
- 도매가가 미승인 회원/소매 회원에게 노출되지 않도록 **가격 계산 경로를 구조적으로 단일화**
- 관리자가 상품 큐레이션·회원승인·할인규칙·주문을 `mall/admin/` 한 곳에서 처리할 수 있는 통합 관리 화면 제공
- 기존 프로젝트 컨벤션(procedural PHP + `lib/*_helper.php` 함수형 헬퍼)을 그대로 따라 팀 러닝커브 최소화

### 1.2 Design Principles

- **단일 가격 계산 경로**: 모든 화면·API는 `mall/lib/pricing.php`의 함수만 호출해 가격을 구한다. 직접 `inventory.selling_price`나 `wholesale_products.wholesale_price`를 페이지에서 조회해 그대로 노출하지 않는다.
- **원본 데이터 비복제**: 판매가/도매가는 저장하지 않고 조회 시점에 계산한다. 단, 주문 확정 시점의 가격은 `mall_order_items`에 스냅샷으로 저장한다(사후 원가 변경에도 과거 주문 금액은 불변).
- **네임스페이스 격리, 로직 공유**: 소매/도매 화면은 분리하되 인증·장바구니·주문·가격계산 로직은 공유한다(Plan Approach A 원칙 계승).
- **기존 인프라 재사용 우선**: 새 테이블은 기존 `wholesale_products`, `inventory`, `products`, `stores`, admin `users`/`has_permission()` 위에 얹는 방식으로 설계하고 중복 데이터를 만들지 않는다.

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 페이지별 SQL·가격로직 인라인 | Repository/Service/Domain 클래스 계층 | `mall/lib/*.php` 함수형 헬퍼 모듈 |
| **New Files** | ~15 | ~40 | ~22 |
| **Modified Files** | ~1 | ~3 | ~2 |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Medium | High | High |
| **Effort** | Low | High | Medium |
| **Risk** | High (도매가 오노출 위험) | Low (그러나 컨벤션 이질적) | Low |
| **Recommendation** | 빠른 프로토타입 | 대규모 팀 | **Default choice** |

**Selected**: Option C — **Rationale**: CLAUDE.md에 명시된 `permission_helper.php`/`margin_helper.php`/`session_helper.php` 패턴과 동일한 함수형 헬퍼 구조를 따르며, 가격계산을 `pricing.php` 단일 모듈로 강제해 도매가 오노출 리스크를 구조적으로 낮춘다. Option B는 이 프로젝트 규모·컨벤션 대비 과설계.

> 아래 상세 설계는 Option C 기준으로 작성됨.

### 2.1 Component Diagram

```
┌──────────────┐      ┌────────────────────────────┐      ┌──────────────────┐
│   Browser    │─────▶│  mall/*.php  (Presentation) │─────▶│  MySQL (기존 DB)   │
│ (고객/관리자) │      │  mall/admin/*.php            │      │  products/inventory│
└──────────────┘      └──────────────┬───────────────┘      │  wholesale_products│
                                      │ require                stores/users     │
                                      ▼                       mall_* (신규)     │
                       ┌────────────────────────────┐      └──────────────────┘
                       │  mall/lib/*.php  (Helper)   │
                       │  auth.php / pricing.php     │
                       │  cart.php / order.php       │
                       └──────────────┬───────────────┘
                                      │ get_db_connection() / PDO
                                      ▼
                       ┌────────────────────────────┐
                       │  config/db_config.php (기존) │
                       └────────────────────────────┘
```

### 2.2 Data Flow

```
[조회] 사용자 요청 → mall/*.php → mall/lib/pricing.php(회원등급 판별) →
       products/inventory/wholesale_products 실시간 조회 → 할인 적용 → 화면 렌더링

[주문] 장바구니 → checkout.php → mall/lib/order.php →
       (트랜잭션) mall_orders/mall_order_items 생성 + inventory.quantity 차감 → 완료 응답

[배치] cron → mall/batch/recalc_tiers.php →
       mall_orders 누적 합계 → mall_members.retail_tier / mall_member_stats 갱신
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `mall/*.php` (Presentation) | `mall/lib/*.php` | 화면 렌더링, 요청 처리 |
| `mall/lib/pricing.php` | `products`, `inventory`, `wholesale_products`, `mall_retail_discount_rules`, `mall_wholesale_*_tiers` | 회원별 최종 가격 계산 |
| `mall/lib/order.php` | `mall/lib/pricing.php`, `config/db_config.php` | 주문 생성 트랜잭션, 재고 차감 |
| `mall/lib/auth.php` | `mall_members` | 세션 기반 고객 인증 |
| `mall/admin/*.php` | 기존 `admin/partials/header.php`, `lib/permission_helper.php` | 관리자 인증/권한, 공용 UI |
| `mall/batch/recalc_tiers.php` | `mall_orders`, `mall_retail_discount_rules`, `mall_wholesale_cumulative_tiers` | 월 1회 등급 재산정 |

---

## 3. Data Model

### 3.1 Entity Definition (PHP 배열/레코드 형태 — TypeScript 아님)

```
mall_members
  id, member_type('retail'|'wholesale'), email, password_hash, name, phone,
  business_name, business_reg_no, store_id, retail_tier('general'|'discount'|'vip'),
  wholesale_status('pending'|'approved'|'rejected'), wholesale_customer_id,
  is_active, created_at, updated_at

mall_products (소매 큐레이션, product_id UNIQUE)
  id, product_id, store_id, display_name, display_name_en, is_active, display_order, created_at, updated_at

mall_product_images (소매/도매 공용, product_id 기준 — 항상 products.id를 가리킴)
  id, product_id, image_path, sort_order, created_at

mall_wholesale_visibility (기존 wholesale_products 중 몰 노출 대상 지정, wholesale_product_id UNIQUE)
  id, wholesale_product_id, is_visible, created_at, updated_at

mall_retail_discount_rules
  id, tier, discount_rate, min_cumulative_amount, updated_at

mall_wholesale_instant_discount_tiers (즉석할인 구간)
  id, min_order_amount, discount_rate, sort_order, is_active

mall_wholesale_cumulative_tiers (누적실적 등급)
  id, tier_name, min_cumulative_amount, additional_discount_rate, sort_order

mall_member_stats (누적실적/현재 도매등급, 월배치로 갱신, PK=member_id 자체, cumulative_amount 집계기간="가입 이후 전체 누적")
  member_id(PK, FK→mall_members.id), cumulative_amount decimal(12,2), current_wholesale_tier_id, last_recalculated_at

mall_cart_items (UNIQUE: member_id+product_id+channel — 동일 상품 중복 행 방지, 있으면 quantity UPDATE)
  id, member_id, product_id, channel('retail'|'wholesale'), quantity, created_at, updated_at

mall_orders
  id, order_number, member_id, store_id(FK→stores.id), channel, subtotal, discount_amount, total_amount,
  status('pending'|'confirmed'|'preparing'|'ready'|'completed'|'cancelled'),
  payment_method('cod'|'offline'), memo, created_at, updated_at

mall_order_items
  id, order_id(FK→mall_orders.id), product_id, product_name_snapshot, unit_price_snapshot,
  discount_rate_snapshot, quantity, line_total

mall_wishlist (UNIQUE: member_id+product_id+channel)
  id, member_id(FK→mall_members.id), product_id, channel, created_at

mall_reviews (구매 검증: order_id가 있는 완료 주문에서만 작성 가능하도록 order.php에서 검증)
  id, member_id(FK→mall_members.id), product_id, order_id(FK→mall_orders.id), rating(1-5), comment, created_at

mall_password_resets (Plan §3.1 "비밀번호 찾기" 구현용, 토큰은 평문 저장하지 않음)
  id, member_id(FK→mall_members.id), token_hash, expires_at, used_at, created_at
```

> **product_id 참조 규칙(전 테이블 공통)**: `mall_cart_items`/`mall_order_items`/`mall_wishlist`/`mall_reviews`/`mall_product_images`의 `product_id`는 `channel` 값과 무관하게 **항상 `products.id`를 가리킨다**. 도매(wholesale) 채널이어도 별도의 상품 ID 체계를 두지 않는다 — `wholesale_products.product_id`가 이미 `products.id`를 참조하는 1:N(점포별 1건) 관계이므로, 도매가가 필요할 때 `pricing.php`가 `SELECT ... FROM wholesale_products WHERE product_id = ? AND store_id = MALL_STORE_ID`로 조회한다. 따라서 장바구니/주문/위시리스트/리뷰 스키마는 채널 분기와 무관하게 단일 FK(`products.id`)로 통일된다.

### 3.2 Entity Relationships

```
[stores](기존) 1───N [mall_members]
[stores](기존) 1───N [mall_orders]
[stores](기존) 1───N [mall_products]
[mall_members] 1───N [mall_cart_items] ──N───1 [products](기존)
[mall_members] 1───N [mall_wishlist] ──N───1 [products](기존)
[mall_members] 1───N [mall_reviews]
[mall_members] 1───N [mall_password_resets]
[mall_members] 1───N [mall_orders] 1───N [mall_order_items] ──N───1 [products](기존)
[mall_orders] 1───N [mall_reviews] (구매 검증용, order_id로 리뷰 작성 자격 확인)
[mall_members] 1───1 [mall_member_stats]
[mall_members] N───1 [wholesale_customers](기존, wholesale_status='approved' 시 연결)
[products](기존) 1───N [mall_product_images]
[products](기존) 1───1 [mall_products](소매 노출 시, product_id UNIQUE)
[products](기존) 1───N [mall_reviews]
[products](기존) 1───1 [wholesale_products](기존, product_id는 wholesale_products 쪽에서 FK)
[wholesale_products](기존) 1───1 [mall_wholesale_visibility](도매 노출 시, wholesale_product_id UNIQUE)
[mall_wholesale_cumulative_tiers] 1───N [mall_member_stats]
```

> **핵심 연결**: 도매 회원이 관리자 승인을 받으면 `mall_members.wholesale_customer_id`에 기존 `wholesale_customers` 레코드를 매칭(신규 생성 또는 기존 거래처 선택)한다. 이렇게 하면 온라인으로 들어온 도매 주문도 기존 `wholesale_sales` 기반 거래처 관리·통계와 자연스럽게 연결된다.

### 3.3 Database Schema

> 전체 DDL은 `sql/mall_schema.sql`에 작성한다(Do phase 산출물). 아래는 설계 확정을 위한 발췌.

```sql
CREATE TABLE `mall_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_type` enum('retail','wholesale') NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `business_name` varchar(255) DEFAULT NULL COMMENT '사업자 상호 (도매만)',
  `business_reg_no` varchar(50) DEFAULT NULL COMMENT '사업자등록번호 (도매만)',
  `store_id` int(11) NOT NULL,
  `retail_tier` enum('general','discount','vip') NOT NULL DEFAULT 'general',
  `wholesale_status` enum('pending','approved','rejected') DEFAULT NULL,
  `wholesale_customer_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `store_id` (`store_id`),
  KEY `wholesale_customer_id` (`wholesale_customer_id`),
  CONSTRAINT `mall_members_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
  CONSTRAINT `mall_members_ibfk_2` FOREIGN KEY (`wholesale_customer_id`) REFERENCES `wholesale_customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 고객 회원(관리자 users와 별도)';

CREATE TABLE `mall_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `member_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL,
  `channel` enum('retail','wholesale') NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','confirmed','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_method` enum('cod','offline') NOT NULL DEFAULT 'cod',
  `memo` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `mall_orders_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`),
  CONSTRAINT `mall_orders_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 주문';

-- mall_products / mall_product_images / mall_wholesale_visibility /
-- mall_retail_discount_rules / mall_wholesale_instant_discount_tiers /
-- mall_wholesale_cumulative_tiers / mall_member_stats / mall_cart_items /
-- mall_order_items / mall_wishlist / mall_reviews 는 §3.1 필드 정의대로
-- 동일한 규칙(utf8mb4, mall_ 접두사, FK 명시)으로 Do phase에서 전체 작성한다.
```

---

## 4. API Specification

> 이 프로젝트는 REST API가 아닌 **PHP AJAX 엔드포인트 패턴**(`ajax_*.php` → JSON 응답)을 사용한다(기존 `admin/ajax_*.php` 관행과 동일). Method는 실질적으로 모두 POST(폼/JS fetch), 응답은 `{success, data, error}` 형태의 JSON.

### 4.1 Endpoint List

| Endpoint | Description | Auth |
|--------|------|------|
| `mall/ajax/add_to_cart.php` | 장바구니 담기 | mall_members 세션 |
| `mall/ajax/update_cart_item.php` | 수량 변경 | mall_members 세션 |
| `mall/ajax/remove_cart_item.php` | 항목 삭제 | mall_members 세션 |
| `mall/ajax/submit_order.php` | 주문 접수(체크아웃) | mall_members 세션 |
| `mall/ajax/toggle_wishlist.php` | 위시리스트 추가/제거 | mall_members 세션 |
| `mall/ajax/submit_review.php` | 리뷰 등록 | mall_members 세션(구매자만) |
| `mall/admin/ajax/approve_wholesale_member.php` | 도매 회원 승인/반려 | admin 세션 + `mall_management` 권한 |
| `mall/admin/ajax/update_member_tier.php` | 회원 등급 수동 변경 | admin + 권한 |
| `mall/admin/ajax/save_retail_product.php` | 소매 상품 큐레이션 저장(+사진 업로드) | admin + 권한 |
| `mall/admin/ajax/toggle_wholesale_visibility.php` | 도매 상품 몰 노출 ON/OFF | admin + 권한 |
| `mall/admin/ajax/save_discount_rules.php` | 할인규칙(등급률/즉석구간/누적등급) 저장 | admin + 권한 |
| `mall/admin/ajax/update_order_status.php` | 주문 상태 변경 | admin + 권한 |

### 4.2 Detailed Specification

#### `POST mall/ajax/submit_order.php`

**Request (form-encoded):**
```
member_id (세션에서 자동)
channel = retail | wholesale
memo = string (optional)
```
장바구니 내용(`mall_cart_items`)을 서버에서 다시 조회하여 사용 — 클라이언트가 가격을 전달하지 않음(변조 방지).

**Response (200):**
```json
{
  "success": true,
  "data": {
    "order_id": 123,
    "order_number": "MALL-20260812-0001",
    "total_amount": 45600.00
  }
}
```

**Error Responses** (§6.2 형식 `{success:false, error:{code, message, details}}` 통일 사용):
- `error.code:"EMPTY_CART"` — 장바구니 비어있음
- `error.code:"OUT_OF_STOCK", error.details:{product_id}` — 주문 확정 시점 재고 부족
- `error.code:"WHOLESALE_NOT_APPROVED"` — 미승인 도매 회원이 도매 채널로 주문 시도
- `error.code:"UNAUTHORIZED"` — 미로그인

#### `POST mall/admin/ajax/approve_wholesale_member.php`

**Request:** `member_id`, `action = approve|reject`, `wholesale_customer_id (선택, 기존 거래처와 매칭 시)`

**Response:** `{success:true, data:{member_id, wholesale_status:"approved"}}`

---

## 5. UI/UX Design

### 5.1 Screen Layout (고객용 기본 레이아웃)

```
┌────────────────────────────────────────┐
│  Header: 로고 / 회원유형표시(도매·소매) /   │
│          장바구니 아이콘 / 마이페이지       │
├────────────────────────────────────────┤
│  카테고리 사이드바 │  상품 그리드/상세      │
│                   │  (등급별 가격 자동 표시) │
├────────────────────────────────────────┤
│  Footer: 언어전환(한/영)                  │
└────────────────────────────────────────┘
```

### 5.2 User Flow

```
[소매] 홈 → 회원가입(즉시활성화) → 로그인 → 카탈로그(등급 할인가) → 장바구니 → 주문접수 → 마이페이지 주문확인
[도매] 홈 → 사업자 회원가입 → "승인대기" 안내 → (관리자 승인) → 로그인 → 도매 카탈로그(도매가+즉석할인) → 장바구니 → 주문접수
[관리자] mall/admin 로그인 → 대시보드 → 상품 큐레이션/회원승인/할인규칙/주문관리
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| 상품 카드 | `mall/partials/product_card.php` | 등급별 가격·품절뱃지·위시리스트 버튼 표시 |
| 장바구니 위젯 | `mall/partials/cart_widget.php` | 헤더 내 장바구니 개수/합계 표시 |
| 등급 뱃지 | `mall/partials/tier_badge.php` | 일반/할인/우수 또는 도매 승인상태 표시 |
| 관리자 사이드바 | `mall/admin/partials/sidebar.php` | mall/admin 내 메뉴(상품/회원/할인/주문) |

### 5.4 Page UI Checklist

#### 카탈로그 (`mall/index.php`)
- [ ] 필터: 카테고리 드롭다운 (기존 `categories` 테이블 기반)
- [ ] 검색: 상품명 키워드 검색창
- [ ] 카드: 상품 이미지(`mall_product_images` 첫 번째), 상품명, 가격(등급별 계산값), 품절 뱃지(조건부)
- [ ] 카드: 위시리스트 토글 버튼(하트 아이콘)
- [ ] 도매 회원 미승인 시: "승인 대기중 — 참고용 소매가로 표시됩니다" 안내 배너 (도매가는 절대 노출하지 않고 `inventory.selling_price` 기준 소매가로 대체)

#### 상품 상세 (`mall/product.php`)
- [ ] 이미지 갤러리: `mall_product_images` sort_order 순 다중 이미지
- [ ] 가격 표시: 기본가 + 적용된 할인율 + 최종가 (도매는 즉석할인 구간표 함께 표시)
- [ ] 수량 선택 input + 장바구니 담기 버튼
- [ ] 재고 상태: 품절 시 버튼 비활성화
- [ ] 리뷰 목록: 평점 평균 + 개별 리뷰(작성자명 마스킹, 별점, 코멘트)
- [ ] 리뷰 작성 폼: 구매 이력 있는 회원에게만 노출

#### 장바구니 (`mall/cart.php`)
- [ ] 목록: 상품별 수량 input(변경 시 즉시 합계 갱신), 삭제 버튼
- [ ] 요약: 소계, 적용 할인(등급/즉석), 최종 합계
- [ ] 도매 전용: "이 금액대에서 X% 즉석할인 적용중" 안내 + 다음 구간까지 남은 금액 표시
- [ ] 버튼: 주문하기(checkout 이동)

#### 체크아웃 (`mall/checkout.php`)
- [ ] 주문 요약 재확인(가격 최종본)
- [ ] 요청사항 메모 입력창
- [ ] 결제방식 안내: "결제는 오프라인/배송 시 진행됩니다" (COD 고정, 선택 UI 없음)
- [ ] 주문 확정 버튼 → 성공 시 주문번호 표시

#### 로그인/비밀번호 찾기 (`mall/login.php`, `mall/forgot_password.php`, `mall/reset_password.php`)
- [ ] 비밀번호 찾기: 이메일 입력 → `mall_password_resets` 토큰 발급(해시 저장) → 재설정 링크 발송 안내
- [ ] 비밀번호 재설정: 토큰 검증(만료/재사용 여부) → 새 비밀번호 입력 → `password_hash()` 갱신 → 토큰 `used_at` 기록

#### 마이페이지 (`mall/mypage/orders.php`, `mall/mypage/wishlist.php`, `mall/mypage/profile.php`, `mall/order_detail.php`)
- [ ] 주문내역: 주문번호/일자/상태뱃지/합계, 클릭 시 `order_detail.php`로 상세 이동
- [ ] 주문 상세: 주문 항목별 스냅샷 가격/수량, 상태 이력
- [ ] 위시리스트: 담은 상품 목록 + 장바구니 이동 버튼
- [ ] 회원정보수정: 이름/연락처 수정 폼, 비밀번호 변경 폼

#### 관리자 — 상품 큐레이션 (`mall/admin/products.php`)
- [ ] 검색: 기존 `products` 테이블에서 상품 검색(바코드/이름)
- [ ] 버튼: "쇼핑몰에 추가" (mall_products insert)
- [ ] 이미지 업로드: 다중 파일 업로드 + 정렬(드래그 또는 순서 input)
- [ ] 노출 토글: is_active ON/OFF, display_order 조정

#### 관리자 — 도매 상품 노출 (`mall/admin/wholesale_products.php`)
- [ ] 목록: 기존 `wholesale_products` 전체 표시(현재 몰 노출 여부 컬럼 포함)
- [ ] 토글: 몰 노출 ON/OFF (`mall_wholesale_visibility`)

#### 관리자 — 회원 관리 (`mall/admin/members.php`)
- [ ] 목록: 회원유형/이메일/가입일/등급/승인상태 필터·검색
- [ ] 도매 승인대기 탭: 승인/반려 버튼, 기존 `wholesale_customers`와 매칭 선택 UI
- [ ] 등급 수동 변경: 드롭다운(일반/할인/우수)

#### 관리자 — 할인규칙 (`mall/admin/discount_rules.php`)
- [ ] 소매 등급별 할인율 입력(일반/할인/우수, %) + 등급 산정 누적금액 기준 입력
- [ ] 도매 즉석할인 구간 표(금액 구간 추가/삭제/정렬, 각 구간 할인율)
- [ ] 도매 누적등급 표(등급명, 누적기준금액, 추가 할인율)
- [ ] 저장 버튼(변경 즉시 pricing.php 계산에 반영)

#### 관리자 — 주문 관리 (`mall/admin/orders.php`)
- [ ] 목록: 주문번호/회원/채널(도매·소매)/합계/상태 필터
- [ ] 상태 변경 드롭다운(pending→confirmed→preparing→ready→completed, cancelled)
- [ ] 상세: 주문 항목별 스냅샷 가격/수량 표시

---

## 6. Error Handling

### 6.1 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| UNAUTHORIZED | 로그인이 필요합니다 | 세션 없음/만료 | 로그인 페이지로 이동 |
| WHOLESALE_NOT_APPROVED | 도매 승인 대기중입니다 | wholesale_status != 'approved' | 도매가 비노출, 안내 배너 |
| OUT_OF_STOCK | 재고가 부족합니다 | 주문 확정 시점 재고 < 요청수량 | 장바구니에서 수량 조정 안내 |
| EMPTY_CART | 장바구니가 비어있습니다 | 주문 시도 시 항목 0개 | 카탈로그로 이동 안내 |
| VALIDATION_ERROR | 입력값을 확인해주세요 | 필수값 누락/형식 오류 | 필드별 오류 메시지 표시 |
| DUPLICATE_EMAIL | 이미 가입된 이메일입니다 | 회원가입 시 email UNIQUE 충돌 | 로그인 페이지 안내 |

### 6.2 Error Response Format

```json
{
  "success": false,
  "error": {
    "code": "OUT_OF_STOCK",
    "message": "재고가 부족합니다",
    "details": { "product_id": 18803, "available": 2 }
  }
}
```

---

## 7. Security Considerations

- [ ] 모든 쿼리는 prepared statement 사용 (mysqli/PDO), 문자열 결합 SQL 금지
- [ ] `mall_members.password_hash`는 `password_hash()`/`password_verify()` 사용
- [ ] **도매가 접근 제어**: `pricing.php`의 도매가 계산 함수는 호출 시점에 `wholesale_status === 'approved'`를 서버에서 재검증(세션 캐시 신뢰 금지). 미승인 도매 회원 및 소매 회원이 조회하면 `wholesale_products.wholesale_price`를 절대 반환하지 않고 `inventory.selling_price` 기준 소매가로 대체 반환한다(§5.4 정책과 일치)
- [ ] **소유권 검증(IDOR 방지)**: `mall_cart_items`/`mall_wishlist`/`mall_orders`의 조회·수정·삭제 시 `member_id`가 현재 세션 회원과 일치하는지 매 요청마다 WHERE 절에서 검증(URL/폼 파라미터의 id만으로 접근 금지)
- [ ] 관리자 화면(`mall/admin/`)은 기존 `require_permission('mall_management', ...)` 패턴으로 보호, `permission_helper.php`에 `mall_management` 권한 항목 추가
- [ ] 주문 생성 시 클라이언트가 보낸 가격을 신뢰하지 않고 서버에서 `pricing.php`로 재계산
- [ ] 이미지 업로드: 확장자/MIME 화이트리스트, 파일명 난수화, 업로드 경로는 웹루트 내 별도 디렉터리(`mall/uploads/products/`)로 격리하고 실행권한 제거
- [ ] CSRF 토큰: 관리자 폼 및 주문/결제 관련 POST에 세션 기반 토큰 검증 추가
- [ ] 세션: `mall_members` 세션과 관리자 `users` 세션을 쿠키 이름부터 분리(`MALLSESSID` 등)하여 교차 오염 방지

---

## 8. Test Plan

> 이 프로젝트는 Playwright/E2E 프레임워크가 구성되어 있지 않다. L1은 curl 기반 AJAX 엔드포인트 테스트로, L2/L3는 **수동 브라우저 시나리오 체크리스트**로 대체한다(Do phase에서 실제 절차 문서화, Check phase에서 수행).

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: AJAX 엔드포인트 테스트 | `mall/ajax/*.php`, `mall/admin/ajax/*.php` | curl | Do |
| L2: UI 동작 테스트 | §5.4 체크리스트 요소별 동작 | 수동 브라우저 체크리스트 | Do/Check |
| L3: 시나리오 테스트 | 회원가입→주문→관리자승인 전체 흐름 | 수동 브라우저 시나리오 | Check |

### 8.2 L1: API Test Scenarios

| # | Endpoint | Test Description | Expected |
|---|----------|-------------------|----------|
| 1 | `add_to_cart.php` | 정상 상품 담기 | success:true, cart 갱신 |
| 2 | `submit_order.php` | 미로그인 상태로 주문 시도 | success:false, UNAUTHORIZED |
| 3 | `submit_order.php` | 미승인 도매회원이 도매채널 주문 | success:false, WHOLESALE_NOT_APPROVED |
| 4 | `submit_order.php` | 재고보다 많은 수량 주문 | success:false, OUT_OF_STOCK |
| 5 | `submit_order.php` | 정상 주문(소매/도매 각각) | success:true, order_number 발급, inventory.quantity 차감 확인 |
| 6 | `approve_wholesale_member.php` | 비관리자 세션으로 호출 | 403 또는 UNAUTHORIZED |
| 7 | `save_discount_rules.php` | 할인율 저장 후 pricing 재조회 | 변경된 할인율이 즉시 반영됨 |

### 8.3 L2: UI Action Test Scenarios

| # | Page | Action | Expected Result |
|---|------|--------|----------------|
| 1 | 카탈로그 | 소매 "일반" 계정으로 로그인 후 상품 조회 | 등급별 할인 적용된 가격만 노출, 도매가 없음 |
| 2 | 카탈로그 | 승인된 도매 계정으로 로그인 | 도매가+즉석할인 구간 안내 표시 |
| 3 | 카탈로그 | 승인대기 도매 계정으로 로그인 | 도매가는 노출되지 않고, `inventory.selling_price` 기준 소매가(할인 미적용, "일반" 등급 취급)로 대체 표시 + "승인 대기중 — 참고용 소매가로 표시됩니다" 안내 배너 노출. 승인 완료 후 재로그인 시에만 도매가 전환 |
| 4 | 상품상세 | 품절 상품 조회 | 품절 뱃지, 장바구니 버튼 비활성화 |
| 5 | 관리자 discount_rules | 즉석할인 구간 추가 후 저장 | 도매 카탈로그에 즉시 반영 |

### 8.4 L3: E2E Scenario Test Scenarios

| # | Scenario | Steps | Success Criteria |
|---|----------|-------|-----------------|
| 1 | 소매 전체 흐름 | 회원가입 → 로그인 → 상품 담기 → 주문접수 → 마이페이지 확인 | 주문내역에 정확한 등급 할인가로 표시 |
| 2 | 도매 승인 흐름 | 사업자 가입 → 승인대기 확인 → 관리자 승인 → 재로그인 → 도매가 노출 확인 | 승인 전/후 화면 차이 정확 |
| 3 | 도매 할인 누적 | 즉석할인 구간 넘는 주문 → 익월 배치 실행(수동 트리거) → 누적등급 상승 → 다음 주문 시 추가 할인 확인 | 배치 전/후 할인율 차이 확인 |
| 4 | 관리자 통합 운영 | mall/admin 로그인 → 상품 큐레이션 → 회원 승인 → 할인규칙 수정 → 주문 상태 변경까지 한 세션에서 처리 | 모든 기능이 하나의 관리자 화면 체계 내에서 동작 |

### 8.5 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| stores | 1 | mall_config.php에 지정된 store_id |
| products + inventory | 10+ | selling_price, quantity(일부 0으로 품절 케이스 포함) |
| wholesale_products | 5+ | wholesale_price, is_active=1 (기존 데이터 재사용 가능) |
| mall_members | 3+ | retail(general/discount/vip 각 1) + wholesale(승인/미승인 각 1) |
| mall_retail_discount_rules | 3 | 일반/할인/우수 각 할인율 |
| mall_wholesale_instant_discount_tiers | 2+ | 서로 다른 금액 구간 |
| mall_wholesale_cumulative_tiers | 2+ | 서로 다른 누적기준 |

---

## 9. Clean Architecture (PHP Procedural 변형)

> 이 프로젝트는 React/TS Clean Architecture가 아닌 **procedural PHP + 함수형 헬퍼** 컨벤션을 사용한다(CLAUDE.md 기준). 계층 개념만 차용해 적용한다.

### 9.1 Layer Structure

| Layer | Responsibility | Location |
|-------|---------------|----------|
| **Presentation** | 화면 렌더링, 요청 파라미터 처리 | `mall/*.php`, `mall/admin/*.php` |
| **Application** | 장바구니/주문 유스케이스 오케스트레이션 | `mall/lib/cart.php`, `mall/lib/order.php` |
| **Domain** | 가격·할인 계산 핵심 규칙(가장 민감한 로직) | `mall/lib/pricing.php` |
| **Infrastructure** | DB 연결, 세션, 설정 | `config/db_config.php`(기존), `mall/lib/auth.php`, `mall/config/mall_config.php` |

### 9.2 Dependency Rules

```
Presentation(mall/*.php) ──require──▶ Application(cart.php/order.php) ──▶ Domain(pricing.php)
                          ──require──▶ Domain(pricing.php) 직접 호출도 허용(조회 전용 화면)
Domain(pricing.php)       ──▶ Infrastructure(get_db_connection()) 만 의존, 다른 lib에 의존하지 않음
규칙: 어떤 페이지도 pricing.php를 거치지 않고 selling_price/wholesale_price를 직접 화면에 출력하지 않는다.
```

### 9.3 File Import Rules

| From | Can Require | Cannot |
|------|-----------|---------------|
| Presentation (`mall/*.php`) | `lib/auth.php`, `lib/pricing.php`, `lib/cart.php`, `lib/order.php` | DB 커넥션 직접 open(반드시 헬퍼 경유) |
| Application (`lib/cart.php`,`lib/order.php`) | `lib/pricing.php`, `config/db_config.php` | Presentation 파일 require 금지 |
| Domain (`lib/pricing.php`) | `config/db_config.php`만 | 다른 mall/lib 파일 의존 금지(순수 계산 함수 유지) |

### 9.4 This Feature's Layer Assignment

| Component | Layer | Location |
|-----------|-------|----------|
| 카탈로그/상세/장바구니/체크아웃 화면 | Presentation | `mall/index.php`, `mall/product.php`, `mall/cart.php`, `mall/checkout.php` |
| 관리자 화면 일체 | Presentation | `mall/admin/*.php` |
| 장바구니 유스케이스 | Application | `mall/lib/cart.php` |
| 주문 생성 유스케이스(트랜잭션) | Application | `mall/lib/order.php` |
| 가격/할인 계산 | Domain | `mall/lib/pricing.php` |
| 회원 세션 관리 | Infrastructure | `mall/lib/auth.php` |
| 대상 점포 설정 | Infrastructure | `mall/config/mall_config.php` |

---

## 10. Coding Convention Reference

> React/TS 컨벤션이 아닌 CLAUDE.md 기준 PHP 컨벤션을 적용한다.

### 10.1 Naming Conventions

| Target | Rule | Example |
|--------|------|---------|
| 함수 | snake_case | `get_mall_price()`, `calculate_wholesale_discount()` |
| 파일 | snake_case.php | `pricing.php`, `discount_rules.php` |
| 폴더 | snake_case | `mall/`, `mall/admin/`, `mall/lib/`, `mall/ajax/` |
| DB 테이블/컬럼 | snake_case, 신규 테이블은 `mall_` 접두사 | `mall_orders`, `unit_price_snapshot` |
| 상수 | UPPER_SNAKE_CASE | `MALL_STORE_ID`, `MALL_SESSION_NAME` |

### 10.2 Include/Require Order (PHP)

```php
<?php
// 1. 설정
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/config/mall_config.php';

// 2. 공용 헬퍼 (기존 lib/)
require_once __DIR__ . '/../lib/lang_helper.php';

// 3. mall 전용 헬퍼
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/pricing.php';

// 4. 페이지 로직
```

### 10.3 Configuration Constants (환경변수 대신 상수 파일 사용 — 기존 관행)

| 파일 | 용도 | 예시 |
|--------|---------|-------|
| `config/db_config.php` (기존) | DB 접속정보 | `DB_HOST`, `DB_NAME` |
| `mall/config/mall_config.php` (신규) | 몰 대상 점포 | `MALL_STORE_ID` |

### 10.4 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| 가격 계산 | 반드시 `pricing.php`의 함수 경유, 페이지 내 직접 계산 금지 |
| 원가/합계 표시 | 소숫점 둘째자리까지 표시 (CLAUDE.md 규칙) |
| 다국어 | 기존 `lib/lang_helper.php` 재사용 |
| 세션 | 관리자 세션과 분리된 별도 쿠키명 사용 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
mall/
├── index.php / product.php / cart.php / checkout.php
├── login.php / signup.php / forgot_password.php / reset_password.php
├── order_detail.php               (마이페이지 주문 상세)
├── mypage/
│   ├── orders.php / wishlist.php / profile.php
├── partials/
│   ├── header.php / footer.php / product_card.php / tier_badge.php / cart_widget.php
├── lib/
│   ├── auth.php / pricing.php / cart.php / order.php
├── ajax/
│   ├── add_to_cart.php / update_cart_item.php / remove_cart_item.php
│   ├── submit_order.php / toggle_wishlist.php / submit_review.php
│   ├── delete_product_image.php / reorder_product_images.php
├── batch/
│   └── recalc_tiers.php
├── config/
│   └── mall_config.php
├── uploads/products/            (업로드 이미지 저장, 웹서버 실행권한 제거)
│
└── admin/                       (별도 login.php 없음 — 기존 admin/partials/header.php의 세션/인증을 require로 재사용)
    ├── dashboard.php
    ├── products.php / wholesale_products.php
    ├── members.php / discount_rules.php / orders.php
    ├── partials/sidebar.php
    └── ajax/
        ├── approve_wholesale_member.php / update_member_tier.php
        ├── save_retail_product.php / toggle_wholesale_visibility.php
        ├── save_discount_rules.php / update_order_status.php

sql/
└── mall_schema.sql
```

### 11.2 Implementation Order

1. [ ] `sql/mall_schema.sql` 작성 및 실제 DB 적용(라이브 스키마 대조 후)
2. [ ] `mall/config/mall_config.php`, `mall/lib/auth.php` (세션 인프라)
3. [ ] 회원가입/로그인 (`signup.php`, `login.php`) + `mall/admin/members.php`(승인)
4. [ ] `mall/lib/pricing.php` (가격/할인 엔진) + `mall/admin/discount_rules.php`
5. [ ] 상품 큐레이션(`mall/admin/products.php`, `wholesale_products.php`) + 카탈로그/상세 화면
6. [ ] 장바구니/주문(`lib/cart.php`, `lib/order.php`, `cart.php`, `checkout.php`) + `mall/admin/orders.php`
7. [ ] 마이페이지(주문내역/위시리스트/회원정보), 리뷰
8. [ ] `mall/batch/recalc_tiers.php` + 다국어 적용 + 전체 통합 테스트(§8)

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|--------------|:---------------:|
| DB 스키마 + 인프라 | `module-1` | mall_schema.sql, mall_config.php, auth.php | 15-20 |
| 회원 시스템 | `module-2` | 가입/로그인/승인, mall/admin/members.php | 25-30 |
| 가격/할인 엔진 | `module-3` | pricing.php, discount_rules.php | 25-30 |
| 상품 카탈로그 | `module-4` | 큐레이션 admin + index/product.php, 이미지업로드 | 30-35 |
| 장바구니/주문 | `module-5` | cart.php, order.php, checkout, admin/orders.php | 30-35 |
| 마이페이지/리뷰/위시리스트 | `module-6` | mypage/*, reviews, wishlist | 20-25 |
| 월배치/다국어/통합테스트 | `module-7` | recalc_tiers.php, lang 적용, §8 테스트 수행 | 20-25 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 (완료) | - |
| Session 2 | Do | `--scope module-1,module-2` | 40-50 |
| Session 3 | Do | `--scope module-3,module-4` | 45-55 |
| Session 4 | Do | `--scope module-5` | 30-35 |
| Session 5 | Do | `--scope module-6,module-7` | 40-45 |
| Session 6 | Check + Report | 전체 | 30-40 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-12 | Initial draft | whdans007 |
| 0.2 | 2026-08-13 | design-validator 검증 반영: (1) product_id는 채널 무관 항상 products.id를 가리키도록 통일(도매가는 pricing.php가 wholesale_products.product_id로 별도 조회), (2) 미승인 도매회원 화면 정책을 "소매가 대체 표시"로 확정(§5.4/§7/§8.3 일치), (3) 엔드포인트 에러 응답 포맷을 §6.2 객체 형식으로 통일, ERD 누락 관계 보강, UNIQUE 제약 명시, mall_products.display_name_en 추가(FR-15), mall_password_resets 테이블 추가(비밀번호 찾기), mall/admin/login.php 제거(기존 admin 세션 재사용으로 명확화), IDOR 방지 조항 추가 | whdans007 |
