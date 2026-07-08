# Plan: 입고 시 파손 상품 등록 (inbound-damage-registration)

> **Feature**: inbound-damage-registration
> **Phase**: Plan
> **Date**: 2026-07-07
> **Author**: whdans007

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 공급업체로부터 입고받을 때 이미 파손된 상품이 섞여 들어와도 이를 기록하거나 정상재고에서 제외할 방법이 없어, 파손분이 판매 가능 재고에 섞이거나 그냥 폐기되어 손실이 추적되지 않음 |
| **Solution** | 입고 등록 화면(`logistics/inbound_add.php`)의 품목 행마다 "파손 수량 + 사유" 입력을 추가. 파손분은 판매재고(`lc_inventory`)에서 자동 제외하고 신규 이력 테이블에 기록, 별도 목록 화면에서 조회 |
| **Function/UX Effect** | 창고 담당자는 입고 등록 한 화면에서 파손 수량까지 한 번에 처리(추가 화면 이동 불필요). 물류센터는 파손 이력 목록에서 기간별/공급업체별/상품별 손실 수량과 금액을 확인 |
| **Core Value** | 파손 손실이 시스템에 기록되어 공급업체 클레임 근거자료 확보 + 파손분이 판매재고에 섞이지 않아 재고 정확도 향상 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 입고 시 파손 상품을 기록/제외할 수단이 없어 재고 부정확 + 손실 추적 불가 |
| **WHO** | 물류센터 스태프(`lc_require_staff`) — 입고 등록 담당자 |
| **RISK** | 파손 수량이 실제 입고 수량보다 크게 입력되는 등 검증 실패, 기존 `lc_box_breaks`(박스 개봉 시 파손) 개념과의 혼동 |
| **SUCCESS** | 입고 등록 시 파손 수량을 입력하면 정상재고에서 자동 제외되고, 파손 이력이 저장되어 별도 목록에서 조회 가능 |
| **SCOPE** | 입고 등록 화면 파손 입력 + 이력 테이블 + 목록 화면. 파손품 폐기/공급업체반품 워크플로, 사진 첨부는 범위 외 |

---

## 1. User Intent Discovery

### 1.1 핵심 문제
- 공급업체 발주 물량 중 일부가 파손된 상태로 도착해도, 현재 `inbound_add.php`는 입고 수량 전체를 그대로 `lc_inventory`(판매 가능 재고)에 등록함
- 파손분을 인지해도 시스템에 남길 곳이 없어 구두/수기로만 처리되어, 이후 공급업체 클레임이나 손실 집계 근거가 없음
- 기존 `lc_box_breaks` 테이블은 "이미 입고되어 재고에 있는 BOX를 개봉하다가 발견한 파손"만 다루며, "입고 검수 시점에 이미 파손된 상태로 도착"하는 이번 케이스와는 발생 시점이 다름

### 1.2 대상 사용자
| 역할 | 수행 업무 |
|------|-----------|
| 물류센터 스태프 | 입고 등록 시 파손 수량/사유 입력, 파손 이력 목록에서 손실 현황 확인 |

### 1.3 성공 기준 (Success Criteria)
- [ ] **SC-1**: 입고 등록 화면의 품목 행마다 "파손 수량"과 "파손 사유" 입력란이 있다
- [ ] **SC-2**: 파손 수량이 입력되면, 해당 품목의 판매재고(`lc_inventory.quantity_in`)는 (입고수량 − 파손수량)으로 등록된다
- [ ] **SC-3**: 파손 수량은 입고 수량을 초과할 수 없다 (서버측 검증)
- [ ] **SC-4**: 파손 건은 별도 이력 테이블에 상품/공급업체/수량/사유/손실금액과 함께 저장된다
- [ ] **SC-5**: 물류센터 전용 "파손 이력" 목록 화면에서 기간/공급업체/상품으로 필터링하고, 총 손실 수량·금액을 확인할 수 있다
- [ ] **SC-6**: 파손 수량이 0이면 기존 입고 등록 동작과 완전히 동일하다 (하위 호환)

---

## 2. Alternatives Explored

