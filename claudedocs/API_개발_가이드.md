# 배달 앱 API 개발 가이드

**작성일**: 2025-10-03
**버전**: 1.0
**Base URL**: `https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api`

---

## 📋 목차

1. [개요](#개요)
2. [인증 시스템](#인증-시스템)
3. [API 엔드포인트](#api-엔드포인트)
4. [에러 처리](#에러-처리)
5. [다음 단계](#다음-단계)

---

## 개요

### 기술 스택
- **Backend**: PHP 8.2 + PDO
- **인증**: JWT (JSON Web Token)
- **응답 형식**: JSON
- **CORS**: 모든 도메인 허용 (개발용)

### 공통 헤더
```
Content-Type: application/json
Authorization: Bearer {JWT_TOKEN}  # 인증 필요 시
```

### 응답 형식

#### 성공 응답
```json
{
  "success": true,
  "message": "Operation successful",
  "data": { ... }
}
```

#### 에러 응답
```json
{
  "success": false,
  "error": {
    "message": "Error message",
    "code": 400,
    "details": [ ... ]  // 선택사항
  }
}
```

---

## 인증 시스템

### 1. 회원가입
**POST** `/api/auth/register`

#### 요청
```json
{
  "email": "user@example.com",
  "password": "password123",
  "full_name": "Juan Dela Cruz",
  "phone": "+63-917-123-4567",
  "username": "juandc",  // 선택
  "preferred_language": "en"  // 선택, 기본값: en
}
```

#### 응답
```json
{
  "success": true,
  "message": "Registration successful",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhb...",
    "user": {
      "id": 1,
      "username": "juandc",
      "email": "user@example.com",
      "full_name": "Juan Dela Cruz",
      "phone": "+63-917-123-4567",
      "role": "user",
      "preferred_language": "en",
      "created_at": "2025-10-03 10:00:00"
    }
  }
}
```

### 2. 로그인
**POST** `/api/auth/login`

#### 요청
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

#### 응답
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhb...",
    "user": {
      "id": 1,
      "username": "juandc",
      "email": "user@example.com",
      "full_name": "Juan Dela Cruz",
      "role": "user",
      "is_delivery_available": true
    }
  }
}
```

### 3. 프로필 조회
**GET** `/api/auth/profile`
**인증**: 필수

#### 응답
```json
{
  "success": true,
  "data": {
    "id": 1,
    "username": "juandc",
    "email": "user@example.com",
    "full_name": "Juan Dela Cruz",
    "phone": "+63-917-123-4567",
    "role": "user",
    "preferred_language": "en",
    "phone_verified": false,
    "default_delivery_address_id": 3,
    "default_address_name": "집",
    "default_city": "Angeles City"
  }
}
```

---

## API 엔드포인트

### 상품 API

#### 1. 상품 목록 조회
**GET** `/api/products?store_id={store_id}&page={page}&limit={limit}`

**쿼리 파라미터**:
- `store_id` (필수): 점포 ID
- `page` (선택): 페이지 번호, 기본값 1
- `limit` (선택): 페이지당 항목 수, 기본값 20, 최대 100
- `category_id` (선택): 카테고리 ID
- `search` (선택): 검색어 (상품명, 바코드, SKU)

#### 응답
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "name": "Lucky Me Pancit Canton",
        "barcode": "8851234567890",
        "sku": "LM-PC-001",
        "description": "Instant noodles",
        "category_id": 5,
        "category_name": "Instant Food",
        "brand_id": 2,
        "brand_name": "Lucky Me",
        "selling_price": 12.50,
        "cost_price": 10.00,
        "quantity": 100,
        "image_url": null,
        "is_active": true
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 5,
      "total_items": 95,
      "items_per_page": 20,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

#### 2. 상품 상세 조회
**GET** `/api/products/detail?id={product_id}&store_id={store_id}`

**쿼리 파라미터**:
- `id` (필수): 상품 ID
- `store_id` (필수): 점포 ID

#### 응답
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Lucky Me Pancit Canton",
    "barcode": "8851234567890",
    "sku": "LM-PC-001",
    "description": "Instant noodles with special sauce",
    "category_id": 5,
    "category_name": "Instant Food",
    "brand_id": 2,
    "brand_name": "Lucky Me",
    "supplier_id": 3,
    "supplier_name": "Manila Distributors",
    "selling_price": 12.50,
    "cost_price": 10.00,
    "quantity": 100,
    "image_url": null,
    "is_active": true,
    "created_at": "2025-01-01 10:00:00",
    "updated_at": "2025-10-03 09:00:00"
  }
}
```

---

## 에러 처리

### HTTP 상태 코드

| 코드 | 의미 | 설명 |
|------|------|------|
| 200 | OK | 성공 |
| 400 | Bad Request | 잘못된 요청 (파라미터 누락, 유효성 오류) |
| 401 | Unauthorized | 인증 실패 (토큰 없음, 만료, 유효하지 않음) |
| 403 | Forbidden | 권한 없음 |
| 404 | Not Found | 리소스를 찾을 수 없음 |
| 405 | Method Not Allowed | 허용되지 않은 HTTP 메서드 |
| 409 | Conflict | 리소스 충돌 (이메일 중복 등) |
| 500 | Internal Server Error | 서버 오류 |

### 에러 예시

#### 1. 인증 실패 (401)
```json
{
  "success": false,
  "error": {
    "message": "Invalid or expired token",
    "code": 401
  }
}
```

#### 2. 유효성 오류 (400)
```json
{
  "success": false,
  "error": {
    "message": "Validation failed",
    "code": 400,
    "details": [
      "Field 'email' is required",
      "Field 'password' is required"
    ]
  }
}
```

#### 3. 리소스 없음 (404)
```json
{
  "success": false,
  "error": {
    "message": "Product not found",
    "code": 404
  }
}
```

### 주소 관리 API

#### 1. 주소 목록 조회
**GET** `/api/addresses`
**인증**: 필수

#### 응답
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "address_name": "집",
      "house_number": "123",
      "street": "Main Street",
      "barangay": "Balibago",
      "city": "Angeles City",
      "province": "Pampanga",
      "postal_code": "2009",
      "detailed_address": "Yellow gate",
      "landmark": "Near SM Clark",
      "latitude": 15.1450,
      "longitude": 120.5900,
      "is_default": true,
      "is_active": true
    }
  ]
}
```

#### 2. 주소 추가
**POST** `/api/addresses`
**인증**: 필수

#### 요청
```json
{
  "address_name": "집",
  "house_number": "123",
  "street": "Main Street",
  "barangay": "Balibago",
  "city": "Angeles City",
  "province": "Pampanga",
  "postal_code": "2009",
  "detailed_address": "Yellow gate",
  "landmark": "Near SM Clark",
  "latitude": 15.1450,
  "longitude": 120.5900,
  "is_default": true
}
```

#### 3. 주소 수정
**PUT** `/api/addresses?id={address_id}`
**인증**: 필수

#### 4. 주소 삭제 (비활성화)
**DELETE** `/api/addresses?id={address_id}`
**인증**: 필수

#### 5. 기본 주소 설정
**POST** `/api/addresses/set-default?id={address_id}`
**인증**: 필수

---

### 장바구니 API

#### 1. 장바구니 조회
**GET** `/api/cart`
**인증**: 필수

#### 응답
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "cart_id": 1,
        "product_id": 5,
        "product_name": "Lucky Me Pancit Canton",
        "barcode": "8851234567890",
        "unit_price": 12.50,
        "quantity": 3,
        "subtotal": 37.50,
        "stock_quantity": 100
      }
    ],
    "summary": {
      "total_items": 3,
      "total_quantity": 5,
      "subtotal": 87.50
    }
  }
}
```

