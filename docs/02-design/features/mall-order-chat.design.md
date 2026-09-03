---
template: design
version: 1.3
feature: mall-order-chat
date: 2026-08-30
author: whdans007
project: HOME K MART
version_project: '-'
---

# mall-order-chat Design Document

> **Summary**: 몰 웹앱 하단 탭바에 "주문톡"을 추가해 고객이 주문 건별로 매장과 1:1 텍스트 채팅을 하는 기능의 상세 기술 설계. 실시간성은 기존 배송 위치 추적과 동일하게 폴링으로 구현하고, 관리자는 `mall/admin/orders.php` 주문 상세 모달 안에서 응답한다.
>
> **Project**: HOME K MART
> **Version**: -
> **Author**: whdans007
> **Date**: 2026-08-30
> **Status**: Draft
> **Planning Doc**: [mall-order-chat.plan.md](../01-plan/features/mall-order-chat.plan.md)

### Pipeline References (if applicable)

| Phase | Document | Status |
|-------|----------|--------|
| Phase 1 | Schema Definition | N/A (미사용) |
| Phase 2 | Coding Conventions | N/A (CLAUDE.md 컨벤션으로 대체) |
| Phase 3 | Mockup | N/A |
| Phase 4 | API Spec | 본 문서 §4에서 직접 정의 |

---

## Context Anchor

> Copied from Plan document.

| Key | Value |
|-----|-------|
| **WHY** | 주문 관련 소통이 전화뿐이라 느리고 기록이 남지 않는다 |
| **WHO** | 몰 고객(회원), 매장 관리자(`mall_management` 권한) |
| **RISK** | 웹소켓 없이 폴링으로 구현 시 서버 부하/체감 지연 가능성; 관리자 미확인 메시지 방치 위험 |
| **SUCCESS** | 고객·관리자 모두 주문 상세 화면 이탈 없이 채팅 송수신·안읽음 배지 확인 가능 |
| **SCOPE** | v1: 주문별 1:1 텍스트 채팅 + 하단 탭 배지 + 관리자 모달 통합. 이미지 전송/푸시알림/실시간 소켓은 제외 |

---

## 1. Overview

### 1.1 Design Goals

- 주문(`mall_orders`)마다 독립된 대화방을 갖는 메시지 모델을 신설하되, 기존 `mall/lib/*.php` 함수형 헬퍼 컨벤션을 그대로 따른다.
- 고객·관리자 양쪽에서 "안읽음" 개념이 정확히 대칭적으로 동작하도록(내가 안 읽은 상대방 메시지 수) 설계한다.
- 하단 탭바(`bottom_nav.php`) 배지는 페이지 로딩 시 계산(기존 장바구니 카운트와 동일 패턴)하고, 실제 대화창을 열었을 때만 폴링한다 — 불필요한 상시 폴링을 피한다.
- 관리자는 새 화면 없이 기존 주문 상세 모달에 탭을 추가해 문맥(주문 정보) 안에서 바로 응답한다.

### 1.2 Design Principles

- **기존 컨벤션 재사용**: `mall/lib/auth.php`(고객 세션), `lib/session_helper.php`(관리자 세션), `mall/lib/csrf.php`, `mall/admin/ajax/*.php`(JSON+CSRF+권한) 패턴을 그대로 따른다.
- **소유권 검증 필수**: 고객 쪽 모든 조회/발송은 `mall_orders.member_id = 현재 로그인 회원`을 항상 검증한다(IDOR 방지, `get_order_tracking.php` 선례와 동일).
- **읽음 처리는 단일 지점**: 대화창을 "여는" 시점에만 읽음 처리하며, 폴링 응답 자체는 읽음 상태를 바꾸지 않는다(중복 update 방지).
- **폴링은 열람 중에만**: 배지는 페이지 렌더 시 1회 계산, 실시간 갱신은 대화창이 열려 있을 때만 수행한다.

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 각 ajax 파일에 SQL 직접 작성 | Repository/Service 클래스 계층 | `mall/lib/order_chat.php` 함수형 헬퍼 |
| **New Files** | ~4 | ~9 | ~6 |
| **Modified Files** | ~2 | ~2 | ~2 |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Medium | High | High |
| **Effort** | Low | High | Medium |
| **Risk** | High (안읽음/권한 로직 중복 → 불일치 위험) | Low (그러나 기존 함수형 관습과 이질적) | Low |
| **Recommendation** | 빠른 프로토타입 | 대규모 팀 | **Default choice** |

