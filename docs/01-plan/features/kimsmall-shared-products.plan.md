---
template: plan
version: 1.3
---

# kimsmall-shared-products Planning Document

> **Summary**: KIM'S MALL 창고(`kimsmall_wherehouse/`)가 별도 상품 마스터 `kw_products`로 상품정보를 관리하던 방식을, admin 공용 `products`(+brands, categories)를 참조하는 방식으로 전환한다. 창고 업무 테이블의 FK(`kw_products.id`)는 유지한다.
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: Claude (PDCA) — Codex 상의 완료 (2026-10-02)
> **Date**: 2026-10-02
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 창고가 별도 상품 DB(`kw_products`, 1,250건)로 상품을 입력·관리해 사용이 불편하고, admin 상품(51,196건)과 이름·분류·이미지가 따로 관리되어 중복 입력과 불일치가 생긴다. |
| **Solution** | `kw_products.id`는 유지하고 `product_id` 컬럼으로 admin `products.id`와 연결한다. 이름·바코드(sku)·브랜드·카테고리·이미지는 admin에서 읽고, 창고 전용 값(unit, pieces_per_box, min_stock, requires_expiry, 박스/물류 바코드, is_active)은 `kw_products`에 둔다. |
| **Function/UX Effect** | 창고 화면의 상품 검색 대상이 admin 전체 상품이 된다. 창고에 처음 쓰는 상품을 고르면 `kw_products` 연결 행이 자동 생성되어 상품정보 재입력이 없다. 신규 상품은 admin 상품정보에 등록한다. |
| **Core Value** | 상품 마스터 단일화로 입력 부담과 데이터 불일치를 줄이면서, 재고(FIFO)·입출고·실사 데이터는 건드리지 않아 위험을 최소화한다. |

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 별도 상품 DB 사용이 불편하고 admin 상품과 이중 관리됨 |
| **WHO** | KIM'S MALL 창고 직원/관리자 (`kimsmall_wherehouse/`), admin 상품 관리자 |
| **RISK** | ① 재고·원가 환산(unit/pieces_per_box) 의미 훼손 ② 바코드 불일치 6건 오연결 ③ 브랜드·카테고리 ID 오매핑 ④ 55개 PHP 참조 파일 중 누락 |
| **SUCCESS** | 창고 상품 검색이 admin 상품을 대상으로 동작하고, 입고·FIFO 출고·박스분해·주문·실사의 재고/원가가 전환 전과 동일 |
| **SCOPE** | `kimsmall_wherehouse/` 전체(상품/입고/재고/주문/출고/실사/출력), `kimsmall_wherehouse/sql/` 마이그레이션. admin 코드는 변경하지 않음(상품 등록 화면 재사용만) |

---

## 1. Overview

### 1.1 확인된 현황 (로컬 DB, 2026-10-02)

