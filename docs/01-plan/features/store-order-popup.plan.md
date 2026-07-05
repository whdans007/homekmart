# Plan: 점포 신규 주문 실시간 팝업 알림 (store-order-popup)

> **Feature**: store-order-popup
> **Phase**: Plan
> **Date**: 2026-06-17
> **Author**: whdans007

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 점포(store)에서 주문을 넣어도 물류센터(logistics) 담당자는 주문 목록 화면을 직접 열어 새로고침하기 전까지 신규 주문이 들어온 사실을 모름 → 처리 지연 |
| **Solution** | logistics 공통 푸터에 폴링 스크립트를 주입하여 어떤 화면에 있든 신규 `pending` 주문을 20초마다 감지, 새 주문 발생 시 알림음과 함께 팝업 표시 |
| **UX Effect** | 물류 담당자: 어느 logistics 페이지에 있든 점포 주문이 들어오면 즉시 팝업으로 인지 → '주문 보기'로 상세 이동, '닫기'로 해제. 화면 전환/수동 새로고침 불필요 |
| **Core Value** | 점포 주문의 누락·지연 없는 즉시 인지. 웹소켓 등 인프라 변경 없이 기존 PHP/mysqli 스택 위에서 경량 폴링으로 구현 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 점포 주문이 들어와도 logistics가 즉시 알 수 없어 처리가 지연됨 |
| **WHO** | logistics 스태프(`lc_require_staff`) — 모든 물류센터 사용자 |
| **RISK** | 폴링 부하/중복 알림, 다중 담당자 동시 알림, 알림음 자동재생 브라우저 정책 |
| **SUCCESS** | 새 점포 주문 발생 후 최대 ~20초 내 모든 logistics 화면에서 팝업 노출 |
| **SCOPE** | 신규 `pending` 주문 감지 팝업(알림). 주문 승인/처리 로직은 범위 외 |

---

## 1. User Intent Discovery

### 1.1 핵심 문제
- 점포가 `store/order.php`로 주문 → `lc_orders`에 `status='pending'` 저장되지만, logistics는 `orders.php`를 열어 새로고침해야만 확인 가능
- 담당자가 입고/재고 등 다른 logistics 화면에서 작업 중이면 신규 주문을 전혀 인지하지 못함
- 결과적으로 주문 처리(승인/출고)가 지연됨

### 1.2 대상 사용자
| 역할 | 수행 업무 |
|------|-----------|
| logistics 스태프 | 모든 물류센터 사용자. 화면에 떠 있는 동안 신규 점포 주문 알림을 받음 |
| 점포(store) 사용자 | (간접) 주문을 생성하는 주체. 본 기능은 logistics 측 알림만 다룸 |

