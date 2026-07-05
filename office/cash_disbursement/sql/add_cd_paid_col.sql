-- CD 일괄결제 처리 여부 컬럼 추가
ALTER TABLE office_product_purchases
  ADD COLUMN IF NOT EXISTS is_cd_paid TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'CD 일괄결제 처리 여부 (0=미결제, 1=결제완료)';

ALTER TABLE office_product_purchases
  ADD INDEX IF NOT EXISTS idx_cd_paid (store_id, is_cd_paid, payment_date);
