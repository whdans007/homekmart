---
template: plan-plus
version: 1.0
---

# mall-fresh-products Planning Document

> **Summary**: 몰(쇼핑몰) 전용 신선상품(과일/채소/정육/수산물) 무게 주문 및 점포별 상품코드 통합 관리 체계
>
> **Project**: HOME K MART 관리 프로그램
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-09-05
> **Status**: Draft
> **Method**: Plan Plus (Brainstorming-Enhanced PDCA)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 과일/채소/정육/수산물 같은 무게(저울) 상품은 정가상품과 달리 100g 단위 주문과 실측 후 가격 확정이 필요한데, 몰은 점포별로 상품코드가 다른 정가상품 구조(`products`/`mall_products`)만 지원해 신선상품을 등록·매입·원가관리할 방법이 없음 |
| **Solution** | 기존 `products`/`inventory`/`purchase_items`/`mall_products`는 전혀 건드리지 않고, 몰 전용 신선상품 마스터 + 점포코드 매핑 + 별도 매입원가 테이블을 신설해 완전히 분리된 신선상품 전용 레이어로 처리 |
| **Function/UX Effect** | 고객은 100g 단위로 원하는 양을 주문(예상금액 표시) → 점포 직원이 준비중 단계에서 실측 무게를 admin PC 화면에 입력 → 확정금액 자동 계산 → 배송 시 현금(COD)으로 정산 |
| **Core Value** | 기존 정가상품 운영에 영향을 주지 않으면서 신선상품 카테고리를 몰에 안전하게 추가하고, 점포마다 다른 코드를 자동 매칭으로 흡수해 원가·마진을 몰 대표코드 단위로 관리 |

---

## 1. User Intent Discovery

### 1.1 Core Problem

이번 기획은 세 가지 문제를 하나의 설계로 동시에 해결한다 (사용자 선택: "세 가지 모두 한 번에"):
1. 점포마다 다른 신선상품 코드를 몰 대표 코드 하나로 통합
2. 고객이 100g 단위로 원하는 양을 주문하고, 실제 준비 후 실측 무게/가격을 입력하는 흐름
3. 몰 전용 신선상품의 매입 원가 관리 (점포 매입과 연동)

### 1.2 Target Users

| User Type | Usage Context | Key Need |
|-----------|---------------|----------|
| 몰 고객 | 몰 프론트에서 신선상품 상세페이지 조회/주문 | 100g 단위로 원하는 양만큼 주문하고 싶음 |
| 점포 직원 | admin PC 화면에서 신선상품 매입 등록, 준비중 주문 실측 입력 | 매입 시 무게/원가 기록, 준비중 주문에서 실제 계량값 입력 |
| 몰 관리자 | 신선상품 마스터/점포 매핑/원가 리포트 관리 | 점포별로 다른 코드를 몰 대표코드로 통합 관리하고 원가·마진을 파악 |
| 배송 기사 | 배송 시 확정금액 기준 현금 수령 | 실측 후 확정된 최종 금액을 정확히 파악 |

### 1.3 Success Criteria

- [ ] 고객이 신선상품 상세페이지에서 100g 단위로 수량을 선택해 주문할 수 있다
- [ ] 점포 직원이 준비중 주문에서 실제 무게를 입력하면 확정금액이 자동 계산된다
- [ ] 점포마다 다른 상품코드가 매입 등록 시 자동 매칭 후보로 제안되고, 관리자가 1회 확인하면 이후 자동 인식된다
- [ ] 기존 `products`/`inventory`/`purchase_items`/`mall_products` 테이블과 화면은 전혀 변경되지 않는다
- [ ] 몰 대표코드별 매입원가 대비 판매단가 마진율을 리포트에서 확인할 수 있다

### 1.4 Constraints

| Constraint | Details | Impact |
|------------|---------|--------|
| 기존 상품 데이터 무변경 | `products`/`inventory`/`purchase_items`/`mall_products` 스키마·쿼리 변경 금지 | High |
| 점포별 신선상품 코드 상이 | 점포마다 같은 신선상품이 서로 다른 `products.id`/바코드를 가짐 | High |
| 결제방식 = 현금(COD) | 필리핀 클락/앙헬레스 배송권역, PG 자동 재청구 없이 배송 시 현금 정산 | Medium |
| 무게 단위 = 100g 고정 | 현재 요구사항은 100g 단위 계량, 다른 단위 확장은 후순위 | Low |

---

## 2. Alternatives Explored

