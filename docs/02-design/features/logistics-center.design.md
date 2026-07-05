# Design: 물류센터 독립 시스템 (logistics-center)

**Feature**: logistics-center
**Phase**: Design
**Architecture**: Option C — 실용적 균형
**Created**: 2026-05-20

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 기존 admin에 혼재된 물류 관리를 분리. 유통기한 추적 불가, 점포 직접 주문 불가, 권한 미분리 문제 해결 |
| **WHO** | 물류센터 직원(전체 관리), 점포 담당자(주문 전용), 관리자(전체 접근) |
| **RISK** | lc_inventory GENERATED COLUMN은 MariaDB 10.2+ 필요. quantity_remain 계산 시 정합성 주의 |
| **SUCCESS** | 물류직원이 /logistics/만으로 업무 완결 + D-30 만료 알림 + 점포 재고기반 주문 + 상태흐름 완성 |
| **SCOPE** | /logistics/ 신규 디렉토리 23개 파일 + lc_ 전용 테이블 5개 + DB 마이그레이션 SQL |

---

## 1. 아키텍처 결정 (Option C — 실용적 균형)

### 선택 이유
- DB 연결 및 권한 헬퍼는 기존 검증된 코드를 래핑해서 재활용
- UI/네비게이션/세션은 완전 독립 — 물류 전용 UI/UX
- 코드 중복 최소화하면서 admin 디렉토리와 명확히 분리

### 의존 관계
```
logistics/config/db.php
    └── require_once ../../config/db_config.php   (DB 상수만 가져옴)

logistics/lib/auth.php
    └── require_once ../../lib/permission_helper.php  (is_logistics_department() 재활용)

logistics/partials/header.php   → 완전 독립 (admin header 미참조)
logistics/ajax/*                → 완전 독립 AJAX
```

---

## 2. 파일 구성

```
/Volumes/web/sunset/logistics/
├── login.php                   물류 전용 로그인
├── logout.php                  로그아웃
├── index.php                   대시보드 (만료임박 D-30, 재고요약, 미처리 주문)
│
├── products.php                상품 마스터 목록 (물류직원/관리자)
├── product_add.php             상품 등록
├── product_edit.php            상품 수정
│
├── inbound.php                 입고 목록
├── inbound_add.php             입고 등록 (로트번호 + 유통기한)
│
├── inventory.php               재고 현황 (lot 단위, FIFO 순)
│
├── outbound.php                출고 이력 목록
│
├── orders.php                  주문 목록 (물류직원: 전체 / 점포: 본인 것)
├── order_new.php               점포 주문 생성 (재고있는 상품만 표시)
├── order_detail.php            주문 상세 + 상태 변경 + 출고처리
│
├── config/
│   └── db.php                  DB 연결 래퍼 (기존 db_config.php 상수 활용)
│
├── lib/
│   ├── auth.php                물류 전용 권한 헬퍼
│   └── inventory_helper.php    FIFO 재고 계산 함수
│
├── ajax/
│   ├── get_available_products.php   주문 가능 상품 목록 (재고>0)
│   ├── update_order_status.php      주문 상태 변경
│   └── get_expiry_alerts.php        만료임박 상품 조회
│
├── partials/
│   ├── header.php              물류센터 전용 헤더 (TailwindCSS, 역할별 네비)
│   └── footer.php              공통 footer
│
└── sql/
    └── lc_migration.sql        DB 테이블 생성 스크립트
```

**총 파일 수**: 23개 (PHP 17 + SQL 1 + 파티셜 2 + 헬퍼 2 + AJAX 3)

---

## 3. DB 설계

### 3.1 신규 테이블 (lc_ prefix)

