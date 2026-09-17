<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_quick_create_supplier.method_not_allowed')]);
    exit;
}

lc_verify_csrf();

$name    = trim($_POST['name']           ?? '');
$contact = trim($_POST['contact_person'] ?? '');
$phone   = trim($_POST['phone']          ?? '');
$email   = trim($_POST['email']          ?? '');

if ($name === '') {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_quick_create_supplier.name_required')]);
    exit;
}

try {
    $conn = get_lc_db();

    $st = $conn->prepare(
        "INSERT INTO lc_suppliers (name, contact_person, phone, email) VALUES (?, ?, ?, ?)"
    );
    $st->bind_param('ssss', $name, $contact, $phone, $email);
    $st->execute();
    $new_id = $conn->insert_id;
    $st->close();
    $conn->close();

    echo json_encode([
        'success'  => true,
        'supplier' => ['id' => $new_id, 'name' => $name, 'contact_person' => $contact, 'phone' => $phone],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_quick_create_supplier.db_error', ['error' => $e->getMessage()])]);
}
