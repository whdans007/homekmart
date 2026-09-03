---
template: design
version: 1.3
feature: mall-delivery-dispatch
date: 2026-08-30
author: whdans007
project: HOME K MART
version_project: '-'
---

# mall-delivery-dispatch Design Document

> **Summary**: 쇼핑몰 주문의 접수확인/취소, 피킹슬립 인쇄, 자체 배송기사 배정과 실시간 위치 추적, 도착·완료·재배정 처리까지 이어지는 주문 이행(fulfillment) 파이프라인의 상세 기술 설계.
>
> **Project**: HOME K MART
> **Version**: -
> **Author**: whdans007
> **Date**: 2026-08-30
> **Status**: Draft
> **Planning Doc**: [mall-delivery-dispatch.plan.md](../01-plan/features/mall-delivery-dispatch.plan.md)

### Pipeline References (if applicable)

| Phase | Document | Status |
|-------|----------|--------|
| Phase 1 | Schema Definition | N/A (미사용) |
| Phase 2 | Coding Conventions | N/A (CLAUDE.md 컨벤션으로 대체) |
| Phase 3 | Mockup | N/A |
| Phase 4 | API Spec | 본 문서 §4에서 직접 정의 |

> Plan 문서는 Plan Plus(브레인스토밍) 템플릿으로 작성되어 별도 Context Anchor 섹션이 없다. 대신 Plan §1.4 Constraints, §7 Risks, Appendix Brainstorming Log를 전략적 컨텍스트로 참조한다.

---

## 1. Overview

### 1.1 Design Goals

- 기존 `mall_orders` 파이프라인(접수대기→상품준비중)을 배정·배송중·도착·완료·배송실패까지 자연스럽게 확장
- 배송기사 인증/세션을 `mall_members`(고객), admin `users`(관리자)와 완전히 분리해 권한 오염 방지
- 위치 추적/배정/재배정 로직을 `mall/lib/*.php` 함수형 헬퍼로 단일화해 관리자·기사 양쪽 화면에서 동일 로직 재사용
- 재배정 시에도 과거 배정·위치 이력이 사라지지 않도록 이력 기반(append-only) 데이터 모델 채택

### 1.2 Design Principles

- **기존 컨벤션 재사용**: `mall/lib/auth.php`(세션), `mall/lib/order.php`(트랜잭션), `mall/admin/ajax/*.php`(JSON 응답+CSRF+권한) 패턴을 그대로 따른다.
- **이력은 삭제하지 않고 append**: 배정 재시도, 위치 갱신 모두 새 행 INSERT로 처리하고 기존 행은 상태만 종료 처리한다(경로 재생·감사 추적 목적).
- **조회 성능을 위한 최소 캐시**: 위치 이력 테이블 풀스캔을 피하기 위해 `mall_drivers`에 최신 위치(`last_lat`/`last_lng`/`last_seen_at`) 캐시 컬럼을 둔다.
- **네임스페이스 격리**: 배송기사 세션 쿠키명을 `mall_members`(`MALLSESSID`)와 다르게 분리한다.

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 각 페이지에 배정/상태전환 SQL 직접 작성 | Repository/Service 클래스 계층 | `mall/lib/driver.php`, `mall/lib/delivery.php` 함수형 헬퍼 |
| **New Files** | ~16 | ~35 | ~18 |
| **Modified Files** | ~4 | ~4 | ~4 |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Medium | High | High |
| **Effort** | Low | High | Medium |
| **Risk** | High (배정/재배정 로직 여러 파일에 중복 → 버그 위험) | Low (그러나 기존 함수형 컨벤션과 이질적) | Low |
| **Recommendation** | 빠른 프로토타입 | 대규모 팀 | **Default choice** |

**Selected**: Option C — **Rationale**: 사용자가 3개 옵션 비교 후 직접 선택. `mall/lib/auth.php`·`order.php`·`cart.php`와 동일한 함수형 헬퍼 패턴을 따르며, 배정/재배정/상태전환처럼 여러 화면(관리자 배정, 기사 앱, 재배정)에서 공유되는 로직을 `mall/lib/delivery.php` 한 곳에 모아 중복·불일치 위험을 구조적으로 낮춘다.

