---
template: plan
version: 1.3
description: PDCA Plan phase document template with Context Anchor and Architecture considerations
variables:
  - feature: mall-order-chat
  - date: 2026-08-30
  - author: whdans007
  - project: HOME K MART
  - version: '-'
---

# mall-order-chat Planning Document

> **Summary**: 몰 웹앱 하단 탭바에 "주문톡"을 추가해, 고객이 자신의 주문 건별로 매장(관리자)과 1:1 채팅으로 소통할 수 있게 한다. 관리자는 기존 주문 상세 모달 안에서 바로 응답한다.
>
> **Project**: HOME K MART
> **Version**: -
> **Author**: whdans007
> **Date**: 2026-08-30
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 주문 처리 중(품절/배송지 확인/준비 지연 등) 고객과 소통할 채널이 전화뿐이라 응답이 느리고 기록이 남지 않는다. 고객도 문의할 방법이 마땅치 않다. |
| **Solution** | 주문(mall_orders)마다 채팅방을 두는 "주문톡" 기능을 추가한다. 실시간성은 기존 배송 위치 추적과 동일하게 폴링 방식으로 구현하고, 관리자는 새 화면 없이 `mall/admin/orders.php` 주문 상세 모달 안에서 응답한다. |
| **Function/UX Effect** | 고객은 하단 탭바 "주문톡"에서 자신의 주문 목록과 안 읽은 메시지 배지를 보고, 주문을 선택해 채팅으로 문의한다. 관리자는 주문 목록/상세 모달에서 안 읽은 메시지 배지를 보고 바로 답장한다. |
| **Core Value** | 주문 단위로 대화 이력이 남아 어떤 주문 때문에 무슨 이야기가 오갔는지 추적 가능해지고, 전화보다 빠르고 기록이 남는 소통 채널이 생긴다. |

---

## Context Anchor

> Auto-generated from Executive Summary. Propagated to Design/Do documents for context continuity.

| Key | Value |
|-----|-------|
| **WHY** | 주문 관련 소통이 전화뿐이라 느리고 기록이 남지 않는다 |
| **WHO** | 몰 고객(회원), 매장 관리자(`mall_management` 권한) |
| **RISK** | 웹소켓 없이 폴링으로 구현 시 서버 부하/체감 지연 가능성; 관리자 미확인 메시지 방치 위험 |
| **SUCCESS** | 고객·관리자 모두 주문 상세 화면 이탈 없이 채팅 송수신·안읽음 배지 확인 가능 |
| **SCOPE** | v1: 주문별 1:1 텍스트 채팅 + 하단 탭 배지 + 관리자 모달 통합. 이미지 전송/푸시알림/실시간 소켓은 제외 |

---

## 1. Overview

### 1.1 Purpose

고객이 자신의 주문에 대해 매장과 직접, 기록이 남는 방식으로 소통할 수 있게 한다. 특히 최근 추가된 품절 처리(주문 항목 품절 표시) 같은 상황에서 관리자가 고객에게 바로 알리고, 고객도 배송지·수령 시간 등을 문의할 창구가 필요하다.

### 1.2 Background

몰에는 이미 배송기사 위치 추적처럼 "실시간처럼 보이지만 실제로는 폴링"으로 구현된 기능이 있다(`mall/admin/ajax/get_driver_locations.php`, `mall/lib/delivery.php`). 별도 Node.js/웹소켓 서버가 없는 PHP 공유호스팅 환경이므로, 채팅도 같은 폴링 패턴을 재사용하는 것이 가장 현실적이다.

### 1.3 Related Documents

- Requirements: 사용자 대화(2026-08-30) — "고객와의 소통을 위해서 실시간 채팅기능이 필요해 주문톡 이라고 몰 웹앱 하단 네비게이션 바에 장바구니 옆에 넣어주면 좋을꺼 같아"
- References: `docs/01-plan/features/mall-delivery-dispatch.plan.md` (동일 폴링 패턴 선례)

---

## 2. Scope

### 2.1 In Scope

- [ ] `mall_order_messages` 테이블 신설 (주문별 메시지, 발신자 구분, 읽음 여부)
- [ ] 고객용 주문톡 목록 화면(내 주문 목록 + 마지막 메시지 미리보기 + 안읽음 배지)
- [ ] 고객용 주문별 채팅 화면(텍스트 메시지 송수신, 폴링 갱신)
- [ ] 하단 탭바에 "주문톡" 탭 추가(장바구니 탭 옆), 안읽음 총합 배지 표시
- [ ] 관리자용: `mall/admin/orders.php` 주문 상세 모달에 "채팅" 탭 추가, 목록 화면에 안읽음 배지 표시
- [ ] 메시지 발송/조회/읽음처리 AJAX 엔드포인트

