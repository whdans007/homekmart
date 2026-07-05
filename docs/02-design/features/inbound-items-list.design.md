---
feature: inbound-items-list
phase: design
status: active
created_at: 2026-06-11
version: 1.0
architecture: option-c-pragmatic-balance
---

# Inbound Items List - Design Document

## Context Anchor

| Element | Description |
|---------|-------------|
| **WHY** | Logistics warehouse staff need fast, granular access to inbound item data for inventory verification, supplier tracking, and item-level management. |
| **WHO** | Warehouse staff, logistics coordinators, inventory managers using the Logistics Center. |
| **RISK** | Complex filtering logic with large datasets (100K+ items) may cause performance issues; poor UX could reduce adoption. |
| **SUCCESS** | Item search < 2 seconds; all filter combinations working correctly; no performance degradation with 100K items. |
| **SCOPE** | New page only; integration with existing `inbound.php` and `inbound_detail.php`; no database schema changes. |

---

## 1. Overview

### 1.1 Design Approach: Option C (Pragmatic Balance)
- **Strategy**: Reuse existing inbound.php code patterns, extract common filter logic into a helper function
- **Philosophy**: Minimal new files, maximum code pattern consistency
- **Key Decision**: One shared helper function (`getFilteredInboundItems()`) in `lib/inbound_helper.php`, then use in both `inbound.php` (if refactored) and `inbound_items.php`
- **Alternative Path**: Can be refactored to Option B later without breaking Option C

### 1.2 File Structure
```
logistics/
├── inbound.php                     (existing, unchanged in this phase)
├── inbound_items.php               (NEW - main feature page)
├── inbound_detail.php              (existing, unchanged)
├── config/db.php                   (existing, unchanged)
├── lib/inbound_helper.php          (NEW - shared helper functions)
├── partials/header.php             (existing, add menu link)
└── ...
```

### 1.3 Technology Stack
- **Language**: PHP 7.4+ (procedural style, matching project)
- **Database**: MySQL / MySQLi (prepared statements)
- **Frontend**: Vanilla JavaScript + Tailwind CSS
- **Session**: `lc_require_staff()` for authentication

---

## 2. Architecture Overview

### 2.1 Request Flow Diagram
```
User Access: /logistics/inbound_items.php
    ↓
lc_require_staff() → Authentication check
    ↓
Parse filters from GET params (supplier, product, date_from, date_to)
    ↓
Call getFilteredInboundItems($filters, $page)  ← Helper function
    ↓
Execute parameterized MySQL query (with prepared statements)
    ↓
Render table + pagination
    ↓
Display filters + results
```

### 2.2 Helper Function: `getFilteredInboundItems()`
**Location**: `lib/inbound_helper.php`

```php
/**
 * Get filtered inbound items with pagination
 * 
 * @param array $filters - ['supplier' => '', 'product_or_barcode' => '', 'date_from' => '', 'date_to' => '']
 * @param int $page - Page number (1-indexed)
 * @param int $limit - Items per page (default 20)
 * @return array - ['items' => [...], 'total' => N, 'page' => N, 'total_pages' => N]
 */
function getFilteredInboundItems($filters = [], $page = 1, $limit = 20)
```

**Responsibilities**:
1. Build WHERE clause from filters (supplier, product/barcode, date range)
2. Execute COUNT(*) query for pagination
3. Execute SELECT query with LIMIT/OFFSET
4. Return structured result array

**Reusability**: Can be used in multiple pages (inbound.php, reports, etc.)

---

## 3. Data Model

### 3.1 Related Tables
```
lc_inbound_batches (inbound batch records)
├── id (PK)
├── supplier_id (FK → suppliers.id)
├── inbound_date (DATE)
├── created_at (DATETIME)
├── notes
└── is_confirmed (BOOLEAN, v6+)

lc_inbound (individual items in batch)
├── id (PK)
├── batch_id (FK → lc_inbound_batches.id)
├── product_id (FK → products.id)
├── quantity (INT)
├── cost_price (DECIMAL(10,2))
└── ...

products (product master data)
├── id (PK)
├── name (VARCHAR)
├── barcode (VARCHAR)
└── ...

suppliers (supplier master data)
├── id (PK)
├── name (VARCHAR)
└── ...
```

