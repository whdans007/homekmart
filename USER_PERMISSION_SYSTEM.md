# 권한별 사용자 관리 시스템

HOME K MART 시스템에 구현된 세분화된 사용자 권한 관리 시스템입니다.

## 🎯 주요 기능

### 1. 권한별 기능 접근 제어
- **일반 사용자**: 쇼핑몰만 이용 가능, 관리자 메뉴 접근 차단
- **관리자**: 제한된 관리 기능 접근
- **총괄 관리자**: 모든 기능 접근 가능

### 2. 세분화된 권한 설정
- 사용자별 개별 권한 설정 가능
- 권한 프리셋 제공 (일반/관리자/총괄관리자)
- 실시간 권한 검증

## 📊 권한 구조

### 권한 종류
```json
{
  "admin_access": "관리자 메뉴 접근",
  "user_management": "회원 관리",
  "store_management": "지점 관리", 
  "product_management": "상품 관리",
  "purchase_management": "매입 관리",
  "brand_management": "브랜드 관리",
  "category_management": "카테고리 관리",
  "supplier_management": "공급처 관리",
  "settings": "환경 설정",
  "shop_access": "쇼핑몰 접근"
}
```

### 기본 권한 템플릿

#### 일반 사용자 (user)
- ✅ 쇼핑몰 접근
- ❌ 관리자 메뉴 접근 불가

#### 관리자 (admin)  
- ✅ 관리자 메뉴 접근
- ✅ 회원 관리
- ✅ 상품 관리
- ✅ 매입 관리
- ✅ 쇼핑몰 접근
- ❌ 지점/브랜드/카테고리/공급처/환경설정 관리 불가

#### 총괄 관리자 (super_admin)
- ✅ 모든 기능 접근 가능

## 🛠 구현된 기능

### 1. 데이터베이스 구조
```sql
-- users 테이블에 permissions 컬럼 추가
ALTER TABLE users 
ADD COLUMN permissions JSON DEFAULT NULL;

-- 기존 사용자들에 기본 권한 설정
UPDATE users SET permissions = JSON_OBJECT(...);
```

### 2. 권한 검사 함수 (`lib/permission_helper.php`)
```php
// 특정 권한 확인
has_permission('user_management')

// 페이지 접근 제어
require_permission('admin_access')

// 사용자 권한 업데이트
update_user_permissions($user_id, $permissions)
```

### 3. 회원 추가 시 권한 선택 UI
- 권한별 체크박스 인터페이스
- 권한 프리셋 버튼 (일반/관리자/총괄관리자)
- 자동 권한 검증 (관리자 메뉴 접근 시 다른 관리 권한 필수)

### 4. 메뉴 표시 제어
- 권한에 따른 동적 메뉴 표시/숨김
- 데스크톱 및 모바일 메뉴 모두 적용

### 5. 일반 사용자용 쇼핑몰 인터페이스
- 상품 목록 표시 (재고 있는 상품만)
- 카테고리/브랜드별 필터링
- 상품 검색 기능
- 관리자 모드 전환 버튼 (권한 있는 경우만)

## 📁 관련 파일

### 새로 생성된 파일
- `lib/permission_helper.php` - 권한 관리 헬퍼 함수
- `public/shop.php` - 일반 사용자용 쇼핑몰 인터페이스
- `sql/add_permissions_column.sql` - 데이터베이스 스키마 변경

### 수정된 파일
- `public/add_user.php` - 권한 선택 UI 추가
- `public/user_management.php` - 권한 기반 접근 제어
- `public/partials/header.php` - 권한 기반 메뉴 표시

## 🔧 사용 방법

### 1. 데이터베이스 설정
```bash
# MySQL에서 permissions 컬럼 추가 및 기본 권한 설정
mysql -u root -p < sql/add_permissions_column.sql
```

### 2. 새 사용자 추가
1. 관리자 메뉴 → 회원 관리 → 회원 추가
2. 기본 정보 입력
3. 권한 프리셋 선택 또는 개별 권한 설정
4. 저장

### 3. 권한 확인
```php
// 특정 기능 접근 전 권한 확인
if (has_permission('product_management')) {
    // 상품 관리 기능 실행
}

// 페이지 전체 접근 제어
require_permission('admin_access');
```

## 🔐 보안 특징

### 1. 다층 보안
- 메뉴 표시 제어 (프론트엔드)
- 페이지 접근 제어 (백엔드)
- 기능별 권한 검증

### 2. 하위 호환성
- 기존 role 기반 권한과 호환
- permissions 컬럼이 없어도 기본 동작 보장

### 3. 안전한 기본값
- 권한 설정이 없으면 최소 권한 적용
- super_admin은 항상 모든 권한 보유

## 🎨 사용자 경험

### 일반 사용자
- 로그인 후 자동으로 쇼핑몰로 이동
- 관리자 메뉴 완전 숨김
- 직관적인 상품 검색 및 필터링

### 관리자
- 필요한 관리 메뉴만 표시
- 권한 없는 메뉴는 자동 숨김
- 쇼핑몰과 관리자 모드 간 전환 가능

### 총괄 관리자
- 모든 기능 접근 가능
- 다른 사용자 권한 설정 관리
- 시스템 전체 제어

## 📈 확장 가능성

### 추가 가능한 권한
- `inventory_management` - 재고 관리
- `sales_management` - 판매 관리  
- `report_access` - 보고서 접근
- `promotion_management` - 프로모션 관리

### 향후 개선 사항
- 권한 그룹 기능
- 시간 기반 권한 (근무 시간만 접근)
- 지점별 권한 세분화
- 권한 변경 로그 기록

이 시스템을 통해 사용자별로 적절한 권한을 부여하여 보안을 강화하고, 각 역할에 맞는 최적화된 인터페이스를 제공할 수 있습니다.