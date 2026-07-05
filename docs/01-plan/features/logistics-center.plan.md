# Plan: 물류센터 독립 시스템 (logistics-center)

> **Feature**: logistics-center
> **Phase**: Plan
> **Date**: 2026-05-20
> **Author**: whdans007

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 기존 admin 시스템에 혼재된 물류 관리. 유통기한 추적 불가, 점포가 직접 주문 불가, 물류센터 직원과 점포 접근 권한 미분리 |
| **Solution** | `/logistics/` 독립 디렉토리 + `lc_` 전용 테이블. 역할별 로그인 분리, 로트번호 기반 유통기한 관리, FIFO 재고 시스템, 점포 주문 워크플로우 |
| **UX Effect** | 물류직원: 한 화면에서 입고→재고→출고 완결. 점포: 재고있는 상품만 보여주는 간결한 주문 화면. 관리자: 전체 현황 대시보드 |
| **Core Value** | 유통기한 만료 손실 방지 + 점포-물류센터 주문 자동화로 수작업 커뮤니케이션 제거 |

---

## 1. User Intent Discovery

### 1.1 핵심 문제
세 가지 모두 동등하게 중요:
- admin 시스템과 분리된 **독립 운영**
- **유통기한 기반** 식품/소비재 재고 관리
- 각 **점포가 직접 주문**할 수 있는 B2B 주문 시스템

### 1.2 대상 사용자
| 역할 | 접근 범위 |
|------|-----------|
| 물류센터 직원 | 전체 (상품, 입고, 재고, 출고, 주문 승인) |
| 점포 담당자 | 주문 생성/조회만 |
| 관리자 (super_admin/admin) | 전체 + 설정 |

### 1.3 성공 기준
- [ ] 물류직원이 `/logistics/`만으로 업무 완결 (admin 접속 불필요)
- [ ] 유통기한 D-30 이내 상품이 대시보드에 자동 표시
- [ ] 점포가 재고 있는 상품만 주문 가능 (재고 0 → 주문 버튼 비활성)
- [ ] 주문 상태 흐름 완성: pending → approved → shipped → delivered

---

## 2. Alternatives Explored

| 방식 | 장점 | 단점 | 결정 |
|------|------|------|------|
| A: 점진적 마이그레이션 | 빠른 구현, 코드 재활용 | 중복 코드, 기존 구조 종속 | ❌ |
| **B: 완전 신규 구축** | **깔끔한 구조, lc_ 전용 DB** | **초기 개발 공수 높음** | **✅ 채택** |
| C: API 방식 | 데이터 일관성 | PHP 환경에서 과설계 | ❌ |

---

## 3. YAGNI Review

### MVP v1 포함
- 역할별 로그인 (물류직원 / 점포 / 관리자)
- lc_상품 마스터 관리
- 입고 관리 (로트번호 + 유통기한)
- 실 재고 현황 (FIFO)
- 점포 주문 + 물류센터 승인 흐름

### v2 이후 미루기
- SMS/이메일 만료 알림
- 통계 대시보드 (월별 입출고 차트)
- 바코드 스캔 입고

---

## 4. Architecture Design

### 4.1 디렉토리 구조
```
/Volumes/web/sunset/
├── admin/                      (기존 — 변경 없음)
└── logistics/                  (신규 독립 시스템)
    ├── login.php               물류전용 로그인 페이지
    ├── logout.php
    ├── index.php               대시보드 (만료임박, 재고요약)
    ├── products.php            lc_상품 마스터 목록
    ├── product_add.php         상품 등록
    ├── product_edit.php        상품 수정
    ├── inbound.php             입고 관리 목록
    ├── inbound_add.php         입고 등록 (로트번호 + 유통기한)
    ├── inventory.php           재고 현황 (lot별 FIFO)
    ├── outbound.php            출고 관리 목록
    ├── outbound_add.php        출고 처리
    ├── orders.php              주문 목록 (물류직원: 전체, 점포: 본인 것)
    ├── order_new.php           점포 주문 생성 (재고있는 상품만)
    ├── order_detail.php        주문 상세 + 상태 변경
    ├── config/
    │   └── db.php              DB 연결 (기존 u622428657_homekmart 재활용)
    ├── lib/
    │   ├── auth.php            물류 권한 헬퍼
    │   └── inventory_helper.php FIFO 재고 계산
    └── partials/
        ├── header.php          물류센터 전용 헤더
        └── nav.php             역할별 네비게이션
```