### 2.1 Approach A: 마스터 + 매핑 테이블 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | 신규 테이블 3개(`mall_fresh_products`, `mall_fresh_product_store_links`, `fresh_purchase_items`)로 완전히 분리된 신선상품 레이어 구성 |
| **Pros** | 기존 4개 테이블 무변경, 점포별 코드 차이를 구조적으로 흡수, 자동 매칭에 필요한 데이터 구조 확보 |
| **Cons** | 신규 테이블 3개 + 화면 5개로 개발 범위가 가장 큼 |
| **Effort** | High |
| **Best For** | 기존 상품 데이터 안정성이 최우선이고, 점포마다 코드가 다른 상황 |

### 2.2 Approach B: 마스터 단일화 (매핑 생략)

| Aspect | Details |
|--------|---------|
| **Summary** | 점포별 매핑 없이 카테고리 단위로 몰 신선코드를 단순화하고, 원가는 매입 시마다 수동 입력 |
| **Pros** | 스키마가 단순함 |
| **Cons** | "매입 시 자동 매칭 후보 제안" 요구사항을 구현할 데이터 구조가 없음, 점포별 원가 추적이 약함 |
| **Effort** | Low |
| **Best For** | 신선 SKU 수가 아주 적고 자동화가 필요 없을 때 (해당 없음) |

### 2.3 Approach C: 기존 테이블 확장형 (참고용, 미채택)

| Aspect | Details |
|--------|---------|
| **Summary** | `products`에 무게/그룹 컬럼 추가, `mall_products.product_id` UNIQUE 제약 제거해 다점포 매핑 허용 |
| **Pros** | 신규 테이블 없이 기존 구조 재사용 |
| **Cons** | 기존 상품정보 변경 리스크를 정면으로 발생시킴 (이번 기획의 최우선 제약과 충돌) |
| **Effort** | Medium |
| **Best For** | 기존 데이터 변경 리스크를 감수할 수 있는 경우 (해당 없음) |

### 2.3 Decision Rationale

**Selected**: Approach A
**Reason**: "기존 상품정보를 건드리면 문제가 생긴다"는 제약이 최우선이었고, 동시에 "매입 시 자동 매칭 후보 제안" 기능을 v1에 포함하기로 했기 때문에 점포 코드 ↔ 몰 대표코드 매핑 테이블이 구조적으로 필수.

---

## 3. YAGNI Review

### 3.1 Included (v1 Must-Have)

- [ ] 신선상품 마스터 등록/관리 (`mall_fresh_products`)
- [ ] 점포 코드 ↔ 몰 대표코드 매핑 + 매입 시 자동 매칭 후보 제안
- [ ] 신선상품 전용 매입 등록 (`fresh_purchase_items`: 실측 무게/총원가)
- [ ] 몰 프론트 100g 단위 주문 UI (예상금액 표시)
- [ ] 준비중 주문 실측 무게 입력 → 확정금액 자동 계산
- [ ] 신선상품 원가/마진 리포트 화면
- [ ] 신선상품 UI 한/영 번역

### 3.2 Deferred (v2+ Maybe)

| Feature | Reason for Deferral | Revisit When |
|---------|---------------------|--------------|
| PG 자동 재청구 (카드 결제 차액 자동 처리) | 결제방식이 현금(COD)이라 v1에서 불필요 | 온라인 카드결제를 도입할 때 |
| 100g 외 다른 계량 단위 (kg 단위 등) | 현재 요구사항은 100g 고정 | 정육/수산물에서 다른 단위 요청이 들어올 때 |
| 사람 확인 없는 완전 자동 매칭 | 오매칭 리스크가 원가·매출에 직접 영향 | 매칭 정확도가 충분히 검증된 이후 |

### 3.3 Removed (Won't Do)

| Feature | Reason for Removal |
|---------|-------------------|
| `products`/`mall_products` 스키마 직접 확장 (Approach C) | 기존 상품정보 변경 리스크가 이번 기획의 핵심 제약과 정면 충돌 |

---

## 4. Scope

### 4.1 In Scope

- [ ] `mall_fresh_products`, `mall_fresh_product_store_links`, `fresh_purchase_items` 신규 테이블
- [ ] `mall_order_items`에 신선상품용 컬럼 추가 (`weight_g`, `actual_weight_g`, `estimated_price`, `confirmed_price`, `mall_fresh_product_id`)
- [ ] 신선상품 마스터 관리 화면 (몰 관리자)
- [ ] 점포-몰 코드 매핑 관리 화면 (자동 매칭 후보 확인/연결)
- [ ] 신선상품 매입 등록 화면 (점포 직원)
- [ ] 준비중 주문 실측 입력 화면 (점포 직원)
- [ ] 신선상품 원가/마진 리포트 화면
- [ ] 한/영 번역 (`lang/ko.json`, `lang/en.json`)

### 4.2 Out of Scope

