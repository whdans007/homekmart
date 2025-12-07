# 바코드 검색 수정 완료

## 문제점
바코드 `8809344668882`로 상품 검색 시 다음 오류 발생:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'p.cost_price' in 'field list'
```

## 원인
`products` 테이블에는 `cost_price` 컬럼이 없습니다. `cost_price`는 `inventory` 테이블에만 존재합니다.

SQL 쿼리에서 `COALESCE(i.cost_price, p.cost_price)`를 사용했으나, `p.cost_price`는 존재하지 않는 컬럼이었습니다.

## 수정 내용

### 수정된 파일
1. `admin/ajax_get_wholesale_product_by_barcode.php` - 메인 바코드 검색 AJAX 엔드포인트
2. `admin/test_barcode_debug.php` - 디버그 테스트 파일

### 변경 사항
```sql
-- 수정 전
COALESCE(i.cost_price, p.cost_price) as cost_price,

-- 수정 후
i.cost_price,
```

이 변경은 다음 두 쿼리에 적용되었습니다:
- 1차 검색: 기본 SKU로 검색하는 쿼리 (53-78줄)
- 2차 검색: 도매 SKU JSON 배열에서 검색하는 쿼리 (113-146줄)

## 테스트 방법

### 방법 1: 실제 페이지에서 테스트
1. `https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/admin/wholesale_sales.php` 접속
2. "상품 검색" 필드에 `8809344668882` 입력
3. Enter 키 누름
4. 상품이 장바구니에 추가되는지 확인

### 방법 2: AJAX 테스트 페이지
1. `https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/admin/test_barcode_ajax.php` 접속
2. 바코드 필드에 `8809344668882` 입력
3. "검색" 버튼 클릭
4. 결과에 `"success": true` 표시되는지 확인

### 방법 3: 디버그 페이지
1. `https://192-168-0-138.philsarang.direct.quickconnect.to/homekmart/admin/test_barcode_debug.php?barcode=8809344668882&store_id=1` 접속
2. JSON 응답에서 `"success": true` 확인
3. `product_data` 섹션에 상품 정보가 표시되는지 확인

## 예상 결과

바코드 `8809344668882`로 검색 시:
- 상품 ID: 14768
- 상품명: (데이터베이스에서 조회)
- SKU: 8809344668882
- 원가: inventory 테이블의 cost_price 값
- 제안 도매가: 원가 × 1.15 (15% 마진)
- 상태: 'unregistered' (도매상품으로 미등록)

## 추가 정보

- 도매상품으로 등록되지 않은 상품은 현재 점포의 원가에 설정된 마진율(기본 15%)을 적용하여 도매가를 자동 계산합니다.
- 마진율은 wholesale_sales.php 페이지에서 "도매 마진율" 필드로 조정 가능합니다.
- 도매상품으로 이미 등록된 상품은 등록된 도매가를 우선 사용합니다.
