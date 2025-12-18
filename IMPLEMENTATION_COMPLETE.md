# 할인 기능 개선 - 최종 구현 완료 보고서

**완료 날짜**: 2025-12-18
**상태**: ✅ 완전 완료 및 실행됨

---

## 📋 요구사항 분석

### 원래 요청사항
1. ✅ **할인된 원가 저장**: 할인을 적용한 실제 원가를 DB에 저장
2. ✅ **오리지날 원가 표시**: 저장된 데이터에서 오리지날 원가를 계산해서 표시
3. ✅ **명확한 정보 제공**: UI에서 "오리지날 원가 → 할인율 → 할인된 원가" 관계 시각화

---

## 🎯 구현 완료 사항

### Phase 1: 데이터베이스 스키마 확장 ✅

**새 컬럼 추가:**
```
original_unit_price    DECIMAL(10,2)  -- 할인 적용 전 원단가
discounted_unit_price  DECIMAL(10,2)  -- 할인 적용 후 원단가
```

**마이그레이션 결과:**
- ✅ `original_unit_price` 컬럼: 이미 존재
- ✅ `discounted_unit_price` 컬럼: 새로 추가 (8512개 행)
- ✅ 기존 데이터 자동 마이그레이션 완료

---

### Phase 2: 백엔드 로직 개선 ✅

**자동 계산 로직 구현:**

| 시나리오 | 코드 위치 | 기능 |
|---------|---------|------|
| 수정 항목 저장 | 라인 461-473 | 할인율 입력 시 단가 자동 계산 |
| 개별 저장 | 라인 730-743 | 단가 변경 후 할인 계산 |
| 일괄 할인 | 라인 914-929 | 다중 선택 후 일괄 할인 적용 |

**계산 공식:**
```php
original_unit_price = current unit_price
discounted_unit_price = unit_price × (1 - discount_rate/100)
discounted_total = discounted_unit_price × quantity
```

---

### Phase 3: UI/UX 개선 ✅

**테이블 컬럼 구조:**
```
순번 | SKU | 상품명 | ... | 단가 | 원가(할인전) | VAT | ... | 할인율 | 할인된단가 | 합계
```

**시각적 표현:**
- **원가(할인 전)**: 회색 배경 (text-gray-400), 읽기 전용
- **할인된 단가**: 옅은 노란색 배경 (bg-amber-50), 읽기 전용

**데이터 표시 예시:**
```
원단가: 100원 ───────────┐
                         ├→ 할인율: 10% [입력]
                         ├→ 할인된 단가: 90원 (읽기 전용)
                         └→ 합계: 450원 (수량 5 × 90)
```

---

### Phase 4: JavaScript 실시간 계산 ✅

**동작 흐름:**
```javascript
사용자 입력 (할인율)
    ↓
calculateRowTotal() 호출
    ↓
discounted_unit_price = unit_price × (1 - discount_rate/100) 계산
    ↓
UI 업데이트 (3개 셀)
    - original_unit_price (현재 단가)
    - discounted_unit_price (계산값)
    - discounted_total (최종 합계)
    ↓
trackChange() 호출 → 변경사항 저장
```

**영향받는 코드:**
- 라인 2463-2536: `calculateRowTotal()` 함수 완전 재작성
- 라인 1903-1923: `trackChange()` 함수 확장
- 라인 1975-1989: hidden 필드에 새 값 포함

---

## 📊 데이터 저장 구조

### 입력 예시
```
사용자 입력:
  - 수량: 5개
  - 단가: 100원
  - 할인율: 10%
```

### 자동 계산 & 저장
```
자동 계산:
  - original_unit_price = 100 (입력된 단가)
  - discounted_unit_price = 90 (100 × 0.9)
  - discounted_total = 450 (90 × 5)

DB 저장:
  unit_price: 100
  original_unit_price: 100 ← NEW
  discounted_unit_price: 90 ← NEW
  discount_rate: 10
  discounted_total: 450
```

### 데이터 추적 가능성
```
필드만으로 모든 정보 복원:
  ✓ 오리지날 단가: original_unit_price = 100
  ✓ 할인율: discount_rate = 10%
  ✓ 할인액: 100 - 90 = 10 또는 100 × 10% = 10
  ✓ 할인 후 금액: discounted_total = 450
  ✓ 수량: discounted_total ÷ discounted_unit_price = 5
```

---

## 🔧 마이그레이션 실행 결과

### SQL 실행 로그
```
✅ original_unit_price: 이미 존재 (스킵)
✅ discounted_unit_price: 새로 추가 (8512 행)
✅ 기존 데이터 마이그레이션: 진행 완료
⚠️ Data truncation 경고: 정상 (소수점 2자리로 자동 반올림)
```

### 데이터 마이그레이션 정책
```sql
-- 할인이 없는 경우
original_unit_price = unit_price
discounted_unit_price = unit_price

-- 할인이 있는 경우
original_unit_price = unit_price
discounted_unit_price = unit_price × (1 - discount_rate/100)
```

---

## 📁 변경된 파일 목록

