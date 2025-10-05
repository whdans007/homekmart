# HOME K MART Delivery API 테스트 결과

## 작업 일시
2025-01-XX

## 완료된 작업

### 1. 데이터베이스 테이블 구조 확인
- **products 테이블**: `name_ko`, `name_en` 컬럼 사용 (name 컬럼 없음)
- **categories 테이블**: `name` (한국어), `name_en` 컬럼 사용
- **brands 테이블**: `name_ko`, `name_en` 컬럼 사용
- **inventory 테이블**: 23,652개 레코드, store_id=1에 23,646개 상품

### 2. Products API 수정 완료
#### 수정 파일
- `api/products/index.php` - 상품 목록 조회 (페이지네이션)
- `api/products/list.php` - 동일 기능 (백업용)
- `api/products/detail.php` - 상품 상세 조회
- `api/products/test.php` - 최소 기능 테스트

#### 주요 수정 사항
```php
// 수정 전 (에러)
p.name
c.name_ko as category_name
b.name as brand_name

// 수정 후 (정상)
p.name_ko, p.name_en
c.name as category_name
b.name_ko as brand_name
```

### 3. API 테스트 결과

#### ✅ 성공한 엔드포인트
```
GET /api/test_connection.php
- 응답: 10 users, 23,646 products, 3 stores

GET /api/test_inventory.php
- 응답: 23,652개 inventory 레코드 확인

GET /api/products/test.php
- 응답: 5개 상품 정상 반환

GET /api/products/list.php?store_id=1&page=1&limit=5
- 응답: 페이지네이션 포함 5개 상품

GET /api/products/index.php?store_id=1&page=1&limit=3
- 응답: 페이지네이션 포함 3개 상품
```

#### 샘플 응답 (products/index.php)
```json
{
    "success": true,
    "data": [
        {
            "id": "23196",
            "name_ko": "",
            "name_en": "SEASONED AND ROASTED LAVER",
            "sku": "8809411162473",
            "description": null,
            "category_id": null,
            "category_name": null,
            "brand_id": null,
            "brand_name": null,
            "selling_price": 108,
            "cost_price": 108,
            "quantity": 40,
            "image_url": null,
            "is_active": true
        }
    ],
    "pagination": {
        "current_page": 1,
        "total_pages": 7882,
        "total_items": 23646,
        "items_per_page": 3,
        "has_next": true,
        "has_prev": false
    }
}
```

### 4. Flutter 앱 수정 완료

#### ProductModel 수정
```dart
// 수정 전
final String name;

// 수정 후
@JsonKey(name: 'name_ko')
final String? nameKo;
@JsonKey(name: 'name_en')
final String? nameEn;

// Getter 추가
String get name => nameKo?.isNotEmpty == true ? nameKo! : (nameEn ?? 'No name');
```

#### ProductProvider 수정
```dart
// 수정 전
_products = await _productService.getProducts(...);

// 수정 후
final response = await _productService.getProducts(...);
_products = response.data;
```

### 5. 디버깅 과정에서 발견한 문제들

#### 문제 1: Unknown column 'p.name'
**원인**: products 테이블에 `name` 컬럼 없음
**해결**: `name_ko`, `name_en` 사용

#### 문제 2: Unknown column 'c.name_ko'
**원인**: categories 테이블에 `name_ko` 컬럼 없음 (name만 존재)
**해결**: `c.name` 사용

#### 문제 3: Unknown column 'b.name'
**원인**: brands 테이블에 `name` 컬럼 없음
**해결**: `b.name_ko` 사용

#### 문제 4: SQL syntax error with LIMIT/OFFSET
**원인**: PDO prepared statement에서 LIMIT/OFFSET를 문자열로 바인딩
**해결**: MySQLi로 변경하여 직접 정수 삽입

### 6. 남은 작업

#### API 개발 (미완성)
- [ ] 인증 API 테스트 (register, login)
- [ ] 장바구니 API 테스트
- [ ] 주문 생성 API 테스트
- [ ] 주문 조회 API 테스트
- [ ] 주문 추적 API 테스트

#### Flutter 앱 (49개 에러)
- [ ] CartProvider 메서드 누락 (updateCartItem, deleteCartItem)
- [ ] 모델 getter 누락 (formattedPrice, formattedOrderDate 등)
- [ ] OrderProvider 타입 불일치
- [ ] ProductService 생성자 불일치
- [ ] deprecated 경고 수정 (withOpacity → withValues)

## 다음 단계

1. **인증 API 테스트** - 회원가입, 로그인 엔드포인트 확인
2. **Flutter 컴파일 에러 수정** - 49개 에러 해결
3. **통합 테스트** - Flutter 앱에서 실제 API 호출 테스트
4. **배달 기능 테스트** - 주소, 주문, 배달 추적 전체 플로우 확인

## API URL
```
Base URL: https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api
Products: GET /products/index.php?store_id=1&page=1&limit=20
Detail: GET /products/detail.php?id={product_id}&store_id={store_id}
```

## 참고 사항
- 데이터베이스: u622428657_homekmart
- 주요 점포: store_id = 1
- 총 상품 수: 23,646개
- PHP 버전: 8.2.28
- MariaDB 10
