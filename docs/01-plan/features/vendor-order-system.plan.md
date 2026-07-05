# Plan: 업체별 주문 요청 시스템 (vendor-order-system)

> **Feature**: vendor-order-system
> **Phase**: Plan
> **Date**: 2026-05-29
> **Author**: whdans007

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 업체마다 서식이 다른 엑셀 재고리스트를 수작업으로 확인하고 전화/이메일로 발주 → 실수, 누락, 시간 낭비 발생 |
| **Solution** | 업체별 엑셀 파싱 설정 + 재고 DB 저장 + 장바구니 → 원본 서식 유지 주문서 자동 생성 다운로드 |
| **UX Effect** | 담당자: 검색창 하나로 12개+ 업체 재고 동시 탐색 → 장바구니에 담아 한 번에 업체별 발주서 다운로드. 관리자: 업체/컬럼 설정 한 번으로 이후 자동화 |
| **Core Value** | 업체 엑셀 서식 그대로 주문서 생성 → 업체 측 별도 작업 없음 + 발주 이력 자동 축적 |

---

## 1. User Intent Discovery

### 1.1 핵심 문제
업체 발주 자동화:
- 업체마다 서식이 다른 엑셀 재고리스트를 수동으로 처리하는 비효율
- 상품 검색 시 여러 파일을 직접 열어봐야 하는 불편
- 발주서 작성 시 업체 서식에 맞게 수작업으로 입력해야 하는 번거로움

### 1.2 대상 사용자
| 역할 | 수행 업무 |
|------|-----------|
| 관리자 (admin) | 업체 등록, 엑셀 컬럼 매핑 설정, 재고리스트 업로드 |
| 구매 담당 스태프 | 재고 검색, 장바구니 담기, 주문서 다운로드 |

### 1.3 성공 기준
- [ ] 관리자가 업체 컬럼 설정 후 재고 업로드 → 상품 검색 가능
- [ ] 숨겨진 행은 파싱에서 제외됨
- [ ] 검색 결과를 장바구니에 담고 업체별로 그룹화되어 표시
- [ ] 업체별 주문서 다운로드 시 원본 엑셀 서식 100% 유지
- [ ] 발주 완료 시 주문 이력이 DB에 저장됨

---

## 2. Alternatives Explored

| 방식 | 장점 | 단점 | 결정 |
|------|------|------|------|
| **A: 업체 설정 + 파싱 엔진** | **유연한 서식 지원, 업체 추가 쉬움, 설정 1회 후 자동** | **초기 업체 설정 시간 필요** | **✅ 채택** |
| B: 업로드 시 컬럼 지정 | 설정 페이지 불필요 | 매번 선택 번거로움, 일관성 없음 | ❌ |
| C: 세션 기반 처리 | 구현 단순 | 새로고침 시 장바구니 소실, 재고 누적 불가 | ❌ |

---

## 3. YAGNI Review

### 3.1 1차 버전 포함 (In Scope)
- [x] 업체 관리 (등록/수정/삭제) + 엑셀 컬럼 매핑 설정
- [x] 업체별 재고리스트 엑셀 업로드 (숨겨진 행 제외 파싱)
- [x] 상품명 검색 (업체 필터 포함)
- [x] 장바구니 (업체별 그룹 정렬 + 수량 입력)
- [x] 업체별 주문서 다운로드 (원본 엑셀 서식 유지)
- [x] 주문 이력 DB 저장 및 조회

### 3.2 2차 버전으로 미룸 (Out of Scope)
- [ ] 사용자별 독립 장바구니 (현재: 점포/사용자 공유)
- [ ] 모바일 최적화 UI
- [ ] 자동 발주 알림 (이메일/슬랙)
- [ ] 재고 부족 알림

---

## 4. Architecture Design

### 4.1 전체 흐름

