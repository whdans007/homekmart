# 할인 기능 개선 완료 보고서

## 📌 개요
`edit_purchase.php`에서 할인율 입력 시 할인된 원가와 오리지날 원가를 명확하게 추적할 수 있도록 개선했습니다.

---

## 🎯 구현된 기능

### 1. **새로운 데이터베이스 컬럼**
```sql
-- 추가된 컬럼
ALTER TABLE purchase_items ADD COLUMN original_unit_price DECIMAL(10,2) COMMENT '할인 적용 전 원단가';
ALTER TABLE purchase_items ADD COLUMN discounted_unit_price DECIMAL(10,2) COMMENT '할인 적용 후 원단가';
```

**목적:**
- `original_unit_price`: 할인 적용 전의 원래 단가 저장
- `discounted_unit_price`: 할인율을 적용한 실제 단가 저장
- 두 값의 차이로 할인액 계산 가능

---

### 2. **자동 계산 로직 (백엔드)**

할인율이 입력되거나 변경될 때 자동으로 계산:

```php
// 할인된 단가 = 원단가 × (1 - 할인율/100)
$new_original_unit_price = $new_unit_price;
$new_discounted_unit_price = $new_unit_price * (1 - $discount_rate / 100);
$discounted_total = $original_total * (1 - $discount_rate / 100);
```

**적용되는 경우:**
1. 단일 항목 수정 시 (라인 461-473)
2. 개별 항목 저장 시 (라인 730-743)
3. 일괄 할인 적용 시 (라인 914-929)

---

### 3. **UI 개선 (프론트엔드)**

#### 테이블 헤더 추가
```
... | 원가 | 원가(할인전) | VAT구분 | ... | 할인율 | 할인된단가 | 합계
```

#### 셀 스타일
- **원가(할인 전)**: 회색 배경, 읽기 전용
- **할인된 단가**: 옅은 노란색 배경, 읽기 전용

#### 데이터 표시 예시
```
할인 전: 100원 [단가입력]
            ↓
        원가(할인 전): 100 (회색, 읽기 전용)
        할인율: 10% [입력창]
        할인된 단가: 90 (노란색, 읽기 전용)
        할인 후 합계: 450 (수량 5 × 90)
```

---

### 4. **실시간 계산 (JavaScript)**

할인율 입력 또는 변경 시 즉시 계산:

```javascript
function calculateRowTotal(row) {
    // 1. 할인된 단가 계산
    const discountedUnitPrice = unitPrice * (1 - discountRate / 100);

    // 2. UI 업데이트
    originalPriceElement.textContent = unitPrice;
    discountedUnitPriceElement.textContent = discountedUnitPrice;
    discountedTotalElement.textContent = discountedUnitPrice * quantity;
}
```

**업데이트되는 요소:**
- 오리지날 원가 (현재 입력된 단가로)
- 할인된 단가 (할인율 적용)
- 할인 후 합계 (수량 × 할인된 단가)

---

## 📊 데이터 저장 구조

### 입력 시나리오
```
사용자 입력:
  - 수량: 5
  - 단가: 100
  - 할인율: 10%

자동 저장 데이터:
  - unit_price: 100
  - original_unit_price: 100 (할인 전)
  - discounted_unit_price: 90 (할인 후)
  - discount_rate: 10
  - discounted_total: 450 (할인 후 합계)
```

### 데이터 복원 가능
```
DB에서 역계산:
  - 오리지날 원가: original_unit_price = 100
  - 할인율: discount_rate = 10%
  - 할인액: 100 - 90 = 10 또는 original × discount_rate/100
  - 할인 후 금액: discounted_total = 450
```

---

## 🔧 설치/적용 방법

### 1단계: 데이터베이스 마이그레이션
```bash
mysql -u root -p database_name < add_discount_columns.sql
```

또는 phpMyAdmin에서 `add_discount_columns.sql` 파일 실행

### 2단계: 파일 적용
- `edit_purchase.php` - 이미 수정 완료

### 3단계: 동작 확인
1. 매입 화면 접속
2. 할인율 입력
3. UI에서 자동 계산 확인
4. 저장 후 DB 데이터 확인

---

## 📋 변경된 파일

| 파일 | 변경 내용 |
|------|---------|
| `add_discount_columns.sql` | 새로 생성 - DB 마이그레이션 |
| `admin/edit_purchase.php` | 컬럼 초기화, 백엔드 로직, UI, JavaScript 수정 |

---

## 🔍 주요 기능 체크리스트

- ✅ 할인된 원가 DB 저장
- ✅ 오리지날 원가 DB 저장
- ✅ 할인율 변경 시 자동 계산
- ✅ 단가 변경 시 오리지날 원가 업데이트
- ✅ 일괄 할인 적용 시 컬럼 자동 계산
- ✅ UI에서 할인 전/후 명확히 표시
- ✅ 읽기 전용으로 설정 (실수 방지)
- ✅ 기존 데이터 자동 마이그레이션

---

## 📝 추가 참고사항

### 기존 데이터 처리
```php
// 파일 접속 시 자동으로 마이그레이션 실행
// (라인 203-220)
- NULL 데이터: unit_price로 초기화
- 할인율이 있는 데이터: 자동 계산
- 한 번만 실행되고 이후 스킵
```

### 셀 인덱스 변경
원가(할인 전) 컬럼 추가로 인해:
- 기존 9번 인덱스 → 10번으로 변경
- 할인된 단가: 12번 (새 컬럼)
- JavaScript에서도 인덱스 수정됨 (라인 2513-2535)

### 호환성
- 기존 기능 완전 호환
- 새 컬럼은 NULL 허용 (자동 COALESCE)
- 점진적 마이그레이션 가능

---

## ⚠️ 주의사항

1. **마이그레이션 필수**: SQL 파일 실행 필수
2. **브라우저 캐시**: JS 변경 후 브라우저 캐시 삭제 권장
3. **기존 데이터**: 자동으로 마이그레이션되나, 백업 권장

---

**작성일**: 2025-12-18
**상태**: 완료 (테스트 대기)
