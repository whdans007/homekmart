<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

kw_require_staff();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['kw_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => 'Security error']);
    exit;
}

$pid = (int)($_POST['product_id'] ?? 0);
if (!$pid) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $conn = get_lc_db();
    $st = $conn->prepare("UPDATE kw_products SET requires_expiry = 1 - requires_expiry WHERE id = ?");
    $st->bind_param('i', $pid);
    $st->execute();

    $st2 = $conn->prepare("SELECT requires_expiry FROM kw_products WHERE id = ?");
    $st2->bind_param('i', $pid);
    $st2->execute();
    $new_val = (int)$st2->get_result()->fetch_assoc()['requires_expiry'];
    $conn->close();

    echo json_encode(['success' => true, 'requires_expiry' => $new_val]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
