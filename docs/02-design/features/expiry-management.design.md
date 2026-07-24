# expiry-management Design Document

> **Summary**: 기존 `inventory_expirations` 로트 재고 테이블을 재사용해 유통기한 점검·폐기를 관리하는 "유통기한 관리" 섹션(점검기록/폐기등록/폐기통계)을 관리자 화면에 추가한다.
>
> **Project**: HOME K MART
> **Version**: 1.0.0
> **Author**: whdans007
> **Date**: 2026-07-22
> **Status**: Draft
> **Planning Doc**: [expiry-management.plan.md](../../01-plan/features/expiry-management.plan.md)

### Pipeline References (if applicable)

| Phase | Document | Status |
|-------|----------|--------|
| Phase 1 | Schema Definition | N/A — 이 프로젝트는 CLAUDE.md에 문서화된 기존 스키마를 사용 |
| Phase 2 | Coding Conventions | N/A — 별도 컨벤션 문서 없음, §10에서 기존 코드 컨벤션 직접 정리 |
| Phase 3 | Mockup | N/A — ASCII 화면 레이아웃으로 대체 (§5.1) |
| Phase 4 | API Spec | 본 문서 §4에서 PHP 페이지/폼 기준으로 대체 정의 |

---

## Context Anchor

> Plan 문서(Executive Summary / Risks / Success Criteria)에서 도출. Plan Plus 템플릿에는 별도 Context Anchor 섹션이 없어 이번에 새로 정리함.

| Key | Value |
|-----|-------|
| **WHY** | 유통기한 임박/경과 상품의 폐기 손실이 기록·추적되지 않고, 사전 점검 프로세스가 없어 손실을 놓치는 문제를 해결하기 위함 |
| **WHO** | `product_management` 권한을 가진 매장 직원/매니저/관리자 |
| **RISK** | 폐기 시 잘못된 로트를 차감하면 재고 수치가 오염됨; 판매 시 자동 FIFO 차감(`deduct_inventory_by_expiration`)이 `updated_at`을 계속 갱신하므로 "등록/점검 시점"을 별도 컬럼(`registered_at`)으로 분리해야 함 |
| **SUCCESS** | 점검기록 등록 시 목록·배지에 반영, 폐기등록 시 재고 자동 차감+이력 저장, 폐기통계에서 월별 집계 확인, 임계값을 관리자가 조정 가능 |
| **SCOPE** | 신규 관리자 페이지 3개 + 공유 헬퍼 1개 + DB 변경 3건(1 ALTER + 2 CREATE) + `header.php` 네비/배지. 전체 점포 통합조회·승인워크플로·엑셀·사진첨부·추가 알림채널은 Out of Scope (Plan §4.2) |

> Design Anchor(Pencil MCP) 섹션은 이 기능이 기존 TailwindCSS 관리자 UI 패턴을 그대로 따르는 서버 렌더링 PHP 화면이라 생략함.

---

## 1. Overview

### 1.1 Design Goals

- 기존 `inventory_expirations`(로트 재고) 데이터를 깨지 않고 그대로 확장해, 상품 편집 모달의 "로트 관리" 기능과 항상 동일한 데이터를 보게 한다.
- 폐기 시 재고 차감이 트랜잭션으로 원자적으로 처리되어, 부분 실패로 재고와 이력이 불일치하는 상황을 방지한다.
- 임박/알림 판정 로직(임계값 계산)을 한 곳(`lib/expiry_helper.php`)에만 두어, 배지·목록 색상·대시보드가 항상 같은 기준을 쓰게 한다.

### 1.2 Design Principles

