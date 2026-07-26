<?php
// File: logistics/test_inbound_helper.php
// Purpose: Test helper function with sample filters

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inbound_helper.php';

// 웹 접근 시 KIMS MALL WHEREHOUSE(킴스몰 창고) 소속/슈퍼관리자만 허용 (CLI 실행은 예외)
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/lib/auth.php';
    kw_require_staff();
}

echo "=== Inbound Items Helper Function Test ===\n\n";

// Test 1: 필터 없이 전체 조회
echo "Test 1: All items (no filters)\n";
$start = microtime(true);
$result = getFilteredInboundItems([], 1, 20);
$elapsed = (microtime(true) - $start) * 1000;
echo "Total items: " . $result['total'] . "\n";
echo "Items returned: " . count($result['items']) . "\n";
echo "Pages: " . $result['total_pages'] . "\n";
echo "Response time: " . round($elapsed, 2) . "ms\n";
if (isset($result['items'][0])) {
    echo "First item: " . json_encode($result['items'][0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
if ($result['error']) {
    echo "⚠️ Error: " . $result['error'] . "\n";
}
echo "✅ Test 1 complete\n\n";

// Test 2: 업체별 필터
echo "Test 2: Filter by supplier\n";
$start = microtime(true);
$result = getFilteredInboundItems(['supplier' => 'JK'], 1, 20);
$elapsed = (microtime(true) - $start) * 1000;
echo "Supplier filter: 'JK'\n";
echo "Total items: " . $result['total'] . "\n";
echo "Items returned: " . count($result['items']) . "\n";
echo "Response time: " . round($elapsed, 2) . "ms\n";
if ($result['error']) {
    echo "⚠️ Error: " . $result['error'] . "\n";
}
echo "✅ Test 2 complete\n\n";

// Test 3: 상품/바코드 필터
echo "Test 3: Filter by product/barcode\n";
$start = microtime(true);
$result = getFilteredInboundItems(['product_or_barcode' => 'Item'], 1, 20);
$elapsed = (microtime(true) - $start) * 1000;
echo "Product filter: 'Item'\n";
echo "Total items: " . $result['total'] . "\n";
echo "Items returned: " . count($result['items']) . "\n";
echo "Response time: " . round($elapsed, 2) . "ms\n";
if ($result['error']) {
    echo "⚠️ Error: " . $result['error'] . "\n";
}
echo "✅ Test 3 complete\n\n";

// Test 4: 날짜범위 필터
echo "Test 4: Filter by date range\n";
$start = microtime(true);
$result = getFilteredInboundItems([
    'date_from' => '2026-01-01',
    'date_to' => '2026-12-31'
], 1, 20);
$elapsed = (microtime(true) - $start) * 1000;
echo "Date range: 2026-01-01 to 2026-12-31\n";
echo "Total items: " . $result['total'] . "\n";
echo "Items returned: " . count($result['items']) . "\n";
echo "Response time: " . round($elapsed, 2) . "ms\n";
if ($result['error']) {
    echo "⚠️ Error: " . $result['error'] . "\n";
}
echo "✅ Test 4 complete\n\n";

// Test 5: 복합 필터
echo "Test 5: Combined filters (AND logic)\n";
$start = microtime(true);
$result = getFilteredInboundItems([
    'supplier' => 'JK',
    'product_or_barcode' => 'Item',
    'date_from' => '2026-01-01'
], 1, 20);
$elapsed = (microtime(true) - $start) * 1000;
echo "Filters: supplier='JK' AND product='Item' AND date >= 2026-01-01\n";
echo "Total items: " . $result['total'] . "\n";
echo "Items returned: " . count($result['items']) . "\n";
echo "Response time: " . round($elapsed, 2) . "ms\n";
if ($result['error']) {
    echo "⚠️ Error: " . $result['error'] . "\n";
}
echo "✅ Test 5 complete\n\n";

echo "=== All Tests Complete ===\n";
echo "Performance target: < 2000ms (2 seconds)\n";
echo "Next step: Session 2 - Create main page (inbound_items.php)\n";

?>