| 방식 | 장점 | 단점 | 결정 |
|------|------|------|------|
| **A: `inbound_add.php` 품목 행에 파손 입력 추가 + 신규 이력 테이블(`lc_inbound_damages`)** | 검수·등록을 한 화면에서 완결, 파손분이 애초에 판매재고에 안 들어감(사후 정정 불필요) | 기존 입고 등록 폼/저장 로직 수정 필요 | **✅ 채택** |
| B: 입고 완료 후 `inbound_detail.php`에서 별도로 "파손 등록" 처리 | 입고 등록 폼은 변경 없음 | 파손분이 일시적으로라도 판매재고에 반영되었다가 사후 차감 → 그 사이 주문 가능한 재고로 잘못 노출될 위험 | ❌ (SC-2 위반) |
| C: 기존 `lc_box_breaks`(박스 개봉 파손) 테이블 재사용 | 신규 테이블 불필요 | 개념이 다름(개봉 후 발견 vs 입고 시 이미 파손) — `source_inventory_id` 등 개봉 전제 컬럼이 안 맞음, 리포트 뒤섞임 | ❌ |

---

## 3. YAGNI Review

### 3.1 1차 버전 포함 (In Scope)
- [x] `inbound_add.php` 품목 행: "Damaged" 수량 입력 (입고 등록 단위와 동일 단위: BOX 행이면 BOX 수, PCS 행이면 PCS 수)
- [x] 품목 행: "Damage Reason" 텍스트 입력 (파손 수량 > 0일 때만 필수)
- [x] 서버측 검증: 파손 수량 ≤ 입고 수량
- [x] `lc_inventory.quantity_in` = 입고수량 − 파손수량으로 등록 (파손분은 애초에 판매재고에 미반영)
- [x] 신규 테이블 `lc_inbound_damages` (파손 이력, 손실금액 자동 계산)
- [x] `logistics/inbound_damages.php` — 파손 이력 목록 (기간/공급업체/상품 필터, 총 손실 수량·금액 요약)
- [x] 사이드바 내비게이션에 "Damaged Goods" 메뉴 추가 (Inbound/Outbound 섹션)

### 3.2 2차 버전으로 미룸 (Out of Scope)
- [ ] 파손 사진 첨부
- [ ] 파손품 폐기/공급업체 반품 처리 워크플로 (상태 관리)
- [ ] 파손 이력에 대한 승인/결재 프로세스
- [ ] 공급업체별 파손율 통계/알림
- [ ] 파손 항목 수정/삭제 (등록 후 정정이 필요하면 관리자 직접 DB 처리)

---

## 4. Requirements

### 4.1 기능 요구사항 (FR)
| ID | 요구사항 |
|----|----------|
| FR-1 | 입고 등록 화면의 각 품목 행에 "Damaged" 수량 입력란이 있으며, 기본값은 0이다 |
| FR-2 | 파손 수량 > 0인 행은 "Damage Reason" 텍스트가 필수이다 |
| FR-3 | 파손 수량은 해당 행의 입고 수량을 초과할 수 없다 (초과 시 등록 거부 + 에러 메시지) |
| FR-4 | 등록 시 `lc_inventory.quantity_in`은 (입고수량 − 파손수량)으로 저장되어, 파손분은 판매 가능 재고에서 제외된다 |
| FR-5 | 파손 수량 > 0인 행은 `lc_inbound_damages`에 상품/공급업체/입고ID/수량/사유/손실금액/등록자와 함께 저장된다 |
| FR-6 | 손실금액 = 파손수량(PCS 환산) × PCS 원가(`cost_price_pcs`) |
| FR-7 | `logistics/inbound_damages.php`에서 파손 이력을 목록으로 조회하며, 기간/공급업체/상품 필터와 총 손실 수량·금액 요약을 제공한다 |