#### 2. 장바구니 추가
**POST** `/api/cart`
**인증**: 필수

#### 요청
```json
{
  "product_id": 5,
  "store_id": 1,
  "quantity": 2
}
```

#### 3. 수량 변경
**PUT** `/api/cart?id={cart_id}`
**인증**: 필수

#### 요청
```json
{
  "quantity": 5
}
```

#### 4. 항목 삭제
**DELETE** `/api/cart?id={cart_id}`
**인증**: 필수

---

### 주문 API

#### 1. 주문 생성
**POST** `/api/orders`
**인증**: 필수

#### 요청
```json
{
  "delivery_address_id": 1,
  "payment_method": "cod",
  "delivery_notes": "문 앞에 놔주세요",
  "use_points": 0
}
```

**payment_method** 옵션:
- `cod` - Cash on Delivery
- `gcash` - GCash
- `paymaya` - PayMaya

#### 응답
```json
{
  "success": true,
  "message": "Order created successfully",
  "data": {
    "order_id": 1,
    "order_number": "DO-20251003-12345",
    "subtotal": 500.00,
    "delivery_fee": 50.00,
    "points_used": 0,
    "total_amount": 550.00,
    "payment_method": "cod",
    "order_status": "pending"
  }
}
```

#### 2. 주문 목록 조회
**GET** `/api/orders?status={status}&page={page}&limit={limit}`
**인증**: 필수

**쿼리 파라미터**:
- `status` (선택): 주문 상태 필터
  - `pending` - 대기중
  - `confirmed` - 확인됨
  - `preparing` - 준비중
  - `out_for_delivery` - 배송중
  - `delivered` - 배송완료
  - `cancelled` - 취소됨