- PG 자동 재청구 / 카드 차액 자동 결제 — (from YAGNI Review)
- 100g 외 계량 단위 지원 — (from YAGNI Review)
- 완전 자동(사람 확인 없는) 매칭 — (from YAGNI Review)
- 기존 `products`/`inventory`/`purchase_items`/`mall_products` 스키마 변경 — (from YAGNI Review, Approach C 제거)

---

## 5. Requirements

### 5.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 몰 관리자는 신선상품 대표코드(이름/카테고리/100g당 판매단가)를 등록·수정할 수 있다 | High | Pending |
| FR-02 | 점포 직원이 신선상품 매입을 등록하면 이름/바코드 유사도 기반으로 몰 대표코드 후보가 제안된다 | High | Pending |
| FR-03 | 관리자가 매칭 후보를 확정하면 해당 점포 상품코드가 몰 대표코드에 영구 연결된다 | High | Pending |
| FR-04 | 신선상품 매입 등록 시 실측 무게(kg)와 총 매입금액을 입력하면 100g당 원가가 자동 계산된다 | High | Pending |
| FR-05 | 고객은 몰 신선상품 상세페이지에서 100g 단위(+/-)로 주문 수량을 선택할 수 있다 | High | Pending |
| FR-06 | 주문 생성 시 예상금액(요청 그램수 × 판매단가)이 계산되어 표시된다 | High | Pending |
| FR-07 | 점포 직원은 준비중 주문의 신선상품 라인에 실측 무게를 입력할 수 있다 | High | Pending |
| FR-08 | 실측 무게 입력 시 확정금액이 자동 계산되어 주문에 반영된다 | High | Pending |
| FR-09 | 배송 화면/영수증에 확정금액 기준 현금 수령액이 표시된다 | Medium | Pending |
| FR-10 | 몰 관리자는 대표코드별 매입원가 대비 판매단가 마진율을 리포트로 조회할 수 있다 | Medium | Pending |
| FR-11 | 신선상품 관련 UI는 한국어/영어를 지원한다 | Medium | Pending |

### 5.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Performance | 매입 등록 화면의 매칭 후보 제안은 1초 이내 응답 | 개발 환경에서 유사도 쿼리 응답시간 측정 |
| Security | 모든 신규 쿼리는 prepared statement 사용, `product_management`/`purchase_management` 권한 체크 재사용 | `/code-review`로 검증 |
| Data Integrity | 기존 `products`/`inventory`/`purchase_items`/`mall_products` 테이블 스키마 무변경 | 마이그레이션 스크립트에 해당 테이블 `ALTER` 없음을 확인 |

---

## 6. Success Criteria

### 6.1 Definition of Done

- [ ] 5개 화면(마스터 관리/코드 매핑/매입 등록/준비중 실측/원가 리포트) 구현 완료
- [ ] 기존 매입·상품·몰 관리 기능에 회귀 없음 (수동 확인)
- [ ] `/code-review` 통과
- [ ] Design 문서 작성 완료 (`/pdca design mall-fresh-products`)

### 6.2 Quality Criteria

- [ ] 신규 테이블에 대한 쿼리 전수 prepared statement 검증
- [ ] 기존 admin 매입/상품 페이지 정상 동작 확인
- [ ] 실측 무게 → 확정금액 계산 로직 검증 (경계값: 0g, 소수점 처리)

---

## 7. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 오매칭 (다른 점포의 다른 상품이 같은 몰 대표코드로 잘못 연결) | High | Medium | 매칭 후보는 항상 관리자 확인을 거쳐야 확정되도록 강제, `match_source` 기록으로 추적 가능하게 함 |
| 실측 무게 입력 누락으로 주문 확정 지연 | Medium | Medium | 준비중 목록에서 신선상품 라인 미입력 시 배송 전환 단계 차단 |
| 신선상품 매입이 기존 `inventory` 재고에 반영되지 않아 재고 수량 인식 불일치 | Medium | Low | v1은 신선상품을 "당일 준비" 개념으로 보고 `inventory` 재고수량 관리 대상에서 제외한다고 Design 단계에서 명시적으로 확정 |
| 영문 번역 누락 | Low | Medium | `lang/ko.json`/`lang/en.json`에 신규 키를 항상 쌍으로 추가 |

---

## 8. Architecture Considerations

### 8.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure (`components/`, `lib/`, `types/`) | Static sites, portfolios, landing pages | |
| **Dynamic** | Feature-based modules, DB 연동 웹앱 | 백엔드가 있는 웹앱 | ✅ |
| **Enterprise** | Strict layer separation, DI, microservices | High-traffic systems, complex architectures | |

