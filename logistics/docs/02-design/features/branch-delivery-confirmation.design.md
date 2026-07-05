---
feature: branch-delivery-confirmation
phase: design
created: 2026-06-10
updated: 2026-06-10
architecture: pragmatic-balance
level: Dynamic
status: active
---

# Design: Branch Delivery Confirmation (지점 배송 확인)

## Context Anchor

| Dimension | Value |
|-----------|-------|
| **WHY** | Branch outbound workflow requires cross-system delivery confirmation without manual logistics approval—store initiates, system auto-completes. |
| **WHO** | Store staff (confirm delivery); Logistics staff (view updated delivery status); System (sync status via API). |
| **RISK** | Race condition if confirm happens while outbound order is still processing; incorrect order ID mapping; delivery confirmation not reaching logistics. |
| **SUCCESS** | ✅ Store can confirm delivery on existing orders page. ✅ Logistics order status auto-updates to "delivered" within <2s. ✅ No manual approval step needed. |
| **SCOPE** | @store orders page modification + new API endpoint for delivery confirmation + @logistics order status listener (webhook/polling). |

---

## 1. System Overview

### Architecture Diagram

```
┌─────────────────────┐
│  @store/orders.php  │
│  (Incoming Section) │──┐
└─────────────────────┘  │ [1] POST /ajax/order_confirm_delivery.php
                          │     with order_id, store_id, csrf_token
                          │
                          ▼
                ┌──────────────────────────────┐
                │  @logistics/ajax/            │
                │  order_confirm_delivery.php  │
                │  (confirm_delivery action)   │
                └──────────────────────────────┘
                          │
                          │ [2] Validate & Update lc_orders
                          │     status='shipped' → 'delivered'
                          │
                          ▼
                ┌──────────────────────────────┐
                │     lc_orders Table          │
                │  - status = 'delivered'      │
                │  - delivered_at = NOW()      │
                └──────────────────────────────┘
                          │
                          │ [3] Return JSON {success, message}
                          │
                          ▼
                ┌─────────────────────┐
                │ Store UI: Show      │
                │ Success/Error Toast │
                │ Move order section  │
                └─────────────────────┘
```

---

## 2. Selected Architecture: Option C — Pragmatic Balance

**Rationale**: Balances implementation speed (3-4 hours) with maintainability. Avoids over-engineering while keeping code clear and extensible for future notifications/webhooks.

### Design Principles
- **Clarity over Abstraction**: Direct SQL with clear variable names instead of ORM wrapper
- **Reuse Existing Patterns**: Use `lc_require_staff()`, `lc_verify_csrf()`, existing session helpers
- **Idempotency**: Handle duplicate confirmations gracefully (2nd confirm is no-op)
- **Clear Boundaries**: API endpoint is isolated; store UI is independent
- **Extensible**: Easy to add audit logging, email notifications later

---

## 3. Database Schema

### Changes to `lc_orders` Table

**Column Addition** (if not already present):
```sql
ALTER TABLE lc_orders 
ADD COLUMN delivered_at TIMESTAMP NULL DEFAULT NULL;
```

**Status Flow**:
- `pending` → `approved` → `shipped` → `delivered` (or `cancelled`)

**Delivery Confirmation State Machine**:
```
shipped ──[Store confirms]──> delivered
  ↓         (idempotent)
  └─ [2nd confirm] → same state (no-op, return success)
```

---

## 4. API Contract

### Endpoint: `POST /ajax/order_confirm_delivery.php`

**Request**:
```json
{
  "action": "confirm_delivery",
  "order_id": 123,
  "store_id": 5,
  "csrf_token": "..."
}
```

**Response (Success)**:
```json
{
  "success": true,
  "message": "배송 확인이 완료되었습니다."
}
```

**Response (Error - Already Delivered)**:
```json
{
  "success": true,
  "message": "이미 배송 확인된 주문입니다."
}
```

**Response (Error - Validation Failure)**:
```json
{
  "success": false,
  "message": "주문을 찾을 수 없거나 상태가 맞지 않습니다."
}
```

**HTTP Status Codes**:
- `200 OK`: Valid request (success or idempotent retry)
- `400 Bad Request`: Invalid order_id/store_id
- `403 Forbidden`: CSRF token invalid
- `404 Not Found`: Order not found

---

## 5. Detailed Component Design

### 5.1 Backend API: `order_confirm_delivery.php`

**File**: `/logistics/ajax/order_confirm_delivery.php`

