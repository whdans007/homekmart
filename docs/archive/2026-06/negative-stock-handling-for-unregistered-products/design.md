# Design: 미등록 상품 마이너스 재고 처리

**Feature**: negative-stock-handling-for-unregistered-products  
**Architecture**: Clean Architecture (Option B)  
**Status**: Design  
**Created**: 2026-06-11  
**Owner**: Logistics Team

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 지점 운영 중 미등록 상품도 즉시 처리해야 하는 현장 요구사항 |
| **WHO** | 지점 직원 (출고 실행), 센터 관리자 (재고 정상화) |
| **RISK** | 마이너스 재고 오용 가능성, 부정확한 재고 데이터 축적 |
| **SUCCESS** | ✅ 마이너스 재고 자동 생성 ✅ 정상화 UI 제공 ✅ 추적 이력 기록 |
| **SCOPE** | 출고 기능 수정 + 재고 관리 UI + 정상화 로직 (가격 책정, 주문 관리는 제외) |

---

## 1. 아키텍처 개요

### 1.1 선택된 아키텍처: Clean Architecture
- **StockService 클래스**: 모든 재고 관련 로직 중앙화
- **감사 로그 통합**: 모든 변경사항 기록
- **정상화 로직 분리**: 자동/수동 정상화 별도 처리
- **권한 기반 접근**: 관리자 기능 보호

### 1.2 아키텍처 다이어그램

```
┌─────────────────────────────────────────┐
│  UI Layer (View)                        │
├─────────────────────────────────────────┤
│ - export_form.php (출고 폼 수정)        │
│ - stock_list.php (재고 목록 수정)       │
│ - negative_stock_dashboard.php (신규)   │
├─────────────────────────────────────────┤
│  Business Logic Layer (Service)         │
├─────────────────────────────────────────┤
│ - StockService.php (신규)               │
│   ├─ createNegativeStock()              │
│   ├─ normalizeStock()                   │
│   ├─ manualAdjustStock()                │
│   └─ getNegativeStockList()             │
│ - AuditLogService.php (신규)            │
│   └─ logStockChange()                   │
├─────────────────────────────────────────┤
│  Data Access Layer (Repository)         │
├─────────────────────────────────────────┤
│ - StockRepository.php (신규)            │
│   ├─ updateStock()                      │
│   ├─ getStockByProductId()              │
│   └─ getNegativeStocks()                │
│ - AuditLogRepository.php (신규)         │
│   └─ insertLog()                        │
├─────────────────────────────────────────┤
│  Database                               │
├─────────────────────────────────────────┤
│ - stock 테이블 (수정)                   │
│ - stock_audit_log 테이블 (신규)         │
└─────────────────────────────────────────┘
```

---

## 2. 데이터 모델

### 2.1 기존 테이블 수정

**stock 테이블**
```sql
ALTER TABLE stock MODIFY quantity INT DEFAULT 0;
-- 기존: NOT NULL, >= 0 검증 제거
```

### 2.2 신규 테이블

**stock_audit_log 테이블**
```sql
CREATE TABLE stock_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  stock_id INT,
  action VARCHAR(50), -- 'NEGATIVE_STOCK', 'NORMALIZE', 'MANUAL_ADJUST'
  old_quantity INT,
  new_quantity INT,
  reason TEXT,
  branch_id INT,
  user_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### 2.3 데이터 흐름

```
미등록 상품 출고
  ↓
StockService::createNegativeStock()
  ├─ quantity = 0 - export_qty (음수 설정)
  ├─ StockRepository::updateStock() (DB 저장)
  └─ AuditLogService::logStockChange() (로그 기록)
  
상품 등록 → 자동 정상화
  ↓
StockService::normalizeStock() (자동 호출)
  ├─ 기존 음수 재고 감지
  ├─ quantity = (-quantity) + new_inbound_qty
  └─ AuditLogService::logStockChange() (정상화 로그)

관리자 수동 조정
  ↓
StockService::manualAdjustStock()
  ├─ 사유 입력 (required)
  ├─ 수량 조정
  └─ AuditLogService::logStockChange() (조정 로그)
```

---

## 3. 주요 컴포넌트

### 3.1 StockService.php (신규)

```php
class StockService {
  private $stockRepo;
  private $auditService;
  
  // 마이너스 재고 생성 (출고 시)
  public function createNegativeStock($productId, $qty, $branchId, $userId) {
    $stock = $this->stockRepo->getStockByProductId($productId);
    if (!$stock) {
      // 신규 생성
      $this->stockRepo->createStock($productId, -$qty);
    } else {
      // 기존 재고 차감
      $this->stockRepo->updateStock($productId, $stock['quantity'] - $qty);
    }
    $this->auditService->logStockChange($productId, 'NEGATIVE_STOCK', 
      $stock['quantity'] ?? 0, -$qty, '미등록 상품 출고');
  }
  
