<?php
// 출력 버퍼링 시작하여 예기치 않은 출력 방지
ob_start();

try {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';
    
    // margin_helper는 선택적으로 로드
    $margin_helper_loaded = false;
    try {
        require_once __DIR__ . '/../lib/margin_helper.php';
        $margin_helper_loaded = true;
    } catch (Exception $e) {
        error_log("Margin helper 로드 실패: " . $e->getMessage());
        // margin_helper 없이도 계속 진행
    }
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

$purchase_id = $_POST['purchase_id'] ?? '';
$store_id = $_POST['store_id'] ?? null;

if (empty($purchase_id)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '매입 ID가 누락되었습니다.']);
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
    error_log("일괄 적용 시작 - purchase_id: " . $purchase_id . ", store_id: " . ($store_id ?? 'null'));
    
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 가격변경 이력 테이블 생성 (존재하지 않는 경우)
    createPriceChangeHistoryTable($pdo);

    // 트랜잭션 시작
    $pdo->beginTransaction();

    // 매입 상품과 현재 점포 정보를 비교 조회하여 인상된 상품만 찾기
    $items_sql = "SELECT 
        pi.*, 
        pr.name_ko as product_name_ko,
        pr.sku,
        pr.cost_price as product_cost_price,
        pr.selling_price as product_selling_price,
        pr.pieces_per_box,
        COALESCE(inv.cost_price, pr.cost_price) as current_cost_price,
        COALESCE(inv.selling_price, pr.selling_price) as current_selling_price,
        -- 매입 단가를 낱개 기준으로 환산
        CASE 
            WHEN pi.purchase_type = 'box' AND pr.pieces_per_box > 0 
            THEN pi.unit_price / pr.pieces_per_box
            ELSE pi.unit_price
        END as purchase_unit_price_per_piece
    FROM purchase_items pi
    JOIN products pr ON pi.product_id = pr.id
    LEFT JOIN inventory inv ON pi.product_id = inv.product_id AND inv.store_id = ?
    WHERE pi.purchase_id = ?
    ORDER BY pr.name_ko";

    $items_stmt = $pdo->prepare($items_sql);
    $items_stmt->execute([$store_id, $purchase_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated_count = 0;
    $updated_products = [];

    foreach ($items as $item) {
        // 가격 변동 계산
        $price_change = $item['purchase_unit_price_per_piece'] - $item['current_cost_price'];
        
        // 인상된 상품만 처리 (1원 이상 인상)
        if ($price_change <= 0) {
            continue;
        }

        // 기존 마진율 계산
        $current_margin_rate = 0;
        if ($item['current_cost_price'] > 0) {
            $current_margin_rate = (($item['current_selling_price'] - $item['current_cost_price']) / $item['current_cost_price']) * 100;
        }

        // 새 원가 기준 예상 판매가 계산
        $new_selling_price = 0;
        if ($current_margin_rate > 0) {
            $new_selling_price = $item['purchase_unit_price_per_piece'] * (1 + ($current_margin_rate / 100));
        } else {
            $margin_rate = 30; // 기본 마진율 30%
            
            if ($margin_helper_loaded && function_exists('get_margin_rate_by_product')) {
                try {
                    $margin_rate = get_margin_rate_by_product($item['product_id']);
                } catch (Exception $e) {
                    error_log("마진율 조회 오류: " . $e->getMessage());
                    $margin_rate = 30; // 기본 마진율 30%
                }
            }
            
            $new_selling_price = $item['purchase_unit_price_per_piece'] * (1 + ($margin_rate / 100));
        }

        // 새 마진율 계산
        $new_margin_rate = 0;
        if ($item['purchase_unit_price_per_piece'] > 0) {
            $new_margin_rate = (($new_selling_price - $item['purchase_unit_price_per_piece']) / $item['purchase_unit_price_per_piece']) * 100;
        }

        // 가격 업데이트
        if ($store_id && is_numeric($store_id)) {
            // inventory 테이블 컬럼 확인
            $columns_query = $pdo->prepare("SHOW COLUMNS FROM inventory");
            $columns_query->execute();
            $columns = $columns_query->fetchAll(PDO::FETCH_COLUMN);
            
            $has_selling_price = in_array('selling_price', $columns);
            $has_cost_price = in_array('cost_price', $columns);
            $has_created_at = in_array('created_at', $columns);
            $has_updated_at = in_array('updated_at', $columns);
            
            // 기존 레코드 확인
            $check_stmt = $pdo->prepare("SELECT id FROM inventory WHERE product_id = ? AND store_id = ?");
            $check_stmt->execute([$item['product_id'], $store_id]);
            $exists = $check_stmt->fetch();
            
            if ($exists) {
                // 업데이트
                $update_fields = [];
                $update_params = [];
                
                if ($has_selling_price) {
                    $update_fields[] = "selling_price = ?";
                    $update_params[] = $new_selling_price;
                }
                
                if ($has_cost_price) {
                    $update_fields[] = "cost_price = ?";
                    $update_params[] = $item['purchase_unit_price_per_piece'];
                }
                
                if ($has_updated_at) {
                    $update_fields[] = "updated_at = NOW()";
                }
                
                if (!empty($update_fields)) {
                    $update_params[] = $item['product_id'];
                    $update_params[] = $store_id;
                    
                    $update_sql = "UPDATE inventory SET " . implode(', ', $update_fields) . " WHERE product_id = ? AND store_id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute($update_params);
                }
            } else {
                // 새 레코드 삽입
                $insert_fields = ['product_id', 'store_id', 'quantity'];
                $insert_values = ['?', '?', '0'];
                $insert_params = [$item['product_id'], $store_id];
                
                if ($has_selling_price) {
                    $insert_fields[] = 'selling_price';
                    $insert_values[] = '?';
                    $insert_params[] = $new_selling_price;
                }
                
                if ($has_cost_price) {
                    $insert_fields[] = 'cost_price';
                    $insert_values[] = '?';
                    $insert_params[] = $item['purchase_unit_price_per_piece'];
                }
                
                if ($has_created_at) {
                    $insert_fields[] = 'created_at';
                    $insert_values[] = 'NOW()';
                }
                
                if ($has_updated_at) {
                    $insert_fields[] = 'updated_at';
                    $insert_values[] = 'NOW()';
                }
                
                $insert_sql = "INSERT INTO inventory (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->execute($insert_params);
            }
        } else {
            // 기본 가격 업데이트
            $update_stmt = $pdo->prepare("
                UPDATE products 
                SET selling_price = ?, cost_price = ?, updated_at = NOW(), last_modified_by_user_id = ?
                WHERE id = ?
            ");
            $update_stmt->execute([
                $new_selling_price, 
                $item['purchase_unit_price_per_piece'], 
                $_SESSION['user_id'], 
                $item['product_id']
            ]);
        }

        // 가격변경 이력 저장
        $history_data = [
            'product_id' => $item['product_id'],
            'store_id' => $store_id,
            'old_cost_price' => $item['current_cost_price'],
            'new_cost_price' => $item['purchase_unit_price_per_piece'],
            'old_selling_price' => $item['current_selling_price'],
            'new_selling_price' => $new_selling_price,
            'old_margin_rate' => $current_margin_rate,
            'new_margin_rate' => $new_margin_rate,
            'change_type' => 'both',
            'change_reason' => '매입건별 인상상품 일괄적용',
            'purchase_id' => $purchase_id,
            'changed_by_user_id' => $_SESSION['user_id']
        ];

        savePriceChangeHistory($pdo, $history_data);

        $updated_count++;
        $updated_products[] = [
            'name' => $item['product_name_ko'],
            'sku' => $item['sku'],
            'old_cost' => $item['current_cost_price'],
            'new_cost' => $item['purchase_unit_price_per_piece'],
            'old_selling' => $item['current_selling_price'],
            'new_selling' => $new_selling_price
        ];
    }

    // 트랜잭션 커밋
    $pdo->commit();

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => "{$updated_count}개 상품의 가격이 일괄 적용되었습니다.",
        'updated_count' => $updated_count,
        'updated_products' => $updated_products
    ]);

} catch (PDOException $e) {
    if (isset($pdo)) {
        $pdo->rollback();
    }
    error_log("일괄 가격 적용 오류: " . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollback();
    }
    error_log("일괄 가격 적용 오류: " . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'message' => '오류가 발생했습니다: ' . $e->getMessage()]);
}
?>