**Logic Flow**:
```php
1. Check action === 'confirm_delivery'
2. lc_require_staff() — only logistics staff can call
3. lc_verify_csrf() — validate CSRF token
4. Parse: order_id, store_id
5. Query lc_orders: SELECT status, store_id FROM lc_orders WHERE id=?
6. Validate:
   - Order exists → if not, return 404
   - status === 'shipped' → if not, return success (idempotent)
   - order.store_id === request.store_id → if not, return 403
7. Update: 
   - UPDATE lc_orders SET status='delivered', delivered_at=NOW() 
             WHERE id=? AND store_id=? AND status='shipped'
8. Return: {success: true, message}
9. On error: {success: false, message}
```

**Design Decision**: 
- Single UPDATE query with WHERE conditions (atomic, no race condition)
- No audit log table (use delivered_at timestamp + manual audit if needed)
- Return success for idempotent cases (2nd confirm of already-delivered order)

---

### 5.2 Store-side UI: `orders.php` Modification

**File**: `/store/orders.php`

**Layout**:
```
┌─────────────────────────────────────────────┐
│  Store Orders Page                          │
├─────────────────────────────────────────────┤
│ [Incoming Shipments] [Order History]        │
├─────────────────────────────────────────────┤
│ Incoming Shipments (collapsed section)      │
│ ┌──────────────────────────────────────────┐│
│ │ Order #123                              ││
│ │ Shipped: 2026-06-10 10:00               ││
│ │ Items:                                  ││
│ │  - Product A x 5                        ││
│ │  - Product B x 3                        ││
│ │ [수령했습니다] [Details]                 ││
│ └──────────────────────────────────────────┘│
│ ┌──────────────────────────────────────────┐│
│ │ Order #124                              ││
│ │ Shipped: 2026-06-09 14:30               ││
│ │ Items:                                  ││
│ │  - Product C x 2                        ││
│ │ [수령했습니다] [Details]                 ││
│ └──────────────────────────────────────────┘│
├─────────────────────────────────────────────┤
│ Order History                               │
│ (other statuses: pending, approved, etc)   │
└─────────────────────────────────────────────┘
```

**UI Elements**:
- **Incoming Shipments Header**: Only show if count > 0
- **Order Card**:
  - Order ID + link to details
  - Shipment date (from lc_orders.shipped_at)
  - Product list (query lc_order_items + lc_products)
  - Two buttons: "수령했습니다" (confirm), "Details" (link to order_detail.php)
- **Confirm Button Behavior**:
  - Disabled state during POST
  - Show spinner icon while loading
  - On success: remove order from incoming section; show toast "배송 확인이 완료되었습니다"
  - On error: show error toast with retry button

**JavaScript Handler**:
```js
// When "수령했습니다" button clicked:
1. Disable button, show spinner
2. POST /ajax/order_confirm_delivery.php {order_id, store_id, csrf_token}
3. On success: 
   - Remove order row from DOM
   - Show success toast
   - Auto-dismiss after 3 seconds
4. On error:
   - Show error toast
   - Enable button, hide spinner
   - Keep order in list for retry
```

---

### 5.3 Logistics-side View: `order_detail.php` Modification

**File**: `/logistics/order_detail.php`

**Changes**:
- If `status === 'delivered'` AND `delivered_at IS NOT NULL`:
  - Display delivered timestamp: "배송 확인: 2026-06-10 14:30"
  - Show delivery badge/icon (green checkmark) next to status
- No additional functionality needed (manual refresh shows updated state)

---

## 6. Security Considerations

### CSRF Protection
- ✅ Endpoint requires `csrf_token` parameter
- ✅ Validate via `lc_verify_csrf()` before any DB operation

### Authorization
- ✅ `lc_require_staff()` ensures only logistics staff can confirm delivery
- ✅ Store-side POST includes valid session (inherited from @store login)
- ✅ Validate `order.store_id === request.store_id` to prevent wrong store confirming

### SQL Injection Prevention
- ✅ Use prepared statements for all queries
- ✅ Bind parameters: `order_id`, `store_id`

### Race Condition Prevention
- ✅ Idempotency check: 2nd confirm of already-delivered order returns success without update
- ✅ WHERE clause: `WHERE status='shipped'` ensures only shipping-to-delivered transition

---

## 7. Error Handling

| Scenario | HTTP Code | Response | User Action |
|----------|-----------|----------|-------------|
| CSRF token invalid | 403 | {success: false, message} | Retry (show refresh page message) |
| Order not found | 404 | {success: false, message} | Refresh orders page |
| Wrong store confirms | 403 | {success: false, message} | N/A (prevent via store_id validation) |
| Order already delivered | 200 | {success: true} | N/A (idempotent, hidden from UI) |
| Network timeout | N/A | Show timeout error | Retry button |
| DB connection error | 500 | {success: false, message} | Show error, retry |

