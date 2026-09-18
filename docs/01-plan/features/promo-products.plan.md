# promo-products Planning Document

> **Summary**: 유통기한 임박 LOT 재고를 할인가로 별도 관리·출고하는 "프로모 상품" 기능
>
> **Project**: HOME K MART — logistics(물류센터) 모듈
> **Version**: -
> **Author**: bkit Product Manager (Claude Code)
> **Date**: 2026-09-18
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 유통기한이 임박한 LOT 재고가 정상가로만 출고 가능해 폐기 손실 위험이 크고, 물류직원이 임박 재고를 할인으로 소진시킬 별도 수단이 없다. |
| **Solution** | 대시보드 유통기한 임박 목록(`inventory.php?filter=expiring`)을 LOT 단위로 전환해 할인등록 기능을 붙이고, 신규 `lc_lot_promotions` 테이블 + "프로모 상품" 메뉴로 등록된 할인 LOT을 조회·관리하며, 점포 주문 시 할인 LOT을 정상 재고와 분리된 별도 주문행으로 노출해 승인(출고) 시점에 지정 LOT에서만 차감되도록 한다. |
| **Function/UX Effect** | 물류직원은 임박 재고를 즉시 할인 등록/취소할 수 있고, 점포 사용자는 주문 화면에서 "프로모션: LOT / 유통기한 / 잔량 / 할인율"을 명확히 보고 선택할 수 있다. 재고가 소진되면 해당 프로모션은 목록에서 자동으로 사라진다(이력은 보존). |
| **Core Value** | 유통기한 임박 재고의 폐기 손실을 줄이고, 점포는 할인 조건을 정확히 인지한 상태로 발주할 수 있어 물류센터-점포 간 정보 비대칭을 해소한다. |

---

## Context Anchor

> Auto-generated from Executive Summary. Propagated to Design/Do documents for context continuity.

| Key | Value |
|-----|-------|
| **WHY** | 유통기한 임박 LOT 재고가 정상가로만 출고돼 폐기 손실 위험이 크고, 임박 재고를 할인으로 소진시킬 수단이 없음 |
| **WHO** | 물류직원(`lc_require_staff()` 대상, 할인 등록/취소 권한), 점포 발주 담당자(`order_new.php` 사용자, 할인 LOT 조회/주문) |
| **RISK** | `lc_allocate_order_stock()`의 기존 FEFO 음수 허용 차감 로직이 할인 LOT에 그대로 적용되면 정책 위반(할인 아닌 LOT이 할인가로 출고되거나 반대) 발생 가능 — 승인 시점 서버 재검증 필수 |
| **SUCCESS** | (1) `inventory.php?filter=expiring`에서 LOT 단위 할인 등록/취소 가능 (2) "프로모 상품" 메뉴에 활성 할인 LOT 전체 노출, 재고 0 시 자동 제외 (3) `order_new.php`에서 할인 LOT이 일반 재고와 분리된 별도 행으로 노출되고 승인 시 정확히 해당 LOT에서만 할인가로 차감 |
| **SCOPE** | 1차: 단일 LOT 지정 할인 등록/취소 + 주문 분리행 + 승인 시 차감 + 초과 주문 자동 분할(FR-12) + 단위별(BOX/PACK/PCS) 확정 할인가(FR-13) — 2026-09-18 Design 체크포인트에서 Option B(완전 자동화) 채택. 후속: 부분 출고(분할 배송) 정책, 주문 생성 시점 재고 예약/홀드 |

---

## 1. Overview

### 1.1 Purpose

물류센터(HQ 창고)에서 관리하는 LOT 재고 중 유통기한이 임박한 항목을 할인가로 지정해 점포 발주 시 노출하고, 승인(출고) 시점에 해당 LOT에서만 할인가로 정확히 차감되도록 하는 "프로모 상품" 기능을 신설한다.

### 1.2 Background

- 현재 `logistics/index.php` 대시보드의 "유통기한 임박" 카드 → "전체보기" 링크는 `inventory.php?filter=expiring`로 연결되지만, 이 화면은 상품 단위 GROUP BY(`MIN(i.expiry_date)`, `SUM(i.quantity_remain)`)로 표시돼 LOT 개별 식별이 불가능하다.
- `logistics/ajax/get_expiry_alerts.php`는 이미 LOT 단위(`lot_number`, `expiry_date`, `SUM(quantity_remain)`) 쿼리 패턴을 사용 중이며 참고 가능.
- `logistics/order_new.php`는 상품별로 전체 LOT 재고를 단위(BOX/PACK/PCS)별로 합산해 보여줄 뿐 LOT/가격 정보가 없고 `unit_price`는 항상 `0 AS selling_price`로 하드코딩되어 있다.
- 핵심 구조적 전제: `logistics/lib/inventory_helper.php`의 `lc_allocate_order_stock()`은 **주문 승인 시점**에 FEFO(유통기한 빠른 순)로 LOT을 차감하고 차감된 LOT의 가중평균 원가를 `lc_order_items.unit_price`에 기록한다. 즉 현재 `unit_price`는 소비자 판매가가 아니라 HQ→점포 내부 이관 원가이며, 이 시스템은 소매가 아닌 내부 발주 구조다. 할인가 로직도 이 원가 이관 구조 위에서 동작해야 한다.
- Codex 사전 상담(읽기 전용 설계 검토, `.codex-logs/promo-products-consult-stdout.log`)을 거쳐 아래 §2~§7 설계를 확정했다.