### 2.2 Out of Scope

- 이미지/파일 첨부 전송 (텍스트만)
- 푸시 알림/SMS/이메일 알림 연동 (배지 표시로 대체)
- 웹소켓 기반 즉시 반영 (폴링으로 대체)
- 주문과 무관한 일반 상담(비회원 문의, 1:1 고객센터)
- 여러 관리자 간 담당자 배정/전달 기능(모든 관리자가 같은 대화를 봄)

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 고객은 하단 탭바 "주문톡"에서 본인 주문 목록과 각 주문의 마지막 메시지·안읽음 여부를 볼 수 있다 | High | Pending |
| FR-02 | 고객은 특정 주문을 선택해 텍스트 메시지를 보내고, 상대방(관리자) 메시지를 받아볼 수 있다 | High | Pending |
| FR-03 | 관리자는 `mall/admin/orders.php` 주문 상세 모달에서 해당 주문의 채팅을 보고 답장할 수 있다 | High | Pending |
| FR-04 | 새 메시지가 오면 하단 탭바(고객) 및 주문 목록(관리자)에 안읽음 배지 숫자가 표시된다 | High | Pending |
| FR-05 | 채팅 화면을 열람하면 해당 주문의 상대방 메시지가 읽음 처리되어 배지에서 제외된다 | Medium | Pending |
| FR-06 | 취소/완료 등 종료된 주문도 과거 대화 이력은 계속 조회할 수 있다(신규 발송 제한 여부는 Design에서 결정) | Low | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|--------------------|
| Performance | 폴링 주기 동안 대화 목록·미읽음 카운트 조회가 기존 페이지 로딩에 체감 지연을 주지 않음 | 폴링 API 응답시간 수동 확인 |
| Security | 본인 주문/본인이 접근 권한 있는 주문의 메시지만 조회·발송 가능 (`member_id` 소유 검증), 관리자는 `mall_management` 권한 필요 | 코드 리뷰 + 다른 회원 주문 ID로 접근 시도 테스트 |
| Data Integrity | 메시지는 발신자·주문·시각이 스냅샷처럼 고정되어 이후 주문 상태 변경과 무관하게 보존됨 | 코드 리뷰 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] 고객이 하단 탭 "주문톡"에서 주문을 선택해 메시지를 보내고 받을 수 있다
- [ ] 관리자가 주문 상세 모달에서 같은 대화를 보고 답장할 수 있다
- [ ] 양쪽 모두 안읽음 배지가 실제 미확인 메시지 수와 일치한다
- [ ] 다른 회원의 주문 채팅에는 접근할 수 없다(권한 검증)
- [ ] 코드 리뷰 완료

### 4.2 Quality Criteria

- [ ] 폴링 API가 불필요하게 매초 단위로 호출되지 않음(적정 주기 확인)
- [ ] 기존 하단 탭바(`bottom_nav.php`) 레이아웃이 6개 탭으로 늘어나도 깨지지 않음(`mall/css/mall.css` 확인)

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 폴링 주기가 너무 짧으면 서버/DB 부하 증가 | Medium | Medium | 배송 위치 추적과 동일하게 검증된 폴링 주기(예: 5~10초)를 재사용, 탭이 비활성일 때 폴링 중단 |
| 안읽음 배지 카운트 로직이 실제와 어긋남(읽음 처리 타이밍 버그) | Medium | Medium | 채팅 화면 진입 시점에만 읽음 처리하는 단일 지점으로 로직 통일 |
| 하단 탭 6개로 늘어나며 좁은 화면에서 레이아웃 깨짐 | Low | Medium | Design 단계에서 실제 목업으로 확인 |
| 관리자가 채팅을 놓쳐 응답 지연 | Medium | Medium | v1은 배지만 지원(사용자 확정 사항), 추후 알림음/푸시는 별도 기능으로 확장 여지 남김 |

---

## 6. Impact Analysis

> **Purpose**: List every existing consumer of the resources being changed.

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `mall_order_messages` | DB Table (신규) | 주문별 채팅 메시지 저장 |
| `mall/partials/bottom_nav.php` | Partial | 탭 5개 → 6개로 확장, "주문톡" 탭 추가 |
| `mall/admin/orders.php` | Page/Modal | 주문 상세 모달에 "채팅" 탭 추가 |
| `mall/admin/ajax/get_order_detail.php` | AJAX | 응답에 안읽음 메시지 수 등 채팅 관련 필드 추가 가능성(Design에서 확정) |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `bottom_nav.php` | READ(렌더) | `mall/partials/footer.php`를 include하는 모든 고객용 페이지(cart.php, product.php, order_detail.php 등) | Needs verification — 탭 추가로 CSS 레이아웃 전수 확인 필요 |
| `orders.php` 주문 상세 모달 | READ/UPDATE | `mall/admin/orders.php` (관리자 전용) | None — 기존 섹션 옆에 탭 추가하는 가산 변경 |
| `mall_orders` | READ | 채팅 목록 화면에서 회원 주문 목록 조회 시 참조(기존 `mall/mypage/orders.php` 조회 로직 재사용 가능) | None |