### 3.2 View Model (Display Data)
```php
$item = [
    'id'              => 123,           // lc_inbound.id
    'batch_id'        => 456,           // lc_inbound.batch_id
    'supplier_name'   => 'JK Supply',   // suppliers.name
    'barcode'         => '0000',        // products.barcode
    'product_name'    => 'Item Name',   // products.name
    'cost_price'      => 1500.50,       // lc_inbound.cost_price
    'quantity'        => 10,            // lc_inbound.quantity
    'inbound_date'    => '2026-06-11'   // lc_inbound_batches.inbound_date
];
```

---

## 4. Database Queries

### 4.1 Main Query: Get Filtered Items
```sql
SELECT 
    i.id,
    i.batch_id,
    b.inbound_date,
    b.created_at,
    s.name AS supplier_name,
    p.barcode,
    p.name AS product_name,
    i.cost_price,
    i.quantity
FROM lc_inbound i
LEFT JOIN lc_inbound_batches b ON i.batch_id = b.id
LEFT JOIN suppliers s ON b.supplier_id = s.id
LEFT JOIN products p ON i.product_id = p.id
WHERE 1=1
  [AND s.name LIKE ?]          -- if supplier filter
  [AND (p.name LIKE ? OR p.barcode LIKE ?)]  -- if product/barcode filter
  [AND b.inbound_date >= ?]    -- if date_from filter
  [AND b.inbound_date <= ?]    -- if date_to filter
ORDER BY b.inbound_date DESC, i.id DESC
LIMIT ? OFFSET ?
```

### 4.2 Count Query: Total Items
```sql
SELECT COUNT(*) AS total
FROM lc_inbound i
LEFT JOIN lc_inbound_batches b ON i.batch_id = b.id
LEFT JOIN suppliers s ON b.supplier_id = s.id
LEFT JOIN products p ON i.product_id = p.id
WHERE 1=1
  [AND filters...]
```

### 4.3 Index Optimization
**Recommended indexes** (for performance):
```sql
CREATE INDEX idx_inbound_batch_id ON lc_inbound(batch_id);
CREATE INDEX idx_inbound_batches_supplier_date ON lc_inbound_batches(supplier_id, inbound_date);
CREATE INDEX idx_products_barcode ON products(barcode);
CREATE INDEX idx_suppliers_name ON suppliers(name);
```

---

## 5. UI/UX Design

### 5.1 Page Layout
```
┌─────────────────────────────────────────────────────┐
│  Header (Logistics Navigation)                      │
│  [Home] [Inbound] [Inbound Items] [...]             │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│  Page Title: "Inbound Items List"                   │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Search & Filter Panel:                             │
│  ┌─────────────────────────────────────────────┐   │
│  │ Supplier: [____________]  Product/Barcode:  │   │
│  │ [____________]                              │   │
│  │                                              │   │
│  │ Date From: [__________] Date To: [__________] │   │
│  │                                              │   │
│  │ [Search] [Reset] [Excel Download] [Print]   │   │
│  └─────────────────────────────────────────────┘   │
│                                                      │
├─────────────────────────────────────────────────────┤
│  Total 1,234 items found                            │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Scrollable Table:                                  │
│  ┌──┬──────────┬────────┬──────────┬─────┬────────┐ │
│  │# │ Supplier │Barcode │ Product  │Price│ Qty   │ │
│  ├──┼──────────┼────────┼──────────┼─────┼────────┤ │
│  │1 │JK Supply │0000    │Item A    │1500 │10     │ │
│  │2 │JK Supply │0001    │Item B    │2000 │5      │ │
│  │3 │...       │...     │...       │...  │...    │ │
│  └──┴──────────┴────────┴──────────┴─────┴────────┘ │
│                                                      │
│  Pagination:                                        │
│  [< Prev] [1] [2] [3] ... [10] [Next >]            │
│                                                      │
└─────────────────────────────────────────────────────┘
```