- **기존 컨벤션 우선**: 이 프로젝트는 `lib/*_helper.php`에 공유 로직을 모으고 페이지는 절차적으로 작성하는 패턴을 이미 쓰고 있음(`lib/inventory_helper.php`, `lib/permission_helper.php`). 새 기능도 이 패턴을 그대로 따른다.
- **단일 진실 공급원(Single Source of Truth)**: 유통기한 재고 수량은 `inventory_expirations` 하나만 신뢰하고, `product_disposals`는 이력(로그)만 남긴다.
- **파일명 회피 규칙**: 루트 `.htaccess`가 `^(debug|test|check).*\.(php|txt)$` 패턴을 403으로 차단하므로, 신규 파일명은 이 접두사를 쓰지 않는다 (이번 PDCA 사이클 중 실제로 겪은 이슈).

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 각 페이지에 로직 인라인 | lib + 전용 AJAX 4개로 완전 분리 | 핵심 공유 로직만 lib로 추출, 나머지 인라인 |
| **New Files** | 5 | 9 | 5 |
| **Modified Files** | 1 (header.php) | 1 (header.php) | 1 (header.php) |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Medium (배지 쿼리 중복 위험) | High | High |
| **Effort** | Low | High | Medium |
| **Risk** | Low (coupled) | Low (clean) | Low (balanced) |
| **Recommendation** | Quick wins, hotfixes | Long-term projects | **Default choice** |

**Selected**: Option C — **Rationale**: 이 코드베이스는 이미 `lib/inventory_helper.php` 같은 헬퍼 라이브러리 패턴을 쓰고 있고, AJAX 파일은 "여러 화면에서 재사용될 때만" 분리하는 관행이 있음. 배지 카운트·임계값 조회·폐기 트랜잭션처럼 header.php와 여러 페이지가 공유해야 하는 로직만 `lib/expiry_helper.php`로 추출하고, 나머지는 기존 `product_management.php`, `plu_pattern_report.php`와 동일하게 페이지 자체에서 폼 처리.

### 2.1 Component Diagram

```
┌──────────────────────┐     ┌───────────────────────────┐     ┌──────────────┐
│   Browser (관리자)     │────▶│  admin/*.php (PHP 렌더링)   │────▶│  MySQL       │
│  - 점검기록 화면        │     │  - expiry_inspection.php   │     │  - inventory_│
│  - 폐기등록 화면        │     │  - expiry_disposal.php     │     │    expirations│
│  - 폐기통계 화면        │◀────│  - expiry_disposal_report  │◀────│  - product_  │
└──────────────────────┘     │       .php                 │     │    disposals │
                              │  - partials/header.php     │     │  - expiry_   │
                              │    (배지)                   │     │    settings  │
                              └──────────┬──────────────────┘     │  - inventory │
                                         │ calls                  └──────────────┘
                                         ▼
                              ┌───────────────────────────┐
                              │  lib/expiry_helper.php     │
                              │  - get_expiry_settings()   │
                              │  - get_expiry_alert_count()│
                              │  - get_expiry_status()     │
                              │  - register_disposal()     │
                              └───────────────────────────┘
```

### 2.2 Data Flow

```
[점검기록 등록/수정] 사용자 입력 → expiry_inspection.php (자체 POST 처리)
  → lib/expiry_helper.php 없이 직접 INSERT/UPDATE inventory_expirations
    (registered_by, registered_at 기록) → inventory.quantity 동기화 → 목록/배지 갱신

[폐기등록] 사용자 입력 → expiry_disposal.php (자체 POST 처리)
  → lib/expiry_helper.php::register_disposal() 호출
    → BEGIN TRANSACTION
    → inventory_expirations.quantity 검증 후 차감 (store_id/product_id/id 일치 재검증)
    → inventory.quantity 동기화
    → product_disposals INSERT (unit_cost 스냅샷 포함)
    → COMMIT (실패 시 ROLLBACK + 에러 메시지)

[배지/색상] admin/partials/header.php, expiry_inspection.php
  → lib/expiry_helper.php::get_expiry_alert_count($conn, $store_id)
  → expiry_settings.alert_days 이내 건수 반환 → 배지 표시

[폐기통계] expiry_disposal_report.php
  → product_disposals를 월별로 직접 GROUP BY 집계 (헬퍼 미사용, 페이지 전용 쿼리)
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `admin/expiry_inspection.php` | `lib/expiry_helper.php`, `config/db_config.php`, `lib/permission_helper.php` | 점검기록 목록/등록/설정 |
| `admin/expiry_disposal.php` | `lib/expiry_helper.php`, `admin/ajax_search_products.php`(기존), `admin/ajax_get_lot_inventory.php`(기존) | 폐기 등록/이력 |
| `admin/expiry_disposal_report.php` | `config/db_config.php` | 월별 통계 (헬퍼 미의존) |
| `admin/partials/header.php` | `lib/expiry_helper.php` | 배지 카운트 |
| `lib/expiry_helper.php` | `config/db_config.php` | DB 커넥션 |

---

## 3. Data Model

### 3.1 기존 테이블 변경 — `inventory_expirations`

```sql
ALTER TABLE `inventory_expirations`
  ADD COLUMN `registered_by` INT(11) UNSIGNED NULL AFTER `quantity`,
  ADD COLUMN `registered_at` DATETIME NULL AFTER `registered_by`;