| 항목 | 값 |
|------|----|
| `kw_products` 행 수 / 활성 | 1,250 / 1,136 |
| admin `products` 행 수 | 51,196 |
| `kw_products.barcode_unit = products.sku` 일치 | **1,244 / 1,250** |
| 미일치 6건 | id 105, 488, 945, 1147, 1168, 1170 — 4건은 `barcode_unit`에 바코드 대신 영문 상품명, 2건은 NULL |
| `kw_products.barcode_box` 보유 | 3건 (id 769는 값이 `` ` `` 로 무효), `barcode_logistics` 0건, `barcode` 컬럼 미사용 |
| `kw_brands` / admin `brands` | 149 / 119 |
| `kw_categories` / admin `categories` | 28 / 63 (parent_id, default_margin_rate 보유) |
| `kw_products`를 FK로 참조하는 테이블 | kw_inbound, kw_inventory, kw_order_items, kw_product_history, kw_box_breaks, kw_stock_count_* 등 |

### 1.2 박스 물류 코드 (결정 사항)

- 박스 코드는 ITF-14(14자리, 앞자리 `1`)이며 **낱개 EAN에서 계산해 만들 수 없다** (예: 낱개 `4800092112782` ↔ 박스 `14800092111775`).
- admin `products`에는 박스 코드 컬럼이 없고, 과거 박스 코드가 `products.sku`에 잘못 등록된 사례가 80건 있다.
- **결정 (1)**: 박스/물류 바코드는 `kw_products.barcode_box` / `barcode_logistics`에 그대로 유지한다. admin 스키마는 변경하지 않는다.
- **원칙**: 신규 상품은 admin 상품정보에 **낱개 바코드를 `sku`로** 등록한다. 박스 코드를 `sku`에 넣지 않는다.

### 1.3 접근안 (Codex 상의 결과: A안 채택)

- A) `kw_products.id` 유지 + `product_id` 연결 컬럼 추가, 공용 정보는 JOIN — **채택**
- B) `kw_products`를 VIEW로 교체 — 기존 FK 때문에 불가
- C) 모든 `kw_*.product_id`를 `products.id`로 재매핑 — 검증·롤백 범위가 커서 제외

---

## 2. Scope

### 2.1 In Scope
- [ ] 마이그레이션 `sql/run_migration_v23.php`: `kw_products.product_id` 추가(인덱스, 초기 NULL 허용), 연결 매핑
- [ ] 브랜드/카테고리 매핑표 테이블(`kw_brand_map`, `kw_category_map`) 및 예외 보고
- [ ] 로컬 전용 진단/매핑 리포트 스크립트 (미일치 6건, 중복 sku, 매핑 예외)
- [ ] 읽기 경로 전환: 목록·검색·스캔·재고·입고·주문·출고·출력·내보내기에서 이름/브랜드/카테고리/이미지를 `products` JOIN으로 변경
- [ ] 신규 등록 흐름 변경: admin 상품 검색→선택→창고 전용 값(unit/pieces_per_box/min_stock/requires_expiry/박스 바코드) 입력, `kw_products` 연결 행 자동 생성, 중복 연결 방지
- [ ] `ajax/quick_create.php`, `brand_manage.php`, `category_manage.php`: 창고 전용 분류 생성 중단 또는 admin 분류 사용으로 전환
- [ ] 다국어(한/영) 문구 처리

### 2.2 Out of Scope
- `kw_*` 업무 테이블의 FK 변경, `kw_products.id` 재매핑
- admin `products` 스키마 변경(박스 코드 컬럼 추가 등)
- `kw_products` 중복 컬럼(name, image 등) 삭제 — 안정화 후 별도 작업
- 운영(Hostinger) DB 쓰기 — 로컬에서만 작성·검증하고, 운영은 배포 후 사용자가 마이그레이션 실행 (운영 DB는 SELECT만)

---

## 3. 핵심 설계 원칙

1. **창고 전용 값은 덮어쓰지 않는다**: `unit`(PCS/BOX/PACK), `pieces_per_box`는 재고·원가 환산에 쓰이므로 admin `products.pieces_per_box`로 대체하지 않는다. `lib/unit_helper.php`의 의미를 보존한다.
2. **자동 연결은 sku 정확 일치만**: `kw_products.barcode_unit = products.sku` 1,244건만 자동 연결. 나머지 6건은 예외 목록으로 사용자 확인 후 처리.
3. **ID 직접 이식 금지**: 브랜드·카테고리는 이름 기준 후보 → 매핑표 → 사용자 확인.
4. **롤백 가능성 유지**: 마이그레이션은 컬럼·매핑표 추가만 한다. 기존 컬럼·FK·데이터는 변경/삭제하지 않는다.
5. **collation 주의**: `products`(utf8mb4_unicode_ci)와 `kw_*`/`lc_*`(utf8mb4_general_ci) 조인 시 `Illegal mix of collations` 발생 확인됨 → JOIN에서 `COLLATE` 명시 또는 비교 컬럼 정합화.

---

## 4. 이행 단계

| 단계 | 내용 | 담당 |
|------|------|------|
| 0 | 로컬 DB 백업, `kw_products` 참조 파일(비-SQL 40개) 읽기/쓰기 분류표 작성 | Claude |
| 1 | `run_migration_v23.php` + 매핑/진단 리포트 스크립트 작성 | Codex 작성, Claude 검토 |
| 2 | 로컬 실행 → 예외(6건, 박스 바코드 id 769 무효값, 브랜드·카테고리 미매핑) 사용자 확정 | 사용자 + Claude |
| 3 | 읽기 경로 전환 (목록/검색/재고/입고/주문/출고/출력) | Claude |
| 4 | 신규 등록·검색 흐름 전환 (`product_add/edit`, `ajax/add_product`, `quick_create`, `search_product_by_barcode`) | Claude |
| 5 | 검증: 전환 전후 재고·원가 비교, 입고·FIFO 출고·박스분해·주문·실사·이력, 기존 `test_*.php` 재실행 | Claude 실행, Codex 독립 리뷰 |
| 6 | (별도 작업) 중복 컬럼 정리 | 추후 |

### 롤백
- 3~5단계 중에는 `kw_products` 기존 컬럼·FK를 유지하므로 코드 되돌리기만으로 원복 가능.
- `product_id` 컬럼과 매핑표는 삭제하지 않고 유지.

---

## 5. 파일 분담 (충돌 방지)

| 담당 | 파일 |
|------|------|
| Codex | `kimsmall_wherehouse/sql/run_migration_v23.php`, 매핑/진단 리포트 스크립트, 전환 결과 독립 리뷰 |
| Claude | `kimsmall_wherehouse/` 내 PHP 화면·AJAX·lib 전부, 최종 `/code-review` 및 병합 |

공용 파일(`admin/partials/*`, `config/db_config.php`, `lib/*_helper.php`)은 수정하지 않는다.
브랜치/워크트리: `codex/kimsmall-shared-products`(마이그레이션), `claude/kimsmall-shared-products`(코드 전환) — `scripts/agent-worktree.ps1` 사용.

---

## 6. Success Criteria
- [ ] 1,244건 sku 일치 상품이 `product_id`로 연결되고 6건 예외 목록이 확정됨
- [ ] 창고 상품 검색이 admin 전체 상품을 대상으로 이름·바코드(sku)로 동작
- [ ] admin 상품 선택 시 `kw_products` 연결 행이 자동 생성되며 같은 상품 중복 연결 불가
- [ ] 전환 전후 `kw_inventory` 재고 합계·원가가 동일
- [ ] 입고·FIFO 출고·박스분해·주문·실사·이력 기능 회귀 없음
- [ ] 한/영 다국어 문구 적용, 원가·합계는 소숫점 둘째자리 표시

---

## 7. Risks & Mitigation

| 위험 | 영향 | 대응 |
|------|------|------|
| unit/pieces_per_box 의미 혼동 | 재고·원가 오계산 | 창고 값 유지, 공용 값으로 덮어쓰지 않음, 전후 비교 검증 |
| 미일치 6건 오연결 | 이력 오귀속 | 자동 연결 제외, 사용자 확정 |
| 브랜드·카테고리 오매핑 | 분류 오표시 | 이름 기준 후보 + 매핑표 + 확인 |
| collation 불일치 | 쿼리 오류 | JOIN 시 COLLATE 명시 |
| 참조 파일 누락 | 일부 화면 구 데이터 표시 | 단계 0 분류표로 전수 점검 |
| 분류 체계 재분리 | 데이터 불일치 재발 | `quick_create` 등 창고 전용 분류 생성 경로 제거 |

---

## 8. Open Items
- ✅ 결정(2026-10-02): 신규 상품은 **창고 화면에서 직접 등록**한다 (admin `products` INSERT + `kw_products` 연결 행 생성). 창고 직원이 admin `products`에 쓸 수 있도록 창고 로그인 사용자 권한/`last_modified_by_user_id` 처리 방식 확인 필요
- `barcode_box` 무효값(id 769 `` ` ``) 정리 방법
- 창고 화면에 노출할 카테고리 계층(admin `parent_id`) 범위
