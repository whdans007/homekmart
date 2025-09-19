-- 사용하지 않는 products 테이블 컬럼 삭제 스크립트
-- 실행 전 데이터베이스 백업을 권장합니다

-- 1단계: 이벤트 관련 컬럼 삭제 (안전함 - 사용하지 않음)
ALTER TABLE products DROP COLUMN IF EXISTS event_price;
ALTER TABLE products DROP COLUMN IF EXISTS event_start_date;
ALTER TABLE products DROP COLUMN IF EXISTS event_end_date;

-- 2단계: wholesale_price 컬럼 삭제 (사용도 낮음)
ALTER TABLE products DROP COLUMN IF EXISTS wholesale_price;

-- 3단계: barcode 컬럼 삭제 (SKU로 대체됨)
-- 주의: 이 단계 실행 전에 모든 barcode 참조가 sku로 변경되었는지 확인하세요
ALTER TABLE products DROP COLUMN IF EXISTS barcode;

-- 4단계: margin_rate 컬럼 삭제 (inventory 테이블로 이동됨)
-- 주의: margin_helper.php에서 사용하지 않는다고 확인된 경우에만 실행
-- ALTER TABLE products DROP COLUMN IF EXISTS margin_rate;

-- 5단계: cost_price, selling_price 컬럼 삭제 (inventory 테이블로 이동됨)
-- 주의: 모든 상품이 inventory 테이블에 데이터가 있는지 확인 후 실행
-- 현재는 COALESCE(i.cost_price, p.cost_price) 패턴으로 fallback 사용 중
-- ALTER TABLE products DROP COLUMN IF EXISTS cost_price;
-- ALTER TABLE products DROP COLUMN IF EXISTS selling_price;

-- 성공 메시지
SELECT 'products 테이블의 불필요한 컬럼이 성공적으로 삭제되었습니다.' as result;