### 1.3 Related Documents

- 참고 템플릿/스타일: `docs/01-plan/features/logistics-i18n.plan.md`
- 관련 라이브러리: `logistics/lib/inventory_helper.php`, `logistics/lib/auth.php`
- 관련 컨벤션: `docs/00-conventions/agent-orchestration.md`

---

## 2. Scope

### 2.1 In Scope (1차 구현 범위)

- [ ] 신규 테이블 `lc_lot_promotions` + 마이그레이션 스크립트 `logistics/sql/run_migration_v23.php`
- [ ] 사이드바 "Inventory Status" 메뉴 아래 "프로모 상품"(`promo_products.php`) 메뉴 신설 (staff 전용)
- [ ] `logistics/inventory.php`의 `filter=expiring`을 LOT 단위 행으로 전환 + 할인율 입력/할인등록 버튼 + 취소 버튼 추가
- [ ] 신규 `logistics/promo_products.php` — 활성 할인 LOT 리스트(상품명, LOT, 유통기한, D-day, 잔량, 정상가, 할인율, 할인가, 등록일/등록자, 취소 버튼)
- [ ] `logistics/order_new.php` — 할인 LOT을 일반 재고와 분리된 별도 주문행으로 노출(LOT/유통기한/잔량/할인율/할인가 명시)
- [ ] `logistics/lib/inventory_helper.php`의 승인/출고 로직(`lc_allocate_order_stock()` 등) — 지정 프로모션 LOT을 우선/전용으로 차감하고 할인가를 `unit_price`(또는 신규 컬럼)에 반영하는 로직 추가
- [ ] 신규 AJAX 엔드포인트: 할인 등록, 할인 취소 (§ 신규 AJAX 참고)
- [ ] 재고 소진 시 자동 목록 제외(물리 삭제 없이 조회 조건으로 처리)
- [ ] `lang/ko.json`, `lang/en.json`에 `logistics.promo.*` 네임스페이스 신규 추가 (i18n 필수)
- [ ] 권한: 할인 등록/취소는 물류직원(`lc_require_staff()`)만 가능

### 2.2 Out of Scope (후속 과제로 이관)

> **2026-09-18 설계(Design) 단계에서 Option B(완전 자동화) 채택 — 아래 두 항목은 1차 범위로 승격됨(§3.1 FR-12, FR-13 참고, Priority Must로 상향).** 원래 Codex 상담에서 후속 과제로 분류됐던 항목이지만, 설계 체크포인트에서 사용자가 처음부터 전부 구현하는 방향을 선택했다.

