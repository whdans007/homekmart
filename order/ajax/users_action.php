<?php
require_once dirname(__DIR__) . '/lib/auth.php';
ord_require_admin();
ord_verify_csrf();
header('Content-Type: application/json; charset=utf-8');

$action         = $_POST['action'] ?? '';
$currentStoreId = ord_current_store_id();
$isSuperAdmin   = ($_SESSION['role'] ?? '') === 'super_admin';

try {
    $conn = get_ord_db();

    if ($action === 'create') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username']  ?? '');
        $password = $_POST['password'] ?? '';
        $storeId  = (int)($_POST['store_id'] ?? $currentStoreId);
        $role     = $_POST['role'] ?? 'admin';

        if (!$fullName || !$username || strlen($password) < 8) {
            throw new Exception('이름, 아이디는 필수이며 비밀번호는 8자 이상이어야 합니다.');
        }
        if (!in_array($role, ['admin'], true)) {
            $role = 'admin';
        }
        if (!$isSuperAdmin) {
            $storeId = $currentStoreId;
        }
        if (!$storeId) {
            throw new Exception('점포를 선택해주세요.');
        }

        $check = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check->bind_param('s', $username);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            throw new Exception('이미 존재하는 아이디입니다.');
        }
        $check->close();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins  = $conn->prepare("INSERT INTO users (username, full_name, password, role, store_id) VALUES (?,?,?,?,?)");
        $ins->bind_param('ssssi', $username, $fullName, $hash, $role, $storeId);
        $ins->execute();
        $ins->close();

        echo json_encode(['success' => true]);

    } elseif ($action === 'reset_password') {
        $uid      = (int)($_POST['id']       ?? 0);
        $password = $_POST['password'] ?? '';

        if (!$uid || strlen($password) < 8) {
            throw new Exception('비밀번호는 8자 이상이어야 합니다.');
        }

        // 같은 점포 사용자만 변경 가능 (super_admin 제외)
        if (!$isSuperAdmin) {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND store_id = ? AND role != 'super_admin' LIMIT 1");
            $chk->bind_param('ii', $uid, $currentStoreId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows === 0) {
                throw new Exception('권한이 없습니다.');
            }
            $chk->close();
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd  = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->bind_param('si', $hash, $uid);
        $upd->execute();
        $upd->close();

        echo json_encode(['success' => true]);

    } else {
        throw new Exception('알 수 없는 액션');
    }

    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
