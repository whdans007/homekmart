# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based retail management system called "HOME K MART" for managing stores, users, products, and inventory. It uses MySQL for data storage and TailwindCSS for frontend styling.

## Architecture

- **Backend**: PHP with MySQLi/PDO database connections (dual database access patterns)
- **Frontend**: PHP templates with TailwindCSS and FontAwesome icons
- **Database**: MySQL with UTF-8 encoding (database name: `min`)
- **CSS Build System**: PostCSS with TailwindCSS compilation
- **Permission System**: Granular role-based access control with JSON-based permissions
- **Margin Management**: Dynamic category-based pricing with margin calculation helpers

## Development Commands

### CSS Development
```bash
# Build CSS once
npm run build:css

# Watch CSS for changes during development
npm run watch:css
```

### PHP Development
- Use XAMPP or similar local server environment
- Database connection available via `get_db_connection()` in `config/db_config.php`
- No specific PHP linting/testing commands defined in package.json

## Database Architecture

### Connection Patterns
- **MySQLi**: `get_db_connection()` function in `config/db_config.php`
- **PDO**: Direct instantiation in helper libraries for advanced features
- **Database**: `min` with charset `utf8mb4`
- **Credentials**: Configured via constants in `config/db_config.php`

### Key Helper Libraries
- `lib/permission_helper.php` - Advanced role-based permission management
- `lib/margin_helper.php` - Category-based margin calculation and pricing
- `lib/session_helper.php` - Session management utilities

## Permission System Architecture

The system uses a sophisticated dual-layer permission model:

### Permission Layers
1. **Legacy Role-Based**: Traditional role checking for backward compatibility
2. **Granular JSON Permissions**: Per-user customizable permissions stored as JSON

### Available Permissions
- `admin_access` - Administrative interface access
- `user_management` - User creation/modification
- `store_management` - Store operations (super_admin only)
- `product_management` - Product catalog management
- `purchase_management` - Purchase/inventory operations
- `brand_management` - Brand catalog management
- `category_management` - Category management
- `supplier_management` - Supplier relationship management
- `settings` - System configuration (super_admin only)
- `shop_access` - POS/shopping interface access
- `barcode_management` - Barcode operations
- `accounting_management` - Financial operations

### User Roles Hierarchy
- `super_admin` - Full system access, all permissions
- `admin` - Most management functions except store/settings
- `staff` - Shop access and barcode management only
- `office_staff` - Shop access, barcode, and accounting management
- `user` - Basic shop access only

### Permission Usage Pattern
```php
// Check specific permission
if (has_permission('product_management')) {
    // Allow product operations
}

// Require permission (redirects if denied)
require_permission('admin_access', 'login.php');

// Get all user permissions
$permissions = get_user_permissions($user_id);
```

## Margin Management System

Dynamic pricing system with category-based margin rules:

### Core Functions
- `get_margin_rate_by_product($product_id)` - Retrieve margin for specific product
- `get_margin_rate_by_category($category_id)` - Get category-specific margin
- `calculate_suggested_price($cost_price, $margin_rate)` - Price calculation
- `calculate_margin_rate($selling_price, $cost_price)` - Reverse calculation

### Default Behavior
- Default margin rate: 30% if no category rule exists
- Margin rules stored in `margin_rules` table linking categories to percentages
- Graceful fallback to defaults on database errors

## File Organization

### Core Structure
- `config/` - Database and system configuration
- `lib/` - Reusable helper libraries and utilities
- `public/` - Web-accessible files (document root)
- `public/partials/` - Reusable template components
- `src/` - Source files for compilation (CSS)
- `sql/` - Database migration scripts

### Management Modules
- User operations: `*_user.php` files
- Store operations: `*_store.php` files  
- Product operations: `*_product.php` files
- Purchase operations: `*_purchase.php` files
- AJAX endpoints: `ajax_*.php` files

## Key Conventions

### Database Access
- Use `get_db_connection()` for simple MySQLi operations
- Use PDO with proper error handling for complex queries and transactions
- Always set charset to utf8mb4 for proper Korean character support

### Error Handling
- Database errors logged via `error_log()`
- User-facing errors stored in session flash messages
- Graceful fallbacks for permission and margin calculation failures

### Security Patterns
- All database queries use prepared statements
- Permission checks before sensitive operations
- Session-based authentication with role verification

## Korean Language

This application is primarily in Korean (한국어) with Korean comments and UI text. All user-facing content, error messages, and administrative interfaces use Korean language.