- ~~프로모션 LOT 수량을 초과하는 주문에 대한 자동 분할~~ → 1차 범위로 승격 (FR-12)
- ~~단위(BOX/PACK/PCS) 환산 기반 확정 할인가 정책~~ → 1차 범위로 승격 (FR-13)
- 프로모션 승인 대기 중 부분 출고(분할 배송) 시나리오
- 프로모션 이력/통계 리포트(할인 판매량, 폐기 방지 효과 등) 대시보드 위젯
- 만료일 당일(D-0) 이후 자동 처리 정책(자동 취소/자동 폐기 연동)
- **주문 생성 시점(pending 상태)에서의 재고 예약/홀드**(현재 시스템은 전 주문 유형에 걸쳐 승인 시점에만 차감하는 구조 — 이를 프로모션에만 예외로 바꾸면 기존 주문 동시성 모델 전체에 영향을 주므로, 동시성 보호는 기존과 동일하게 "승인 시점 `SELECT ... FOR UPDATE`"로 유지하고 생성 시점 예약은 별도 과제로 분리)

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 물류직원은 `inventory.php?filter=expiring`에서 개별 LOT에 할인율을 입력하고 할인등록 버튼으로 프로모션을 등록할 수 있다 | Must | Pending |
| FR-02 | 물류직원은 등록된 할인을 취소할 수 있다(활성 상태만, 이미 출고된 LOT 과거 출고가는 유지) | Must | Pending |
| FR-03 | "프로모 상품" 메뉴는 활성 상태(`status='active'`)이며 잔량(`quantity_remain > 0`)이 있는 할인 LOT만 리스트로 보여준다 | Must | Pending |
| FR-04 | 재고가 소진된 프로모션은 별도 배치 작업 없이 조회 조건만으로 자동 제외된다(물리 삭제 금지, 이력 보존) | Must | Pending |
| FR-05 | 점포 주문 화면(`order_new.php`)은 할인 LOT을 일반 재고와 분리된 주문행으로 표시하고 유통기한·잔량·할인율·할인가를 명시한다 | Must | Pending |
| FR-06 | 주문 승인(출고) 시점에 지정된 프로모션 LOT에서만 정확히 차감되며, 차감된 수량의 판매단가는 할인가 기준으로 기록된다 | Must | Pending |
| FR-07 | 프로모션 LOT 잔량을 초과하는 수량을 주문하면 서버에서 거부(에러 반환)한다 | Must | Pending |
| FR-08 | 할인율은 0~100% 범위만 허용하며 서버에서 검증한다 | Must | Pending |
| FR-09 | 신규 UI 문자열은 전부 `t('logistics.promo.*')` 패턴을 사용한다 | Must | Pending |
| FR-10 | 프로모션 등록/취소는 물류직원(`lc_require_staff()`)만 가능하다 | Must | Pending |
| FR-11 | 한 상품에 여러 활성 프로모션 LOT이 동시에 존재할 수 있고 각각 독립적으로 조회/주문된다 | Should | Pending |
| FR-12 | 프로모션 LOT 잔량을 초과하는 수량을 주문하면 서버가 자동으로 "할인 LOT 잔량만큼 할인가 + 나머지는 일반 FEFO 재고에서 정상가"로 분할 출고한다(주문 항목이 1개여도 승인 시 내부적으로 프로모션/일반 두 갈래로 차감) | Must | Pending |
| FR-13 | (2026-09-18 설계 검증 중 정정) `lc_inventory` 한 행(LOT)은 항상 단일 단위(BOX/PACK/PCS)만 가지며(박스개봉은 `box_break.php`가 별도의 새 PCS LOT을 생성하는 방식, 같은 행이 여러 단위를 겸하지 않음 — `logistics/box_break.php` 확인 완료). 따라서 단위 간 환산가 계산은 애초에 불필요하며, `discounted_price`는 해당 LOT의 저장 단위 기준 단일 값으로 충분하다. **원래 FR-13(단위별 환산 할인가)은 잘못된 전제였으므로 요구사항에서 제외**하고, 대신 "등록된 할인가는 항상 해당 LOT의 저장 단위(BOX/PACK/PCS 중 하나) 기준"임을 화면에 명확히 표시하는 것으로 대체한다 | Must | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| 데이터 정합성 | 동시 주문 경쟁 상태에서 동일 LOT이 중복 차감되지 않음 | `SELECT ... FOR UPDATE` + 트랜잭션 내 재검증 코드 리뷰 |
| 표시 규칙 | 원가/할인가/합계 금액은 소숫점 둘째자리까지 표시 (CLAUDE.md 규칙) | 화면 스크린샷 확인 |
| 다국어 | 신규 UI 문자열은 `ko.json`/`en.json` 양쪽에 동시 존재 | JSON 키 집합 diff 비교 |
| 하위 호환 | 기존 `filter=all/low/negative/out` 상품 단위 표시는 변경하지 않음 | `inventory.php` 코드 리뷰 + 수동 확인 |
| 권한 | 프로모션 등록/취소 AJAX는 staff 권한 미보유 시 401/403 | 권한 없는 세션으로 수동 호출 테스트 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] `lc_lot_promotions` 테이블이 마이그레이션 스크립트로 생성되고 재실행 가드가 동작한다
- [ ] `inventory.php?filter=expiring`이 LOT 단위로 표시되고 할인등록/취소가 정상 동작한다
- [ ] "프로모 상품" 메뉴가 사이드바 Inventory Status 아래 노출되고 활성 할인 LOT만 정확히 보여준다
- [ ] `order_new.php`에서 할인 LOT 주문 후 승인 시 해당 LOT에서만 차감되고 `unit_price`가 할인가로 기록된다
- [ ] 재고 소진된 프로모션이 화면에서 자동으로 사라진다(DB 데이터는 남아있음)
- [ ] 신규 문자열이 `ko.json`/`en.json`에 모두 존재하고 `t()`로 렌더링된다
- [ ] `php -l` 전 대상 파일 통과, `git status`로 대상 외 파일 변경 없음 확인

### 4.2 Quality Criteria