**Selected**: Option C — **Rationale**: 사용자가 3개 옵션 비교 후 직접 선택. `mall/lib/delivery.php`·`driver.php`·`order.php`와 동일한 함수형 헬퍼 패턴이며, 발송/조회/읽음처리/안읽음카운트처럼 고객·관리자 양쪽 ajax가 공유하는 로직을 한 파일에 모아 불일치 위험을 구조적으로 낮춘다.

> 아래 상세 설계는 Option C 기준으로 작성됨.

### 2.1 Component Diagram

```
┌──────────────┐   ┌──────────────────────────────┐   ┌───────────────────────┐
│   Browser    │──▶│ mall/order_chat.php (고객)    │──▶│  MySQL (기존 DB)       │
│ (고객)       │   │ mall/ajax/*chat*.php          │   │  mall_order_messages  │
├──────────────┤   ├──────────────────────────────┤   │   (신규)               │
│   Browser    │──▶│ mall/admin/orders.php(모달)   │   └───────────────────────┘
│ (관리자)     │   │ mall/admin/ajax/*chat*.php    │
└──────────────┘   └─────────────┬────────────────┘
                                  │ require
                                  ▼
                    ┌───────────────────────────┐
                    │ mall/lib/order_chat.php    │
                    │  (발송/조회/읽음/안읽음카운트) │
                    └─────────────┬──────────────┘
                                  │ mall_get_db_connection() / get_db_connection()
                                  ▼
                    ┌───────────────────────────┐
                    │ config/db_config.php (기존)│
                    └───────────────────────────┘
```

### 2.2 Data Flow

```
[고객 발송] order_chat.php → ajax/send_order_chat_message.php
    → mall_order_chat_send(order_id, 'member', member_id, text)
    → mall_order_messages INSERT(sender_type='member', is_read_by_admin=0)

[관리자 발송] admin/orders.php 채팅 탭 → admin/ajax/send_order_chat_reply.php
    → mall_order_chat_send(order_id, 'admin', admin_user_id, text)
    → mall_order_messages INSERT(sender_type='admin', is_read_by_member=0)

[열람 시 읽음처리] 대화창 오픈
    → ajax/get_order_chat_messages.php (고객) / admin/ajax/get_order_chat_messages.php (관리자)
    → mall_order_chat_mark_read(order_id, 'member'|'admin') → 상대방이 보낸 미확인 메시지 read=1 UPDATE
    → 메시지 목록 반환

[폴링] 대화창이 열려 있는 동안 5초 간격으로 get_order_chat_messages.php 재호출(마지막 메시지 id 이후만 조회)

[배지] 페이지 렌더 시(bottom_nav.php include 시점) mall_order_chat_unread_count_for_member() 1회 호출
       관리자 목록(orders.php) 렌더 시 주문별 mall_order_chat_unread_count_for_admin() 1회 호출
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `mall/lib/order_chat.php` | `get_db_connection()` | 메시지 발송/조회/읽음처리/안읽음카운트 (함수형 헬퍼) |
| `mall/order_chat.php` | `mall/lib/auth.php`, `mall/lib/order_chat.php` | 고객용 주문 목록+채팅창 화면 |
| `mall/ajax/send_order_chat_message.php`, `get_order_chat_messages.php` | `mall/lib/auth.php`, `mall/lib/order_chat.php`, `mall/lib/csrf.php` | 고객용 발송/폴링 API |
| `mall/admin/orders.php` (모달 확장) | `mall/lib/order_chat.php` | 관리자 채팅 탭 |
| `mall/admin/ajax/send_order_chat_reply.php`, `get_order_chat_messages.php` | `lib/session_helper.php`, `lib/permission_helper.php`, `mall/lib/order_chat.php`, `mall/lib/csrf.php` | 관리자용 발송/폴링 API |
| `mall/partials/bottom_nav.php` (수정) | `mall/lib/order_chat.php` | "주문톡" 탭 + 안읽음 배지 |

---

## 3. Data Model

### 3.1 mall_order_messages (신규)

```sql
CREATE TABLE `mall_order_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `sender_type` enum('member','admin') NOT NULL,
  `sender_member_id` int(11) DEFAULT NULL COMMENT 'sender_type=member일 때 mall_members.id',
  `sender_admin_id` int(11) DEFAULT NULL COMMENT 'sender_type=admin일 때 users.id',
  `message` text NOT NULL,
  `is_read_by_member` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'admin이 보낸 메시지를 고객이 읽었는지(member 발신 메시지는 항상 1로 저장)',
  `is_read_by_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'member가 보낸 메시지를 관리자가 읽었는지(admin 발신 메시지는 항상 1로 저장)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id_created` (`order_id`, `id`),
  CONSTRAINT `mall_order_messages_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문별 채팅 메시지';
```

