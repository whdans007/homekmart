---
template: plan-plus
version: 1.0
description: Brainstorming-enhanced PDCA Plan template with User Intent, Alternatives, and YAGNI sections
variables:
  - feature: mall-delivery-dispatch
  - date: 2026-08-30
  - author: whdans007
  - project: HOME K MART
  - version: '-'
---

# mall-delivery-dispatch Planning Document

> **Summary**: 쇼핑몰 주문의 접수확인/취소, 피킹슬립 인쇄, 자체 배송기사 배정과 실시간 위치 추적, 도착/완료 처리까지 이어지는 주문 이행(fulfillment) 파이프라인
>
> **Project**: HOME K MART
> **Version**: -
> **Author**: whdans007
> **Date**: 2026-08-30
> **Status**: Draft
> **Method**: Plan Plus (Brainstorming-Enhanced PDCA)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 관리자는 주문 접수확인 후 어떤 상품이 준비됐는지 프린트해서 확인할 방법이 없고, 상품 준비가 끝난 뒤 배송기사에게 배정·추적하는 수단이 전혀 없다. 고객도 주문이 지금 어디쯤 와있는지 알 방법이 없다. |
| **Solution** | mall_orders 상태 파이프라인을 접수대기→상품준비중→준비완료→배정→배송중→도착→완료로 확장하고, 인쇄용 피킹슬립, 자체 배송기사 전용 로그인·모바일 웹앱, 30초 주기 실시간 GPS 위치 추적, 관리자/고객 지도 뷰를 v1 범위에 포함해 구축한다. |
| **Function/UX Effect** | 관리자는 접수확인 시 바코드·사진·한/영 상품명이 포함된 피킹슬립을 인쇄해 매장에서 상품을 준비하고, 준비 완료 후 기사를 배정한다. 기사는 모바일 웹에서 배정 목록을 보고 Google 지도로 길찾기 후 도착/완료 버튼을 누른다. 고객은 주문 상세 페이지에서 기사 위치를 실시간 지도로 확인한다. |
| **Core Value** | 매장 접수부터 고객 수령까지 전 과정이 시스템 안에서 추적되어, 주문이 어느 단계에서 지연되는지 파악할 수 있고 고객 신뢰도(배송 투명성)가 올라간다. |

---

## 1. User Intent Discovery

### 1.1 Core Problem

`mall/admin/orders.php`에 "접수확인" 버튼(상태를 상품준비중으로 전환 + 준비시간 입력)까지는 이미 구현되어 있으나, 그 다음 단계가 전혀 없다: (1) 어떤 상품을 준비해야 하는지 인쇄해서 확인할 방법이 없고, (2) 상품 준비가 끝난 뒤 배송을 누가 어떻게 할지 배정·추적하는 기능이 없으며, (3) 고객은 주문 이후 배송 진행 상황을 전혀 알 수 없다.

### 1.2 Target Users

| User Type | Usage Context | Key Need |
|-----------|---------------|----------|
| 매장 관리자 | `mall/admin/orders.php`에서 접수확인 후 상품 준비, 준비완료 후 기사 배정 | 정확한 피킹슬립 인쇄, 배차 현황 파악 |
| 자체 배송기사 | 모바일 웹앱에서 배정된 주문 확인, 길찾기, 도착/완료 처리 | 주문별 고객·상품 정보, 내비게이션 연동 |
| 고객(회원) | `mall/order_detail.php`에서 본인 주문 상태 확인 | 배송 진행 상황(기사 위치, 도착 여부) 실시간 확인 |

### 1.3 Success Criteria

- [ ] 관리자가 접수확인 후 피킹슬립을 인쇄해 상품 준비에 사용할 수 있다
- [ ] 관리자가 준비완료된 주문에 자체 배송기사를 배정할 수 있다
- [ ] 배송기사가 모바일 웹에서 배정된 주문의 고객/상품 정보를 보고 Google 지도로 길찾기를 열 수 있다
- [ ] 배송 중 기사 위치가 30초 주기로 갱신되어 관리자·고객이 지도에서 확인할 수 있다
- [ ] 기사가 도착/완료 버튼을 누르면 주문 상태와 고객 화면이 그에 맞게 갱신된다
- [ ] 배송 실패 시 관리자가 다른 기사에게 재배정할 수 있다

