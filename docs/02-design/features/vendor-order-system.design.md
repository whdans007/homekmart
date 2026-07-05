# Design: 업체별 주문 요청 시스템 (vendor-order-system)

**Feature**: vendor-order-system
**Phase**: Design
**Architecture**: logistics 패턴 — 루트 레벨 `order/` 독립 운영, 인증만 공유
**Created**: 2026-05-29

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 업체마다 다른 엑셀 서식의 재고리스트를 수작업으로 발주하는 비효율 → 검색·장바구니·원본서식 주문서 자동생성으로 해결 |
| **WHO** | 관리자(업체 설정, 재고 업로드), 구매 담당 스태프(검색, 발주) |
| **RISK** | PhpSpreadsheet 숨겨진 행 감지 API가 엑셀 버전별로 다를 수 있음. 원본 파일 보존 필수 (주문서 서식 재현 기반) |
| **SUCCESS** | 숨김 행 제외 파싱, 업체별 장바구니 그룹화, 원본 서식 100% 유지 주문서 다운로드, 발주 이력 저장 |
| **SCOPE** | 루트 레벨 `order/` 독립 디렉토리, 12개 PHP 파일 + `ord_` 전용 함수 + SQL 6개 테이블 |

---

## 1. 아키텍처 결정 (logistics 패턴 적용)

### 선택 이유
- `logistics/`, `office/`, `store/` 와 동일한 루트 레벨 독립 모듈
- DB 연결: `order/config/db.php` → `../../config/db_config.php` 상수만 가져옴
- 인증: `order/lib/auth.php` → `../../lib/permission_helper.php` 재활용, `ord_*` 래퍼 함수
- UI: `order/partials/header.php` 완전 독립 (admin header 미참조)

### 의존 관계
```
order/config/db.php
    └── require_once ../../config/db_config.php   (DB 상수만 가져옴)

order/lib/auth.php
    └── require_once ../../lib/permission_helper.php  (권한 헬퍼 재활용)
    └── require_once ../config/db.php                 (ORD_BASE 상수)

order/lib/order_helper.php
    └── require_once ../config/db.php
    └── use PhpSpreadsheet (composer autoload)

order/partials/header.php
    └── require_once ../lib/auth.php
    → 완전 독립 네비게이션 (admin header 미참조)

order/ajax/*.php
    → 완전 독립 AJAX 엔드포인트
```

---

## 2. 파일 구성

```
/sunset/order/                         ← 루트 레벨 독립 디렉토리
├── login.php                          ← 발주 모듈 로그인 (admin 세션 공유)
├── logout.php                         ← 로그아웃
├── index.php                          ← 메인: 상품 검색 + 장바구니
├── vendors.php                        ← 업체 목록 (관리자 전용)
├── vendor_form.php                    ← 업체 등록/수정
├── vendor_column_setup.php            ← 컬럼 매핑 설정
├── upload_inventory.php               ← 재고 엑셀 업로드 + 파싱 미리보기
├── order_history.php                  ← 주문 이력 조회
│
├── config/
│   └── db.php                         ← DB 연결 래퍼 + ORD_BASE 상수
│
├── lib/
│   ├── auth.php                       ← 발주 전용 권한 헬퍼 (ord_*)
│   └── order_helper.php               ← 파싱 엔진 + 주문서 생성 로직
│
├── ajax/
│   ├── search.php                     ← 상품 검색
│   ├── cart_action.php                ← 장바구니 추가/수정/삭제
│   └── download_order.php             ← 주문서 엑셀 생성 + 이력 저장
│
├── partials/
│   ├── header.php                     ← 발주 전용 헤더 (TailwindCSS, 독립 네비)
│   └── footer.php                     ← 공통 footer
│
└── sql/
    └── order_create_tables.sql        ← 6개 테이블 DDL

/sunset/uploads/order_inventories/     ← 원본 엑셀 파일 저장
    {vendor_id}/
        {yyyymmdd}_{upload_id}_{original_filename}
```

---

## 3. 핵심 파일 구현 패턴

### 3.1 order/config/db.php (logistics 패턴 동일)

```php
<?php
require_once __DIR__ . '/../../config/db_config.php';

if (!defined('ORD_BASE')) {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($script, '/order/');
    $base_prefix = $pos !== false ? substr($script, 0, $pos) : '';
    define('ORD_BASE',     $base_prefix . '/order');
    define('ORD_WEB_ROOT', $base_prefix);  // 예: /sunset
}

function get_ord_db(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception('Database connection failed.');
    }
    $conn->set_charset(DB_CHARSET);
    return $conn;
}
```

