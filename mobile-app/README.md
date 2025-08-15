# HOME K MART Philippines Delivery App

React Native 기반 필리핀 배달 쇼핑몰 앱

## 프로젝트 개요

- **플랫폼**: iOS/Android (React Native)
- **백엔드**: PHP REST API (HOME K MART 시스템 기반)
- **인증**: Google OAuth 2.0
- **결제**: COD (Cash on Delivery) 중심
- **지역**: 필리핀 (바랑가이, 랜드마크 지원)
- **언어**: 영어/한국어

## 기술 스택

### Frontend (Mobile)
- React Native 0.72+
- TypeScript
- React Navigation 6
- React Query/TanStack Query
- Async Storage
- React Native Maps
- React Native Google Signin

### Backend API
- PHP 8.2+
- MySQL/MariaDB
- Google OAuth 2.0
- RESTful API 설계

## API 엔드포인트

### 인증
- `POST /auth/google` - Google OAuth 로그인
- `POST /auth/logout` - 로그아웃
- `GET /auth/profile` - 사용자 프로필

### 상품
- `GET /enhanced_products_api.php` - 상품 목록 (필터링, 검색)
- `GET /working_categories_api.php` - 카테고리 목록
- `GET /correct_products_api.php` - 기본 상품 API

### 주문
- `GET /delivery_orders_api.php` - 주문 목록
- `POST /delivery_orders_api.php` - 주문 생성

### 배달 주소
- `GET /delivery_addresses_api.php` - 주소 목록
- `POST /delivery_addresses_api.php` - 주소 추가
- `PUT /delivery_addresses_api.php` - 주소 수정

### 위치 & 배달 구역
- `GET /delivery_zones_api.php` - 배달 구역 정보
- `GET /location_search_api.php` - 위치 검색

### 장바구니
- `GET /api_cart.php` - 장바구니 조회
- `POST /api_cart.php` - 상품 추가

## 프로젝트 구조 (예정)

```
mobile-app/
├── src/
│   ├── components/        # 재사용 컴포넌트
│   ├── screens/          # 화면 컴포넌트
│   ├── navigation/       # 네비게이션 설정
│   ├── services/         # API 호출
│   ├── hooks/           # 커스텀 훅
│   ├── utils/           # 유틸리티 함수
│   ├── types/           # TypeScript 타입
│   └── constants/       # 상수 정의
├── android/             # Android 설정
├── ios/                # iOS 설정
└── package.json
```

## 주요 기능

### 인증 & 사용자 관리
- Google 계정 로그인
- 사용자 프로필 관리
- 배달 주소 관리 (필리핀 주소 체계)

### 상품 탐색
- 카테고리별 상품 검색
- 실시간 검색
- 상품 상세 정보
- 가격 정보 (PHP)

### 주문 & 결제
- 장바구니 관리
- COD 결제
- 주문 추적
- 주문 히스토리

### 배달 & 위치
- 배달 가능 지역 확인
- Google Maps 연동
- 바랑가이/랜드마크 기반 주소
- 배달비 계산

## 개발 환경 설정

### 1. React Native 환경 설정
```bash
# React Native CLI 설치
npm install -g react-native-cli

# 새 프로젝트 생성
npx react-native init HomeKMartDelivery --template react-native-template-typescript

# 의존성 설치
cd HomeKMartDelivery
npm install
```

### 2. 추가 패키지 설치 (예정)
```bash
# 네비게이션
npm install @react-navigation/native @react-navigation/stack @react-navigation/bottom-tabs

# 상태 관리 & API
npm install @tanstack/react-query axios

# Google 로그인
npm install @react-native-google-signin/google-signin

# 맵
npm install react-native-maps

# 기타
npm install react-native-async-storage react-native-vector-icons
```

## API 서버 정보

- **Base URL**: `http://192-168-0-138.philsarang.direct.quickconnect.to/min/`
- **테스트 페이지**: `/api_test_page.php`
- **데이터베이스**: MySQL (min)

## 다음 단계

1. ✅ React Native 프로젝트 생성
2. 📱 기본 네비게이션 구조 설정
3. 🔐 Google OAuth 연동
4. 🛒 상품 목록 화면
5. 📍 주소 관리 화면
6. 💰 장바구니 & 주문 화면
7. 🌐 다국어 지원 (영어/한국어)

## 개발 참고사항

### 필리핀 주소 체계
- **Province** (도/주)
- **City/Municipality** (시/군)
- **Barangay** (바랑가이 - 최소 행정구역)
- **Street Address** (상세 주소)
- **Landmark** (랜드마크)

### COD 결제 특징
- 현금 결제만 지원
- 배달시 결제
- 거스름돈 처리
- 주문 확인 필수

### 개발 우선순위
1. 핵심 기능 (상품 조회, 주문)
2. 사용자 경험 (검색, 필터)
3. 배달 기능 (주소, 구역)
4. 부가 기능 (알림, 리뷰)