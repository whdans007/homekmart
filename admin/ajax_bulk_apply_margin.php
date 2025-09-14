<?php
// 출력 버퍼링 시작하여 예기치 않은 출력 방지
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

// 세션 시작 (세션이 시작되지 않은 경우)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 출력 버퍼 정리
ob_clean();
header('Content-Type: application/json');

if (!is_logged_in() || !has_permission('purchase_management')) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

// 필수 파라미터 검증
$purchase_id = $_POST['purchase_id'] ?? '';
$item_ids = $_POST['item_ids'] ?? [];
$margin_rate = $_POST['margin_rate'] ?? '';
$store_id = $_POST['store_id'] ?? '';

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

if (empty($store_id)) {
    echo json_encode(['success' => false, 'message' => '점포 정보가 필요합니다.']);
    exit;
}

// 가격변경 이력 테이블 생성 함수
function createPriceChangeHistoryTable($pdo) {
    $sql = "
    CREATE TABLE IF NOT EXISTS price_change_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        store_id INT NULL,
        old_cost_price DECIMAL(10,2) NULL,
        new_cost_price DECIMAL(10,2) NULL,
        old_selling_price DECIMAL(10,2) NULL,
        new_selling_price DECIMAL(10,2) NULL,
        old_margin_rate DECIMAL(5,2) NULL,
        new_margin_rate DECIMAL(5,2) NULL,
        change_type ENUM('cost_only', 'selling_only', 'both', 'margin_adjust') NOT NULL DEFAULT 'both',
        change_reason VARCHAR(255) NULL,
        purchase_id VARCHAR(50) NULL COMMENT '매입이력에서 변경된 경우 매입ID',
        changed_by_user_id INT NOT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        INDEX idx_product_id (product_id),
        INDEX idx_store_id (store_id),
        INDEX idx_changed_at (changed_at),
        INDEX idx_changed_by_user_id (changed_by_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 가격변경 이력'
    ";
    
    try {
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("가격변경 이력 테이블 생성 실패: " . $e->getMessage());
        return false;
    }
}

// 가격변경 이력 저장 함수
function savePriceChangeHistory($pdo, $data) {
    try {
        $sql = "INSERT INTO price_change_history 
                (product_id, store_id, old_cost_price, new_cost_price, old_selling_price, new_selling_price, 
                 old_margin_rate, new_margin_rate, change_type, change_reason, purchase_id, changed_by_user_id, changed_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['product_id'],
            $data['store_id'],
            $data['old_cost_price'],
            $data['new_cost_price'],
            $data['old_selling_price'],
            $data['new_selling_price'],
            $data['old_margin_rate'],
            $data['new_margin_rate'],
            $data['change_type'],
            $data['change_reason'],
            $data['purchase_id'],
            $data['changed_by_user_id']
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("가격변경 이력 저장 실패: " . $e->getMessage());
        return false;
    }
}

try {
    // 디버깅 로그
    $debug_msg = date('Y-m-d H:i:s') . " - 일괄 가격적용 시작 - item_ids: " . implode(',', $item_ids) . ", store_id: " . ($store_id ?? 'null') . ", margin_rate: " . $margin_rate . "%\n";
    file_put_contents(__DIR__ . '/debug_log.txt', $debug_msg, FILE_APPEND | LOCK_EX);
    error_log("일괄 가격적용 시작 - item count: " . count($item_ids) . ", store_id: " . ($store_id ?? 'null') . ", margin_rate: " . $margin_rate . "%");
    
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 가격변경 이력 테이블 생성 (존재하지 않는 경우)
    createPriceChangeHistoryTable($pdo);
    
    // 트랜잭션 시작
    $pdo->beginTransaction();
    
    $updated_count = 0;
    $updated_products = [];
    
    // inventory 테이블에 selling_price와 cost_price 컬럼 확인 및 추가
    $column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'selling_price'");
    $column_check->execute();
    $has_selling_price_column = $column_check->fetch();
    
    if (!$has_selling_price_column) {
        try {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN selling_price DECIMAL(10,2) DEFAULT NULL AFTER quantity");
        } catch (PDOException $e) {
            error_log("selling_price 컬럼 추가 실패: " . $e->getMessage());
        }
    }
    
    $cost_column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'cost_price'");
    $cost_column_check->execute();
    $has_cost_price_column = $cost_column_check->fetch();
    
    if (!$has_cost_price_column) {
        try {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN cost_price DECIMAL(10,2) DEFAULT NULL AFTER selling_price");
        } catch (PDOException $e) {
            error_log("cost_price 컬럼 추가 실패: " . $e->getMessage());
        }
    }
    
    // 각 아이템에 대해 처리 (배열 순서 의존성 제거)
    foreach ($item_ids as $item_id) {
        if (!is_numeric($item_id)) {
            continue;
        }
        
        // 해당 아이템의 product_id를 직접 가져오기
        $product_id = $_POST["product_id_{$item_id}"] ?? null;
        if (!$product_id || !is_numeric($product_id)) {
            error_log("Item {$item_id}의 product_id를 찾을 수 없습니다");
            continue;
        }
        
        // 매입 상품 정보와 현재 가격 조회 (item_id로 정확한 행 조회)
        $query = "SELECT 
            pi.product_id,
            pi.unit_price as purchase_unit_price,
            pi.purchase_type,
            pr.pieces_per_box,
            pr.name_ko,
            COALESCE(inv.cost_price, pr.cost_price) as current_cost_price,
            COALESCE(inv.selling_price, pr.selling_price) as current_selling_price
        FROM purchase_items pi
        JOIN products pr ON pi.product_id = pr.id
        LEFT JOIN inventory inv ON pi.product_id = inv.product_id AND inv.store_id = ?
        WHERE pi.purchase_id = ? AND pi.item_id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$store_id, $purchase_id, $item_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            continue;
        }
        
        // 매입 단가를 낱개 기준으로 환산
        $purchase_unit_price_per_piece = $result['purchase_unit_price'];
        if ($result['purchase_type'] === 'box' && $result['pieces_per_box'] > 0) {
            $purchase_unit_price_per_piece = $result['purchase_unit_price'] / $result['pieces_per_box'];
        }
        
        // 새로운 판매가 설정 (JavaScript에서 전송된 값 사용, 없으면 마진율로 계산)
        $new_cost_price = $purchase_unit_price_per_piece;
        
        // JavaScript에서 전송된 판매가를 직접 가져오기
        $selling_price_from_js = $_POST["selling_price_{$item_id}"] ?? null;
        if ($selling_price_from_js && is_numeric($selling_price_from_js)) {
            $new_selling_price = intval($selling_price_from_js);
        } else {
            // Math.ceil과 동일한 결과를 위해 ceil 사용
            $new_selling_price = ceil($new_cost_price * (1 + ($margin_rate / 100)));
        }
        
        // 기존 가격 정보 저장
        $old_cost_price = $result['current_cost_price'];
        $old_selling_price = $result['current_selling_price'];
        
        // 마진율 계산
        $old_margin_rate = null;
        $new_margin_rate = null;
        
        if ($old_cost_price && $old_selling_price && $old_cost_price > 0) {
            $old_margin_rate = (($old_selling_price - $old_cost_price) / $old_cost_price) * 100;
        }
        
        if ($new_cost_price && $new_selling_price && $new_cost_price > 0) {
            $new_margin_rate = (($new_selling_price - $new_cost_price) / $new_cost_price) * 100;
        }
        
        if ($store_id && is_numeric($store_id)) {
            // inventory 테이블에 해당 상품이 있는지 확인
            $check_inventory = "SELECT id FROM inventory WHERE product_id = ? AND store_id = ?";
            $check_stmt = $pdo->prepare($check_inventory);
            $check_stmt->execute([$product_id, $store_id]);
            $inventory_exists = $check_stmt->fetch();
            
            if ($inventory_exists) {
                // 기존 inventory 레코드 업데이트
                $update_inventory = "UPDATE inventory 
                                   SET cost_price = ?, selling_price = ?, updated_at = NOW() 
                                   WHERE product_id = ? AND store_id = ?";
                $update_stmt = $pdo->prepare($update_inventory);
                $update_stmt->execute([$new_cost_price, $new_selling_price, $product_id, $store_id]);
            } else {
                // 새로운 inventory 레코드 생성
                $insert_inventory = "INSERT INTO inventory (product_id, store_id, cost_price, selling_price, quantity, created_at, updated_at) 
                                   VALUES (?, ?, ?, ?, 0, NOW(), NOW())";
                $insert_stmt = $pdo->prepare($insert_inventory);
                $insert_stmt->execute([$product_id, $store_id, $new_cost_price, $new_selling_price]);
            }
        } else {
            // products 테이블 업데이트 (기본 가격)
            $update_products = "UPDATE products 
                               SET cost_price = ?, selling_price = ?, updated_at = NOW(), last_modified_by_user_id = ?
                               WHERE id = ?";
            $update_stmt = $pdo->prepare($update_products);
            $update_stmt->execute([$new_cost_price, $new_selling_price, $_SESSION['user_id'], $product_id]);
        }
        
        // 가격변경 이력 저장
        $history_data = [
            'product_id' => $product_id,
            'store_id' => $store_id && is_numeric($store_id) ? $store_id : null,
            'old_cost_price' => $old_cost_price,
            'new_cost_price' => $new_cost_price,
            'old_selling_price' => $old_selling_price,
            'new_selling_price' => $new_selling_price,
            'old_margin_rate' => $old_margin_rate,
            'new_margin_rate' => $new_margin_rate,
            'change_type' => 'margin_adjust',
            'change_reason' => '일괄 마진율 적용 (' . $margin_rate . '%)',
            'purchase_id' => $purchase_id,
            'changed_by_user_id' => $_SESSION['user_id']
        ];
        
        savePriceChangeHistory($pdo, $history_data);
        
        $updated_count++;
        $updated_products[] = [
            'item_id' => $item_id,
            'product_id' => $product_id,
            'product_name' => $result['name_ko'],
            'old_cost_price' => $old_cost_price,
            'new_cost_price' => $new_cost_price,
            'old_selling_price' => $old_selling_price,
            'new_selling_price' => $new_selling_price,
            'margin_rate' => $new_margin_rate,
            'used_js_price' => isset($_POST["selling_price_{$item_id}"])
        ];
    }
    
    // 트랜잭션 커밋
    $pdo->commit();
    
    // 로그 기록
    $log_message = date('Y-m-d H:i:s') . " - 일괄 마진율 적용 완료: " . 
                   "user_id=" . $_SESSION['user_id'] . 
                   ", store_id=" . $store_id . 
                   ", purchase_id=" . $purchase_id . 
                   ", margin_rate=" . $margin_rate . "%" .
                   ", updated_count=" . $updated_count . "\n";
    file_put_contents(__DIR__ . '/debug_log.txt', $log_message, FILE_APPEND | LOCK_EX);
    
    echo json_encode([
        'success' => true, 
        'message' => $updated_count . '개 상품의 가격이 ' . $margin_rate . '% 마진율로 업데이트되었습니다.',
        'updated_count' => $updated_count,
        'updated_products' => $updated_products
    ]);
    
} catch (PDOException $e) {
    // 트랜잭션 롤백
    $pdo->rollback();
    
    // 오류 로그
    $error_message = date('Y-m-d H:i:s') . " - 일괄 마진율 적용 PDO 오류: " . $e->getMessage() . 
                     " (user_id=" . $_SESSION['user_id'] . ", purchase_id=" . $purchase_id . ")\n";
    file_put_contents(__DIR__ . '/debug_log.txt', $error_message, FILE_APPEND | LOCK_EX);
    error_log("일괄 마진율 적용 PDO 오류: " . $e->getMessage());
    
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => '데이터베이스 오류: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // 트랜잭션 롤백
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    // 오류 로그
    $error_message = date('Y-m-d H:i:s') . " - 일괄 마진율 적용 일반 오류: " . $e->getMessage() . 
                     " (user_id=" . $_SESSION['user_id'] . ", purchase_id=" . $purchase_id . ")\n";
    file_put_contents(__DIR__ . '/debug_log.txt', $error_message, FILE_APPEND | LOCK_EX);
    error_log("일괄 마진율 적용 일반 오류: " . $e->getMessage());
    
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => '오류가 발생했습니다: ' . $e->getMessage()
    ]);
}
?>