> `is_read_by_member`/`is_read_by_admin`을 "발신자 본인 기준으로는 항상 읽음(1)"으로 저장하는 이유: 안읽음 카운트 쿼리가 `WHERE sender_type='admin' AND is_read_by_member=0` 형태로 단순해지고, 발신자 자신의 메시지가 배지에 잘못 잡히는 걸 코드 조건 없이 스키마 단에서 막는다.

### 3.2 Entity Relationship

```
mall_orders (1) ──── (N) mall_order_messages
mall_members (1) ──── (N) mall_order_messages [sender_member_id, sender_type='member']
users (1) ──── (N) mall_order_messages [sender_admin_id, sender_type='admin']
```

### 3.3 mall_order_chat.php 함수 목록

| Function | Params | Returns | 설명 |
|----------|--------|---------|------|
| `mall_order_chat_send($order_id, $sender_type, $sender_id, $message)` | int, 'member'/'admin', int, string | array(message row) | INSERT 1건, 트랜잭션 불필요(단일 INSERT) |
| `mall_order_chat_list($order_id, $after_id = 0)` | int, int | array | `id > after_id` 메시지만 오름차순 반환(폴링용 증분 조회) |
| `mall_order_chat_mark_read($order_id, $reader_type)` | int, 'member'/'admin' | void | reader가 아직 안 읽은 상대방 메시지를 read=1로 UPDATE |
| `mall_order_chat_unread_count_for_member($member_id)` | int | int | 해당 회원의 전체 주문 중 `sender_type='admin' AND is_read_by_member=0` 총합 |
| `mall_order_chat_unread_count_for_admin($order_id)` | int | int | 해당 주문의 `sender_type='member' AND is_read_by_admin=0` 개수 |
| `mall_order_chat_unread_map_for_admin($order_ids)` | int[] | array<order_id,int> | 주문 목록 화면에서 N+1 쿼리 방지용 일괄 조회 |

---

## 4. API Specification

### 4.1 Endpoint List

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET | `mall/ajax/get_order_chat_messages.php?order_id=&after_id=` | 고객: 메시지 증분 조회(+대화창 오픈 시 읽음처리) | 회원 로그인 + 주문 소유권 |
| POST | `mall/ajax/send_order_chat_message.php` | 고객: 메시지 발송 | 회원 로그인 + 주문 소유권 + CSRF |
| GET | `mall/admin/ajax/get_order_chat_messages.php?order_id=&after_id=` | 관리자: 메시지 증분 조회(+읽음처리) | `mall_management` 권한 |
| POST | `mall/admin/ajax/send_order_chat_reply.php` | 관리자: 메시지 발송 | `mall_management` 권한 + CSRF |

> 안읽음 배지는 별도 API 없이 페이지 렌더 시점에 `bottom_nav.php`(고객)와 `orders.php` 목록 쿼리(관리자)에서 직접 계산해 내려준다(§2.2 참고). 최초 범위에서는 배지 전용 폴링 엔드포인트를 두지 않는다.

### 4.2 Detailed Specification

