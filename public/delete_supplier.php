<?php
require_once __DIR__ . '/../lib/session_helper.php';
ensure_logged_in();

// 총괄관리자만 접근 가능
if ($_SESSION['role'] !== 'super_admin') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '공급처를 삭제할 권한이 없습니다.'];
    header('Location: supplier_management.php');
    exit;
}

$supplier_id_to_delete = $_GET['id'] ?? null;

if (!$supplier_id_to_delete || !filter_var($supplier_id_to_delete, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 공급처 ID입니다.'];
    header('Location: supplier_management.php');
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 삭제할 공급처의 이름을 가져와서 메시지에 사용
    $stmt = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id_to_delete]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($supplier) {
        $delete_stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
        $delete_stmt->execute([$supplier_id_to_delete]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => "공급처 '" . htmlspecialchars($supplier['name']) . "'이(가) 성공적으로 삭제되었습니다."];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '삭제할 공급처를 찾을 수 없습니다.'];
    }
} catch (PDOException $e) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '데이터베이스 오류로 인해 공급처 삭제에 실패했습니다.'];
}

header('Location: supplier_management.php');
exit;