  // 정상화 (자동: 상품 등록 후)
  public function normalizeStock($productId, $inboundQty, $userId) {
    $stock = $this->stockRepo->getStockByProductId($productId);
    if ($stock && $stock['quantity'] < 0) {
      $newQty = $stock['quantity'] + $inboundQty;
      $this->stockRepo->updateStock($productId, $newQty);
      $this->auditService->logStockChange($productId, 'NORMALIZE',
        $stock['quantity'], $newQty, '상품 등록 후 자동 정상화');
    }
  }
  
  // 수동 조정 (관리자)
  public function manualAdjustStock($productId, $adjustQty, $reason, $userId) {
    // 권한 확인: 관리자 만 허용
    $stock = $this->stockRepo->getStockByProductId($productId);
    $newQty = $stock['quantity'] + $adjustQty;
    $this->stockRepo->updateStock($productId, $newQty);
    $this->auditService->logStockChange($productId, 'MANUAL_ADJUST',
      $stock['quantity'], $newQty, $reason, $userId);
  }
  
  // 마이너스 재고 목록 조회
  public function getNegativeStockList($limit = 50, $offset = 0) {
    return $this->stockRepo->getNegativeStocks($limit, $offset);
  }
}
```

### 3.2 AuditLogService.php (신규)

```php
class AuditLogService {
  private $auditRepo;
  
  public function logStockChange($productId, $action, $oldQty, $newQty, $reason, $userId) {
    $this->auditRepo->insertLog([
      'product_id' => $productId,
      'action' => $action,
      'old_quantity' => $oldQty,
      'new_quantity' => $newQty,
      'reason' => $reason,
      'user_id' => $userId,
      'created_at' => date('Y-m-d H:i:s')
    ]);
  }
}
```

---

## 4. 페이지 및 기능

### 4.1 수정 대상 페이지

**export_form.php (출고 폼)**
```diff
- 미등록 상품 선택 불가
+ 미등록 상품 선택 가능 (경고 메시지 표시)
+ 출고 시 StockService::createNegativeStock() 호출
```

**stock_list.php (재고 목록)**
```diff
+ 음수 수량은 빨간색 + ⚠️ 아이콘 표시
+ 클릭하면 상세 정보 (발생 시간, 발생자) 표시
+ 추가 출고 시 경고 메시지
```

### 4.2 신규 페이지

**negative_stock_dashboard.php (마이너스 재고 관리)**
- 마이너스 재고 목록 표시
- 필터: 상태 (대기/해결)
- 액션: 수동 정상화, 이력 조회
- 권한: 관리자만

---

## 5. 시퀀스 다이어그램

### 5.1 미등록 상품 출고

```
사용자              export_form.php      StockService      Database
  │                      │                    │                │
  ├─ 상품 선택 (미등록)──>│                    │                │
  │                      ├─ 경고 표시          │                │
  │                      │<────────────────────│                │
  │                      │                    │                │
  ├─ 수량 입력 & 확인────>│                    │                │
  │                      ├─ createNegativeStock()              │
  │                      │──────────────────>│                  │
  │                      │                    ├─ 음수 재고 저장──>│
  │                      │                    │                │<─
  │                      │                    ├─ 감사 로그 저장──>│
  │                      │                    │<────────────────│
  │<─ 완료 메시지────────│<───────────────────│                │
```

### 5.2 자동 정상화 (상품 등록 후)

```
product_add.php         StockService        Database
      │                      │                  │
      ├─ 상품 등록────>      │                  │
      │                      ├─ normalizeStock()│
      │                      ├─ 음수 감지       │
      │                      ├─ 수량 계산       │
      │                      ├─ 재고 업데이트──>│
      │                      │<──────────────────│
      │                      ├─ 로그 기록──────>│
      │<─ 완료─────────────│<──────────────────│
```

---

## 6. API 엔드포인트

### 6.1 출고 API (수정)

**POST /admin/ajax_export_order.php**
```json
{
  "product_id": 123,
  "quantity": 5,
  "branch_id": 2
}

Response:
{
  "success": true,
  "message": "출고 완료",
  "new_stock": -5
}
```

### 6.2 정상화 API (신규)

**POST /admin/ajax_normalize_stock.php**
```json
{
  "product_id": 123,
  "method": "manual", // or "auto"
  "reason": "재고 부재 처리",
  "adjust_qty": 5
}

Response:
{
  "success": true,
  "message": "정상화 완료",
  "new_stock": 0
}
```

**GET /admin/ajax_get_negative_stocks.php**
```
Response:
[
  {
    "product_id": 123,
    "product_name": "상품명",
    "quantity": -5,
    "created_at": "2026-06-11 10:30:00",
    "created_by": "이름",
    "branch_id": 2
  }
]
```

---

## 7. UI 컴포넌트

### 7.1 경고 메시지

**미등록 상품 선택 시**
```html
<div class="alert alert-warning">
  <i class="fas fa-exclamation-triangle"></i>
  이 상품은 아직 등록되지 않았습니다. 계속하시겠습니까?
  <button class="btn-yes">예</button>
  <button class="btn-no">아니오</button>
