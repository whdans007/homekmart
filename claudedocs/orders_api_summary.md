# 주문 API 개발 요약

## 📅 작업일: 2025-10-04

## ✅ 완료된 작업

### 1. 주문 관련 테이블 확인
- ✅ `delivery_orders`: 주문 정보 (24개 컬럼, 0개 행)
- ✅ `delivery_order_items`: 주문 상품 (9개 컬럼, 0개 행)
- ✅ `delivery_addresses`: 배달 주소 (18개 컬럼, 1개 행 생성됨)
- ✅ `delivery_zones`: 배달 지역 (15개 컬럼, 30개 행)
- ✅ `delivery_tracking`: 배달 추적 (9개 컬럼, 0개 행)

### 2. 개발된 API 엔드포인트 (MySQLi 버전, 인증 없음)

#### 2.1 주문 생성 API
- **파일**: `z:\homekmart\api\orders\create.php`
- **메서드**: POST
- **URL**: `/api/orders/create.php`
- **요청 파라미터**:
  ```json
  {
    "user_id": 13,
    "delivery_address_id": 1,
    "payment_method": "cod",
    "cod_amount": 100,
    "special_instructions": "Please ring the doorbell"
  }
  ```
- **기능**:
  - 사용자 점포 확인
  - 배달 주소 검증
  - 배달 지역 및 배달비 계산
  - 장바구니 아이템 조회 및 재고 검증
  - 주문 번호 자동 생성 (ORD + YYYYMMDD + 6자리 해시)
  - 주문 및 주문 아이템 생성
  - 배달 추적 레코드 생성 (order_placed)
  - 재고 자동 차감
  - 장바구니 비우기
- **트랜잭션**: autocommit(false) → commit/rollback

#### 2.2 주문 목록 조회 API
- **파일**: `z:\homekmart\api\orders\list.php`
- **메서드**: GET
- **URL**: `/api/orders/list.php?user_id=13&status=pending`
- **쿼리 파라미터**:
  - `user_id`: 사용자 ID (필수)
  - `status`: 주문 상태 (선택) - pending, confirmed, preparing, ready_for_delivery, out_for_delivery, delivered, cancelled
- **응답**: 주문 배열 + 개수

#### 2.3 주문 상세 조회 API
- **파일**: `z:\homekmart\api\orders\detail.php`
- **메서드**: GET
- **URL**: `/api/orders/detail.php?order_id=1&user_id=13`
- **응답**: 주문 정보 + 주문 아이템 + 배달 주소 + 추적 기록

#### 2.4 주문 취소 API
- **파일**: `z:\homekmart\api\orders\cancel.php`
- **메서드**: POST
- **URL**: `/api/orders/cancel.php`
- **요청 파라미터**:
  ```json
  {
    "order_id": 1,
    "user_id": 13,
    "cancellation_reason": "Changed my mind"
  }
  ```
- **기능**:
  - 취소 가능 상태 확인 (pending, confirmed, preparing만 가능)
  - 재고 복구
  - 주문 상태 'cancelled'로 변경
  - 추적 레코드 추가

#### 2.5 주문 추적 API
- **파일**: `z:\homekmart\api\orders\tracking.php`
- **메서드**: GET
- **URL**: `/api/orders/tracking.php?order_id=1&user_id=13`
- **응답**: 현재 주문 상태 + 추적 히스토리 (시간순)

### 3. 테스트 파일
- ✅ `test_orders.html`: 브라우저 기반 주문 API 테스트 인터페이스
- ✅ `create_test_delivery_address.php`: 테스트용 배달 주소 생성 스크립트
- ✅ `test_create_order.php`: CURL 기반 주문 생성 테스트

### 4. 생성된 테스트 데이터
- ✅ 배달 주소 ID 1 (Makati, Metro Manila)
- ✅ 장바구니: User 13, Product 5 (Sprite), Quantity 2

## ⚠️ 현재 이슈

### 주문 생성 API 400 에러
- **증상**: POST 요청 시 Synology NAS 400 에러 페이지 반환
- **원인 (추정)**:
  - PHP 스크립트 실행 시간 초과 가능성
  - 복잡한 트랜잭션 로직으로 인한 성능 이슈
  - display_errors 설정과 상관없이 HTML 에러 페이지 반환
- **다음 단계**:
  - 스크립트 간소화 및 성능 최적화
  - 에러 로그 확인
  - 단계별 디버깅 (주문 생성만 테스트)

## 📊 API 개발 현황

| API | 상태 | 테스트 | 비고 |
|-----|------|--------|------|
| 주문 생성 | ✅ 완료 | ⚠️ 400 에러 | 디버깅 필요 |
| 주문 목록 | ✅ 완료 | ⏳ 대기 | 주문 생성 후 테스트 가능 |
| 주문 상세 | ✅ 완료 | ⏳ 대기 | 주문 생성 후 테스트 가능 |
| 주문 취소 | ✅ 완료 | ⏳ 대기 | 주문 생성 후 테스트 가능 |
| 주문 추적 | ✅ 완료 | ⏳ 대기 | 주문 생성 후 테스트 가능 |

## 🔗 테스트 URL

```
기본 URL: https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api

주문 생성: POST /orders/create.php
주문 목록: GET /orders/list.php?user_id=13
주문 상세: GET /orders/detail.php?order_id=1&user_id=13
주문 취소: POST /orders/cancel.php
주문 추적: GET /orders/tracking.php?order_id=1&user_id=13

테스트 인터페이스: /api/test_orders.html
```

## 📝 주요 비즈니스 로직

### 배달비 계산
- 기본 배달비: ₱50
- 무료 배달 기준: 주문 금액 ≥ ₱1,000
- 배달 지역별 차등 적용 가능

### 주문 번호 생성
- 형식: `ORD + YYYYMMDD + 6자리 해시`
- 예시: `ORD20251004A3F7B2`

### 주문 상태 흐름
```
pending → confirmed → preparing → ready_for_delivery
→ out_for_delivery → delivered
                   ↘ cancelled (pending/confirmed/preparing에서만 가능)
```

### 배달 추적 상태
```
order_placed → order_confirmed → preparing → ready_for_pickup
→ picked_up → out_for_delivery → delivered
            ↘ failed_delivery
            ↘ cancelled
```

## 📁 파일 구조

```
z:\homekmart\api\
├── orders/
│   ├── create.php          (주문 생성)
│   ├── list.php            (주문 목록)
│   ├── detail.php          (주문 상세)
│   ├── cancel.php          (주문 취소)
│   └── tracking.php        (주문 추적)
├── test_orders.html
├── create_test_delivery_address.php
└── test_create_order.php
```

## 🔄 다음 작업 예정

1. **주문 생성 API 디버깅**
   - 400 에러 원인 파악
   - 스크립트 성능 최적화
   - 단계별 테스트

2. **전체 API 통합 테스트**
   - 주문 생성 성공 후 전체 흐름 테스트
   - 각 상태별 시나리오 검증

3. **배달 주소 API 개발**
   - 주소 등록/수정/삭제
   - 기본 주소 설정
   - GPS 좌표 관리

4. **Flutter 앱 통합**
   - API 연동
   - UI 구현
   - 상태 관리

## 📄 관련 문서
- [장바구니 API 요약](cart_api_summary.md)
- [데이터베이스 스키마](../sql/delivery_app_schema.sql)
