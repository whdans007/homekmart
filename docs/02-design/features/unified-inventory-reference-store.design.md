# Design: 통합 재고 원장 및 Reference Store 기반 점포 운영

**Feature**: unified-inventory-reference-store  
**Status**: Design  
**Implementation owner**: Claude  
**UI owner**: Codex

## 1. 설계 원칙

1. 실제 점포 재고의 기준은 `inventory(product_id, store_id)`다.
2. 유통기한 재고는 `inventory_expirations`와 함께 처리한다.
3. 모든 재고 변경은 공통 서비스와 원장을 거친다.
4. 거래 원본 테이블은 보존하고 원장은 append-only를 기본으로 한다.
5. 음수 재고는 정상적으로 저장하고 그대로 표시한다.
6. Reference Store는 거래 점포를 변경하지 않는다.
7. 금액과 재고 수량의 기준을 혼합하지 않는다.

## 2. 데이터 모델

### 2.1 Reference Store history

```sql
CREATE TABLE mall_reference_store_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,
  effective_from DATETIME NOT NULL,
  effective_to DATETIME NULL,
  changed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reference_effective (effective_from, effective_to),
  KEY idx_reference_store (store_id)
);
```

불변 규칙:

- 동시에 열린 history row는 하나만 허용한다.
- 새 설정 저장 시 기존 row의 `effective_to`를 저장 시각으로 닫는다.
- 설정 저장과 history 갱신은 하나의 트랜잭션으로 처리한다.

### 2.2 설정값

```text
system_settings
setting_key   = mall_reference_store_id
setting_value = store id
```

`system_settings`는 현재값 캐시이며, 과거 기준은 history 테이블에서 조회한다.

### 2.3 재고 원장

```sql
CREATE TABLE inventory_ledger (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity_change INT NOT NULL,
  quantity_after INT NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_id BIGINT NOT NULL,
  reference_history_id INT NULL,
  occurred_at DATETIME NOT NULL,
  user_id INT NULL,
  remarks VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ledger_product_store_time (product_id, store_id, occurred_at),
  KEY idx_ledger_source (source_type, source_id),
  KEY idx_ledger_event (event_type, occurred_at)
);
```

중복 방지는 별도 idempotency 키 테이블 또는 다음 논리 키로 보장한다.

```text
source_type + source_id + store_id + product_id + event_type
```

복합 UNIQUE 적용 전 기존 데이터 중복 여부를 검사한다.

## 3. 재고 서비스 계약

파일 후보:

```text
lib/inventory_service.php
lib/reference_store_service.php
```

### 3.1 입출고

```php
inventory_apply_delta(
    mysqli $conn,
    int $storeId,
    int $productId,
    int $quantityChange,
    string $eventType,
    string $sourceType,
    int $sourceId,
    ?int $userId,
    string $remarks = ''
): array
```

동작:

1. `inventory` row를 `FOR UPDATE`로 조회
2. 없으면 수량 0을 기준으로 생성
3. `quantity_after = current + quantity_change`
4. 음수도 그대로 저장
5. `inventory.quantity` 갱신
6. `inventory_ledger` 기록
7. 필요 시 유통기한 lot 갱신

### 3.2 조정

실사 수량을 직접 저장하지 않고 차이를 계산한다.

```text
adjustment = physical_quantity - book_quantity
```

양수면 `ADJUSTMENT_IN`, 음수면 `ADJUSTMENT_OUT`으로 기록한다.

### 3.3 로트

- 입고: 해당 lot 증가
- POS/몰/도매/크레딧/폐기: FIFO 차감
- 음수로 부족한 수량은 일반 `inventory.quantity`에 반영
- lot가 부족하더라도 전체 출고를 차단하지 않는다.
- lot별 수량과 전체 수량의 차이는 대사 화면에서 표시한다.

## 4. 거래별 연동 설계

### 4.1 매입확정

대상: `admin/ajax_confirm_purchase.php`

트랜잭션:

```text
매입 lock
→ 미확정 여부 확인
→ 각 품목 낱개 수량 계산
→ PURCHASE_IN 적용
→ lot 입고
→ box_price/cost 갱신
→ Reference Store 조건이면 품절 해제
→ 매입 confirmed 저장
→ commit
```

품절 해제는 다음 조건을 모두 만족해야 한다.

- 일반상품
- 매입 점포 = 현재 Reference Store
- `mall_products` 등록 존재
- `is_sold_out = 1`
- 실제 입고 수량 > 0

### 4.2 POS

대상: `office/pos_data/upload.php` 및 업로드 처리부

- `item_code`를 SKU로 매칭
- `pcs`를 음수 delta로 적용
- `upload_id`를 source ID로 사용
- 재처리 시 같은 source가 이미 있으면 skip
- 미매칭 SKU는 별도 결과로 반환

### 4.3 몰

몰 주문이 실제 확정되는 단일 지점을 찾아 `MALL_OUT`을 연결한다.