#### `GET mall/ajax/get_order_chat_messages.php?order_id=123&after_id=0`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "messages": [
      {"id": 12, "sender_type": "admin", "message": "품절 상품이 있어 안내드립니다", "created_at": "2026-08-30 10:15:00"},
      {"id": 13, "sender_type": "member", "message": "네 알겠습니다", "created_at": "2026-08-30 10:16:20"}
    ]
  }
}
```
- `after_id=0`(최초 진입) 시 전체 이력 반환 + 상대방(admin) 메시지 읽음처리
- `after_id=13`(폴링) 시 id>13인 신규 메시지만 반환, 신규 admin 메시지가 있으면 그만큼만 읽음처리

**Error Responses:**
- `401 UNAUTHORIZED`: 로그인 필요
- `404 VALIDATION_ERROR`: 본인 소유가 아닌 주문(존재하지 않는 것처럼 응답 — IDOR 방지)

#### `POST mall/ajax/send_order_chat_message.php`

**Request (form-urlencoded):** `order_id`, `message`, `csrf_token`

**Response (200):**
```json
{"success": true, "data": {"id": 14, "created_at": "2026-08-30 10:17:00"}}
```

**Error Responses:**
- `400 VALIDATION_ERROR`: message 공백/500자 초과
- `401 UNAUTHORIZED` / `403 CSRF_INVALID`
- `404 VALIDATION_ERROR`: 본인 주문 아님

#### 관리자 측 두 엔드포인트는 위와 동일 스펙이며 인증만 `has_permission('mall_management')`로 대체된다.

---

## 5. UI/UX Design

### 5.1 하단 탭바 레이아웃 (6개 탭으로 확장)

```
┌─────┬─────────┬────────┬───────────┬───────────┬───────┐
│ 홈  │ 카테고리 │  검색  │  주문톡①  │ 장바구니② │ 마이  │
└─────┴─────────┴────────┴───────────┴───────────┴───────┘
① 안읽음 있을 때만 배지 숫자(빨간 원)
② 기존 장바구니 뱃지(수량)와 동일 스타일 재사용
```

> 배치는 "장바구니 옆"이라는 요청에 따라 장바구니 **바로 앞**에 둔다(검색 다음, 장바구니 이전). `mall/css/mall.css`의 `.bottom-nav a` 폭 계산이 5등분에서 6등분으로 바뀌므로 Do phase에서 실제 좁은 화면(360px 기준) 시각 확인 필요.

### 5.2 User Flow

```
[고객] 하단 탭 "주문톡" 클릭 → order_chat.php (주문 목록, 최신순, 마지막 메시지 미리보기 + 안읽음 배지)
     → 주문 1건 선택 → 채팅창(해당 주문 대화 전체) → 텍스트 입력 후 전송 → 5초 폴링으로 관리자 응답 수신

[관리자] orders.php 주문 목록(행별 안읽음 배지) → 행 클릭 → 상세 모달 → "채팅" 탭 클릭
     → 대화 전체 표시(+읽음처리) → 텍스트 입력 후 전송 → 모달 열려있는 동안 5초 폴링
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|-----------------|
| 주문톡 탭 | `mall/partials/bottom_nav.php` | 안읽음 배지 표시, `order_chat.php` 링크 |
| 주문 목록(고객) | `mall/order_chat.php` | 내 주문별 마지막 메시지·안읽음 표시, 채팅창 진입점 |
| 채팅창(고객) | `mall/order_chat.php` (같은 파일, `?order_id=`로 분기) | 메시지 목록, 입력창, 폴링 |
| 채팅 탭(관리자) | `mall/admin/orders.php` 주문 상세 모달 | 메시지 목록, 입력창, 폴링, 목록 안읽음 배지 |

### 5.4 Page UI Checklist

#### 고객용 주문톡 목록 화면 (`mall/order_chat.php`, order_id 없이 접근 시)

- [ ] List: 내 주문 목록(최신 주문일시순), 각 행에 주문번호·마지막 메시지 미리보기(1줄)·마지막 발신 시각
- [ ] Badge: 안읽음 메시지가 있는 주문 행에 빨간 점/숫자 배지
- [ ] Empty state: 주문이 아예 없을 때 "주문 후 이용 가능합니다" 안내
- [ ] Empty state: 주문은 있지만 대화가 없을 때도 목록에는 노출(대화 시작 유도 문구)

#### 고객용 채팅창 (`mall/order_chat.php?order_id=N`)

- [ ] Header: 주문번호, 뒤로가기(목록으로)
- [ ] List: 메시지 말풍선(발신자별 좌/우 정렬, 내 메시지 vs 상대 메시지 스타일 구분), 시각 표시
- [ ] Input: 텍스트 입력창 + 전송 버튼(공백만 입력 시 비활성화)
- [ ] Polling: 5초 간격 신규 메시지 갱신(탭 비활성/화면 이탈 시 폴링 중단)

#### 관리자용 채팅 탭 (`mall/admin/orders.php` 주문 상세 모달 내)

- [ ] Tab: "채팅" 탭(기존 "진행 상황"/"고객 정보"/"주문 내역" 탭과 같은 레벨)
- [ ] List: 메시지 말풍선(고객 vs 관리자 구분), 시각 표시
- [ ] Input: 텍스트 입력창 + 전송 버튼
- [ ] Badge: 주문 목록 각 행에 안읽음 메시지 수 배지(현재 8개 컬럼 중 적절한 위치)

---

## 6. Error Handling

