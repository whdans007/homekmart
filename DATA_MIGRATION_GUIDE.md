# 🔄 HOME K MART 데이터 마이그레이션 가이드

## 📋 개요

이 가이드는 로컬 테스트 환경의 모든 데이터를 InfinityFree 운영 서버로 완전히 이전하는 방법을 설명합니다.

## ⚠️ 중요 주의사항

- **이 과정은 운영 서버의 기존 데이터를 모두 삭제합니다**
- 운영 서버에 중요한 데이터가 있다면 사전에 백업하세요
- 마이그레이션 중에는 서비스가 중단될 수 있습니다

## 🛠️ 마이그레이션 도구

### 1. 웹 기반 도구 (권장)
- **`data_migration_tool.php`** - 브라우저에서 실행하는 마이그레이션 도구
- 접속: `http://localhost/min/data_migration_tool.php`

### 2. 명령줄 도구 (고급 사용자용)
- **`create_migration_dump.bat`** - Windows 배치 스크립트
- mysqldump를 사용한 전문가용 도구

### 3. 마이그레이션 후 도우미
- **`migration_helper.php`** - 운영 서버에서 마이그레이션 상태 확인

## 📊 1단계: 로컬 데이터 백업 생성

### 방법 A: 웹 도구 사용 (권장)

1. **로컬 서버 시작**
   - XAMPP 또는 WAMP 시작
   - Apache, MySQL 서비스 실행

2. **마이그레이션 도구 실행**
   ```
   http://localhost/min/data_migration_tool.php
   ```

3. **백업 파일 생성**
   - 자동으로 모든 테이블과 데이터 분석
   - `sql/full_migration_[날짜시간].sql` 파일 생성
   - 다운로드 링크 제공

### 방법 B: 배치 스크립트 사용

1. **명령 프롬프트 실행**
   ```cmd
   cd Z:\min
   create_migration_dump.bat
   ```

2. **자동 백업 생성**
   - MySQL 도구 자동 감지
   - 운영 서버용 SQL 파일 생성

### 생성되는 파일 구조

```sql
-- 데이터베이스 재생성
DROP DATABASE IF EXISTS `if0_39723369_min`;
CREATE DATABASE `if0_39723369_min`;

-- 테이블 구조 생성
CREATE TABLE stores (...);
CREATE TABLE users (...);
-- ... 모든 테이블

-- 데이터 삽입
INSERT INTO stores VALUES (...);
INSERT INTO users VALUES (...);
-- ... 모든 데이터
```

## 🚀 2단계: 운영 서버에 데이터 이전

### InfinityFree phpMyAdmin 접속

1. **제어판 로그인**
   - InfinityFree 계정 로그인
   - 호스팅 관리 페이지 이동

2. **데이터베이스 관리**
   - "MySQL Databases" 클릭
   - phpMyAdmin 접속

3. **데이터베이스 선택**
   - `if0_39723369_min` 선택

### SQL 파일 실행

1. **SQL 탭 선택**
   - phpMyAdmin 상단의 "SQL" 탭 클릭

2. **마이그레이션 SQL 실행**
   - 생성된 SQL 파일 내용 전체 복사
   - SQL 쿼리 박스에 붙여넣기
   - "실행" 또는 "Go" 버튼 클릭

3. **실행 결과 확인**
   ```
   Migration completed successfully!
   stores_count: X
   users_count: X
   products_count: X
   inventory_count: X
   ```

### 큰 파일 처리 방법

SQL 파일이 너무 클 경우:

1. **분할 실행**
   - CREATE TABLE 구문들만 먼저 실행
   - INSERT 구문들을 나누어서 실행

2. **설정 확인**
   - phpMyAdmin 업로드 제한 확인
   - `max_allowed_packet` 설정 확인

## 🔍 3단계: 마이그레이션 검증

### 웹 도구로 검증

1. **마이그레이션 도우미 실행**
   ```
   https://homekmart.rf.gd/migration_helper.php
   ```

