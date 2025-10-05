# HOME K MART 데이터베이스 스키마 참조

**데이터베이스명**: `u622428657_homekmart`
**문서 생성일**: 2025-10-05
**용도**: 개발 시 테이블 구조 참조용

---

## 📋 테이블 목록

### 1. 기본 마스터 데이터
- `brands` - 브랜드 정보
- `categories` - 카테고리 정보
- `suppliers` - 공급업체 정보
- `stores` - 점포 정보
- `users` - 사용자 정보

### 2. 상품 관리
- `products` - 상품 마스터
- `inventory` - 점포별 재고/가격

### 3. 구매/입고
- `purchases` - 구매 헤더
- `purchase_items` - 구매 상세 항목

### 4. 가격 관리
- `margin_rules` - 카테고리별 마진율
- `price_change_history` - 가격 변경 이력
- `price_label_projects` - 가격표 프로젝트
- `price_label_project_items` - 가격표 항목

### 5. 점간 이동
- `store_transfers` - 점간 이동 헤더
- `store_transfer_items` - 이동 항목

### 6. 도매 관리
- `wholesale_customers` - 도매 거래처
- `wholesale_products` - 도매 상품 가격
- `wholesale_sales` - 도매 판매
- `wholesale_sale_items` - 도매 판매 항목

### 7. 배달 앱
- `customers` - 고객 정보
- `delivery_addresses` - 배달 주소
- `delivery_orders` - 배달 주문
- `delivery_order_items` - 배달 주문 항목
- `delivery_zones` - 배달 구역
- `delivery_tracking` - 배달 추적
- `shopping_cart` - 장바구니
- `wishlists` - 위시리스트

### 8. 쇼핑몰 UI
- `display_sections` - 디스플레이 섹션
- `product_displays` - 상품 디스플레이
- `shop_menus` - 쇼핑 메뉴
- `shop_menu_products` - 메뉴별 상품
- `layout_presets` - 레이아웃 프리셋
- `layout_rows` - 레이아웃 행
- `layout_columns` - 레이아웃 열

### 9. 주문 관리
- `orders` - 주문 헤더
- `order_items` - 주문 항목
- `shipping_addresses` - 배송 주소

### 10. 시스템
- `settings` - 시스템 설정
- `delivery_settings` - 배달 설정
- `customer_sessions` - 고객 세션
- `inventory_transactions` - 재고 트랜잭션

---

## 📊 주요 테이블 상세 구조

### `brands` (브랜드)
```sql
id              INT(11) UNSIGNED    PRIMARY KEY AUTO_INCREMENT
name_ko         VARCHAR(100)        NOT NULL, UNIQUE
name_en         VARCHAR(100)        NULL
logo_url        VARCHAR(255)        NULL
created_at      TIMESTAMP           DEFAULT CURRENT_TIMESTAMP
updated_at      TIMESTAMP           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### `categories` (카테고리)
```sql
id              INT(11) UNSIGNED    PRIMARY KEY AUTO_INCREMENT
name            VARCHAR(100)        NOT NULL, UNIQUE
name_en         VARCHAR(100)        NULL
description     TEXT                NULL
parent_id       INT(11)             NULL
created_at      TIMESTAMP           DEFAULT CURRENT_TIMESTAMP
updated_at      TIMESTAMP           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### `products` (상품)
```sql
id                          INT(11) UNSIGNED    PRIMARY KEY AUTO_INCREMENT
sku                         VARCHAR(100)        NOT NULL, UNIQUE
name_ko                     VARCHAR(255)        NULL
name_en                     VARCHAR(255)        NULL
description                 TEXT                NULL
category_id                 INT(11)             NULL, FK -> categories(id)
brand_id                    INT(11) UNSIGNED    NULL, FK -> brands(id)
image_url                   VARCHAR(500)        NULL
pieces_per_box              INT(11)             DEFAULT 1
is_active                   TINYINT(1)          DEFAULT 1
is_vat_applicable           TINYINT(1)          DEFAULT 1
last_modified_by_user_id    INT(11)             NULL
created_at                  TIMESTAMP           DEFAULT CURRENT_TIMESTAMP
updated_at                  TIMESTAMP           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### `inventory` (재고/가격 - 점포별)
```sql
id                  INT(11)         PRIMARY KEY AUTO_INCREMENT
product_id          INT(11)         NOT NULL, FK -> products(id)
store_id            INT(11)         NOT NULL, FK -> stores(id)
quantity            INT(11)         DEFAULT 0
cost_price          DECIMAL(10,2)   NULL
selling_price       DECIMAL(10,2)   NULL
box_price           DECIMAL(10,2)   NULL
margin_rate         DECIMAL(5,2)    DEFAULT 30.00
last_purchase_date  DATE            NULL
updated_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