### 4.2 AJAX 엔드포인트
```
logistics/ajax/
    get_products.php        재고있는 상품 목록 (주문시 사용)
    update_order_status.php 주문 상태 변경
    get_expiry_alerts.php   만료임박 상품 조회
```

---

## 5. Database Design

### 5.1 신규 테이블 (lc_ prefix)

```sql
-- 물류전용 상품 마스터
CREATE TABLE lc_products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(200) NOT NULL,
    sku             VARCHAR(100),
    barcode         VARCHAR(100),
    unit            VARCHAR(20) DEFAULT '개',  -- 단위 (개, 박스, kg...)
    pieces_per_box  INT DEFAULT 1,
    category        VARCHAR(100),
    supplier_id     INT,                       -- suppliers 테이블 참조
    cost_price      DECIMAL(15,2) DEFAULT 0,
    selling_price   DECIMAL(15,2) DEFAULT 0,
    min_stock       INT DEFAULT 0,             -- 최소 재고 경보
    is_active       TINYINT(1) DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 입고 (로트 단위)
CREATE TABLE lc_inbound (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inbound_date    DATE NOT NULL,
    product_id      INT NOT NULL,
    lot_number      VARCHAR(100),              -- 로트번호 (선택)
    expiry_date     DATE,                      -- 유통기한
    quantity        INT NOT NULL DEFAULT 0,
    cost_price      DECIMAL(15,2) DEFAULT 0,
    supplier_id     INT,
    notes           TEXT,
    created_by      INT,                       -- users.id
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES lc_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 재고 (lot 단위 FIFO)
CREATE TABLE lc_inventory (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inbound_id      INT NOT NULL,              -- lc_inbound 참조
    product_id      INT NOT NULL,
    lot_number      VARCHAR(100),
    expiry_date     DATE,
    quantity_in     INT NOT NULL DEFAULT 0,    -- 입고 수량
    quantity_out    INT NOT NULL DEFAULT 0,    -- 출고 수량
    quantity_remain INT GENERATED ALWAYS AS (quantity_in - quantity_out) STORED,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inbound_id) REFERENCES lc_inbound(id),
    FOREIGN KEY (product_id) REFERENCES lc_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 점포 주문 헤더
CREATE TABLE lc_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_date      DATE NOT NULL,
    store_id        INT NOT NULL,              -- stores.id
    status          ENUM('pending','approved','shipped','delivered','cancelled') DEFAULT 'pending',
    total_amount    DECIMAL(15,2) DEFAULT 0,
    notes           TEXT,
    created_by      INT,                       -- users.id (점포 담당자)
    approved_by     INT,                       -- users.id (물류직원)
    approved_at     DATETIME,
    shipped_at      DATETIME,
    delivered_at    DATETIME,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 주문 상세
CREATE TABLE lc_order_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL,
    product_id      INT NOT NULL,
    quantity        INT NOT NULL DEFAULT 0,
    unit_price      DECIMAL(15,2) DEFAULT 0,
    total_amount    DECIMAL(15,2) DEFAULT 0,
    FOREIGN KEY (order_id)   REFERENCES lc_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES lc_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.2 기존 테이블 활용
- `users` — 로그인, 역할(role), 점포(store_id) 그대로 활용
- `stores` — 점포 목록
- `suppliers` — 공급업체 참조 (lc_products.supplier_id)

---

## 6. Data Flow

### 6.1 입고 플로우
```
물류직원 → inbound_add.php 작성
  → lc_inbound 레코드 생성 (lot_number, expiry_date, quantity)
  → lc_inventory 레코드 생성 (quantity_in = 입고수량, quantity_out = 0)
```

### 6.2 점포 주문 플로우
```
점포 담당자 로그인 (store_id 기준 권한)
  → order_new.php: lc_inventory WHERE quantity_remain > 0 상품만 표시
  → lc_orders (pending) + lc_order_items 생성

