<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// 세션 검증
if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo json_encode(['success' => false, 'error' => '접근 권한이 없습니다.']);
    exit;
}

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST 요청만 허용됩니다.']);
    exit;
}

// JSON 데이터 파싱
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => '잘못된 요청 데이터입니다.']);
    exit;
}

$name = trim($input['name'] ?? '');
$phone = trim($input['phone'] ?? '');
$memo = trim($input['memo'] ?? '');

// 유효성 검사
if (empty($name)) {
    echo json_encode(['success' => false, 'error' => '거래처명을 입력해주세요.']);
    exit;
}

try {
    $conn = get_db_connection();
    
    // 중복 거래처명 확인
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM suppliers WHERE name = ?");
    $check_stmt->bind_param("s", $name);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $count = $check_result->fetch_assoc()['count'];
    $check_stmt->close();
    
    if ($count > 0) {
        echo json_encode(['success' => false, 'error' => '이미 존재하는 거래처명입니다.']);
        $conn->close();
        exit;
    }
    
    // 거래처 추가
    $stmt = $conn->prepare("INSERT INTO suppliers (name, phone, memo) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $name, $phone, $memo);
    
    if ($stmt->execute()) {
        $supplier_id = $stmt->insert_id;
        $stmt->close();
        
        // 새로 추가된 거래처 정보 반환
        $select_stmt = $conn->prepare("SELECT id, name, phone, memo FROM suppliers WHERE id = ?");
        $select_stmt->bind_param("i", $supplier_id);
        $select_stmt->execute();
        $result = $select_stmt->get_result();
        $supplier = $result->fetch_assoc();
        $select_stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => '거래처가 성공적으로 등록되었습니다.',
            'supplier' => $supplier
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => '거래처 등록에 실패했습니다.']);
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => '오류가 발생했습니다: ' . $e->getMessage()]);
}
?>