---

## 8. Test Plan

### L1 — API Endpoint Tests
- ✅ Valid confirmation: POST order_id + store_id → status updates to delivered
- ✅ Idempotency: POST same order twice → both return success, only 1 DB update
- ✅ CSRF validation: Missing/invalid token → 403 Forbidden
- ✅ Wrong store: Store A confirms order for Store B → 403 Forbidden
- ✅ Order not found: POST non-existent order_id → 404 Not Found
- ✅ Wrong status: Try to confirm 'pending' order → return success (no-op)

### L2 — UI Action Tests
- ✅ Render incoming shipments section only if status='shipped' exists
- ✅ Click "수령했습니다" → POST request sent with correct data
- ✅ On success: order removed from incoming section; toast shown
- ✅ On error: error toast shown; button re-enabled; order stays in list

### L3 — End-to-End Scenario
1. Logistics creates branch outbound order → status='shipped'
2. Store staff views orders.php → sees order in "Incoming Shipments"
3. Store staff clicks "수령했습니다"
4. API confirms delivery → status='delivered'
5. Logistics staff refreshes order_detail.php → sees delivered timestamp

---

## 9. Implementation Guide

### Module Map

| Module | Files | Effort | Description |
|--------|-------|--------|-------------|
| **Module-1** | `/logistics/ajax/order_confirm_delivery.php` | 1-2 hrs | Backend API: delivery confirmation endpoint + status update logic |
| **Module-2** | `/store/orders.php` (modify) | 2-3 hrs | Store UI: incoming shipments section + confirm button + POST handler |
| **Module-3** | `/logistics/order_detail.php` (modify) | 1 hr | Logistics view: display delivered_at timestamp if status='delivered' |
| **Module-4** | Testing + QA | 1-2 hrs | L1/L2/L3 tests: API validation, UI actions, end-to-end flow |

### Recommended Session Plan

**Session 1** (2-3 hours): Module-1 + Module-3
- Implement backend API (confirm_delivery action)
- Modify order_detail.php to show delivered timestamp
- Manual API testing via curl/Postman

**Session 2** (2-3 hours): Module-2 + Testing
- Modify orders.php to add incoming shipments UI
- Implement confirm button handler
- L2 UI testing + L3 end-to-end testing

---

## 10. Code References (Design Patterns)

### Existing Patterns to Reuse

**Authentication**: Use existing `lc_require_staff()` from `lib/auth.php`
```php
lc_require_staff(); // Validates logistics staff role
```

**CSRF**: Use existing `lc_csrf_token()` and `lc_verify_csrf()` from `lib/session_helper.php`
```php
$token = lc_csrf_token(); // Get token for form
lc_verify_csrf(); // Validate token on POST
```

**Database Connection**: Use existing `get_lc_db()` from `lib/auth.php`
```php
$conn = get_lc_db(); // Reuses shared connection
```

**Error Response**: Match existing API pattern
```php
echo json_encode(['success' => false, 'message' => '...']);
```

---

## 11. Implementation Guide

### 11.1 Pre-Implementation Checklist

- [ ] Verify `lc_orders.delivered_at` column exists (or plan migration)
- [ ] Confirm @store and @logistics share same database (lc_* tables)
- [ ] Review existing `lc_require_staff()`, `lc_verify_csrf()` patterns
- [ ] Plan module sequence: 1 → 3 → 2 → 4 (backend first, then UI, then tests)

### 11.2 Module-1: Backend API

**Create File**: `/logistics/ajax/order_confirm_delivery.php`

**Outline**:
```php
<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Design Ref: §5 — 배송 확인 API (store → logistics cross-system delivery confirmation)
if ($action === 'confirm_delivery') {
    lc_verify_csrf();
    
    $order_id = (int)($_POST['order_id'] ?? 0);
    $store_id = (int)($_POST['store_id'] ?? 0);
    
    if (!$order_id || !$store_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid params']);
        exit;
    }
    
    try {
        $conn = get_lc_db();
        
        // Plan SC-03: Validate order exists, status='shipped', store_id matches
        $st = $conn->prepare("SELECT id, status FROM lc_orders WHERE id=? AND store_id=?");
        $st->bind_param('ii', $order_id, $store_id);
        $st->execute();
        $order = $st->get_result()->fetch_assoc();
        $st->close();
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found or does not belong to this store']);
            exit;
        }
        
        // Plan SC-06: Idempotency — if already delivered, return success
        if ($order['status'] === 'delivered') {
            echo json_encode(['success' => true, 'message' => 'Already confirmed']);
            exit;
        }
        
        if ($order['status'] !== 'shipped') {
            echo json_encode(['success' => false, 'message' => 'Order is not in shipped status']);
            exit;
        }
        
        // Plan SC-04,05: Update status to delivered, set delivered_at timestamp
        $upd = $conn->prepare("UPDATE lc_orders SET status='delivered', delivered_at=NOW() WHERE id=? AND store_id=? AND status='shipped'");
        $upd->bind_param('ii', $order_id, $store_id);
        $upd->execute();
        $upd->close();
        
        $conn->close();
        
        echo json_encode(['success' => true, 'message' => 'Delivery confirmed']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
```