```sql
-- ============================================================
-- 물류센터 독립 시스템 DB 마이그레이션
-- DB: sunset (기존 공유 DB)
-- ============================================================

-- 1. 물류 전용 상품 마스터
CREATE TABLE IF NOT EXISTS lc_products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(200) NOT NULL              COMMENT '상품명',
    sku             VARCHAR(100)                       COMMENT 'SKU',
    barcode         VARCHAR(100)                       COMMENT '바코드',
    unit            VARCHAR(20) DEFAULT '개'           COMMENT '단위 (개/박스/kg)',
    pieces_per_box  INT DEFAULT 1                      COMMENT '박스당 낱개 수',
    category        VARCHAR(100)                       COMMENT '카테고리',
    supplier_id     INT                                COMMENT 'suppliers.id 참조',
    cost_price      DECIMAL(15,2) DEFAULT 0.00         COMMENT '원가',
    selling_price   DECIMAL(15,2) DEFAULT 0.00         COMMENT '판매가',
    min_stock       INT DEFAULT 0                      COMMENT '최소 재고 임계치',
    is_active       TINYINT(1) DEFAULT 1               COMMENT '활성 여부',
    created_by      INT                                COMMENT 'users.id',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 전용 상품 마스터';

-- 2. 입고 (로트 단위)
CREATE TABLE IF NOT EXISTS lc_inbound (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inbound_date    DATE NOT NULL                      COMMENT '입고일자',
    product_id      INT NOT NULL                       COMMENT 'lc_products.id',
    lot_number      VARCHAR(100)                       COMMENT '로트번호 (선택)',
    expiry_date     DATE                               COMMENT '유통기한',
    quantity        INT NOT NULL DEFAULT 0             COMMENT '입고 수량',
    cost_price      DECIMAL(15,2) DEFAULT 0.00         COMMENT '입고 단가',
    supplier_id     INT                                COMMENT 'suppliers.id',
    notes           TEXT                               COMMENT '비고',
    created_by      INT                                COMMENT 'users.id',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES lc_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 입고 (로트 단위)';

-- 3. 재고 (lot 단위, FIFO 기반)
-- MariaDB 10.2+ GENERATED COLUMN 지원 필요
CREATE TABLE IF NOT EXISTS lc_inventory (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inbound_id      INT NOT NULL                       COMMENT 'lc_inbound.id',
    product_id      INT NOT NULL                       COMMENT 'lc_products.id',
    lot_number      VARCHAR(100)                       COMMENT '로트번호',
    expiry_date     DATE                               COMMENT '유통기한',
    quantity_in     INT NOT NULL DEFAULT 0             COMMENT '입고 수량',
    quantity_out    INT NOT NULL DEFAULT 0             COMMENT '누적 출고 수량',
    quantity_remain INT AS (quantity_in - quantity_out) STORED COMMENT '현재고 (자동계산)',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inbound_id)  REFERENCES lc_inbound(id)  ON DELETE RESTRICT,
    FOREIGN KEY (product_id)  REFERENCES lc_products(id) ON DELETE RESTRICT,
    INDEX idx_product_expiry (product_id, expiry_date),
    INDEX idx_expiry_remain  (expiry_date, quantity_remain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 재고 (lot 단위 FIFO)';

-- 4. 점포 주문 헤더
CREATE TABLE IF NOT EXISTS lc_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_date      DATE NOT NULL                      COMMENT '주문일자',
    store_id        INT NOT NULL                       COMMENT 'stores.id',
    status          ENUM('pending','approved','shipped','delivered','cancelled')
                    DEFAULT 'pending'                  COMMENT '주문 상태',
    total_amount    DECIMAL(15,2) DEFAULT 0.00         COMMENT '주문 총액',
    notes           TEXT                               COMMENT '주문 비고',
    created_by      INT                                COMMENT 'users.id (점포 담당자)',
    approved_by     INT                                COMMENT 'users.id (물류직원)',
    approved_at     DATETIME                           COMMENT '승인 일시',
    shipped_at      DATETIME                           COMMENT '출고 일시',
    delivered_at    DATETIME                           COMMENT '배달 완료 일시',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_status (store_id, status),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 주문';

-- 5. 주문 상세
CREATE TABLE IF NOT EXISTS lc_order_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL                       COMMENT 'lc_orders.id',
    product_id      INT NOT NULL                       COMMENT 'lc_products.id',
    quantity        INT NOT NULL DEFAULT 0             COMMENT '주문 수량',
    unit_price      DECIMAL(15,2) DEFAULT 0.00         COMMENT '단가',
    total_amount    DECIMAL(15,2) AS (quantity * unit_price) STORED COMMENT '소계',
    FOREIGN KEY (order_id)   REFERENCES lc_orders(id)    ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES lc_products(id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='주문 상세 항목';
```