```

> `updated_at`은 판매 시 FIFO 자동 차감(`deduct_inventory_by_expiration`)에서도 갱신되므로 "실제 점검/등록 시점"을 알 수 없음. `registered_at`은 점검기록 화면의 저장 액션에서만 갱신.

### 3.2 신규 테이블 — `product_disposals` (폐기 이력)

```sql
CREATE TABLE IF NOT EXISTS `product_disposals` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id` INT(11) UNSIGNED NOT NULL,
  `product_id` INT(11) UNSIGNED NOT NULL,
  `inventory_expiration_id` INT(11) UNSIGNED NULL COMMENT '차감된 로트 (inventory_expirations.id)',
  `expiration_date` DATE NOT NULL,
  `quantity` INT(11) NOT NULL COMMENT '폐기 수량',
  `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '폐기 시점 원가 스냅샷 (통계용)',
  `reason` ENUM('expired','damaged','other') NOT NULL DEFAULT 'expired',
  `reason_note` VARCHAR(255) NULL COMMENT '사유=other일 때 상세 텍스트',
  `disposed_by` INT(11) NULL COMMENT '등록한 사용자 (users.id)',
  `disposed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_disposal_store_product` (`store_id`,`product_id`),
  KEY `idx_disposal_disposed_at` (`disposed_at`),
  KEY `idx_disposal_lot` (`inventory_expiration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='유통기한 상품 폐기 이력';
```

### 3.3 신규 테이블 — `expiry_settings` (임계값 설정, 단일 행)

```sql
CREATE TABLE IF NOT EXISTS `expiry_settings` (
  `id` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
  `warning_days` INT(11) NOT NULL DEFAULT 60 COMMENT '관찰 대상 기준일',
  `alert_days` INT(11) NOT NULL DEFAULT 30 COMMENT '긴급 알림(배지) 기준일',
  `updated_by` INT(11) NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='유통기한 관리 임계값 설정 (단일 행, id=1 고정)';

INSERT INTO `expiry_settings` (`id`, `warning_days`, `alert_days`)
VALUES (1, 60, 30)
ON DUPLICATE KEY UPDATE `id` = `id`;
```

> `alert_days <= warning_days` 검증은 MySQL 버전에 따라 `CHECK` 제약이 무시될 수 있어 DB 레벨이 아닌 `lib/expiry_helper.php` 저장 함수에서 애플리케이션 레벨로 검증한다 (§7 보안/검증 항목 참고).
> 기존 `inventory_expirations` 테이블도 실제로는 `KEY`만 있고 `FOREIGN KEY` 제약은 없으므로(프로젝트 컨벤션), 위 두 신규 테이블도 동일하게 인덱스만 두고 강제 FK는 걸지 않는다.

### 3.4 Entity Relationships

```
products (기존) ──1──N── inventory_expirations (기존, 컬럼 추가)
products (기존) ──1──N── product_disposals (신규)
inventory_expirations ──1──N── product_disposals (신규, inventory_expiration_id로 느슨하게 연결)
stores (기존) ──1──N── inventory_expirations / product_disposals
expiry_settings: 단일 행(id=1), 관계 없음
```

---

## 4. API Specification

> 이 프로젝트는 REST API가 아닌 서버 렌더링 PHP 폼(자체 POST) 방식을 쓴다. 기존 `admin/product_management.php`, `admin/plu_pattern_report.php`와 동일한 패턴.

### 4.1 페이지/엔드포인트 목록

| 파일 | Method | 설명 | 권한 |
|------|--------|------|------|
| `admin/expiry_inspection.php` | GET | 점검기록 목록 (검색어 `q`, 상태 `status`, 등록자 `registered_by` 필터) | `product_management` |
| `admin/expiry_inspection.php` | POST `action=save` | 유통기한 로트 등록/수정 | `product_management` |
| `admin/expiry_inspection.php` | POST `action=delete` | 로트 삭제 | `product_management` |
| `admin/expiry_inspection.php` | POST `action=save_settings` | 임계값(관찰/알림 일수) 저장 | `product_management` |
| `admin/expiry_disposal.php` | GET | 폐기등록 폼 + 이력 리스트 (필터 `period`, `reason`) | `product_management` |
| `admin/expiry_disposal.php` | POST `action=register` | 폐기 등록 (재고 자동 차감 트랜잭션) | `product_management` |
| `admin/expiry_disposal_report.php` | GET | 월별 폐기 통계 (파라미터 `year`) | `product_management` |
| `admin/ajax_search_products.php` (기존, 재사용) | GET | 상품 검색 | 기존 권한 유지 |
| `admin/ajax_get_lot_inventory.php` (기존, 재사용) | GET | 상품별 유통기한 로트 조회 | 기존 권한 유지 |

### 4.2 상세 스펙 — 폐기 등록 (`admin/expiry_disposal.php`, `action=register`)

**Request (form fields):**
```
product_id            int, required
inventory_expiration_id int, required — 반드시 해당 product_id/store_id 소속 로트여야 함
quantity               int, required, > 0, <= 선택 로트의 현재 quantity
reason                 enum('expired','damaged','other'), required
reason_note            string, reason='other'일 때 required, 그 외 optional
```

**Success**: `$_SESSION['flash'] = ['type'=>'success', 'message'=>'폐기 등록이 완료되었습니다.']` 후 자기 자신으로 리다이렉트, 이력 리스트에 즉시 반영

**Error (수량 초과, 로트 불일치, DB 실패 등)**: `$_SESSION['flash'] = ['type'=>'error', 'message'=>구체적 사유]` 후 자기 자신으로 리다이렉트, 입력값 유지

### 4.3 `lib/expiry_helper.php` 함수 스펙

| 함수 | 파라미터 | 반환 | 설명 |
|------|----------|------|------|
| `get_expiry_settings($conn)` | conn | `['warning_days'=>int,'alert_days'=>int]` | 설정 행 없으면 기본값(60/30) 반환 |
| `get_expiry_status($expiration_date, $settings)` | date, settings | `'expired'\|'alert'\|'warning'\|'normal'` | 잔여일수 기준 상태 분류 |
| `get_expiry_alert_count($conn, $store_id)` | conn, store_id | int | `alert_days` 이내 + `quantity > 0`인 로트 건수 |
| `register_disposal($conn, array $params)` | conn, `['store_id','product_id','inventory_expiration_id','quantity','reason','reason_note','user_id']` | `['success'=>bool,'error'=>?string]` | 트랜잭션으로 로트 차감 + inventory 동기화 + 이력 INSERT |
| `save_expiry_settings($conn, $warning_days, $alert_days, $user_id)` | conn, int, int, int | `['success'=>bool,'error'=>?string]` | `alert_days <= warning_days` 검증 후 UPSERT |

---

## 5. UI/UX Design

### 5.1 Screen Layout

```
┌──────────────────────────────────────────────────────────┐
│  [사이드 메뉴]  유통기한 관리                                  │
│    ├─ 점검기록  🔴3                                          │
│    ├─ 폐기등록                                               │
│    └─ 폐기통계                                               │
├──────────────────────────────────────────────────────────┤
│  점검기록                                    [설정] [새 항목] │
│  검색:[________] 상태:[전체▾] 등록자:[전체▾]                   │
│  ┌────────────────────────────────────────────────────┐  │
│  │상품명/SKU │유통기한│잔여일수│수량│등록자│등록일시│관리      │  │
│  ├────────────────────────────────────────────────────┤  │
│  │ ...       │ ...   │ 🔴12일│ 20 │홍길동│07-20  │수정 삭제│  │
│  └────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────┘
```

### 5.2 User Flow

```
점검기록: 로그인 → 유통기한 관리 → 점검기록 → 상품 검색 → 유통기한/수량 입력 → 저장 → 배지 갱신
폐기등록: 로그인 → 유통기한 관리 → 폐기등록 → 상품 검색 → 로트 선택 → 수량/사유 입력 → 등록 → 재고 차감 확인
폐기통계: 로그인 → 유통기한 관리 → 폐기통계 → 연도 선택 → 월별 집계 확인
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| 점검기록 목록/폼 | `admin/expiry_inspection.php` | 로트 조회·등록·수정·삭제, 임계값 설정 모달 |
| 폐기등록 폼/이력 | `admin/expiry_disposal.php` | 폐기 등록, 이력 조회 |
| 폐기통계 | `admin/expiry_disposal_report.php` | 월별 집계 표시 |
| 네비 배지 | `admin/partials/header.php` | 임박 건수 배지 (PC + 모바일 메뉴) |
| 공유 로직 | `lib/expiry_helper.php` | 설정 조회, 상태 판정, 배지 카운트, 폐기 트랜잭션 |

### 5.4 Page UI Checklist

#### 점검기록 (expiry_inspection.php)

- [ ] 헤더: 페이지 제목 "유통기한 점검기록" + 현재 점포명 표시
- [ ] 버튼: "설정" — 임계값 설정 모달 오픈
- [ ] 버튼: "새 항목 등록" — 등록 폼 오픈(상품 검색 포함)
- [ ] 검색: 상품명/SKU 텍스트 입력
- [ ] 필터: 상태 드롭다운 (전체/경과/알림임박/관찰중/정상 — 5개 옵션)
- [ ] 필터: 등록자 드롭다운 (전체 + 점포 내 등록 이력이 있는 사용자 목록)
- [ ] 테이블 컬럼: 상품명(한/영), SKU, 유통기한, 잔여일수(색상 배지: 빨강=경과, 주황=alert_days 이내, 노랑=warning_days 이내, 회색=정상), 수량, 등록자, 등록일시, 관리(수정/삭제)
- [ ] 행 액션: 수정 — 인라인으로 유통기한/수량 편집 후 저장
- [ ] 행 액션: 삭제 — confirm 팝업 후 삭제
- [ ] 빈 상태: "등록된 유통기한 로트가 없습니다"
- [ ] 모달: 임계값 설정 — 관찰일수 입력, 알림일수 입력, 저장 버튼, `alert_days > warning_days` 입력 시 클라이언트 측 경고

#### 폐기등록 (expiry_disposal.php)

- [ ] 검색: 상품 검색 입력(자동완성, 기존 `ajax_search_products.php` 재사용)
- [ ] 드롭다운: 상품 선택 시 로드되는 유통기한 로트 목록(기존 `ajax_get_lot_inventory.php` 재사용) — 각 옵션에 유통기한+잔여수량 표시
- [ ] 안내 문구: 선택 상품에 등록된 로트가 없을 때 "등록된 로트가 없습니다. 점검기록에서 먼저 등록하세요" + 점검기록 바로가기 링크
- [ ] 입력: 폐기 수량(숫자, 선택 로트 잔여 수량 초과 시 클라이언트 검증 오류 표시)
- [ ] 드롭다운: 사유 (유통기한경과/파손/기타 — 3개 옵션)
- [ ] 입력: 사유 상세 텍스트 — 사유="기타" 선택 시에만 노출 및 필수
- [ ] 버튼: "폐기 등록" — 제출 전 confirm 팝업
- [ ] 테이블(하단): 폐기 이력 — 컬럼: 폐기일시, 상품명, SKU, 유통기한, 수량, 사유, 등록자
- [ ] 필터: 기간(이번달/지난달/전체), 사유
- [ ] 빈 상태: "폐기 이력이 없습니다"

#### 폐기통계 (expiry_disposal_report.php)

- [ ] 필터: 연도 선택 드롭다운
- [ ] 요약 카드: 선택 연도 총 폐기 수량, 총 추정 손실 금액
- [ ] 테이블: 월별 집계 — 컬럼: 월, 폐기 건수, 폐기 수량 합계, 추정 손실 금액(Σ수량×unit_cost)
- [ ] 빈 상태: "해당 연도의 폐기 이력이 없습니다"

#### 네비게이션 (admin/partials/header.php)

- [ ] "유통기한 관리" 섹션 헤더 (PC 사이드 메뉴 + 모바일 메뉴 양쪽)
- [ ] 하위 링크 3개: 점검기록, 폐기등록, 폐기통계
- [ ] 점검기록 링크 옆 빨간 배지 — `get_expiry_alert_count() > 0`일 때만 표시, 0이면 숨김

---

## 6. Error Handling

### 6.1 에러 케이스 정의

| 케이스 | 원인 | 처리 |
|------|---------|--------|
| 권한 없음 | `product_management` 권한 미보유 | `$_SESSION['flash']` 에러 메시지 + `shop.php`로 리다이렉트 (기존 `product_management.php` 패턴) |
| 로트 불일치 | 요청된 `inventory_expiration_id`가 해당 `store_id`/`product_id` 소속이 아님 | 서버에서 재검증 후 거부, "선택한 유통기한 정보를 다시 확인해주세요" |
| 수량 초과 | 폐기 수량 > 로트 잔여 수량 | 클라이언트 검증 + 서버 재검증, "재고 수량을 초과했습니다" |
| 임계값 역전 | `alert_days > warning_days` 저장 시도 | `save_expiry_settings()`에서 거부, "알림 일수는 관찰 일수보다 클 수 없습니다" |
| DB 트랜잭션 실패 | 폐기 등록 중 오류 | `ROLLBACK` 후 세션 플래시 에러, 재고/이력 데이터 정합성 유지 |

### 6.2 에러 표시 형식

```php
$_SESSION['flash'] = [
    'type' => 'error',
    'message' => '사용자에게 보여줄 한글 메시지'
];
```
> 기존 전 관리자 화면과 동일한 세션 플래시 패턴을 따름 (JSON API 응답 형식 아님).

---

## 7. Security Considerations

- [x] 모든 쿼리는 prepared statement(mysqli `bind_param` 또는 PDO) 사용
- [x] 신규 페이지 3개 모두 최상단에서 `has_permission('product_management')` 체크
- [x] 폐기 등록 시 `inventory_expiration_id`가 요청자의 `store_id`/`product_id`와 실제로 일치하는지 서버에서 재검증 (클라이언트 값 신뢰 금지)
- [x] 임계값 저장 시 `alert_days <= warning_days`를 애플리케이션 레벨에서 검증 (DB `CHECK` 제약은 MySQL 버전 의존이라 보조 수단)
- [x] 신규 파일명이 `.htaccess`의 `debug|test|check` 차단 패턴에 걸리지 않는지 확인 (이번 사이클에서 실제 발생한 이슈)
- [ ] Rate Limiting — 이 프로젝트 전반에 없는 기능이라 이번 범위에서도 적용하지 않음 (N/A)

---

## 8. Test Plan

> 이 프로젝트는 Playwright/Jest 등 자동화 테스트 도구가 없다(CLAUDE.md 기준). 아래 항목은 모두 **수동 브라우저 테스트**로 수행하며, Do 단계에서 실제 절차를 체크리스트로 남긴다.

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: 페이지/폼 동작 확인 | 각 페이지 GET/POST, 권한 체크 | 브라우저 수동 + `php -l` 문법 검사 | Do |
| L2: UI 요소 확인 | §5.4 Page UI Checklist 각 항목 | 브라우저 수동 | Do |
| L3: E2E 시나리오 | 점검기록 등록 → 폐기등록 → 배지/통계 반영 | 브라우저 수동 | Do |

### 8.2 L1: 페이지/폼 테스트 시나리오

| # | 대상 | 테스트 내용 | 기대 결과 |
|---|------|-------------|-----------|
| 1 | `expiry_inspection.php` | `product_management` 권한 없는 계정으로 접근 | `shop.php`로 리다이렉트 + 에러 플래시 |
| 2 | `expiry_inspection.php` (`action=save`) | 신규 로트 등록 | `inventory_expirations`에 `registered_by/registered_at` 포함 INSERT, `inventory.quantity` 동기화 |
| 3 | `expiry_disposal.php` (`action=register`) | 정상 폐기 등록 | `inventory_expirations.quantity` 감소, `inventory.quantity` 감소, `product_disposals` 1건 INSERT |
| 4 | `expiry_disposal.php` (`action=register`) | 로트 잔여 수량보다 큰 수량 입력 | 서버에서 거부, 에러 플래시, 재고 변화 없음 |
| 5 | `expiry_disposal.php` (`action=register`) | 다른 상품의 `inventory_expiration_id`를 위조해 전송 | 서버 재검증으로 거부 |
| 6 | `expiry_inspection.php` (`action=save_settings`) | `alert_days > warning_days`로 저장 시도 | 거부 + 에러 메시지 |

### 8.3 L2: UI 요소 테스트 시나리오

| # | 페이지 | 액션 | 기대 결과 |
|---|------|--------|----------------|
| 1 | 점검기록 | 페이지 로드 | §5.4 점검기록 체크리스트 항목 전부 표시, DB 데이터로 렌더링(스켈레톤 아님) |
| 2 | 점검기록 | 상태 필터를 "경과"로 변경 | 유통기한이 지난 로트만 표시 |
| 3 | 폐기등록 | 사유="기타" 선택 | 사유 상세 입력창이 나타나고 필수로 바뀜 |
| 4 | 폐기등록 | 로트가 없는 상품 선택 | 안내 문구 + 점검기록 바로가기 링크 노출 |
| 5 | 폐기통계 | 연도 변경 | 월별 집계 테이블 데이터 갱신 |

### 8.4 L3: E2E 시나리오

| # | 시나리오 | 단계 | 성공 기준 |
|---|----------|-------|-----------|
| 1 | 점검→폐기→통계 전체 흐름 | 점검기록에서 임박(30일 이내) 로트 등록 → 배지 숫자 증가 확인 → 폐기등록에서 해당 로트 전량 폐기 → 점검기록에서 해당 로트 소멸/배지 감소 확인 → 폐기통계 해당 월에 반영 확인 | 각 단계마다 화면·DB 값이 일치 |
| 2 | 권한 검증 | `product_management` 권한 없는 계정으로 3개 페이지 모두 접근 시도 | 전부 차단 |
| 3 | 임계값 변경 반영 | 설정에서 알림일수를 30→15로 변경 → 점검기록 색상/배지 즉시 재계산 확인 | 새 기준으로 정확히 재분류 |

### 8.5 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| `inventory_expirations` | 3 (경과 1건, alert_days 이내 1건, 정상 1건) | `expiration_date`, `quantity > 0` |
| `products` | 1개 이상, `products.sku` 존재 | `sku`, `name_ko` |
| `expiry_settings` | 1 (seed INSERT로 자동 생성) | `warning_days=60`, `alert_days=30` |

---

## 9. Clean Architecture (프로젝트 컨벤션에 맞게 재해석)

> 이 프로젝트는 TypeScript/React 기반이 아닌 절차적 PHP 구조이므로, 원 템플릿의 `src/components` 레이어 대신 이 코드베이스의 실제 레이어로 매핑한다.

### 9.1 Layer Structure

| Layer | Responsibility | Location |
|-------|---------------|----------|
| **Presentation** | 화면 렌더링 + 폼 처리 | `admin/expiry_inspection.php`, `admin/expiry_disposal.php`, `admin/expiry_disposal_report.php`, `admin/partials/header.php` |
| **Shared Logic** | 임계값 판정, 배지 카운트, 폐기 트랜잭션 | `lib/expiry_helper.php` |
| **Infrastructure** | DB 커넥션, 권한, 세션 | `config/db_config.php`, `lib/permission_helper.php`, `lib/session_helper.php` |

### 9.2 Dependency Rules

```
admin/*.php  ──→  lib/expiry_helper.php  ──→  config/db_config.php
    │
    └──→ lib/permission_helper.php, lib/session_helper.php (기존)

규칙: lib/expiry_helper.php는 admin/*.php를 절대 include하지 않는다 (단방향 의존).
```

### 9.3 This Feature's Layer Assignment

| Component | Layer | Location |
|-----------|-------|----------|
| 점검기록 화면 | Presentation | `admin/expiry_inspection.php` |
| 폐기등록 화면 | Presentation | `admin/expiry_disposal.php` |
| 폐기통계 화면 | Presentation | `admin/expiry_disposal_report.php` |
| 배지/임계값/폐기 트랜잭션 함수 | Shared Logic | `lib/expiry_helper.php` |
| DB 연결 | Infrastructure | `config/db_config.php` (기존, 변경 없음) |

---

## 10. Coding Convention Reference (이 프로젝트 기준)

### 10.1 Naming Conventions

| Target | Rule | Example |
|--------|------|---------|
| 함수 | snake_case | `get_expiry_alert_count()`, `register_disposal()` |
| 파일(페이지) | snake_case, `admin/{feature}.php` | `expiry_inspection.php` |
| 파일(헬퍼) | snake_case, `lib/{domain}_helper.php` | `expiry_helper.php` |
| DB 테이블/컬럼 | snake_case | `product_disposals`, `registered_by` |
| **금지 접두사** | 파일명이 `debug`, `test`, `check`로 시작하면 루트 `.htaccess`가 403으로 차단 | ~~`check_expiry.php`~~ → `expiry_inspection.php` |

### 10.2 파일 상단 구조 (기존 패턴 준수)

```php
<?php
require_once __DIR__ . '/../lib/lang_helper.php';   // 필요 시
$page_title = '...';
require_once __DIR__ . '/partials/header.php';       // 인증/네비/스토어 컨텍스트
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/expiry_helper.php';

if (!has_permission('product_management')) { /* flash + redirect */ }
```

### 10.3 Environment Variables

N/A — 이 프로젝트는 `.env`가 아닌 `config/db_config.php`의 상수(`DB_HOST` 등)를 사용 (기존과 동일, 변경 없음).

### 10.4 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| 폼 처리 | 자기 자신에게 POST + `action` 히든 필드로 분기 (기존 `plu_pattern_report.php` 패턴) |
| 다국어 | 이번 화면은 매장 직원 내부용 한글 UI로 작성 (CLAUDE.md 원칙), 필요 시 추후 `t()` 적용 가능하도록 문자열을 상수/배열로 분리 |
| 에러 처리 | `$_SESSION['flash']` 패턴 재사용 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
admin/
├── expiry_inspection.php        (신규)
├── expiry_disposal.php          (신규)
├── expiry_disposal_report.php   (신규)
└── partials/
    └── header.php                (수정: 섹션 + 배지)

lib/
└── expiry_helper.php             (신규)

create_expiry_management_tables.sql  (신규, 프로젝트 루트 — create_inventory_expirations.sql 관례 준수)
```

