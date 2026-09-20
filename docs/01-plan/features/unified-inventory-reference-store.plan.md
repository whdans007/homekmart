# Plan: 통합 재고 원장 및 Reference Store 기반 점포 운영

**Feature**: unified-inventory-reference-store  
**Status**: Plan  
**Owner**: Claude (재고 도메인) / Codex (관리 UI)  
**Scope**: 매입, POS, 몰 판매, 도매 판매, 크레딧 판매, 점포 이동, 폐기, 실사 조정, 품절 자동 해제

## 1. 목표

점포별 입출고를 하나의 재고 원장으로 통합하고, 저장된 Reference Store 설정을 쇼핑몰 가격·재고·품절 자동화의 기준으로 사용한다.

재고가 음수가 되더라도 0으로 보정하지 않고 실제 계산값을 그대로 저장·표시한다.

## 2. 현행 문제

- `inventory`와 `inventory_expirations`가 실제 점포 재고에 사용된다.
- 매입, 이동, 도매, 폐기 등 여러 화면이 재고를 직접 수정한다.
- POS 업로드와 몰 판매가 재고 원장에 일관되게 연결되어 있지 않다.
- `lib/StockRepository.php`, `lib/StockService.php`는 `stock` 테이블을 사용하는 별도/레거시 구조다.
- `/mall/admin/products.php`의 Reference Store는 현재 URL 필터일 뿐 영구 저장되지 않는다.
- Reference Store가 변경되면 과거 거래를 새 점포 기준으로 재계산해서는 안 된다.

## 3. 범위

### 포함

1. Reference Store 저장 및 변경 이력
2. 공통 재고 원장과 트랜잭션 서비스
3. 박스→낱개 환산
4. 매입 입고 및 확정 시 품절 해제
5. POS `pcs` 판매 차감 및 업로드 중복 방지
6. 몰 판매·취소/환불 재고 반영
7. 점포 이동 출고·입고
8. 도매 판매·반품
9. 크레딧/외상 판매·취소
10. 유통기한 폐기
11. 관리자 실사 조정
12. 음수 재고 표시 및 원인 추적
13. 재고 현황·원장·대사 화면

### 제외 또는 후속

- 신선상품을 일반상품 재고 원장에 완전히 통합하는 작업
- `stock` 레거시 테이블을 즉시 삭제하는 작업
- Reference Store 변경 시 새 점포의 재고를 자동 복사하는 작업
- 음수 재고를 자동으로 0으로 정상화하는 작업

## 4. 핵심 비즈니스 규칙

### 4.1 Reference Store

- 전역 설정 키: `mall_reference_store_id`
- 초기 fallback: 기존 `MALL_STORE_ID`
- 설정 변경 시 `effective_from`을 저장한다.
- 변경 이전의 거래는 기존 점포에 귀속한다.
- 변경 이후의 몰 조회와 자동화만 새 점포에 적용한다.
- 실제 매입·판매·폐기·이동은 항상 원본 거래의 `store_id`로 기록한다.
- 새 Reference Store의 시작 재고는 실사 조정으로 입력한다.

### 4.2 수량

- 모든 원장 수량은 낱개 기준 정수로 저장한다.
- 박스 매입/판매는 `quantity * pieces_per_box`로 환산한다.
- 음수 수량을 허용한다.
- 화면에서 음수 수량을 그대로 표시한다.
- 음수라는 이유만으로 판매·폐기·조정을 차단하지 않는다.

### 4.3 원장 이벤트

| 이벤트 | 설명 |
|---|---|
| `PURCHASE_IN` | 매입확정 입고 |
| `POS_OUT` | POS 매출 |
| `MALL_OUT` | 몰 판매 |
| `WHOLESALE_OUT` | 도매 판매 |
| `CREDIT_OUT` | 크레딧/외상 판매 |
| `TRANSFER_OUT` | 점포 이동 출고 |
| `TRANSFER_IN` | 점포 이동 입고 |
| `DISPOSAL_OUT` | 폐기 |
| `ADJUSTMENT_IN` | 실사 증가 |
| `ADJUSTMENT_OUT` | 실사 감소 |
| `RETURN_IN` | 판매 반품/취소 복구 |
| `REVERSAL_OUT` | 기존 입고 취소 보정 |