### 5.2 Table Columns (Exact Order)
| # | Column | Width | Align | Type |
|----|--------|-------|-------|------|
| 1 | Row # | 50px | Center | INT |
| 2 | Supplier Name | 150px | Left | TEXT |
| 3 | Barcode | 100px | Center | TEXT |
| 4 | Product Name | 200px | Left | TEXT |
| 5 | Cost Price | 100px | Right | DECIMAL |
| 6 | Quantity | 80px | Right | INT |
| 7 | Inbound Date | 120px | Center | DATE |

**Row Click Behavior**: Clicking any row → navigate to `/inbound_detail.php?batch_id={batch_id}`

### 5.3 CSS Classes (Tailwind)
- Container: `flex flex-col h-full gap-3 overflow-hidden`
- Table: `w-full text-sm` (matches inbound.php style)
- Header: `sticky top-0 z-10 bg-gray-50`
- Row: `hover:bg-gray-50 transition-colors cursor-pointer`
- Filter Panel: `bg-white rounded-lg border border-gray-200 px-3 py-2 shrink-0`

---

## 6. Filter Logic

### 6.1 Filter Types & Behavior

#### Filter 1: Supplier Name (Text Input)
- **Type**: Text input (case-insensitive)
- **SQL**: `s.name LIKE ?` with `%supplier%`
- **Validation**: Optional, max 100 chars
- **Example**: Input "JK" → shows all JK supplier items

#### Filter 2: Product/Barcode (Text Input)
- **Type**: Text input (searches both fields)
- **SQL**: `(p.name LIKE ? OR p.barcode LIKE ?)` with `%search%`
- **Validation**: Optional, max 50 chars
- **Example**: Input "0000" → shows barcode=0000 items; Input "Item" → shows product names containing "Item"

#### Filter 3: Date Range (Date Pickers)
- **Type**: Date input (HTML5 `<input type="date">`)
- **SQL**: 
  - `b.inbound_date >= ?` (if date_from provided)
  - `b.inbound_date <= ?` (if date_to provided)
- **Validation**: Optional, must be valid date format YYYY-MM-DD
- **Example**: From 2026-01-01 to 2026-06-30 → shows items within range

### 6.2 Filter Combination Logic (AND)
- All filters combined with AND logic
- Example: Supplier="JK" AND Product/Barcode="Item" AND Date>2026-06-01
- If no filters: show all items (reverse chronological)

### 6.3 Default Behavior
- **No Filters**: Show all inbound items (newest first)
- **Sort**: `ORDER BY b.inbound_date DESC, i.id DESC` (reverse chronological)
- **Pagination**: 20 items per page

---

## 7. Component Structure

### 7.1 Main Components
```
inbound_items.php (Main page)
├── Header (partials/header.php)
├── Filter Form
│   ├── Supplier Input
│   ├── Product/Barcode Input
│   ├── Date Range Picker
│   ├── Search Button
│   ├── Reset Button
│   └── Export/Print Buttons
├── Results Summary
├── Items Table
│   └── Pagination Controls
└── Footer

lib/inbound_helper.php
└── getFilteredInboundItems() function
```

### 7.2 JavaScript Functions
```javascript
// Form submission & filter
function submitFilters() { ... }  // GET request with filter params

// Reset filters
function resetFilters() { ... }   // Clear form & redirect

// Row click handler
function goToBatchDetail(batchId) { 
    window.location.href = `.../inbound_detail.php?batch_id=${batchId}`;
}

// Optional: Export to Excel
function exportToExcel() { ... }

// Optional: Print preview
function openPrintPreview() { ... }
```

---

## 8. Test Plan

### 8.1 Test Scenarios (L1: API/Database)

| Test | Input | Expected Output | Status |
|------|-------|-----------------|--------|
| **T1: Display All Items** | No filters | All items, reverse chronological order, 20/page | ✓ Manual |
| **T2: Filter by Supplier** | Supplier="JK" | Only JK items | ✓ Manual |
| **T3: Filter by Product** | Product/Barcode="Item" | Items matching name or barcode | ✓ Manual |
| **T4: Filter by Date Range** | From 2026-01-01, To 2026-06-30 | Items within range | ✓ Manual |
| **T5: Combined Filters** | Supplier="JK" + Product="Item" + Date range | Intersection of all filters | ✓ Manual |
| **T6: Pagination** | Page 2, 3 | Correct offset & limit | ✓ Manual |
| **T7: Performance** | 100K+ items, search/filter | Response < 2 seconds | ✓ Performance test |
| **T8: Row Click** | Click item row | Navigate to inbound_detail.php with batch_id | ✓ Manual |
| **T9: Case-Insensitive Search** | "jk", "JK", "Jk" | Same results | ✓ Manual |
| **T10: Empty Result** | Filters with no matches | "No items found" message | ✓ Manual |

