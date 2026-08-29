<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 점포 목록 조회
if ($action === 'get_stores') {
    try {
        $conn = get_lc_db();
        // 물류센터(CENTER) 자신은 배분 목적지가 될 수 없으므로 목록에서 제외
        $stores = $conn->query("SELECT id, name FROM stores WHERE name <> '" . $conn->real_escape_string(LC_CENTER_STORE_NAME) . "' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
        $conn->close();
        echo json_encode(['success' => true, 'stores' => $stores]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 상품 재고 및 현재가 조회
if ($action === 'get_product_stock') {
    $product_id = (int)($_GET['product_id'] ?? 0);
    if (!$product_id) { echo json_encode(['success' => false, 'message' => 'Invalid product']); exit; }
    try {
        $conn = get_lc_db();
        $st = $conn->prepare(
            "SELECT p.id, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS name,
                    p.unit,
                    COALESCE(SUM(i.quantity_remain), 0) AS total_stock,
                    COALESCE(
                        SUM(i.quantity_remain * ib.cost_price) / NULLIF(SUM(i.quantity_remain), 0)
                    , 0) AS avg_cost
             FROM lc_products p
             LEFT JOIN lc_inventory i ON i.product_id = p.id AND i.quantity_remain > 0
             LEFT JOIN lc_inbound ib ON i.inbound_id = ib.id
             WHERE p.id = ?
             GROUP BY p.id"
        );
        $st->bind_param('i', $product_id);
        $st->execute();
        $product = $st->get_result()->fetch_assoc();
        $st->close();
        $conn->close();
        echo json_encode(['success' => true, 'product' => $product]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 배분 실행
if ($action === 'distribute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['lc_csrf'] ?? '', $token)) {
        echo json_encode(['success' => false, 'message' => 'Security error']);
        exit;
    }

    $product_id = (int)($_POST['product_id'] ?? 0);
    $unit_price = (float)($_POST['unit_price'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');
    $dist       = $_POST['dist'] ?? []; // [store_id => quantity]

    if (!$product_id) { echo json_encode(['success' => false, 'message' => 'Product information error']); exit; }

    $items = [];
    foreach ($dist as $sid => $qty) {
        $sid = (int)$sid; $qty = (int)$qty;
        if ($sid > 0 && $qty > 0) $items[$sid] = $qty;
    }
    if (empty($items)) { echo json_encode(['success' => false, 'message' => 'Please enter the store/quantity to distribute.']); exit; }

    try {
        $conn = get_lc_db();
        $conn->autocommit(false);

        // 물류센터(CENTER) 자신은 배분 목적지가 될 수 없으므로 제외
        foreach (array_keys($items) as $sid) {
            if (lc_is_center_store($conn, $sid)) unset($items[$sid]);
        }
        if (empty($items)) {
            $conn->rollback(); $conn->close();
            echo json_encode(['success' => false, 'message' => 'The Logistics Center cannot be selected as the destination store.']);
            exit;
        }

        $uid   = lc_current_user_id();
        $today = date('Y-m-d');
        $created = 0;

        $ins_order = $conn->prepare(
            "INSERT INTO lc_orders (order_date, store_id, status, total_amount, notes, created_by)
             VALUES (?, ?, 'approved', ?, ?, ?)"
        );
        $ins_item = $conn->prepare(
            "INSERT INTO lc_order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)"
        );

        foreach ($items as $store_id => $qty) {
            $total = round($qty * $unit_price, 2);
            $ins_order->bind_param('sissi', $today, $store_id, $total, $notes, $uid);
            $ins_order->execute();
            $order_id = $conn->insert_id;

            $ins_item->bind_param('iiid', $order_id, $product_id, $qty, $unit_price);
            $ins_item->execute();
            $created++;
        }

        $ins_order->close();
        $ins_item->close();
        $conn->commit();
        $conn->close();

        echo json_encode(['success' => true, 'created' => $created]);
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->close(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
