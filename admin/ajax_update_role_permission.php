<?php
// Design Ref: docs/02-design/features/role-permission-management.design.md §4.2 - ajax_update_role_permission.php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit();
}

// 권한 매트릭스는 settings 권한(super_admin)만 사용 가능
if (!has_permission('settings')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

$role_key = $input['role_key'] ?? '';
$permission_key = $input['permission_key'] ?? '';
$enabled = isset($input['enabled']) ? (int)(bool)$input['enabled'] : null;

if ($role_key === '' || $permission_key === '' || $enabled === null) {
    echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
    exit();
}

// super_admin 역할은 항상 모든 권한 보유 (서버측 이중 방어)
if ($role_key === 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'super_admin 역할의 권한은 변경할 수 없습니다.']);
    exit();
}

// permission_key 화이트리스트 검증 (임의 키 INSERT 방지)
if (!in_array($permission_key, get_all_permission_keys(), true)) {
    echo json_encode(['success' => false, 'message' => '알 수 없는 권한 키입니다.']);
    exit();
}

$result = update_role_permissions($role_key, [$permission_key => $enabled], $_SESSION['user_id']);

if ($result) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => '저장 중 오류가 발생했습니다.']);
}
