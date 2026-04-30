<?php
require_once __DIR__ . '/../lib/office_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$store_id = get_office_store_id();
$job_role = $_GET['job_role'] ?? '';
$roles    = get_job_roles();

if (!in_array($job_role, $roles)) {
    echo json_encode(['employees' => []]);
    exit;
}

$employees = get_office_employees($store_id, $job_role, 'active');

echo json_encode([
    'employees' => array_map(fn($e) => ['id' => (int)$e['id'], 'name' => $e['name']], $employees)
]);
