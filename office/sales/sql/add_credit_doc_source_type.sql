-- Design Ref: §5.2 / module-3 — POS 셀 §4(Subsidiary Company Credits) 거래명세서(credit_transactions) 선택 지원
-- sales_pos_wholesale_pick.source_type 에 'credit_doc' 추가.
--   'credit_doc' = admin 외상 거래명세서(credit_transactions) 참조. POS 셀 매출에는 포함하되
--   외상거래 현황(admin/credit_transactions.php)의 POS외상 합계(source_type='credit')에서는 제외되어
--   거래명세서 금액이 이중 집계되지 않는다.
ALTER TABLE `sales_pos_wholesale_pick`
  MODIFY COLUMN `source_type`
  ENUM('wholesale','delivery_k','credit','credit_doc') NOT NULL;
