# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based retail management system called "HOME K MART" for managing stores, users, products, and inventory. It uses MySQL for data storage and TailwindCSS for frontend styling.

## Architecture

- **Backend**: PHP with MySQLi/PDO database connections
- **Frontend**: PHP templates with TailwindCSS and FontAwesome icons
- **Database**: MySQL with UTF-8 encoding (database name: `min`)
- **CSS Build System**: PostCSS with TailwindCSS compilation

## Key Components

- `config/db_config.php` - Database configuration and connection helper
- `lib/session_helper.php` - Session management utilities
- `public/` - All web-accessible files including PHP pages and assets
- `public/partials/` - Reusable header/footer templates
- `src/input.css` - TailwindCSS source file

## Development Commands

### CSS Development
```bash
# Build CSS once
npm run build:css

# Watch CSS for changes during development
npm run watch:css
```

## Database Connection

The application connects to MySQL using:
- Host: localhost
- Database: min
- Charset: utf8mb4
- Connection helper: `get_db_connection()` function in `config/db_config.php`

## User Roles

The system has role-based access control with roles including:
- `super_admin` - Full system access
- `admin` - Store management access
- Regular users with store assignments

## File Structure

- User management: `add_user.php`, `edit_user.php`, `user_management.php`
- Store management: `add_store.php`, `edit_store.php`, `store_management.php`
- Product management: `add_product.php`, `edit_product.php`, `product_management.php`
- Brand/Category management: `brand_management.php`, `category_management.php`
- Purchase tracking: `purchase_management.php`

## Korean Language

This application is primarily in Korean (한국어) with Korean comments and UI text.