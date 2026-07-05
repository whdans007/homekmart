<?php
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/order_helper.php';
ord_require_manager();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') ord_verify_csrf();

$storeId = ord_current_store_id();
$conn    = get_ord_db();
ord_ensure_cart_schema($conn);

$stmt = $conn->prepare("
    SELECT c.id, c.vendor_id, c.inventory_item_id, c.quantity,
           c.added_by, u.full_name AS added_by_name,
           v.name AS vendor_name,
           i.product_name, i.unit_price, i.unit_price_pcs
    FROM order_cart_items c
    JOIN order_vendors v ON c.vendor_id = v.id
    JOIN order_vendor_inventory_items i ON c.inventory_item_id = i.id
    LEFT JOIN users u ON c.added_by = u.id
    WHERE c.store_id = ?
    ORDER BY v.name, i.product_name
");
$stmt->bind_param('i', $storeId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$groups = [];
foreach ($rows as $row) {
    $vid = $row['vendor_id'];
    if (!isset($groups[$vid])) {
        $groups[$vid] = ['vendor_id' => $vid, 'vendor_name' => $row['vendor_name'], 'items' => []];
    }
    $price = $row['unit_price'] ?: $row['unit_price_pcs'] ?: 0;
    $groups[$vid]['items'][] = [
        'id'             => $row['inventory_item_id'],
        'product_name'   => $row['product_name'],
        'quantity'       => (int)$row['quantity'],
        'unit_price'     => (float)$row['unit_price'],
        'unit_price_pcs' => (float)$row['unit_price_pcs'],
        'amount'         => $row['quantity'] * $price,
        'added_by_name'  => $row['added_by_name'] ?? null,
    ];
}

$result = array_values($groups);
foreach ($result as &$g) {
    $g['item_count']     = count($g['items']);
    $g['total_quantity'] = array_sum(array_column($g['items'], 'quantity'));
    $g['total_amount']   = array_sum(array_column($g['items'], 'amount'));
}

$versionData = array_map(fn($r) => $r['inventory_item_id'] . ':' . $r['quantity'], $rows);
$version = md5(implode(',', $versionData));

echo json_encode(['success' => true, 'groups' => $result, 'total_items' => array_sum(array_column($result, 'item_count')), 'version' => $version]);