### 3.2 기존 테이블 활용 (변경 없음)
| 테이블 | 활용 방식 |
|--------|-----------|
| `users` | 로그인, role, store_id — 그대로 |
| `stores` | 점포 목록 조회 (lc_orders.store_id 참조) |
| `suppliers` | 공급업체 조회 (lc_products.supplier_id, lc_inbound.supplier_id) |

---

## 4. 핵심 모듈 설계

### 4.1 `logistics/config/db.php`

```php
<?php
require_once __DIR__ . '/../../config/db_config.php'; // DB 상수 재활용

function get_lc_db(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception('DB 연결 실패: ' . $conn->connect_error);
    }
    $conn->set_charset(DB_CHARSET);
    return $conn;
}
```

### 4.2 `logistics/lib/auth.php`

```php
<?php
require_once __DIR__ . '/../../lib/permission_helper.php'; // 기존 함수 재활용

function lc_require_login(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: /logistics/login.php');
        exit;
    }
}

function lc_is_staff(): bool {
    // 물류직원 OR 관리자
    return in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])
        || is_logistics_department();
}

function lc_is_store_user(): bool {
    // 점포 담당자 (물류직원/관리자 아닌 경우)
    return !lc_is_staff() && !empty($_SESSION['store_id']);
}

function lc_require_staff(): void {
    lc_require_login();
    if (!lc_is_staff()) {
        header('Location: /logistics/index.php');
        exit;
    }
}
```

### 4.3 `logistics/lib/inventory_helper.php` — FIFO 출고 핵심 로직

```php
<?php
// FIFO 출고: 동일 product_id 중 expiry_date 빠른 lot부터 차감
// 반환: true(성공) / false(재고 부족)
function lc_fifo_deduct(mysqli $conn, int $product_id, int $qty_needed): bool {
    // 1. 유통기한 빠른 순 lot 목록 조회 (재고 있는 것만)
    $stmt = $conn->prepare(
        "SELECT id, quantity_remain FROM lc_inventory
         WHERE product_id = ? AND quantity_remain > 0
         ORDER BY expiry_date ASC, id ASC
         FOR UPDATE"
    );
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $total_available = array_sum(array_column($lots, 'quantity_remain'));
    if ($total_available < $qty_needed) return false;

    // 2. FIFO 차감
    $remaining = $qty_needed;
    foreach ($lots as $lot) {
        if ($remaining <= 0) break;
        $deduct = min($remaining, $lot['quantity_remain']);
        $upd = $conn->prepare(
            "UPDATE lc_inventory SET quantity_out = quantity_out + ? WHERE id = ?"
        );
        $upd->bind_param('ii', $deduct, $lot['id']);
        $upd->execute();
        $remaining -= $deduct;
    }
    return true;
}

// 상품별 총 재고 합계
function lc_get_stock(mysqli $conn, int $product_id): int {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity_remain), 0) FROM lc_inventory
         WHERE product_id = ? AND quantity_remain > 0"
    );
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0];
}
```

---

## 5. 데이터 흐름 상세

### 5.1 입고 등록 → 재고 생성 (트랜잭션)
```
POST inbound_add.php
  → 입력: product_id, lot_number, expiry_date, quantity, cost_price, supplier_id
  → BEGIN TRANSACTION
  → INSERT lc_inbound (inbound_id 반환)
  → INSERT lc_inventory (inbound_id, product_id, lot_number, expiry_date,
                          quantity_in = quantity, quantity_out = 0)
  → COMMIT
  → redirect: inbound.php?msg=success
```