### 1.4 Constraints

| Constraint | Details | Impact |
|------------|---------|--------|
| 외부(제3자) 드라이버 미지원 | 사용자가 "우선 자체 배송 먼저" 명시 — 이번 범위는 자체 기사만, `driver_type` 컬럼으로 확장 여지만 남김 | Medium |
| 실시간 push/SMS 인프라 없음 | 고객 알림은 v1에서 주문 상세 페이지 배너(폴링)로만 제공 — 별도 푸시/SMS 게이트웨이 연동 없음 | Medium |
| "규격" 전용 필드 없음 | products/mall_products에 규격 컬럼이 없어 상품명 텍스트에 이미 포함된 값을 그대로 사용(신규 컬럼 추가 안 함) | Low |
| 기존 스택 준수 | 신규 네이티브 앱 대신 기존 PHP 모바일 웹 스택(mall/ 패턴)을 재사용 | Low |

---

## 2. Alternatives Explored

### 2.1 Approach A: 경량 배차(딥링크 내비게이션, 상태 텍스트만)

| Aspect | Details |
|--------|---------|
| **Summary** | 기사 앱은 Google 지도 딥링크로 길찾기만 열어주고, 위치 추적 없이 상태(배송중/도착/완료) 텍스트만 갱신 |
| **Pros** | 최소 인프라, 빠른 구현, 배터리·개인정보 이슈 적음 |
| **Cons** | 관리자·고객이 기사 위치를 지도로 볼 수 없음 |
| **Effort** | Low |
| **Best For** | 배송 건수가 적고 빠른 출시가 우선일 때 |

### 2.2 Approach B: 실시간 위치 추적 포함 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | 기사 앱이 배송 중 30초 주기로 GPS 좌표를 서버에 전송, 관리자 대시보드와 고객 주문상세 페이지 양쪽에서 기사 위치를 지도로 실시간 확인 |
| **Pros** | 고객 경험 향상(배송 투명성), 위치 이력으로 배송 소요시간 분석 가능 |
| **Cons** | 위치 저장 테이블, 배터리 소모, 위치 권한 UX, 지도 폴링 렌더링 등 구현 범위가 큼 |
| **Effort** | High |
| **Best For** | 배송 경험을 핵심 차별점으로 삼으려는 경우 |

### 2.3 Approach C: 외부 배송대행 API 연동 (참고용, 이번 범위 아님)

| Aspect | Details |
|--------|---------|
| **Summary** | Lalamove 등 필리핀 현지 배송 플랫폼과 연동해 자체 기사/앱 개발 없이 바로 확장 |
| **Pros** | 자체 기사 채용/앱 개발 불필요, 즉시 확장 가능 |
| **Cons** | "자체 배송 먼저"라는 요구와 배치, 수수료 발생, 외부 API 연동 복잡도 |
| **Effort** | Medium (연동 관점) |
| **Best For** | 자체 배송 역량을 넘어서는 물량이 발생하는 후속 단계 |

### 2.4 Decision Rationale

**Selected**: Approach B (실시간 위치 추적 포함)
**Reason**: 사용자가 대안 비교 후 "실시간 위치 추적 포함"을 직접 선택함 — 관리자·고객 모두 기사 위치를 지도로 실시간 확인하는 경험을 v1부터 제공하기로 결정. Approach C(외부 API)는 사용자가 명시한 "우선 자체 배송" 방향과 맞지 않아 후속 단계 참고용으로만 남김.

---

## 3. YAGNI Review

### 3.1 Included (v1 Must-Have)

- [ ] 주문 상태 파이프라인 확장 (준비완료/배정됨/배송중/도착/완료/배송실패)
- [ ] "취소" 버튼 — 상품준비중 → 접수대기로 되돌리기 (기존 "주문취소"와 별개)
- [ ] 접수확인 시 상세정보 조회 + 인쇄용 피킹슬립 (사진/바코드/한글·영문 상품명/수량/단가/금액 + 소계/할인/배송비/총합계)
- [ ] 배송기사 전용 로그인·세션 (mall_drivers, mall 고객 세션과 분리)
- [ ] 배송기사 모바일 웹앱 (배정 목록, 주문 상세, Google 지도 길찾기 딥링크, 상태 버튼)
- [ ] 실시간 위치 추적 (배송 중 30초 주기 전송)
- [ ] 관리자 배차 UI + 실시간 기사 위치 지도
- [ ] 고객 주문상세 페이지 실시간 기사 위치 지도 + 상태 배너
- [ ] 기사 위치 이력 저장(경로 재생 가능하도록 이력 테이블에 축적)
- [ ] 기사 1명이 여러 건 동시 배송 가능
- [ ] 배송 실패/재배정 처리
- [ ] 관리자 대시보드 기사별 배송 통계(건수, 평균 소요시간)

