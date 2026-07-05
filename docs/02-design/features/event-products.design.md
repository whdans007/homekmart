# Design: 행사상품 등록 (Event Products)

**Feature**: event-products  
**Phase**: Design  
**Architecture**: Option A — 단일 페이지  
**Created**: 2026-05-01  

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 행사/프로모션 상품을 점포별로 독립 관리하고 할인가 적용 이력을 추적하기 위함 |
| **WHO** | admin/super_admin 역할의 점포 관리자 |
| **RISK** | 기존 inventory/price_change_history 테이블 구조에 의존 — 가격 데이터 없는 신규 상품 처리 필요 |
| **SUCCESS** | 행사상품 등록, 원가/판매가 자동 조회, 행사가 저장, 행사 목록 조회 4개 기능 정상 동작 |
| **SCOPE** | admin/partials/header.php 네비게이션 + 신규 PHP 페이지 3개 + DB 테이블 1개 |

---

## 1. 아키텍처 결정 (Option A)

### 선택 이유
- 기존 `wholesale_sales_list.php` 단일 페이지 패턴과 일관성 유지
- 파일 수 최소화 (3개)로 빠른 개발 및 유지보수
- 목록-등록-수정이 한 화면에서 처리되어 UX 흐름이 자연스러움

### 파일 구성

```
admin/
├── event_products_list.php          ← 목록 + 인라인 등록/수정 폼
├── ajax_search_event_products.php   ← 상품 검색 AJAX
├── ajax_save_event_product.php      ← 저장/수정/삭제 AJAX
└── partials/
    └── header.php                   ← 점간이동 섹션에 메뉴 추가
```

---

## 2. DB 설계

### 2.1 신규 테이블: `event_products`

```sql
CREATE TABLE IF NOT EXISTS event_products (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    store_id         INT NOT NULL,
    product_id       INT NOT NULL,
    event_price      DECIMAL(10,2) NOT NULL,
    original_cost    DECIMAL(10,2) DEFAULT NULL,
    original_selling DECIMAL(10,2) DEFAULT NULL,
    start_date       DATE NOT NULL,
    end_date         DATE NOT NULL,
    remarks          VARCHAR(255) DEFAULT NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_by       INT DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_id (store_id),
    INDEX idx_product_id (product_id),
    INDEX idx_dates (start_date, end_date),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2.2 컬럼 설명

| 컬럼 | 타입 | 설명 |
|------|------|------|
| `event_price` | DECIMAL(10,2) | 행사 할인가 |
| `original_cost` | DECIMAL(10,2) | 등록 시점 원가 스냅샷 (inventory.cost_price) |
| `original_selling` | DECIMAL(10,2) | 등록 시점 판매가 스냅샷 (price_change_history) |
| `start_date` | DATE | 행사 시작일 |
| `end_date` | DATE | 행사 종료일 |
| `is_active` | TINYINT(1) | 1=활성, 0=비활성(수동 종료) |

---

## 3. 페이지 설계: `event_products_list.php`

### 3.1 PHP 로직 구조

```php
<?php
// 1. 세션/권한 확인 (store_transfer_management 권한)
// 2. DB 연결 (PDO)
// 3. 현재 점포 ID 결정
//    - super_admin: GET['store_id'] 파라미터로 점포 선택 가능
//    - admin: $_SESSION['store_id'] 고정
// 4. 수정 모드 확인: GET['edit'] 파라미터
// 5. 수정 데이터 로드 (edit 모드 시)
// 6. 행사상품 목록 조회
//    - 필터: status (all/active/ended)
//    - super_admin이면 store_id 조건 없음
// 7. 점포 목록 (super_admin용)
?>
```

### 3.2 목록 쿼리

```sql
SELECT 
    ep.id,
    ep.event_price,
    ep.original_cost,
    ep.original_selling,
    ep.start_date,
    ep.end_date,
    ep.remarks,
    ep.is_active,
    ep.created_at,
    p.id as product_id,
    p.name_ko,
    p.name_en,
    p.sku,
    s.name as store_name,
    CASE 
        WHEN ep.end_date < CURDATE() THEN 'ended'
        WHEN ep.start_date > CURDATE() THEN 'upcoming'
        ELSE 'active'
    END as status
