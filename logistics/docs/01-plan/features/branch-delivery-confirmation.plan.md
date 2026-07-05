---
feature: branch-delivery-confirmation
phase: plan
created: 2026-06-10
updated: 2026-06-10
level: Dynamic
status: active
---

# Plan: Branch Delivery Confirmation (지점 배송 확인)

## Executive Summary

| Perspective | Description |
|------------|-------------|
| **Problem** | Store staff cannot confirm receipt of shipments from logistics center. No real-time delivery status sync between @store and @logistics systems. |
| **Solution** | Add delivery confirmation flow: store confirms receipt on orders page → order auto-updates to "delivered" in logistics center. |
| **Function UX Effect** | Store: Incoming shipments section in orders page with one-click confirm. Logistics: Live order status updates without manual intervention. |
| **Core Value** | Eliminates manual delivery confirmation step; enables real-time logistics visibility; reduces fulfillment friction. |

## Context Anchor

| Dimension | Value |
|-----------|-------|
| **WHY** | Branch outbound workflow requires cross-system delivery confirmation without manual logistics approval—store initiates, system auto-completes. |
| **WHO** | Store staff (confirm delivery); Logistics staff (view updated delivery status); System (sync status via API). |
| **RISK** | Race condition if confirm happens while outbound order is still processing; incorrect order ID mapping; delivery confirmation not reaching logistics. |
| **SUCCESS** | ✅ Store can confirm delivery on existing orders page. ✅ Logistics order status auto-updates to "delivered" within <2s. ✅ No manual approval step needed. |
| **SCOPE** | @store orders page modification + new API endpoint for delivery confirmation + @logistics order status listener (webhook/polling). |

---

## 1. Requirements

### Functional Requirements

**FR-01: Store-side Delivery Confirmation UI**
- Add "Incoming Shipments" section to @store/orders.php (or integrate into existing orders list)
- Display only orders with status = 'shipped' (incoming from logistics)
- Show: product list (product name + quantity), shipment date, shipment source (logistics center name)
- Single "수령했습니다 (Confirmed Received)" button per shipment
- Button trigger → POST to @logistics API with delivery confirmation
- On success: hide order from "Incoming" section; show success message
- On error: show error message with retry option

**FR-02: Logistics-side Order Status Update**
- Create new API endpoint: `POST /ajax/order_confirm_delivery.php?action=confirm_delivery`
  - Accept: order_id, store_id (for validation), csrf_token
  - Validate: order exists, status='shipped', belongs to correct store
  - Update: lc_orders.status = 'delivered', lc_orders.delivered_at = NOW()
  - Return: {success: true/false, message}
- Order detail page (lc_orders.php) must show delivery status updates in real-time (manual refresh shows latest state)

**FR-03: Data Consistency**
- Delivery confirmation is one-way: shipped → delivered (no reverse status change allowed)
- Only store that received shipment can confirm (validate store_id match)
- Each order confirmed only once (prevent duplicate confirmations via idempotency or state check)

### Non-Functional Requirements

- **Latency**: Delivery confirmation should reach logistics within 2 seconds
- **Availability**: If store→logistics API call fails, show user-friendly error and allow retry
- **Audit**: Log delivery confirmations in lc_orders.delivered_at timestamp + log table (optional)

---

## 2. Scope & Constraints

### In Scope
- Store-side: Add incoming shipments UI to existing orders page
- Logistics-side: New delivery confirmation API endpoint + status update logic
- Cross-system: HTTP POST API call from @store to @logistics with CSRF token

### Out of Scope
- SMS/Email notifications on delivery confirmation
- Automatic status sync (e.g., webhook listener) — manual refresh is acceptable
- Delivery proof (photo/signature capture)
- Return/rejection flows

### Technical Constraints
- Use existing @store and @logistics auth/session patterns (no new auth system)
- @store and @logistics share same database (both connect to `lc_*` tables)
- CSRF token must be validated on @logistics API endpoint
- No new database tables required (reuse lc_orders)

---

## 3. Architecture Overview

### Data Model
- **lc_orders** table: Already has `status`, `shipped_at` columns
  - Add column (or reuse): `delivered_at` TIMESTAMP NULL (timestamp when store confirmed delivery)
  - Status values: 'pending' → 'approved' → 'shipped' → 'delivered' (or 'cancelled')

### API Integration
```
@store/orders.php (UI)
    ↓ POST with csrf_token
@logistics/ajax/order_confirm_delivery.php (API endpoint)
    ↓ Validate & Update lc_orders
@logistics/order_detail.php (View)
    ← Refresh to see delivered_at timestamp
```

### Authentication & Authorization
- @store requests must include valid session + CSRF token
- @logistics endpoint validates: lc_require_staff() checks if current user is logistics staff
- (Optional) Add role check: only staff of matching store can confirm

---

## 4. User Flows

