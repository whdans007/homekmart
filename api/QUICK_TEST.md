# 빠른 API 테스트 가이드

## ✅ 1. 연결 테스트 - 성공!
```json
{
    "success": true,
    "message": "API connection test successful",
    "data": {
        "database": "connected",
        "stats": {
            "users": 10,
            "products": 23646,
            "stores": 3
        }
    }
}
```

## 🔍 2. 다음 테스트할 API

### 브라우저에서 바로 테스트 가능한 API

#### 상품 목록 조회 (인증 불필요)
```
https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/products/index.php?store_id=1&page=1&limit=5
```

#### 배송 구역 조회 (인증 불필요)
```
https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/delivery-zones/index.php?store_id=1
```

### Postman/Thunder Client로 테스트

#### 회원가입
- **Method**: POST
- **URL**: `https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/auth/register.php`
- **Headers**: `Content-Type: application/json`
- **Body**:
```json
{
  "email": "delivery_test@example.com",
  "password": "test1234",
  "full_name": "Delivery Test User",
  "phone": "+639171234567",
  "preferred_language": "en"
}
```

**예상 응답**:
```json
{
  "success": true,
  "message": "Registration successful",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "id": 11,
      "email": "delivery_test@example.com",
      "full_name": "Delivery Test User",
      "role": "user"
    }
  }
}
```

#### 로그인
- **Method**: POST
- **URL**: `https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/auth/login.php`
- **Headers**: `Content-Type: application/json`
- **Body**:
```json
{
  "email": "delivery_test@example.com",
  "password": "test1234"
}
```

#### 프로필 조회 (토큰 필요)
- **Method**: GET
- **URL**: `https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api/auth/profile.php`
- **Headers**:
  - `Content-Type: application/json`
  - `Authorization: Bearer {위에서 받은 토큰}`

## 📊 테스트 체크리스트

- [x] ✅ 데이터베이스 연결
- [ ] 상품 목록 조회
- [ ] 회원가입
- [ ] 로그인
- [ ] 프로필 조회
- [ ] 장바구니 추가
- [ ] 주문 생성

## 🔗 전체 URL 목록

모든 API 엔드포인트는 다음 Base URL을 사용합니다:
```
https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api
```

### 인증 불필요 (브라우저 테스트 가능)
- `GET /test_connection.php` ✅ 완료
- `GET /products/index.php?store_id=1`
- `GET /delivery-zones/index.php?store_id=1`

### 인증 필요 (Postman/Thunder Client)
- `POST /auth/register.php`
- `POST /auth/login.php`
- `GET /auth/profile.php` (토큰 필요)
- `GET /cart/index.php` (토큰 필요)
- `POST /cart/add.php` (토큰 필요)
- `POST /orders/create.php` (토큰 필요)