### 3.2 order/lib/auth.php (logistics 패턴, ord_ 접두사)

```php
<?php
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../config/db.php';

function ord_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function ord_require_login(): void {
    ord_session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . ORD_BASE . '/login.php');
        exit;
    }
}

// 관리자 여부 (업체 설정, 재고 업로드 권한)
function ord_is_admin(): bool {
    ord_session_start();
    return in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])
        || has_permission('purchase_management');
}

// 관리자 전용 페이지 접근 제한
function ord_require_admin(): void {
    ord_require_login();
    if (!ord_is_admin()) {
        ord_set_flash('error', '접근 권한이 없습니다.');
        header('Location: ' . ORD_BASE . '/index.php');
        exit;
    }
}

function ord_current_user_id(): int  { return (int)($_SESSION['user_id'] ?? 0); }
function ord_current_store_id(): ?int { return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null; }

// CSRF
function ord_csrf_token(): string {
    ord_session_start();
    if (empty($_SESSION['ord_csrf'])) {
        $_SESSION['ord_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['ord_csrf'];
}

function ord_verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['ord_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('CSRF validation failed');
    }
}

// 플래시 메시지
function ord_set_flash(string $type, string $message): void {
    ord_session_start();
    $_SESSION['ord_flash'] = ['type' => $type, 'message' => $message];
}

function ord_get_flash(): ?array {
    $flash = $_SESSION['ord_flash'] ?? null;
    unset($_SESSION['ord_flash']);
    return $flash;
}
```

---

## 4. 데이터베이스 설계

### 4.1 테이블 목록

| 테이블명 | 역할 |
|----------|------|
| `order_vendors` | 업체 기본 정보 |
| `order_vendor_column_maps` | 업체별 엑셀 컬럼 매핑 설정 |
| `order_vendor_inventories` | 업로드된 재고 파일 이력 |
| `order_vendor_inventory_items` | 파싱된 재고 아이템 행 |
| `order_cart_items` | 점포별 장바구니 |
| `order_history` | 발주 완료 이력 |

### 4.2 DDL (order/sql/order_create_tables.sql)

```sql
CREATE TABLE order_vendors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  contact_info VARCHAR(200),
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_vendor_column_maps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  sheet_index INT DEFAULT 0,
  header_row INT DEFAULT 1,
  product_name_col VARCHAR(5) NOT NULL,
  quantity_col VARCHAR(5) NOT NULL,
  unit_price_col VARCHAR(5),
  unit_col VARCHAR(5),
  product_code_col VARCHAR(5),
  notes TEXT,
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_vendor_inventories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filepath VARCHAR(500) NOT NULL,
  upload_date DATE NOT NULL,
  uploaded_by INT,
  row_count INT DEFAULT 0,
  is_current TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_vendor_inventory_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT NOT NULL,
  vendor_id INT NOT NULL,
  row_number INT NOT NULL,
  product_name VARCHAR(500) NOT NULL,
  product_code VARCHAR(100),
  unit_price DECIMAL(10,2),
  unit VARCHAR(50),
  extra_data JSON,
  FOREIGN KEY (inventory_id) REFERENCES order_vendor_inventories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_cart_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,
  vendor_id INT NOT NULL,
  inventory_item_id INT NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_cart_item (store_id, inventory_item_id),
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id),
  FOREIGN KEY (inventory_item_id) REFERENCES order_vendor_inventory_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,
  vendor_id INT NOT NULL,
  vendor_name VARCHAR(100) NOT NULL,
  ordered_by INT,
  order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  items_json JSON NOT NULL,
  item_count INT DEFAULT 0,
  inventory_id INT,
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 5. API 설계 (AJAX 엔드포인트)

### 5.1 상품 검색
`POST order/ajax/search.php`

**요청:**
```json
{ "keyword": "사과", "vendor_id": 0, "page": 1, "limit": 50 }
```

**응답:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "vendor_id": 3,
      "vendor_name": "대성농산",
      "product_name": "사과 부사",
      "product_code": "AP001",
      "unit_price": 15000.00,
      "unit": "박스",
      "in_cart": false,
      "cart_quantity": 0
    }
  ],
  "total": 12
}
```

### 5.2 장바구니 액션
`POST order/ajax/cart_action.php`

**요청:**
```json
{ "action": "add|update|remove|clear_vendor", "inventory_item_id": 1, "quantity": 5, "vendor_id": 3 }
```