### 6.1 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| 400 | VALIDATION_ERROR | 빈 메시지/500자 초과/order_id 누락 | 클라이언트에서 재입력 유도 |
| 401 | UNAUTHORIZED | 비로그인(고객) | 로그인 페이지로 유도 |
| 403 | UNAUTHORIZED | 권한 없음(관리자, `mall_management` 미보유) | 접근 거부 메시지 |
| 403 | CSRF_INVALID | CSRF 토큰 만료 | 새로고침 후 재시도 안내 |
| 404 | VALIDATION_ERROR | 본인 소유가 아닌 주문(고객) / 존재하지 않는 주문 | "대상 주문을 찾을 수 없습니다" (소유권 여부는 노출하지 않음) |
| 500 | SERVER_ERROR | DB 오류 | `error_log()` 기록 후 공통 오류 메시지 |

### 6.2 Error Response Format

기존 `mall/ajax/*.php`, `mall/admin/ajax/*.php`와 동일한 포맷을 그대로 사용한다:

```json
{"success": false, "error": {"code": "VALIDATION_ERROR", "message": "입력값을 확인해주세요"}}
```

---

## 7. Security Considerations

- [ ] 고객 측 모든 조회/발송은 `mall_orders.member_id = 로그인 회원 id`로 소유권 검증(다른 회원 주문 접근 시 404로 응답해 존재 여부도 숨김)
- [ ] 관리자 측은 기존 패턴대로 `is_logged_in()` + `has_permission('mall_management')` 검증
- [ ] 메시지 발송(POST)은 `mall/lib/csrf.php` 검증 필수(기존 admin/ajax 전부 적용된 패턴)
- [ ] `message`는 `htmlspecialchars()` 출력 이스케이프로 XSS 방지(저장은 원문, 출력 시 이스케이프 — 기존 프로젝트 관례)
- [ ] 메시지 길이 서버단 제한(500자) — 클라이언트 검증과 별개로 서버에서도 재검증
- [ ] Rate Limiting은 v1 범위 밖(내부 소규모 서비스 특성상 생략, 남용 시 후속 조치)

---

## 8. Test Plan

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: API Tests | 4개 엔드포인트 — 상태코드, 소유권 검증, CSRF | curl | Do |
| L2: UI Action Tests | 고객 목록/채팅창, 관리자 채팅 탭 — 폼, 배지 | 수동 브라우저 확인(Playwright 미설치 프로젝트) | Do |
| L3: E2E Scenario Tests | 고객↔관리자 왕복 대화 흐름 | 수동 브라우저 확인 | Do |

### 8.2 L1: API Test Scenarios

| # | Endpoint | Method | Test Description | Expected Status | Expected Response |
|---|----------|--------|-------------------|:----------------:|--------------------|
| 1 | `mall/ajax/get_order_chat_messages.php?order_id=X` | GET | 본인 주문 최초 조회 | 200 | `.data.messages` 배열, admin 메시지 read 처리됨 |
| 2 | `mall/ajax/get_order_chat_messages.php?order_id=Y`(타인 주문) | GET | 소유권 없는 주문 조회 시도 | 404 | `.error.code`="VALIDATION_ERROR" |
| 3 | `mall/ajax/send_order_chat_message.php` | POST | 정상 메시지 발송 | 200 | `.data.id` 존재 |
| 4 | `mall/ajax/send_order_chat_message.php` | POST | 공백 메시지 발송 | 400 | `.error.code`="VALIDATION_ERROR" |
| 5 | `mall/admin/ajax/send_order_chat_reply.php` | POST | 권한 없는 계정으로 발송 시도 | 403 | `.error.code`="UNAUTHORIZED" |
| 6 | `mall/admin/ajax/get_order_chat_messages.php?order_id=X&after_id=13` | GET | 증분 폴링(신규 없음) | 200 | `.data.messages` 빈 배열 |

### 8.3 L2: UI Action Test Scenarios

| # | Page | Action | Expected Result | Data Verification |
|---|------|--------|-------------------|--------------------|
| 1 | `order_chat.php`(목록) | 안읽음 있는 주문 행 클릭 | 채팅창 진입 후 배지 사라짐 | DB `is_read_by_member` 1로 갱신 |
| 2 | `order_chat.php`(채팅창) | 메시지 입력 후 전송 | 말풍선 즉시 추가, 입력창 비워짐 | `mall_order_messages`에 신규 행 |
| 3 | `admin/orders.php` 모달 | "채팅" 탭 클릭 | 대화 이력 표시, 목록 배지 사라짐 | `is_read_by_admin` 1로 갱신 |
| 4 | `bottom_nav.php` | 관리자가 답장 후 고객 페이지 새로고침 | "주문톡" 탭에 배지 표시 | 렌더 시 unread count > 0 |