### 4.4 중복 처리

원본 거래를 재시도해도 중복 차감하지 않는다. 원장에는 `source_type`, `source_id`, `product_id`, `store_id` 조합에 대한 중복 방지 규칙을 둔다.

## 5. 작업 분담

### Claude

- Plan/Design 확정
- 재고 원장 스키마 및 migration
- `lib/inventory_service.php` 구현
- `lib/inventory_ledger.php` 구현
- Reference Store 서비스 및 이력 처리
- 매입/POS/몰/이동/도매/크레딧/폐기 핵심 트랜잭션 연동
- row lock, rollback, idempotency 검증
- Codex 결과에 대한 최종 code review
- 통합 테스트 및 병합 승인

### Codex

- Reference Store 선택·저장 UI
- 재고 현황 화면
- 원장 조회 화면
- 실사 조정 화면
- 대사 화면
- 음수 재고 표시/필터
- POS 미매칭 SKU 화면
- 메뉴·권한·언어팩 연결
- 브라우저 기반 UI 검증

### 충돌 방지

- 브랜치: `claude/unified-inventory-reference-store`, `codex/unified-inventory-reference-store`
- 공통 핵심 파일은 Claude만 수정한다.
- Codex는 Claude가 확정한 API 계약을 기준으로 화면을 연결한다.
- 두 에이전트가 같은 파일을 동시에 수정하지 않는다.

## 6. 단계별 개발 순서

### Phase 0: 현행 기준선

- 모든 `inventory` 직접 수정 지점 목록화
- `stock` 레거시 구조와의 관계 확인
- 과거 데이터 및 migration 상태 확인
- 이벤트별 원본 거래 ID 확인

**완료 조건**: 기존 중복 차감 지점과 통합 대상 파일 목록이 문서화됨.

### Phase 1: Reference Store

- `system_settings` 저장
- `mall_reference_store_history` migration
- 저장/조회 API
- `products.php` UI 연결

**완료 조건**: 저장 후 재접속해도 유지되고, 변경 전후 조회 경계가 검증됨.

### Phase 2: 공통 재고 기반

- `inventory_ledger` migration
- 공통 입고/출고/조정 함수
- 음수 허용
- 중복 방지
- 로트 보조 처리

**완료 조건**: 독립적인 입고·출고·조정 테스트가 통과함.

### Phase 3: 거래 연동

- 매입확정
- POS 업로드
- 몰 판매/취소
- 점포 이동
- 도매 판매/반품
- 크레딧 판매
- 폐기

**완료 조건**: 각 거래가 한 번만 원장에 반영되고 기존 기능이 rollback 가능함.

### Phase 4: 관리자 화면

- 재고 현황
- 음수 재고
- 원장
- 실사 조정
- POS 미매칭
- 대사 보고서

**완료 조건**: 운영자가 원장과 현재 수량을 화면에서 추적할 수 있음.

### Phase 5: 품절 자동 해제

- Reference Store 매입확정 조건 확인
- `mall_products.is_sold_out` 해제
- 자동 처리 이력 표시

**완료 조건**: 다른 점포에는 영향이 없고, 일반상품만 처리됨.

## 7. 검증 시나리오

1. 박스 매입 2개 × 12개 = `+24`
2. POS 판매가 재고보다 많아 `-3` 발생
3. `-3`이 화면에 그대로 표시
4. 같은 POS 파일 재업로드 시 중복 차감 없음
5. 몰 주문 취소 시 재고 복구
6. 점포 이동에서 출발/도착 수량 일치
7. 도매 반품 시 복구
8. 수기 크레딧 품목은 재고 제외
9. 폐기 시 FIFO 로트 차감
10. 실사 조정 +10 기록
11. Reference Store 변경 전후 거래 점포 분리
12. 매입확정 후 품절 해제
13. 매입확정 재전송 시 중복 해제/입고 없음
14. 트랜잭션 중 오류 시 전체 rollback

## 8. 완료 산출물

- Plan 문서
- Design 문서
- migration 파일
- 공통 재고 서비스
- 거래 연동 코드
- Codex 관리자 화면
- Check 분석 문서
- 최종 Report 문서
- 운영자 재고 조정 가이드
