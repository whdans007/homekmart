# HOME K MART Delivery App

Flutter 기반 필리핀 배달 앱 - COD(현금 결제) 중심 시스템

## 📱 프로젝트 개요

HOME K MART의 모바일 배달 앱입니다. 필리핀 시장에 최적화되어 있으며, Cash on Delivery를 주요 결제 수단으로 지원합니다.

## 🛠 기술 스택

- **Framework**: Flutter 3.x
- **언어**: Dart 3.x
- **상태 관리**: Provider + Riverpod
- **네비게이션**: GoRouter
- **HTTP 클라이언트**: Dio
- **로컬 저장소**: Hive + Shared Preferences
- **지도**: Google Maps
- **인증**: JWT + Google Sign-In

## 📁 프로젝트 구조

```
lib/
├── main.dart                      # 앱 진입점
├── app.dart                       # 앱 설정 및 라우팅
│
├── core/                          # 핵심 기능
│   ├── constants/                 # 상수 정의
│   │   ├── api_constants.dart
│   │   ├── app_constants.dart
│   │   └── storage_keys.dart
│   ├── config/                    # 설정
│   │   ├── app_config.dart
│   │   └── theme_config.dart
│   ├── error/                     # 에러 핸들링
│   │   ├── exceptions.dart
│   │   └── failures.dart
│   ├── network/                   # 네트워크
│   │   ├── dio_client.dart
│   │   └── network_info.dart
│   ├── storage/                   # 로컬 저장소
│   │   ├── secure_storage.dart
│   │   └── local_storage.dart
│   └── utils/                     # 유틸리티
│       ├── validators.dart
│       ├── formatters.dart
│       └── helpers.dart
│
├── data/                          # 데이터 레이어
│   ├── models/                    # 데이터 모델
│   │   ├── user_model.dart
│   │   ├── product_model.dart
│   │   ├── address_model.dart
│   │   ├── cart_model.dart
│   │   └── order_model.dart
│   ├── repositories/              # 리포지토리
│   │   ├── auth_repository.dart
│   │   ├── product_repository.dart
│   │   ├── cart_repository.dart
│   │   └── order_repository.dart
│   └── services/                  # API 서비스
│       ├── api_service.dart
│       ├── auth_service.dart
│       └── storage_service.dart
│
├── domain/                        # 도메인 레이어
│   ├── entities/                  # 엔티티
│   └── usecases/                  # 유즈케이스
│
├── presentation/                  # UI 레이어
│   ├── providers/                 # 상태 관리
│   │   ├── auth_provider.dart
│   │   ├── cart_provider.dart
│   │   └── order_provider.dart
│   ├── screens/                   # 화면
│   │   ├── auth/
│   │   │   ├── login_screen.dart
│   │   │   └── register_screen.dart
│   │   ├── home/
│   │   │   ├── home_screen.dart
│   │   │   └── product_detail_screen.dart
│   │   ├── cart/
│   │   │   └── cart_screen.dart
│   │   ├── order/
│   │   │   ├── checkout_screen.dart
│   │   │   ├── order_list_screen.dart
│   │   │   └── order_detail_screen.dart
│   │   ├── address/
│   │   │   ├── address_list_screen.dart
│   │   │   └── address_form_screen.dart
│   │   └── profile/
│   │       └── profile_screen.dart
│   ├── widgets/                   # 재사용 위젯
│   │   ├── common/
│   │   │   ├── app_button.dart
│   │   │   ├── app_textfield.dart
│   │   │   └── loading_overlay.dart
│   │   ├── product/
│   │   │   ├── product_card.dart
│   │   │   └── product_grid.dart
│   │   └── cart/
│   │       └── cart_item_card.dart
│   └── routes/                    # 라우팅
│       └── app_router.dart
│
└── l10n/                          # 다국어
    ├── app_en.arb
    └── app_ko.arb
```

## 🚀 시작하기

### 필수 요구사항

- Flutter SDK 3.0 이상
- Dart SDK 3.0 이상
- Android Studio / VS Code
- Android SDK (Android 개발)
- Xcode (iOS 개발, macOS만)

### 설치

1. **Flutter 의존성 설치**
```bash
flutter pub get
```