- 장바구니 추가 시 차감하지 않는다.
- 주문 실패/취소 시 차감하지 않는다.
- 결제 또는 운영상 판매 확정 시 차감한다.
- 취소·환불은 `RETURN_IN`으로 복구한다.

### 4.4 점포 이동

대상: `admin/store_transfers.php`

- 출발점포 `TRANSFER_OUT`
- 도착점포 `TRANSFER_IN`
- 두 이벤트와 transfer status 변경을 단일 트랜잭션으로 처리
- 수정/삭제는 기존 이벤트를 삭제하지 않고 reverse delta 기록

### 4.5 도매/크레딧

- 도매 확정: `WHOLESALE_OUT`
- 도매 반품: `RETURN_IN`
- 크레딧 확정: `CREDIT_OUT`
- 수기 상품은 `product_id`가 없으므로 재고 제외
- 수정은 old quantity와 new quantity 차이만 적용

### 4.6 폐기

대상: `admin/expiry_disposal.php`, 기존 `lib/expiry_helper.php`

- 폐기 확정 시 `DISPOSAL_OUT`
- 기존 `register_disposal()`의 lot/전체 재고 갱신을 공통 서비스로 이전
- 폐기 번호를 source ID로 사용
- 사유와 담당자를 원장에 기록

## 5. Reference Store 시간 경계

Reference Store 조회 함수:

```php
reference_store_at($conn, DateTimeInterface $at): int
```

규칙:

- 과거 원장 이벤트의 점포를 변경하지 않는다.
- 원장 조회 시점의 기준 점포와 이벤트 발생 시점의 기준 점포를 구분한다.
- 실제 거래 모듈은 항상 거래 자체의 `store_id`를 사용한다.
- 몰 가격/품절 자동화만 유효한 Reference Store를 사용한다.
- 변경 직후 새 점포의 opening balance는 실사 조정으로 기록한다.

## 6. 관리자 UI 설계

### 6.1 Reference Store

기존 `mall/admin/products.php`:

- select
- 저장 버튼
- 현재 저장값 표시
- 마지막 변경 시각 표시
- 저장된 기준 점포와 임시 조회 점포를 구분

### 6.2 재고 현황

필드:

- 상품명/SKU
- 점포
- 현재 수량
- 음수 여부
- 최근 입고
- 최근 판매
- 최근 조정
- Reference Store 적용 상태

음수 수량은 빨간색으로 표시하지만 데이터는 수정하지 않는다.

### 6.3 원장

필터:

- 기간
- 점포
- SKU
- 이벤트 유형
- 원본 거래 번호
- 담당자

### 6.4 재고 조정

- 장부 수량 표시
- 실사 수량 입력
- 차이 자동 계산
- 사유 필수
- 관리자 권한 확인
- 저장 후 원장 상세 링크 제공

## 7. 보안 및 무결성

- 모든 변경 endpoint는 로그인/권한/CSRF 검증
- 모든 SQL은 prepared statement
- 거래 단위 DB transaction
- 수량 변경 전 row lock
- source ID 서버 재검증
- 다른 점포 ID 위조 방지
- 음수 허용과 권한 검증을 분리
- 실패 시 원장과 원본 거래 상태를 함께 rollback

## 8. 레거시 처리

`stock`, `StockRepository`, `StockService`는 즉시 삭제하지 않는다.

1. 실제 호출부 검색
2. 운영 데이터 존재 여부 확인
3. `inventory`와 수량 비교
4. 사용처가 없음을 확인한 뒤 deprecated 표시
5. 별도 후속 작업으로 제거

기존 `inventory_transactions`는 초기에는 보존하고, 신규 원장과 중복 기록하지 않도록 전환 기간의 source 정책을 정한다.

## 9. 테스트 전략

### 단위/DB 테스트

- +입고
- -출고
- 음수 저장
- 박스 환산
- FIFO lot 차감
- 중복 source skip
- 동시 업데이트 lock
- rollback

### 통합 테스트

- 매입확정→재고 증가→품절 해제
- POS 업로드→재고 감소
- 몰 판매/취소
- 이동 양쪽 수량 변화
- 도매 판매/반품
- 크레딧 판매
- 폐기 FIFO
- 실사 조정

### Reference Store 테스트

- 설정 저장
- history 생성
- 변경 전/후 거래 분리
- 과거 데이터 비소급
- 새 점포 opening adjustment

### UI 테스트

- 음수 표시
- 원장 필터
- 재고 조정 validation
- 권한 차단
- Reference Store 저장 후 재접속

## 10. bkit Check/Report 산출물

Check 문서에는 다음을 포함한다.

- Plan 항목별 구현 상태
- Design API/DB 계약 일치 여부
- 변경 파일 목록
- migration 결과
- 테스트 결과
- 미해결 위험
- 수동 검증 필요 항목

Report 문서에는 다음을 포함한다.

- 최종 기능 요약
- 운영 전 migration 순서
- rollback 방법
- 음수 재고 운영 가이드
- Reference Store 변경 운영 가이드
