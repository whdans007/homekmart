# Test Plan: Branch Delivery Confirmation

**Feature**: branch-delivery-confirmation  
**Date**: 2026-06-10  
**Modules Tested**: M1 (API), M2 (Store UI), M3 (Logistics View)

---

## L1 — API Endpoint Tests

### Test Case L1-01: Valid Confirmation
**Scenario**: Store confirms delivery of shipped order
- **Setup**: Create order in lc_orders with status='shipped', store_id=5, id=123
- **Action**: POST `/ajax/order_confirm_delivery.php` with order_id=123, store_id=5, valid csrf_token
- **Expected**:
  - HTTP 200 OK
  - Response: `{success: true, message: "배송 확인이 완료되었습니다."}`
  - DB: lc_orders.status='delivered', delivered_at=NOW()
- **Status**: ⚠️ Manual test (requires local database)

### Test Case L1-02: Idempotency (2nd Confirm)
**Scenario**: Store confirms same order twice
- **Setup**: Run L1-01, then repeat exact same POST
- **Expected**:
  - HTTP 200 OK
  - Response: `{success: true, message: "이미 배송 확인된 주문입니다."}`
  - DB: No additional update (same delivered_at timestamp as L1-01)
- **Status**: ⚠️ Manual test

### Test Case L1-03: CSRF Token Validation
**Scenario**: POST with invalid/missing CSRF token
- **Setup**: Create valid order
- **Action**: POST without csrf_token or with invalid token
- **Expected**:
  - HTTP 403 Forbidden (lc_verify_csrf will terminate early)
- **Status**: ⚠️ Manual test

### Test Case L1-04: Wrong Store Confirms
**Scenario**: Store A tries to confirm order for Store B
- **Setup**: Create order with store_id=5
- **Action**: POST with store_id=6, valid order_id, valid csrf_token from Store A session
- **Expected**:
  - HTTP 404
  - Response: `{success: false, message: "주문을 찾을 수 없거나 이 지점의 주문이 아닙니다."}`
  - DB: No update
- **Status**: ⚠️ Manual test

### Test Case L1-05: Order Not Found
**Scenario**: POST non-existent order_id
- **Setup**: None (no such order)
- **Action**: POST with order_id=999999, store_id=5, valid csrf_token
- **Expected**:
  - HTTP 404
  - Response: `{success: false, message: "주문을 찾을 수 없거나 이 지점의 주문이 아닙니다."}`
- **Status**: ⚠️ Manual test

### Test Case L1-06: Wrong Status (Pending)
**Scenario**: Try to confirm order with status='pending'
- **Setup**: Create order with status='pending'
- **Action**: POST with valid order_id, store_id, csrf_token
- **Expected**:
  - HTTP 200 OK
  - Response: `{success: false, message: "주문이 배송 상태가 아닙니다."}`
  - DB: No update
- **Status**: ⚠️ Manual test

---

## L2 — UI Action Tests

### Test Case L2-01: Render Incoming Shipments Section
**Scenario**: Store views orders page with pending shipments
- **Setup**: Login to @store, create order with status='shipped'
- **Action**: Navigate to /store/orders.php
- **Expected**:
  - "배송 대기 중" section visible
  - Order card shows order ID, shipped date, product list
  - "수령했습니다" button visible
- **Status**: ⚠️ Manual browser test

### Test Case L2-02: No Incoming Section When Empty
**Scenario**: Store views orders page with no pending shipments
- **Setup**: Login to @store, no orders with status='shipped'
- **Action**: Navigate to /store/orders.php
- **Expected**:
  - "배송 대기 중" section NOT visible
  - Only "Order History" section shown
- **Status**: ⚠️ Manual browser test

### Test Case L2-03: Confirm Button Click
**Scenario**: Click "수령했습니다" button
- **Setup**: From L2-01 state
- **Action**: Click confirm button on incoming order
- **Expected**:
  - Button disabled, spinner shown
  - POST request sent to `/ajax/order_confirm_delivery.php` with order_id, store_id, csrf_token
  - On success: order card fades out, toast/alert shown, page reloads
  - Order disappears from "배송 대기 중" section
- **Status**: ⚠️ Manual browser test

### Test Case L2-04: Confirm Button Error Handling
**Scenario**: Network error during confirm
- **Setup**: Mock network failure (browser dev tools)
- **Action**: Click confirm button
- **Expected**:
  - Alert shown: "네트워크 오류"
  - Button re-enabled
  - Order card remains visible
  - Allow retry