</div>
```

### 7.2 음수 재고 표시

```html
<td class="text-danger font-weight-bold">
  <i class="fas fa-exclamation-circle"></i> -5
  <small class="text-muted">(2026-06-11 10:30)</small>
</td>
```

### 7.3 정상화 버튼

```html
<button class="btn btn-primary btn-sm" onclick="normalizeStock(123)">
  <i class="fas fa-check"></i> 정상화
</button>
<button class="btn btn-info btn-sm" onclick="viewHistory(123)">
  <i class="fas fa-history"></i> 이력
</button>
```

---

## 8. 보안 및 권한

### 8.1 권한 제어

| 기능 | 지점 직원 | 센터 관리자 |
|------|---------|-----------|
| 미등록 상품 출고 | ✅ | ✅ |
| 마이너스 재고 조회 | ❌ | ✅ |
| 수동 정상화 | ❌ | ✅ |
| 감사 로그 조회 | ❌ | ✅ |

### 8.2 데이터 검증

- 수량: 정수 ≥ -999999
- 사유: 필수 (max 500자)
- 사용자: 로그인 필수
- 감사 로그: 모든 변경사항 기록

---

## 9. 에러 처리

### 9.1 예상 에러 시나리오

| 시나리오 | 처리 방법 |
|---------|---------|
| 출고 중 DB 연결 실패 | 롤백 + 에러 메시지 |
| 권한 없는 정상화 시도 | 403 Forbidden |
| 중복 정상화 | 트랜잭션 Lock + 경고 |
| 음수 수량 검증 실패 | 400 Bad Request |

---

## 10. 성능 최적화

### 10.1 쿼리 최적화

```sql
-- Index 추가
CREATE INDEX idx_stock_product_id ON stock(product_id);
CREATE INDEX idx_stock_quantity ON stock(quantity);
CREATE INDEX idx_audit_product_id ON stock_audit_log(product_id);
CREATE INDEX idx_audit_created_at ON stock_audit_log(created_at);
```

### 10.2 캐싱

- 마이너스 재고 목록: Redis 캐싱 (5분)
- 조회 성능: < 500ms 목표

---

## 11. 구현 가이드

### 11.1 파일 구조

```
lib/
  ├─ StockService.php (신규)
  ├─ AuditLogService.php (신규)
  ├─ StockRepository.php (신규)
  └─ AuditLogRepository.php (신규)

admin/
  ├─ export_form.php (수정)
  ├─ stock_list.php (수정)
  ├─ negative_stock_dashboard.php (신규)
  ├─ ajax/
  │  ├─ ajax_export_order.php (수정)
  │  ├─ ajax_normalize_stock.php (신규)
  │  └─ ajax_get_negative_stocks.php (신규)

sql/
  └─ 001_negative_stock_schema.sql (신규)
```

### 11.2 구현 순서

1. **데이터베이스 설정** (stock_audit_log 테이블 생성)
2. **Repository 클래스** (StockRepository, AuditLogRepository)
3. **Service 클래스** (StockService, AuditLogService)
4. **출고 로직 수정** (export_form.php, ajax_export_order.php)
5. **UI 업데이트** (stock_list.php, 메시지 표시)
6. **정상화 기능** (ajax_normalize_stock.php, 대시보드)
7. **테스트** (전체 시나리오 검증)

### 11.3 Session Guide

#### Session 1: 기반 구축 (1일)
- 데이터베이스 스키마 생성
- Repository 클래스 구현
- Service 클래스 기본 틀

#### Session 2: 출고 로직 (1일)
- StockService 완성
- export_form.php 수정
- ajax_export_order.php 수정

#### Session 3: UI & 정상화 (1일)
- stock_list.php 수정
- negative_stock_dashboard.php 개발
- ajax_normalize_stock.php 구현

#### Session 4: QA & 배포 (1일)
- 통합 테스트
- 권한 검증
- 감사 로그 확인
- 배포

---

## 12. 테스트 계획

### 12.1 Unit Tests

- `StockService::createNegativeStock()` 
- `StockService::normalizeStock()`
- `StockService::manualAdjustStock()`

### 12.2 Integration Tests

- T-1: 미등록 상품 출고 → 음수 재고 생성
- T-2: 상품 등록 → 자동 정상화
- T-3: 수동 정상화 → 로그 기록
- T-4: 권한 검증 → 403 반환

### 12.3 UI Tests

- 경고 메시지 표시 확인
- 음수 재고 빨간색 표시 확인
- 정상화 버튼 작동 확인
- 대시보드 필터링 확인

---

## 13. 배포 체크리스트

- [ ] 데이터베이스 마이그레이션 완료
- [ ] 모든 테이블 인덱스 생성
- [ ] 서비스 클래스 배포
- [ ] UI 수정사항 적용
- [ ] 권한 설정 확인
- [ ] 감사 로그 활성화
- [ ] 사용자 교육 (관리자 가이드)

---

## 14. 다음 단계

✅ **Design 완료**  
→ `/pdca do negative-stock-handling-for-unregistered-products` 실행  
→ 구현 시작 (Session Guide 참고)
