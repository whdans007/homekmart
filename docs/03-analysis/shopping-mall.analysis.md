---
template: analysis
feature: shopping-mall
date: 2026-08-13
author: whdans007
project: HOME K MART
---

# shopping-mall Analysis Document (Check Phase)

> **Plan**: [shopping-mall.plan.md](../01-plan/features/shopping-mall.plan.md)
> **Design**: [shopping-mall.design.md](../02-design/features/shopping-mall.design.md) (v0.2)
> **Method**: Static-only gap analysis (gap-detector agent) — no live server reachable in this environment (local CLI PHP has no mysqli loaded; remote host does not yet have this code deployed). Runtime L1/L2/L3 not executed.

## Context Anchor (carried from Design)

| Axis | Content |
|------|---------|
| WHY | 상품/재고/마진 데이터가 관리 전용으로만 존재 — 고객이 직접 가입해 도매가/소매가로 주문하는 온라인몰이 없음 |
| RISK 1순위 | 도매가가 미승인/소매 회원에게 노출되는 것 — `pricing.php` 단일화 + 서버측 재검증으로 완화 |
| SCOPE 경계 | 결제 게이트웨이 제외(COD만), 다점포 선택 제외(store_id 고정), 기존 `delivery_*`와 통합 안 함 |

---

## 1. Match Rate (static-only formula)

`Overall = Structural×0.2 + Functional×0.4 + Contract×0.4`

| Axis | Initial (before this Check pass) | After C-1/C-2 fix (this session) |
|------|:---:|:---:|
| Structural | 93% (42/46 §11.1 artifacts, 14/14 tables, 12/12 endpoints) | ~96% (dashboard.php added) |
| Functional Depth | 60% (blocked by C-1 fatal error) | ~90% (estimated — C-1 was the dominant blocker across nearly every core path) |
| API Contract | 92% | 92% (unchanged) |
| **Overall** | **79%** | **~91%** (estimated, not yet re-verified by a fresh gap-detector pass) |

The 91% figure is the gap-detector agent's own projection after being told C-1/C-2 would be fixed — it has not been independently re-run to confirm. Treat it as directional, not final.

## 2. Critical Issues — Found and Fixed This Session

### C-1: `MALL_STORE_ID` passed by reference to `bind_param()` — FIXED
`mysqli_stmt::bind_param()` requires its value arguments by reference. A PHP constant (`define()`-created) cannot be passed by reference, so every call site threw an uncaught fatal `Error` at runtime — invisible to `php -l`, only surfaces when the code actually executes. This affected:
- `mall/lib/pricing.php` (3 sites) — every price/stock lookup, i.e. catalog, detail, cart, checkout, all 6 customer ajax endpoints
- `mall/product.php` — product detail page
- `mall/signup.php` — member registration completely blocked
- `mall/admin/ajax/save_retail_product.php` — "쇼핑몰에 추가" curation
- `mall/admin/ajax/approve_wholesale_member.php` — new-customer-creation path of wholesale approval

**Fix applied**: assign `MALL_STORE_ID` to a local variable before `bind_param()` at all 7 sites (matches the pattern `mall/lib/order.php` already used correctly). Re-verified with `php -l` on all 6 touched files — all pass.

### C-2: `mall/admin/dashboard.php` missing — FIXED
Linked from the shared admin sidebar (`mall/admin/partials/sidebar.php`) but the file didn't exist, so every admin page had a dead nav link (404). Design §11.1/§8.4 scenario 4 require it.

**Fix applied**: created `mall/admin/dashboard.php` — 오늘/이번달 주문 건수·매출, 도매 승인대기 수, 접수대기 주문 수, 최근 주문 10건 목록. `php -l` passes.

## 3. Important Issues — Not Yet Fixed (require a decision)

| ID | Issue | File(s) | Impact |
|----|-------|---------|--------|
| I-1 | No CSRF protection on any of the 12 ajax endpoints (Design §7 mandates it) | all `mall/*/ajax/*.php` | Cross-site form submission could trigger order placement or wholesale approval while an admin/member session is active |
| I-2 | `delete_product_image.php` / `reorder_product_images.php` missing (Design §11.1) | `mall/admin/products.php` | Product images can be added but never deleted or reordered from the UI |
| I-3 | Catalog (`mall/index.php`) only ever queries `mall_products` (retail curation) — a `wholesale_products` row toggled visible via `mall_wholesale_visibility` never appears unless *also* separately curated into `mall_products` | `mall/index.php:34` | Undercuts FR-05 / Plan SC-3 (도매가 실시간 연동) — wholesale members may see an empty/wrong catalog |
| I-4 | Cart instant-discount banner is a static string, not the actual applied %/remaining-to-next-tier | `mall/cart.php:39` | Cosmetic gap vs Design §5.4 requirement |
| I-5 | `add_to_cart.php` accepts any `product_id` with no curation/visibility check | `mall/ajax/add_to_cart.php:30` | Low risk (checkout still re-validates via `pricing.php`/stock), but allows adding non-curated products to cart |