- **Status**: ⚠️ Manual browser test

---

## L3 — End-to-End Scenario

### Test Case L3-01: Full Delivery Confirmation Flow
**Scenario**: Complete workflow from logistics outbound to store delivery confirmation
1. **Step 1**: Logistics staff creates branch outbound order
   - Via /branch_outbound.php
   - Status='shipped', store_id=5
   - Order ID: 999 (example)

2. **Step 2**: Store staff views orders
   - Login to @store, view /orders.php
   - **Verify**: Order #999 appears in "배송 대기 중" section

3. **Step 3**: Store staff confirms delivery
   - Click "수령했습니다" button
   - **Verify**: Button disables, spinner shows, order fades out

4. **Step 4**: Logistics staff refreshes order detail
   - Login to @logistics
   - Navigate to /order_detail.php?id=999
   - **Verify**: Status badge shows "Delivered" (or equivalent)
   - **Verify**: Green badge shows "배송 확인: 2026-06-10 14:30" (or similar timestamp)

5. **Step 5**: Store refreshes orders page
   - **Verify**: Order #999 no longer in "배송 대기 중" section
   - May appear in order history if status filter shows "delivered"

**Expected Result**: All 5 steps complete without errors, status flows correctly both directions

**Status**: ⚠️ Manual end-to-end test (requires 2 separate browser sessions)

---

## Manual Test Checklist

### Prerequisites
- [ ] `lc_orders.delivered_at` column exists in database
- [ ] @store and @logistics share same database (lc_* tables)
- [ ] Both systems are running and accessible
- [ ] Test user has staff role (for @logistics)
- [ ] Test user has store role (for @store, store_id=5 assumed)

### API Tests (curl/Postman)
- [ ] L1-01: Valid confirmation updates order status
- [ ] L1-02: Idempotent retry returns success
- [ ] L1-03: CSRF validation prevents unauthorized access
- [ ] L1-04: Wrong store blocked
- [ ] L1-05: Non-existent order returns 404
- [ ] L1-06: Wrong status returns error

### UI Tests (Browser)
- [ ] L2-01: Incoming section renders with orders
- [ ] L2-02: No section shown when empty
- [ ] L2-03: Confirm button triggers POST + reload
- [ ] L2-04: Error handling on network failure

### E2E Test (2 browser windows)
- [ ] L3-01: Full flow works end-to-end
  - [ ] Logistics creates outbound order
  - [ ] Store sees incoming shipment
  - [ ] Store confirms delivery
  - [ ] Logistics sees delivery timestamp

---

## Automation Scripts (Optional)

### Curl Test - L1-01 (Valid Confirmation)
```bash
curl -X POST http://192.168.1.116/logistics/ajax/order_confirm_delivery.php \
  -H "Cookie: PHPSESSID=<staff-session>" \
  -d "action=confirm_delivery&order_id=123&store_id=5&csrf_token=<token>"
```

### Database Verification
```sql
-- Check delivery confirmation
SELECT id, status, delivered_at FROM lc_orders WHERE id=123;

-- Should show: | 123 | delivered | 2026-06-10 14:30:xx |
```

---

## Test Result Summary

| Test Case | Status | Notes |
|-----------|--------|-------|
| L1-01 | ⚠️ Pending | Manual API test via curl or Postman |
| L1-02 | ⚠️ Pending | Verify no duplicate DB updates |
| L1-03 | ⚠️ Pending | CSRF validation by lc_verify_csrf() |
| L1-04 | ⚠️ Pending | Store isolation check |
| L1-05 | ⚠️ Pending | 404 handling |
| L1-06 | ⚠️ Pending | Status validation |
| L2-01 | ⚠️ Pending | Browser rendering test |
| L2-02 | ⚠️ Pending | Empty state rendering |
| L2-03 | ⚠️ Pending | Button interaction + POST |
| L2-04 | ⚠️ Pending | Error state handling |
| L3-01 | ⚠️ Pending | Full E2E scenario |

---

## Sign-Off

- **Developer**: [Name]
- **Tester**: [Name]
- **QA Lead**: [Name]
- **Date**: [YYYY-MM-DD]

When all tests pass, mark as ✅ PASSED and update phase to "check" for gap analysis.
