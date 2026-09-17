<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$q = trim($_GET['q'] ?? '');

try {
    $conn = get_lc_db();

    if ($q === '') {
        $st = $conn->prepare(
            "SELECT id, name, contact_person, phone FROM lc_suppliers ORDER BY name ASC LIMIT 20"
        );
        $st->execute();
    } else {
        $like = '%' . $q . '%';
        $st = $conn->prepare(
            "SELECT id, name, contact_person, phone FROM lc_suppliers
             WHERE name LIKE ? OR contact_person LIKE ?
             ORDER BY name ASC LIMIT 20"
        );
        $st->bind_param('ss', $like, $like);
        $st->execute();
    }

    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $conn->close();

    echo json_encode(['success' => true, 'suppliers' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_search_supplier.db_error', ['error' => $e->getMessage()])]);
}
