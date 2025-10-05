# HOME K MART Delivery API - 테스트 가이드

## 📋 API 테스트 개요

### 서버 정보
- **Base URL**: `https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api`
- **Local URL**: `http://localhost/homekmart/api`
- **Database**: MariaDB 10 (u622428657_homekmart)

## 🔍 1. 연결 테스트

### 데이터베이스 연결 확인
```bash
# 브라우저에서
https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/test_connection.php

# 또는 curl
curl https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/test_connection.php
```

**예상 응답:**
```json
{
  "success": true,
  "message": "API connection test successful",
  "data": {
    "database": "connected",
    "stats": {
      "users": 5,
      "products": 100,
      "stores": 2
    },
    "timestamp": "2025-10-04 10:00:00"
  }
}
```

## 🔐 2. 인증 API 테스트

### 2.1 회원가입 (Register)
```bash
curl -X POST https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "password123",
    "full_name": "Test User",
    "phone": "+639123456789",
    "preferred_language": "en"
  }'
```

**예상 응답:**
```json
{
  "success": true,
  "message": "Registration successful",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "id": 10,
      "username": "testuser",
      "email": "test@example.com",
      "full_name": "Test User",
      "phone": "+639123456789",
      "role": "user",
      "preferred_language": "en",
      "created_at": "2025-10-04 10:00:00"
    }
  }
}
```

### 2.2 로그인 (Login)
```bash
curl -X POST https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "password123"
  }'
```

### 2.3 프로필 조회 (Profile)
```bash
# 먼저 로그인하여 토큰 받기
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGc..."

curl -X GET https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/auth/profile \
  -H "Authorization: Bearer $TOKEN"
```

## 📦 3. 상품 API 테스트

### 3.1 상품 목록 조회
```bash
curl -X GET "https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/products?store_id=1&page=1&limit=10"
```

**예상 응답:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "name": "Product Name",
        "barcode": "1234567890123",
        "description": "Product description",
        "category_id": 1,
        "category_name": "Electronics",
        "brand_id": 1,
        "brand_name": "Samsung",
        "selling_price": 25000.00,
        "cost_price": 20000.00,
        "quantity": 50,
        "image_url": null,
        "is_active": true
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 10,
      "total_items": 100,
      "items_per_page": 10,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

### 3.2 상품 검색
```bash
curl -X GET "https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/products?store_id=1&search=samsung"
```

### 3.3 카테고리별 상품
```bash
curl -X GET "https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/products?store_id=1&category_id=1"
```

## 🛒 4. 장바구니 API 테스트

### 4.1 장바구니 조회
```bash
curl -X GET https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/cart \
  -H "Authorization: Bearer $TOKEN"
```

### 4.2 장바구니에 상품 추가
```bash
curl -X POST https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/cart/add \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": 1,
    "store_id": 1,
    "quantity": 2
  }'
```

### 4.3 장바구니 수량 변경
```bash
curl -X PUT https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/cart/update \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "cart_id": 1,
    "quantity": 3
  }'
```

### 4.4 장바구니 아이템 삭제
```bash
curl -X DELETE https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/cart/delete \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "cart_id": 1
  }'
```

## 📋 5. 주문 API 테스트

### 5.1 주문 생성
```bash
curl -X POST https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/orders/create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "delivery_address_id": 1,
    "payment_method": "cod",
    "notes": "Please deliver in the morning"
  }'
```

### 5.2 주문 목록 조회
```bash
# 전체 주문
curl -X GET https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/orders \
  -H "Authorization: Bearer $TOKEN"

# 특정 상태 주문
curl -X GET "https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/orders?status=pending" \
  -H "Authorization: Bearer $TOKEN"
```

### 5.3 주문 상세 조회
```bash
curl -X GET https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/orders/detail?order_id=1 \
  -H "Authorization: Bearer $TOKEN"
```

### 5.4 주문 추적
```bash
curl -X GET https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/orders/tracking?order_id=1 \
  -H "Authorization: Bearer $TOKEN"
```

### 5.5 주문 취소
```bash
curl -X POST https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/orders/cancel \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1
  }'
```

## 📍 6. 주소 API 테스트

### 6.1 주소 목록 조회
```bash
curl -X GET https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/addresses \
  -H "Authorization: Bearer $TOKEN"
```

### 6.2 주소 추가
```bash
curl -X POST https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/addresses/create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address_name": "Home",
    "house_number": "123",
    "street": "Main Street",
    "barangay": "Barangay 1",
    "city": "Manila",
    "province": "Metro Manila",
    "postal_code": "1000",
    "landmark": "Near SM Mall",
    "latitude": 14.5995,
    "longitude": 120.9842,
    "is_default": true
  }'
```

## ✅ 테스트 체크리스트

### 필수 테스트
- [ ] 데이터베이스 연결 확인
- [ ] 회원가입 성공
- [ ] 로그인 성공 및 JWT 발급
- [ ] 인증된 사용자 프로필 조회
- [ ] 상품 목록 조회 (페이지네이션)
- [ ] 장바구니 CRUD 작업
- [ ] 주문 생성 및 조회
- [ ] 주문 추적 정보 조회

### 에러 케이스 테스트
- [ ] 잘못된 인증 정보로 로그인
- [ ] 토큰 없이 보호된 엔드포인트 접근
- [ ] 만료된 토큰으로 접근
- [ ] 필수 파라미터 누락
- [ ] 존재하지 않는 리소스 조회
- [ ] 재고 부족 상품 주문

## 🔧 문제 해결

### CORS 에러
config.php에서 CORS 헤더가 올바르게 설정되어 있는지 확인

### 401 Unauthorized
- JWT 토큰이 올바른지 확인
- Authorization 헤더 형식: `Bearer {token}`

### 500 Internal Server Error
- PHP 에러 로그 확인: `/var/log/php/error.log`
- 데이터베이스 연결 설정 확인

## 📝 다음 단계

1. ✅ API 연결 테스트
2. ✅ 인증 플로우 테스트
3. ✅ 상품 조회 테스트
4. ⏳ Flutter 앱과 통합 테스트
5. ⏳ 실제 디바이스에서 테스트
