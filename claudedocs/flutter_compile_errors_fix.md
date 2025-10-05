# Flutter 컴파일 에러 수정 가이드

**작성일**: 2025-10-04
**총 에러 수**: 60+개

## 📋 에러 분류

### 1. Service/Provider 생성자 에러 (6개)
**문제**: Named parameter와 positional parameter 불일치

#### app.dart 수정 필요
```dart
// ❌ 현재 (에러)
ProductProvider(productService: ProductService(dioClient))
OrderProvider(orderService: OrderService(dioClient))

// ✅ 수정 후
ProductProvider(ProductService(dioClient))
OrderProvider(OrderService(dioClient))
```

#### Provider 생성자 수정 필요
- `auth_provider.dart:16` - positional → named parameter로 변경 필요
- `cart_provider.dart:16` - positional → named parameter로 변경 필요

### 2. Model 필드 이름 불일치 (30+개)

#### ProductModel 필드 누락
```dart
// 누락된 필드들
- productId  (id로 변경 필요)
- stockQuantity  (quantity로 변경 필요)
```

#### OrderModel 필드 누락
```dart
// 누락된 필드들
- orderId  (id로 변경 필요)
- formattedOrderDate  (getter 추가 필요)
- formattedTotalAmount  (getter 추가 필요)
```

#### OrderDetailModel 필드 누락
```dart
// 누락된 필드들
- deliveryAddress  (address로 변경 필요)
- formattedOrderDate  (getter 추가 필요)
- formattedTotalAmount  (getter 추가 필요)
```

#### OrderItemModel 필드 누락
```dart
// 누락된 필드들
- imageUrl  (image_url로 변경 필요)
- formattedPrice  (getter 추가 필요)
```

#### OrderTrackingModel 필드 누락
```dart
// 누락된 필드들
- formattedTimestamp  (getter 추가 필요)
```

#### CartItemModel 필드 누락
```dart
// 누락된 필드들
- formattedPrice  (getter 추가 필요)
```

#### CartSummary 필드 누락
```dart
// 누락된 필드들
- formattedTotal  (getter 추가 필요)
- formattedDeliveryFee  (getter 추가 필요)
```

#### UserModel 필드 누락
```dart
// 누락된 필드들
- name  (full_name으로 변경 필요)
```

### 3. ThemeConfig 누락 속성 (15+개)
**문제**: `ThemeConfig.textSecondary` 정의 없음

```dart
// theme_config.dart에 추가 필요
static const Color textSecondary = Color(0xFF6B7280);
static const Color textPrimary = Color(0xFF111827);
```

### 4. CartProvider 메소드 누락 (2개)
```dart
// 누락된 메소드들
- updateCartItem()
- deleteCartItem()
```

### 5. OrderService 메소드 시그니처 불일치 (3개)
```dart
// ❌ 현재 호출
await _orderService.getOrderDetail(orderId)
await _orderService.getOrderTracking(orderId)
await _orderService.cancelOrder(orderId)

// ✅ 실제 시그니처 (named parameter)
await _orderService.getOrderDetail(orderId: orderId)
await _orderService.getOrderTracking(orderId: orderId)
await _orderService.cancelOrder(orderId: orderId)
```

### 6. OrderProvider.createOrder 파라미터 불일치
```dart
// ❌ 현재
addressId: addressId

// ✅ 수정 (이름 확인 필요)
deliveryAddressId: addressId
```

### 7. AuthService.register 파라미터 불일치
```dart
// ❌ 현재 호출
name: _nameController.text.trim()

// ✅ 수정
fullName: _nameController.text.trim()
```

### 8. CardTheme 타입 불일치
```dart
// ❌ 현재
cardTheme: CardTheme(...)

// ✅ 수정
cardTheme: CardThemeData(...)
```

### 9. ProductProvider.loadProducts 타입 불일치
```dart
// ❌ 현재
_products = await _productService.getProducts(storeId: storeId)

// ✅ 수정
final response = await _productService.getProducts(storeId: storeId)
_products = response.products  // ProductListResponse.products 사용
```

### 10. OrderProvider.loadOrders 타입 불일치
```dart
// ❌ 현재
_orders = await _orderService.getOrders(status: status)

// ✅ 수정
final response = await _orderService.getOrders(status: status)
_orders = response.orders  // OrderListResponse.orders 사용
```

## 🔧 수정 순서

### Phase 1: 핵심 인프라 수정
1. ✅ ThemeConfig에 textSecondary, textPrimary 추가
2. ✅ CardTheme → CardThemeData 변경

### Phase 2: Model 수정
3. ✅ ProductModel 필드 추가/수정
   - productId getter 추가
   - stockQuantity getter 추가
4. ✅ UserModel 필드 추가
   - name getter 추가
5. ✅ CartItemModel 필드 추가
   - formattedPrice getter 추가
6. ✅ CartSummary 필드 추가
   - formattedTotal, formattedDeliveryFee getter 추가
7. ✅ OrderModel 필드 추가
   - orderId getter 추가
   - formatted 필드 getter 추가
8. ✅ OrderDetailModel 필드 추가
   - deliveryAddress 필드 추가
   - formatted 필드 getter 추가
9. ✅ OrderItemModel 필드 추가
   - imageUrl 필드 추가
   - formattedPrice getter 추가
10. ✅ OrderTrackingModel 필드 추가
    - formattedTimestamp getter 추가

### Phase 3: Service/Provider 수정
11. ✅ app.dart Provider 생성 수정
12. ✅ AuthProvider, CartProvider 생성자 수정
13. ✅ CartProvider 메소드 추가 (updateCartItem, deleteCartItem)
14. ✅ ProductProvider.loadProducts 수정
15. ✅ OrderProvider 수정
    - loadOrders 수정
    - createOrder addressId → deliveryAddressId
    - Service 메소드 호출 named parameter로 수정

### Phase 4: UI 화면 수정
16. ✅ register_screen.dart name → fullName
17. ✅ product_detail_screen.dart ProductService 생성자 수정

## 📝 수정 스크립트

현재 에러가 너무 많아서 수동 수정보다는 체계적인 접근이 필요합니다.

### 우선순위 1: 즉시 수정 가능한 항목 (20분)
- theme_config.dart
- app.dart
- Provider 생성자들

### 우선순위 2: Model 필드 수정 (1시간)
- 모든 Model 클래스에 getter 추가

### 우선순위 3: UI 화면 수정 (30분)
- 필드 이름 변경 반영

## 🎯 다음 단계

Flutter 앱 컴파일 에러 수정보다는:

1. **API 테스트 완료** 후 Flutter 앱 개발 재개
2. 또는 **새로운 기능 개발** (결제 API, 배달원 추적 등)

Flutter 앱은 너무 많은 에러가 있어서 한 번에 수정하기보다는 단계적으로 접근하는 것이 좋습니다.
