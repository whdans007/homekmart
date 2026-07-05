---
feature: inbound-items-list
phase: plan
status: active
created_at: 2026-06-11
version: 1.0
---

# Inbound Items List - Plan Document

## Executive Summary

| Perspective | Description |
|-------------|-------------|
| **Problem** | Currently, users can only search inbound batches at the batch level (by supplier/date), making it impossible to find specific items within inbound shipments quickly. Users need granular visibility into individual items across all inbound batches. |
| **Solution** | Create a dedicated "Inbound Items List" page that displays all inbound items with comprehensive filtering and search capabilities (by supplier, product/barcode, date range). |
| **Function & UX Effect** | New menu page with searchable item table showing: supplier name, barcode, product name, cost price, quantity, and inbound date. Users can quickly locate items and navigate to batch details. |
| **Core Value** | Enable efficient item-level inventory management, reduce search time from 5+ minutes to seconds, and improve warehouse operational visibility. |

## Context Anchor

| Element | Description |
|---------|-------------|
| **WHY** | Logistics warehouse staff need fast, granular access to inbound item data for inventory verification, supplier tracking, and item-level management. |
| **WHO** | Warehouse staff, logistics coordinators, inventory managers using the Logistics Center. |
| **RISK** | Complex filtering logic with large datasets (100K+ items) may cause performance issues; poor UX could reduce adoption. |
| **SUCCESS** | Item search < 2 seconds; all filter combinations working correctly; no performance degradation with 100K items. |
| **SCOPE** | New page only; integration with existing `inbound.php` and `inbound_detail.php`; no database schema changes. |

---

## 1. Requirements

### 1.1 Functional Requirements

#### FR-1: Item Search & Display Page
- Create new page: `logistics/inbound_items.php`
- Display all inbound items in a searchable, filterable table
- Columns (in order): Supplier Name, Barcode, Product Name, Cost Price, Quantity, Inbound Date
- Default sort: **Reverse chronological (newest first)**
- Pagination: 20 items per page

#### FR-2: Search Filters
- **Supplier Name Filter**: Text input, case-insensitive LIKE search
- **Product/Barcode Filter**: Text input, searches both product name and barcode
- **Date Range Filter**: Start date and end date inputs (optional range)
- All filters work in combination (AND logic)

#### FR-3: Item Details Link
- Each item row clickable → navigate to batch detail page (`inbound_detail.php?batch_id=X`)
- Batch detail shows all items from that inbound batch
- Alternative: Quick view modal showing batch items (future enhancement)

#### FR-4: Menu Integration
- Add "Inbound Items" link to logistics module menu (e.g., in header or sidebar)
- Ensure navigation consistency with existing logistics pages

#### FR-5: Responsive Design
- Use existing Tailwind CSS styling
- Mobile-friendly table (scrollable on small screens)
- Match visual design of `inbound.php` and other logistics pages

### 1.2 Non-Functional Requirements

| Requirement | Target | Rationale |
|-------------|--------|-----------|
| **Performance** | Search/filter response < 2 seconds | Large dataset handling (100K+ items) |
| **Browser Compatibility** | Chrome, Firefox, Safari (latest) | Standard web app support |
| **Accessibility** | WCAG 2.1 AA | Logistics staff may use on various devices |
| **Maintainability** | Code follows existing PHP patterns | Consistency with current codebase |

---

## 2. Scope

### 2.1 In Scope
- ✅ New `inbound_items.php` page with search/filter UI
- ✅ Database query to fetch items from `lc_inbound` table
- ✅ Three filter types: supplier, product/barcode, date range
- ✅ Pagination (20 items/page)
- ✅ Navigation menu link addition
- ✅ Responsive styling

### 2.2 Out of Scope
- ❌ Batch detail page overhaul (minor addition only)
- ❌ Export/Download functionality (future FR)
- ❌ Real-time item tracking/history
- ❌ Barcode scanning integration
- ❌ New database tables or schema changes

---

## 3. Success Criteria

| Criterion | Definition | Acceptance Test |
|-----------|-----------|-----------------|
| **SC-1: All Columns Display Correctly** | 6 columns (supplier, barcode, product, price, qty, date) display in correct order | Manual verification: row inspection |
| **SC-2: Reverse Chronological Sort** | Items sorted newest to oldest by inbound date | Verify first and last rows match date range |
| **SC-3: Supplier Filter Works** | Searching "JK" shows only JK supplier items | Test with 2-3 suppliers |
| **SC-4: Product/Barcode Filter Works** | Searching product name or barcode returns matching items | Test with known barcode, partial name |
| **SC-5: Date Range Filter Works** | Date picker filters items within selected range | Test with overlapping date ranges |
| **SC-6: Combined Filters Work** | All 3 filters can be used together (AND logic) | Apply supplier + product + date range |
| **SC-7: Pagination Works** | Page navigation shows correct subset of items; total count accurate | Navigate through multiple pages |
| **SC-8: Item Clickable** | Clicking row navigates to `inbound_detail.php?batch_id=X` | Click 3+ items, verify batch detail loads |
| **SC-9: Menu Link Added** | "Inbound Items" link appears in logistics module navigation | Visual inspection of header/menu |
| **SC-10: Performance < 2s** | Search/filter completes in < 2 seconds (100K dataset) | Load test with prepared dataset |

