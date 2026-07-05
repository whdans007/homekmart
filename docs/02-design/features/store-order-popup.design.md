# Design: 점포 신규 주문 실시간 팝업 알림 (store-order-popup)

**Feature**: store-order-popup
**Phase**: Design
**Architecture**: C — 실용 균형 (인라인 footer 스크립트 + localStorage 워터마크, 스키마 변경 없음)
**Created**: 2026-06-17

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

## 1. 아키텍처 결정 (Option C)

### 선택 이유
- **스키마 변경 없음**: `lc_orders`/`stores`/`lc_order_items` 기존 테이블만 조회
- **프로젝트 관례 준수**: logistics는 정적 JS 자산 디렉토리가 없고 인라인 `<script>`를 사용 → footer에 인라인 주입
- **전역 적용**: logistics 공통 `partials/footer.php`는 사용자용 주요 화면(index/inventory/inbound/orders/products 등)에 모두 포함됨
- **실사용 견고성**: 최초 로드 시 과거 주문 스팸 방지(워터마크 초기화), 알림음 unlock, 네트워크 오류 내성 포함

### 구성 요소
```
[Store] store/order.php  ──INSERT──▶  lc_orders(status='pending')
                                            │
[Logistics any page] partials/footer.php (인라인 polling JS, 20s)
        │  fetch ?since=<localStorage 워터마크>
        ▼
logistics/ajax/check_new_orders.php  ──SELECT──▶ lc_orders + stores + lc_order_items
        │  JSON { orders:[...], latest_id }
        ▼
신규 주문 존재 시 → 팝업 DOM 표시 + 알림음 → '주문 보기' / '닫기'
```

### 변경 요약
| 구분 | 파일 | 작업 |
|------|------|------|
| 신규 | `logistics/ajax/check_new_orders.php` | 신규 pending 주문 조회 JSON 엔드포인트 |
| 수정 | `logistics/partials/footer.php` | 팝업 마크업 + 인라인 폴링 스크립트 주입 |

---

## 2. AJAX 엔드포인트 설계

### 2.1 파일: `logistics/ajax/check_new_orders.php`

**인증/헤더 관례** (`add_product.php`와 동일):
```php
<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');
lc_require_staff();   // 비인증 시 리다이렉트 → 클라이언트는 JSON 파싱 실패로 조용히 무시 (NFR-2)
```

**입력 (GET)**
| 파라미터 | 타입 | 기본 | 설명 |
|----------|------|------|------|
| `since` | int | 0 | 클라이언트가 마지막으로 본 주문 ID. 이보다 큰 pending 주문만 신규로 간주 |

**쿼리 (개념)**
```sql
SELECT o.id,
       s.name                         AS store_name,
       o.total_amount,
       o.created_at,
       (SELECT COUNT(*) FROM lc_order_items oi WHERE oi.order_id = o.id) AS item_count
FROM lc_orders o
LEFT JOIN stores s ON o.store_id = s.id
WHERE o.status = 'pending'
  AND o.id > ?              -- since
ORDER BY o.id DESC
LIMIT 20;                   -- 폭주 대비 상한
```
- `latest_id` = 현재 pending 주문의 최대 id (신규 없을 때 워터마크 초기화/동기화용). 별도 `SELECT MAX(id) ... WHERE status='pending'` 또는 결과 첫 행에서 도출.
- `order_no` 는 클라이언트에서 `#0001` 형식으로 포맷(서버는 raw id 반환) 또는 서버에서 `str_pad`로 생성. **본 설계는 서버에서 `order_no` 문자열도 함께 반환**해 일관성 확보.

**응답 (성공)**
```json
{
  "success": true,
  "latest_id": 142,
  "orders": [
    {
      "id": 142,
      "order_no": "#0142",
      "store_name": "강남점",
      "total_amount": 153000,
      "item_count": 7,
      "created_at": "2026-06-17 14:22:10"
    }
  ]
}
```
- 신규 없음: `{ "success": true, "latest_id": 141, "orders": [] }`
- 오류: `{ "success": false }` (HTTP 200, 메시지 불필요 — 클라이언트는 조용히 다음 주기 재시도)