- [ ] 할인 등록/취소 AJAX가 CSRF 토큰 검증을 거친다(기존 logistics AJAX 컨벤션과 동일)
- [ ] 승인 시점 차감 로직이 트랜잭션 + `FOR UPDATE` 락으로 동시성 보호된다
- [ ] 할인율 0~100% 범위 밖 입력 시 서버가 거부한다

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 한 상품에 정상 LOT + 할인 LOT이 공존할 때 주문 화면/승인 로직이 이를 혼동해 잘못된 LOT에서 차감 | High | Medium | 할인 LOT을 일반 재고와 별도 주문행(별도 `inventory_id`/promotion_id 명시)으로 분리하고, 승인 시점 서버가 요청된 LOT/프로모션 ID를 재검증(FR-06, FR-07) |
| 기존 `lc_fifo_ship_allow_negative()`의 "재고 부족 시 음수 허용" 정책이 프로모션 LOT에 그대로 적용되면 할인 한도를 넘는 음수 출고가 발생 | High | Medium | 프로모션 LOT 차감 경로는 별도 함수(예: `lc_ship_promo_lot()`)로 분리하고 음수 허용하지 않음, 잔량 초과 시 즉시 예외 발생시켜 트랜잭션 롤백 |
| 동시 주문 경쟁 상태로 동일 LOT이 초과 차감됨 | Medium | Low | `SELECT ... FOR UPDATE`로 LOT 행 잠금 후 차감(기존 `lc_fifo_ship_allow_negative()` 패턴과 동일) |
~~단위(BOX/PACK/PCS) 환산가 불일치로 할인가 계산 오류~~ | ~~Medium~~ | ~~Medium~~ | (2026-09-18 정정) 해당 없음 — LOT 한 행은 항상 단일 단위만 가짐(FR-13 참고) |
| 유통기한 당일(D-0) 또는 만료 후 LOT에 대한 처리 정책 미정 | Low | Medium | 1차는 등록 시점 검증만 수행(만료 여부와 무관하게 등록 허용), 자동 만료 처리 정책은 후속 과제 |
| 이미 출고된 프로모션 LOT의 할인율을 사후 변경/취소 시 과거 출고 기록과 불일치 | Medium | Low | 취소는 `status`만 변경(활성→취소)하고 과거 `lc_order_item_lots`의 `cost_price` 등 이미 기록된 값은 수정하지 않음(FR-02) |
| i18n 누락 시 `t()` fallback으로 키 문자열이 그대로 노출 | Low | Medium | `logistics.promo.*` 신규 키를 `ko.json`/`en.json` 양쪽에 동시 추가([[feedback_migration_files_php]] 및 logistics-i18n.plan.md 배치 컨벤션과 동일한 검증 절차 적용) |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `lc_lot_promotions` | DB Table (신규) | LOT별 할인 등록 이력 테이블 신설 |
| `lc_order_items` | DB Table (컬럼 추가) | `promotion_id` 컬럼 추가(§7.1.1) — 주문 생성 시점에 프로모션 지정 항목을 표시, 승인 시점 분기 판단에 사용 |
| `logistics/lib/inventory_helper.php` | PHP Library | `lc_allocate_order_stock()` 수정 + 프로모션 전용 차감 함수 신규 추가 |
| `logistics/inventory.php` | PHP Page | `filter=expiring` 분기를 상품 단위 → LOT 단위로 변경, 할인등록 UI 추가 |
| `logistics/order_new.php` | PHP Page | 할인 LOT 별도 주문행 노출, 폼 제출 데이터에 `inventory_id`/promotion 식별자 포함 |
| `logistics/partials/header.php` | PHP Partial | 사이드바 메뉴 항목 추가 |
| `lang/ko.json`, `lang/en.json` | i18n | `logistics.promo.*` 네임스페이스 신규 |
| `logistics/ajax/` | 신규 AJAX 2개 | 할인 등록, 할인 취소 엔드포인트 신설 |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `lc_allocate_order_stock()` | CALL | 주문 승인 처리 플로우(주문 승인 액션에서 호출) | Needs verification — 프로모션 LOT 분기 추가 시 기존 일반 주문 차감 로직(FEFO)이 그대로 동작하는지 회귀 확인 필요 |
| `lc_fifo_ship_allow_negative()` | CALL | `lc_allocate_order_stock()` 내부 | None — 일반(비프로모션) 항목 차감 경로는 변경하지 않음, 프로모션 항목만 신규 함수로 분기 |
| `inventory.php?filter=expiring` | READ | 대시보드 "전체보기" 링크(`logistics/index.php`) | Needs verification — 링크 URL은 유지하되 렌더링 구조가 상품 단위→LOT 단위로 바뀌므로 화면 레이아웃 확인 필요 |
| `logistics/ajax/get_expiry_alerts.php` | READ | 대시보드 위젯(쿼리 패턴 참고용) | None — 이번 변경과 무관, 그대로 유지 |
| `order_new.php` 폼 제출 → `lc_order_items` INSERT | CREATE | `order_new.php` 상단 POST 처리 블록 | Breaking 가능성 — 할인 LOT 주문행 추가 시 INSERT 파라미터(프로모션 식별자 등)가 늘어나므로 기존 일반 상품 주문 흐름이 깨지지 않는지 확인 필요 |

### 6.3 Verification

- [ ] 일반(비프로모션) 주문의 승인/차감 흐름이 기존과 동일하게 동작함을 회귀 확인
- [ ] `filter=all/low/negative/out` 등 다른 필터는 상품 단위 표시를 그대로 유지하는지 확인
- [ ] 프로모션 관련 신규 AJAX가 staff 권한 없는 계정에서 거부되는지 확인

---

## 7. DB 스키마 설계

### 7.1 신규 테이블 `lc_lot_promotions`