```
[관리자 설정]
업체 등록 → 컬럼 매핑 설정 → 재고 엑셀 업로드
                                    ↓
                            원본 파일 서버 저장
                            보이는 행만 파싱 → inventory_items DB 저장

[스태프 업무]
상품명 검색 (업체 필터) → 장바구니 추가 (수량 입력)
                               ↓
                         업체별 정렬 표시
                               ↓
                    [업체별 주문하기] 클릭
                               ↓
                    원본 엑셀 복사 → 수량 칸 채우기 → 다운로드
                               ↓
                         주문 이력 DB 저장
```

### 4.2 디렉토리 구조

```
admin/
  order/
    index.php               ← 메인 (검색 + 장바구니)
    vendors.php             ← 업체 목록/관리
    vendor_form.php         ← 업체 등록/수정 폼
    vendor_column_setup.php ← 컬럼 매핑 설정
    upload_inventory.php    ← 재고 업로드
    cart.php                ← 장바구니 상세
    order_history.php       ← 주문 이력
  ajax_order_search.php     ← 검색 AJAX
  ajax_order_cart.php       ← 장바구니 CRUD AJAX
  ajax_order_download.php   ← 주문서 생성/다운로드

uploads/order_inventories/  ← 원본 엑셀 파일 저장
  {vendor_id}/
    {upload_id}_{filename}
```

### 4.3 데이터베이스 테이블

#### order_vendors
```sql
CREATE TABLE order_vendors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  contact_info VARCHAR(200),
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### order_vendor_column_maps
```sql
CREATE TABLE order_vendor_column_maps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  sheet_index INT DEFAULT 0,           -- 0: 첫 번째 시트
  header_row INT DEFAULT 1,            -- 헤더가 있는 행 번호
  product_name_col VARCHAR(5) NOT NULL, -- 예: 'A', 'B', 'C' 또는 컬럼 번호
  quantity_col VARCHAR(5) NOT NULL,     -- 주문 수량을 입력할 컬럼
  unit_price_col VARCHAR(5),           -- 단가 컬럼 (선택)
  unit_col VARCHAR(5),                 -- 단위 컬럼 (선택)
  product_code_col VARCHAR(5),         -- 상품코드 컬럼 (선택)
  notes TEXT,
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id) ON DELETE CASCADE
);
```

#### order_vendor_inventories
```sql
CREATE TABLE order_vendor_inventories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filepath VARCHAR(500) NOT NULL,  -- 서버 저장 경로
  upload_date DATE NOT NULL,
  uploaded_by INT,                        -- user_id
  row_count INT DEFAULT 0,               -- 파싱된 유효 행 수
  is_current TINYINT(1) DEFAULT 1,       -- 최신 재고 여부
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id) ON DELETE CASCADE
);
```

#### order_vendor_inventory_items
```sql
CREATE TABLE order_vendor_inventory_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT NOT NULL,
  vendor_id INT NOT NULL,
  row_number INT NOT NULL,              -- 원본 엑셀에서의 행 번호 (수량 입력 위치 특정용)
  product_name VARCHAR(500) NOT NULL,
  product_code VARCHAR(100),
  unit_price DECIMAL(10,2),
  unit VARCHAR(50),
  extra_data JSON,                      -- 기타 컬럼 데이터 보관
  FOREIGN KEY (inventory_id) REFERENCES order_vendor_inventories(id) ON DELETE CASCADE
);
```

#### order_cart_items
```sql
CREATE TABLE order_cart_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,               -- 점포 단위 장바구니
  vendor_id INT NOT NULL,
  inventory_item_id INT NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_cart_item (store_id, inventory_item_id),
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id),
  FOREIGN KEY (inventory_item_id) REFERENCES order_vendor_inventory_items(id)
);
```

#### order_history
```sql
CREATE TABLE order_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,
  vendor_id INT NOT NULL,
  ordered_by INT,                       -- user_id
  order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  items_json JSON NOT NULL,             -- 주문 시점 스냅샷
  original_inventory_id INT,           -- 기준 재고 업로드 ID
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id)
);
```

### 4.4 숨겨진 행 파싱 로직 (핵심)

```php
// PhpSpreadsheet 숨겨진 행 제외 파싱
foreach ($sheet->getRowIterator() as $row) {
    $rowIndex = $row->getRowIndex();
    $rowDimension = $sheet->getRowDimension($rowIndex);
    
    // 숨겨진 행 건너뜀
    if ($rowDimension->getVisible() === false) continue;
    // 행 높이 0인 경우도 숨겨진 것으로 처리
    if ($rowDimension->getRowHeight() == 0) continue;
    
    // 헤더 행 건너뜀
    if ($rowIndex <= $columnMap->header_row) continue;
    
    // 파싱 처리
    $productName = $sheet->getCell($columnMap->product_name_col . $rowIndex)->getValue();
    if (empty($productName)) continue;
    
    // DB 저장...
}
```

### 4.5 주문서 생성 로직 (원본 서식 유지)

```php
// 원본 파일 복사 후 수량만 채우기
$reader = IOFactory::createReaderForFile($originalFilePath);
$spreadsheet = $reader->load($originalFilePath);
$sheet = $spreadsheet->getSheet($columnMap->sheet_index);