UNIQUE KEY: (product_id, store_id)
INDEX: quantity
```

### `stores` (점포)
```sql
id          INT(11)         PRIMARY KEY AUTO_INCREMENT
name        VARCHAR(100)    NOT NULL
address     TEXT            NULL
phone       VARCHAR(20)     NULL
is_active   TINYINT(1)      DEFAULT 1
created_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
updated_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### `users` (사용자)
```sql
id                      INT(11)         PRIMARY KEY AUTO_INCREMENT
username                VARCHAR(50)     NOT NULL, UNIQUE
email                   VARCHAR(100)    NOT NULL, UNIQUE
password                VARCHAR(255)    NOT NULL
role                    ENUM('super_admin','admin','staff','office_staff','user')
store_id                INT(11)         NULL, FK -> stores(id)
permissions             JSON            NULL
is_active               TINYINT(1)      DEFAULT 1
phone                   VARCHAR(20)     NULL
google_id               VARCHAR(255)    NULL
profile_image_url       TEXT            NULL
preferred_language      VARCHAR(10)     DEFAULT 'ko'
phone_verified          TINYINT(1)      DEFAULT 0
created_at              TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
updated_at              TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### `purchases` (매입)
```sql
id              INT(11)         PRIMARY KEY AUTO_INCREMENT
store_id        INT(11)         NOT NULL, FK -> stores(id)
supplier_id     INT(11)         NULL, FK -> suppliers(id)
user_id         INT(11)         NOT NULL, FK -> users(id)
purchase_date   DATE            NOT NULL
total_amount    DECIMAL(12,2)   DEFAULT 0.00
vat_amount      DECIMAL(12,2)   DEFAULT 0.00
vat_inclusive   TINYINT(1)      DEFAULT 1
status          ENUM('pending','confirmed','received','cancelled')
remarks         TEXT            NULL
created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
updated_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### `purchase_items` (매입 항목)
```sql
id              INT(11)         PRIMARY KEY AUTO_INCREMENT
purchase_id     INT(11)         NOT NULL, FK -> purchases(id)
product_id      INT(11)         NOT NULL, FK -> products(id)
quantity        INT(11)         NOT NULL
box_quantity    INT(11)         DEFAULT 0
unit_cost       DECIMAL(10,2)   NOT NULL
box_price       DECIMAL(10,2)   NULL
total_cost      DECIMAL(12,2)   NOT NULL
expiry_date     DATE            NULL
```

### `margin_rules` (마진 규칙)
```sql
id              INT(11)         PRIMARY KEY AUTO_INCREMENT
category_id     INT(11)         NOT NULL, UNIQUE, FK -> categories(id)
margin_rate     DECIMAL(5,2)    DEFAULT 30.00
created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
updated_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### `delivery_addresses` (배달 주소)
```sql
id                  INT(11)         PRIMARY KEY AUTO_INCREMENT
user_id             INT(11)         NOT NULL, FK -> users(id)
address_name        VARCHAR(100)    NOT NULL
detailed_address    TEXT            NOT NULL
landmark            VARCHAR(200)    NULL
delivery_notes      TEXT            NULL
latitude            DECIMAL(10,8)   NULL
longitude           DECIMAL(11,8)   NULL
is_default          TINYINT(1)      DEFAULT 0
is_active           TINYINT(1)      DEFAULT 1
created_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
updated_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