> 아래 상세 설계는 Option C 기준으로 작성됨.

### 2.1 Component Diagram

```
┌──────────────┐   ┌───────────────────────────┐   ┌──────────────────────┐
│   Browser    │──▶│ mall/admin/*.php (관리자)  │──▶│  MySQL (기존 DB)      │
│ (관리자)      │   │ mall/driver/*.php (기사)   │   │  mall_orders(확장)    │
├──────────────┤   │ mall/order_detail.php(고객)│   │  mall_drivers(신규)   │
│   Browser    │──▶│                            │   │  mall_order_driver_   │
│ (기사, 모바일)│   └─────────────┬──────────────┘   │   assignments(신규)   │
├──────────────┤                 │ require            │  mall_driver_         │
│   Browser    │──▶              ▼                    │   locations(신규)     │
│ (고객)       │   ┌───────────────────────────┐      └──────────────────────┘
└──────────────┘   │ mall/lib/driver.php        │
                    │ mall/lib/delivery.php      │
                    │ mall/lib/order.php (기존)  │
                    └─────────────┬──────────────┘
                                  │ mall_get_db_connection()
                                  ▼
                    ┌───────────────────────────┐
                    │ config/db_config.php (기존)│
                    └───────────────────────────┘

Google Maps JS API: 관리자 delivery_map.php(다중 마커) / 고객 order_detail.php(단일 마커) / 기사 앱 길찾기 딥링크(https://www.google.com/maps/dir/?api=1&destination=lat,lng)
```

### 2.2 Data Flow