### 8.2 Test Scenarios (L2: UI/UX)

| Test | Action | Expected | Status |
|------|--------|----------|--------|
| **T11: Filter Form Layout** | Load page | All filters visible, styled correctly | ✓ Visual |
| **T12: Table Columns** | Load page | 7 columns in correct order, proper widths | ✓ Visual |
| **T13: Menu Link** | Load header | "Inbound Items" link visible, clickable | ✓ Manual |
| **T14: Responsive Mobile** | Mobile viewport (320px) | Table scrollable, no horizontal overflow | ✓ Manual |
| **T15: Error Message** | DB connection error | User-friendly error displayed, not blank | ✓ Manual |

### 8.3 Success Criteria Mapping
- **SC-1**: All Columns Display → T12
- **SC-2**: Reverse Chronological Sort → T1
- **SC-3**: Supplier Filter Works → T2
- **SC-4**: Product/Barcode Filter Works → T3
- **SC-5**: Date Range Filter Works → T4
- **SC-6**: Combined Filters Work → T5
- **SC-7**: Pagination Works → T6
- **SC-8**: Item Clickable → T8
- **SC-9**: Menu Link Added → T13
- **SC-10**: Performance < 2s → T7

---

## 9. Error Handling

### 9.1 Database Connection Errors
```php
try {
    $conn = get_lc_db();
    // Query execution
} catch (Exception $e) {
    $db_error = $e->getMessage();
    // Display error message
}
```
**Display**: Show error in red banner (similar to inbound.php)

### 9.2 No Results Found
- Display: Centered message "No inbound items found" with icon
- Suggest: Reset filters or adjust search criteria

### 9.3 Invalid Filter Input
- **Validation**: Max length checks, date format validation
- **Handling**: Sanitize input using `trim()`, prepared statements prevent SQL injection
- **User Feedback**: Client-side validation with helpful messages

### 9.4 Performance Timeout
- **Mitigation**: Set MySQL query timeout (e.g., 5 seconds)
- **User Experience**: Display timeout message, suggest refining filters

---

## 10. Performance Considerations

### 10.1 Query Optimization
- **Use Indexes**: supplier_id, inbound_date, barcode on key columns
- **LIMIT/OFFSET**: Always paginate (20 items/page max)
- **Prepared Statements**: Use parameterized queries to avoid full table scans

### 10.2 Caching (Future)
- Not required for MVP, but consider caching supplier/product lists if performance degrades

### 10.3 Database Design
- Ensure foreign key indexes exist
- Monitor slow query log for optimization opportunities

### 10.4 Load Testing Target
- **100K items**: Query response < 1s (target < 2s)
- **Page load**: < 2s total including render

---

## 11. Implementation Guide

### 11.1 Implementation Order

#### Phase 1: Helper Function (0.5h)
1. Create `lib/inbound_helper.php`
2. Implement `getFilteredInboundItems($filters, $page, $limit)` function
3. Test with sample filters

#### Phase 2: Page Structure (1h)
1. Create `inbound_items.php`
2. Add authentication check: `lc_require_staff()`
3. Add page header & menu link in `partials/header.php`
4. Create filter form HTML (supplier, product, date range)

#### Phase 3: Styling & Layout (1h)
1. Add Tailwind CSS classes (match inbound.php style)
2. Create table structure with placeholder rows
3. Add pagination HTML
4. Test responsive design

#### Phase 4: Data Binding (1.5h)
1. Add PHP logic to parse GET parameters (supplier, product, date_from, date_to)
2. Call `getFilteredInboundItems()` function
3. Bind data to table rows
4. Implement pagination links