```sql
CREATE TABLE lc_lot_promotions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id    INT NOT NULL COMMENT 'lc_inventory.id FK — 할인 대상 LOT',
    product_id      INT NOT NULL COMMENT '조회 편의용 비정규화 — lc_products.id',
    lot_number      VARCHAR(100) NULL COMMENT '조회 편의용 비정규화 — 등록 시점 lc_inventory.lot_number 스냅샷',
    expiry_date     DATE NULL COMMENT '조회 편의용 비정규화 — 등록 시점 lc_inventory.expiry_date 스냅샷',
    discount_rate   DECIMAL(5,2) NOT NULL COMMENT '할인율(%), 0~100 범위 검증은 애플리케이션에서',
    base_price      DECIMAL(12,2) NOT NULL COMMENT '등록 시점 정상 단가 스냅샷(원가 또는 기준가)',
    discounted_price DECIMAL(12,2) NOT NULL COMMENT '확정 할인 단가 = base_price * (1 - discount_rate/100), 소숫점 둘째자리',
    status          ENUM('active','cancelled','expired') NOT NULL DEFAULT 'active' COMMENT '조회 시 active만 노출',
    registered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    registered_by   INT NULL COMMENT '등록한 사용자 id (users.id)',
    cancelled_at    DATETIME NULL,
    cancelled_by    INT NULL COMMENT 'users.id',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES lc_inventory(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id)   REFERENCES lc_products(id)  ON DELETE RESTRICT,
    KEY idx_inventory_id (inventory_id),
    KEY idx_product_status (product_id, status),
    KEY idx_status_expiry (status, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='LOT 단위 할인(프로모션) 등록/이력';
```

(기존 프로젝트 테이블들이 모두 `INT`(UNSIGNED 아님)를 PK/FK로 사용하는 컨벤션 — `logistics/sql/run_migration.php` 참고 — 이므로 동일하게 `INT`로 맞춤. `INT UNSIGNED`로 만들면 `lc_inventory.id`/`lc_products.id`와 타입이 달라 FK 생성이 실패할 수 있음. `inventory_id`/`product_id`에 대한 FK 제약도 다른 테이블과 일관되게 명시.)

- `inventory_id`는 원본 FK로 사용하되 `product_id`/`lot_number`/`expiry_date`는 목록 조회 편의를 위해 비정규화 저장(원본이 바뀌어도 등록 당시 스냅샷 값을 유지 — 이력 목적).
- 물리 삭제는 하지 않는다. 재고 소진 시에도 행은 유지하고 조회 조건(`status='active' AND quantity_remain > 0`)으로만 목록에서 제외한다.
- 재고 소진 여부는 `lc_inventory.quantity_remain`을 조인해서 실시간 판단하며, `status` 컬럼은 "사람이 취소했는가"만 표현한다(자동 소진과 수동 취소를 구분).

### 7.1.1 `lc_order_items` 컬럼 추가 (설계 검증 중 발견된 필수 보완)

현재 `order_new.php`의 `lc_order_items` INSERT는 `order_id, product_id, quantity, order_unit, pieces_per_box, unit_price`만 저장하며, **주문 생성 시점에 "이 항목이 어느 프로모션 LOT을 지정했는지"를 저장할 컬럼이 없다.** 주문은 `pending` 상태로 먼저 생성되고, 실제 LOT 차감은 한참 뒤 **승인 시점**(`lc_allocate_order_stock()`)에 일어나므로, 이 연결 정보를 어딘가에 영속화하지 않으면 승인 시점에 "이 주문 항목이 프로모션 대상이었다"는 사실 자체를 알 수 없다. 이는 §9의 승인 로직이 정상 동작하기 위한 전제조건이므로 1차 범위에 반드시 포함한다.

```sql
ALTER TABLE lc_order_items
    ADD COLUMN promotion_id INT NULL COMMENT 'lc_lot_promotions.id — 프로모션 지정 주문 항목만 설정, 일반 항목은 NULL' AFTER product_id,
    ADD KEY idx_promotion_id (promotion_id);
```

- `order_new.php`가 할인 LOT 주문행을 제출할 때는 `inventory_id`가 아니라 **`promotion_id`(`lc_lot_promotions.id`)** 하나만 hidden input으로 전달한다 — 프로모션 행이 이미 `inventory_id`를 갖고 있으므로 중복 전달할 필요가 없고, 승인 시점에 `lc_lot_promotions`를 조회하면 LOT/할인가를 함께 얻을 수 있다(§8.4, §9, §10 전체에서 `inventory_id` 대신 `promotion_id`로 표기 통일).
- 일반(비프로모션) 주문 항목은 `promotion_id = NULL`로 기존과 동일하게 INSERT.
- `lc_allocate_order_stock()`은 주문 항목 조회 시 `promotion_id`가 NULL이 아닌 행만 신규 `lc_ship_promo_lot()` 경로로 분기하고, 나머지는 기존 FEFO 경로를 그대로 사용한다.

### 7.2 마이그레이션 스크립트