### Store Workflow
1. Store staff logs into @store/orders.php
2. Page displays two sections:
   - "배송 대기 중 (Incoming Shipments)": orders with status='shipped'
   - "주문 내역 (Order History)": other statuses
3. For each incoming shipment, show: product list, shipment date, source (logistics center name)
4. Click "수령했습니다" button
5. Loading state → POST to @logistics/ajax/order_confirm_delivery.php
6. On success: order moves to "Order History" section; success toast shown
7. On failure: error message with retry button

### Logistics Workflow
1. Logistics staff creates branch outbound order (status='shipped')
2. Logistics staff views /orders.php or /order_detail.php
3. Order shows status='shipped' with shipped_at timestamp
4. (No manual action needed)
5. When store confirms: order status auto-updates to 'delivered' with delivered_at timestamp
6. Logistics staff refreshes /order_detail.php to see updated status

---

## 5. Success Criteria

| Criterion | Definition |
|-----------|-----------|
| **SC-01** | Store-side incoming shipments section displays only orders with status='shipped' |
| **SC-02** | Store staff can click "Confirmed Received" button and trigger API call |
| **SC-03** | API endpoint validates order exists, status is 'shipped', and store_id matches |
| **SC-04** | Order status updates from 'shipped' to 'delivered' in @logistics lc_orders table |
| **SC-05** | delivered_at timestamp is set on confirmation (NOW() in MySQL) |
| **SC-06** | Duplicate confirmations are prevented (idempotent: 2nd confirm returns success but no-op) |
| **SC-07** | CSRF token is validated on API endpoint |
| **SC-08** | Error handling: network failure shows user-friendly message with retry option |
| **SC-09** | No new external dependencies required (use existing session, auth, CSRF patterns) |

---

## 6. Risks & Mitigations

| Risk | Severity | Mitigation |
|------|----------|-----------|
| Network failure during POST (order partially delivered) | High | Idempotent endpoint: 2nd POST from store is no-op; logistics staff can force status via order_detail.php edit (manual override) |
| Store confirms same order twice | Medium | Implement idempotency check: if status already='delivered', return success (no duplicate update) |
| Wrong store confirms delivery for another store's order | High | Always validate: current_store_id == order.store_id in API endpoint |
| Confirm API request is forged (CSRF) | High | Require CSRF token; validate via lc_verify_csrf() |
| Order is not yet in lc_orders when store tries to confirm | Low | API returns 404/error if order not found; store sees error message |

---

## 7. Implementation Plan

### Phase 1: Backend API (1-2 hours)
1. Create `/logistics/ajax/order_confirm_delivery.php`
   - New action: `confirm_delivery`
   - Validate: order_id, store_id, CSRF token
   - Update: lc_orders SET status='delivered', delivered_at=NOW() WHERE id=? AND store_id=? AND status='shipped'
   - Idempotency: If already delivered, return success
   - Return JSON response

### Phase 2: Store-side UI (2-3 hours)
1. Modify `/store/orders.php`
   - Query: SELECT * FROM lc_orders WHERE status='shipped' AND store_id=? ORDER BY order_date DESC
   - Render "Incoming Shipments" section with product list per order
   - Add "Confirmed Received" button with POST handler
   - Show loading/success/error states

### Phase 3: Logistics-side View (1 hour)
1. Modify `/logistics/order_detail.php`
   - Display delivered_at timestamp if status='delivered'
   - Show delivery confirmation badge/icon

### Phase 4: Testing & QA (1-2 hours)
1. Test store confirmation flow end-to-end
2. Test idempotency (confirm 2x, verify only 1 update)
3. Test CSRF validation
4. Test error cases (wrong store, order not found)

---

## 8. Open Questions

1. **Notification on delivery confirmation**: Should store receive email/SMS? → Out of scope for now
2. **Manual override**: Can logistics staff force status to 'delivered' manually? → Recommend YES (via order_detail.php edit form)
3. **Audit log**: Should we log who confirmed each delivery? → Optional (use delivered_at + user context)
4. **Incoming shipments page**: Only show on @store, or also on @logistics for visibility? → Recommend: only on @store (store-facing feature)

---

## 9. Dependencies

- Existing: lc_orders table, @store orders.php, @logistics order_detail.php
- No new npm packages required
- No database migration needed (reuse delivered_at column if exists, else add as future migration)

---

## 10. Success Metrics

- ✅ Store staff can confirm delivery in <10 seconds (UI + API + DB update)
- ✅ Logistics staff sees updated delivery status after manual page refresh
- ✅ Zero delivery confirmation API errors in first week of production
- ✅ CSRF token validation prevents unauthorized status changes

---

## Next Steps

→ **Design Phase**: `/pdca design branch-delivery-confirmation`
- Define exact UI mockups for store-side incoming shipments section
- Design API request/response contracts
- Create session guide for incremental implementation (module-1: backend, module-2: store UI, module-3: logistics view)
