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
| **Solution** | `kw_products.id`는 유지하고 `product_id` 컬럼으로 admin `products.id`와 연결한다. **1차 범위: 이름(name_en/name_ko)·바코드(sku)·이미지만 admin에서 읽는다.** 브랜드·카테고리는 창고 자체 분류(`kw_brands`/`kw_categories`)를 유지하고, 창고 전용 값(unit, pieces_per_box, min_stock, requires_expiry, 박스/물류 바코드, is_active)도 `kw_products`에 둔다. |
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
| `kw_brands` / admin `brands` | 149 / 119 (이름 자동 매칭 26건) |
| `kw_categories` / admin `categories` | 28 / 63 (admin은 한글 계층형, 이름 자동 매칭 2건) |
| 일치 1,244건 중 admin 브랜드/카테고리 보유 | 181 / 74건 (창고는 1,211 / 1,243건) → admin으로 이전 불가 |
| 일치 건 name_en 동일 / name_ko 동일 / pieces_per_box 동일 | 910 / 543 / 495 (admin 이름이 보통 더 상세, pieces_per_box는 의미 다름) |
| 이미지 | 창고 0건, admin 4건 |
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
- [ ] (1차 제외) 브랜드/카테고리는 창고 분류 유지 — 매핑표는 후속 작업
- [ ] 로컬 전용 진단/매핑 리포트 스크립트 (미일치 6건, 중복 sku, 매핑 예외)
- [ ] 읽기 경로 전환: 목록·검색·스캔·재고·입고·주문·출고·출력·내보내기에서 이름/브랜드/카테고리/이미지를 `products` JOIN으로 변경
- [ ] 신규 등록 흐름 변경: admin 상품 검색→선택→창고 전용 값(unit/pieces_per_box/min_stock/requires_expiry/박스 바코드) 입력, `kw_products` 연결 행 자동 생성, 중복 연결 방지
- [ ] `ajax/quick_create.php`, `brand_manage.php`, `category_manage.php`: 창고 전용 분류 생성 중단 또는 admin 분류 사용으로 전환
- [ ] 다국어(한/영) 문구 처리

### 2.2 Out of Scope
- 브랜드/카테고리의 admin 통합 (후속)
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
5. **collation 주의**: `kw_products`와 `products`는 둘 다 utf8mb4_unicode_ci라 충돌 없음(충돌은 `lc_products`(general_ci)와 JOIN할 때). 단 `=`는 대소문자 무시이므로 sku 매칭은 TRIM 후 바이너리(`BINARY`) 비교로 정확일치를 확인하고, 빈 sku·중복 sku는 예외로 집계한다.
6. **VIEW 규칙**: `kw_products_v`는 읽기 전용. 이름은 admin 우선, 빈 문자열/NULL이면 창고 값(`COALESCE(NULLIF(p.name_en,''), kw.name_en)`). `kw_products.name_en`은 NOT NULL이므로 신규 등록 시 kw 연결 행에도 name_en을 채운다.
7. **마이그레이션 안전장치**: 기본 DRY-RUN, 적용은 명시 플래그 + 로그인(super_admin) 필요, 사전검증 실패 시 쓰기 시작 안 함, 이미 연결된 행은 덮어쓰지 않음.
8. **신규 등록**: admin `products` INSERT와 `kw_products` INSERT를 하나의 트랜잭션으로 묶는다. `last_modified_by_user_id`는 창고 로그인 사용자 ID 공간이 admin과 같은지 확인한 뒤 결정한다.

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

---

## 9. 진행 현황 (2026-10-02)

| 단계 | 상태 | 비고 |
|------|------|------|
| 0 백업/분류 | ✅ | `docs/03-analysis/kimsmall-shared-products.step0-inventory.md` |
| 1 마이그레이션 | ✅ | `kimsmall_wherehouse/sql/run_migration_v23.php` (로컬 적용·재실행 검증 완료) |
| 3 읽기 경로 | ✅ | 36개 파일 `kw_products` → `kw_products_v` (INSERT/UPDATE/DELETE 제외) |
| 4 등록/검색 | ✅ | `lib/shared_product_helper.php` 신설. `product_add.php`, `ajax/add_product.php`: admin 연결/생성+창고 등록 단일 트랜잭션. `ajax/search_product_by_barcode.php`: 창고에 없고 admin sku에 있는 바코드는 자동 연결. `product_edit.php`: 연결 상품은 이름을 admin에도 반영, 낱개 바코드(sku) 변경 잠금 |
| 5 검증 | ✅ | 로컬 DB: 주요 화면 실행, 자동연결/중복차단/신규생성/롤백/수정동기화 검증, `test_box_pcs_unit`(33/33), `test_pack_unit`(30/30) 통과 |

### 결정/참고
- 예외 6건(id 105, 488, 945, 1147, 1168, 1170)은 **연결하지 않고 무시**(사용자 결정). 창고 전용 상품으로 계속 동작(`product_id` NULL, 이름은 창고 값).
- 기존 UI "Import from Existing Product"(`ajax/search_shop_product.php`)는 이미 admin products 검색·자동입력을 제공하며, 서버 저장 시 연결/생성으로 이어진다.
- 알려진 기존 이슈(이번 범위 아님): `ajax/get_expiry_alerts.php`, `ajax/get_available_products.php` 는 `../../lib/auth.php` 경로가 잘못되어 호출 불가(호출처 없음).

### 운영 배포 순서 (중요)
1. **먼저** `sql/run_migration_v23.php` 만 업로드 → 브라우저로 DRY-RUN 확인 → `?apply=1&confirm=KW-V23` 로 적용 (창고 관리자 로그인 필요)
2. 적용 결과에서 `VIEW 조회: 성공`, 불일치 0, 공유 0 확인
3. **그 다음** 나머지 코드 업로드 (`kw_products_v` 가 없으면 화면이 오류 남)