### 2.2 보안/성능
- prepared statement 바인딩(`since` int)
- `LIMIT 20` 으로 응답 크기 제한 (NFR-1)
- 인덱스: `lc_orders(status)`, PK(`id`) 활용 — `status='pending' AND id > ?` 범위 스캔. 필요 시 `(status, id)` 복합 인덱스 권장(2차).

---

## 3. 클라이언트 설계 (footer 인라인 스크립트)

### 3.1 상태
| 저장소 | 키 | 용도 |
|--------|----|----|
| `localStorage` | `lc_last_seen_order_id` | 마지막으로 확인한 주문 ID 워터마크 (탭/브라우저 공유) |
| 메모리 | `audioUnlocked` (bool) | 사용자 상호작용 후 알림음 재생 가능 여부 |

### 3.2 동작 흐름
```
[페이지 로드]
  watermark = localStorage['lc_last_seen_order_id']
  if (watermark 없음):           // 최초 진입 → 과거 주문 스팸 방지
      fetch(since=0) 1회
      localStorage['lc_last_seen_order_id'] = response.latest_id  (또는 0)
      팝업 표시하지 않음
  startPolling()

[startPolling: setInterval 20초]
  watermark = localStorage['lc_last_seen_order_id'] (매회 재읽기 → 탭 동기화)
  fetch(`check_new_orders.php?since=${watermark}`)
    .then(JSON)
    .then(data):
        if (!data.success) return                 // 조용히 무시
        if (data.orders.length > 0):
            showPopup(data.orders)
            playBeep()                            // audioUnlocked일 때만
    .catch(() => {})                              // 네트워크 오류 무시 (NFR-3)

[showPopup(orders)]
  - 헤더: "🔔 새 점포 주문 N건"
  - 본문: 최신 주문(orders[0]) 요약 — 점포명 · order_no · 금액 · 항목수
          (N>1이면 "외 N-1건" 표기)
  - 버튼: [주문 보기] → location = order_detail.php?id=orders[0].id
          [닫기]      → closePopup()
  - 닫기/주문보기 시: localStorage['lc_last_seen_order_id'] = max(orders[*].id)
                       (= orders[0].id, DESC 정렬이므로)

[오디오 unlock]
  document 첫 click/keydown 시 audioUnlocked = true (1회)
```

### 3.3 팝업 DOM (footer에 마크업 또는 JS 동적 생성)
- 우하단 고정 토스트형 카드(`fixed bottom-4 right-4 z-50`), 기존 모달과 z-index 충돌 회피
- 스타일은 인라인 `style`/공통 클래스 혼용 (logistics는 컴파일 CSS 일부 유틸 누락 가능 → 폭/위치는 인라인 style 권장 — [[project_logistics_css]])

예시 구조:
```html
<div id="newOrderToast" class="hidden" style="position:fixed;right:1rem;bottom:1rem;z-index:60;width:20rem">
  <div class="bg-white rounded-xl shadow-xl border border-teal-200 overflow-hidden">
    <div class="px-4 py-2.5 bg-teal-600 text-white flex items-center justify-between">
      <span class="text-sm font-semibold"><i class="fas fa-bell mr-1.5"></i><span id="noToastTitle">새 점포 주문</span></span>
      <button type="button" onclick="lcCloseNewOrderToast()" class="text-white/80 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="px-4 py-3">
      <p id="noToastStore" class="text-sm font-semibold text-gray-900"></p>
      <p id="noToastMeta"  class="text-xs text-gray-500 mt-0.5"></p>
    </div>
    <div class="px-4 py-2.5 border-t border-gray-100 flex gap-2">
      <a id="noToastViewLink" href="#" class="flex-1 text-center py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">주문 보기</a>
      <button type="button" onclick="lcCloseNewOrderToast()" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-sm rounded-md hover:bg-gray-200">닫기</button>
    </div>
  </div>
</div>
```

