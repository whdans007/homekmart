<?php
/**
 * POST mall/admin/ajax/save_driver.php
 * Design Ref: mall-delivery-dispatch.design.md §4.1 — 기사 계정 생성/수정/활성화 토글
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_mall_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$action = $_POST['action'] ?? '';

try {
    $conn = get_db_connection();

    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $vehicle_info = trim($_POST['vehicle_info'] ?? '');
            if ($name === '' || $phone === '' || strlen($password) < 8) {
                json_error('VALIDATION_ERROR', '이름/연락처를 입력하고 비밀번호는 8자 이상이어야 합니다');
            }

            $check = $conn->prepare('SELECT id FROM mall_drivers WHERE phone = ?');
            $check->bind_param('s', $phone);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $check->close();
                json_error('DUPLICATE_PHONE', '이미 등록된 연락처입니다');
            }
            $check->close();

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                'INSERT INTO mall_drivers (name, phone, password_hash, vehicle_info) VALUES (?, ?, ?, ?)'
            );
            $stmt->bind_param('ssss', $name, $phone, $password_hash, $vehicle_info);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        case 'toggle_active':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("UPDATE mall_drivers SET is_available = IF(approval_status = 'pending', 1, is_available), is_active = IF(approval_status = 'pending', 1, NOT is_active), approval_status = IF(approval_status = 'pending', 'approved', approval_status) WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $vehicle_info = trim($_POST['vehicle_info'] ?? '');
            $password = $_POST['password'] ?? '';
            if ($id <= 0 || $name === '' || $phone === '') {
                json_error('VALIDATION_ERROR', '이름/연락처를 입력해주세요');
            }
            if ($password !== '' && strlen($password) < 8) {
                json_error('VALIDATION_ERROR', '비밀번호는 8자 이상이어야 합니다');
            }

            $check = $conn->prepare('SELECT id FROM mall_drivers WHERE phone = ? AND id != ?');
            $check->bind_param('si', $phone, $id);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $check->close();
                json_error('DUPLICATE_PHONE', '이미 등록된 연락처입니다');
            }
            $check->close();

            if ($password !== '') {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE mall_drivers SET name = ?, phone = ?, vehicle_info = ?, password_hash = ? WHERE id = ?');
                $stmt->bind_param('ssssi', $name, $phone, $vehicle_info, $password_hash, $id);
            } else {
                $stmt = $conn->prepare('UPDATE mall_drivers SET name = ?, phone = ?, vehicle_info = ? WHERE id = ?');
                $stmt->bind_param('sssi', $name, $phone, $vehicle_info, $id);
            }
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
            }
            $stmt = $conn->prepare('DELETE FROM mall_drivers WHERE id = ?');
            $stmt->bind_param('i', $id);
            try {
                $stmt->execute();
                $stmt->close();
                echo json_encode(['success' => true]);
            } catch (mysqli_sql_exception $e) {
                $stmt->close();
                // FK 제약(배정 이력 존재) — 배송 이력이 있는 기사는 삭제할 수 없고 비활성화만 가능하다.
                if ((int)$e->getCode() === 1451) {
                    json_error('HAS_HISTORY', '배송 이력이 있는 기사는 삭제할 수 없습니다. 비활성화를 사용해주세요', 409);
                }
                throw $e;
            }
            break;

        default:
            json_error('VALIDATION_ERROR', '알 수 없는 작업입니다');
    }

    $conn->close();
} catch (Exception $e) {
    error_log('save_driver.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
