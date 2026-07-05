<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();
lc_verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id      = (int)($_POST['id'] ?? 0);
$name    = trim($_POST['name']           ?? '');
$contact = trim($_POST['contact_person'] ?? '');
$phone   = trim($_POST['phone']          ?? '');
$email   = trim($_POST['email']          ?? '');
$memo    = trim($_POST['memo']           ?? '');

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Supplier name is required.']);
    exit;
}

try {
    $conn = get_lc_db();

    if ($id > 0) {
        $st = $conn->prepare(
            "UPDATE lc_suppliers SET name=?, contact_person=?, phone=?, email=?, memo=? WHERE id=?"
        );
        $st->bind_param('sssssi', $name, $contact, $phone, $email, $memo, $id);
    } else {
        $st = $conn->prepare(
            "INSERT INTO lc_suppliers (name, contact_person, phone, email, memo) VALUES (?, ?, ?, ?, ?)"
        );
        $st->bind_param('sssss', $name, $contact, $phone, $email, $memo);
    }

    $st->execute();
    $affected = $st->affected_rows;
    $new_id = $id ?: $conn->insert_id;
    $st->close();
    $conn->close();

    echo json_encode(['success' => true, 'id' => $new_id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