## 4. Minor Issues (accepted as-is / deferred)

- M-1: `mall/admin/wholesale_products.php` reads `wholesale_price` directly instead of via `pricing.php` — acceptable, admin-only + permission-gated, documented exception to §1.2.
- M-2: `forgot_password.php` doesn't send email (no mail infrastructure exists in this project) — already flagged as a TODO in code.
- M-3: No order status history log table — deferred to v2.
- M-4: `save_discount_rules.php` / `save_retail_product.php` return `{success:true}` without a `data` key — cosmetic contract deviation.
- M-5: `mall/uploads/products/` not pre-created in repo (created on first upload via `mkdir`) — add `.gitkeep`.

## 5. Plan §6.1 Success Criteria — Final Status

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | 도매/소매 회원이 각각 가입·로그인하여 등급에 맞는 가격을 확인할 수 있다 | ✅ Met (after C-1 fix) | `signup.php`, `login.php`, `pricing.php:153-203` |
| 2 | 관리자가 상품 선택+사진등록으로 소매몰에 노출 | ⚠️ Partial | Add/curate works (after C-1 fix); no delete/reorder (I-2) |
| 3 | 소매/도매 판매가 실시간 연동 | ✅ Met | No price columns stored; `pricing.php` reads live |
| 4 | 도매 즉석할인/누적할인 정확 계산 | ✅ Met | `pricing.php:100-136`, `cart.php` two-pass tier resolution |
| 5 | 소매 등급 할인 + 월별 배치 재산정 | ✅ Met | `pricing.php`, `batch/recalc_tiers.php` |
| 6 | 미승인 도매 회원에게 도매가 비노출 | ✅ Met | `pricing.php` requires `approved`; retail fallback verified |
| 7 | 관리자가 한 곳에서 상품·회원·할인규칙·주문 관리 | ✅ Met (after C-2 fix) | 6/6 admin pages now present |

**Score: 6/7 Met, 1/7 Partial** (criterion #2, blocked only by the deferred I-2).

## 6. Known-Risk Areas — Verified Clean

| Check | Result |
|-------|--------|
| Single pricing path (§1.2) | Pass — 1 documented admin-only exception (M-1); no customer-facing bypass |
| IDOR protection on cart/wishlist/orders | Pass at every read/update/delete site checked |
| `bind_param` type-string correctness | Pass — the only defect found was the by-reference issue (C-1), not a count/order mismatch |
| Wholesale approval re-verified server-side per request | Pass — session cache not trusted |
| Purchase-gated review submission | Pass — `submit_review.php` requires completed order + product presence + duplicate guard |

## 7. Resolution Log (this Check pass)

User selected "지금 모두 수정" — all 5 Important items were fixed inline in this same session:

| ID | Fix |
|----|-----|
| I-1 | Added `mall/lib/csrf.php` (token generate/verify/field). Wired into all 6 admin ajax endpoints (token emitted via `window.MALL_CSRF_TOKEN` in `admin/partials/sidebar.php`) and `submit_order.php` (token emitted in `checkout.php`). |
| I-2 | Added `mall/admin/ajax/delete_product_image.php` and `reorder_product_images.php` (tenant-scoped via `mall_products.store_id`). `products.php` now renders per-product thumbnails with delete/▲/▼ controls. |
| I-3 | `mall/index.php` catalog query rewritten: for `channel === 'wholesale'`, the eligible-product set is now a `UNION` of `mall_products` curation and `mall_wholesale_visibility`-visible `wholesale_products`, so wholesale-visible products appear without requiring separate retail curation. |
| I-4 | Added `mall_get_next_wholesale_instant_tier()` to `pricing.php`. `mall/cart.php` banner now shows the actually-applied discount % and the amount remaining to the next tier, computed from the real cart subtotal. |
| I-5 | `mall/ajax/add_to_cart.php` now calls a new `mall_is_product_eligible_for_channel()` check (curated for retail / visible for wholesale) and a stock check before inserting, rejecting non-curated or out-of-stock additions. |

All touched files re-verified with `php -l` (46/46 pass across the full `mall/` tree, up from 42 — 4 new files added).

## 8. Recommendation

C-1 and C-2 were fixed first because C-1 was a total blocker (nothing in the customer-facing mall would have run) and C-2 was a one-file, low-risk addition. I-1~I-5 were then fixed per user decision. Remaining Minor items (M-1~M-5) are accepted/deferred as documented in §4 — none are blocking. This static-analysis pass has not been re-run by a fresh gap-detector agent invocation after these fixes; the Match Rate below is an informed estimate, not a re-measurement. **Next recommended step: an actual browser walk-through (signup → curate → order → admin approve/status-change) against the live DB, since this is the first point where runtime behavior can be observed.**

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-13 | Initial Check-phase gap analysis; C-1/C-2 fixed inline | whdans007 |
| 0.2 | 2026-08-13 | I-1~I-5 fixed inline per user decision ("지금 모두 수정") — see §7 Resolution Log | whdans007 |