FROM event_products ep
JOIN products p ON ep.product_id = p.id
JOIN stores s ON ep.store_id = s.id
WHERE ep.store_id = :store_id   -- super_admin이면 조건 없음
ORDER BY ep.created_at DESC
```

### 3.3 UI 레이아웃

```
┌─────────────────────────────────────────────────────┐
│ 행사상품 관리               [+ 새 행사상품 등록 ▼]    │
├─────────────────────────────────────────────────────┤
│ 필터: [전체▼]  [점포 선택▼] (super_admin만)          │
├─────────────────────────────────────────────────────┤
│ ▼ 행사상품 등록 폼 (기본 접혀있음, 버튼 클릭 시 펼침) │
│ ┌───────────────────────────────────────────────┐   │
│ │ 점포: [선택▼]  상품: [검색창       ] [검색]   │   │
│ │                                               │   │
│ │ 원가: ₩XX,XXX  판매가: ₩XX,XXX  할인율: XX%  │   │
│ │                                               │   │
│ │ 행사가: [      ] 원                           │   │
│ │ 기간:  [시작일  ] ~ [종료일  ]                │   │
│ │ 비고:  [                    ]                 │   │
│ │                          [취소] [등록/저장]   │   │
│ └───────────────────────────────────────────────┘   │
├─────────────────────────────────────────────────────┤
│ 상품명     │원가  │판매가│행사가│할인율│기간       │ │
├────────────┼──────┼──────┼──────┼──────┼───────────┤ │
│ 상품A      │10,000│15,000│9,900 │34%  │~05/31 진행│[수정][삭제]│
│ 상품B      │ 8,000│12,000│8,500 │29%  │~04/30 종료│[수정][삭제]│
└─────────────────────────────────────────────────────┘
```

---

## 4. AJAX API 설계

### 4.1 `ajax_search_event_products.php`

**Request** (POST):
```json
{
  "q": "상품명 또는 SKU",
  "store_id": 1
}
```

**Response**:
```json
{
  "success": true,
  "products": [
    {
      "id": 42,
      "sku": "AB-001",
      "name_ko": "상품A",
      "name_en": "Product A",
      "cost_price": "10000.00",
      "selling_price": "15000.00"
    }
  ]
}
```

**쿼리 로직**:
```sql
SELECT
    p.id,
    p.sku,
    p.name_ko,
    p.name_en,
    COALESCE(i.cost_price, 0) as cost_price,
    COALESCE((
        SELECT pch.new_selling_price 
        FROM price_change_history pch 
        WHERE pch.sku = p.sku 
        ORDER BY pch.created_at DESC 
        LIMIT 1
    ), 0) as selling_price
FROM products p
LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = :store_id
WHERE p.is_active = 1
  AND (p.name_ko LIKE :q OR p.name_en LIKE :q OR p.sku LIKE :q)
LIMIT 20
```

### 4.2 `ajax_save_event_product.php`

**Request** (POST):
```json
{
  "action": "save",     // "save" | "delete"
  "id": null,           // null이면 신규, 숫자면 수정
  "store_id": 1,
  "product_id": 42,
  "event_price": 9900,
  "original_cost": 10000,
  "original_selling": 15000,
  "start_date": "2026-05-01",
  "end_date": "2026-05-31",
  "remarks": "어린이날 행사"
}
```

**Response**:
```json
{
  "success": true,
  "message": "행사상품이 등록되었습니다.",
  "id": 7
}
```

**삭제 Request** (POST):
```json
{
  "action": "delete",
  "id": 7
}
```

**서버 사이드 검증**:
- `event_price` > 0 필수
- `start_date` <= `end_date` 필수
- `product_id`, `store_id` 정수형 필수
- 권한 확인: super_admin 아니면 store_id가 세션 store_id와 일치해야 함

---

## 5. 네비게이션 수정 (`header.php`)

점간이동 카드 (보라색) 내부에 메뉴 링크 추가:

```php
<!-- 기존 점간이동 링크 아래에 추가 -->
<a href="event_products_list.php" 
   class="<?php echo in_array($current_page, ['event_products_list.php']) 
     ? 'bg-purple-200 text-purple-900' 
     : 'text-purple-700 hover:bg-purple-200 hover:text-purple-900'; ?> 
   group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
    <i class="fas fa-tag mr-2 text-purple-500 group-hover:text-purple-600 text-xs"></i>
    행사상품 관리