- 경로: `logistics/sql/run_migration_v23.php`
- 기존 `run_migration_v22.php` 패턴을 그대로 따른다: `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, 재실행 가드(`SHOW TABLES LIKE 'lc_lot_promotions'`로 이미 존재하면 skip), 적용 전/후 검증 로그 출력, `<pre>` 텍스트 출력.
- raw `.sql` 파일 단독 배치 금지 — 반드시 브라우저/CLI에서 실행 가능한 PHP 스크립트로 작성 ([[feedback_migration_files_php]] 컨벤션 준수).

---

## 8. 화면별 변경 범위

### 8.1 사이드바 메뉴 (`logistics/partials/header.php`)

- "Inventory Status"(`inventory.php`) 메뉴 항목 바로 아래, `$_lc_is_staff` 분기 내부에 "프로모 상품"(`promo_products.php`) 메뉴 추가.
- 라벨은 `t('logistics.nav.promo_products')` 사용.

### 8.2 `logistics/inventory.php` — `filter=expiring` LOT 단위 전환

- 대상 코드: 약 185~230줄, `filter=expiring`일 때의 쿼리를 상품 단위 GROUP BY(`MIN(expiry_date)`, `SUM(quantity_remain)`)에서 **LOT 단위 행**(`i.id`, `i.lot_number`, `i.expiry_date`, `i.quantity_remain` 그대로)으로 전환.
- 다른 필터(`all`/`low`/`negative`/`out`)는 기존 상품 단위 로직을 그대로 유지(리스크 최소화, Codex 권고안 채택).
- LOT 행에 표시할 컬럼: 상품명, LOT번호, 유통기한, D-day, 단위, 남은수량, 프로모션 상태(미등록/활성 배지), 할인율 입력 필드, 할인등록 버튼(미등록 시), 할인취소 버튼(활성 시).
- 할인등록/취소는 AJAX로 처리하고 성공 시 해당 행만 갱신(또는 전체 리로드, 구현 단계에서 결정).

### 8.3 신규 `logistics/promo_products.php`

- staff 전용(`lc_require_staff()`), URL: `/logistics/promo_products.php`.
- 쿼리: `lc_lot_promotions p JOIN lc_inventory i ON p.inventory_id = i.id JOIN lc_products pr ON p.product_id = pr.id WHERE p.status = 'active' AND i.quantity_remain > 0`.
- 컬럼: 상품명, 카테고리/바코드, LOT번호, 유통기한, D-day, 남은수량, 정상가(`base_price`), 할인율, 할인가(`discounted_price`), 등록일(`registered_at`), 등록자, 취소 버튼.
- CLAUDE.md 규칙에 따라 정상가/할인가는 소숫점 둘째자리까지 표시.

### 8.4 `logistics/order_new.php` — 할인 LOT 분리 표시

- 대상 코드: 약 120~145줄의 재고 조회 쿼리를 확장해 활성 프로모션 LOT을 별도 결과셋으로 조회(일반 재고 집계 쿼리와 분리).
- 화면에는 일반 상품 목록과 별도 섹션(또는 별도 테이블)으로 "프로모션 상품" 표시: "프로모션: LOT A / 2026-09-25까지 / 5 PCS / 20% 할인" 형태.
- 주문 폼 제출 시 할인 LOT 항목은 `promotion_id`(`lc_lot_promotions.id`)를 명시적으로 hidden input에 담아 전송, `lc_order_items.promotion_id`(§7.1.1 신규 컬럼)에 그대로 저장 — 서버가 승인 시점에 재검증할 수 있도록 함.
- `unit`(BOX/PACK/PCS)별 합산 로직과는 별도로, 프로모션 항목은 LOT의 저장 단위 기준 잔량만 노출(1차 범위, FR-13 후속 과제와 연결).

---

## 9. 승인/출고 시점 가격 반영 로직

### 9.1 수정 대상 함수

| 함수 | 파일 | 변경 내용 |
|------|------|-----------|
| `lc_allocate_order_stock()` | `logistics/lib/inventory_helper.php` | 주문 항목 중 프로모션 LOT 지정 항목과 일반 항목을 분기 처리. 프로모션 항목은 신규 함수로 위임, 일반 항목은 기존 `lc_fifo_ship_allow_negative()` 경로 유지 |
| (신규) `lc_ship_promo_lot()` | `logistics/lib/inventory_helper.php` | `lc_order_items.promotion_id`로 `lc_lot_promotions`를 조회해 대상 LOT(`inventory_id`)을 확정하고 `SELECT ... FOR UPDATE`로 잔량 확인 후 차감. 잔량 초과 시 예외 발생(음수 허용 금지 — 기존 `lc_fifo_ship_allow_negative()`와 다른 정책). 차감된 수량 × `lc_lot_promotions.discounted_price`를 해당 주문 항목의 판매단가로 반영 |
| `lc_restore_order_stock()` | `logistics/lib/inventory_helper.php` | 주문 취소 시 프로모션 LOT 차감분도 함께 복원되는지 확인(기존 `lc_order_item_lots` 기반 복원 로직은 LOT 단위이므로 별도 수정 없이 재사용 가능할 것으로 추정 — 구현 단계에서 검증 필요) |
| `lc_order_stock_allocated()` | `logistics/lib/inventory_helper.php` | 변경 불필요(이미 차감됐는지 판별하는 기존 로직 그대로 재사용) |

### 9.2 처리 순서 (Codex 권고 반영)

1. 주문 승인 트랜잭션 시작
2. 주문 항목을 `promotion_id IS NULL`(일반) / `promotion_id IS NOT NULL`(프로모션 지정)로 분기 — 후자는 해당 `lc_lot_promotions`가 `status='active'`인지 재확인(등록 후 취소됐을 수 있음)
3. 대상 LOT(`lc_lot_promotions.inventory_id` → `lc_inventory.id`) `SELECT ... FOR UPDATE`로 잠금
4. 잔량 확인 — 요청 수량이 잔량을 초과하면 예외 발생 → 트랜잭션 롤백(FR-07, 1차 범위는 자동 분할 없음)
5. `lc_order_item_lots`에 LOT별 차감 기록 삽입(기존 테이블 그대로 재사용, 신규 컬럼 불필요 — `cost_price` 컬럼에 할인가 반영)
6. 해당 주문 항목의 `unit_price`를 할인가 기준으로 갱신
7. 일반(비프로모션) 항목은 기존 `lc_fifo_ship_allow_negative()` 경로로 정상 처리
8. 트랜잭션 커밋

---

## 10. 신규 AJAX 엔드포인트

### 10.1 할인 등록 — `logistics/ajax/promo_register.php` (신규)

**요청 (POST)**
```json
{
  "csrf_token": "...",
  "inventory_id": 1234,
  "discount_rate": 20.00
}
```

**응답 (성공)**
```json
{
  "success": true,
  "message": "할인이 등록되었습니다.",
  "data": {
    "promotion_id": 55,
    "base_price": 10000.00,
    "discounted_price": 8000.00,
    "discount_rate": 20.00
  }
}
```

**응답 (실패 예시 — 이미 활성 프로모션 존재)**
```json
{ "success": false, "message": "이미 할인이 등록된 LOT입니다." }
```

- 서버 검증: staff 권한, CSRF, `discount_rate` 0~100 범위, 대상 LOT에 이미 활성 프로모션이 없는지, `quantity_remain > 0`.
- `base_price` 산출: `lc_inventory.inbound_id`로 `lc_inbound.cost_price`(해당 LOT의 입고 단가)를 조회해 스냅샷으로 저장한다(등록 이후 `lc_inbound.cost_price`가 수정되어도 프로모션 가격은 변하지 않도록). `discounted_price = ROUND(base_price * (1 - discount_rate/100), 2)`.

### 10.2 할인 취소 — `logistics/ajax/promo_cancel.php` (신규)

**요청 (POST)**
```json
{ "csrf_token": "...", "promotion_id": 55 }
```

**응답 (성공)**
```json
{ "success": true, "message": "할인이 취소되었습니다." }
```

- 서버 검증: staff 권한, CSRF, 대상 프로모션이 `status='active'`인지(이미 취소/소진된 건은 거부).
- 처리: `status = 'cancelled'`, `cancelled_at = NOW()`, `cancelled_by = 현재 사용자 id`로 UPDATE. 물리 삭제 금지.

---

## 11. 정책 결정이 필요한 열린 이슈 (1차 구현 범위 vs 후속 과제)

| # | 이슈 | 1차 구현 범위 결정 | 후속 과제 |
|---|------|---------------------|-----------|
| 1 | 한 상품에 정상 LOT + 할인 LOT 공존 시 주문 구분 | 별도 주문행 + 서버 측 `inventory_id`/promotion 재검증 | - |
| 2 | 프로모션 LOT 잔량 초과 주문 | **(2026-09-18 변경) 1차 범위로 승격**: 서버가 자동으로 할인분/일반분 분할 출고(FR-12) | 승인 대기 중 부분 출고(분할 배송) 시나리오는 후속 |
| 3 | 동시 주문 경쟁 상태 | 트랜잭션 + `SELECT ... FOR UPDATE` 락 | - |
| 4 | 단위(BOX/PACK/PCS) 환산가 정책 | **(2026-09-18 설계 검증 중 정정)** 애초에 불필요한 문제였음 — LOT 한 행은 항상 단일 단위만 가지므로 환산 자체가 발생하지 않음(FR-13) | - |
| 5 | 유통기한 당일(D-0)/만료 후 LOT 처리 | 등록 시점 제한 없음(만료 여부 무관하게 등록 허용) | 자동 만료 처리, 만료 후 자동 취소 정책 |
| 6 | 할인율 검증 범위 및 사후 변경 | 0~100% 검증만 수행, 활성 프로모션의 할인율 수정은 미지원(취소 후 재등록 방식) | 할인율 인라인 수정 기능 |
| 7 | 권한 | 등록/취소 모두 물류직원(`lc_require_staff()`) 전용 | - |

---

## 12. Architecture Considerations

### 12.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure | Static sites | ☐ |
| **Dynamic** | Feature-based modules | Web apps with backend | ☑ (기존 프로젝트 구조 유지, PHP+MySQL 모놀리식) |
| **Enterprise** | Strict layer separation | High-traffic systems | ☐ |

기존 `logistics/` 모듈 구조(페이지+`lib/`+`ajax/`+`sql/`)를 그대로 따르며 별도 아키텍처 전환은 하지 않는다.

### 12.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 프로모션 저장 방식 | `lc_inventory`에 컬럼 추가 vs 별도 테이블 | 별도 테이블 `lc_lot_promotions` | 이력(등록/취소) 보존 필요, 가격 스냅샷 보관 필요 (Codex 권고) |
| `filter=expiring` 표시 단위 | 상품 단위 유지 vs LOT 단위 전환 vs 별도 화면 | LOT 단위 전환(해당 필터만) | expand 방식은 기존 집계 쿼리와 충돌 위험, 별도 화면은 대시보드 동선과 분리돼 비권장 (Codex 권고) |
| 주문 화면 할인 LOT 표시 | 배지/툴팁만 vs 별도 주문행 분리 | 별도 주문행 분리 + 서버 재검증 | 배지만으로는 어느 LOT에서 차감될지 보장 불가, 조작 위험 (Codex 권고) |
| 승인 시점 차감 개입 지점 | 주문 생성 시점 vs 승인 시점(`lc_allocate_order_stock()`) | 승인 시점 | 기존 구조가 이미 승인 시점에 FEFO 차감+원가 반영을 수행하므로 동일 지점에서 확장하는 것이 가장 안전 |
| 재고 소진 처리 | cleanup 배치 작업 vs 조회 조건만 | 조회 조건만(`status='active' AND quantity_remain > 0`) | 별도 배치 불필요, 실시간 정합성 보장 (Codex 권고) |

---

## 13. Convention Prerequisites

### 13.1 Existing Project Conventions

- [x] `CLAUDE.md`에 코딩 컨벤션 섹션 존재(한글 UI, 소숫점 둘째자리, 권한 패턴 등)
- [x] `docs/00-conventions/agent-orchestration.md` 존재 — Codex/Claude 작업 분담 규칙
- [x] `logistics/` 모듈 전체가 이미 `t()` 기반 i18n 완료 상태(logistics-i18n.plan.md 참고) — 신규 UI도 동일 패턴 필수

### 13.2 Conventions to Define/Verify

| Category | Current State | To Define | Priority |
|----------|---------------|-----------|:--------:|
| 마이그레이션 파일 형식 | 존재(v2~v22 PHP 러너 패턴) | v23을 동일 패턴으로 작성 | High |
| i18n 네임스페이스 | 존재(`logistics.*`) | `logistics.promo.*` 신규 네임스페이스 추가 | High |
| AJAX 응답 구조 | 존재(`success`/`message`/`data`) | 신규 엔드포인트도 동일 구조 준수 | High |

### 13.3 Environment Variables Needed

해당 없음 (기존 `config/db_config.php` 연결 방식 재사용).

---

## 14. 배치(batch) 전략 — Codex/Claude 작업 분담

`docs/00-conventions/agent-orchestration.md` 컨벤션에 따라, 이번에는 파일 크기가 아닌 **기능 단위**로 배치를 나눈다(logistics-i18n.plan.md는 파일 크기 기준 B0~B8이었으나, 이번 기능은 스키마→화면→로직 순서의 의존성이 강해 기능 단위 분할이 더 적절).

| 배치 | 대상 | 비고 |
|------|------|------|
| **B0 스키마+마이그레이션** | `logistics/sql/run_migration_v23.php`(신규 — `lc_lot_promotions` 테이블 생성 + `lc_order_items.promotion_id` 컬럼 추가, §7.1.1 포함), `lang/ko.json`/`lang/en.json`(`logistics.promo.*` 네임스페이스 골격만) | 최우선 단독 실행. 다른 배치는 이 테이블/컬럼 존재를 전제로 함 |
| **B1 inventory.php 변경** | `logistics/inventory.php`(`filter=expiring` LOT 단위 전환 + 할인등록/취소 UI), `logistics/partials/header.php`(사이드바 메뉴 추가) | B0 이후 착수. 다른 필터 로직 건드리지 않도록 diff 범위 제한 |
| **B2 promo_products.php 신설** | `logistics/promo_products.php`(신규) | B0 이후 착수, B1과 병렬 가능 |
| **B3 신규 AJAX** | `logistics/ajax/promo_register.php`(신규), `logistics/ajax/promo_cancel.php`(신규) | B0 이후 착수, B1/B2와 병렬 가능. B1의 할인등록/취소 버튼이 이 엔드포인트를 호출하므로 인터페이스(§10) 사전 합의 필요 |
| **B4 order_new.php + 승인 로직** | `logistics/order_new.php`, `logistics/lib/inventory_helper.php`(`lc_allocate_order_stock()` 수정 + `lc_ship_promo_lot()` 신규) | B0~B3 완료 후 착수(가장 리스크 높은 배치 — 기존 주문/차감 흐름 회귀 위험 있어 마지막에 단독 배치로 진행) |

각 배치는 별도 Codex 실행(`.codex-logs/promo-products-b{N}-*`)으로 진행하고, 배치마다 Claude Code가 `git status`로 대상 파일 외 변경 없는지 + `php -l` + (B0의 경우) JSON 유효성/키 diff 확인 후 다음 배치로 진행한다.

### 최종 배치 순서

B0 → (B1 · B2 · B3 병렬 가능, 순차 진행도 무방) → B4

병합 게이트는 항상 Claude Code — 전 배치 병합 전 `/code-review`로 검토하고 코드 스타일을 통일한다(CLAUDE.md 규칙).

---

## 15. Next Steps

1. [ ] 설계 문서 작성 (`promo-products.design.md`) — §9 승인 로직 처리 순서를 시퀀스 다이어그램으로 구체화, §14 배치별 상세 구현 가이드 포함
2. [ ] 팀 리뷰 및 승인 (§11 열린 이슈 중 최종 확정 필요 항목 재확인)
3. [ ] B0(스키마+마이그레이션)부터 구현 착수

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-18 | 최초 작성 — Codex 사전 상담 결과 반영 | bkit Product Manager |
