# kimsmall-shared-products — 0단계: 백업 및 참조 파일 분류

> 작성일: 2026-10-02 / 대상 Plan: `docs/01-plan/features/kimsmall-shared-products.plan.md`
> 결정 반영: 신규 상품은 **창고 화면에서 직접 등록**한다 (admin `products`에 INSERT + `kw_products` 연결 행 생성).

## 1. 로컬 DB 백업

- 파일: `C:\laragon\backup_kimsmall_20261002.sql` (저장소 밖, 약 2.7MB)
- 범위: `kw_*` 17개 테이블 전체 (구조 + 데이터). 로컬 DB 기준.
- 행 수: kw_products 1,250 / kw_inbound 1,204 / kw_inbound_batches 1,203 / kw_inventory 1,204 / kw_product_history 3,528 / kw_stock_count_baseline 3,528 / kw_brands 149 / kw_categories 28 / kw_suppliers 58 / kw_stock_count_sessions 3 / kw_stock_count_entries 3 / 그 외(kw_orders, kw_order_items, kw_order_item_lots, kw_box_breaks, kw_stock_count_adjustments) 0
- 생성 컬럼(`kw_inventory.quantity_remain`)은 INSERT에서 제외(복원 시 자동 계산).
- 복원: 파일을 로컬 DB에 실행 (`DROP TABLE IF EXISTS` 포함, FK 검사 해제).
- 참고: `mysqldump`는 `caching_sha2_password` 플러그인 오류로 사용 불가해 PHP로 생성함.

## 2. 참조 파일 분류 (비-SQL 40개)

`kw_products`는 대부분 `p.name_en / p.name_ko / p.brand_id / p.category_id / p.capacity / p.barcode_unit`을 표시용으로 읽는다. 창고 전용 값(`unit`, `pieces_per_box`, `min_stock`, `requires_expiry`, `is_active`, `barcode_box/logistics`)만 쓰는 곳은 전환 불필요.

### A. 쓰기 경로 (신규 등록 흐름 재설계 대상 — 4단계)

| 파일 | 내용 |
|------|------|
| `product_add.php` | `INSERT INTO kw_products` 직접 등록 |
| `ajax/add_product.php` | 입고 화면 등 빠른 등록, `INSERT INTO kw_products` |
| `product_edit.php` | `UPDATE kw_products`, `DELETE FROM kw_products` |
| `products.php` | 활성/비활성 토글 UPDATE, 목록 |
| `ajax/toggle_product_expiry.php` | `requires_expiry` 토글 (창고 전용 값 → 변경 불필요) |
| `ajax/quick_create.php` | 창고 전용 브랜드/카테고리 즉석 생성 → admin 분류 사용으로 전환 |
| `brand_manage.php`, `category_manage.php` | `kw_brands`/`kw_categories` 관리 → 매핑표 기반으로 정리 |
| `test_box_pcs_unit.php`, `test_pack_unit.php` | 테스트용 `INSERT INTO kw_products` (name_en만 사용) → 컬럼 NULL 허용 여부 확인 필요 |

### B. 검색/스캔/중복검사 (바코드 규칙 전환 — 3~4단계)

| 파일 | 현재 동작 |
|------|-----------|
| `ajax/search_product_by_barcode.php` | `kw_products` + `kw_brands` JOIN, 바코드 3종 검색 → admin `products.sku` 검색 추가 필요 |
| `ajax/check_product_code.php`, `lib/barcode_helper.php` | `barcode_unit/box/logistics` 중복 검사 → `products.sku` 중복 검사 포함 |
| `ajax_inuse_products.php` | 사용중 상품 조회 |
| `lib/stock_count_service.php` | 실사 바코드 조회(`barcode_unit/box/logistics`), 기준수량 생성 → 상품 식별은 `kw_products.id` 유지 |
| `inbound_add.php`, `inbound_edit.php` | 활성 상품 **전체를 페이지에 미리 로드**(1,136건). admin 51,196건으로 확장할 수 없으므로 **AJAX 검색으로 전환** 필요 (구조적 변경) |
| `ajax/branch_outbound.php`, `ajax/distribute_to_stores.php`, `ajax/get_available_products.php`, `order_new.php` | 재고 있는 상품만 목록화 → 연결된 상품만 대상이라 검색 확장 불필요 |

