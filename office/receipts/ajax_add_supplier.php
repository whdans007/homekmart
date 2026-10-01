<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$name           = post_str('name');
$contact_person = post_str('contact_person');
$phone          = post_str('phone');
$email          = post_str('email');

if ($name === '') {
    echo json_encode(['success'=>false,'error'=>'Supplier name is required.']);
    exit;
}

$conn = get_db_connection();

// Check duplicate
$chk = $conn->prepare("SELECT id FROM suppliers WHERE name=? LIMIT 1");
$chk->bind_param('s', $name);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
$chk->close();

if ($existing) {
    $conn->close();
    echo json_encode(['success'=>false,'error'=>'Supplier "' . htmlspecialchars($name) . '" already exists.']);
    exit;
}

$audit_context = get_supplier_audit_context($conn);
if ($audit_context !== null) {
    $stmt = $conn->prepare(
        "INSERT INTO suppliers (name, contact_person, phone, email, created_by, created_by_name, created_store_id, created_store_name) VALUES (?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('ssssisis', $name, $contact_person, $phone, $email, $audit_context[0], $audit_context[1], $audit_context[2], $audit_context[3]);
} else {
    $stmt = $conn->prepare(
        "INSERT INTO suppliers (name, contact_person, phone, email) VALUES (?,?,?,?)"
    );
    $stmt->bind_param('ssss', $name, $contact_person, $phone, $email);
}
$stmt->execute();
$id = $conn->insert_id;
$stmt->close();
$conn->close();

echo json_encode(['success'=>true, 'id'=>$id, 'name'=>$name]);