---

## 4. Technical Constraints

| Constraint | Impact | Mitigation |
|-----------|--------|-----------|
| **Legacy PHP/MySQL** | Must use procedural PHP + MySQLi | Follow existing code patterns in `inbound.php` |
| **No Modern ORM** | Raw SQL queries required | Use prepared statements for security |
| **Tailwind CSS Only** | No Bootstrap or other frameworks | Match existing styling from `inbound.php` |
| **Session-based Auth** | Must call `lc_require_staff()` | Inherit from existing logistics pages |
| **DB Connection Pattern** | Use `get_lc_db()` function | Consistent with codebase |

---

## 5. Dependencies

### 5.1 Internal Dependencies
- **`logistics/partials/header.php`**: Navigation header/menu
- **`logistics/config/db.php`**: Database connection function `get_lc_db()`
- **`lc_inbound` table**: Existing database table (no changes)
- **`lc_inbound_batches` table**: For batch ID linking
- **`suppliers` table**: Supplier name data
- **Tailwind CSS**: Styling framework (already available)

### 5.2 External Dependencies
- **None** (standard PHP/MySQL/Tailwind stack)

### 5.3 Assumptions
- Staff authentication via `lc_require_staff()` is functional
- Database has representative inbound items (100+ for testing)
- `inbound_detail.php` page already exists

---

## 6. Risk Analysis

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|-----------|
| **Large dataset slow query** | Medium | High | Use INDEX on (supplier_id, inbound_date, barcode); implement LIMIT + OFFSET pagination |
| **SQL Injection via filters** | Low | Critical | Use prepared statements with parameterized queries (bind_param) |
| **Incorrect filter logic** | Medium | Medium | Write unit tests for filter combinations; manual UAT |
| **UX confusion** | Low | Medium | Follow existing `inbound.php` UX patterns; provide clear filter labels |
| **Menu navigation broken** | Low | Medium | Test all navigation links after deployment |
| **Date filter off-by-one errors** | Medium | Low | Test with date ranges at month/year boundaries |

---

## 7. Implementation Approach

### 7.1 Architecture Decision: Minimal Changes
**Selected: Option B (Clean Architecture)**
- New dedicated page (`inbound_items.php`) — separation of concerns
- Reuse existing `inbound.php` query patterns for consistency
- No changes to existing pages (safer, lower risk)
- Database queries follow MySQLi prepared statement pattern

### 7.2 File Structure
```
logistics/
├── inbound.php                    (existing, unchanged)
├── inbound_detail.php             (existing, unchanged)
├── inbound_items.php              (NEW)
├── partials/header.php            (existing, add menu link)
├── config/db.php                  (existing, unchanged)
└── ...
```

### 7.3 Database Queries
1. **Fetch items with filters**: LEFT JOIN `lc_inbound` + `lc_inbound_batches` + `suppliers`
2. **Count total items**: SELECT COUNT(*) with same WHERE clause
3. **Pagination**: LIMIT + OFFSET

---

## 8. Estimated Effort

| Task | Estimated Hours | Notes |
|------|-----------------|-------|
| UI/HTML + CSS | 2 | Based on existing `inbound.php` |
| Database queries + filtering logic | 3 | Prepared statements, WHERE clause complexity |
| Search/filter integration | 2 | JavaScript form handling, URL params |
| Testing (manual + UAT) | 2 | Verify all filter combinations |
| **Total** | **9 hours** | ~1-1.5 days development |

---

## 9. Success Metrics (Post-Launch)

- Page load time (target: < 2s with 100K items)
- Filter response time (target: < 1s)
- User adoption (target: 80%+ of warehouse staff within 2 weeks)
- Support tickets related to item search (target: < 1 per week)

---

## 10. Next Steps

1. **✅ Checkpoint 1 & 2 Complete**: Requirements and clarifying questions confirmed
2. **→ Next Phase**: `/pdca design inbound-items-list` — Create detailed design spec
3. **Design outputs**: 
   - Database query patterns
   - UI/UX mockup
   - Filter logic flowchart
   - Session guide for implementation

---

## Appendix: User Request Summary

**Original Request (Korean)**:
> "업체별로 인바운드된 내용은 검색이 안되잖아. 인바운드된 모든 아이템을 인풋된 역순으로 보여주는 메뉴를 만들어 주면 좋을꺼 같아. 예를 들면 jk 업체에 바코드가 0000인 상품명, 얼마에 몇개가 들어 왔는지 말이야 그리고 그날 들어온 모든 상품을 볼수 있는 리스트로 바로 갈수 있게끔 말이야."

**Translation & Interpretation**:
- Create "Inbound Items List" menu
- Show all inbound items in reverse chronological order
- Filter by supplier (e.g., JK), barcode, product name
- Display: product name, barcode, cost price, quantity
- Navigate to batch detail to see all items from that date

**Confirmed Scope**: New `inbound_items.php` page with three-filter search system.