### 3.2 Deferred (v2+ Maybe)

| Feature | Reason for Deferral | Revisit When |
|---------|---------------------|--------------|
| 외부(제3자) 드라이버 지원 | 사용자가 "추후" 진행하겠다고 명시 | 자체 배송 역량 초과 물량 발생 시 |
| 실시간 push 알림 / SMS 발송 | 별도 게이트웨이·인프라 필요, v1은 페이지 배너로 충분히 검증 가능 | 페이지 배너만으로 고객 불만이 발생할 때 |
| 네이티브 배송기사 앱(iOS/Android) | 모바일 웹으로 충분, 앱스토어 배포 부담 회피 | 카메라/백그라운드 GPS 등 웹 한계에 부딪힐 때 |
| 배차 자동 최적화(ETA/최단경로 알고리즘) | 초기엔 기사 수가 적어 수동 배정으로 충분 | 기사 수·주문량이 늘어 수동 배정이 병목이 될 때 |

### 3.3 Removed (Won't Do)

| Feature | Reason for Removal |
|---------|-------------------|
| products/mall_products에 "규격" 전용 컬럼 추가 | 상품명에 이미 포함되어 있어 중복 데이터 관리 부담만 커짐(사용자 확인) |

---

## 4. Scope

### 4.1 In Scope

- [ ] `mall_orders` 상태 enum 확장 및 `current_driver_id` 컬럼 추가
- [ ] `mall_drivers`, `mall_order_driver_assignments`, `mall_driver_locations` 신규 테이블
- [ ] `mall/admin/orders.php` — 취소 버튼, 기사 배정 UI, 재배정 UI
- [ ] `mall/admin/order_print.php` — 인쇄용 피킹슬립 신규 페이지
- [ ] `mall/admin/drivers.php` — 기사 계정 관리 + 배송 통계 신규 페이지
- [ ] `mall/admin/delivery_map.php` — 전체 기사 실시간 위치 지도 신규 페이지
- [ ] `mall/driver/` 신규 디렉터리 — 기사 로그인/세션/주문목록/주문상세/위치전송 ajax
- [ ] `mall/order_detail.php` — 배송 상태 배너 + 기사 위치 실시간 지도(고객용)
- [ ] 관련 마이그레이션 스크립트 일체

### 4.2 Out of Scope

- 외부 드라이버 온보딩/매칭 — (YAGNI Review 3.2)
- 푸시 알림/SMS 발송 — (YAGNI Review 3.2)
- 네이티브 앱 — (YAGNI Review 3.2)
- 배차 자동 최적화 — (YAGNI Review 3.2)
- 상품 "규격" 전용 컬럼 — (YAGNI Review 3.3)

---

## 5. Requirements

### 5.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 관리자는 상품준비중 상태 주문을 "취소" 버튼으로 접수대기로 되돌릴 수 있다 | High | Pending |
| FR-02 | 관리자는 주문의 피킹슬립(사진/바코드/한·영 상품명/수량/단가/금액/합계·할인·배송비·총합계)을 인쇄할 수 있다 | High | Pending |
| FR-03 | 관리자는 준비완료 상태 주문에 활성 배송기사를 배정할 수 있다 | High | Pending |
| FR-04 | 배송기사는 전용 로그인으로 자신에게 배정된 주문 목록(여러 건)을 볼 수 있다 | High | Pending |
| FR-05 | 배송기사는 주문 상세에서 고객정보·배송지·상품목록을 보고 Google 지도 길찾기를 열 수 있다 | High | Pending |
| FR-06 | 배송기사가 "배송시작"을 누르면 위치가 30초 주기로 서버에 전송된다 | High | Pending |
| FR-07 | 배송기사는 "도착", "배송완료", "배송실패(사유입력)" 버튼으로 주문 상태를 변경할 수 있다 | High | Pending |
| FR-08 | 관리자는 배송실패 주문을 다른 기사에게 재배정할 수 있다 | High | Pending |
| FR-09 | 관리자는 실시간 지도에서 모든 활성 기사의 현재 위치를 확인할 수 있다 | High | Pending |
| FR-10 | 고객은 본인 주문 상세 페이지에서 배송 상태 배너와 기사 위치 지도를 확인할 수 있다 | High | Pending |
| FR-11 | 관리자는 기사별 배송 건수/평균 소요시간 통계를 볼 수 있다 | Medium | Pending |

