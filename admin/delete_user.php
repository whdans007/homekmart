<?php
require_once __DIR__ . '/../lib/session_helper.php';
ensure_logged_in();

// 권한 확인: 관리자 또는 총괄관리자만 접근 가능
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '회원을 삭제할 권한이 없습니다.'];
    header('Location: user_management.php');
    exit;
}

$user_id_to_delete = $_GET['id'] ?? null;

// ID 유효성 검사
if (!$user_id_to_delete || !filter_var($user_id_to_delete, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 회원 ID입니다.'];
    header('Location: user_management.php');
    exit;
}

// 자기 자신 삭제 방지
if ($user_id_to_delete == $_SESSION['user_id']) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '자신의 계정은 삭제할 수 없습니다.'];
    header('Location: user_management.php');
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 삭제할 회원의 정보를 먼저 확인 (권한 확인 및 메시지 출력용)
    $stmt = $pdo->prepare("SELECT role, username FROM users WHERE id = ?");
    $stmt->execute([$user_id_to_delete]);
    $user_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_to_delete) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '삭제할 회원을 찾을 수 없습니다.'];
    } else {
        // 총괄 관리자 삭제 방지
        if ($_SESSION['role'] === 'admin' && $user_to_delete['role'] === 'super_admin') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => '총괄 관리자는 삭제할 수 없습니다.'];
        } else {
            // 모든 검사를 통과하면 삭제 실행
            $delete_stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->execute([$user_id_to_delete]);

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => "회원 '" . htmlspecialchars($user_to_delete['username']) . "' 님이 성공적으로 삭제되었습니다."
            ];
        }
    }
} catch (PDOException $e) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => '데이터베이스 오류로 인해 회원 삭제에 실패했습니다.'
    ];
    // 실제 운영 환경에서는 아래 코드로 로그를 남기는 것이 좋습니다.
    // error_log($e->getMessage());
}

header('Location: user_management.php');
exit;