### 8.4 L3: E2E Scenario Test Scenarios

| # | Scenario | Steps | Success Criteria |
|---|----------|-------|--------------------|
| 1 | 왕복 대화 | 고객 발송 → 관리자 목록 배지 확인 → 관리자 응답 → 고객 폴링으로 수신 → 배지 소멸 | 양방향 메시지 모두 정확한 발신자로 표시, 배지가 매 단계 정확 |
| 2 | 타 회원 주문 차단 | 회원A로 로그인, 회원B의 order_id로 API 직접 호출 | 404 응답, 메시지 노출 없음 |

### 8.5 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:--------------:|------------------------|
| `mall_orders` | 2 (서로 다른 member_id) | 테스트용 주문 2건 |
| `mall_order_messages` | 3+ | 양쪽 sender_type 섞어서 읽음/안읽음 케이스 커버 |

---

## 9. Clean Architecture (프로젝트 관례 매핑)

이 프로젝트는 TS 계층형 구조가 아닌 PHP 함수형 컨벤션(`mall/lib/*.php`)을 사용한다. 대응 매핑은 다음과 같다.

| 관례상 레이어 | 역할 | 위치 |
|--------------|------|------|
| Presentation | 화면 렌더 + 폴링 JS | `mall/order_chat.php`, `mall/admin/orders.php` |
| Application/Infrastructure (통합) | 메시지 CRUD, 읽음/안읽음 로직 | `mall/lib/order_chat.php` |
| Infrastructure(공통) | DB 커넥션 | `config/db_config.php`(공용), `get_db_connection()` |

---

## 10. Coding Convention Reference

### 10.1 이 기능에 적용되는 컨벤션

| Item | Convention Applied |
|------|----------------------|
| 함수 네이밍 | `mall_order_chat_*()` — 기존 `mall_order_*()`, `mall_delivery_*()`와 동일 접두 규칙 |
| 파일 조직 | `mall/lib/order_chat.php` 단일 헬퍼 + 고객/관리자 ajax 각각 분리(기존 delivery 패턴) |
| 에러 응답 | `json_error($code, $message, $http)` 헬퍼 함수 각 ajax 파일에 로컬 정의(기존 관례 — 공유 안 함) |
| CSRF | POST 엔드포인트는 `mall_csrf_verify($_POST['csrf_token'] ?? '')` 필수 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
mall/
├── lib/
│   └── order_chat.php                       (신규)
├── order_chat.php                            (신규 — 고객 목록+채팅창)
├── ajax/
│   ├── send_order_chat_message.php           (신규)
│   └── get_order_chat_messages.php            (신규)
├── partials/
│   └── bottom_nav.php                         (수정 — 탭 추가)
└── admin/
    ├── orders.php                             (수정 — 채팅 탭 추가)
    └── ajax/
        ├── send_order_chat_reply.php          (신규)
        └── get_order_chat_messages.php         (신규)

sql/
└── run_add_mall_order_messages_migration.php  (신규)
```

### 11.2 Implementation Order

1. [ ] `sql/run_add_mall_order_messages_migration.php` — `mall_order_messages` 테이블 생성
2. [ ] `mall/lib/order_chat.php` — 헬퍼 함수 6종 구현
3. [ ] 고객용 ajax 2개 + `mall/order_chat.php` 화면(목록+채팅창)
4. [ ] `mall/partials/bottom_nav.php`에 "주문톡" 탭 + 배지 추가
5. [ ] 관리자용 ajax 2개 + `mall/admin/orders.php` 모달에 채팅 탭 추가(목록 배지 포함)
6. [ ] L1 curl 테스트 + L2/L3 수동 브라우저 확인

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|--------------|:------------------:|
| DB + lib 헬퍼 | `module-1` | 마이그레이션, `mall/lib/order_chat.php` | 10-15 |
| 고객 화면 + 하단탭 | `module-2` | `order_chat.php`, 고객 ajax 2개, `bottom_nav.php` 수정 | 15-20 |
| 관리자 화면 | `module-3` | `orders.php` 채팅 탭, 관리자 ajax 2개, 목록 배지 | 15-20 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-------:|
| Session 1 | Plan + Design | 전체 | 완료 |
| Session 2 | Do | `--scope module-1` | 10-15 |
| Session 3 | Do | `--scope module-2` | 15-20 |
| Session 4 | Do | `--scope module-3` | 15-20 |
| Session 5 | Check + Report | 전체 | 20-30 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-30 | Initial draft | whdans007 |