### 5.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Security | 기사 세션은 mall 고객 세션과 완전히 분리(별도 쿠키명), 위치/주문 조회는 항상 배정된 기사 본인 것만(IDOR 방지) | 코드 리뷰 — mall_driver_owner_clause 패턴 |
| Performance | 지도 폴링은 각 화면에서 과도한 요청이 발생하지 않도록 10~15초 간격으로 제한 | 코드 리뷰 |
| Data Integrity | 재배정 시 기존 배정 이력은 삭제하지 않고 상태만 종료 처리(failed/reassigned) | 코드 리뷰 |

---

## 6. Success Criteria

### 6.1 Definition of Done

- [ ] 모든 Functional Requirements 구현
- [ ] 관련 마이그레이션 스크립트 작성 및 문서화
- [ ] `php -l` 전체 통과
- [ ] 실제 브라우저 워크스루(관리자/기사/고객 3개 화면) 완료

### 6.2 Quality Criteria

- [ ] 신규 ajax 엔드포인트 전부 권한/CSRF 체크 포함
- [ ] IDOR 방지(기사 본인 배정 건만 조회/변경 가능) 검증

---

## 7. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 브라우저 위치 권한 거부/모바일 백그라운드 제한으로 위치 전송이 끊길 수 있음 | Medium | High | 기사 앱이 포그라운드로 켜져 있을 때만 전송되는 한계를 관리자에게 명확히 안내, 실패 시 마지막 알려진 위치로 표시 |
| 지도 폴링 다발로 Google Maps API 비용/쿼터 증가 | Medium | Medium | 폴링 주기를 10~15초로 제한, 배송중/도착 상태일 때만 폴링 활성화 |
| 기사 1명 다중 배송 허용으로 배정 로직 복잡도 증가 | Medium | Medium | Phase 4 설계에서 배정 순서 UI를 단순하게(수동 정렬) 유지 |
| 기존 mall_orders.status enum 값 확장이 다른 화면(주문목록/필터)에 영향 | Low | Medium | status_labels 배열을 쓰는 모든 화면(mall/mypage/orders.php, mall/admin/orders.php 등) 일괄 점검 |

---

## 8. Architecture Considerations

### 8.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure (`components/`, `lib/`, `types/`) | Static sites, portfolios, landing pages | |
| **Dynamic** | Feature-based modules, BaaS integration (bkend.ai) | Web apps with backend, SaaS MVPs, fullstack apps | ✅ |
| **Enterprise** | Strict layer separation, DI, microservices | High-traffic systems, complex architectures | |

기존 `mall/` 구조(Presentation → lib/ 함수 계층)를 그대로 확장하는 Dynamic 수준 — 기존 shopping-mall 기능과 동일한 아키텍처 옵션(Option C) 유지.

### 8.2 Key Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 위치 추적 방식 | 딥링크만 / 실시간 위치 추적 | 실시간 위치 추적 | 사용자 선택(Alternatives 2.4) |
| 기사 계정 체계 | mall_members 확장 / admin 재사용 / 신규 mall_drivers | 신규 mall_drivers | 사용자 선택 — 권한 분리, 외부기사 확장 용이 |
| 규격 데이터 | 신규 컬럼 / 상품명 텍스트 재사용 | 상품명 텍스트 재사용 | 사용자 선택 — 데이터 중복 방지 |
| 위치 이력 | 최신 위치만 / 이력 전체 저장 | 이력 전체 저장 | 사용자 선택(YAGNI) — 경로 재생 지원 |
| 기사-주문 관계 | 1기사 1주문 순차 / 1기사 다중 동시 | 1기사 다중 동시 | 사용자 선택(YAGNI) |

