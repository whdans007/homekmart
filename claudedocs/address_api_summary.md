# 배달 주소 API 개발 완료 요약

## 📋 개요

필리핀 배달 앱을 위한 배달 주소 관리 API를 MySQLi로 개발 완료했습니다.

**개발 일시**: 2025-10-04
**개발자**: Claude
**베이스 URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/addresses/`

## ✅ 완성된 API 엔드포인트

### 1. 주소 목록 조회 (List)
- **파일**: `list.php`
- **메소드**: GET
- **URL**: `/api/addresses/list.php?user_id={user_id}`
- **기능**: 사용자의 활성화된 모든 배달 주소 조회
- **정렬**: 기본 주소 우선 (is_default DESC), 생성일 역순

**요청 예시**:
```
GET /api/addresses/list.php?user_id=13
```

**응답 예시**:
```json
{
    "success": true,
    "message": "Addresses retrieved successfully",
    "data": [
        {
            "id": 1,
            "address_name": "집",
            "house_number": "123",
            "street": "Rizal Avenue",
            "barangay": "Poblacion",
            "city": "Makati",
            "province": "Metro Manila",
            "postal_code": "1200",
            "detailed_address": "2층 오른쪽 문",
            "landmark": "세븐일레븐 옆",
            "delivery_notes": "문 앞에 놔주세요",
            "latitude": 14.5547,
            "longitude": 121.0244,
            "is_default": true,
            "is_active": true,
            "created_at": "2025-10-04 14:23:15",
            "updated_at": "2025-10-04 14:23:15"
        }
    ]
}
```

### 2. 주소 생성 (Create)
- **파일**: `create.php`
- **메소드**: POST
- **URL**: `/api/addresses/create.php`
- **기능**: 새로운 배달 주소 추가, 기본 주소 설정 지원

**요청 본문**:
```json
{
    "user_id": 13,
    "address_name": "집",
    "house_number": "123",
    "street": "Rizal Avenue",
    "barangay": "Poblacion",
    "city": "Makati",
    "province": "Metro Manila",
    "postal_code": "1200",
    "detailed_address": "2층 오른쪽 문",
    "landmark": "세븐일레븐 옆",
    "delivery_notes": "문 앞에 놔주세요",
    "latitude": 14.5547,
    "longitude": 121.0244,
    "is_default": false
}
```

**필수 필드**:
- `user_id` - 사용자 ID
- `address_name` - 주소 별칭
- `street` - 거리명
- `barangay` - 바랑가이 (필리핀 최소 행정구역)
- `city` - 도시
- `province` - 주/도

**선택 필드**:
- `house_number` - 집 번호
- `postal_code` - 우편번호
- `detailed_address` - 상세 주소
- `landmark` - 랜드마크
- `delivery_notes` - 배달 메모
- `latitude` - 위도 (GPS)
- `longitude` - 경도 (GPS)
- `is_default` - 기본 주소 여부 (기본값: false)

**비즈니스 로직**:
1. `is_default`가 true인 경우, 기존 기본 주소를 자동으로 해제
2. 기본 주소로 설정 시 `users.default_delivery_address_id` 자동 업데이트
3. 트랜잭션으로 데이터 무결성 보장

### 3. 주소 수정 (Update)
- **파일**: `update.php`
- **메소드**: PUT
- **URL**: `/api/addresses/update.php?id={address_id}&user_id={user_id}`
- **기능**: 기존 주소 정보 수정, 동적 필드 업데이트

**요청 본문** (수정할 필드만 포함):
```json
{
    "address_name": "집 (수정됨)",
    "delivery_notes": "초인종 눌러주세요",
    "landmark": "GS25 편의점 맞은편"
}
```

**수정 가능한 필드**:
- `address_name`, `house_number`, `street`, `barangay`, `city`, `province`
- `postal_code`, `detailed_address`, `landmark`, `delivery_notes`
- `latitude`, `longitude`, `is_default`

**보안**:
- 주소 소유권 확인 (user_id 검증)
- 다른 사용자의 주소 수정 불가

### 4. 기본 주소 설정 (Set Default)
- **파일**: `set-default.php`
- **메소드**: POST
- **URL**: `/api/addresses/set-default.php?id={address_id}&user_id={user_id}`
- **기능**: 특정 주소를 기본 배달 주소로 설정

**요청 예시**:
```
POST /api/addresses/set-default.php?id=2&user_id=13
```

**응답 예시**:
```json
{
    "success": true,
    "message": "Default address updated successfully",
    "data": {
        "address_id": 2
    }
}
```

**자동 처리**:
1. 기존 기본 주소 해제 (is_default = 0)
2. 선택한 주소를 기본으로 설정 (is_default = 1)
3. `users.default_delivery_address_id` 업데이트

### 5. 주소 삭제 (Delete - Soft Delete)
- **파일**: `delete.php`
- **메소드**: DELETE
- **URL**: `/api/addresses/delete.php?id={address_id}&user_id={user_id}`
- **기능**: 주소 소프트 삭제 (복구 가능)

**요청 예시**:
```
DELETE /api/addresses/delete.php?id=2&user_id=13
```

**응답 예시**:
```json
{
    "success": true,
    "message": "Address deleted successfully"
}
```

**Soft Delete 방식**:
- 실제 데이터 삭제 없음
- `is_active = 0`으로 설정
- 주소 목록 조회 시 제외됨
- 필요 시 복구 가능

**자동 처리**:
- 기본 주소 삭제 시 `users.default_delivery_address_id = NULL`로 설정

## 🏗️ 기술 스택

### Backend
- **언어**: PHP 8.2
- **데이터베이스**: MariaDB 10
- **연결 방식**: MySQLi (트랜잭션 지원)
- **응답 형식**: JSON (UTF-8)
- **CORS**: 모든 도메인 허용 (테스트용)

### 데이터베이스 테이블: `delivery_addresses`
```sql
CREATE TABLE delivery_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    address_name VARCHAR(100) NOT NULL,
    house_number VARCHAR(50),
    street VARCHAR(255) NOT NULL,
    barangay VARCHAR(100) NOT NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NOT NULL,
    postal_code VARCHAR(20),
    detailed_address TEXT,
    landmark VARCHAR(255),
    delivery_notes TEXT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, is_active),
    INDEX idx_user_default (user_id, is_default)
);
```

## 🎯 필리핀 주소 형식 특징

### 주소 구조
```
House Number + Street, Barangay, City, Province, Postal Code
예: 123 Rizal Avenue, Poblacion, Makati, Metro Manila, 1200
```

### 주요 필드 설명
1. **Barangay** (바랑가이)
   - 필리핀의 최소 행정구역
   - 한국의 동/리에 해당
   - 필수 필드

2. **Landmark** (랜드마크)
   - 필리핀에서 중요한 위치 정보
   - 배달원이 주소 찾는 데 활용
   - 예: "세븐일레븐 옆", "맥도날드 건너편"

3. **GPS 좌표**
   - 정확한 배달을 위해 위도/경도 저장
   - 선택 사항이지만 권장

## 🧪 테스트

### 웹 테스트 인터페이스
- **파일**: `test_addresses.html`
- **URL**: `https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/test_addresses.html`
- **기능**: 5개 API 모두 브라우저에서 테스트 가능

### 테스트 사용자
- **user_id**: 13
- **username**: testuser
- **store_id**: 1

## 🔒 보안 고려사항

### 현재 구현 (테스트용)
- ✅ SQL Injection 방지 (real_escape_string)
- ✅ 트랜잭션 처리
- ✅ 소유권 검증 (user_id)
- ❌ 인증 없음 (user_id를 파라미터로 받음)

### 프로덕션 배포 시 필요한 개선사항
1. **JWT 인증** 추가
   - Authorization 헤더에서 토큰 검증
   - 토큰에서 user_id 추출
   - 파라미터로 받는 user_id 제거

2. **CORS 제한**
   - 특정 도메인만 허용
   - 프로덕션 앱 도메인만 화이트리스트

3. **Rate Limiting**
   - API 호출 횟수 제한
   - DDoS 방어

4. **입력 검증 강화**
   - 위도/경도 범위 검증
   - 우편번호 형식 검증
   - 최대 길이 제한

## 📊 API 응답 형식

### 성공 응답
```json
{
    "success": true,
    "message": "작업 성공 메시지",
    "data": { /* 데이터 객체 또는 배열 */ }
}
```

### 실패 응답
```json
{
    "success": false,
    "error": {
        "message": "에러 메시지"
    }
}
```

### HTTP 상태 코드
- **200**: 성공
- **400**: 잘못된 요청 (필수 필드 누락, 검증 실패)
- **404**: 리소스 없음 (주소 ID 없음, 소유권 없음)
- **405**: 메소드 허용 안 됨 (GET/POST/PUT/DELETE 불일치)
- **500**: 서버 에러

## 🚀 다음 단계

배달 주소 API 개발이 완료되었습니다. 다음 작업 후보:

### 1. Flutter 앱 통합 ⭐ 추천
- Address API를 Flutter 앱에 통합
- Provider 패턴으로 상태 관리
- UI 화면 개발 (주소 목록, 추가, 수정)

### 2. Flutter 컴파일 에러 수정
- 현재 60개 이상의 컴파일 에러 발생
- 주요 이슈:
  - Service 생성자 파라미터 불일치
  - Model 필드 이름 불일치 (productId, stockQuantity, formattedPrice 등)
  - ThemeConfig.textSecondary 누락
  - Provider 메소드 시그니처 불일치

### 3. 결제 API 개발
- COD (Cash on Delivery) 처리
- 결제 상태 관리
- 영수증 생성

### 4. 배달원 추적 API
- 실시간 위치 업데이트
- 배달 상태 변경
- GPS 트래킹

## 📝 파일 목록

### API 파일
- `api/addresses/list.php` - 주소 목록 조회
- `api/addresses/create.php` - 주소 생성
- `api/addresses/update.php` - 주소 수정
- `api/addresses/delete.php` - 주소 삭제
- `api/addresses/set-default.php` - 기본 주소 설정

### 테스트 파일
- `api/test_addresses.html` - 웹 테스트 인터페이스

### 문서
- `claudedocs/address_api_summary.md` - 이 문서

## ✅ 체크리스트

- [x] List API 개발
- [x] Create API 개발
- [x] Update API 개발
- [x] Delete API 개발
- [x] Set Default API 개발
- [x] 테스트 인터페이스 생성
- [x] API 문서 작성
- [ ] Flutter 앱 통합
- [ ] 프로덕션 보안 강화
- [ ] 배포

---

**작성일**: 2025-10-04
**작성자**: Claude
**상태**: ✅ 개발 완료, 테스트 준비됨