### 11.3 Module-3: Logistics Order Detail View

**Modify**: `/logistics/order_detail.php`

**Change**: Add delivered_at display after status section
```php
// Show delivered_at if order is delivered
if ($order['status'] === 'delivered' && $order['delivered_at']) {
    echo '<p class="text-sm text-green-700 font-medium">';
    echo '<i class="fas fa-check-circle mr-1"></i>배송 확인: ' . htmlspecialchars($order['delivered_at']);
    echo '</p>';
}
```

### 11.4 Module-2: Store Orders Page

**Modify**: `/store/orders.php`

**Sections**:
1. **Query incoming orders**: `SELECT * FROM lc_orders WHERE status='shipped' AND store_id=? ORDER BY order_date DESC`
2. **Render tabs**: "Incoming Shipments" | "Order History"
3. **Render incoming cards**: Order ID, shipped date, product list, buttons
4. **Add confirm button handler** (JavaScript): POST to `/ajax/order_confirm_delivery.php` with order_id, store_id, csrf_token

**Outline**:
```html
<!-- Incoming Shipments Section -->
<?php if (!empty($incoming_orders)): ?>
<div class="mb-6">
    <h3 class="text-lg font-bold mb-3">배송 대기 중</h3>
    <?php foreach ($incoming_orders as $order): ?>
    <div class="order-card p-4 border border-teal-200 rounded-lg mb-3">
        <p class="font-medium">Order #<?php echo $order['id']; ?></p>
        <p class="text-sm text-gray-500">Shipped: <?php echo $order['shipped_at']; ?></p>
        <ul class="mt-2">
        <?php foreach ($order['items'] as $item): ?>
            <li class="text-sm">- <?php echo $item['product_name']; ?> x <?php echo $item['quantity']; ?></li>
        <?php endforeach; ?>
        </ul>
        <button class="mt-3 px-4 py-2 bg-teal-600 text-white rounded confirm-btn" 
                data-order-id="<?php echo $order['id']; ?>">
            수령했습니다
        </button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.confirm-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        var orderId = this.dataset.orderId;
        var btn = this;
        btn.disabled = true;
        
        fetch('/store/ajax/order_confirm_delivery.php', {
            method: 'POST',
            body: new FormData({{ order_id: orderId, store_id: <?php echo $_SESSION['store_id']; ?>, csrf_token: '<?php echo lc_csrf_token(); ?>' }})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.closest('.order-card').style.display = 'none';
                alert('배송 확인이 완료되었습니다');
            } else {
                alert('Error: ' + data.message);
                btn.disabled = false;
            }
        })
        .catch(e => {
            alert('Network error');
            btn.disabled = false;
        });
    });
});
</script>
```

### 11.5 Module-4: Testing

**Test Cases**:
1. API: Valid confirmation → status updates
2. API: Idempotent retry → same response
3. API: CSRF validation → 403
4. UI: Render incoming section → correct orders shown
5. UI: Confirm button → POST sent, order removed
6. E2E: Full flow → delivery confirmed in logistics

---

## 12. Success Metrics

- ✅ **Latency**: Delivery confirmation API responds in <500ms
- ✅ **Correctness**: 100% of confirmations update lc_orders.status to 'delivered'
- ✅ **Idempotency**: 2nd confirm of same order returns success without duplicate update
- ✅ **Security**: CSRF validation prevents unauthorized confirmations
- ✅ **UX**: Store staff can confirm delivery in <5 seconds (button click + toast)

---

## Next Steps

→ **Do Phase**: `/pdca do branch-delivery-confirmation`
- Implement Module-1 (backend API)
- Implement Module-3 (logistics view)
- Implement Module-2 (store UI)
- Run Module-4 (testing)
- Optional: Add more comprehensive audit logging or notification system
