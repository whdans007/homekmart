# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based retail management system called "HOME K MART" for managing stores, users, products, and inventory. It uses MySQL for data storage and TailwindCSS for frontend styling.

## Architecture

- **Backend**: PHP with MySQLi/PDO database connections (dual database access patterns)
- **Frontend**: PHP templates with TailwindCSS and FontAwesome icons
- **Database**: MySQL with UTF-8 encoding (database name: `u622428657_homekmart`)
- **CSS Build System**: PostCSS with TailwindCSS compilation
- **Permission System**: Granular role-based access control with JSON-based permissions
- **Margin Management**: Dynamic category-based pricing with margin calculation helpers
- **Excel Processing**: PhpSpreadsheet integration for bulk product uploads and data import

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
- PHP syntax checking: `PATH="/c/xampp/php:$PATH" php -l filename.php`
- No specific PHP linting/testing commands defined in package.json

## Database Architecture

### Connection Patterns
- **MySQLi**: `get_db_connection()` function in `config/db_config.php`
- **PDO**: Direct instantiation in helper libraries for advanced features
- **Database**: `u622428657_homekmart` with charset `utf8mb4`
- **Credentials**: Configured via constants in `config/db_config.php`

### Key Helper Libraries
- `lib/permission_helper.php` - Advanced role-based permission management
- `lib/margin_helper.php` - Category-based margin calculation and pricing
- `lib/session_helper.php` - Session management utilities
- `lib/lang_helper.php` - Multilingual support (Korean/English)

### Data Flow Architecture
- **Store-Centric Design**: Each user belongs to a store, data operations are store-scoped
- **Inventory Management**: `products` table for catalog, `inventory` table for store-specific pricing/quantities
- **Purchase Tracking**: Purchase records link to specific stores and update inventory automatically
- **Excel Integration**: Bulk import processes validate and insert data into both products and inventory tables

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
- `wholesale_management` - Wholesale operations
- `store_transfer_management` - Store transfer operations
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
- `admin/` - Administrative interface files (main application)
- `admin/partials/` - Reusable template components (header.php, footer.php)
- `src/` - Source files for compilation (CSS)
- `vendor/` - PhpSpreadsheet and other Composer dependencies

### Management Modules
- **User operations**: `*_user.php` files
- **Store operations**: `*_store.php` files  
- **Product operations**: `*_product.php` files (including bulk upload and Excel processing)
- **Purchase operations**: `*_purchase.php` files
- **AJAX endpoints**: `ajax_*.php` files for dynamic functionality
- **Specialized tools**: `excel_test.php` for Excel import testing and validation

### Template Architecture
- All pages use `admin/partials/header.php` which includes authentication, store context, and navigation
- Navigation is permission-aware and adapts based on user role
- Store information is automatically loaded and available in `$current_store_name` and `$current_store_id`

## Key Conventions

### Database Access
- Use `get_db_connection()` for simple MySQLi operations
- Use PDO with proper error handling for complex queries and transactions
- Always set charset to utf8mb4 for proper Korean character support
- Transaction handling: Use `autocommit(false)` + `commit()`/`rollback()` for data integrity

### Error Handling
- Database errors logged via `error_log()`
- User-facing errors stored in session flash messages
- Graceful fallbacks for permission and margin calculation failures
- Comprehensive validation with detailed error messages for Excel imports

### Security Patterns
- All database queries use prepared statements
- Permission checks before sensitive operations using `has_permission()` and `require_permission()`
- Session-based authentication with role verification
- Store-scoped data access (users can only access their assigned store data unless super_admin)

### Excel/Data Import Patterns
- PhpSpreadsheet library integration for Excel file processing
- Validation-first approach: validate all data before database transactions
- Partial success handling: commit valid data even if some rows fail
- Real-time feedback with progress tracking and detailed error reporting

## Korean Language

This application is primarily in Korean (한국어) with Korean comments and UI text. All user-facing content, error messages, and administrative interfaces use Korean language.

## TailwindCSS Integration

- Custom build process with PostCSS
- Extensive safelist in `tailwind.config.js` for dynamic classes
- Primary color palette customized for brand consistency
- FontAwesome icons integrated for UI elements
- Responsive design patterns with mobile-first approach

## HOME K MART 관리 프로그램 개발 지침

### 🖥️ 서버 환경
- Synology NAS Web Station
- PHP 8.2
- MariaDB 10

### 🔧 개발 원칙
- 모든 응답과 설명은 **한글로 진행**
- 헤더의 권한/점포 정보 적극 활용
- 계획 수립 시 `--think-hard` 플래그 적용
- 새 기능 개발 전 반드시 **데이터베이스 테이블 구조 확인**

### 💰 데이터 표시 규칙
- 원가 단위: **소숫점 둘째자리**까지 표시
- 합계 금액: **소숫점 둘째자리**까지 표시
- 화폐단위는 요청시에만 사용

### 📄 파일 관리
- 새로운 SQL 쿼리 발생 시 별도 파일 생성
- 언어 다중화 지원 (한국어, 영어)

### 🌐 개발 URL
- https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/admin/