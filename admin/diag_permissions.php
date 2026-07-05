<?php
/**
 * 권한 진단 스크립트.
 * 특정 사용자의 admin_access 등 권한이 어느 단계에서 결정되는지 보여줍니다.
 *   사용: https://.../admin/diag_permissions.php?username=매니져아이디
 * super_admin 전용.
 */
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit('super_admin 전용 스크립트입니다.');
}

header('Content-Type: text/plain; charset=utf-8');

$username = $_GET['username'] ?? '';
if ($username === '') {
    echo "사용법: diag_permissions.php?username=<아이디>\n";
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $has_perm_col = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'permissions'")->fetch();
    $fields = "id, username, full_name, role, store_id" . ($has_perm_col ? ", permissions" : "");
    $stmt = $pdo->prepare("SELECT {$fields} FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        echo "사용자를 찾을 수 없습니다: {$username}\n";
        exit;
    }

    echo "=== 사용자 ===\n";
    echo " id={$u['id']}  username={$u['username']}  role={$u['role']}  store_id=" . ($u['store_id'] ?? 'NULL') . "\n";
    echo " role level=" . get_role_level($u['role']) . "\n\n";

    echo "=== ① 사용자 개인 permissions JSON (최우선) ===\n";
    if ($has_perm_col && !empty($u['permissions'])) {
        $uj = json_decode($u['permissions'], true);
        if (is_array($uj)) {
            foreach (['admin_access', 'purchase_management', 'shop_access'] as $k) {
                echo "  {$k} = " . (isset($uj[$k]) ? ($uj[$k] ? 'true' : 'false') : '(미지정)') . "\n";
            }
        } else {
            echo "  (JSON 파싱 불가)\n";
        }
    } else {
        echo "  (개인 권한 JSON 없음 → 역할 기준으로 결정)\n";
    }

    echo "\n=== ② role_permissions DB 행 / ③ 레거시 기본값 ===\n";
    $rolePerms = get_role_permissions($u['role']);
    foreach (['admin_access', 'purchase_management', 'shop_access'] as $k) {
        echo "  {$k} = " . (isset($rolePerms[$k]) ? ($rolePerms[$k] ? 'true' : 'false') : '(미지정)') . "\n";
    }

    echo "\n=== 최종 판정 (has_permission) ===\n";
    foreach (['admin_access', 'purchase_management', 'shop_access'] as $k) {
        echo "  {$k} = " . (has_permission($k, $u['id']) ? 'ALLOW' : 'DENY') . "\n";
    }

    echo "\n=== 해석 ===\n";
    if (has_permission('admin_access', $u['id'])) {
        echo "  admin_access 가 ALLOW 입니다. admin/ 진입이 가능해야 합니다.\n";
    } else {
        echo "  admin_access 가 DENY 입니다 → admin/index.php 가 shop.php 로 리다이렉트합니다.\n";
        echo "  위 ①에 false 가 있으면 회원관리에서 해당 사용자 권한을 수정하세요.\n";
        echo "  ②가 false/명시돼 있으면 권한 매트릭스에서 manager 의 admin_access 를 켜세요.\n";
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