### 1.3 성공 기준 (Success Criteria)
- [ ] **SC-1**: 점포 신규 주문(`status='pending'`) 발생 시, 열려 있는 모든 logistics 화면에서 최대 약 20초 내 팝업이 뜬다
- [ ] **SC-2**: 팝업에 점포명·주문번호(#0001)·금액·항목 수가 표시된다
- [ ] **SC-3**: '주문 보기' 클릭 시 해당 `order_detail.php`로 이동한다
- [ ] **SC-4**: '닫기'(확인) 후 동일 주문으로는 다시 팝업이 뜨지 않는다 (브라우저별 마지막 확인 시점 기준)
- [ ] **SC-5**: 새 주문 발생 시 알림음이 재생된다 (브라우저 자동재생 정책 허용 범위 내)
- [ ] **SC-6**: 신규 주문이 없으면 어떤 UI 변화도 없고, 폴링은 화면 부하를 유발하지 않는다 (가벼운 카운트/ID 조회)

---

## 2. Alternatives Explored

| 방식 | 장점 | 단점 | 결정 |
|------|------|------|------|
| **A: 공통 푸터 + AJAX 폴링(20s) + localStorage 워터마크** | **기존 스택 그대로, 전역 적용 간단, 다중 담당자 각자 알림** | 실시간은 아님(최대 20s 지연), 폴링 트래픽 | **✅ 채택** |
| B: WebSocket / SSE 실시간 푸시 | 즉시성 | PHP 스택에 상시 연결 서버 필요, 인프라 변경 큼 | ❌ 과도 |
| C: orders.php 화면에서만 갱신 | 구현 최소 | 다른 화면에선 알림 못 받음 → 본 문제 미해결 | ❌ |

**채택 핵심**: "마지막으로 본 주문 ID"를 브라우저 `localStorage`에 저장. 폴링 응답의 최신 pending 주문 중 워터마크보다 큰 ID가 있으면 팝업. 담당자별(브라우저별) 독립 알림이 자연스럽게 동작.

---

## 3. YAGNI Review

### 3.1 1차 버전 포함 (In Scope)
- [x] logistics `partials/footer.php`에 전역 폴링 스크립트 주입
- [x] 신규 pending 주문 조회 AJAX 엔드포인트 (`logistics/ajax/check_new_orders.php`)
- [x] 팝업 UI(점포명/주문번호/금액/항목수 + 주문 보기 링크 + 닫기)
- [x] 20초 폴링 + localStorage 워터마크 기반 중복 방지
- [x] 알림음 재생 (ON)
- [x] 다건 동시 발생 시 "새 주문 N건" 요약 + 최신 건 우선 표시

### 3.2 2차 버전으로 미룸 (Out of Scope)
- [ ] 팝업 내 즉시 승인(Approve) 버튼
- [ ] 서버측 "확인됨" 상태 저장(현재: 브라우저별 localStorage)
- [ ] 브라우저 데스크톱 알림(Notification API) / 모바일 푸시
- [ ] store 외 다른 이벤트(취소 요청 등) 알림 통합
- [ ] 알림 주기/소리 사용자 설정 UI

---

## 4. Requirements

### 4.1 기능 요구사항 (FR)
| ID | 요구사항 |
|----|----------|
| FR-1 | logistics 모든 페이지 로드 시 폴링 스크립트가 동작한다 (공통 footer) |
| FR-2 | AJAX 엔드포인트는 최근 pending 주문(예: 최근 N건 또는 `id > since`)을 `id, store_name, order_no, total_amount, item_count, created_at` 형태로 반환한다 |
| FR-3 | 클라이언트는 localStorage의 `lc_last_seen_order_id`보다 큰 pending 주문이 있으면 팝업을 띄운다 |
| FR-4 | 팝업의 '주문 보기'는 `logistics/order_detail.php?id={id}`로 이동 |
| FR-5 | '닫기' 시 워터마크를 표시된 최대 주문 ID로 갱신하여 재알림 방지 |
| FR-6 | 새 주문 감지 시 알림음 재생 |

### 4.2 비기능 요구사항 (NFR)
| ID | 요구사항 |
|----|----------|
| NFR-1 | AJAX 응답은 가볍게(인덱스 활용, LIMIT) — 폴링 주기 20s |
| NFR-2 | 인증 필수(`lc_require_staff`), 비로그인/세션만료 시 조용히 무시 |
| NFR-3 | 폴링 실패(네트워크 오류) 시 다음 주기에 재시도, UI 깨지지 않음 |
| NFR-4 | 기존 logistics 화면/스크립트와 충돌 없음 (전역 네임스페이스 오염 최소화) |

---

## 5. 데이터 / 구현 스케치 (Plan 수준)

- **데이터 소스**: `lc_orders o LEFT JOIN stores s ON o.store_id = s.id`, `status='pending'`, 항목수는 `lc_order_items` 카운트
- **주문번호 표기**: `#` + `str_pad(id, 4, '0', STR_PAD_LEFT)` (기존 규칙과 동일)
- **신규 추가 파일**: `logistics/ajax/check_new_orders.php`
- **수정 파일**: `logistics/partials/footer.php` (스크립트/팝업 마크업 주입)
- **클라이언트 상태**: `localStorage['lc_last_seen_order_id']`
- **알림음**: 짧은 비프(데이터 URI 또는 `logistics/` 정적 자산), 사용자 상호작용 정책 고려

> 상세 설계(엔드포인트 응답 스펙, 팝업 DOM 구조, 워터마크 초기화 규칙, 알림음 자산)는 Design 단계에서 확정.

---

## 6. Risks & Mitigations

| 리스크 | 영향 | 완화 |
|--------|------|------|
| 브라우저 자동재생 정책으로 알림음 차단 | 소리 안 남 | 첫 사용자 상호작용 이후 오디오 unlock, 실패해도 팝업은 정상 |
| 최초 진입 시 과거 pending 주문이 한꺼번에 팝업 | 첫 화면 스팸 | 최초 로드시 현재 최대 pending ID로 워터마크 초기화(과거분 무시) |
| 다수 logistics 화면/탭에서 중복 알림 | 거슬림 | 브라우저별 1회, localStorage 공유로 탭 간 워터마크 동기화 |
| 폴링 트래픽 누적 | 경미한 부하 | 20s 주기 + 경량 쿼리(LIMIT) + 인덱스(`status`, `id`) |

---

## 7. Out of Scope (명시)
- 주문 승인/출고/상태 변경 등 처리 로직
- store 측 화면 변경
- 서버측 읽음 처리, 데스크톱/모바일 푸시 알림