**응답:**
```json
{ "success": true, "cart_count": 15, "vendor_summary": [...] }
```

### 5.3 주문서 다운로드
`POST order/ajax/download_order.php`

**요청:** `{ "vendor_id": 3, "save_history": true }`

**응답:** `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` 바이너리

---

## 6. 핵심 로직 (order/lib/order_helper.php)

### 6.1 숨겨진 행 파싱

```php
function ord_parse_visible_rows(string $filepath, object $colMap): array {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filepath);
    $sheet = $spreadsheet->getSheet($colMap->sheet_index);
    $items = [];

    foreach ($sheet->getRowIterator() as $row) {
        $idx = $row->getRowIndex();
        if ($idx <= $colMap->header_row) continue;

        $dim = $sheet->getRowDimension($idx);
        if (!$dim->getVisible() || $dim->getRowHeight() == 0) continue;

        $name = trim($sheet->getCell($colMap->product_name_col . $idx)->getCalculatedValue());
        if ($name === '') continue;

        $items[] = [
            'row_number'   => $idx,
            'product_name' => $name,
            'product_code' => $colMap->product_code_col
                ? trim($sheet->getCell($colMap->product_code_col . $idx)->getCalculatedValue()) : null,
            'unit_price'   => $colMap->unit_price_col
                ? (float)$sheet->getCell($colMap->unit_price_col . $idx)->getCalculatedValue() : null,
            'unit'         => $colMap->unit_col
                ? trim($sheet->getCell($colMap->unit_col . $idx)->getCalculatedValue()) : null,
        ];
    }
    return $items;
}
```

### 6.2 주문서 생성 (원본 서식 유지)

```php
function ord_generate_order_file(string $originalPath, object $colMap, array $cartItems): string {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($originalPath);
    $sheet = $spreadsheet->getSheet($colMap->sheet_index);

    // row_number → quantity 맵
    $qtyMap = array_column($cartItems, 'quantity', 'row_number');

    foreach ($sheet->getRowIterator() as $row) {
        $idx = $row->getRowIndex();
        if ($idx <= $colMap->header_row) continue;
        $cell = $colMap->quantity_col . $idx;
        $sheet->setCellValue($cell, $qtyMap[$idx] ?? '');
    }

    $tmp = sys_get_temp_dir() . '/ord_' . uniqid() . '.xlsx';
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);
    return $tmp;
}
```

---

## 7. UI 화면 설계

### 7.1 메인 화면 (order/index.php)

```
┌─────────────────────────────────────────────────────────────┐
│  [HOME K MART — 발주관리]  검색  업로드  업체관리  발주이력   │
├──────────────────────────────────────────────────────────────┤
│  🔍 [검색어__________________] [업체 전체▼] [검색]            │
├──────────────────────────┬───────────────────────────────────┤
│  검색 결과 (128건)        │  장바구니                          │
│  ─────────────────────── │  ── 대성농산 (3종) ──              │
│  □ 대성농산               │  사과 부사    [5] 박스  [X]        │
│    사과 부사   15,000      │  배           [3] 박스  [X]        │
│    [담기]                │  감귤         [2] 박스  [X]        │
│  □ 한국식품               │  [📥 대성농산 발주서 다운로드]      │
│    설탕 1kg    3,500       │  ───────────────────────────────  │
│    [담기]                │  ── 한국식품 (1종) ──              │
│                          │  설탕 1kg     [5] 봉    [X]        │
│                          │  [📥 한국식품 발주서 다운로드]      │
└──────────────────────────┴───────────────────────────────────┘
```

### 7.2 업체 관리 (order/vendors.php)

```
┌──────────────────────────────────────────────────────┐
│  업체 관리                            [+ 업체 등록]   │
├──────────────────────────────────────────────────────┤
│  #  업체명    컬럼설정    최근업로드    상태  액션      │
│  1  대성농산  ✅ 설정됨   2026-05-28   활성  [수정][삭제]│
│  2  한국식품  ⚠️ 미설정   -            활성  [설정][삭제]│
└──────────────────────────────────────────────────────┘
```

### 7.3 컬럼 설정 (order/vendor_column_setup.php)