### C. 이름·브랜드·카테고리 읽기 (JOIN 전환 — 3단계)

`products.php`, `inventory.php`, `index.php`, `inbound_detail.php`, `inbound_edit.php`, `inbound_add.php`, `order_detail.php`, `order_new.php`, `outbound.php`, `box_break.php`, `ajax/box_break.php`, `ajax/get_expiry_alerts.php`, `ajax/update_inbound_item.php`, `ajax/product_inbound_history.php`, `ajax/branch_outbound.php`, `print_inventory.php`, `print_outbound.php`, `print_branch_outbound.php`, `print_products.php`, `export_inventory.php`, `export_products.php`, `lib/inbound_helper.php`

### D. 진단용 (낮은 우선순위)

`diag_cancel_restore.php`, `diag_product_search.php`, `diag_stock.php`

### E. 창고 전용 값만 사용 (전환 불필요)

`order_detail.php:138`, `order_new.php:88`, `ajax/branch_outbound.php:200` (unit/pieces_per_box), `lib/stock_count_service.php` 일부 (pieces_per_box), `ajax/toggle_product_expiry.php`

## 3. 전환 방식 제안 (3단계 변경량 최소화)

C그룹 22개 파일은 `kw_products p`의 `p.name_en/name_ko/brand_id/category_id/capacity/image_path`만 바꾸면 된다. 파일마다 JOIN을 새로 쓰는 대신:

- **읽기 전용 VIEW `kw_products_v`** 를 만든다. 기반은 `kw_products`이고, `product_id`가 있으면 `products`의 이름·브랜드·카테고리·이미지를 우선(`COALESCE`)해서 같은 컬럼명으로 노출한다. FK는 기존 `kw_products` 테이블에 그대로 둔다 (Codex 지적의 "B안 불가" 사유 회피 — VIEW는 읽기 전용으로만 사용).
- C그룹은 `FROM/JOIN kw_products` → `kw_products_v` 로 테이블명 교체 위주로 수정.
- 쓰기(A그룹)는 `kw_products` 테이블에 직접.
- **주의 1**: 브랜드/카테고리 ID가 admin ID로 바뀌면 `JOIN kw_brands/kw_categories` 를 쓰는 곳(`inventory.php`, `order_new.php`, `order_detail.php`, `branch_outbound`, `print_*`, `export_products.php`, `lib/inbound_helper.php`, `products.php`)은 admin `brands`/`categories` JOIN으로 같이 바꿔야 한다. VIEW에서 브랜드/카테고리 **이름 컬럼**(`brand_name`, `category_name`)까지 제공하면 JOIN 자체를 제거할 수 있다.
- **주의 2**: collation(`products` utf8mb4_unicode_ci vs `kw_*` utf8mb4_general_ci) — VIEW 내부에서 JOIN은 정수 `product_id`만 쓰므로 영향 없음. 문자열 비교(sku ↔ barcode_unit)는 `COLLATE` 명시.
- **주의 3**: 전체 상품 로드 구조(`inbound_add.php`, `inbound_edit.php`)는 VIEW로도 해결되지 않으며 AJAX 검색으로 변경해야 한다.

## 4. 단계 1(마이그레이션)에 전달할 선결 사항

- `kw_products.name_en`은 NOT NULL (`kw_products` 컬럼 기준) → 신규 등록 시 admin `name_en`이 비어 있을 때 대체값 규칙 필요 (admin `products.name_en` 컬럼도 확인).
- 불일치 6건: id 105, 488, 945, 1147, 1168, 1170 (4건은 `barcode_unit`에 영문 상품명이 들어 있음, 2건은 NULL).
- 박스 바코드 무효값: id 769 (`` ` ``).
- 테스트 스크립트 2개는 `name_en`만 채워 INSERT → 스키마 변경 영향 확인.