</a>
```

---

## 6. 디자인 토큰 (기존 시스템 따름)

| 요소 | 클래스 |
|------|--------|
| 섹션 배경 | `bg-gradient-to-br from-purple-50 to-purple-100` |
| 테이블 헤더 | `bg-gray-50 text-xs font-medium text-gray-500 uppercase` |
| 버튼 (등록) | `bg-purple-600 hover:bg-purple-700 text-white` |
| 버튼 (취소) | `bg-gray-200 hover:bg-gray-300 text-gray-700` |
| 버튼 (삭제) | `text-red-600 hover:text-red-800` |
| 상태 뱃지 (진행중) | `bg-green-100 text-green-800` |
| 상태 뱃지 (예정) | `bg-blue-100 text-blue-800` |
| 상태 뱃지 (종료) | `bg-gray-100 text-gray-600` |

---

## 7. 에러 처리

| 케이스 | 처리 방법 |
|--------|----------|
| 판매가 이력 없는 상품 | selling_price = null → UI에 "정보 없음" 표시, 등록은 허용 |
| 재고 없는 상품 | cost_price = 0 → UI에 "재고 없음" 경고, 등록은 허용 |
| 행사가 > 판매가 | 클라이언트 경고 표시 (차단하지 않음) |
| 중복 등록 (동일 상품+기간) | 경고 표시 후 허용 |
| DB 오류 | JSON `{"success": false, "message": "..."}` 반환 |

---

## 8. 테스트 시나리오

| ID | 시나리오 | 기대 결과 |
|----|----------|----------|
| T-01 | 상품명으로 검색 | 일치하는 상품 + 원가/판매가 자동 표시 |
| T-02 | 행사가 입력 + 기간 설정 후 등록 | DB 저장, 목록 갱신 |
| T-03 | 목록에서 [수정] 클릭 | 폼에 기존 데이터 로드 |
| T-04 | 수정 후 저장 | DB 업데이트, 목록 갱신 |
| T-05 | [삭제] 클릭 | 확인 후 삭제, 목록 갱신 |
| T-06 | admin으로 접속 | 자기 점포 목록만 표시 |
| T-07 | super_admin으로 접속 | 점포 선택 가능, 전체 목록 조회 가능 |
| T-08 | 판매가 없는 상품 등록 | "정보 없음" 표시, 등록 가능 |

---

## 9. 구현 순서

### Module 1: DB 마이그레이션
1. `event_products` 테이블 생성 SQL 작성
2. 테이블 생성 실행

### Module 2: AJAX — 상품 검색
1. `ajax_search_event_products.php` 작성
2. products + inventory + price_change_history 조인 쿼리 구현

### Module 3: AJAX — 저장/삭제
1. `ajax_save_event_product.php` 작성
2. save/delete action 분기 처리
3. 권한 검증 로직

### Module 4: 메인 페이지
1. `event_products_list.php` 작성
2. 목록 쿼리 + PHP 렌더링
3. 인라인 폼 (접힘/펼침)
4. 수정 모드 처리 (?edit=ID)
5. JavaScript: 검색 자동완성, 할인율 계산, AJAX 저장/삭제

### Module 5: 네비게이션
1. `header.php` 점간이동 섹션에 링크 추가

---

## 10. Session Guide

### Module Map

| 모듈 | 파일 | 예상 작업 시간 |
|------|------|--------------|
| module-1 | DB 마이그레이션 | 10분 |
| module-2 | ajax_search_event_products.php | 20분 |
| module-3 | ajax_save_event_product.php | 20분 |
| module-4 | event_products_list.php | 60분 |
| module-5 | header.php 수정 | 5분 |

### 추천 세션 분할

```
Session 1: module-1 + module-2 + module-3  (백엔드)
Session 2: module-4 + module-5             (프론트엔드 + 네비게이션)
```