| 파일 | 변경 내용 | 라인 |
|------|---------|------|
| **add_discount_columns.sql** | 데이터베이스 마이그레이션 | - |
| **edit_purchase.php** | 컬럼 초기화 | 191-220 |
| **edit_purchase.php** | 데이터 조회 쿼리 | 1174-1176 |
| **edit_purchase.php** | 테이블 헤더 | 1431, 1437 |
| **edit_purchase.php** | 테이블 행 (UI) | 1562-1565, 1595-1598 |
| **edit_purchase.php** | 백엔드 할인 계산 (1) | 461-473 |
| **edit_purchase.php** | 백엔드 할인 계산 (2) | 730-743 |
| **edit_purchase.php** | 백엔드 할인 계산 (3) | 914-929 |
| **edit_purchase.php** | JavaScript 계산 | 2463-2536 |
| **edit_purchase.php** | JavaScript 추적 | 1903-1923 |
| **edit_purchase.php** | JavaScript 저장 | 1975-1989 |

---

## ✅ 기능 검증 체크리스트

### 데이터베이스
- ✅ `original_unit_price` 컬럼 존재
- ✅ `discounted_unit_price` 컬럼 존재
- ✅ 기존 데이터 자동 마이그레이션
- ✅ NULL 값 적절히 처리

### 백엔드
- ✅ 할인율 입력 시 자동 계산
- ✅ 3가지 시나리오 모두 적용 (수정, 저장, 일괄)
- ✅ 데이터 정합성 유지
- ✅ POST 데이터 수신 및 처리

### 프론트엔드
- ✅ 새 컬럼 테이블 헤더 표시
- ✅ 오리지날 원가 셀 표시 (회색, 읽기 전용)
- ✅ 할인된 단가 셀 표시 (노란색, 읽기 전용)
- ✅ 적절한 스타일 적용

### JavaScript
- ✅ 할인율 입력 시 실시간 계산
- ✅ 3개 셀 동시 업데이트
- ✅ hidden 필드에 값 저장
- ✅ 페이지 로드 시 초기 값 표시

---

## 🚀 사용 방법

### 1단계: 마이그레이션 (이미 완료 ✅)
```bash
# 이미 실행됨
mysql -u user -p database_name < add_discount_columns.sql
```

### 2단계: 페이지 접속
```
https://[server]/homekmart/admin/edit_purchase.php?id=[purchase_id]
```

### 3단계: 할인율 입력
```
1. 상품 행에서 할인율(%) 입력
2. 엔터 또는 다른 필드로 이동
3. 자동으로 계산됨:
   - 원가(할인 전) 표시
   - 할인된 단가 계산
   - 합계 업데이트
```

### 4단계: 저장
```
- "변경사항 저장" 버튼 클릭
- DB에 모든 데이터 저장:
  * unit_price
  * original_unit_price (NEW)
  * discounted_unit_price (NEW)
  * discount_rate
  * discounted_total
```

---

## 📈 성능 영향

| 항목 | 영향 |
|------|------|
| 페이지 로드 | 최소화 (계산은 클라이언트에서) |
| 저장 속도 | 약간 증가 (컬럼 2개 추가) |
| 메모리 사용 | 무시할 수준 |
| DB 크기 | 약 160KB 증가 (8512 × 2 × 10bytes) |

---

## 🔍 고급 기능

### 데이터 감사 (Audit)
새 컬럼으로 인해 가능한 것들:
```sql
-- 할인 패턴 분석
SELECT discount_rate, COUNT(*) as count
FROM purchase_items
WHERE discount_rate > 0
GROUP BY discount_rate
ORDER BY count DESC;

-- 할인액 통계
SELECT
    AVG(original_unit_price - discounted_unit_price) as avg_discount_amount,
    SUM(original_unit_price - discounted_unit_price) as total_discount_amount
FROM purchase_items
WHERE discount_rate > 0;

-- 데이터 검증
SELECT COUNT(*) as errors
FROM purchase_items
WHERE discount_rate > 0
AND ABS(
    (discounted_unit_price * quantity) - discounted_total
) > 0.01;
```

---

## ⚠️ 주의사항

1. **브라우저 캐시**: CSS/JS 변경 후 `Ctrl+F5` 강력 새로고침
2. **기존 데이터**: 안전하게 마이그레이션되었으나 백업 권장
3. **정밀도**: 소수점 2자리까지만 저장 (금융 데이터 표준)

---

## 📝 향후 개선 사항 (선택사항)

```
[ ] 할인 이력 추적 테이블
[ ] 할인 규칙 자동 적용 (카테고리별)
[ ] 할인 리포트 대시보드
[ ] 할인율 검증 (최대값 제한 등)
[ ] 할인 원인 분류 (프로모션, 손상 등)
```

---

## 📞 지원 정보

**문제 발생 시:**
1. 브라우저 콘솔 확인 (F12)
2. DB 로그 확인
3. `DISCOUNT_FEATURE_SUMMARY.md` 참조

**검증 쿼리:**
```sql
-- 마이그레이션 상태
SELECT
    COUNT(*) as total,
    SUM(CASE WHEN original_unit_price IS NULL THEN 1 ELSE 0 END) as null_original,
    SUM(CASE WHEN discounted_unit_price IS NULL THEN 1 ELSE 0 END) as null_discounted
FROM purchase_items;
```

---

**최종 상태: ✅ 완전 구현 및 테스트 완료**

모든 요구사항이 충족되었으며, 데이터베이스 마이그레이션도 완료되었습니다.
이제 시스템은 할인된 원가를 추적하고 오리지날 원가를 복원할 수 있습니다!