#### 응답
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "order_number": "DO-20251003-12345",
        "subtotal": 500.00,
        "delivery_fee": 50.00,
        "points_used": 0,
        "total_amount": 550.00,
        "payment_method": "cod",
        "payment_status": "pending",
        "order_status": "pending",
        "delivery_notes": "",
        "created_at": "2025-10-03 10:30:00",
        "address_name": "집",
        "city": "Angeles City",
        "province": "Pampanga",
        "item_count": 3
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 1,
      "total_items": 5,
      "items_per_page": 20,
      "has_next": false,
      "has_prev": false
    }
  }
}
```

#### 3. 주문 상세 조회
**GET** `/api/orders/detail?id={order_id}`
**인증**: 필수

#### 응답
```json
{
  "success": true,
  "data": {
    "id": 1,
    "order_number": "DO-20251003-12345",
    "user_id": 5,
    "store_id": 1,
    "delivery_address_id": 1,
    "subtotal": 500.00,
    "delivery_fee": 50.00,
    "points_used": 0,
    "total_amount": 550.00,
    "payment_method": "cod",
    "payment_status": "pending",
    "order_status": "confirmed",
    "delivery_notes": "문 앞에 놔주세요",
    "address_name": "집",
    "house_number": "123",
    "street": "Main Street",
    "barangay": "Balibago",
    "city": "Angeles City",
    "province": "Pampanga",
    "postal_code": "2009",
    "detailed_address": "Yellow gate",
    "landmark": "Near SM Clark",
    "latitude": 15.1450,
    "longitude": 120.5900,
    "items": [
      {
        "id": 1,
        "product_id": 5,
        "product_name": "Lucky Me Pancit Canton",
        "barcode": "8851234567890",
        "quantity": 3,
        "unit_price": 12.50,
        "subtotal": 37.50
      }
    ],
    "tracking": [
      {
        "id": 2,
        "status": "confirmed",
        "notes": "Order confirmed",
        "created_at": "2025-10-03 10:35:00",
        "updated_by_name": "Admin User"
      },
      {
        "id": 1,
        "status": "pending",
        "notes": "Order placed",
        "created_at": "2025-10-03 10:30:00",
        "updated_by_name": null
      }
    ]
  }
}
```

#### 4. 주문 취소
**POST** `/api/orders/cancel?id={order_id}`
**인증**: 필수

**취소 가능 상태**: `pending`, `confirmed`, `preparing`

#### 요청
```json
{
  "cancel_reason": "고객 변심"
}
```

#### 응답
```json
{
  "success": true,
  "message": "Order cancelled successfully",
  "data": {
    "order_id": 1,
    "order_status": "cancelled",
    "cancel_reason": "고객 변심"
  }
}
```

#### 5. 배송 추적
**GET** `/api/orders/tracking?id={order_id}`
**인증**: 필수

#### 응답
```json
{
  "success": true,
  "data": {
    "order_id": 1,
    "order_number": "DO-20251003-12345",
    "current_status": "out_for_delivery",
    "tracking_history": [
      {
        "id": 4,
        "status": "out_for_delivery",
        "notes": "Out for delivery - Rider John",
        "location": null,
        "created_at": "2025-10-03 11:00:00",
        "updated_by_name": "Admin User"
      },
      {
        "id": 3,
        "status": "preparing",
        "notes": "Order is being prepared",
        "location": null,
        "created_at": "2025-10-03 10:40:00",
        "updated_by_name": "Admin User"
      },
      {
        "id": 2,
        "status": "confirmed",
        "notes": "Order confirmed",
        "location": null,
        "created_at": "2025-10-03 10:35:00",
        "updated_by_name": "Admin User"
      },
      {
        "id": 1,
        "status": "pending",
        "notes": "Order placed",
        "location": null,
        "created_at": "2025-10-03 10:30:00",
        "updated_by_name": null
      }
    ]
  }
}
```

---

### 배송 구역 API

#### 1. 배송 가능 구역 조회
**GET** `/api/delivery-zones?city={city}&province={province}`

**쿼리 파라미터**:
- `city` (선택): 도시명 (부분 검색)
- `province` (선택): 주 이름 (부분 검색)

#### 응답
```json
{
  "success": true,
  "data": {
    "zones": [
      {
        "id": 1,
        "zone_name": "Angeles City Center",
        "barangay": null,
        "city": "Angeles City",
        "province": "Pampanga",
        "delivery_fee": 50.00,
        "min_order_amount": 200.00,
        "free_delivery_threshold": 1000.00,
        "estimated_delivery_time": 30,
        "max_delivery_time": 60,
        "service_start_time": "08:00:00",
        "service_end_time": "22:00:00"
      }
    ],
    "total": 3
  }
}
```

#### 2. 배송 가능 여부 확인
**POST** `/api/delivery-zones/check`

#### 요청
```json
{
  "city": "Angeles City",
  "province": "Pampanga",
  "barangay": "Balibago"
}
```

#### 응답
```json
{
  "success": true,
  "data": {
    "is_deliverable": true,
    "is_service_time": true,
    "zone": {
      "id": 1,
      "zone_name": "Angeles City Center",
      "barangay": null,
      "city": "Angeles City",
      "province": "Pampanga",
      "delivery_fee": 50.00,
      "min_order_amount": 200.00,
      "free_delivery_threshold": 1000.00,
      "estimated_delivery_time": 30,
      "max_delivery_time": 60,
      "service_start_time": "08:00:00",
      "service_end_time": "22:00:00"
    },
    "message": "Delivery is available now"
  }
}
```

#### 3. 배송비 계산
**POST** `/api/delivery-zones/calculate-fee`

#### 요청
```json
{
  "city": "Angeles City",
  "province": "Pampanga",
  "barangay": "Balibago",
  "order_amount": 1500.00
}
```

#### 응답 (무료 배송 적용)
```json
{
  "success": true,
  "data": {
    "is_valid": true,
    "zone_name": "Angeles City Center",
    "order_amount": 1500.00,
    "delivery_fee": 0.00,
    "is_free_delivery": true,
    "free_delivery_threshold": 1000.00,
    "total_amount": 1500.00,
    "message": "Free delivery applied!"
  }
}
```

#### 응답 (배송비 부과)
```json
{
  "success": true,
  "data": {
    "is_valid": true,
    "zone_name": "Angeles City Center",
    "order_amount": 500.00,
    "delivery_fee": 50.00,
    "is_free_delivery": false,
    "free_delivery_threshold": 1000.00,
    "total_amount": 550.00,
    "message": "Delivery fee applied"
  }
}
```

#### 응답 (최소 주문 금액 미달)
```json
{
  "success": true,
  "data": {
    "is_valid": false,
    "zone_name": "Angeles City Center",
    "order_amount": 150.00,
    "min_order_amount": 200.00,
    "delivery_fee": 50.00,
    "message": "Minimum order amount is PHP 200.00"
  }
}
```

---

## API 개발 완료 현황

✅ **인증 API** (3개)
- 회원가입
- 로그인
- 프로필 조회

✅ **상품 API** (2개)
- 상품 목록 조회
- 상품 상세 조회

✅ **주소 관리 API** (5개)
- 주소 목록 조회
- 주소 추가
- 주소 수정
- 주소 삭제
- 기본 주소 설정

✅ **장바구니 API** (4개)
- 장바구니 조회
- 장바구니 추가
- 수량 변경
- 항목 삭제

✅ **주문 API** (5개)
- 주문 생성
- 주문 목록 조회
- 주문 상세 조회
- 주문 취소
- 배송 추적

✅ **배송 구역 API** (3개)
- 배송 가능 구역 조회
- 배송 가능 여부 확인
- 배송비 계산

**총 22개 API 엔드포인트 개발 완료**

---

## 다음 단계

### 4단계: 모바일 앱 개발
- Flutter/React Native를 사용한 모바일 앱 개발
- UI/UX 디자인
- API 연동
- 푸시 알림 설정

---

## 테스트 방법

### Postman 사용

#### 1. 회원가입
```
POST https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/auth/register
Content-Type: application/json