### 6.3 Verification

- [ ] 6개 탭으로 늘어난 하단 탭바가 모든 고객용 페이지(홈/카테고리/검색/상품상세/장바구니/주문상세/마이)에서 정상 표시되는지 확인
- [ ] 다른 회원의 주문 ID로 채팅 API 호출 시 거부되는지 확인
- [ ] 관리자 권한 없는 계정이 채팅 응답 API를 호출할 수 없는지 확인

---

## 7. Architecture Considerations

### 7.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure | Static sites | ☐ |
| **Dynamic** | Feature-based modules | Web apps with backend | ☑ (기존 몰 구조와 동일한 `mall/lib/*`, `mall/admin/ajax/*` 패턴) |
| **Enterprise** | Strict layer separation | High-traffic systems | ☐ |

### 7.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 실시간 방식 | 폴링 / 웹소켓 | 폴링 | 사용자 확정. 별도 상시 서버 없이 기존 PHP 스택으로 구현 가능, 배송 위치 추적과 동일 패턴 |
| 채팅 단위 | 주문별 / 고객당 1개 | 주문별(`mall_orders.id` 기준) | 사용자 확정. 문의 맥락(어떤 주문 얘기인지)이 명확함 |
| 관리자 UI 위치 | 신규 화면 / 기존 모달 통합 | `mall/admin/orders.php` 상세 모달에 탭 추가 | 사용자 확정. 이미 주문 컨텍스트를 보고 있는 화면에서 바로 응대 |
| 알림 방식 | 배지만 / 배지+소리 | 배지 숫자만 | 사용자 확정. v1 범위 최소화, 추후 확장 여지는 남김 |
| DB 접근 | mysqli(기존 패턴) | mysqli | 기존 `mall/lib/*.php` 전부 mysqli 사용, 일관성 유지 |

### 7.3 Clean Architecture Approach

```
Selected Level: Dynamic (기존 mall/ 디렉토리 구조 준수)

mall/
├── partials/bottom_nav.php      (탭 추가)
├── order_chat.php               (신규: 고객용 채팅 목록 + 채팅창)
├── lib/order_chat.php           (신규: 메시지 CRUD, 안읽음 카운트, 권한 검증 함수)
├── ajax/
│   ├── send_order_chat_message.php   (신규)
│   └── get_order_chat_messages.php   (신규, 폴링용)
└── admin/
    ├── orders.php                (기존 모달에 채팅 탭 추가)
    └── ajax/
        ├── send_order_chat_reply.php     (신규)
        └── get_order_chat_unread.php     (신규, 주문 목록 배지용)
```

---

## 8. Convention Prerequisites

### 8.1 Existing Project Conventions

- [x] 기존 코드 컨벤션 존재 (`mall/lib/*.php` mysqli 패턴, `mall/admin/ajax/*.php` JSON 응답 패턴, CSRF 검증(`mall/lib/csrf.php`))
- [ ] 별도 `CONVENTIONS.md` 없음 — 기존 코드 패턴을 그대로 따름
- [ ] ESLint/Prettier/TypeScript 해당 없음 (순수 PHP + vanilla JS 프로젝트)

### 8.2 Conventions to Define/Verify

| Category | Current State | To Define | Priority |
|----------|---------------|-----------|:--------:|
| 폴링 주기 | 배송 위치 추적에 선례 있음 | 채팅 전용 주기 값(초) | High |
| 안읽음 카운트 쿼리 | 없음(신규) | `mall_order_messages`에 읽음 플래그 컬럼 설계 | High |
| 권한 검증 | 기존 `is_logged_in()`/`has_permission('mall_management')` 패턴 존재 | 고객 쪽 `mall_is_logged_in()` + 주문 소유자 검증 함수 재사용 | High |

### 8.3 Environment Variables Needed

해당 없음 (신규 외부 서비스/시크릿 불필요)

### 8.4 Pipeline Integration

해당 없음 (9-phase 파이프라인 미사용 프로젝트)

---

## 9. Next Steps

1. [ ] Design 문서 작성 (`mall-order-chat.design.md`) — 테이블 스키마, 폴링 API 스펙, 하단 탭바 6개 레이아웃 목업 포함
2. [ ] 사용자 검토 및 승인
3. [ ] 구현 시작

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-30 | Initial draft | whdans007 |
