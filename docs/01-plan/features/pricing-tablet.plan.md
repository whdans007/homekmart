# Plan: pricing-tablet

## Executive Summary

| Perspective | Detail |
|---|---|
| Problem | Staff need a fast, touch-friendly price lookup on tablets without Korean text or edit controls |
| Solution | Dedicated tablet UI at `/pricing-tablet/` — English-only, read-only, large price display |
| Functional UX Effect | Scan or type SKU → instant price result fullscreen, no distractions |
| Core Value | Reduces lookup friction on the shop floor; no accidental price edits |

## Context Anchor

| Key | Value |
|---|---|
| WHY | Tablets on the shop floor need a simple price checker without edit access |
| WHO | Store staff using tablets (kimsmall store 1) |
| RISK | Must reuse existing DB/search logic to avoid drift from main pricing tool |
| SUCCESS | SKU search returns correct price within 1 second; no Korean text; no edit controls |
| SCOPE | New folder `pricing-tablet/` — read-only lookup only, store fixed to store 1 |

## 1. Requirements

### Functional
- FR-01: Search by SKU/barcode (exact match) via text input
- FR-02: Autocomplete suggestions while typing (name_en, SKU)
- FR-03: Display product name (EN), SKU, and selling price on result
- FR-04: Store fixed to kimsmall store 1 (store_id = 1), no store switcher
- FR-05: English-only UI — no Korean text anywhere
- FR-06: Read-only — no price editing, no label printing

### Non-Functional
- NFR-01: Tablet-optimized — large touch targets, readable font sizes
- NFR-02: Dark theme consistent with existing pricing tool
- NFR-03: No authentication required (same as existing pricing tool)
- NFR-04: Reuse `pricing/ajax_search.php` and `pricing/ajax_suggest.php` query logic

## 2. Scope

**In scope**
- `pricing-tablet/index.php` — main tablet UI
- `pricing-tablet/ajax_search.php` — SKU lookup (EN fields, store 1)
- `pricing-tablet/ajax_suggest.php` — autocomplete (EN fields, store 1)

**Out of scope**
- Price editing
- Label printing
- Language toggle
- Store switching UI
- Authentication

## 3. Success Criteria
- SC-01: Searching a valid SKU displays product name, SKU, and selling price
- SC-02: Autocomplete shows EN product names matching the typed query
- SC-03: No Korean text visible in any UI state
- SC-04: All interactive elements are comfortably tappable on a 10" tablet