#### Phase 5: Filter Logic & Validation (1h)
1. Add input validation (trim, max length)
2. Implement case-insensitive search
3. Test filter combinations
4. Add "No results" handling

#### Phase 6: Testing & Refinement (1h)
1. Manual testing (all 10 test scenarios)
2. Performance testing with large dataset
3. Fix responsive design issues
4. Database optimization (indexes)

**Total Estimated Time**: 5.5-6 hours (slightly under 9 hour plan estimate, leaves buffer)

---

### 11.2 File Checklist

**New Files**:
- [ ] `lib/inbound_helper.php` — Helper function
- [ ] `logistics/inbound_items.php` — Main page

**Modified Files**:
- [ ] `logistics/partials/header.php` — Add menu link

**No Changes**:
- `config/db.php` — Use existing connection
- `inbound.php` — No changes (Phase 1)
- `inbound_detail.php` — No changes

---

### 11.3 Session Guide

**For Incremental Implementation** (if split across sessions):

#### Session 1: Foundation (1.5h)
- Create `lib/inbound_helper.php` with core query function
- Create `inbound_items.php` skeleton with auth + menu link
- Test helper function with sample data

**Deliverable**: Working helper function, basic page structure

---

#### Session 2: UI & Styling (1.5h)
- Implement filter form HTML + Tailwind CSS
- Build table structure
- Add pagination HTML
- Test responsive design

**Deliverable**: Visual layout complete, data binding ready

---

#### Session 3: Logic & Testing (1.5h)
- Parse filters from GET parameters
- Call helper function with filters
- Bind data to table
- Implement all test scenarios

**Deliverable**: Feature complete, all tests passing

---

**Recommended**: Single 5.5-6 hour session (for consistency), or split into 3 sessions of 1.5h each if session time is limited.

---

## 12. Success Metrics

### 12.1 Implementation Completion Criteria
- ✅ All 10 test scenarios passing
- ✅ Response time < 2 seconds (100K items)
- ✅ All Success Criteria (SC-1 to SC-10) met
- ✅ Code follows existing inbound.php patterns
- ✅ No SQL injection vulnerabilities
- ✅ Responsive on mobile (320px+)

### 12.2 Code Quality Standards
- Prepared statements for all SQL queries
- Input validation & sanitization
- Error handling with user-friendly messages
- Consistent naming conventions (camelCase for functions, snake_case for variables)
- Comments for complex filter logic

---

## 13. Architecture Decision Record

**Decision**: Option C — Pragmatic Balance

**Rationale**:
1. **Speed**: 5.5h development vs 4.5h (Option A) saves minimal time, worth cleaner code
2. **Maintainability**: Shared helper function prevents duplication, easier future updates
3. **Consistency**: Follows existing inbound.php procedural PHP patterns
4. **Flexibility**: Easy to migrate to Option B later without breaking changes
5. **Risk**: Minimal risk, zero new infrastructure required

**Trade-offs Accepted**:
- Not the absolute fastest approach (Option A 2-3h vs C 5.5h)
- Not the most scalable (Option B would be better for 10+ similar pages)
- Accepted because: Current scope is one page, team prefers pragmatic over complex

**Future Path**: If next feature (e.g., supplier items report) requires similar filtering, refactor shared code to Option B level.

---

## 14. Next Steps

1. ✅ Design phase complete
2. → **Do Phase**: `/pdca do inbound-items-list`
3. **Implementation** will follow this Design using Session Guide (§11.3)

---

## Appendix: Design vs Plan Decisions

| Aspect | Plan | Design | Notes |
|--------|------|--------|-------|
| Architecture | Option B suggested | **Option C selected** | Better match for project style |
| Helper Function | Not specified | `getFilteredInboundItems()` in lib/ | Enables code reuse |
| Pagination | 20 items/page | 20 items/page (confirmed) | ✓ Aligned |
| Filters | 3 types | Same 3 types + detailed logic | ✓ Aligned |
| Test Plan | Not specified | L1-L2 test scenarios defined | ✓ Enables implementation |
| Session Guide | Not specified | 3-session incremental plan | ✓ Practical for team |
