# Plan: 신선매입 등록에서 점포상품(store_product_id) 매칭 기능 완전 제거
**Feature**: fresh-purchase-remove-store-product-match
**Date**: 2026-09-06
**Phase**: Plan

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | 신선매입 등록 시 각 항목을 특정 점포 판매상품(SKU)과 자동/수동으로 매칭시키는 기능이 실사용에서 불필요함 |
| Solution | `store_product_id` 개념을 매입 흐름/DB에서 완전히 제거 — 신선매입은 이제 `mall_fresh_product_id` 기준으로만 원가를 기록 |
| Function/UX Effect | 매칭 검색창, 경고 문구, "변경" 버튼이 모두 사라져 등록 화면이 단순해짐 |
| Core Value | 실제로 쓰지 않는 매칭 절차를 없애 입력 단계를 줄임 |

---

## Context Anchor

| Key | Value |
|-----|-------|
| WHY | 사용자가 매칭 실패 안내/검색 UI를 보고 "이 기능 자체가 필요 없다"고 명시적으로 판단함 |
| WHO | 신선매입 등록 화면 사용자 |
| RISK | `fresh_purchase_items.store_product_id`는 DB에 `NOT NULL` + `products(id)` FK로 걸려있어 컬럼 삭제는 스키마 변경(마이그레이션) 필요. `fresh_purchase_batch_detail.php`가 이 컬럼을 INNER JOIN으로 사용 중이라 함께 수정해야 함 |
| SUCCESS | 매입 등록 시 점포상품 검색/매칭 UI가 전혀 노출되지 않고, 저장도 매칭 없이 정상 동작. 매입 상세 조회 화면도 정상 표시 |
| SCOPE | `admin/add_fresh_purchase_item.php`, `admin/fresh_purchase_batch_detail.php`, `admin/fresh_product_common.php`, `admin/ajax_search_fresh_store_products.php`(삭제), `lang/ko.json`/`lang/en.json`(미사용 키 삭제), 신규 DB 마이그레이션 |

---

## 1. 영향 분석 (Impact Analysis)

### 1.1 변경 대상 리소스

| 리소스 | 종류 | 변경 내용 |
|--------|------|----------|
| `fresh_purchase_items.store_product_id` | DB 컬럼 (NOT NULL, FK → products) | 컬럼 + FK 삭제 |
| `admin/add_fresh_purchase_item.php` | PHP/JS | 매칭 검색 UI, 자동매칭 로직, 서버측 검증/INSERT에서 store_product_id 제거 |
| `admin/fresh_purchase_batch_detail.php` | PHP | SELECT의 `JOIN products` + `store_product_name_ko/sku`, 표 컬럼 제거 |
| `admin/fresh_product_common.php` | PHP | `fresh_search_store_products()`, `fresh_find_store_product()` 삭제 (다른 곳에서 미사용 확인됨) |
| `admin/ajax_search_fresh_store_products.php` | PHP (AJAX) | 파일 삭제 (add_fresh_purchase_item.php 외 사용처 없음, grep으로 확인) |
| `lang/ko.json`, `lang/en.json` | 다국어 키 | `store_product`, `no_store_product_selected`, `store_product_not_matched`, `search_store_product_placeholder` 삭제 (다른 화면 미사용 grep 확인) |

### 1.2 현재 사용처 확인 (Current Consumers)

| 리소스 | 사용 위치 | 영향 |
|--------|----------|------|
| `fresh_purchase_items.store_product_id` | INSERT: `add_fresh_purchase_item.php` | 컬럼 제거로 INSERT문에서 제거 필요 |
| `fresh_purchase_items.store_product_id` | SELECT(JOIN): `fresh_purchase_batch_detail.php:88` | LEFT/INNER JOIN 제거하고 표시 컬럼도 제거 |
| `fresh_find_store_product()` | `add_fresh_purchase_item.php` 서버 검증 | 호출부 삭제 |
| `fresh_search_store_products()` | `ajax_search_fresh_store_products.php` | 파일 자체 삭제로 자동 정리 |
| `mall_fresh_product_store_links` 테이블 (store_product_id 보유) | 코드베이스 grep 결과 **런타임에서 미사용** (설계만 되어 있고 실제 참조 코드 없음) | **이번 작업 범위 아님** — 별도 테이블이므로 건드리지 않음 |

### 1.3 검증

- [x] 위 컬럼/함수/AJAX의 모든 사용처를 grep으로 확인함 (add_fresh_purchase_item.php, fresh_purchase_batch_detail.php 외 없음)
- [x] `mall_fresh_product_store_links` 테이블은 별개이며 이번 변경과 무관함을 확인 — 삭제 대상에서 제외
- [ ] 마이그레이션 적용 전 `fresh_purchase_items`에 기존 데이터가 있는지 확인 (있다면 DROP COLUMN 시 해당 값은 소실됨 — 원가 계산에는 영향 없음, 참조용 데이터였을 뿐)

---

## 2. 구현 계획

### Phase 1: DB 마이그레이션 스크립트 작성 (실행은 사용자 확인 후)
- `sql/migrations/run_remove_fresh_purchase_store_product_id.php` — 기존 마이그레이션 스크립트와 동일한 패턴(브라우저 접속 → 실행 버튼 → idempotent). `ALTER TABLE fresh_purchase_items DROP FOREIGN KEY fpi_ibfk_3, DROP COLUMN store_product_id`

### Phase 2: `admin/add_fresh_purchase_item.php` 수정
- 서버측: `$storeProductId`, `fresh_find_store_product()` 호출, `no_store_product_selected` 검증 제거. INSERT/`validatedRows`에서 `store_product_id` 제거
- 클라이언트: `findMatchingStoreProduct()`, `spDisplay`/`spSearchWrap` 관련 DOM, `refreshStoreProductCell()`, `it.storeProductId` submit 검증, draft 복원 시 `store_product_id` 참조 모두 제거

### Phase 3: `admin/fresh_purchase_batch_detail.php` 수정
- SELECT에서 `JOIN products p ON p.id = fpi.store_product_id`, `p.name_ko AS store_product_name_ko, p.sku` 제거
- 표 헤더/행에서 점포상품 컬럼 제거, `colspan` 7→6

### Phase 4: 죽은 코드 정리
- `admin/fresh_product_common.php`에서 `fresh_search_store_products()`, `fresh_find_store_product()` 삭제
- `admin/ajax_search_fresh_store_products.php` 파일 삭제
- `lang/ko.json`, `lang/en.json`에서 미사용 키 4개 삭제

---

## 3. 성공 기준

- [ ] 신선매입 등록 화면에 점포상품 검색/매칭 UI가 전혀 보이지 않음
- [ ] 매입 저장이 store_product_id 없이 정상 동작 (`php -l` 통과, 서버 검증 오류 없음)
- [ ] 매입 상세 조회 화면이 점포상품 컬럼 없이 정상 표시됨
- [ ] `git grep`으로 `store_product_id`, `fresh_find_store_product`, `fresh_search_store_products` 잔여 참조 없음 (마이그레이션 스크립트 자체 제외)
- [ ] DB 마이그레이션 스크립트는 작성만 하고, 실제 실행은 사용자 승인 후 진행
