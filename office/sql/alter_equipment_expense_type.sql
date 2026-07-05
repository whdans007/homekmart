ALTER TABLE office_equipment_purchases
  ADD COLUMN expense_type ENUM('consumable','other_expense') NOT NULL DEFAULT 'consumable' AFTER delivery_content,
  ADD COLUMN expense_category VARCHAR(50) NULL AFTER expense_type;
