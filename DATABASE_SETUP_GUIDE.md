# 🗃️ HOME K MART 데이터베이스 설치 가이드

## 📋 개요

이 가이드는 HOME K MART 시스템을 InfinityFree 호스팅 환경에 배포하기 위한 데이터베이스 설정 방법을 설명합니다.

## 🔧 사전 준비사항

### 호스팅 정보
- **도메인**: homekmart.rf.gd
- **데이터베이스명**: if0_39723369_min
- **사용자명**: if0_39723369
- **비밀번호**: PGm9pJCqUW
- **호스트**: sql112.infinityfree.com
- **포트**: 3306

### 필요한 파일들
- `sql/complete_schema.sql` - 전체 데이터베이스 스키마
- `sql/initial_data.sql` - 초기 데이터
- `database_setup.php` - 로컬 스키마 분석 도구 (선택적)

## 🚀 설치 단계

### 1단계: MySQL 관리 도구 접속

1. **InfinityFree 제어판 로그인**
   - InfinityFree 계정으로 로그인
   - 호스팅 계정 관리 페이지 이동

2. **MySQL 데이터베이스 관리**
   - "MySQL Databases" 또는 "phpMyAdmin" 클릭
   - 데이터베이스 `if0_39723369_min` 선택

### 2단계: 데이터베이스 스키마 생성

1. **SQL 탭 선택**
   - phpMyAdmin에서 "SQL" 탭 클릭

2. **스키마 파일 실행**
   ```sql
   -- sql/complete_schema.sql 파일의 전체 내용을 복사하여 붙여넣기
   ```
   - "실행" 또는 "Go" 버튼 클릭
   - 성공 메시지 확인

### 3단계: 초기 데이터 삽입

1. **초기 데이터 실행**
   ```sql
   -- sql/initial_data.sql 파일의 전체 내용을 복사하여 붙여넣기
   ```
   - "실행" 또는 "Go" 버튼 클릭
   - 완료 메시지와 데이터 개수 확인

### 4단계: 설치 검증

1. **테이블 생성 확인**
   - 좌측 데이터베이스 트리에서 생성된 테이블들 확인
   - 예상 테이블 수: 약 20-25개

2. **데이터 확인**
   ```sql
   SELECT COUNT(*) FROM users;     -- 3개 (관리자 계정들)
   SELECT COUNT(*) FROM stores;    -- 2개 (본점, 분점)
   SELECT COUNT(*) FROM products;  -- 5개 (샘플 상품들)
   ```

## 📊 생성되는 주요 테이블

### 기본 마스터 테이블
- `brands` - 브랜드 정보
- `categories` - 상품 카테고리  
- `suppliers` - 공급업체
- `stores` - 점포 정보
- `users` - 사용자 계정

### 상품 관리 테이블
- `products` - 상품 정보
- `inventory` - 점포별 재고
- `margin_rules` - 카테고리별 마진 규칙

### 거래 관리 테이블
- `purchases` / `purchase_items` - 구매/입고
- `store_transfers` / `store_transfer_items` - 점간 이동
- `wholesale_sales` / `wholesale_sale_items` - 도매 판매

### 시스템 테이블
- `price_change_history` - 가격 변경 이력
- `system_settings` - 시스템 설정
- `shopping_cart` - 장바구니 (배달 앱용)

## 👤 기본 계정 정보

### Super Admin (전체 관리자)
- **아이디**: admin
- **이메일**: admin@homekmart.com
- **비밀번호**: password
- **권한**: 모든 기능 접근 가능

### Store Admin (점포 관리자)
- **본점 관리자**: store1_admin / store1@homekmart.com
- **분점 관리자**: store2_admin / store2@homekmart.com
- **비밀번호**: password (모든 계정 동일)

⚠️ **보안 중요**: 배포 후 반드시 모든 계정의 비밀번호를 변경하세요!

## 🔍 설치 문제 해결

### 일반적인 오류들

1. **"Table already exists" 오류**
   - 기존 테이블이 있는 경우 발생
   - 해결: `DROP DATABASE if0_39723369_min;` 후 재실행

2. **"Foreign key constraint fails" 오류**
   - 데이터 삽입 순서 문제
   - 해결: complete_schema.sql 먼저 실행, 그 다음 initial_data.sql

3. **"Access denied" 오류**
   - 데이터베이스 권한 문제
   - 해결: 호스팅 제공업체 확인

### 로컬에서 스키마 추출하기 (선택적)

로컬 개발 환경이 있는 경우:

1. **database_setup.php 실행**
   ```
   http://localhost/min/database_setup.php
   ```

2. **생성된 스키마 복사**
   - 화면에 표시된 SQL 코드 복사
   - 운영 서버에 적용

## ✅ 설치 완료 체크리스트

- [ ] complete_schema.sql 실행 완료
- [ ] initial_data.sql 실행 완료  
- [ ] 테이블 20개 이상 생성 확인
- [ ] 기본 계정 3개 생성 확인
- [ ] 샘플 상품 5개 생성 확인
- [ ] 웹사이트에서 로그인 테스트 성공
- [ ] 기본 기능 동작 확인

## 🔧 추가 설정 (선택적)

### 도매 시스템 활성화
```sql
UPDATE system_settings 
SET setting_value = 'true' 
WHERE setting_key = 'enable_wholesale_system';
```

### 배달 시스템 활성화 (미래 확장용)
```sql
UPDATE system_settings 
SET setting_value = 'true' 
WHERE setting_key = 'enable_delivery_system';
```

### 바코드 시스템 비활성화
```sql
UPDATE system_settings 
SET setting_value = 'false' 
WHERE setting_key = 'enable_barcode_system';
```

## 📞 지원

설치 중 문제가 발생하면:
1. 오류 메시지 전체 복사
2. 실행한 SQL 단계 명시
3. 호스팅 환경 정보 확인

---

**🎉 설치 완료 후 다음 단계**: deployment_test.php로 전체 시스템 검증