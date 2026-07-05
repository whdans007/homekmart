ALTER TABLE sales_transfers
  ADD COLUMN file_path VARCHAR(500) NULL AFTER notes,
  ADD COLUMN file_original_name VARCHAR(255) NULL AFTER file_path,
  ADD COLUMN file_mime VARCHAR(100) NULL AFTER file_original_name;