### 4.2 비기능 요구사항 (NFR)
| ID | 요구사항 |
|----|----------|
| NFR-1 | 파손 수량 0인 기존 입고 등록 플로우는 동작·검증 로직에 변화가 없다 (하위 호환) |
| NFR-2 | 모든 변경은 기존 `inbound_add.php`의 트랜잭션(`autocommit(false)` + `commit`/`rollback`) 범위 내에서 함께 처리된다 |
| NFR-3 | `logistics/inbound_damages.php`는 `lc_require_staff()`로 접근 제한 |
| NFR-4 | 파손 수량/사유 입력은 기존 품목 행 UI와 동일한 스타일(테이블 인라인 입력)로 통일 |

---

## 5. 데이터 / 구현 스케치 (Plan 수준)

**신규 테이블**
```sql
CREATE TABLE lc_inbound_damages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    inbound_id   INT NOT NULL          COMMENT 'lc_inbound.id (파손이 발생한 입고 건)',
    product_id   INT NOT NULL          COMMENT 'lc_products.id',
    supplier_id  INT                   COMMENT 'suppliers.id (조회 편의를 위한 비정규화)',
    quantity     INT NOT NULL          COMMENT '파손 수량 (입고 행과 동일 단위: BOX 또는 PCS)',
    unit         ENUM('BOX','PCS') NOT NULL COMMENT '파손 수량 단위 (해당 입고 행의 단위와 동일)',
    cost_loss    DECIMAL(15,4) NOT NULL DEFAULT 0 COMMENT '손실금액 = PCS환산수량 x cost_price_pcs',
    reason       VARCHAR(255) NOT NULL COMMENT '파손 사유',
    created_by   INT                   COMMENT 'users.id',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inbound_id) REFERENCES lc_inbound(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES lc_products(id) ON DELETE RESTRICT,
    INDEX idx_product_time (product_id, created_at),
    INDEX idx_supplier_time (supplier_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='입고 시 파손 상품 이력';
```

- **단위 일치 원칙**: 파손 수량은 해당 품목 행이 등록된 단위(BOX 또는 PCS)와 동일하게 입력받는다. 손실금액 계산 시에만 PCS로 환산(BOX면 × ppb)하여 `cost_price_pcs`를 곱한다 — 기존 `lc_box_breaks.damaged_qty`(PCS 고정)와는 별개 컬럼 체계
- **재고 반영**: `inbound_add.php`의 `lc_inventory` INSERT에서 `quantity_in` 값만 `$item['quantity'] - $item['damaged_qty']`로 변경(그 외 컬럼/로직 동일)
- **수정 파일**: `logistics/inbound_add.php` (폼 UI + 서버 검증/저장 로직), `logistics/partials/header.php` (내비게이션)
- **신규 파일**: `sql/lc_inbound_damages.sql` + 실행용 `sql/run_migration_inbound_damages.php`, `logistics/inbound_damages.php`

> 상세 설계(화면 레이아웃, 폼 필드 배치, 검증 위치, 목록 화면 쿼리)는 Design 단계에서 확정.

---

## 6. Risks & Mitigations

| 리스크 | 영향 | 완화 |
|--------|------|------|
| 파손 수량이 입고 수량보다 크게 입력됨 | 재고 음수/데이터 오류 | 서버측 검증(FR-3)으로 초과 시 등록 자체를 거부 |
| 기존 `lc_box_breaks`(개봉 파손)과 개념 혼동 | 두 이력이 뒤섞여 리포트 왜곡 | 별도 테이블·별도 화면으로 분리, 명칭에 "Inbound Damage" 명시 |
| 파손 입력 UI 추가로 기존 입고 등록 폼이 복잡해짐 | 담당자 실수 유발 | 기본값 0 유지, 파손 수량 입력 시에만 사유란 활성화(0이면 기존과 동일한 화면) |
| BOX 단위로 입력된 파손 수량과 실제 파손 PCS 수가 다를 수 있음(부분 파손) | 손실금액 오차 | 부분 파손(박스 내 일부만 파손)은 해당 행을 PCS 단위로 등록하도록 안내(2차 개선 필요 시 별도 처리) |

---

## 7. Out of Scope (명시)
- 파손 사진 첨부
- 파손품 폐기/공급업체 반품 워크플로
- 파손 이력 승인/결재 프로세스
- 파손 이력 수정/삭제 화면
- 공급업체별 파손율 통계/알림
