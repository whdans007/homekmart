<?php
// Design Ref: daily-report.design.md §4.2 — 기타지출 항목→카테고리 배치 저장/삭제
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/../lib/daily_report_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

$date = trim($payload['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date']);
    exit;
}

// Plan/Design §7 — store 스코프 강제: super_admin만 store_id override 가능
$store_id = get_office_store_id();
$is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';
if ($is_super_admin) {
    $req_store_id = (int)($payload['store_id'] ?? 0);
    if ($req_store_id > 0) $store_id = $req_store_id;
}

$valid_categories = array_keys(get_daily_report_categories());
$placements = is_array($payload['placements'] ?? null) ? $payload['placements'] : [];
$removals   = is_array($payload['removals'] ?? null) ? $payload['removals'] : [];

$conn = get_db_connection();
$conn->autocommit(false);

$saved = 0;
$errors = [];
try {
    $created_by = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $stmt = $conn->prepare(
        "INSERT INTO daily_report_expense_category (store_id, sale_date, source_item_id, category_key, created_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE category_key = VALUES(category_key)"
    );
    foreach ($placements as $p) {
        $source_item_id = trim((string)($p['source_item_id'] ?? ''));
        $category_key   = trim((string)($p['category_key'] ?? ''));
        if ($source_item_id === '' || !in_array($category_key, $valid_categories, true)) {
            $errors[] = "Invalid category for {$source_item_id}";
            continue;
        }
        $stmt->bind_param('isssi', $store_id, $date, $source_item_id, $category_key, $created_by);
        $stmt->execute();
        $saved++;
    }
    $stmt->close();

    $removed = 0;
    if (!empty($removals)) {
        $del = $conn->prepare(
            "DELETE FROM daily_report_expense_category WHERE store_id=? AND sale_date=? AND source_item_id=?"
        );
        foreach ($removals as $source_item_id) {
            $source_item_id = trim((string)$source_item_id);
            if ($source_item_id === '') continue;
            $del->bind_param('iss', $store_id, $date, $source_item_id);
            $del->execute();
            $removed++;
        }
        $del->close();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'saved' => $saved, 'removed' => $removed, 'errors' => $errors]);
} catch (Exception $e) {
    $conn->rollback();
    error_log('daily_report ajax_save_categories error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'DB error']);
}
$conn->close();
