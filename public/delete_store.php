<?php
require_once __DIR__ . '/../lib/session_helper.php';
ensure_logged_in();

// 총괄관리자만 접근 가능
if ($_SESSION['role'] !== 'super_admin') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '지점을 삭제할 권한이 없습니다.'];
    header('Location: store_management.php');
    exit;
}

$store_id_to_delete = $_GET['id'] ?? null;

if (!$store_id_to_delete || !filter_var($store_id_to_delete, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 지점 ID입니다.'];
    header('Location: store_management.php');
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 삭제할 지점의 이름을 가져와서 메시지에 사용
    $stmt = $pdo->prepare("SELECT name FROM stores WHERE id = ?");
    $stmt->execute([$store_id_to_delete]);
    $store = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($store) {
        $delete_stmt = $pdo->prepare("DELETE FROM stores WHERE id = ?");
        $delete_stmt->execute([$store_id_to_delete]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => "지점 '" . htmlspecialchars($store['name']) . "'이(가) 성공적으로 삭제되었습니다."];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '삭제할 지점을 찾을 수 없습니다.'];
    }
} catch (PDOException $e) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '데이터베이스 오류로 인해 지점 삭제에 실패했습니다.'];
    // 실제 운영 환경에서는 아래 코드로 로그를 남기는 것이 좋습니다.
    // error_log($e->getMessage());
}

header('Location: store_management.php');
exit;