```
[준비] 관리자 접수확인(기존) → 상품준비중 → 인쇄(order_print.php) → 관리자 "준비완료" 처리
[배정] 관리자가 활성 기사 선택 → mall_order_assign_driver() → mall_order_driver_assignments INSERT
       + mall_orders.status='assigned', current_driver_id 갱신
[배송] 기사 앱 "배송시작" → mall_delivery_start() → status='delivering'
       → (30초 주기) 기사 앱 위치 전송 → mall_delivery_record_location()
         → mall_driver_locations INSERT + mall_drivers.last_lat/lng/seen_at UPDATE
       → 관리자 delivery_map.php / 고객 order_detail.php가 폴링으로 최신 위치 조회
[도착] 기사 앱 "도착" → mall_delivery_mark_arrived() → status='arrived' → 고객 화면 배너 갱신(폴링)
[완료] 기사 앱 "배송완료" → mall_delivery_complete() → status='completed', assignment.completed_at 기록
[실패] 기사 앱 "배송실패(사유)" → mall_delivery_fail() → status='delivery_failed', assignment.status='failed'
       → 관리자 "재배정" → mall_order_reassign_driver() → 기존 배정 status='reassigned', 새 배정 행 생성 → [배송]부터 반복
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `mall/lib/delivery.php` | `mall/lib/order.php`, `mall_get_db_connection()` | 배정/상태전환/위치기록 트랜잭션 |
| `mall/lib/driver.php` | `mall/config/mall_config.php` | 기사 전용 세션 관리(별도 쿠키명) |
| `mall/driver/*.php` | `mall/lib/driver.php`, `mall/lib/delivery.php` | 기사 앱 화면 |
| `mall/admin/drivers.php`, `delivery_map.php` | `mall/lib/delivery.php` | 관리자 배차/통계/지도 |
| `mall/order_detail.php`(확장) | `mall/lib/delivery.php` | 고객 배송 상태·위치 조회 |

---

## 3. Data Model

### 3.1 mall_orders 확장

```sql
-- status enum 확장(기존 값 유지 + 신규 배송 단계 추가)
ALTER TABLE `mall_orders`
  MODIFY COLUMN `status` enum('pending','confirmed','preparing','ready','assigned','delivering','arrived','completed','cancelled','delivery_failed') NOT NULL DEFAULT 'pending',
  ADD COLUMN `current_driver_id` int(11) DEFAULT NULL AFTER `estimated_ready_at`,
  ADD CONSTRAINT `mall_orders_driver_fk` FOREIGN KEY (`current_driver_id`) REFERENCES `mall_drivers` (`id`);
```

> `ready`(준비완료)는 "배차 대기" 상태로 재사용한다. `confirmed`는 기존 파이프라인 호환을 위해 enum에 남겨두되 이번 기능에서는 사용하지 않는다(접수확인은 곧바로 `preparing`으로 전환하는 기존 로직 유지).

### 3.2 mall_drivers (신규)

```sql
CREATE TABLE `mall_drivers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `driver_type` enum('inhouse','external') NOT NULL DEFAULT 'inhouse' COMMENT '이번 범위는 inhouse만 사용, 추후 외부기사 확장 대비',
  `vehicle_info` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_lat` decimal(10,7) DEFAULT NULL COMMENT '최신 위치 캐시(폴링 조회 성능용)',
  `last_lng` decimal(10,7) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배송기사 계정(mall_members/admin users와 완전 분리)';
```

### 3.3 mall_order_driver_assignments (신규 — 배정 이력)

```sql
CREATE TABLE `mall_order_driver_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `status` enum('assigned','delivering','arrived','completed','failed','reassigned') NOT NULL DEFAULT 'assigned',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivering_at` datetime DEFAULT NULL,
  `arrived_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `failed_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `mall_oda_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_oda_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `mall_drivers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문-기사 배정 이력. 재배정마다 새 행 생성, 기존 행은 상태만 종료 처리';
```

### 3.4 mall_driver_locations (신규 — 위치 이력)

```sql
CREATE TABLE `mall_driver_locations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `driver_order_time` (`driver_id`,`order_id`,`recorded_at`),
  CONSTRAINT `mall_dl_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `mall_drivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_dl_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='기사 위치 이력(경로 재생용). 최신값은 mall_drivers.last_lat/lng 캐시로 조회';
```

### 3.5 Entity Relationships

```
[mall_orders] 1 ──── N [mall_order_driver_assignments] N ──── 1 [mall_drivers]
     │                                                              │
     │ current_driver_id (최신 배정 빠른 조회용, FK)                  │
     └──────────────────────────────────────────────────────────────┘
[mall_drivers] 1 ──── N [mall_driver_locations] N ──── 1 [mall_orders]
```

---

## 4. API Specification

> 이 프로젝트는 REST API가 아닌 **PHP AJAX 엔드포인트 패턴**(`ajax_*.php` → JSON 응답)을 사용한다. Method는 실질적으로 모두 POST(GET은 조회 전용 폴링), 응답은 `{success, data, error}` JSON.

### 4.1 Endpoint List

| Endpoint | Description | Auth |
|--------|------|------|
| `mall/admin/ajax/save_driver.php` | 기사 계정 생성/수정/활성화 토글 | admin + `mall_management` |
| `mall/admin/ajax/assign_driver.php` | 준비완료 주문에 기사 배정 | admin + 권한 |
| `mall/admin/ajax/reassign_driver.php` | 배송실패 주문 재배정 | admin + 권한 |
| `mall/admin/ajax/get_driver_locations.php` | 활성 기사 최신 위치 목록(지도 폴링) | admin + 권한 |
| `mall/admin/ajax/update_order_status.php` | (기존 확장) 취소·상태전환 — valid status 목록에 신규 값 추가 | admin + 권한 |
| `mall/driver/ajax/login.php` | 기사 로그인 | 없음(로그인 자체) |
| `mall/driver/ajax/logout.php` | 기사 로그아웃 | 기사 세션 |
| `mall/driver/ajax/start_delivery.php` | 배송시작(status→delivering) | 기사 세션 + 본인 배정건만 |
| `mall/driver/ajax/update_location.php` | 위치 전송(30초 주기) | 기사 세션 + 본인 배정건만 |
| `mall/driver/ajax/mark_arrived.php` | 도착 처리 | 기사 세션 + 본인 배정건만 |
| `mall/driver/ajax/complete_delivery.php` | 배송완료 처리 | 기사 세션 + 본인 배정건만 |
| `mall/driver/ajax/fail_delivery.php` | 배송실패(사유) 처리 | 기사 세션 + 본인 배정건만 |
| `mall/ajax/get_order_tracking.php` | 고객용 주문 상태+기사 위치 폴링 조회 | mall_members 세션 + 본인 주문만 |

### 4.2 Detailed Specification

#### `POST mall/admin/ajax/assign_driver.php`

**Request:** `order_id`, `driver_id`, `csrf_token`

**처리:** `mall_order_assign_driver($order_id, $driver_id)` 호출 — `mall_order_driver_assignments` INSERT(status=`assigned`) + `mall_orders.status='assigned'`, `current_driver_id` 갱신. 이미 종료되지 않은 배정이 있으면 `ALREADY_ASSIGNED` 오류.

**Response:** `{success:true, data:{order_id, driver_id, status:"assigned"}}`

#### `POST mall/driver/ajax/update_location.php`

**Request:** `order_id`, `lat`, `lng` (기사 세션에서 driver_id 확인)

**처리:** 해당 `order_id`가 현재 로그인한 기사에게 배정되어 있고 상태가 `delivering`일 때만 기록(IDOR + 상태 검증). `mall_driver_locations` INSERT + `mall_drivers.last_lat/last_lng/last_seen_at` UPDATE.

**Response:** `{success:true}`

#### `GET mall/ajax/get_order_tracking.php?order_id=X`

**Request:** `order_id` (쿼리스트링), 회원 세션에서 소유권 검증

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "delivering",
    "driver_location": { "lat": 14.5547, "lng": 121.0244, "recorded_at": "2026-08-30 10:15:00" }
  }
}
```
상태가 `delivering`/`arrived`가 아니면 `driver_location`은 `null`.

**Error Responses** (§6.2 형식 통일):
- `error.code:"UNAUTHORIZED"` — 미로그인/본인 주문 아님
- `error.code:"ALREADY_ASSIGNED"` — 이미 배정된 주문에 재배정 시도(재배정 전용 엔드포인트 사용 안내)
- `error.code:"NOT_ASSIGNED_TO_YOU"` — 기사 본인 배정건이 아닌 order_id로 상태변경 시도
- `error.code:"INVALID_STATE_TRANSITION"` — 예: `assigned` 상태에서 곧바로 `complete_delivery` 호출

---

## 5. UI/UX Design

### 5.1 Screen Layout

```
[관리자] mall/admin/orders.php ── 준비완료 주문에 "기사 배정" 드롭다운+버튼 추가
         mall/admin/order_print.php ── 인쇄 전용 단일 페이지(피킹슬립)
         mall/admin/drivers.php ── 기사 목록/추가/통계
         mall/admin/delivery_map.php ── 전체 활성 기사 지도(폴링)

[기사, 모바일웹] mall/driver/login.php → index.php(배정 목록) → order_detail.php(상세+상태버튼)

[고객] mall/order_detail.php ── 기존 화면에 상태배너 + 지도(배송중/도착일 때만) 추가
```

### 5.2 User Flow

```
[관리자] 접수확인(기존) → 인쇄(피킹슬립) → 상품 준비 → "준비완료" → 기사 배정 → (실패 시) 재배정 → 완료 확인
[기사] 로그인 → 배정 목록(여러 건) → 주문 선택 → 길찾기 열기 → 배송시작(위치전송 시작) → 도착 → 완료/실패
[고객] mypage/orders.php → order_detail.php → (배송중이면) 지도 + 상태배너 자동 갱신 → 완료
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| 피킹슬립 인쇄뷰 | `mall/admin/order_print.php` | 사진/바코드/한·영 상품명/수량/금액/합계 인쇄 레이아웃 |
| 기사 배정 위젯 | `mall/admin/orders.php` (인라인) | 활성 기사 드롭다운 + 배정/재배정 버튼 |
| 실시간 지도(관리자) | `mall/admin/delivery_map.php` | 전체 활성 기사 마커 폴링 표시 |
| 실시간 지도(고객) | `mall/order_detail.php` (인라인) | 단일 기사 마커 폴링 표시 |
| 기사 주문 카드 | `mall/driver/index.php` | 배정된 주문 요약 + 상태별 액션 버튼 |

### 5.4 Page UI Checklist

#### 관리자 — 주문 관리 (`mall/admin/orders.php`, 기존 화면 확장)
- [ ] 버튼: "취소" — `preparing` 상태에서만 노출, 클릭 시 `pending`으로 되돌림(기존 "접수확인" 버튼 옆)
- [ ] 위젯: "기사 배정" — `ready` 상태에서만 노출, 활성 기사 드롭다운(현재 진행중 배송건수 함께 표시) + 배정 버튼
- [ ] 위젯: "재배정" — `delivery_failed` 상태에서만 노출, 실패 사유 표시 + 다른 기사 드롭다운
- [ ] 상태뱃지: 신규 상태(배정됨/배송중/도착/배송실패) 라벨 매핑
- [ ] 링크: "피킹슬립 인쇄" 버튼 → `order_print.php?id=X` 새 창

#### 관리자 — 피킹슬립 인쇄 (`mall/admin/order_print.php`, 신규)
- [ ] 상품별: 사진 썸네일, 바코드(JsBarcode 렌더링), 한글 상품명, 영문 상품명, 수량, 단가, 금액
- [ ] 합계 영역: 소계, 할인금액, 배송비, 총합계
- [ ] 버튼: "인쇄"(`window.print()`), `@media print`로 버튼/네비게이션 숨김

#### 관리자 — 기사 관리 (`mall/admin/drivers.php`, 신규)
- [ ] 목록: 이름/연락처/활성여부/차량정보
- [ ] 폼: 기사 추가(이름/연락처/비밀번호/차량정보)
- [ ] 토글: 활성/비활성
- [ ] 통계: 기사별 배송 건수, 평균 소요시간(배정→완료)

#### 관리자 — 실시간 배송 지도 (`mall/admin/delivery_map.php`, 신규)
- [ ] 지도: 배송중 상태인 모든 기사 마커(15초 폴링 갱신)
- [ ] 마커 클릭 시: 기사 이름 + 진행중인 주문번호 팝업

#### 기사 앱 — 로그인 (`mall/driver/login.php`, 신규)
- [ ] 폼: 연락처 + 비밀번호

#### 기사 앱 — 배정 목록 (`mall/driver/index.php`, 신규)
- [ ] 목록: 배정된 주문 카드(주문번호, 배송지 요약, 상태뱃지) — 여러 건 동시 표시
- [ ] 정렬: 배정 시각순(수동 우선순위 조정 없음, v1)

#### 기사 앱 — 주문 상세 (`mall/driver/order_detail.php`, 신규)
- [ ] 정보: 고객명/연락처, 배송지(지역/시/바랑가이/상세주소/랜드마크), 상품목록
- [ ] 버튼: "Google 지도 길찾기 열기"(딥링크, 새 탭)
- [ ] 버튼: 상태별 1개만 노출 — "배송시작"(assigned) / "도착"+"배송실패"(delivering) / "배송완료"(arrived)
- [ ] 모달: 배송실패 클릭 시 사유 입력창

#### 고객 — 주문 상세 (`mall/order_detail.php`, 기존 화면 확장)
- [ ] 배너: 배송 상태 텍스트(배정됨/배송중/도착/완료), 도착 시 강조 스타일
- [ ] 지도: 배송중/도착 상태일 때만 노출, 기사 위치 마커(15초 폴링)

---

## 6. Error Handling

### 6.1 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| UNAUTHORIZED | 로그인이 필요합니다 | 세션 없음/만료 | 로그인 페이지로 이동 |
| ALREADY_ASSIGNED | 이미 배정된 주문입니다 | 종료되지 않은 배정이 이미 존재 | 재배정 엔드포인트 안내 |
| NOT_ASSIGNED_TO_YOU | 본인에게 배정된 주문이 아닙니다 | 기사가 타인 배정건 order_id로 접근(IDOR 시도) | 403, 목록으로 리다이렉트 |
| INVALID_STATE_TRANSITION | 처리할 수 없는 상태입니다 | 상태 순서를 건너뛴 요청(예: assigned에서 complete 호출) | 현재 상태 재조회 후 안내 |
| VALIDATION_ERROR | 입력값을 확인해주세요 | 필수값 누락/형식 오류 | 필드별 오류 메시지 표시 |

### 6.2 Error Response Format

```json
{
  "success": false,
  "error": { "code": "NOT_ASSIGNED_TO_YOU", "message": "본인에게 배정된 주문이 아닙니다", "details": {} }
}
```

---

## 7. Security Considerations

- [ ] 모든 쿼리 prepared statement 사용, 문자열 결합 SQL 금지
- [ ] `mall_drivers.password_hash`는 `password_hash()`/`password_verify()` 사용
- [ ] **기사 세션 분리**: 별도 세션 쿠키명(`MALLDRIVERSESSID`) 사용, `mall_members`/admin 세션과 교차 오염 금지
- [ ] **IDOR 방지**: 기사 앱의 모든 상태전환/위치전송 엔드포인트는 `order_id`가 현재 로그인 기사에게 배정된 건인지 매 요청 WHERE 절에서 검증(`mall_order_driver_assignments` JOIN)
- [ ] **상태 전이 검증**: 서버에서 현재 상태→요청 상태 전이가 허용된 순서인지 확인 후에만 UPDATE(클라이언트가 버튼 순서를 건너뛰어도 서버가 최종 방어)
- [ ] 고객 위치 조회(`get_order_tracking.php`)는 본인 주문(`member_id` 일치)만 반환
- [ ] 관리자 화면은 기존 `require_permission('mall_management', ...)` 패턴 재사용
- [ ] CSRF 토큰: 상태를 변경하는 모든 POST(관리자/기사 앱)에 기존 `mall/lib/csrf.php` 패턴 적용

---

## 8. Test Plan

> Playwright/E2E 프레임워크 미구성 — L1은 curl 기반 AJAX 테스트, L2/L3는 수동 브라우저 시나리오 체크리스트로 대체(Do phase에서 절차 문서화, Check phase에서 수행).

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: AJAX 엔드포인트 테스트 | `mall/admin/ajax/*.php`, `mall/driver/ajax/*.php`, `mall/ajax/get_order_tracking.php` | curl | Do |
| L2: UI 동작 테스트 | §5.4 체크리스트 요소별 동작 | 수동 브라우저 체크리스트 | Do/Check |
| L3: E2E 시나리오 | 관리자→기사→고객 전체 흐름 | 수동 멀티 브라우저 시나리오 | Do/Check |

### 8.2 L1: API Test Scenarios

| # | Endpoint | Method | Test Description | Expected Status | Expected Response |
|---|----------|--------|-----------------|:--------------:|-------------------|
| 1 | `assign_driver.php` | POST | 준비완료 주문에 활성 기사 배정 | 200 | `data.status="assigned"` |
| 2 | `assign_driver.php` | POST | 이미 배정된 주문 재배정 시도 | 400 | `error.code="ALREADY_ASSIGNED"` |
| 3 | `driver/ajax/update_location.php` | POST | 배송중 아닌 주문에 위치 전송 시도 | 400 | `error.code="INVALID_STATE_TRANSITION"` |
| 4 | `driver/ajax/update_location.php` | POST | 타 기사 배정건에 위치 전송 시도 | 403 | `error.code="NOT_ASSIGNED_TO_YOU"` |
| 5 | `driver/ajax/complete_delivery.php` | POST | assigned 상태에서 곧바로 완료 시도(단계 스킵) | 400 | `error.code="INVALID_STATE_TRANSITION"` |
| 6 | `get_order_tracking.php` | GET | 타인 주문 조회 시도 | 401/404 | `error.code="UNAUTHORIZED"` |
| 7 | `reassign_driver.php` | POST | 배송실패 주문 재배정 | 200 | 새 assignment 행 생성, 기존 행 status="reassigned" |

### 8.3 L2: UI Action Test Scenarios

| # | Page | Action | Expected Result | Data Verification |
|---|------|--------|----------------|-------------------|
| 1 | `mall/admin/orders.php` | "취소" 클릭(preparing) | 상태가 접수대기로 되돌아감 | DB status='pending' |
| 2 | `mall/admin/orders.php` | 준비완료 주문에 기사 배정 | 상태뱃지 "배정됨"으로 변경 | current_driver_id 채워짐 |
| 3 | `mall/admin/order_print.php` | 인쇄 버튼 클릭 | 인쇄 미리보기에 상품/바코드/합계 모두 표시 | §5.4 체크리스트 전 항목 렌더링 |
| 4 | `mall/driver/order_detail.php` | "배송시작" 클릭 | 위치 전송 시작(30초 간격 네트워크 요청 확인) | mall_driver_locations 행 증가 |
| 5 | `mall/order_detail.php`(고객) | 배송중 상태에서 페이지 로드 | 지도에 기사 마커 표시, 15초마다 갱신 | 폴링 응답에 driver_location 존재 |
| 6 | `mall/driver/order_detail.php` | "배송실패" 사유 입력 후 제출 | 관리자 화면에 실패 사유 노출 | mall_orders.status='delivery_failed' |

### 8.4 L3: E2E Scenario Test Scenarios

| # | Scenario | Steps | Success Criteria |
|---|----------|-------|-----------------|
| 1 | 정상 배송 전체 흐름 | 관리자 접수확인→인쇄→준비완료→배정 → 기사 로그인→배송시작→도착→완료 → 고객 화면에서 상태 변화 확인 | 각 단계 전환마다 고객 화면이 폴링으로 반영됨 |
| 2 | 배송실패→재배정 흐름 | 기사 배송실패(사유) → 관리자 재배정 → 새 기사 배송시작→완료 | 실패 이력과 재배정 이력이 모두 assignments 테이블에 남음 |
| 3 | IDOR 방어 확인 | 기사A로 로그인해 기사B에게 배정된 order_id로 상태변경 API 직접 호출 | 403 NOT_ASSIGNED_TO_YOU 반환, 상태 변경 안 됨 |

### 8.5 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| mall_drivers | 2 | is_active=1(1명), is_active=0(1명, 배정 드롭다운 제외 검증용) |
| mall_orders | 3 | status='ready'(배정 대상), status='delivering'(위치추적 대상), status='delivery_failed'(재배정 대상) |

---

## 9. Clean Architecture (프로젝트 컨벤션 매핑)

> 이 프로젝트는 TypeScript/React 계층 대신 **Procedural PHP + `lib/*_helper.php` 함수형 헬퍼** 컨벤션을 사용한다(CLAUDE.md 기준). Option C 선택에 따라 아래처럼 매핑한다.

### 9.1 Layer Structure

| Layer | Responsibility | Location |
|-------|---------------|----------|
| **Presentation** | 화면 렌더링, 폼/버튼, JS fetch 호출 | `mall/admin/*.php`, `mall/driver/*.php`, `mall/order_detail.php` |
| **Application** | 배정/상태전환/위치기록 유스케이스 | `mall/lib/delivery.php`, `mall/lib/driver.php` |
| **Infrastructure** | DB 커넥션, 세션 | `mall/config/mall_config.php`(`mall_get_db_connection()`), `config/db_config.php` |

### 9.2 File Import Rules

| From | Can Import | Cannot Import |
|------|-----------|---------------|
| Presentation(`mall/admin/*.php`, `mall/driver/*.php`) | `mall/lib/*.php` | DB 직접 쿼리(반드시 lib 함수 경유) |
| `mall/lib/delivery.php` | `mall/lib/order.php`, `mall_get_db_connection()` | Presentation 파일 require 금지 |

### 9.3 This Feature's Layer Assignment

| Component | Layer | Location |
|-----------|-------|----------|
| 배정/재배정/상태전환/위치기록 함수 | Application | `mall/lib/delivery.php` |
| 기사 세션/로그인 함수 | Application | `mall/lib/driver.php` |
| 관리자 배차 화면 | Presentation | `mall/admin/orders.php`, `drivers.php`, `delivery_map.php` |
| 기사 앱 화면 | Presentation | `mall/driver/*.php` |

---

## 10. Coding Convention Reference

### 10.1 Naming Conventions

| Target | Rule | Example |
|--------|------|---------|
| PHP 함수 | `mall_` 접두사 + snake_case | `mall_order_assign_driver()`, `mall_delivery_record_location()` |
| DB 테이블 | `mall_` 접두사, snake_case | `mall_drivers`, `mall_order_driver_assignments` |
| ajax 응답 | `{success, data, error}` JSON | 기존 `mall/ajax/*.php` 동일 |
| 세션 쿠키명 | UPPER_SNAKE + SESSID | `MALLDRIVERSESSID` |

### 10.4 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| 함수 위치 | 배정/상태전환/위치기록은 전부 `mall/lib/delivery.php`, 세션은 `mall/lib/driver.php`로 분리 |
| 상태 전이 검증 | 모든 상태변경 함수 내부에서 현재 상태를 재조회 후 허용된 전이인지 확인(서버 최종 방어) |
| 에러 처리 | 기존 `json_error()` 헬퍼 패턴(각 ajax 파일 상단 정의) 재사용 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
mall/
├── lib/
│   ├── driver.php              (신규) 기사 세션/인증
│   └── delivery.php            (신규) 배정/재배정/상태전환/위치기록/통계
├── driver/                     (신규 디렉터리)
│   ├── login.php
│   ├── index.php
│   ├── order_detail.php
│   └── ajax/
│       ├── login.php
│       ├── logout.php
│       ├── start_delivery.php
│       ├── update_location.php
│       ├── mark_arrived.php
│       ├── complete_delivery.php
│       └── fail_delivery.php
├── admin/
│   ├── orders.php              (수정) 취소/배정/재배정 UI 추가
│   ├── order_print.php         (신규) 피킹슬립 인쇄
│   ├── drivers.php             (신규) 기사 관리 + 통계
│   ├── delivery_map.php        (신규) 실시간 지도
│   └── ajax/
│       ├── save_driver.php     (신규)
│       ├── assign_driver.php   (신규)
│       ├── reassign_driver.php (신규)
│       ├── get_driver_locations.php (신규)
│       └── update_order_status.php  (수정) valid status 목록 확장
├── ajax/
│   └── get_order_tracking.php  (신규) 고객용 폴링
└── order_detail.php            (수정) 배송 배너 + 지도

sql/
├── mall_schema.sql             (수정) 신규 테이블 3종 + mall_orders 확장 반영
└── run_add_mall_delivery_dispatch_migration.php (신규) 실행용 마이그레이션
```

### 11.2 Implementation Order

1. [ ] DB 마이그레이션(mall_orders 확장 + 신규 테이블 3종) + mall_schema.sql 반영
2. [ ] `mall/lib/driver.php`(기사 세션/인증) + `mall/lib/delivery.php`(배정/상태전환/위치기록) 구현
3. [ ] 관리자 화면(취소 버튼, 배정/재배정, 피킹슬립 인쇄, 기사 관리, 실시간 지도)
4. [ ] 기사 앱(로그인, 배정 목록, 주문 상세, 상태 버튼, 위치 전송)
5. [ ] 고객 화면(order_detail.php 배너+지도) + 폴링 엔드포인트
6. [ ] L1/L2/L3 테스트 수행 + 문서화

### 11.3 Session Guide

> `/pdca do mall-delivery-dispatch --scope module-N`으로 모듈별 세션 분리 가능.

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| DB + lib 헬퍼 | `module-1` | 마이그레이션, `mall/lib/driver.php`, `mall/lib/delivery.php` | 30-40 |
| 관리자 화면 | `module-2` | 취소/배정/재배정 UI, 피킹슬립 인쇄, 기사 관리, 실시간 지도 | 50-60 |
| 기사 앱 | `module-3` | 로그인, 배정 목록, 주문 상세, 상태 버튼, 위치 전송 | 40-50 |
| 고객 화면 + 폴링 | `module-4` | order_detail.php 배너/지도, get_order_tracking.php | 20-30 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 | 완료 |
| Session 2 | Do | `--scope module-1` | 30-40 |
| Session 3 | Do | `--scope module-2` | 50-60 |
| Session 4 | Do | `--scope module-3` | 40-50 |
| Session 5 | Do | `--scope module-4` | 20-30 |
| Session 6 | Check + Report | 전체 | 30-40 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-30 | Initial draft (Option C 선택 반영) | whdans007 |