### 8.2 Key Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 전체 아키텍처 | A(마스터+매핑) / B(단일화) / C(기존 확장) | A | 기존 상품정보 무변경 + 자동 매칭 요구사항 동시 충족 |
| 점포코드 ↔ 몰코드 연결 방식 | 수동 1:1 / 자동 매칭 후보 제안 / 완전 자동 | 자동 매칭 후보 제안 | 편의성과 오매칭 방지(사람 확인) 균형 |
| 신선상품 매입 데이터 구조 | 기존 `purchase_items` 확장 / 별도 테이블 | 별도 테이블 (`fresh_purchase_items`) | 기존 매입 흐름/리포트에 영향 없음 |
| 실측 계량 작업 위치 | admin PC / 매장 태블릿 / 물류센터 일괄 | 각 점포 admin PC 화면 | 기존 admin 로그인/권한 재사용, 개발 범위 최소화 |
| 결제 차액 처리 | PG 자동 재청구 / 포인트 반영 / 배송 시 현금 정산 | 배송 시 현금 정산 | 필리핀 COD 운영 구조와 일치, PG 연동 불필요 |

### 8.3 Component Overview

```
[기존, 무변경]                          [신규 레이어]
products (전역)                         mall_fresh_products (몰 대표 신선코드)
inventory (점포별 원가/재고)   ←매핑→   mall_fresh_product_store_links
purchase_items (박스/낱개 매입)          (점포 상품코드 ↔ 몰 대표코드)
mall_products (몰 진열상품)                    ↓
mall_order_items (주문항목, 컬럼 추가)   fresh_purchase_items (점포 매입 시 실측 원가)
```

### 8.4 Data Flow

```
[매입 → 원가 반영]
몰 대표코드 등록
  → 점포 매입 등록 시 이름/바코드 유사도로 후보 제안
  → 관리자 확인 후 mall_fresh_product_store_links에 매핑 저장
  → fresh_purchase_items에 실측 원가(무게/총액) 기록
  → 원가/마진 리포트에 집계

[주문 → 정산]
고객이 100g 단위로 수량 선택 (예상금액 표시)
  → 주문 생성 (mall_order_items.weight_g, estimated_price)
  → 점포 준비중 화면에서 실측 (actual_weight_g 입력)
  → confirmed_price 자동 계산
  → 배송 기사가 확정금액 기준 현금 수령 (PG 연동 없음)
```

---

## 9. Convention Prerequisites

### 9.1 Applicable Conventions

- [ ] 기존 prepared statement / `has_permission()` 권한 체크 패턴 확인
- [ ] `lang/ko.json`, `lang/en.json` 다국어 키 네이밍 규칙 확인
- [ ] Codex와 작업을 분담할 경우 `docs/00-conventions/agent-orchestration.md` 규칙(파일 단위 분배, Claude Code 병합 게이트) 적용

---

## 10. Next Steps

1. [ ] Design 문서 작성 (`/pdca design mall-fresh-products`) — 신규 테이블 DDL, 화면별 상세 UI/AJAX 설계, `inventory` 재고 제외 여부 최종 확정
2. [ ] 팀 리뷰 및 승인
3. [ ] 구현 시작 (`/pdca do mall-fresh-products`) — 필요 시 `scripts/agent-worktree.ps1`로 Codex와 화면 단위 작업 분담

---

## Appendix: Brainstorming Log

| Phase | Question | Answer | Decision |
|-------|----------|--------|----------|
| Intent | 가장 먼저 반드시 풀어야 하는 핵심 문제는? | 세 가지 모두 한 번에 | 코드 통합 + 무게 주문 + 원가관리를 한 설계로 통합 진행 |
| Intent | 실측 계량/가격 확정 작업은 누가, 어디서? | 각 점포 직원이 admin PC 화면에서 | 별도 태블릿/물류센터 일괄 입력 대신 기존 admin 재사용 |
| Intent | 점포코드를 몰 대표코드에 연결하는 방식은? | 매입 시 자동 매칭 후보 제안 | 매핑 테이블 + 유사도 추천 로직 필요 → Approach A 채택 근거 |
| Intent | 신선상품 매입 무게 정보는 어디에 기록? | 신선상품 전용 별도 매입 테이블 | `fresh_purchase_items` 신설, 기존 `purchase_items` 무변경 |
| Alternatives | A(마스터+매핑) / B(단일화) / C(기존 확장) | Approach A | 기존 상품정보 무변경 + 자동 매칭 요구사항 동시 충족 |
| YAGNI | 자동매칭 후보 / 원가리포트 / PG재청구 / 영문번역 중 v1 포함 항목 | 자동매칭 후보, 원가리포트, 영문번역 (PG재청구 제외) | 결제가 현금(COD)이라 PG 연동은 불필요, 나머지는 v1 포함 |
| YAGNI | 실측가와 예상가 차이 시 차액 처리 | 배송 시 현금 정산 (필리핀 COD) | PG 자동 재청구 불필요 → Deferred 처리 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-05 | Initial draft (Plan Plus) | whdans007 |