### 3.4 알림음
- WebAudio `OscillatorNode`로 짧은 비프(약 0.15s, 880Hz) 생성 → 외부 음원 파일 불필요
- `audioUnlocked === true`일 때만 재생(자동재생 정책). 차단되어도 팝업은 정상(SC-5는 best-effort)

---

## 4. Success Criteria 매핑

| SC | 충족 방법 |
|----|-----------|
| SC-1 | footer 전역 주입 + 20s 폴링 → 모든 logistics 화면에서 ~20초 내 노출 |
| SC-2 | 응답의 store_name/order_no/total_amount/item_count 를 팝업에 렌더 |
| SC-3 | '주문 보기' → `order_detail.php?id={id}` |
| SC-4 | 닫기/보기 시 워터마크를 표시 최대 id로 갱신 → 재알림 차단 |
| SC-5 | WebAudio 비프 + unlock 처리 |
| SC-6 | 신규 없으면 DOM 변화 없음, 경량 쿼리(LIMIT 20) |

---

## 5. 엣지 케이스 / 예외 처리

| 상황 | 처리 |
|------|------|
| 최초 진입(워터마크 없음) | latest_id로 초기화, 과거 pending 팝업 안 띄움 |
| 세션 만료 | 엔드포인트 리다이렉트 → 클라이언트 JSON 파싱 실패 → 무시, 다음 주기 재시도 |
| 네트워크 오류 | `.catch` 무시, 다음 주기 재시도 |
| 다중 탭 | 매 폴링마다 localStorage 재읽기 → 한 탭에서 닫으면 다른 탭도 워터마크 공유 |
| 동시 다건 주문 | "새 주문 N건" + 최신 1건 상세, 나머지는 주문 목록에서 확인 |
| store_name NULL | LEFT JOIN → '(알 수 없음)' 폴백 표기 |
| 팝업 표시 중 새 주문 추가 | 다음 주기에 카운트/내용 갱신(재표시) |

---

## 6. 테스트 계획 (Check 단계용)

| 레벨 | 시나리오 | 기대 |
|------|----------|------|
| L1 | `GET check_new_orders.php?since=0` (로그인) | 200, JSON, pending 주문 배열 |
| L1 | `since` = 현재 max | `orders: []`, latest_id 일치 |
| L1 | 비로그인 호출 | 리다이렉트(JSON 아님) → 클라이언트 무시 |
| L2 | store에서 주문 생성 후 logistics 화면 대기 | ~20초 내 팝업 + 알림음 |
| L2 | '주문 보기' 클릭 | `order_detail.php?id=` 이동 |
| L2 | '닫기' 후 동일 주문 | 재팝업 없음 (워터마크 갱신 확인) |
| L3 | 입고/재고 등 비-주문 화면에서 주문 발생 | 동일하게 팝업 노출(전역 확인) |

---

## 7. Out of Scope
- 팝업 내 즉시 승인(Approve)
- 서버측 사용자별 읽음 상태 저장(다기기 동기화)
- 데스크톱/모바일 푸시 알림
- 알림 주기/소리 사용자 설정 UI

---

## 8. Implementation Guide (Do 단계)

### 구현 순서
1. `logistics/ajax/check_new_orders.php` 생성 — 인증·쿼리·JSON 응답 (§2)
2. 수동 검증: 브라우저에서 `?since=0` 호출하여 JSON 확인
3. `logistics/partials/footer.php` 에 팝업 마크업 추가 (§3.3)
4. 같은 footer에 인라인 폴링 스크립트 추가 (§3.2) — 워터마크 초기화 → setInterval → showPopup
5. WebAudio 비프 + 오디오 unlock (§3.4)
6. store에서 실제 주문 생성하여 전역 팝업/알림음/링크/닫기 동작 확인

### 핵심 파일/심볼
- 신규: `logistics/ajax/check_new_orders.php`
- 수정: `logistics/partials/footer.php` (`#newOrderToast`, `lcCloseNewOrderToast()`, 폴링 IIFE)
- 참조: `order_detail.php?id=`, 주문번호 `str_pad(id,4,'0',STR_PAD_LEFT)`