### 8.3 Component Overview

```
mall/admin/orders.php ──(취소/기사배정)──> mall_orders, mall_order_driver_assignments
mall/admin/order_print.php ──(조회)──> mall_order_items, products, mall_product_images
mall/admin/drivers.php ──(CRUD/통계)──> mall_drivers, mall_order_driver_assignments
mall/admin/delivery_map.php ──(폴링 조회)──> mall_drivers(last_lat/last_lng)

mall/driver/login.php, lib/auth.php ──(신규 세션)──> mall_drivers
mall/driver/index.php ──(목록)──> mall_order_driver_assignments
mall/driver/order_detail.php ──(상세+상태버튼)──> mall_orders, mall_order_items
mall/driver/ajax/update_location.php ──(주기 전송)──> mall_driver_locations, mall_drivers(캐시 갱신)

mall/order_detail.php(고객) ──(폴링 조회)──> mall_orders.status, mall_driver_locations 최신값
```

### 8.4 Data Flow

```
[관리자] 접수확인 → 상품준비중
   → [관리자] 인쇄(order_print.php) → 매장에서 상품 준비
   → [관리자] "준비완료" 처리 → 기사 배정(mall_order_driver_assignments 신규 행, mall_orders.current_driver_id 갱신)
   → [기사 앱] 배정 목록에 노출 → "배송시작" 클릭 → mall_orders.status='delivering'
        → (30초 주기) 위치 전송 → mall_driver_locations INSERT + mall_drivers 캐시 UPDATE
        → [고객/관리자 화면] 폴링으로 최신 위치 지도 갱신
   → [기사 앱] "도착" 클릭 → mall_orders.status='arrived' → 고객 화면 배너 갱신
   → [기사 앱] "배송완료" 클릭 → mall_orders.status='completed', assignment.completed_at 기록
        └─ 실패 시: "배송실패(사유)" 클릭 → status='delivery_failed', assignment.status='failed'
           → [관리자] 재배정 → 새 assignment 행 생성 → 위 흐름 반복
```

---

## 9. Convention Prerequisites

### 9.1 Applicable Conventions

- [ ] 기존 mall/lib/* 함수형 유스케이스 패턴 준수 (Presentation은 lib 함수만 호출)
- [ ] 신규 세션은 `mall_driver_session_start()` 등 별도 세션명 사용(MALLSESSID와 충돌 금지)
- [ ] 신규 테이블은 `mall_` 접두사, utf8mb4, FK 명시 — 기존 mall_schema.sql 컨벤션 준수
- [ ] 신규 ajax는 기존 `mall/admin/ajax/*`, `mall/ajax/*` 패턴(JSON 응답, CSRF, 권한 체크) 준수

---

## 10. Next Steps

1. [ ] Write design document (`/pdca design mall-delivery-dispatch`)
2. [ ] Team review and approval
3. [ ] Start implementation (`/pdca do mall-delivery-dispatch`)

---

## Appendix: Brainstorming Log

| Phase | Question | Answer | Decision |
|-------|----------|--------|----------|
| Intent | 배송기사 계정을 어떻게 관리할까요? | 새 배송기사 전용 로그인 | mall_drivers 신규 테이블/세션 |
| Intent | 상품 "규격" 정보는 어떻게 표시할까요? | 상품명에 이미 포함된 값 그대로 사용 | 신규 컬럼 추가 안 함 |
| Intent | 고객 "배송 도착" 알림 1차 목표는? | 주문 상세 페이지 상태 배너만 | 푸시/SMS 인프라 미구축(v1) |
| Intent | 배송기사 앱 형태는? | 모바일 웹페이지 | 기존 mall/ PHP 스택 재사용 |
| Alternatives | 경량 배차 vs 실시간 위치추적 vs 외부 API 연동 | 실시간 위치 추적 포함 | Approach B 선택 — 관리자/고객 지도에서 기사 위치 실시간 확인 |
| YAGNI | 위치이력/다중배송/실패재배정/기사별통계 중 v1 포함할 것은? | 전부 포함 | 4개 항목 모두 v1 In Scope로 승격 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-30 | Initial draft (Plan Plus) | whdans007 |
