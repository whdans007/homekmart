<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

if (!is_logged_in() || !has_permission('store_transfer_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '접근 권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '허용되지 않은 요청 방식입니다.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
            exit;
        }

        // 권한 확인: admin은 자기 점포 데이터만 삭제 가능
        if ($_SESSION['role'] !== 'super_admin') {
            $check = $pdo->prepare("SELECT store_id FROM event_products WHERE id = ?");
            $check->execute([$id]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['store_id'] !== (int)($_SESSION['store_id'] ?? 0)) {
                echo json_encode(['success' => false, 'message' => '삭제 권한이 없습니다.']);
                exit;
            }
        }

        $stmt = $pdo->prepare("DELETE FROM event_products WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => '행사상품이 삭제되었습니다.']);
        exit;
    }

    if ($action === 'save') {
        $id              = isset($input['id']) && $input['id'] ? (int)$input['id'] : null;
        $store_id        = (int)($input['store_id'] ?? 0);
        $product_id      = (int)($input['product_id'] ?? 0);
        $event_price     = (float)($input['event_price'] ?? 0);
        $original_cost   = isset($input['original_cost']) ? (float)$input['original_cost'] : null;
        $original_selling = isset($input['original_selling']) ? (float)$input['original_selling'] : null;
        $start_date      = $input['start_date'] ?? '';
        $end_date        = $input['end_date'] ?? '';
        $remarks         = trim($input['remarks'] ?? '');

        // 유효성 검사
        if (!$store_id || !$product_id) {
            echo json_encode(['success' => false, 'message' => '점포와 상품을 선택해주세요.']);
            exit;
        }
        if ($event_price <= 0) {
            echo json_encode(['success' => false, 'message' => '행사가는 0보다 커야 합니다.']);
            exit;
        }
        if (!$start_date || !$end_date) {
            echo json_encode(['success' => false, 'message' => '행사 기간을 설정해주세요.']);
            exit;
        }
        if ($start_date > $end_date) {
            echo json_encode(['success' => false, 'message' => '종료일은 시작일 이후여야 합니다.']);
            exit;
        }

        // 권한 확인: admin은 자기 점포만
        if ($_SESSION['role'] !== 'super_admin') {
            $session_store = (int)($_SESSION['store_id'] ?? 0);
            if ($store_id !== $session_store) {
                echo json_encode(['success' => false, 'message' => '다른 점포의 데이터를 수정할 수 없습니다.']);
                exit;
            }
        }

        $created_by = (int)$_SESSION['user_id'];

        if ($id) {
            // 수정
            $stmt = $pdo->prepare("
                UPDATE event_products
                SET store_id = ?, product_id = ?, event_price = ?,
                    original_cost = ?, original_selling = ?,
                    start_date = ?, end_date = ?, remarks = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $store_id, $product_id, $event_price,
                $original_cost, $original_selling,
                $start_date, $end_date,
                $remarks ?: null,
                $id
            ]);
            echo json_encode(['success' => true, 'message' => '행사상품이 수정되었습니다.', 'id' => $id]);
        } else {
            // 신규 등록
            $stmt = $pdo->prepare("
                INSERT INTO event_products
                    (store_id, product_id, event_price, original_cost, original_selling,
                     start_date, end_date, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $store_id, $product_id, $event_price,
                $original_cost, $original_selling,
                $start_date, $end_date,
                $remarks ?: null,
                $created_by
            ]);
            $new_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'message' => '행사상품이 등록되었습니다.', 'id' => $new_id]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => '알 수 없는 요청입니다.']);

} catch (PDOException $e) {
    error_log("ajax_save_event_product error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '처리 중 오류가 발생했습니다.']);
}
