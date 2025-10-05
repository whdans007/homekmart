# 올바른 API URL 목록

## 🌐 Base URL
```
https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api
```

## ✅ 테스트 완료

### 연결 테스트
```
https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/test_connection.php
```
**결과**: ✅ 성공 (사용자: 10, 상품: 23,646, 점포: 3)

---

## 🔍 브라우저에서 바로 테스트 가능 (인증 불필요)

### 상품 목록 조회
```
https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/products/index.php?store_id=1&page=1&limit=5
```

### 상품 상세 조회
```
https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/products/detail.php?product_id=1&store_id=1
```

### 배송 구역 조회
```
https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/delivery-zones/index.php?store_id=1
```

---

## 🔐 Postman/Thunder Client로 테스트 (POST 요청)

### 1. 회원가입
**URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/auth/register.php`
**Method**: POST
**Headers**:
```
Content-Type: application/json
```
**Body**:
```json
{
  "email": "test_delivery@example.com",
  "password": "password123",
  "full_name": "Test Delivery User",
  "phone": "+639171234567",
  "preferred_language": "en"
}
```

### 2. 로그인
**URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/auth/login.php`
**Method**: POST
**Headers**:
```
Content-Type: application/json
```
**Body**:
```json
{
  "email": "test_delivery@example.com",
  "password": "password123"
}
```

**응답에서 토큰 복사**: `data.token`

### 3. 프로필 조회 (토큰 필요)
**URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/auth/profile.php`
**Method**: GET
**Headers**:
```
Content-Type: application/json
Authorization: Bearer {여기에_토큰_붙여넣기}
```

### 4. 장바구니에 상품 추가 (토큰 필요)
**URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/cart/add.php`
**Method**: POST
**Headers**:
```
Content-Type: application/json
Authorization: Bearer {토큰}
```
**Body**:
```json
{
  "product_id": 1,
  "store_id": 1,
  "quantity": 2
}
```

### 5. 장바구니 조회 (토큰 필요)
**URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/cart/index.php`
**Method**: GET
**Headers**:
```
Content-Type: application/json
Authorization: Bearer {토큰}
```

### 6. 주문 생성 (토큰 필요)
먼저 주소를 추가해야 합니다.

**주소 추가 URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/addresses/create.php`
**Method**: POST
**Headers**:
```
Content-Type: application/json
Authorization: Bearer {토큰}
```
**Body**:
```json
{
  "address_name": "My Home",
  "house_number": "123",
  "street": "Main Street",
  "barangay": "Barangay 1",
  "city": "Manila",
  "province": "Metro Manila",
  "postal_code": "1000",
  "landmark": "Near SM Mall",
  "is_default": true
}
```

**주문 생성 URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/orders/create.php`
**Method**: POST
**Headers**:
```
Content-Type: application/json
Authorization: Bearer {토큰}
```
**Body**:
```json
{
  "delivery_address_id": 1,
  "payment_method": "cod",
  "notes": "Please deliver in the morning"
}
```

### 7. 주문 목록 조회 (토큰 필요)
**URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/orders/index.php`
**Method**: GET
**Headers**:
```
Content-Type: application/json
Authorization: Bearer {토큰}
```

### 8. 주문 상세 조회 (토큰 필요)
**URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/orders/detail.php?order_id=1`
**Method**: GET
**Headers**:
```
Content-Type: application/json
Authorization: Bearer {토큰}
```

---

## 📝 Flutter 앱 설정 업데이트

Flutter 앱의 API URL도 업데이트해야 합니다:

**파일**: `delivery_app_flutter/lib/core/constants/api_constants.dart`
```dart
class ApiConstants {
  static const String baseUrl = 'https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api';

  // ... 나머지 엔드포인트
}
```

---

## 🎯 테스트 순서 추천

1. ✅ 연결 테스트 (완료)
2. 🔍 상품 목록 조회 (브라우저)
3. 🔐 회원가입 → 로그인 (Postman)
4. 🛒 장바구니 추가 → 조회 (Postman)
5. 📦 주소 추가 → 주문 생성 → 주문 조회 (Postman)

모든 테스트가 성공하면 Flutter 앱 연동 시작!
