<?php
// 출력 버퍼링 시작
ob_start();

try {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '필요한 라이브러리를 불러올 수 없습니다: ' . $e->getMessage()]);
    exit;
}

// 세션 시작
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 출력 버퍼 정리
ob_clean();
header('Content-Type: application/json');

// 권한 확인
if (!is_logged_in() || !has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

// 파라미터 받기
$purchase_id = $_POST['purchase_id'] ?? '';
$item_ids = $_POST['item_ids'] ?? [];
$margin_rate = $_POST['margin_rate'] ?? '';
$store_id = $_POST['store_id'] ?? '';

// 검증
if (empty($purchase_id)) {
    echo json_encode(['success' => false, 'message' => '매입 ID가 필요합니다.']);
    exit;
}

if (empty($item_ids) || !is_array($item_ids)) {
    echo json_encode(['success' => false, 'message' => '선택된 상품이 없습니다.']);
    exit;
}

if (!is_numeric($margin_rate) || $margin_rate < 0) {
    echo json_encode(['success' => false, 'message' => '올바른 마진율을 입력해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 트랜잭션 시작
    $transaction_started = false;
    try {
        $pdo->beginTransaction();
        $transaction_started = true;
    } catch (PDOException $e) {
        // 이미 트랜잭션이 시작된 경우 무시
        error_log("트랜잭션 시작 경고: " . $e->getMessage());
    }
    
    $updated_count = 0;
    $updated_items = [];
    
    // 각 item_id에 대해 개별 처리
    foreach ($item_ids as $item_id) {
        if (!is_numeric($item_id)) {
            continue;
        }
        
        // 직접 매핑으로 데이터 가져오기
        $product_id = $_POST["product_id_{$item_id}"] ?? null;
        $selling_price = $_POST["selling_price_{$item_id}"] ?? null;
        
        if (!$product_id || !is_numeric($product_id)) {
            error_log("Item {$item_id}의 product_id를 찾을 수 없습니다");
            continue;
        }
        
        // 1. purchase_items 테이블에서 현재 정보 조회
        $sql = "SELECT pi.*, p.name_ko, p.pieces_per_box 
                FROM purchase_items pi
                JOIN products p ON pi.product_id = p.id
                WHERE pi.item_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            error_log("Item {$item_id}를 찾을 수 없습니다");
            continue;
        }
        
        // 원가 계산 (낱개 기준)
        $cost_price = $item['unit_price'];
        if ($item['purchase_type'] === 'box' && $item['pieces_per_box'] > 0) {
            $cost_price = $item['unit_price'] / $item['pieces_per_box'];
        }
        
        // 판매가 설정 (JavaScript에서 전송된 값 사용, 없으면 마진율로 계산)
        if ($selling_price && is_numeric($selling_price)) {
            $new_selling_price = intval($selling_price);
        } else {
            $new_selling_price = ceil($cost_price * (1 + ($margin_rate / 100)));
        }
        
        // 2. purchase_items 테이블에 예상 판매가 저장 (컬럼이 있다면)
        try {
            $sql_update_item = "UPDATE purchase_items 
                               SET expected_selling_price = ? 
                               WHERE item_id = ?";
            $stmt = $pdo->prepare($sql_update_item);
            $stmt->execute([$new_selling_price, $item_id]);
        } catch (PDOException $e) {
            // expected_selling_price 컬럼이 없을 수 있음
            error_log("purchase_items 업데이트 실패 (컬럼 없음 가능): " . $e->getMessage());
        }
        
        // 3. store_id가 있으면 inventory 테이블 업데이트
        if ($store_id && is_numeric($store_id)) {
            // inventory 레코드 확인
            $sql_check = "SELECT id FROM inventory WHERE product_id = ? AND store_id = ?";
            $stmt = $pdo->prepare($sql_check);
            $stmt->execute([$product_id, $store_id]);
            $inventory_exists = $stmt->fetch();
            
            if ($inventory_exists) {
                // 기존 레코드 업데이트
                $sql_update = "UPDATE inventory 
                              SET cost_price = ?, selling_price = ?, updated_at = NOW() 
                              WHERE product_id = ? AND store_id = ?";
                $stmt = $pdo->prepare($sql_update);
                $stmt->execute([$cost_price, $new_selling_price, $product_id, $store_id]);
            } else {
                // 새 레코드 생성
                $sql_insert = "INSERT INTO inventory (product_id, store_id, cost_price, selling_price, quantity, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, 0, NOW(), NOW())";
                $stmt = $pdo->prepare($sql_insert);
                $stmt->execute([$product_id, $store_id, $cost_price, $new_selling_price]);
            }
        } else {
            // 4. store_id가 없으면 products 테이블 기본 가격 업데이트
            $sql_update_product = "UPDATE products 
                                  SET cost_price = ?, selling_price = ?, updated_at = NOW() 
                                  WHERE id = ?";
            $stmt = $pdo->prepare($sql_update_product);
            $stmt->execute([$cost_price, $new_selling_price, $product_id]);
        }
        
        // 5. 가격변경 이력 저장 (테이블이 있는 경우)
        try {
            // 테이블 생성 (없으면)
            $create_table = "CREATE TABLE IF NOT EXISTS price_change_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                store_id INT NULL,
                item_id INT NULL,
                purchase_id VARCHAR(50) NULL,
                old_cost_price DECIMAL(10,2) NULL,
                new_cost_price DECIMAL(10,2) NULL,
                old_selling_price DECIMAL(10,2) NULL,
                new_selling_price DECIMAL(10,2) NULL,
                margin_rate DECIMAL(5,2) NULL,
                change_type VARCHAR(50) NULL,
                change_reason VARCHAR(255) NULL,
                changed_by_user_id INT NOT NULL,
                changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_item_id (item_id),
                INDEX idx_product_id (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($create_table);
            
            // 이력 저장
            $sql_history = "INSERT INTO price_change_history 
                           (product_id, store_id, item_id, purchase_id, new_cost_price, new_selling_price, 
                            margin_rate, change_type, change_reason, changed_by_user_id) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'margin_adjust', ?, ?)";
            
            $stmt = $pdo->prepare($sql_history);
            $stmt->execute([
                $product_id,
                $store_id ?: null,
                $item_id,
                $purchase_id,
                $cost_price,
                $new_selling_price,
                $margin_rate,
                '일괄 마진율 적용 (' . $margin_rate . '%)',
                $_SESSION['user_id']
            ]);
        } catch (PDOException $e) {
            error_log("가격변경 이력 저장 실패: " . $e->getMessage());
        }
        
        $updated_count++;
        $updated_items[] = [
            'item_id' => $item_id,
            'product_id' => $product_id,
            'product_name' => $item['name_ko'],
            'cost_price' => $cost_price,
            'selling_price' => $new_selling_price,
            'margin_rate' => $margin_rate
        ];
    }
    
    // 트랜잭션 커밋 (트랜잭션이 시작된 경우에만)
    if ($transaction_started && $pdo->inTransaction()) {
        $pdo->commit();
    }
    
    // 성공 응답
    echo json_encode([
        'success' => true,
        'message' => $updated_count . '개 상품의 가격이 업데이트되었습니다.',
        'updated_count' => $updated_count,
        'updated_items' => $updated_items
    ]);
    
} catch (PDOException $e) {
    // 트랜잭션 롤백 (트랜잭션이 활성화되어 있는 경우에만)
    try {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollback();
        }
    } catch (PDOException $rollbackException) {
        // 롤백 실패 무시
    }
    
    error_log("일괄 마진율 적용 오류: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => '데이터베이스 오류: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // 트랜잭션 롤백 (트랜잭션이 활성화되어 있는 경우에만)
    try {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollback();
        }
    } catch (PDOException $rollbackException) {
        // 롤백 실패 무시
    }
    
    error_log("일괄 마진율 적용 일반 오류: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => '오류가 발생했습니다: ' . $e->getMessage()
    ]);
}
?>