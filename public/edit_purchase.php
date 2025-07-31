<?php
$page_title = "매입 내역 상세보기";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'><strong class='font-bold'>접근 불가:</strong><span class='block sm:inline'> 이 페이지에 접근할 권한이 없습니다.</span></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$purchase_id = $_GET['id'] ?? null;
if (!$purchase_id) {
    header('Location: purchase_management.php');
    exit();
}

$conn = get_db_connection();
$message = '';
$message_type = '';

// 전체 매입 내역 삭제 처리 (Soft Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_purchase') {
    try {
        $conn->begin_transaction();
        
        // 현재 사용자 정보 확인
        $user_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_info = $user_stmt->get_result()->fetch_assoc();
        $user_stmt->close();
        
        if ($user_info) {
            // 모든 매입 상품들의 재고를 원복
            $items_stmt = $conn->prepare("
                SELECT pi.*, pr.pieces_per_box 
                FROM purchase_items pi 
                JOIN products pr ON pi.product_id = pr.id
                WHERE pi.purchase_id = ?
            ");
            $items_stmt->bind_param("i", $purchase_id);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            
            // 디버깅: 아이템 수 로그
            $total_items = $items_result->num_rows;
            error_log("Processing purchase deletion for purchase_id: {$purchase_id}, total items: {$total_items}");
            
            $item_count = 0;
            while ($item = $items_result->fetch_assoc()) {
                $item_count++;
                try {
                    // 실제 입고 수량 계산
                    $actual_quantity = (int)$item['quantity'];
                    if ($item['purchase_type'] === 'box') {
                        $pieces_per_box = $item['pieces_per_box'] ?? 1;
                        $actual_quantity = (int)$item['quantity'] * $pieces_per_box;
                    }
                    
                    // 재고에서 해당 수량 차감 (매입 삭제이므로 입고된 수량을 다시 빼야 함)
                    if ($user_info['store_id'] && $actual_quantity > 0) {
                        // inventory 테이블에 해당 레코드가 존재하는지 먼저 확인
                        $check_inv_stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND store_id = ?");
                        $check_inv_stmt->bind_param("ii", $item['product_id'], $user_info['store_id']);
                        $check_inv_stmt->execute();
                        $inv_result = $check_inv_stmt->get_result();
                        $inventory_record = $inv_result->fetch_assoc();
                        $check_inv_stmt->close();
                        
                        if ($inventory_record) {
                            // 재고 업데이트 (음수가 되지 않도록 처리)
                            $new_quantity = max(0, $inventory_record['quantity'] - $actual_quantity);
                            $inv_stmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE product_id = ? AND store_id = ?");
                            $inv_stmt->bind_param("iii", $new_quantity, $item['product_id'], $user_info['store_id']);
                            
                            if (!$inv_stmt->execute()) {
                                throw new Exception("재고 업데이트 실패 - Product ID: {$item['product_id']}, Error: " . $inv_stmt->error);
                            }
                            $inv_stmt->close();
                            
                            // 재고 트랜잭션 로그 기록
                            try {
                                $trans_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, 'OUT', ?, ?)");
                                $remarks = "매입 내역 삭제 (Purchase ID: {$purchase_id}, Item: {$item_count})";
                                $quantity_change = -$actual_quantity;
                                $trans_stmt->bind_param("iiss", $inventory_record['id'], $_SESSION['user_id'], $quantity_change, $remarks);
                                $trans_stmt->execute();
                                $trans_stmt->close();
                            } catch (Exception $log_error) {
                                error_log("Transaction log failed for item {$item_count}: " . $log_error->getMessage());
                                // 로그 실패는 전체 트랜잭션을 중단하지 않음
                            }
                        } else {
                            error_log("Inventory record not found for product_id: {$item['product_id']}, store_id: {$user_info['store_id']}");
                            // 재고 레코드가 없어도 매입 삭제는 계속 진행
                        }
                    }
                } catch (Exception $item_error) {
                    error_log("Error processing item {$item_count}: " . $item_error->getMessage());
                    throw new Exception("아이템 #{$item_count} 처리 중 오류: " . $item_error->getMessage());
                }
            }
            $items_stmt->close();
            
            // purchases 테이블에 deleted_at 컬럼이 있는지 확인하고 없으면 추가
            $check_deleted_at = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
            $has_deleted_at_col = $check_deleted_at->num_rows > 0;
            
            if (!$has_deleted_at_col) {
                $conn->query("ALTER TABLE purchases ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
            }
            
            // deleted_by_user_id 컬럼이 없으면 추가
            $check_deleted_by = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_by_user_id'");
            $has_deleted_by_col = $check_deleted_by->num_rows > 0;
            
            if (!$has_deleted_by_col) {
                $conn->query("ALTER TABLE purchases ADD COLUMN deleted_by_user_id INT DEFAULT NULL");
            }
            
            // Soft Delete: deleted_at 컬럼에 현재 시간 설정
            // 컬럼 추가 후 다시 확인
            $check_deleted_at_final = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
            $check_deleted_by_final = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_by_user_id'");
            
            if ($check_deleted_at_final->num_rows > 0 && $check_deleted_by_final->num_rows > 0) {
                $soft_delete_stmt = $conn->prepare("UPDATE purchases SET deleted_at = NOW(), deleted_by_user_id = ? WHERE purchase_id = ?");
                $soft_delete_stmt->bind_param("ii", $_SESSION['user_id'], $purchase_id);
            } else if ($check_deleted_at_final->num_rows > 0) {
                $soft_delete_stmt = $conn->prepare("UPDATE purchases SET deleted_at = NOW() WHERE purchase_id = ?");
                $soft_delete_stmt->bind_param("i", $purchase_id);
            } else {
                throw new Exception('deleted_at 컬럼을 생성할 수 없습니다.');
            }
            $soft_delete_stmt->execute();
            $soft_delete_stmt->close();
            
            $conn->commit();
            $message = '매입 내역이 성공적으로 삭제되었습니다. (복원 가능)';
            $message_type = 'success';
            
            // 삭제 후 목록으로 리다이렉트
            header('Location: purchase_management.php?message=' . urlencode($message));
            exit();
        } else {
            throw new Exception('사용자 정보를 찾을 수 없습니다.');
        }
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
        error_log("Purchase deletion failed: " . $error_msg . " for purchase_id: " . $purchase_id);
        
        // 사용자에게 더 자세한 오류 정보 제공
        if (strpos($error_msg, '아이템') !== false) {
            $message = '매입 내역 삭제 중 특정 아이템 처리에서 오류가 발생했습니다: ' . $error_msg;
        } else if (strpos($error_msg, 'deleted_at') !== false) {
            $message = '데이터베이스 구조 오류입니다. Soft Delete 설정을 다시 실행해 주세요.';
        } else {
            $message = '매입 내역 삭제에 실패했습니다: ' . $error_msg;
        }
        $message_type = 'error';
    }
}