INDEX: user_id, (latitude, longitude)
```

### `delivery_orders` (배달 주문)
```sql
id                      INT(11)             PRIMARY KEY AUTO_INCREMENT
order_number            VARCHAR(50)         NOT NULL, UNIQUE
user_id                 INT(11)             NOT NULL, FK -> users(id)
store_id                INT(11)             NOT NULL, FK -> stores(id)
delivery_address_id     INT(11)             NOT NULL, FK -> delivery_addresses(id)
subtotal_amount         DECIMAL(10,2)       NOT NULL
delivery_fee            DECIMAL(10,2)       DEFAULT 0.00
discount_amount         DECIMAL(10,2)       DEFAULT 0.00
total_amount            DECIMAL(10,2)       NOT NULL
payment_method          ENUM('cash','card','online')
payment_status          ENUM('pending','paid','refunded')
delivery_status         ENUM('pending','confirmed','preparing','delivering','completed','cancelled')
delivery_notes          TEXT                NULL
ordered_at              TIMESTAMP           DEFAULT CURRENT_TIMESTAMP
delivered_at            TIMESTAMP           NULL
cancelled_at            TIMESTAMP           NULL

INDEX: user_id, store_id, order_number, delivery_status, payment_status
```

### `shopping_cart` (장바구니)
```sql
id          INT(11)     PRIMARY KEY AUTO_INCREMENT
user_id     INT(11)     NOT NULL, FK -> users(id)
product_id  INT(11)     NOT NULL, FK -> products(id)
store_id    INT(11)     NOT NULL, FK -> stores(id)
quantity    INT(11)     DEFAULT 1
added_at    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
updated_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

UNIQUE KEY: (user_id, product_id, store_id)
```

---

## 🔑 중요 참고사항

### 1. 가격 정보 구조
- 상품 원가/판매가는 `inventory` 테이블에 **점포별로** 저장
- `products` 테이블에는 가격 정보 없음 (마스터 데이터만)
- 점포별 마진율: `margin_rules` 테이블의 카테고리별 설정 참조

### 2. 재고 관리
- 재고 수량: `inventory.quantity` (점포별)
- 매입 시 자동으로 재고 증가
- 점간 이동 시 출발점 감소, 도착점 증가

### 3. VAT 처리
- `products.is_vat_applicable`: 상품별 VAT 적용 여부
- `purchases.vat_inclusive`: 매입 시 VAT 포함/미포함 여부
- VAT 비적용 상품(쌀 등)은 원가 계산 시 VAT 제외

### 4. 사용자 권한
- `users.role`: super_admin, admin, staff, office_staff, user
- `users.permissions`: JSON 형태의 세부 권한 설정
- 점포별 접근 제한: `users.store_id` 활용

### 5. 배달 시스템
- 주문번호 자동 생성: `delivery_orders.order_number`
- 배달 주소 관리: `delivery_addresses` (사용자별 여러 주소 가능)
- 기본 배달 주소: `is_default = 1`

### 6. 바코드 관리
- 상품 SKU가 바코드 역할: `products.sku`
- 바코드는 UNIQUE 제약조건
- 13자리 EAN-13 형식 사용 (체크 디지트 포함)

---

## 📝 작업 시 주의사항

1. **INSERT/UPDATE 시 점포 ID 필수 확인**
   - `inventory`, `purchases`, `delivery_orders` 등은 모두 `store_id` 필수

2. **가격 데이터 타입**
   - 원가/판매가: `DECIMAL(10,2)` - 소수점 둘째자리까지
   - 합계 금액: `DECIMAL(12,2)` - 더 큰 범위

3. **트랜잭션 처리 필요**
   - 매입 등록: `purchases` + `purchase_items` + `inventory` 업데이트
   - 점간 이동: `store_transfers` + `store_transfer_items` + `inventory` 업데이트 (양쪽)

4. **세션 변수 활용**
   - `$_SESSION['user_id']`: 작업자 추적
   - `$_SESSION['store_id']`: 또는 헤더의 `$current_store_id` 사용
   - `$_SESSION['role']`: 권한 체크

5. **컬럼 존재 여부 확인**
   - 일부 테이블에 `last_modified_by_user_id` 없음 → 에러 발생 주의
   - 작업 전 해당 테이블 구조 확인 필수

---

**마지막 업데이트**: 2025-10-05
**참조 백업 파일**: `backups/homekmart_backup_2025-09-18_10-55-17.sql`