foreach ($cartItems as $item) {
    $qtyCell = $columnMap->quantity_col . $item->row_number;
    $sheet->setCellValue($qtyCell, $item->quantity);
}

// 나머지 행의 수량 칸은 비움 (행 삭제 X, 서식 유지)
// ... 비장바구니 행 수량 초기화

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save($outputPath);
```

---

## 5. Key Technical Decisions

| 결정 | 이유 |
|------|------|
| 원본 파일 서버 저장 | 주문서 생성 시 원본 서식 100% 재현 필요 |
| 행 번호(row_number) DB 저장 | 원본 파일에서 수량 입력 위치 특정을 위해 |
| 컬럼을 문자열로 저장 (A, B...) | 엑셀 컬럼 레터와 직접 매핑, 직관적 |
| store_id 기준 장바구니 | 같은 점포 내 여러 스태프가 공동 작업 가능 |
| is_current 플래그 | 최신 재고만 검색에 사용, 이력은 보존 |

---

## 6. Brainstorming Log

| 단계 | 결정 | 이유 |
|------|------|------|
| Phase 1 | 핵심 목적 = 발주 자동화 | 재고 파악보다 발주서 생성이 주목적 |
| Phase 1 | 사용자 = 관리자 + 스태프 복수 | 설정과 사용 역할 분리 |
| Phase 1 | 서식 처리 = 관리자 사전 설정 | 매번 선택보다 1회 설정이 효율적 |
| Phase 2 | Approach A 채택 | 12개+ 업체 대응에 가장 유연 |
| Phase 3 | 주문 이력 포함 | 발주 추적은 사실상 필수 기능 |
| Phase 4 | 원본 파일 복사 후 수량 입력 | 서식 재현이 아닌 서식 유지가 핵심 |

---

## 7. Implementation Phases

### Phase 1: 기반 구축 (DB + 업체 관리)
- SQL 테이블 생성
- 업체 CRUD 페이지 (`vendors.php`, `vendor_form.php`)
- 컬럼 매핑 설정 페이지 (`vendor_column_setup.php`)

### Phase 2: 재고 업로드 + 파싱
- 재고 업로드 페이지 (`upload_inventory.php`)
- PhpSpreadsheet 기반 파싱 (숨겨진 행 제외)
- 파싱 결과 미리보기 및 저장

### Phase 3: 검색 + 장바구니
- 상품 검색 AJAX (`ajax_order_search.php`)
- 장바구니 추가/수정/삭제 AJAX (`ajax_order_cart.php`)
- 메인 화면 (`index.php`) — 검색 + 장바구니 업체별 그룹

### Phase 4: 주문서 생성 + 이력
- 주문서 다운로드 (`ajax_order_download.php`)
- 주문 이력 저장 및 조회 (`order_history.php`)

---

## 8. Out of Scope (v2)

- 사용자별 독립 장바구니
- 모바일 최적화 UI
- 자동 발주 알림
- 재고 부족 경고
- 업체 직접 연동 API