// 매입 상품 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_item' && isset($_POST['item_id'])) {
        $item_id = (int)$_POST['item_id'];
        
        try {
            $conn->begin_transaction();
            
            // 삭제할 아이템 정보 조회 (재고 업데이트를 위해)
            $item_stmt = $conn->prepare("
                SELECT pi.*, pr.pieces_per_box, u.store_id 
                FROM purchase_items pi 
                JOIN products pr ON pi.product_id = pr.id
                JOIN purchases p ON pi.purchase_id = p.purchase_id
                JOIN users u ON u.id = ?
                WHERE pi.item_id = ? AND pi.purchase_id = ?
            ");
            $item_stmt->bind_param("iii", $_SESSION['user_id'], $item_id, $purchase_id);
            $item_stmt->execute();
            $item_info = $item_stmt->get_result()->fetch_assoc();
            $item_stmt->close();
            
            if ($item_info) {
                // 실제 입고된 수량 계산
                $actual_quantity = (int)$item_info['quantity'];
                if ($item_info['purchase_type'] === 'box') {
                    $actual_quantity = (int)$item_info['quantity'] * ($item_info['pieces_per_box'] ?? 1);
                }
                
                // 재고에서 해당 수량 차감
                if ($item_info['store_id']) {
                    $inv_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND store_id = ?");
                    $inv_stmt->bind_param("iii", $actual_quantity, $item_info['product_id'], $item_info['store_id']);
                    $inv_stmt->execute();
                    $inv_stmt->close();
                    
                    // 재고 트랜잭션 로그 기록
                    try {
                        $trans_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) SELECT id, ?, 'OUT', ?, ? FROM inventory WHERE product_id = ? AND store_id = ?");
                        $remarks = "매입 상품 삭제 (Purchase Item ID: {$item_id})";
                        $trans_stmt->bind_param("iisii", $_SESSION['user_id'], -$actual_quantity, $remarks, $item_info['product_id'], $item_info['store_id']);
                        $trans_stmt->execute();
                        $trans_stmt->close();
                    } catch (Exception $log_error) {
                        // 로그 기록 실패는 무시하고 계속 진행
                        error_log("Transaction log failed: " . $log_error->getMessage());
                    }
                }
                
                // 매입 상품 삭제
                $delete_stmt = $conn->prepare("DELETE FROM purchase_items WHERE item_id = ? AND purchase_id = ?");
                $delete_stmt->bind_param("ii", $item_id, $purchase_id);
                $delete_stmt->execute();
                $delete_stmt->close();
                
                // 매입 전체 합계 재계산 및 업데이트
                $update_stmt = $conn->prepare("
                    UPDATE purchases SET 
                        total_amount = (SELECT SUM(quantity * unit_price) FROM purchase_items WHERE purchase_id = ?),
                        total_items = (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = ?)
                    WHERE purchase_id = ?
                ");
                $update_stmt->bind_param("iii", $purchase_id, $purchase_id, $purchase_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                $conn->commit();
                $message = '매입 상품이 성공적으로 삭제되었습니다.';
                $message_type = 'success';
            } else {
                throw new Exception('삭제할 상품을 찾을 수 없습니다.');
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = '상품 삭제에 실패했습니다: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
    
    // 매입 상품 수정 처리
    if ($_POST['action'] === 'update_item' && isset($_POST['item_id'])) {
        $item_id = (int)$_POST['item_id'];
        $new_quantity = (int)$_POST['quantity'];
        $new_unit_price = (float)$_POST['unit_price'];
        $new_purchase_type = $_POST['purchase_type'] ?? 'piece';
        
        try {
            $conn->begin_transaction();
            
            // 기존 아이템 정보 조회
            $item_stmt = $conn->prepare("
                SELECT pi.*, pr.pieces_per_box, u.store_id 
                FROM purchase_items pi 
                JOIN products pr ON pi.product_id = pr.id
                JOIN purchases p ON pi.purchase_id = p.purchase_id
                JOIN users u ON u.id = ?
                WHERE pi.item_id = ? AND pi.purchase_id = ?
            ");
            $item_stmt->bind_param("iii", $_SESSION['user_id'], $item_id, $purchase_id);
            $item_stmt->execute();
            $old_info = $item_stmt->get_result()->fetch_assoc();
            $item_stmt->close();
            
            if ($old_info) {
                // 기존 실제 입고 수량 계산
                $old_actual_quantity = (int)$old_info['quantity'];
                if ($old_info['purchase_type'] === 'box') {
                    $old_actual_quantity = (int)$old_info['quantity'] * ($old_info['pieces_per_box'] ?? 1);
                }
                
                // 새로운 실제 입고 수량 계산
                $new_actual_quantity = $new_quantity;
                if ($new_purchase_type === 'box') {
                    $new_actual_quantity = $new_quantity * ($old_info['pieces_per_box'] ?? 1);
                }
                
                // 재고 수량 조정
                if ($old_info['store_id']) {
                    $quantity_diff = $new_actual_quantity - $old_actual_quantity;
                    
                    if ($quantity_diff != 0) {
                        $inv_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND store_id = ?");
                        $inv_stmt->bind_param("iii", $quantity_diff, $old_info['product_id'], $old_info['store_id']);
                        $inv_stmt->execute();
                        $inv_stmt->close();
                        
                        // 재고 트랜잭션 로그 기록
                        try {
                            $trans_type = $quantity_diff > 0 ? 'IN' : 'OUT';
                            $trans_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) SELECT id, ?, ?, ?, ? FROM inventory WHERE product_id = ? AND store_id = ?");
                            $remarks = "매입 상품 수정 (Purchase Item ID: {$item_id})";
                            $trans_stmt->bind_param("isisii", $_SESSION['user_id'], $trans_type, $quantity_diff, $remarks, $old_info['product_id'], $old_info['store_id']);
                            $trans_stmt->execute();
                            $trans_stmt->close();
                        } catch (Exception $log_error) {
                            // 로그 기록 실패는 무시하고 계속 진행
                            error_log("Transaction log failed: " . $log_error->getMessage());
                        }
                    }
                }
                
                // 매입 상품 정보 업데이트
                $update_item_stmt = $conn->prepare("UPDATE purchase_items SET quantity = ?, unit_price = ?, purchase_type = ? WHERE item_id = ?");
                $update_item_stmt->bind_param("idsi", $new_quantity, $new_unit_price, $new_purchase_type, $item_id);
                $update_item_stmt->execute();
                $update_item_stmt->close();
                
                // 매입 전체 합계 재계산 및 업데이트
                $update_stmt = $conn->prepare("
                    UPDATE purchases SET 
                        total_amount = (SELECT SUM(quantity * unit_price) FROM purchase_items WHERE purchase_id = ?),
                        total_items = (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = ?)
                    WHERE purchase_id = ?
                ");
                $update_stmt->bind_param("iii", $purchase_id, $purchase_id, $purchase_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                $conn->commit();
                $message = '매입 상품이 성공적으로 수정되었습니다.';
                $message_type = 'success';
            } else {
                throw new Exception('수정할 상품을 찾을 수 없습니다.');
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = '상품 수정에 실패했습니다: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 새로운 상품 추가 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $product_id = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    $unit_price = (float)$_POST['unit_price'];
    $purchase_type = trim($_POST['purchase_type'] ?? 'box');
    
    // 데이터 검증
    if (!in_array($purchase_type, ['box', 'piece'])) {
        $purchase_type = 'box';
    }
    
    if ($product_id && $quantity > 0 && $unit_price >= 0) {
        try {
            $conn->begin_transaction();
            
            // 현재 사용자의 점포 정보 조회
            $user_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $_SESSION['user_id']);
            $user_stmt->execute();
            $user_info = $user_stmt->get_result()->fetch_assoc();
            $user_stmt->close();
            
            // 1. 매입 상세 데이터 저장
            $stmt_item = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, purchase_type, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
            $stmt_item->bind_param("iisis", $purchase_id, $product_id, $purchase_type, $quantity, $unit_price);
            if (!$stmt_item->execute()) {
                throw new Exception("매입 상세 저장 실패: " . $stmt_item->error);
            }
            $stmt_item->close();
            
            // 2. 실제 입고 수량 계산 (박스/낱개 구분)
            $actual_quantity = $quantity;
            if ($purchase_type === 'box') {
                $pieces_stmt = $conn->prepare("SELECT pieces_per_box FROM products WHERE id = ?");
                $pieces_stmt->bind_param("i", $product_id);
                $pieces_stmt->execute();
                $pieces_result = $pieces_stmt->get_result();
                if ($pieces_row = $pieces_result->fetch_assoc()) {
                    $pieces_per_box = $pieces_row['pieces_per_box'] ?? 1;
                    $actual_quantity = $quantity * $pieces_per_box;
                }
                $pieces_stmt->close();
            }
            
            // 3. inventory 테이블 업데이트
            if ($user_info && $user_info['store_id']) {
                $store_id = $user_info['store_id'];
                
                // 기존 재고 레코드 확인
                $inv_check_stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND store_id = ?");
                $inv_check_stmt->bind_param("ii", $product_id, $store_id);
                $inv_check_stmt->execute();
                $inv_result = $inv_check_stmt->get_result();
                
                if ($inv_row = $inv_result->fetch_assoc()) {
                    // 기존 재고 업데이트
                    $new_quantity = $inv_row['quantity'] + $actual_quantity;
                    $inv_update_stmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
                    $inv_update_stmt->bind_param("ii", $new_quantity, $inv_row['id']);
                    $inv_update_stmt->execute();
                    $inv_update_stmt->close();
                    $inventory_id = $inv_row['id'];
                } else {
                    // 새로운 재고 레코드 생성
                    $inv_insert_stmt = $conn->prepare("INSERT INTO inventory (product_id, store_id, quantity) VALUES (?, ?, ?)");
                    $inv_insert_stmt->bind_param("iii", $product_id, $store_id, $actual_quantity);
                    $inv_insert_stmt->execute();
                    $inventory_id = $inv_insert_stmt->insert_id;
                    $inv_insert_stmt->close();
                }
                $inv_check_stmt->close();
                
                // 4. inventory_transactions 로그 기록
                $transaction_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, '입고', ?, ?)");
                $remarks = "매입 상품 추가 (Purchase ID: {$purchase_id})";
                $transaction_stmt->bind_param("iiis", $inventory_id, $_SESSION['user_id'], $actual_quantity, $remarks);
                if (!$transaction_stmt->execute()) {
                    throw new Exception("재고 거래 로그 저장 실패: " . $transaction_stmt->error);
                }
                $transaction_stmt->close();
            }
            
            // 5. 매입 전체 합계 재계산 및 업데이트
            $update_stmt = $conn->prepare("
                UPDATE purchases SET 
                    total_amount = (SELECT SUM(quantity * unit_price) FROM purchase_items WHERE purchase_id = ?),
                    total_items = (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = ?)
                WHERE purchase_id = ?
            ");
            $update_stmt->bind_param("iii", $purchase_id, $purchase_id, $purchase_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $conn->commit();
            $message = '새로운 상품이 매입 내역에 성공적으로 추가되었습니다.';
            $message_type = 'success';
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = '상품 추가에 실패했습니다: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = '상품, 수량, 단가를 올바르게 입력해주세요.';
        $message_type = 'error';
    }
}

// deleted_at 컬럼이 존재하는지 확인
$check_deleted_at_column = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
$has_deleted_at = $check_deleted_at_column->num_rows > 0;

// Fetch purchase details (삭제되지 않은 매입만)
if ($has_deleted_at) {
    $stmt = $conn->prepare("SELECT p.*, s.name as supplier_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id WHERE p.purchase_id = ? AND p.deleted_at IS NULL");
} else {
    $stmt = $conn->prepare("SELECT p.*, s.name as supplier_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id WHERE p.purchase_id = ?");
}
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchase_result = $stmt->get_result();
$purchase = $purchase_result->fetch_assoc();
$stmt->close();

if (!$purchase) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4'>매입 내역을 찾을 수 없습니다.</div>";
    echo "<a href='purchase_management.php' class='bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded'>매입 리스트로 돌아가기</a>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

// Fetch purchase items with product details
$stmt = $conn->prepare("
    SELECT 
        pi.*,
        pr.name_ko as product_name,
        pr.name_en as product_name_en,
        pr.sku,
        pr.barcode,
        pr.pieces_per_box,
        CASE 
            WHEN pi.purchase_type = 'box' THEN pi.quantity * COALESCE(pr.pieces_per_box, 1)
            ELSE pi.quantity
        END as total_pieces
    FROM purchase_items pi 
    JOIN products pr ON pi.product_id = pr.id 
    WHERE pi.purchase_id = ? 
    ORDER BY pi.item_id
");
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$items_result = $stmt->get_result();
?>

<!-- Page header -->
<div class="mb-8 sm:flex sm:items-center sm:justify-between">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">매입 내역 상세보기</h1>
        <p class="mt-2 text-sm text-gray-700">거래번호: <span class="font-semibold"><?php echo htmlspecialchars($purchase_id); ?></span></p>
    </div>
    <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
        <div class="flex space-x-3">
            <?php if ($has_deleted_at): ?>
            <button type="button" id="delete-purchase-btn" class="inline-flex items-center justify-center rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 shadow-sm hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                <i class="fas fa-trash-alt mr-2"></i>
                매입 내역 삭제
            </button>
            <?php else: ?>
            <a href="setup_soft_delete_purchases.php" class="inline-flex items-center justify-center rounded-md border border-yellow-300 bg-yellow-50 px-4 py-2 text-sm font-medium text-yellow-700 shadow-sm hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                <i class="fas fa-database mr-2"></i>
                Soft Delete 설정 필요
            </a>
            <?php endif; ?>
            <a href="purchase_management.php" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                <i class="fas fa-arrow-left mr-2"></i>
                매입 관리로 돌아가기
            </a>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="mb-6 rounded-md <?php echo $message_type === 'success' ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'; ?> p-4 border">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400'; ?>"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm <?php echo $message_type === 'success' ? 'text-green-800' : 'text-red-800'; ?>"><?php echo $message; ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="bg-white shadow rounded-lg">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-medium text-gray-900">매입 기본 정보</h2>
    </div>
    <div class="p-6">
        <!-- 매입 기본 정보 -->
        <div class="bg-gray-50 rounded-lg p-4 mb-8">
            <div class="flex flex-wrap items-center justify-between gap-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-building text-blue-500 mr-2"></i>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-600">거래처:</span>
                        <span class="text-base font-semibold text-gray-900 ml-2"><?php echo htmlspecialchars($purchase['supplier_name']); ?></span>
                    </div>
                </div>
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-calendar-alt text-green-500 mr-2"></i>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-600">매입날짜:</span>
                        <span class="text-base font-semibold text-gray-900 ml-2"><?php echo htmlspecialchars($purchase['purchase_date']); ?></span>
                    </div>
                </div>
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <span class="text-purple-500 text-lg font-bold mr-2">₱</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-600">총매입금액:</span>
                        <span class="text-base font-semibold text-gray-900 ml-2"><?php echo number_format($purchase['total_amount']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 매입 상품 목록 -->
        <div class="mb-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">매입 상품 내역</h3>
            <div class="overflow-x-auto shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">상품명</th>
                            <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">단위</th>
                            <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">수량</th>
                            <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">박스/개</th>
                            <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">단가</th>
                            <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">낱개가</th>
                            <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">입고량</th>
                            <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">합계</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">작업</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php 
                        $total_amount = 0;
                        $total_pieces = 0;
                        $total_products = 0; // 총 품목 수 (상품 종류 수)
                        $total_items = 0;    // 총 아이템 수 (수량 합계)
                        
                        while($item = $items_result->fetch_assoc()): 
                            $item_total = $item['quantity'] * $item['unit_price'];
                            $total_amount += $item_total;
                            $total_pieces += $item['total_pieces'];
                            $total_products++; // 상품 종류 개수
                            $total_items += $item['quantity']; // 수량 합계
                            
                            $piece_price = 0;
                            if ($item['purchase_type'] === 'box' && $item['pieces_per_box'] > 0) {
                                $piece_price = $item['unit_price'] / $item['pieces_per_box'];
                            } else {
                                $piece_price = $item['unit_price'];
                            }
                        ?>
                                <tr class="hover:bg-gray-50" id="row-<?php echo $item['item_id']; ?>">
                                    <td class="px-3 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($item['sku']); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                        <?php if (!empty($item['product_name_en'])): ?>
                                        <div class="text-xs text-gray-600 italic"><?php echo htmlspecialchars($item['product_name_en']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($item['barcode']): ?>
                                        <div class="text-xs text-gray-500 mt-1">바코드: <?php echo htmlspecialchars($item['barcode']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-center">
                                        <span class="type-display inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $item['purchase_type'] === 'box' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'; ?>">
                                            <?php echo $item['purchase_type'] === 'box' ? '박스' : '낱개'; ?>
                                        </span>
                                        <select class="type-input text-xs rounded-md px-2 py-1 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" style="display: none;">
                                            <option value="box" <?php echo $item['purchase_type'] === 'box' ? 'selected' : ''; ?>>박스</option>
                                            <option value="piece" <?php echo $item['purchase_type'] === 'piece' ? 'selected' : ''; ?>>낱개</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-semibold">
                                        <span class="quantity-display"><?php echo number_format($item['quantity']); ?></span>
                                        <input type="number" class="quantity-input w-14 px-2 py-1 border border-gray-300 rounded-md text-right text-sm focus:border-indigo-500 focus:ring-indigo-500" value="<?php echo $item['quantity']; ?>" min="1" style="display: none;">
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($item['pieces_per_box'] ?? 1); ?>개</td>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                        <span class="price-display"><?php echo number_format($item['unit_price']); ?></span>
                                        <input type="number" class="price-input w-18 px-2 py-1 border border-gray-300 rounded-md text-right text-sm focus:border-indigo-500 focus:ring-indigo-500" value="<?php echo $item['unit_price']; ?>" min="0" step="1" style="display: none;">
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($piece_price, 2); ?></td>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-semibold">
                                        <span class="text-blue-600"><?php echo number_format($item['total_pieces']); ?>개</span>
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-bold"><?php echo number_format($item_total); ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <div class="action-buttons flex flex-col space-y-1" id="actions-<?php echo $item['item_id']; ?>">
                                            <button type="button" class="edit-btn inline-flex items-center justify-center px-3 py-1 border border-transparent text-xs font-medium rounded-md text-indigo-600 hover:text-indigo-900 bg-indigo-50 hover:bg-indigo-100 transition-colors duration-200" data-item-id="<?php echo $item['item_id']; ?>">
                                                <i class="fas fa-edit mr-1"></i>수정
                                            </button>
                                            <button type="button" class="delete-btn inline-flex items-center justify-center px-3 py-1 border border-transparent text-xs font-medium rounded-md text-red-600 hover:text-red-900 bg-red-50 hover:bg-red-100 transition-colors duration-200" data-item-id="<?php echo $item['item_id']; ?>">
                                                <i class="fas fa-trash-alt mr-1"></i>삭제
                                            </button>
                                        </div>
                                        <div class="edit-actions flex flex-col space-y-1" id="edit-actions-<?php echo $item['item_id']; ?>" style="display: none;">
                                            <button type="button" class="save-btn inline-flex items-center justify-center px-4 py-2 border border-transparent text-xs font-bold rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 shadow-lg transition-all duration-200 transform hover:scale-105" data-item-id="<?php echo $item['item_id']; ?>">
                                                <i class="fas fa-save mr-1"></i>저장
                                            </button>
                                            <button type="button" class="cancel-btn inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 shadow-sm transition-colors duration-200">
                                                <i class="fas fa-times mr-1"></i>취소
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 상품 추가 버튼 -->
        <div class="mt-6 flex justify-center">
            <a href="add_purchase.php?edit_purchase_id=<?php echo $purchase_id; ?>" 
               class="inline-flex items-center px-24 py-5 border border-blue-300 rounded-lg shadow-sm text-xl font-semibold text-blue-800 bg-blue-100 hover:bg-blue-200 hover:text-blue-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200">
                <i class="fas fa-plus-circle mr-5 text-2xl"></i>
                상품 추가하기
            </a>
        </div>
        
        <!-- 합계 정보 -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-blue-600 to-indigo-600 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-black flex items-center">
                    <i class="fas fa-chart-bar mr-2"></i>
                    매입 합계 정보
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-center text-sm font-semibold text-gray-700 border-r border-gray-200">
                                <div class="flex items-center justify-center mb-2">
                                    <i class="fas fa-boxes text-purple-600 text-lg mr-2"></i>
                                    총 품목 수
                                </div>
                            </th>
                            <th class="px-6 py-4 text-center text-sm font-semibold text-gray-700 border-r border-gray-200">
                                <div class="flex items-center justify-center mb-2">
                                    <i class="fas fa-cube text-orange-600 text-lg mr-2"></i>
                                    총 아이템 수
                                </div>
                            </th>
                            <th class="px-6 py-4 text-center text-sm font-semibold text-gray-700 border-r border-gray-200">
                                <div class="flex items-center justify-center mb-2">
                                    <i class="fas fa-warehouse text-blue-600 text-lg mr-2"></i>
                                    총 입고수량 (낱개)
                                </div>
                            </th>
                            <th class="px-6 py-4 text-center text-sm font-semibold text-gray-700">
                                <div class="flex items-center justify-center mb-2">
                                    <span class="text-green-600 text-lg mr-2 font-bold">₱</span>
                                    총 매입금액
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-6 whitespace-nowrap text-center border-r border-gray-200">
                                <div class="text-3xl font-bold text-purple-600 mb-1"><?php echo number_format($total_products); ?></div>
                                <div class="text-sm text-gray-500">종</div>
                            </td>
                            <td class="px-6 py-6 whitespace-nowrap text-center border-r border-gray-200">
                                <div class="text-3xl font-bold text-orange-600 mb-1"><?php echo number_format($total_items); ?></div>
                                <div class="text-sm text-gray-500">개</div>
                            </td>
                            <td class="px-6 py-6 whitespace-nowrap text-center border-r border-gray-200">
                                <div class="text-3xl font-bold text-blue-600 mb-1"><?php echo number_format($total_pieces); ?></div>
                                <div class="text-sm text-gray-500">개</div>
                            </td>
                            <td class="px-6 py-6 whitespace-nowrap text-center">
                                <div class="text-3xl font-bold text-green-600 mb-1"><?php echo number_format($purchase['total_amount']); ?></div>
                                <div class="text-sm text-gray-500">PHP</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('페이지 로드 완료');
    
    // 수정 버튼 클릭 시
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const itemId = this.dataset.itemId;
            console.log('수정 버튼 클릭:', itemId);
            
            const row = document.getElementById('row-' + itemId);
            const actionsDiv = document.getElementById('actions-' + itemId);
            const editActionsDiv = document.getElementById('edit-actions-' + itemId);
            
            // 디스플레이 요소 숨기기
            row.querySelector('.quantity-display').style.display = 'none';
            row.querySelector('.price-display').style.display = 'none';
            row.querySelector('.type-display').style.display = 'none';
            actionsDiv.style.display = 'none';
            
            // 입력 요소 보이기
            row.querySelector('.quantity-input').style.display = 'inline-block';
            row.querySelector('.price-input').style.display = 'inline-block';
            row.querySelector('.type-input').style.display = 'inline-block';
            editActionsDiv.style.display = 'flex';
            editActionsDiv.style.flexDirection = 'column';
            
            console.log('수정 모드 활성화');
        });
    });
    
    // 취소 버튼 클릭 시
    document.querySelectorAll('.cancel-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const row = this.closest('tr');
            const itemId = row.id.replace('row-', '');
            console.log('취소 버튼 클릭:', itemId);
            
            const actionsDiv = document.getElementById('actions-' + itemId);
            const editActionsDiv = document.getElementById('edit-actions-' + itemId);
            
            // 입력 요소 숨기기
            row.querySelector('.quantity-input').style.display = 'none';
            row.querySelector('.price-input').style.display = 'none';
            row.querySelector('.type-input').style.display = 'none';
            editActionsDiv.style.display = 'none';
            
            // 디스플레이 요소 보이기
            row.querySelector('.quantity-display').style.display = 'inline';
            row.querySelector('.price-display').style.display = 'inline';
            row.querySelector('.type-display').style.display = 'inline-flex';
            actionsDiv.style.display = 'flex';
            
            // 원래 값으로 복원
            const quantityInput = row.querySelector('.quantity-input');
            const priceInput = row.querySelector('.price-input');
            const typeInput = row.querySelector('.type-input');
            quantityInput.value = quantityInput.getAttribute('value');
            priceInput.value = priceInput.getAttribute('value');
            // 타입은 selected 속성으로 복원됨
            
            console.log('수정 모드 취소');
        });
    });
    
    // 저장 버튼 클릭 시
    document.querySelectorAll('.save-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const itemId = this.dataset.itemId;
            console.log('저장 버튼 클릭:', itemId);
            
            const row = document.getElementById('row-' + itemId);
            const quantity = row.querySelector('.quantity-input').value;
            const unitPrice = row.querySelector('.price-input').value;
            const purchaseType = row.querySelector('.type-input').value;
            
            console.log('입력값:', { quantity, unitPrice, purchaseType });
            
            if (!quantity || quantity <= 0) {
                alert('수량은 1 이상이어야 합니다.');
                return;
            }
            
            if (unitPrice === '' || parseFloat(unitPrice) < 0) {
                alert('단가는 0 이상이어야 합니다.');
                return;
            }
            
            if (confirm('이 상품의 정보를 수정하시겠습니까?')) {
                console.log('폼 제출 준비');
                
                // 기존 폼이 있다면 제거
                const existingForm = document.getElementById('update-form');
                if (existingForm) {
                    existingForm.remove();
                }
                
                const form = document.createElement('form');
                form.id = 'update-form';
                form.method = 'POST';
                form.action = '';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="item_id" value="${itemId}">
                    <input type="hidden" name="quantity" value="${quantity}">
                    <input type="hidden" name="unit_price" value="${unitPrice}">
                    <input type="hidden" name="purchase_type" value="${purchaseType}">
                `;
                document.body.appendChild(form);
                console.log('폼 제출');
                form.submit();
            }
        });
    });
    
    // 삭제 버튼 클릭 시
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const itemId = this.dataset.itemId;
            console.log('삭제 버튼 클릭:', itemId);
            
            if (confirm('이 상품을 매입 목록에서 삭제하시겠습니까?\n삭제하면 재고에서도 해당 수량이 차감됩니다.')) {
                console.log('삭제 폼 제출 준비');
                
                // 기존 폼이 있다면 제거
                const existingForm = document.getElementById('delete-form');
                if (existingForm) {
                    existingForm.remove();
                }
                
                const form = document.createElement('form');
                form.id = 'delete-form';
                form.method = 'POST';
                form.action = '';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="item_id" value="${itemId}">
                `;
                document.body.appendChild(form);
                console.log('삭제 폼 제출');
                form.submit();
            }
        });
    });
    
    // 전체 매입 내역 삭제 버튼 클릭 시 (버튼이 존재할 때만)
    const deletePurchaseBtn = document.getElementById('delete-purchase-btn');
    if (deletePurchaseBtn) {
        deletePurchaseBtn.addEventListener('click', function(e) {
        e.preventDefault();
        console.log('전체 매입 삭제 버튼 클릭');
        
        if (confirm('이 매입 내역 전체를 삭제하시겠습니까?\\n\\n삭제된 매입 내역은 복원이 가능하며, 모든 매입 상품의 재고가 차감됩니다.\\n\\n계속하시겠습니까?')) {
            console.log('전체 매입 삭제 확인됨');
            
            // 기존 폼이 있다면 제거
            const existingForm = document.getElementById('delete-purchase-form');
            if (existingForm) {
                existingForm.remove();
            }
            
            const form = document.createElement('form');
            form.id = 'delete-purchase-form';
            form.method = 'POST';
            form.action = '';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_purchase">
            `;
            document.body.appendChild(form);
            console.log('전체 매입 삭제 폼 제출');
            form.submit();
        }
        });
    }
    
    
    console.log('이벤트 리스너 등록 완료');
});
</script>

<?php 
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>