2. **코드 생성 (JSON Serialization)**
```bash
flutter pub run build_runner build --delete-conflicting-outputs
```

3. **앱 실행**
```bash
# Android
flutter run

# iOS
flutter run -d ios

# 특정 디바이스
flutter devices
flutter run -d <device-id>
```

## 🔧 설정

### API 엔드포인트 설정

`lib/core/constants/api_constants.dart` 파일에서 API URL을 설정합니다:

```dart
static const String baseUrl = 'https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/api';
```

### Google Maps API 키 설정

1. **Android**: `android/app/src/main/AndroidManifest.xml`
```xml
<meta-data
    android:name="com.google.android.geo.API_KEY"
    android:value="YOUR_API_KEY"/>
```

2. **iOS**: `ios/Runner/AppDelegate.swift`
```swift
GMSServices.provideAPIKey("YOUR_API_KEY")
```

## 📱 주요 기능

### 1. 인증
- ✅ 이메일/비밀번호 로그인
- ✅ 회원가입
- ⏳ 구글 로그인 (향후)
- ✅ JWT 토큰 관리

### 2. 상품
- ✅ 상품 목록 (페이지네이션)
- ✅ 상품 검색
- ✅ 카테고리 필터
- ✅ 상품 상세

### 3. 장바구니
- ✅ 장바구니 추가/수정/삭제
- ✅ 재고 확인
- ✅ 합계 계산

### 4. 주문
- ✅ 주문 생성
- ✅ 배송지 선택
- ✅ 결제 방법 선택 (COD/GCash/PayMaya)
- ✅ 주문 내역
- ✅ 주문 추적
- ✅ 주문 취소

### 5. 주소 관리
- ✅ 필리핀 주소 체계 (Barangay/Landmark)
- ✅ GPS 위치 선택
- ✅ 배송 가능 지역 확인
- ✅ 배송비 계산

### 6. 배송 추적
- ✅ 실시간 주문 상태
- ✅ 배송 이력
- ⏳ 배달원 위치 (향후)

## 🎨 디자인 시스템

### 컬러 팔레트
- Primary: `#6366F1` (인디고)
- Secondary: `#10B981` (에메랄드)
- Error: `#EF4444` (빨강)
- Warning: `#F59E0B` (주황)
- Success: `#10B981` (초록)

### 타이포그래피
- 기본 폰트: Pretendard (한글/영문)
- Display: Pretendard Bold
- Heading: Pretendard SemiBold
- Body: Pretendard Regular

## 🧪 테스트

```bash
# 단위 테스트
flutter test

# 통합 테스트
flutter test integration_test

# 커버리지
flutter test --coverage
```

## 📦 빌드

### Android APK
```bash
flutter build apk --release
```

### Android App Bundle (Google Play)
```bash
flutter build appbundle --release
```

### iOS
```bash
flutter build ios --release
```

## 🔐 보안

- JWT 토큰은 `flutter_secure_storage`에 안전하게 저장
- API 통신은 HTTPS 사용
- 민감한 정보는 암호화 저장

## 📝 개발 가이드

### 상태 관리 패턴
```dart
// Provider 사용 예시
final cartProvider = ChangeNotifierProvider((ref) => CartProvider());

// 사용
final cart = ref.watch(cartProvider);
```

### API 호출 패턴
```dart
// Repository 패턴
class ProductRepository {
  Future<List<Product>> getProducts() async {
    final response = await apiService.get('/products');
    return response.map((json) => Product.fromJson(json)).toList();
  }
}
```

## 🐛 문제 해결

### 일반적인 문제

1. **Flutter 버전 문제**
```bash
flutter upgrade
flutter clean
flutter pub get
```

2. **iOS 빌드 문제**
```bash
cd ios
pod deintegrate
pod install
```

3. **Android 빌드 문제**
```bash
cd android
./gradlew clean
```

## 📄 라이선스

이 프로젝트는 HOME K MART의 소유입니다.

## 👥 개발팀

- **Backend API**: PHP 8.2 + MySQL
- **Mobile App**: Flutter 3.x
- **Server**: Synology NAS Web Station

## 📞 지원

문제가 있으시면 개발팀에 문의하세요.
