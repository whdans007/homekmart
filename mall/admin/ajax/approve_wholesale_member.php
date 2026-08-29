<?php
/**
 * POST mall/admin/ajax/approve_wholesale_member.php
 * Design Ref: shopping-mall.design.md §4.2 approve_wholesale_member.php
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../config/mall_config.php';
require_once __DIR__ . '/../../lib/csrf.php';

function json_error($code, $message, $http = 400, $details = null) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => $details]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$member_id = (int)($_POST['member_id'] ?? 0);
$action = $_POST['action'] ?? '';
$wholesale_customer_id = (int)($_POST['wholesale_customer_id'] ?? 0);

if ($member_id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $stmt = $conn->prepare(
        "SELECT id, name, phone, business_name FROM mall_members
         WHERE id = ? AND member_type = 'wholesale' AND wholesale_status = 'pending'"
    );
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member) {
        $conn->close();
        json_error('VALIDATION_ERROR', '승인 대기중인 도매 회원이 아닙니다', 404);
    }

    if ($action === 'reject') {
        $update = $conn->prepare("UPDATE mall_members SET wholesale_status = 'rejected' WHERE id = ?");
        $update->bind_param('i', $member_id);
        $update->execute();
        $update->close();
        $conn->close();
        echo json_encode(['success' => true, 'data' => ['member_id' => $member_id, 'wholesale_status' => 'rejected']]);
        exit;
    }

    // action === 'approve'
    $conn->begin_transaction();
    $in_txn = true;

    if ($wholesale_customer_id > 0) {
        $check = $conn->prepare('SELECT id FROM wholesale_customers WHERE id = ? AND is_active = 1');
        $check->bind_param('i', $wholesale_customer_id);
        $check->execute();
        $found = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$found) {
            $conn->rollback();
            $in_txn = false;
            $conn->close();
            json_error('VALIDATION_ERROR', '선택한 거래처를 찾을 수 없습니다', 404);
        }
    } else {
        $customer_name = $member['business_name'] ?: $member['name'];
        $insert = $conn->prepare(
            'INSERT INTO wholesale_customers (store_id, name, phone, is_active) VALUES (?, ?, ?, 1)'
        );
        $store_id = MALL_STORE_ID;
        $insert->bind_param('iss', $store_id, $customer_name, $member['phone']);
        $insert->execute();
        $wholesale_customer_id = $insert->insert_id;
        $insert->close();
    }

    $update = $conn->prepare(
        "UPDATE mall_members SET wholesale_status = 'approved', wholesale_customer_id = ? WHERE id = ?"
    );
    $update->bind_param('ii', $wholesale_customer_id, $member_id);
    $update->execute();
    $update->close();

    $conn->commit();
    $in_txn = false;
    $conn->close();

    echo json_encode(['success' => true, 'data' => [
        'member_id' => $member_id,
        'wholesale_status' => 'approved',
        'wholesale_customer_id' => $wholesale_customer_id,
    ]]);
} catch (Exception $e) {
    if (isset($conn) && !empty($in_txn)) {
        $conn->rollback();
    }
    error_log('approve_wholesale_member.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
