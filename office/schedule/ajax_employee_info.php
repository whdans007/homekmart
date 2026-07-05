<?php
require_once __DIR__ . '/../lib/office_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}
require_office_permission();

$emp_id = (int)($_GET['employee_id'] ?? 0);
if ($emp_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid employee id']);
    exit;
}

$store_id = get_office_store_id();
$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT id, name, job_role, status, inactive_reason, inactive_date, photo, created_at
     FROM office_employees WHERE id=? AND store_id=?"
);
$stmt->bind_param('ii', $emp_id, $store_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Employee not found']);
    exit;
}

echo json_encode(['success' => true, 'employee' => [
    'id'              => (int)$row['id'],
    'name'            => $row['name'],
    'job_role'        => $row['job_role'],
    'job_role_label'  => get_job_role_label($row['job_role']),
    'status'          => $row['status'],
    'inactive_reason' => $row['inactive_reason'],
    'inactive_date'   => $row['inactive_date'],
    'photo_url'       => !empty($row['photo']) ? ('../../uploads/employees/' . $row['photo']) : null,
    'created_at'      => $row['created_at'],
]], JSON_UNESCAPED_UNICODE);