### 5.2 점포 주문 생성 → 승인 → 출고 (트랜잭션)
```
[점포 담당자]
POST order_new.php
  → 입력: store_id (세션), product_id[], quantity[]
  → 서버측 재고 확인: lc_inventory WHERE quantity_remain > 0
  → INSERT lc_orders (status='pending')
  → INSERT lc_order_items × N건
  → redirect: orders.php

[물류직원]
POST order_detail.php → action=approve
  → UPDATE lc_orders SET status='approved', approved_by, approved_at
  → 바로 출고 처리 또는 shipped 단계로 분리 (선택)

POST order_detail.php → action=ship
  → BEGIN TRANSACTION
  → lc_order_items 반복 → lc_fifo_deduct() 호출
  → UPDATE lc_orders SET status='shipped', shipped_at
  → COMMIT
  → redirect: orders.php
```

### 5.3 만료임박 조회 (대시보드)
```sql
SELECT p.name, i.lot_number, i.expiry_date,
       SUM(i.quantity_remain) AS stock,
       DATEDIFF(i.expiry_date, CURDATE()) AS days_left
FROM lc_inventory i
JOIN lc_products p ON i.product_id = p.id
WHERE i.expiry_date IS NOT NULL
  AND i.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
  AND i.quantity_remain > 0
GROUP BY p.id, i.lot_number, i.expiry_date
ORDER BY i.expiry_date ASC
```

---

## 6. UI/UX 설계

### 6.1 네비게이션 구조 (역할별)

**물류직원 / 관리자 네비:**
```
[대시보드] [상품관리] [입고관리] [재고현황] [출고이력] [주문관리]
```

**점포 담당자 네비:**
```
[대시보드] [재고조회] [주문하기] [내 주문 목록]
```

### 6.2 컬러 시스템
- 기본 테마: TailwindCSS teal (기존 admin 물류 메뉴와 동일)
- 유통기한 경고:
  - D-30 이내: `bg-yellow-50 text-yellow-800`
  - D-7 이내: `bg-orange-50 text-orange-800`
  - 만료: `bg-red-50 text-red-800 line-through`
- 주문 상태 배지:
  - pending: `bg-gray-100 text-gray-700`
  - approved: `bg-blue-100 text-blue-700`
  - shipped: `bg-purple-100 text-purple-700`
  - delivered: `bg-green-100 text-green-700`
  - cancelled: `bg-red-100 text-red-700`

### 6.3 주문 화면 (`order_new.php`) — 핵심 UX
```
┌─────────────────────────────────────────────────────┐
│ 상품명         재고      단가     주문수량            │
├─────────────────────────────────────────────────────┤
│ 사과주스 1L    50개     2,500원  [  10  ] [추가]    │
│ 오렌지주스     23개     2,800원  [   5  ] [추가]    │
│ 포도주스        0개     (품절)   [ 주문 불가 ]        │
│ 키위주스      150개     3,200원  [  20  ] [추가]    │
└─────────────────────────────────────────────────────┘
재고 0 상품 → 행 흐리게(opacity-50) + 입력 disabled
```

---

## 7. 보안 설계

### 7.1 인증
- 세션 체크: 모든 PHP 파일 상단에 `lc_require_login()` 호출
- 역할 체크: 물류직원 전용 페이지에 `lc_require_staff()` 호출
- 점포 격리: `lc_is_store_user()` 시 `WHERE store_id = $_SESSION['store_id']` 강제

### 7.2 CSRF 방어
- 모든 POST 폼에 CSRF 토큰 포함 (`$_SESSION['csrf_token']`)
- AJAX POST 요청에 헤더 `X-CSRF-Token` 포함

### 7.3 SQL 인젝션
- 모든 DB 쿼리 Prepared Statement 사용 (MySQLi bind_param)
- `lc_fifo_deduct()` 내 `FOR UPDATE` — 트랜잭션 내에서만 호출

---

## 8. 에러 처리

| 상황 | 처리 방식 |
|------|-----------|
| 재고 부족 시 주문 | 서버측 재검증 후 에러 메시지 반환 |
| FIFO 차감 실패 | ROLLBACK 후 플래시 메시지 표시 |
| DB 연결 실패 | 에러 로그 + 사용자 친화적 메시지 |
| 권한 없는 접근 | `/logistics/login.php` 리다이렉트 |
| 만료된 세션 | 로그인 페이지 리다이렉트 |

