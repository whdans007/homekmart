<?php
// 진단: 주문 폼 input 개수 vs PHP max_input_vars 확인용 (확인 후 삭제할 것)
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

$max_input_vars = ini_get('max_input_vars');
echo "PHP max_input_vars = " . var_export($max_input_vars, true) . "\n";

try {
    $conn = function_exists('get_store_db') ? get_store_db() : null;
} catch (Throwable $e) {
    $conn = null;
}

// get_store_db 가 없으면 직접 연결 시도
if (!$conn) {
    // db_config.php 내 함수/상수에 맞게 조정 필요할 수 있음
    echo "get_store_db() 사용 불가 — db_config 함수명을 확인하세요.\n";
    exit;
}

$row = $conn->query(
    "SELECT COUNT(*) AS cnt
     FROM (
        SELECT p.id
        FROM lc_inventory i
        JOIN lc_products p ON i.product_id = p.id
        JOIN lc_inbound ib ON i.inbound_id = ib.id
        JOIN lc_inbound_batches bat ON ib.batch_id = bat.id
        WHERE i.quantity_remain > 0 AND p.is_active = 1
        GROUP BY p.id
     ) t"
)->fetch_assoc();

$products = (int)$row['cnt'];
echo "주문 가능 상품 수 = {$products}\n";
echo "폼이 생성하는 input 변수 수 (상품당 3개) = " . ($products * 3) . " (+ csrf, notes 등 약 +2)\n";

$limit = (int)$max_input_vars;
$total = $products * 3 + 2;
echo "\n";
if ($limit > 0 && $total > $limit) {
    echo ">>> 초과! 폼 input({$total}) > max_input_vars({$limit}) — 뒤쪽 카테고리 데이터가 잘립니다. (원인 확정)\n";
    $safe = intdiv($limit - 2, 3);
    echo ">>> 약 {$safe}번째 상품까지만 정상 전송되고, 그 이후(영문 카테고리명 늦은 순) 상품은 누락됩니다.\n";
} else {
    echo ">>> max_input_vars 초과는 아님. 다른 원인일 수 있습니다.\n";
}

$conn->close();