2. **상태 확인 항목**
   - 테이블 생성 여부
   - 데이터 개수 확인
   - 사용자 계정 상태
   - 점포 정보 확인
   - 상품/재고 현황

### 시스템 전체 검증

1. **배포 테스트 실행**
   ```
   https://homekmart.rf.gd/deployment_test.php
   ```

2. **로그인 테스트**
   ```
   https://homekmart.rf.gd/public/login.php
   ```

### 이전된 계정 정보

마이그레이션 후 사용 가능한 계정들:

```
# 로컬에서 생성한 모든 사용자 계정이 이전됩니다
# 기본 비밀번호는 로컬과 동일합니다
```

## 🔧 4단계: 마이그레이션 후 설정

### 보안 설정

1. **비밀번호 변경**
   - migration_helper.php에서 "비밀번호 재설정" 클릭
   - 또는 각 계정별로 수동 변경

2. **임시 파일 삭제**
   ```bash
   # 마이그레이션 완료 후 삭제 권장
   rm data_migration_tool.php
   rm migration_helper.php
   rm DATABASE_SETUP_GUIDE.md
   rm DATA_MIGRATION_GUIDE.md
   ```

### 운영 환경 최적화

1. **시스템 설정 업데이트**
   - migration_helper.php에서 "운영 환경 설정 적용"

2. **성능 모니터링**
   - 초기 로그 확인
   - 응답 시간 체크

## 📊 예상 마이그레이션 데이터

### 기본 마스터 데이터
- **브랜드**: 로컬에서 생성한 모든 브랜드
- **카테고리**: 로컬에서 생성한 모든 카테고리
- **공급업체**: 로컬에서 생성한 모든 공급업체
- **점포**: 로컬에서 생성한 모든 점포

### 운영 데이터
- **사용자**: 로컬의 모든 사용자 계정
- **상품**: 로컬의 모든 상품 정보
- **재고**: 점포별 재고 현황
- **구매내역**: 모든 구매/입고 기록

### 거래 데이터
- **점간이동**: 모든 점간 이동 기록
- **도매판매**: 도매 거래 기록 (있는 경우)
- **가격변경**: 가격 변경 이력

## 🆘 문제 해결

### 일반적인 오류

1. **"Table already exists" 오류**
   ```sql
   DROP DATABASE IF EXISTS `if0_39723369_min`;
   ```
   위 명령으로 기존 DB 삭제 후 재시도

2. **"MySQL server has gone away" 오류**
   - SQL 파일을 작은 단위로 분할 실행
   - 테이블 생성과 데이터 삽입을 분리

3. **"Foreign key constraint fails" 오류**
   - SQL 파일 시작 부분의 `SET FOREIGN_KEY_CHECKS = 0;` 확인
   - 테이블 생성 순서 확인

### 로그 및 디버깅

1. **오류 로그 확인**
   - phpMyAdmin 하단의 오류 메시지 확인
   - InfinityFree 제어판의 Error Logs 확인

2. **부분 실행 테스트**
   ```sql
   -- 테이블별 개수 확인
   SELECT COUNT(*) FROM users;
   SELECT COUNT(*) FROM products;
   SELECT COUNT(*) FROM inventory;
   ```

## ✅ 마이그레이션 완료 체크리스트

- [ ] 로컬 데이터 백업 생성 완료
- [ ] 운영 서버에 SQL 실행 완료
- [ ] 모든 테이블 생성 확인
- [ ] 데이터 개수 일치 확인
- [ ] 로그인 기능 정상 동작
- [ ] 주요 기능 테스트 완료
- [ ] 비밀번호 보안 설정 완료
- [ ] 임시 파일 정리 완료

## 📞 지원

마이그레이션 중 문제 발생시:

1. **오류 메시지 전체 복사**
2. **실행한 단계 명시**
3. **로컬 및 운영 환경 정보 확인**

---

**🎉 마이그레이션 완료 후**: 전체 시스템이 로컬과 동일하게 운영됩니다!