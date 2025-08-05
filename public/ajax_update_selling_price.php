<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// 세션 시작 (세션이 시작되지 않은 경우)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

$product_id = $_POST['product_id'] ?? 0;
$selling_price = $_POST['selling_price'] ?? 0;
$cost_price = $_POST['cost_price'] ?? null;
$store_id = $_POST['store_id'] ?? null;
$purchase_id = $_POST['purchase_id'] ?? null;

if (empty($product_id) || empty($selling_price)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
    exit;
}

// 입력값 검증
if (!is_numeric($selling_price) || $selling_price <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '올바른 판매가를 입력해주세요.']);
    exit;
}

if ($cost_price !== null && (!is_numeric($cost_price) || $cost_price < 0)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '올바른 원가를 입력해주세요.']);
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
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 가격변경 이력 테이블 생성 (존재하지 않는 경우)
    createPriceChangeHistoryTable($pdo);

    if ($store_id && is_numeric($store_id)) {
        // 점포별 판매가 설정 (inventory 테이블에 selling_price 컬럼이 있는지 확인)
        $column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'selling_price'");
        $column_check->execute();
        $has_selling_price_column = $column_check->fetch();
        
        if ($has_selling_price_column) {
            // inventory 테이블에 cost_price 컬럼이 있는지도 확인
            $cost_column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'cost_price'");
            $cost_column_check->execute();
            $has_cost_price_column = $cost_column_check->fetch();
            
            // cost_price 컬럼이 없으면 추가
            if (!$has_cost_price_column) {
                try {
                    $add_column = $pdo->prepare("ALTER TABLE inventory ADD COLUMN cost_price DECIMAL(10,2) DEFAULT NULL AFTER selling_price");
                    $add_column->execute();
                    $has_cost_price_column = true;
                    error_log("Added cost_price column to inventory table");
                } catch (PDOException $e) {
                    error_log("Failed to add cost_price column: " . $e->getMessage());
                    $has_cost_price_column = false;
                }
            }
            
            // 기존 가격 정보 조회
            $old_prices_stmt = $pdo->prepare("SELECT cost_price, selling_price FROM inventory WHERE product_id = ? AND store_id = ?");
            $old_prices_stmt->execute([$product_id, $store_id]);
            $old_prices = $old_prices_stmt->fetch(PDO::FETCH_ASSOC);
            
            $old_cost_price = $old_prices['cost_price'] ?? null;
            $old_selling_price = $old_prices['selling_price'] ?? null;
            
            // 마진율 계산
            $old_margin_rate = null;
            $new_margin_rate = null;
            
            if ($old_cost_price && $old_selling_price && $old_cost_price > 0) {
                $old_margin_rate = (($old_selling_price - $old_cost_price) / $old_cost_price) * 100;
            }
            
            if ($cost_price && $selling_price && $cost_price > 0) {
                $new_margin_rate = (($selling_price - $cost_price) / $cost_price) * 100;
            }
            
            if ($has_cost_price_column && $cost_price !== null) {
                // 원가와 판매가 모두 업데이트
                $stmt = $pdo->prepare("
                    UPDATE inventory 
                    SET selling_price = ?, cost_price = ?, updated_at = NOW()
                    WHERE product_id = ? AND store_id = ?
                ");
                $stmt->execute([$selling_price, $cost_price, $product_id, $store_id]);
                
                if ($stmt->rowCount() == 0) {
                    // 재고 레코드가 없으면 생성
                    $stmt = $pdo->prepare("
                        INSERT INTO inventory (product_id, store_id, quantity, selling_price, cost_price, created_at, updated_at)
                        VALUES (?, ?, 0, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$product_id, $store_id, $selling_price, $cost_price]);
                }
                
                // 가격변경 이력 저장
                $change_type = 'both';
                $change_reason = $purchase_id ? '매입이력 기반 가격변경' : '수동 가격변경';
                
            } else {
                // 판매가만 업데이트
                $stmt = $pdo->prepare("
                    UPDATE inventory 
                    SET selling_price = ?, updated_at = NOW()
                    WHERE product_id = ? AND store_id = ?
                ");
                $stmt->execute([$selling_price, $product_id, $store_id]);
                
                if ($stmt->rowCount() == 0) {
                    // 재고 레코드가 없으면 생성
                    $stmt = $pdo->prepare("
                        INSERT INTO inventory (product_id, store_id, quantity, selling_price, created_at, updated_at)
                        VALUES (?, ?, 0, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$product_id, $store_id, $selling_price]);
                }
                
                // 가격변경 이력 저장
                $change_type = 'selling_only';
                $change_reason = $purchase_id ? '매입이력 기반 판매가변경' : '수동 판매가변경';
            }
            
            // 가격변경 이력 저장
            $history_data = [
                'product_id' => $product_id,
                'store_id' => $store_id,
                'old_cost_price' => $old_cost_price,
                'new_cost_price' => $cost_price,
                'old_selling_price' => $old_selling_price,
                'new_selling_price' => $selling_price,
                'old_margin_rate' => $old_margin_rate,
                'new_margin_rate' => $new_margin_rate,
                'change_type' => $change_type,
                'change_reason' => $change_reason,
                'purchase_id' => $purchase_id,
                'changed_by_user_id' => $_SESSION['user_id']
            ];
            
            savePriceChangeHistory($pdo, $history_data);
            
            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => '지점별 ' . ($cost_price !== null ? '원가와 판매가가' : '판매가가') . ' 성공적으로 설정되었습니다.',
                'selling_price' => number_format($selling_price, 0),
                'cost_price' => $cost_price !== null ? number_format($cost_price, 0) : null
            ]);
        } else {
            // inventory 테이블에 selling_price 컬럼이 없는 경우 기본 판매가로 설정
            
            // 기존 가격 정보 조회 (products 테이블)
            $old_prices_stmt = $pdo->prepare("SELECT cost_price, selling_price FROM products WHERE id = ?");
            $old_prices_stmt->execute([$product_id]);
            $old_prices = $old_prices_stmt->fetch(PDO::FETCH_ASSOC);
            
            $old_cost_price = $old_prices['cost_price'] ?? null;
            $old_selling_price = $old_prices['selling_price'] ?? null;
            
            // 마진율 계산
            $old_margin_rate = null;
            $new_margin_rate = null;
            
            if ($old_cost_price && $old_selling_price && $old_cost_price > 0) {
                $old_margin_rate = (($old_selling_price - $old_cost_price) / $old_cost_price) * 100;
            }
            
            if ($cost_price && $selling_price && $cost_price > 0) {
                $new_margin_rate = (($selling_price - $cost_price) / $cost_price) * 100;
            }
            
            if ($cost_price !== null) {
                // 원가와 판매가 모두 업데이트
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET selling_price = ?, cost_price = ?, updated_at = NOW(), last_modified_by_user_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$selling_price, $cost_price, $_SESSION['user_id'], $product_id]);
                $change_type = 'both';
            } else {
                // 판매가만 업데이트
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET selling_price = ?, updated_at = NOW(), last_modified_by_user_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$selling_price, $_SESSION['user_id'], $product_id]);
                $change_type = 'selling_only';
            }

            if ($stmt->rowCount() == 0) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
                exit;
            }
            
            // 가격변경 이력 저장
            $change_reason = $purchase_id ? '매입이력 기반 가격변경' : '수동 가격변경';
            $history_data = [
                'product_id' => $product_id,
                'store_id' => null, // products 테이블은 점포별이 아님
                'old_cost_price' => $old_cost_price,
                'new_cost_price' => $cost_price,
                'old_selling_price' => $old_selling_price,
                'new_selling_price' => $selling_price,
                'old_margin_rate' => $old_margin_rate,
                'new_margin_rate' => $new_margin_rate,
                'change_type' => $change_type,
                'change_reason' => $change_reason,
                'purchase_id' => $purchase_id,
                'changed_by_user_id' => $_SESSION['user_id']
            ];
            
            savePriceChangeHistory($pdo, $history_data);

            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => '기본 판매가' . ($cost_price !== null ? '와 원가가' : '가') . ' 성공적으로 설정되었습니다.',
                'selling_price' => number_format($selling_price, 0),
                'cost_price' => $cost_price !== null ? number_format($cost_price, 0) : null
            ]);
        }
    } else {
        // 기본 판매가 설정 (products 테이블)
        
        // 기존 가격 정보 조회 (products 테이블)
        $old_prices_stmt = $pdo->prepare("SELECT cost_price, selling_price FROM products WHERE id = ?");
        $old_prices_stmt->execute([$product_id]);
        $old_prices = $old_prices_stmt->fetch(PDO::FETCH_ASSOC);
        
        $old_cost_price = $old_prices['cost_price'] ?? null;
        $old_selling_price = $old_prices['selling_price'] ?? null;
        
        // 마진율 계산
        $old_margin_rate = null;
        $new_margin_rate = null;
        
        if ($old_cost_price && $old_selling_price && $old_cost_price > 0) {
            $old_margin_rate = (($old_selling_price - $old_cost_price) / $old_cost_price) * 100;
        }
        
        if ($cost_price && $selling_price && $cost_price > 0) {
            $new_margin_rate = (($selling_price - $cost_price) / $cost_price) * 100;
        }
        
        if ($cost_price !== null) {
            // 원가와 판매가 모두 업데이트
            $stmt = $pdo->prepare("
                UPDATE products 
                SET selling_price = ?, cost_price = ?, updated_at = NOW(), last_modified_by_user_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$selling_price, $cost_price, $_SESSION['user_id'], $product_id]);
            $change_type = 'both';
        } else {
            // 판매가만 업데이트
            $stmt = $pdo->prepare("
                UPDATE products 
                SET selling_price = ?, updated_at = NOW(), last_modified_by_user_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$selling_price, $_SESSION['user_id'], $product_id]);
            $change_type = 'selling_only';
        }

        if ($stmt->rowCount() == 0) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
            exit;
        }
        
        // 가격변경 이력 저장
        $change_reason = $purchase_id ? '매입이력 기반 가격변경' : '수동 가격변경';
        $history_data = [
            'product_id' => $product_id,
            'store_id' => null, // products 테이블은 점포별이 아님
            'old_cost_price' => $old_cost_price,
            'new_cost_price' => $cost_price,
            'old_selling_price' => $old_selling_price,
            'new_selling_price' => $selling_price,
            'old_margin_rate' => $old_margin_rate,
            'new_margin_rate' => $new_margin_rate,
            'change_type' => $change_type,
            'change_reason' => $change_reason,
            'purchase_id' => $purchase_id,
            'changed_by_user_id' => $_SESSION['user_id']
        ];
        
        savePriceChangeHistory($pdo, $history_data);

        ob_clean();
        echo json_encode([
            'success' => true, 
            'message' => '기본 판매가' . ($cost_price !== null ? '와 원가가' : '가') . ' 성공적으로 설정되었습니다.',
            'selling_price' => number_format($selling_price, 0),
            'cost_price' => $cost_price !== null ? number_format($cost_price, 0) : null
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
?>