<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['lc_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_register.security_error')]);
    exit;
}

$inventory_id = (int)($_POST['inventory_id'] ?? 0);
$discount_rate_value = $_POST['discount_rate'] ?? '';
if (!is_numeric($discount_rate_value)) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_register.invalid_rate')]);
    exit;
}

$discount_rate = (float)$discount_rate_value;
if ($discount_rate <= 0 || $discount_rate >= 100) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_register.invalid_rate')]);
    exit;
}

try {
    $conn = get_lc_db();

    $st = $conn->prepare(
        'SELECT id, product_id, inbound_id, lot_number, expiry_date, unit, quantity_remain
         FROM lc_inventory WHERE id = ?'
    );
    $st->bind_param('i', $inventory_id);
    $st->execute();
    $inventory = $st->get_result()->fetch_assoc();
    if (!$inventory || (float)$inventory['quantity_remain'] <= 0) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_register.no_stock')]);
        $conn->close();
        exit;
    }

    $st2 = $conn->prepare(
        "SELECT id FROM lc_lot_promotions WHERE inventory_id = ? AND status = 'active' LIMIT 1"
    );
    $st2->bind_param('i', $inventory_id);
    $st2->execute();
    if ($st2->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_register.already_active')]);
        $conn->close();
        exit;
    }

    $st3 = $conn->prepare('SELECT cost_price FROM lc_inbound WHERE id = ?');
    $st3->bind_param('i', $inventory['inbound_id']);
    $st3->execute();
    $inbound = $st3->get_result()->fetch_assoc();
    if (!$inbound || $inbound['cost_price'] === null || (float)$inbound['cost_price'] <= 0) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_register.no_base_price')]);
        $conn->close();
        exit;
    }

    $base_price = round((float)$inbound['cost_price'], 2);
    $discounted_price = round($base_price * (1 - $discount_rate / 100), 2);
    $registered_by = (int)($_SESSION['user_id'] ?? 0);

    $st4 = $conn->prepare(
        "INSERT INTO lc_lot_promotions
            (inventory_id, product_id, lot_number, expiry_date, unit, discount_rate,
             base_price, discounted_price, status, registered_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
    );
    $st4->bind_param(
        'iisssdddi',
        $inventory['id'],
        $inventory['product_id'],
        $inventory['lot_number'],
        $inventory['expiry_date'],
        $inventory['unit'],
        $discount_rate,
        $base_price,
        $discounted_price,
        $registered_by
    );
    $st4->execute();
    $promotion_id = $conn->insert_id;
    $conn->close();

    echo json_encode([
        'success' => true,
        'message' => t('logistics.ajax_promo_register.success'),
        'data' => [
            'promotion_id' => (int)$promotion_id,
            'base_price' => $base_price,
            'discounted_price' => $discounted_price,
            'discount_rate' => $discount_rate,
            'unit' => $inventory['unit'],
        ],
    ]);
} catch (Exception $e) {
    // 동시 등록 경쟁조건 방어: DB의 uq_active_inventory 유니크 제약(status='active'인 inventory_id는 유일) 위반 시
    // 사전 검증(§중복 등록 방지)을 통과했더라도 최종적으로 여기서 걸러진다(errno 1062 = Duplicate entry)
    if ((int)$e->getCode() === 1062) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_register.already_active')]);
    } else {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_register.db_error')]);
    }
}

