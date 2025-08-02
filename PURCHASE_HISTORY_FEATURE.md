# 매입 이력 기반 판매가 설정 기능

## 📋 기능 개요

상품 상세 정보 팝업에서 해당 상품의 최근 매입 이력을 확인하고, 이를 기반으로 적정 판매가를 설정할 수 있는 기능입니다. 

## 🎯 주요 기능

### 1. 매입 이력 표시
- 상품 상세 팝업 하단에 "최근 매입 이력" 섹션 표시
- 최신 5개 매입 기록을 표 형식으로 제공
- 매입일, 거래처, 점포, 낱개 원가, 권장 판매가 정보 표시

### 2. 스마트 판매가 제안
- 매입 원가 기준 30% 마진율로 권장 판매가 자동 계산
- 박스 단위/낱개 단위 자동 환산
- 실시간 마진율 계산 및 표시

### 3. 판매가 설정
- 매입 이력 선택 후 판매가 입력 및 적용
- 5% 미만 저마진 경고 시스템
- 즉시 상품 정보 업데이트 반영

## 🔧 기술 구현

### 새로운 파일
- `ajax_get_purchase_history.php`: 매입 이력 조회 API
- `ajax_update_selling_price.php`: 판매가 업데이트 API

### 수정된 파일
- `product_management.php`: UI 컴포넌트 및 JavaScript 기능 추가

### 데이터베이스 구조
기존 테이블 활용:
- `purchases`: 매입 기본 정보
- `purchase_items`: 매입 상품별 상세 정보 
- `suppliers`: 거래처 정보
- `products`: 상품 정보 (판매가 업데이트)

## 🚀 사용 방법

### 1. 매입 이력 확인
1. 상품 관리 페이지에서 상품 행 클릭
2. 상품 상세 팝업이 열리면 하단 "최근 매입 이력" 섹션 확인
3. 매입일, 거래처, 원가 정보 검토

### 2. 판매가 설정
1. 매입 이력 테이블에서 원하는 매입 기록의 "선택" 버튼 클릭
2. 판매가 설정 패널이 활성화됨
3. 자동 계산된 권장 판매가 확인 또는 직접 입력
4. 마진율 확인 후 "적용" 버튼 클릭

### 3. 결과 확인
- 토스트 알림으로 설정 완료 확인
- 상품 상세 정보의 판매가 즉시 업데이트
- 상품 목록에서도 변경된 가격 반영

## ⚡ 기능 특징

### 보안
- 관리자 권한(super_admin, admin) 확인
- SQL 인젝션 방지 (PDO 준비된 문 사용)
- 상품 존재 여부 사전 검증
- 입력값 검증 및 타입 체크

### 사용자 경험
- 로딩 스피너와 상태 표시
- 직관적인 토스트 알림 시스템
- 선택 상태 시각적 피드백
- 저마진 경고 시스템

### 성능
- 최근 5개 이력만 조회하여 성능 최적화
- AJAX 비동기 처리로 페이지 새로고침 없음
- 실시간 계산으로 빠른 피드백

## 📊 데이터 처리

### 매입 이력 조회 쿼리
```sql
SELECT 
    p.purchase_id,
    p.purchase_date,
    s.name as supplier_name,
    pi.unit_price,
    pi.quantity,
    pi.purchase_type,
    pr.pieces_per_box,
    (CASE 
        WHEN pi.purchase_type = 'box' THEN pi.unit_price / COALESCE(pr.pieces_per_box, 1)
        ELSE pi.unit_price
    END) as unit_cost_per_piece
FROM purchase_items pi
JOIN purchases p ON pi.purchase_id = p.purchase_id
JOIN suppliers s ON p.supplier_id = s.id
JOIN products pr ON pi.product_id = pr.id
WHERE pi.product_id = ? 
AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
ORDER BY p.purchase_date DESC, p.purchase_id DESC
LIMIT 5
```

### 판매가 업데이트 쿼리
```sql
UPDATE products 
SET selling_price = ?, updated_at = NOW(), last_modified_by_user_id = ?
WHERE id = ?
```

## 🔍 오류 처리

### API 레벨
- 권한 검증 실패
- 필수 파라미터 누락
- 잘못된 데이터 타입
- 데이터베이스 연결 오류
- 상품 존재 여부 확인

### UI 레벨
- 네트워크 연결 오류
- 서버 응답 오류
- 사용자 입력 검증
- 마진율 경고 시스템

## 📝 로그 및 모니터링

- 모든 판매가 변경 이력은 `products.updated_at` 및 `last_modified_by_user_id`로 추적
- JavaScript 콘솔에 상세 오류 로그 기록
- 사용자 친화적인 토스트 알림으로 상태 피드백

이 기능을 통해 사용자는 매입 원가를 기반으로 합리적인 판매가를 효율적으로 설정할 수 있습니다.