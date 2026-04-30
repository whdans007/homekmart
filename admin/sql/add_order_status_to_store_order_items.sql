-- Migration: Replace remarks with order_status in store_order_list_items
-- Created: 2026-03-25

-- Add order_status column
ALTER TABLE store_order_list_items
ADD COLUMN order_status ENUM('주문', '비주문') DEFAULT '주문' AFTER quantity;

-- Drop remarks column if it exists
ALTER TABLE store_order_list_items
DROP COLUMN IF EXISTS remarks;

-- Add index for efficient queries
ALTER TABLE store_order_list_items
ADD INDEX idx_order_status (order_status);