{
  "email": "test@example.com",
  "password": "test123",
  "full_name": "Test User",
  "phone": "+63-917-123-4567"
}
```

#### 2. 로그인 후 토큰 저장
```
POST https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/auth/login
Content-Type: application/json

{
  "email": "test@example.com",
  "password": "test123"
}
```

응답에서 `data.token` 값을 복사하여 다음 요청에 사용

#### 3. 인증이 필요한 API 호출
```
GET https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/auth/profile
Authorization: Bearer {복사한_토큰}
```

#### 4. 상품 목록 조회
```
GET https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/products?store_id=1&page=1&limit=10
```

---

## 보안 고려사항

### 현재 구현
- ✅ JWT 기반 인증
- ✅ 비밀번호 해싱 (bcrypt)
- ✅ SQL Injection 방지 (Prepared Statements)
- ✅ CORS 설정

### 프로덕션 배포 시 추가 필요
- [ ] JWT Secret Key를 환경변수로 관리
- [ ] HTTPS 강제
- [ ] Rate Limiting (API 호출 제한)
- [ ] CORS 도메인 제한
- [ ] 입력값 추가 검증 및 Sanitization
- [ ] API Key 인증 추가 (서버간 통신)

---

**다음 문서**: 모바일 앱 개발 가이드 (예정)
