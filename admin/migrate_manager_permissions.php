<?php
/**
 * 매니져(manager) 역할 권한 보정.
 * 진단 결과: role_permissions DB 행이 존재하며 admin_access=false 라서 admin/ 진입이 막힘.
 * → admin_access 만 true 로 켭니다. (purchase_management 등 기존 설정은 그대로 보존)
 *
 * super_admin 로그인 상태에서 브라우저로 1회 실행:
 *   https://.../admin/migrate_manager_permissions.php
 * (역할 관리 > 권한 매트릭스에서 manager 의 '관리자 메뉴 접근'을 켜도 동일)
 */
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit('super_admin 전용 스크립트입니다.');
}

header('Content-Type: text/plain; charset=utf-8');

echo "변경 전 manager 권한:\n";
$before = get_role_permissions('manager');
foreach (['admin_access', 'purchase_management', 'shop_access'] as $k) {
    echo "  {$k} = " . (isset($before[$k]) ? ($before[$k] ? 'true' : 'false') : '(미지정)') . "\n";
}

// admin_access 만 켠다 (다른 키는 전달하지 않으므로 보존됨)
$result = update_role_permissions('manager', ['admin_access' => true], $_SESSION['user_id'] ?? null);

echo "\n";
if ($result === true) {
    echo "OK: manager 의 admin_access 를 true 로 설정했습니다.\n\n";
    echo "변경 후 manager 권한:\n";
    $after = get_role_permissions('manager');
    foreach (['admin_access', 'purchase_management', 'shop_access'] as $k) {
        echo "  {$k} = " . (isset($after[$k]) ? ($after[$k] ? 'true' : 'false') : '(미지정)') . "\n";
    }
    echo "\n매니져 계정으로 다시 로그인 후 admin/ 진입을 확인하세요.\n";
} else {
    http_response_code(500);
    echo "ERROR: 권한 설정에 실패했습니다.\n";
}
