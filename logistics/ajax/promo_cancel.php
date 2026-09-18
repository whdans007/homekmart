<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['lc_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_cancel.security_error')]);
    exit;
}

$promotion_id = (int)($_POST['promotion_id'] ?? 0);

try {
    $conn = get_lc_db();

    $st = $conn->prepare("SELECT id FROM lc_lot_promotions WHERE id = ? AND status = 'active'");
    $st->bind_param('i', $promotion_id);
    $st->execute();
    if (!$st->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_cancel.not_active')]);
        $conn->close();
        exit;
    }

    $cancelled_by = (int)($_SESSION['user_id'] ?? 0);
    $st2 = $conn->prepare(
        "UPDATE lc_lot_promotions
         SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?
         WHERE id = ?"
    );
    $st2->bind_param('ii', $cancelled_by, $promotion_id);
    $st2->execute();
    $conn->close();

    echo json_encode(['success' => true, 'message' => t('logistics.ajax_promo_cancel.success')]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_promo_cancel.db_error')]);
}