물류직원 → order_detail.php → 승인 (approved)
  → 출고 처리: lc_inventory 차감 (FIFO: expiry_date 빠른 lot부터)
  → 상태 → shipped → delivered
```

### 6.3 유통기한 알림 (대시보드)
```
SELECT * FROM lc_inventory
WHERE expiry_date IS NOT NULL
  AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
  AND quantity_remain > 0
ORDER BY expiry_date ASC
```

### 6.4 FIFO 출고 로직
```
출고 시: 동일 product_id 중 expiry_date 가장 이른 lot의 quantity_out 먼저 증가
남은 재고가 부족하면 다음 lot으로 넘어가서 차감
```

---

## 7. Permission Model

### 7.1 물류 로그인 판별 (기존 로직 재활용)
```php
// logistics/lib/auth.php
function is_logistics_staff(): bool {
    // 기존 is_logistics_department() 로직 준용
    return in_array($_SESSION['role'], ['super_admin', 'admin'])
        || is_logistics_department();
}

function is_store_user(): bool {
    return !is_logistics_staff() && isset($_SESSION['store_id']);
}

function require_logistics_login(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /logistics/login.php');
        exit;
    }
}
```

### 7.2 접근 권한 표
| 페이지 | 물류직원 | 점포담당자 | 관리자 |
|--------|----------|------------|--------|
| 대시보드 | ✅ | ✅ (본인 주문 요약) | ✅ |
| 상품 관리 | ✅ | ❌ | ✅ |
| 입고 관리 | ✅ | ❌ | ✅ |
| 재고 현황 | ✅ | ✅ (조회만) | ✅ |
| 출고 관리 | ✅ | ❌ | ✅ |
| 주문 목록 | ✅ (전체) | ✅ (본인 점포) | ✅ |
| 주문 생성 | ❌ | ✅ | ✅ |
| 주문 승인 | ✅ | ❌ | ✅ |

---

## 8. UI Design Principles

- 기존 admin과 동일한 TailwindCSS 스타일 사용
- 물류센터 테마 컬러: 청록색(teal) — 기존 물류 메뉴 색상 유지
- 재고 현황 테이블: 유통기한 D-30 이내 → 노란색 경고, 만료 → 빨간색
- 점포 주문 화면: 재고 0인 상품 → 흐리게 표시 + 주문 불가 표시
- 모바일 반응형 (점포 담당자가 모바일로 접속하는 경우 고려)

---

## 9. Implementation Phases

### Phase 1: 기반 구조 (우선)
1. `/logistics/` 디렉토리 생성
2. `config/db.php`, `lib/auth.php` 작성
3. `login.php` / `logout.php`
4. DB 마이그레이션 SQL (`lc_products`, `lc_inbound`, `lc_inventory`)
5. `partials/header.php`, `partials/nav.php`

### Phase 2: 핵심 물류 기능
6. `products.php` + `product_add.php` + `product_edit.php`
7. `inbound.php` + `inbound_add.php` (로트번호 + 유통기한)
8. `inventory.php` (FIFO 재고 현황)
9. `index.php` (대시보드 — 만료임박 알림)

### Phase 3: 점포 주문 기능
10. `orders.php` (주문 목록)
11. `order_new.php` (재고있는 상품만 주문)
12. `order_detail.php` (상태 변경 + 출고 처리)
13. `outbound.php` (출고 이력)

---

## 10. Brainstorming Log

| 결정 | 이유 |
|------|------|
| Approach B (완전 신규) 선택 | 기존 코드 종속성 없이 깔끔한 구조 우선 |
| lc_ 테이블 prefix | 기존 products/purchases와 명확히 분리 |
| 기존 users 테이블 재활용 | 별도 사용자 관리 불필요, store_id로 점포 식별 |
| quantity_remain GENERATED COLUMN | 항상 정합성 보장, 별도 업데이트 불필요 |
| FIFO 출고 | 유통기한 관리의 표준 방식 |
| SMS/대시보드 통계 v2 미루기 | MVP 복잡도 제어 |

---

## 11. Out of Scope (v1)

- SMS/이메일 만료 알림 자동화
- 월별 입출고 통계 차트
- 바코드 스캔 입고
- 모바일 전용 앱
- 기존 admin/logistics_*.php 파일 제거 (공존 유지)
