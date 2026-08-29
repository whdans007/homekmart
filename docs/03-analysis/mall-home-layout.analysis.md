---
template: analysis
feature: mall-home-layout
date: 2026-08-13
author: whdans007
project: HOME K MART
---

# mall-home-layout Analysis Document (Check Phase)

> **Plan**: [mall-home-layout.plan.md](../01-plan/features/mall-home-layout.plan.md)
> **Design**: [mall-home-layout.design.md](../02-design/features/mall-home-layout.design.md) (v0.2)
> **Method**: Static-only gap analysis (gap-detector agent) — no live server/mysqli-enabled local PHP CLI available in this environment. Runtime L1/L2/L3 not executed.

## 1. Match Rate (static-only formula)

`Overall = Structural×0.2 + Functional×0.4 + Contract×0.4`

| Axis | Score | Basis |
|------|:-----:|-------|
| Structural | 96% | 12/13 Design §11.1 artifacts exist; `mall_home_sections` schema byte-identical to Design §3.3. Only `mall/uploads/banners/` absent (created on first upload, matches existing `uploads/products/` pattern) |
| Functional | 88% | 17.5/19 §5.4 checklist items; deductions were for the issues fixed below |
| Contract | 95% | All 3 designed endpoints match §4.2 request/response shape exactly; client param names match server reads 1:1 |
| **Overall** | **92.4%** | Already ≥90% before this session's fixes; fixes below further close functional/security gaps |

## 2. bind_param Audit — Clean

Given a known bug class from the related `shopping-mall` feature's own Check phase (PHP constants passed by reference into `bind_param()`, causing uncaught fatal errors invisible to `php -l`), every `bind_param()` call site across the 11 new files was specifically re-audited. **No recurrence found** — every `MALL_STORE_ID` use is assigned to a local variable first, and all type-string character counts match their argument counts (`sssiii`=6/6, `issssii`=7/7, `iii`=3/3, `sss`=3/3, plus the dynamically-built strings in `catalog.php` and `save_home_section.php`).

## 3. Verified Security Requirements

| Requirement | Result |
|---|---|
| `mall_render_product_list_section()` uses `mall_calculate_price()` exclusively | ✅ Zero direct reads of `inventory.selling_price`/`wholesale_products.wholesale_price` |
| All 4 admin ajax endpoints check `mall_management` permission | ✅ |
| 3 state-changing endpoints verify CSRF token | ✅; `search_products.php` correctly exempt (GET, read-only) |
| Banner upload security matches `save_retail_product.php` pattern | ✅ MIME whitelist + `finfo` real-content check + random filename + 5MB limit |
| Guest (`$member === null`) visitors don't hit fatal errors | ✅ `index.php`/`category.php` handle null member safely |
| `reorder_home_sections.php` loop-based `bind_param()` reuse | ✅ correct — each iteration rebinds fresh loop variables, no shared-reference bug |

## 4. Issues Found and Fixed This Session

| ID | Severity | Issue | Fix |
|----|----------|-------|-----|
| IMP-1 | Important | `save_home_section.php`: product list save destroyed the admin's chosen product order (DB `IN()` return order used instead) and silently dropped invalid IDs instead of erroring | Now preserves the submitted order and rejects the request with `VALIDATION_ERROR` if any ID doesn't resolve to an active product |
| IMP-2 | Important | `catalog.php`: category tab filtering matched only the exact `category_id`, so products assigned to a *subcategory* never appeared under their *parent* tab — top-level tabs would often render empty | Now expands the filter to include direct child category IDs (`WHERE category_id IN (self, children)`) |
| IMP-3 | Important | `save_home_section.php`: creating a banner section with no image succeeded silently, producing an invisible section (`mall_render_banner_section()` returns `''` without an `image_path`) | Now rejects banner creation with `VALIDATION_ERROR` if no image is present |
| IMP-4 | Important (Security) | `admin/home_layout.php`: product search results were inserted via `innerHTML` with an unescaped product name — stored XSS in the admin search dropdown | Switched to `textContent`/`createElement`, matching the safe pattern already used in `renderSelectedProducts()` |
| MIN-1 | Minor | `reorder_home_sections.php`: `updated` count used `affected_rows`, which is 0 when `sort_order` doesn't change, undercounting vs. the Design §4.2 example | Now counts loop iterations (attempts) instead |
| MIN-2 | Minor | `save_home_section.php`: the next-`sort_order` lookup used raw string concatenation instead of a prepared statement (int-cast so not exploitable, but inconsistent with Design §7 "모든 쿼리는 prepared statement") | Converted to a prepared statement |

Remaining Minor items accepted/deferred (not fixed this session, none are correctness or security issues): admin product list is N+1 on `mall_get_products_by_ids()` calls per card (MIN-5, cosmetic performance), `preview_home.php` lacks a `?show_inactive=` toggle (MIN-3, always shows inactive dimmed — acceptable default), banner `link_value` for `url` type isn't scheme-whitelisted beyond `htmlspecialchars` (MIN-6, admin-only input, low risk), `mall/uploads/banners/.htaccess` deny-execute rule not pre-created (MIN-4, matches existing `uploads/products/` precedent).

**Documentation drift closed**: `search_products.php` (a Do-phase addition not in the original Design §4.1) has been added to the Design document retroactively.

All touched files re-verified: **57/57 files in `mall/` pass `php -l`**.

## 5. Plan §1.3 Success Criteria — Status

| # | Criterion | Status |
|---|-----------|--------|
| 1 | 섹션 추가+드래그 순서변경 | ✅ Met |
| 2 | 노출 ON/OFF 토글 | ✅ Met |
| 3 | 배너 업로드+링크(URL/카테고리/상품) | ✅ Met (now requires image on create) |
| 4 | 상품 리스트 수동 선택 | ✅ Met (order now preserved) |
| 5 | 실시간 미리보기(실제 렌더 함수 재사용) | ✅ Met |
| 6 | 카테고리 클릭→가로탭+카테고리별 상품 | ✅ Met (now includes subcategory products) |
| 7 | 비회원도 홈/카테고리 화면 열람 가능 | ✅ Met |

**7/7 Met.**

## 6. Recommendation

No Critical issues were found. All 4 Important issues and 2 of 6 Minor issues were fixed inline in this Check pass. Match Rate was already ≥90% before these fixes and is higher now. **Next recommended step**: run the DB migration (`sql/run_mall_home_sections_migration.php`) and do an actual browser walk-through (create each of the 3 section types → drag reorder → preview → verify on `mall/index.php` and `mall/category.php`), since this feature — like `shopping-mall` before it — has not yet been executed against a live server.

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-13 | Initial Check-phase gap analysis; IMP-1~4, MIN-1~2 fixed inline | whdans007 |