---

## 9. 성능 고려사항

- `lc_inventory` 테이블: `(product_id, expiry_date)` 복합 인덱스 — FIFO 쿼리 최적화
- 대시보드 만료임박: 최대 50건 제한 (`LIMIT 50`)
- 주문 가능 상품 AJAX: `GROUP BY product_id HAVING SUM(quantity_remain) > 0`으로 재고 집계

---

## 10. 테스트 시나리오

| 시나리오 | 검증 항목 |
|----------|-----------|
| 로트번호 2개 입고 후 주문 | FIFO 순서로 소비 확인 |
| 재고 정확히 소진 후 주문 | 재고 0 → 주문 불가 확인 |
| 점포A가 점포B 주문 조회 | store_id 격리 확인 |
| 유통기한 지난 lot 주문 | 만료 lot 제외 여부 확인 |
| 승인 → 출고 → 재고 차감 | 재고 수치 정합성 확인 |

---

## 11. 구현 가이드

### 11.1 구현 순서

**Module 1: 기반 구조** (우선 구현)
1. `sql/lc_migration.sql` — 테이블 5개 생성
2. `config/db.php` — DB 연결 래퍼
3. `lib/auth.php` — 권한 헬퍼
4. `lib/inventory_helper.php` — FIFO 로직
5. `partials/header.php` + `partials/footer.php`
6. `login.php` + `logout.php`

**Module 2: 상품 + 입고 관리** (핵심 데이터)
7. `products.php` — 상품 목록 (검색, 페이지네이션)
8. `product_add.php` — 상품 등록 폼
9. `product_edit.php` — 상품 수정 폼
10. `inbound.php` — 입고 목록
11. `inbound_add.php` — 입고 등록 (트랜잭션: lc_inbound + lc_inventory)

**Module 3: 재고 + 대시보드**
12. `inventory.php` — 재고 현황 (lot 단위, 유통기한 컬러)
13. `index.php` — 대시보드 (만료임박 D-30, 미처리 주문 수, 총 재고 요약)

**Module 4: 점포 주문 시스템**
14. `ajax/get_available_products.php` — 재고있는 상품 JSON
15. `order_new.php` — 주문 생성 (점포 담당자 전용)
16. `orders.php` — 주문 목록 (역할별 필터)
17. `order_detail.php` — 주문 상세 + 상태 변경 + FIFO 출고
18. `outbound.php` — 출고 이력

**Module 5: 부가 AJAX**
19. `ajax/update_order_status.php` — 상태 변경 API
20. `ajax/get_expiry_alerts.php` — 만료임박 조회 API

### 11.2 파일별 예상 라인 수
| 파일 | 예상 라인 |
|------|-----------|
| login.php | ~80 |
| partials/header.php | ~150 |
| products.php | ~120 |
| inbound_add.php | ~150 |
| inventory.php | ~130 |
| order_new.php | ~180 |
| order_detail.php | ~200 |
| lib/inventory_helper.php | ~80 |
| **총 합계** | **~1,600줄** |

### 11.3 Session Guide (모듈별 세션 계획)

| 세션 | 모듈 | 주요 파일 | 예상 시간 |
|------|------|-----------|-----------|
| Session 1 | Module 1: 기반 구조 | sql, config, lib, partials, login | 1-2h |
| Session 2 | Module 2: 상품+입고 | products*, inbound* | 1-2h |
| Session 3 | Module 3: 재고+대시보드 | inventory, index | 1h |
| Session 4 | Module 4: 주문 시스템 | orders*, order_*, outbound | 2-3h |
| Session 5 | Module 5: AJAX 완성 | ajax/* | 1h |

**스코프 별 구현 명령어:**
```
/pdca do logistics-center --scope module-1
/pdca do logistics-center --scope module-2
/pdca do logistics-center --scope module-3
/pdca do logistics-center --scope module-4
/pdca do logistics-center --scope module-5
```
