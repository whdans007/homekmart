-- Design Ref: §5.2 / module-3 — POS 셀 §5(Whole Sale) 수기 입력 지원
-- sales_pos_wholesale_pick.source_type 에 'wholesale_manual' 추가.
--   'wholesale_manual' = 그날 admin이 등록하지 않은 Whole Sale 매출을 POS 셀에서 직접 입력.
--   거래처는 wholesale_customers 검색으로 선택하며, source_id는 실제 wholesale_sales.id 를
--   참조하지 않는 셀 내부 고유값(음수)이다. 집계 시 'wholesale'과 동일하게 취급한다.
ALTER TABLE `sales_pos_wholesale_pick`
  MODIFY COLUMN `source_type`
  ENUM('wholesale','delivery_k','credit','credit_doc','wholesale_manual') NOT NULL;