```
┌──────────────────────────────────────────────────────┐
│  대성농산 — 엑셀 컬럼 설정                             │
├──────────────────────────────────────────────────────┤
│  시트:  [첫 번째 시트▼]    헤더 행: [2]               │
│                                                      │
│  헤더 미리보기 (2행):                                 │
│  A: 번호 | B: 상품코드 | C: 상품명 | D: 단위 |         │
│  E: 단가  | F: 수량    | G: 비고                      │
│                                                      │
│  상품명 컬럼: [C▼]    수량 컬럼: [F▼]                 │
│  단가 컬럼:   [E▼]    단위 컬럼: [D▼] (선택)          │
│  상품코드:    [B▼]               (선택)              │
│  [저장]                                              │
└──────────────────────────────────────────────────────┘
```

---

## 8. 권한 설계

| 기능 | 필요 권한 |
|------|-----------|
| 업체 관리 + 컬럼 설정 | `ord_is_admin()` (admin/super_admin 또는 purchase_management) |
| 재고 업로드 | `ord_is_admin()` |
| 상품 검색 + 장바구니 | 로그인 사용자 전체 (`ord_require_login()`) |
| 주문서 다운로드 | 로그인 사용자 전체 |
| 주문 이력 조회 | 로그인 사용자 전체 |

---

## 9. 파일 저장 전략

```
uploads/order_inventories/{vendor_id}/{yyyymmdd}_{upload_id}_{original_filename}
예: uploads/order_inventories/3/20260529_47_대성농산_재고리스트.xlsx
```

- 업로드 시 기존 업체 재고 `is_current = 0`, 신규 `is_current = 1`
- 파일 삭제 금지 (이전 주문 이력의 서식 재현에 필요)
- `.htaccess`로 웹 직접 접근 차단

---

## 10. 네비게이션 통합

`admin/partials/header.php`에 발주관리 메뉴 링크 추가:
```html
<a href="<?= ORD_WEB_ROOT ?? '' ?>/order/index.php">발주관리</a>
```

또는 각 모듈 헤더(logistics/partials/header.php 등)에도 크로스링크 추가 가능.

---

## 11. 구현 가이드

### 11.1 구현 순서

| # | 파일 | 설명 |
|---|------|------|
| 1 | `order/sql/order_create_tables.sql` | DB 테이블 생성 |
| 2 | `order/config/db.php` | DB 연결 래퍼 + ORD_BASE |
| 3 | `order/lib/auth.php` | 발주 전용 권한 헬퍼 (ord_*) |
| 4 | `order/lib/order_helper.php` | 파싱 + 주문서 생성 로직 |
| 5 | `order/partials/header.php` + `footer.php` | 독립 헤더/푸터 |
| 6 | `order/login.php` + `logout.php` | 로그인 (admin 세션 공유) |
| 7 | `order/vendors.php` + `vendor_form.php` | 업체 CRUD |
| 8 | `order/vendor_column_setup.php` | 컬럼 매핑 설정 |
| 9 | `order/upload_inventory.php` | 재고 업로드 + 파싱 미리보기 |
| 10 | `order/ajax/search.php` | 상품 검색 AJAX |
| 11 | `order/ajax/cart_action.php` | 장바구니 AJAX |
| 12 | `order/index.php` | 메인 검색+장바구니 UI |
| 13 | `order/ajax/download_order.php` | 주문서 생성 + 이력 저장 |
| 14 | `order/order_history.php` | 발주 이력 조회 |
| 15 | admin 헤더에 발주관리 링크 추가 | 네비 통합 |

### 11.2 Session Guide (모듈별)

| 모듈 | 파일 | 예상 줄 수 |
|------|------|----------|
| Module 1: 기반 인프라 | config/db.php, lib/auth.php, lib/order_helper.php, partials/*, login.php, logout.php, sql | ~300 |
| Module 2: 업체 설정 | vendors.php, vendor_form.php, vendor_column_setup.php | ~250 |
| Module 3: 재고 업로드 | upload_inventory.php | ~200 |
| Module 4: 검색+장바구니 | ajax/search.php, ajax/cart_action.php, index.php | ~350 |
| Module 5: 주문서+이력 | ajax/download_order.php, order_history.php | ~200 |

### 11.3 Session Guide 상세

```
/pdca do vendor-order-system --scope module-1   # 기반 인프라 (logistics 패턴 복제)
/pdca do vendor-order-system --scope module-2   # 업체 설정 UI
/pdca do vendor-order-system --scope module-3   # 재고 업로드
/pdca do vendor-order-system --scope module-4   # 검색 + 장바구니 (핵심 UI)
/pdca do vendor-order-system --scope module-5   # 주문서 생성 + 발주 이력
```