### 11.2 Implementation Order

1. [ ] `create_expiry_management_tables.sql` 작성 (ALTER + 2 CREATE + seed INSERT)
2. [ ] `lib/expiry_helper.php` 구현 (5개 함수, §4.3)
3. [ ] `admin/expiry_inspection.php` 구현 (목록/등록/수정/삭제/설정모달)
4. [ ] `admin/expiry_disposal.php` 구현 (등록 폼 + 이력)
5. [ ] `admin/expiry_disposal_report.php` 구현 (월별 통계)
6. [ ] `admin/partials/header.php` 수정 (섹션 추가 + 배지, PC/모바일 양쪽)
7. [ ] §8 테스트 시나리오 수동 실행

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| DB + 헬퍼 | `module-1` | SQL 마이그레이션 파일 + `lib/expiry_helper.php` | 10-15 |
| 점검기록 | `module-2` | `admin/expiry_inspection.php` + 설정 모달 | 15-20 |
| 폐기등록 | `module-3` | `admin/expiry_disposal.php` (등록+이력) | 15-20 |
| 폐기통계 | `module-4` | `admin/expiry_disposal_report.php` | 8-10 |
| 네비/배지 | `module-5` | `admin/partials/header.php` 수정 (PC+모바일) | 8-10 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 | 완료 (이번 세션들) |
| Session 2 | Do | `--scope module-1,module-2` | 25-35 |
| Session 3 | Do | `--scope module-3,module-4` | 25-30 |
| Session 4 | Do | `--scope module-5` | 8-10 |
| Session 5 | Check + Report | 전체 | 30-40 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-22 | Initial draft (Option C 선택 반영) | whdans007 |
