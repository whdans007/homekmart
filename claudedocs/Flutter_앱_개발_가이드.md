# Flutter 배달 앱 개발 가이드

**작성일**: 2025-10-03
**버전**: 1.0
**기술 스택**: Flutter 3.x + Dart 3.x

---

## 📋 목차

1. [프로젝트 개요](#프로젝트-개요)
2. [개발 환경 설정](#개발-환경-설정)
3. [프로젝트 구조](#프로젝트-구조)
4. [개발 가이드](#개발-가이드)
5. [다음 단계](#다음-단계)

---

## 프로젝트 개요

### 앱 정보
- **앱 이름**: HOME K MART Delivery
- **타겟 시장**: 필리핀
- **주요 기능**: COD 중심 배달 앱
- **플랫폼**: Android + iOS (크로스 플랫폼)

### 기술 스택

#### Core
- **Framework**: Flutter 3.x
- **언어**: Dart 3.x
- **IDE**: VS Code / Android Studio

#### 주요 패키지
- **상태 관리**: Provider + Riverpod
- **네비게이션**: GoRouter
- **HTTP**: Dio
- **로컬 저장소**: Hive + Shared Preferences + Secure Storage
- **지도**: Google Maps Flutter
- **이미지**: Cached Network Image
- **폰트**: Google Fonts
- **JSON**: json_serializable + freezed

---

## 개발 환경 설정

### 1. Flutter SDK 설치

#### Windows
```bash
# Chocolatey 사용
choco install flutter

# 또는 공식 사이트에서 다운로드
# https://docs.flutter.dev/get-started/install/windows
```

#### macOS
```bash
# Homebrew 사용
brew install --cask flutter

# Flutter Doctor 실행
flutter doctor
```

### 2. 안드로이드 스튜디오 설치
```bash
# Android SDK 및 Android Studio 설치
# https://developer.android.com/studio

# Flutter/Dart 플러그인 설치
```

### 3. 프로젝트 설정

```bash
# 프로젝트 디렉토리로 이동
cd z:/homekmart/delivery_app_flutter

# 의존성 설치
flutter pub get

# 코드 생성 (JSON serialization)
flutter pub run build_runner build --delete-conflicting-outputs

# 앱 실행
flutter run
```

### 4. VS Code 확장 설치
- Flutter
- Dart
- Flutter Widget Snippets
- Awesome Flutter Snippets

---

## 프로젝트 구조

### 디렉토리 구조
```
delivery_app_flutter/
├── lib/
│   ├── main.dart                    # 앱 진입점
│   ├── app.dart                     # 앱 설정
│   │
│   ├── core/                        # 핵심 기능
│   │   ├── constants/               # 상수
│   │   │   ├── api_constants.dart   # API URL 및 엔드포인트
│   │   │   ├── app_constants.dart   # 앱 전역 상수
│   │   │   └── storage_keys.dart    # 저장소 키
│   │   ├── config/
│   │   │   └── theme_config.dart    # 테마 설정
│   │   ├── error/
│   │   │   └── exceptions.dart      # 예외 클래스
│   │   ├── network/
│   │   │   └── dio_client.dart      # HTTP 클라이언트
│   │   ├── storage/
│   │   │   ├── secure_storage.dart  # 보안 저장소
│   │   │   └── local_storage.dart   # 로컬 저장소
│   │   └── utils/                   # 유틸리티
│   │
│   ├── data/                        # 데이터 레이어
│   │   ├── models/                  # 데이터 모델
│   │   │   ├── user_model.dart
│   │   │   ├── product_model.dart
│   │   │   ├── address_model.dart
│   │   │   ├── cart_model.dart
│   │   │   └── order_model.dart
│   │   ├── repositories/            # 리포지토리
│   │   │   ├── auth_repository.dart
│   │   │   ├── product_repository.dart
│   │   │   ├── cart_repository.dart
│   │   │   └── order_repository.dart
│   │   └── services/                # API 서비스
│   │       ├── auth_service.dart
│   │       └── api_service.dart
│   │
│   ├── presentation/                # UI 레이어
│   │   ├── providers/               # 상태 관리
│   │   ├── screens/                 # 화면
│   │   ├── widgets/                 # 재사용 위젯
│   │   └── routes/                  # 라우팅
│   │
│   └── l10n/                        # 다국어
│
├── assets/                          # 리소스
│   ├── images/
│   ├── icons/
│   ├── fonts/
│   └── animations/
│
├── test/                            # 테스트
├── integration_test/                # 통합 테스트
│
├── android/                         # Android 설정
├── ios/                             # iOS 설정
│
├── pubspec.yaml                     # 패키지 설정
└── README.md
```

### 아키텍처 패턴

**Clean Architecture + MVVM**

```
Presentation Layer (UI)
    ↓
Domain Layer (Business Logic)
    ↓
Data Layer (API/Database)
```

---

## 개발 가이드

### 1. 코드 생성

모델 클래스 작성 후 코드 생성:

```bash
# 일회성 생성
flutter pub run build_runner build --delete-conflicting-outputs

# 파일 변경 감지 (개발 중)
flutter pub run build_runner watch --delete-conflicting-outputs
```

### 2. 모델 클래스 작성 예시

```dart
import 'package:json_annotation/json_annotation.dart';

part 'user_model.g.dart';

@JsonSerializable()
class UserModel {
  final int id;
  final String email;
  @JsonKey(name: 'full_name')
  final String fullName;

  UserModel({
    required this.id,
    required this.email,
    required this.fullName,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) =>
      _$UserModelFromJson(json);

  Map<String, dynamic> toJson() => _$UserModelToJson(this);
}
```

### 3. API 서비스 작성 패턴

```dart
class ProductService {
  final DioClient _dioClient;

  ProductService(this._dioClient);

  Future<List<ProductModel>> getProducts({
    required int storeId,
    int page = 1,
    int limit = 20,
  }) async {
    final response = await _dioClient.get(
      ApiConstants.products,
      queryParameters: {
        'store_id': storeId,
        'page': page,
        'limit': limit,
      },
    );

    if (response.data['success'] == true) {
      final data = response.data['data']['data'] as List;
      return data.map((json) => ProductModel.fromJson(json)).toList();
    }

    throw ServerException(message: 'Failed to fetch products');
  }
}
```

### 4. Provider 사용 패턴

```dart
import 'package:flutter/foundation.dart';

class CartProvider extends ChangeNotifier {
  List<CartItem> _items = [];

  List<CartItem> get items => _items;

  int get itemCount => _items.length;

  double get totalAmount {
    return _items.fold(0, (sum, item) => sum + item.subtotal);
  }

  void addItem(CartItem item) {
    _items.add(item);
    notifyListeners();
  }

  void removeItem(int cartId) {
    _items.removeWhere((item) => item.id == cartId);
    notifyListeners();
  }

  void clear() {
    _items.clear();
    notifyListeners();
  }
}
```

### 5. 화면 구현 패턴

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

class ProductListScreen extends StatefulWidget {
  const ProductListScreen({super.key});

  @override
  State<ProductListScreen> createState() => _ProductListScreenState();
}

class _ProductListScreenState extends State<ProductListScreen> {
  @override
  void initState() {
    super.initState();
    // 데이터 로드
    Future.microtask(() => context.read<ProductProvider>().loadProducts());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Products'),
      ),
      body: Consumer<ProductProvider>(
        builder: (context, provider, child) {
          if (provider.isLoading) {
            return const Center(child: CircularProgressIndicator());
          }

          if (provider.error != null) {
            return Center(child: Text(provider.error!));
          }

          return ListView.builder(
            itemCount: provider.products.length,
            itemBuilder: (context, index) {
              final product = provider.products[index];
              return ProductCard(product: product);
            },
          );
        },
      ),
    );
  }
}
```

---

## 다음 단계

### 즉시 구현 필요

#### 1. 데이터 모델 완성 (우선순위: 높음)
- ✅ `user_model.dart` - 완료
- ✅ `product_model.dart` - 완료
- ⏳ `address_model.dart` - 필요
- ⏳ `cart_model.dart` - 필요
- ⏳ `order_model.dart` - 필요

#### 2. 서비스 레이어 구현
- ✅ `auth_service.dart` - 완료
- ⏳ `product_service.dart` - 필요
- ⏳ `cart_service.dart` - 필요
- ⏳ `order_service.dart` - 필요
- ⏳ `address_service.dart` - 필요

#### 3. Provider 구현
- ⏳ `auth_provider.dart` - 인증 상태 관리
- ⏳ `product_provider.dart` - 상품 목록 관리
- ⏳ `cart_provider.dart` - 장바구니 관리
- ⏳ `order_provider.dart` - 주문 관리

#### 4. 라우팅 설정
- ⏳ GoRouter 설정
- ⏳ 라우트 정의
- ⏳ 인증 가드

#### 5. UI 컴포넌트
- ⏳ 공통 버튼 컴포넌트
- ⏳ 공통 입력 필드
- ⏳ 로딩 인디케이터
- ⏳ 에러 위젯

#### 6. 인증 화면
- ⏳ 로그인 화면
- ⏳ 회원가입 화면
- ⏳ 스플래시 화면

#### 7. 메인 화면
- ⏳ 홈 화면 (상품 목록)
- ⏳ 상품 상세
- ⏳ 장바구니
- ⏳ 프로필

### 실행 명령어

```bash
# 의존성 설치
flutter pub get

# 코드 생성
flutter pub run build_runner build --delete-conflicting-outputs

# 앱 실행 (개발 모드)
flutter run

# 앱 실행 (특정 디바이스)
flutter devices
flutter run -d <device-id>

# 핫 리로드: r
# 핫 리스타트: R
# 종료: q

# 테스트
flutter test

# 빌드 (Android)
flutter build apk --release

# 빌드 (iOS)
flutter build ios --release
```

---

## API 연동 체크리스트

### 인증 API
- [ ] POST /api/auth/register - 회원가입
- [ ] POST /api/auth/login - 로그인
- [ ] GET /api/auth/profile - 프로필 조회

### 상품 API
- [ ] GET /api/products - 상품 목록
- [ ] GET /api/products/detail - 상품 상세

### 장바구니 API
- [ ] GET /api/cart - 장바구니 조회
- [ ] POST /api/cart - 추가
- [ ] PUT /api/cart - 수정
- [ ] DELETE /api/cart - 삭제

### 주문 API
- [ ] POST /api/orders - 주문 생성
- [ ] GET /api/orders - 주문 목록
- [ ] GET /api/orders/detail - 주문 상세
- [ ] POST /api/orders/cancel - 주문 취소
- [ ] GET /api/orders/tracking - 배송 추적

### 주소 API
- [ ] GET /api/addresses - 주소 목록
- [ ] POST /api/addresses - 주소 추가
- [ ] PUT /api/addresses - 주소 수정
- [ ] DELETE /api/addresses - 주소 삭제
- [ ] POST /api/addresses/set-default - 기본 주소 설정

### 배송 구역 API
- [ ] GET /api/delivery-zones - 배송 구역 조회
- [ ] POST /api/delivery-zones/check - 배송 가능 확인
- [ ] POST /api/delivery-zones/calculate-fee - 배송비 계산

---

## 문제 해결

### 일반적인 오류

#### 1. 코드 생성 오류
```bash
flutter pub run build_runner clean
flutter pub run build_runner build --delete-conflicting-outputs
```

#### 2. 패키지 충돌
```bash
flutter clean
flutter pub get
```

#### 3. Gradle 오류 (Android)
```bash
cd android
./gradlew clean
cd ..
flutter run
```

#### 4. Pod 오류 (iOS)
```bash
cd ios
pod deintegrate
pod install
cd ..
flutter run
```

---

## 코딩 컨벤션

### 파일 명명
- 소문자 + 언더스코어: `user_model.dart`
- 클래스명은 PascalCase: `UserModel`

### 변수 명명
- camelCase: `userName`, `isLoggedIn`
- 상수는 lowerCamelCase: `defaultPageSize`

### 폴더 구조
- 기능별로 분리
- 공통 컴포넌트는 widgets/common

---

## 참고 자료

- [Flutter 공식 문서](https://docs.flutter.dev/)
- [Dart 공식 문서](https://dart.dev/guides)
- [Provider 패키지](https://pub.dev/packages/provider)
- [Dio 패키지](https://pub.dev/packages/dio)
- [GoRouter 패키지](https://pub.dev/packages/go_router)

---

**다음 문서**: UI 컴포넌트 가이드 (예정)
